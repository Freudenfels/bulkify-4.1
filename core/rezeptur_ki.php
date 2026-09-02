<?php
// Aus einer Kundenidee einen Rezepturvorschlag entwickeln – mit Novel-Food-Prüfung und
// Machbarkeit. Der Kunde schreibt, was er will („etwas für besseren Schlaf, vegan, Kapseln"),
// die KI schlägt Zutaten mit Mengen vor.
//
// WICHTIG: Das ist ein Entwurf für das Team, kein Freigabedokument. Gespeichert wird nichts
// automatisch; die Zeilen landen erst nach Prüfung in der Anfrage und daraus in der Rezeptur.
// Die rechtliche Bewertung (Novel Food, Höchstmengen, Health Claims) MUSS ein Mensch prüfen.
require_once __DIR__ . '/ki.php';
require_once __DIR__ . '/schema.php';

// Unser Rohstoffkatalog als Liste für die KI – sie soll bevorzugt vorschlagen, was wir einkaufen
// können, statt Exoten zu erfinden.
function rezeptur_ki_katalog(): array {
    $out = [];
    foreach (all("SELECT id, name, name_lat, form FROM item WHERE kategorie='rohstoff' AND gesperrt=0 ORDER BY name") as $r)
        $out[(int)$r['id']] = trim($r['name'] . ($r['name_lat'] ? ' (' . $r['name_lat'] . ')' : ''));
    return $out;
}

// Der Auftrag an die KI.
function rezeptur_ki_anweisung(string $form, array $katalog): string {
    $liste = $katalog ? "\n- " . implode("\n- ", array_values($katalog)) : ' (noch keine)';
    $formLbl = ['kapsel'=>'Kapseln', 'tablette'=>'Tabletten', 'softgel'=>'Softgels', 'stick'=>'Sticks',
                'pulver'=>'Pulver', 'fluessig'=>'flüssig'][$form] ?? $form;
    return <<<TXT
Du entwickelst Rezepturen für einen deutschen Lohnhersteller von Nahrungsergänzungsmitteln.
Aus der Idee des Kunden soll ein sauberer, herstellbarer und verkehrsfähiger Vorschlag werden.
Darreichungsform: $formLbl.

Diese Rohstoffe haben wir im Katalog – bevorzuge sie, wenn sie fachlich passen:$liste

Gib NUR dieses JSON zurück:
{
  "name": "Vorschlag für den Produktnamen",
  "zutaten": [
    { "bezeichnung": "", "katalog": "", "menge_mg": 0, "funktion": "", "begruendung": "" }
  ],
  "tagesdosis": "z. B. 2 Kapseln täglich",
  "novel_food": [ { "stoff": "", "bewertung": "unproblematisch|prüfen|novel_food", "begruendung": "" } ],
  "hoechstmengen": [ { "stoff": "", "menge_mg": 0, "bewertung": "im Rahmen|nahe der Obergrenze|zu hoch", "begruendung": "" } ],
  "health_claims": [ { "stoff": "", "claim": "", "zulaessig": true } ],
  "machbarkeit": { "bewertung": "gut|kritisch|nicht machbar", "gruende": [ "" ] },
  "hinweise": [ "" ]
}

Regeln:
- "menge_mg" ist die Menge JE EINHEIT (je Kapsel, je Tablette, je Portion) in Milligramm, als Zahl.
- "katalog": der exakte Name aus der Liste oben, wenn der Rohstoff dort steht. Sonst leer lassen.
- "novel_food": Beurteile nach EU-Verordnung 2015/2283, ob der Stoff in der EU als Lebensmittel etabliert ist. Im Zweifel "prüfen" und den Grund nennen – nicht raten.
- "hoechstmengen": Orientiere dich an den BfR-Höchstmengenempfehlungen und den NRV. Nenne die Zahl, die du zugrunde legst.
- "health_claims": nur Angaben aus der EU-Liste zugelassener Claims (VO 432/2012). Nichts erfinden; im Zweifel "zulaessig": false.
- "machbarkeit": Denk an Fließfähigkeit, Schüttdichte, Geschmack, Feuchtigkeit und daran, ob Extrakte in dieser Menge sinnvoll sind.
  Die passende Kapselgröße rechnen wir selbst aus unseren Größen aus - nenne in der Machbarkeit KEINE Kapselgröße, sonst widersprechen sich die beiden Angaben.
  Wenn die Schüttdichte kritisch ist, schreib das als Hinweis (etwa "Füllgewicht in der Praxis prüfen"), ohne eine Größe zu nennen.
- Schreib knapp und auf Deutsch. Keine Werbesprache.
- Wenn die Idee des Kunden fachlich oder rechtlich nicht umsetzbar ist, sag das in "machbarkeit" deutlich und schlage die nächstbeste umsetzbare Variante vor.
TXT;
}

// Vorschlag entwickeln. $anfrage_id = rezeptur_anfrage. Rückgabe ['ok'=>bool, …].
function rezeptur_ki_entwickeln(int $anfrage_id): array {
    $a = one("SELECT * FROM rezeptur_anfrage WHERE id=?", [$anfrage_id]);
    if (!$a) return ['ok'=>false, 'fehler'=>'Anfrage nicht gefunden.'];
    if (!ki_bereit()) return ['ok'=>false, 'fehler'=>'Die KI ist nicht eingerichtet (Einstellungen → KI).'];

    $form = (string)($a['darreichungsform'] ?: 'kapsel');
    $katalog = rezeptur_ki_katalog();

    // Was der Kunde geschrieben hat – Idee, Produktname und seine Wunschzutaten.
    $text = "Idee des Kunden:\n" . trim((string)$a['notiz']);
    if (trim((string)$a['produktname']) !== '') $text .= "\n\nGewünschter Produktname: " . $a['produktname'];
    $wuensche = all("SELECT bezeichnung, wunsch_menge, einheit FROM rezeptur_anfrage_wunsch WHERE anfrage_id=? ORDER BY sort", [$anfrage_id]);
    if ($wuensche) {
        $text .= "\n\nDiese Zutaten hat der Kunde ausdrücklich genannt (sie sollen vorkommen, wenn es fachlich vertretbar ist):";
        foreach ($wuensche as $w) $text .= "\n- " . $w['bezeichnung'] . ' ' . trim((string)$w['wunsch_menge'] . ' ' . (string)$w['einheit']);
    }

    $r = ki_json($text, [
        'system'     => rezeptur_ki_anweisung($form, $katalog),
        'denken'     => true,
        'aufwand'    => 'high',
        'max_tokens' => 12000,
        'timeout'    => 300,
        'zweck'      => 'rezeptur-entwickeln',
    ]);
    if (!$r['ok']) return $r;
    $d = $r['daten'];

    // Zutaten aufbereiten und – wo möglich – auf unseren Katalog abbilden.
    $katalogUmgekehrt = [];
    foreach ($katalog as $iid => $nm) $katalogUmgekehrt[mb_strtolower($nm)] = $iid;
    $zutaten = []; $summe = 0.0;
    foreach ((array)($d['zutaten'] ?? []) as $z) {
        $bez = trim((string)($z['bezeichnung'] ?? ''));
        if ($bez === '') continue;
        $mg = (float) str_replace(',', '.', (string)($z['menge_mg'] ?? 0));
        $kat = trim((string)($z['katalog'] ?? ''));
        $iid = $kat !== '' ? ($katalogUmgekehrt[mb_strtolower($kat)] ?? null) : null;
        if (!$iid) $iid = rezeptur_ki_item_finden($bez);   // zweiter Versuch über den Namen
        $zutaten[] = [
            'bezeichnung' => mb_substr($bez, 0, 190),
            'menge_mg'    => $mg,
            'item_id'     => $iid,
            'item_name'   => $iid ? ($katalog[$iid] ?? (string) scalar("SELECT name FROM item WHERE id=?", [$iid])) : '',
            'funktion'    => mb_substr(trim((string)($z['funktion'] ?? '')), 0, 190),
            'begruendung' => mb_substr(trim((string)($z['begruendung'] ?? '')), 0, 400),
        ];
        $summe += $mg;
    }

    // Machbarkeit rechnen wir selbst nach: Was die KI schätzt, ist eine Meinung – das Füllgewicht
    // gegen unsere Kapselgrößen ist eine Tatsache.
    $kapsel = null;
    if (in_array($form, ['kapsel', 'softgel'], true) && $summe > 0) {
        $passend = one("SELECT id, name, fuellmenge_mg FROM kapselgroesse WHERE fuellmenge_mg >= ? ORDER BY fuellmenge_mg ASC LIMIT 1", [$summe]);
        $groesste = one("SELECT name, fuellmenge_mg FROM kapselgroesse ORDER BY fuellmenge_mg DESC LIMIT 1");
        $kapsel = [
            'fuellgewicht_mg' => round($summe, 1),
            'groesse'         => $passend['name'] ?? null,
            'groesse_id'      => $passend ? (int)$passend['id'] : null,
            'passt'           => (bool)$passend,
            'groesste'        => $groesste['name'] ?? '',
            'groesste_mg'     => $groesste ? (float)$groesste['fuellmenge_mg'] : 0,
        ];
    }

    return [
        'ok'            => true,
        'name'          => mb_substr(trim((string)($d['name'] ?? '')), 0, 190),
        'zutaten'       => $zutaten,
        'tagesdosis'    => mb_substr(trim((string)($d['tagesdosis'] ?? '')), 0, 190),
        'novel_food'    => rezeptur_ki_liste($d['novel_food'] ?? [], ['stoff', 'bewertung', 'begruendung']),
        'hoechstmengen' => rezeptur_ki_liste($d['hoechstmengen'] ?? [], ['stoff', 'menge_mg', 'bewertung', 'begruendung']),
        'health_claims' => rezeptur_ki_liste($d['health_claims'] ?? [], ['stoff', 'claim', 'zulaessig']),
        'machbarkeit'   => [
            'bewertung' => mb_substr(trim((string)($d['machbarkeit']['bewertung'] ?? '')), 0, 40),
            'gruende'   => array_slice(array_map(fn($g) => mb_substr(trim((string)$g), 0, 300), (array)($d['machbarkeit']['gruende'] ?? [])), 0, 8),
        ],
        'kapsel'        => $kapsel,
        'hinweise'      => array_slice(array_map(fn($g) => mb_substr(trim((string)$g), 0, 300), (array)($d['hinweise'] ?? [])), 0, 8),
        'usage'         => $r['usage'] ?? [],
        'modell'        => $r['modell'] ?? '',
        'stand'         => gmdate('c'),
    ];
}

// Hilfslisten kürzen und auf die erwarteten Felder beschränken.
function rezeptur_ki_liste($roh, array $felder): array {
    $out = [];
    foreach ((array)$roh as $z) {
        $zeile = [];
        foreach ($felder as $f) {
            $v = $z[$f] ?? '';
            $zeile[$f] = is_bool($v) ? $v : mb_substr(trim((string)$v), 0, 400);
        }
        if (trim((string)($zeile[$felder[0]] ?? '')) === '') continue;
        $out[] = $zeile;
    }
    return array_slice($out, 0, 30);
}

// Einen Rohstoff über den Namen suchen (exakt, dann enthalten).
function rezeptur_ki_item_finden(string $bez): ?int {
    $bez = trim($bez);
    if ($bez === '') return null;
    // Die KI haengt gern eine Erklaerung an ("Magnesiumbisglycinat (ca. 14 % Mg)"). Ohne die Klammer
    // trifft der Name unseren Stammdatensatz meistens genau.
    $ohne = trim(preg_replace('/\s*\([^)]*\)/u', '', $bez));
    foreach (array_unique([$bez, $ohne]) as $v) {
        if ($v === '') continue;
        $id = scalar("SELECT id FROM item WHERE kategorie='rohstoff' AND gesperrt=0 AND name=? LIMIT 1", [$v]);
        if ($id) return (int)$id;
    }
    foreach (array_unique([$bez, $ohne]) as $v) {
        if ($v === '') continue;
        $id = scalar("SELECT id FROM item WHERE kategorie='rohstoff' AND gesperrt=0 AND name LIKE ? LIMIT 1", ['%' . $v . '%']);
        if ($id) return (int)$id;
    }
    // Andersherum: unser Name steckt in der Bezeichnung der KI. Kurze Namen bleiben aussen vor,
    // sonst passt "Zink" auf alles; der laengste Treffer gewinnt, das ist der genaueste.
    $id = scalar("SELECT id FROM item WHERE kategorie='rohstoff' AND gesperrt=0 AND CHAR_LENGTH(name) >= 6
                  AND ? LIKE CONCAT('%', name, '%') ORDER BY CHAR_LENGTH(name) DESC LIMIT 1", [$bez]);
    return $id ? (int)$id : null;
}

// Vorschlag an der Anfrage merken / holen.
function rezeptur_ki_merken(int $anfrage_id, array $vorschlag): void {
    if ($anfrage_id <= 0 || empty($vorschlag['ok'])) return;
    q("UPDATE rezeptur_anfrage SET ki_daten=?, ki_stand=? WHERE id=?",
      [json_encode($vorschlag, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s'), $anfrage_id]);
}
function rezeptur_ki_vorschlag(int $anfrage_id): ?array {
    $j = scalar("SELECT ki_daten FROM rezeptur_anfrage WHERE id=?", [$anfrage_id]);
    if (!$j) return null;
    $d = json_decode((string)$j, true);
    return is_array($d) ? $d : null;
}

// Die Zutaten des Vorschlags als Wunschzeilen in die Anfrage schreiben – von dort baut das
// vorhandene Formular die Rezeptur. Ersetzt die bisherigen Zeilen.
function rezeptur_ki_zeilen_uebernehmen(int $anfrage_id, array $zutaten): int {
    if ($anfrage_id <= 0 || !$zutaten) return 0;
    q("DELETE FROM rezeptur_anfrage_wunsch WHERE anfrage_id=?", [$anfrage_id]);
    $n = 0;
    foreach ($zutaten as $z) {
        if (trim((string)($z['bezeichnung'] ?? '')) === '') continue;
        q("INSERT INTO rezeptur_anfrage_wunsch (anfrage_id,bezeichnung,wunsch_menge,einheit,notiz,item_id,menge_final,sort)
           VALUES (?,?,?,?,?,?,?,?)",
          [$anfrage_id, $z['bezeichnung'], rtrim(rtrim(number_format((float)$z['menge_mg'], 3, '.', ''), '0'), '.'), 'mg',
           mb_substr(trim((string)($z['funktion'] ?? '')), 0, 255) ?: null,
           $z['item_id'] ?: null, (float)$z['menge_mg'], $n]);
        $n++;
    }
    return $n;
}
