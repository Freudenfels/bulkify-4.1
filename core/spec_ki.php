<?php
// Lieferantenunterlagen (Spezifikation oder Analysenzertifikat) mit Claude auslesen.
//
// Warum die KI und nicht die Textsuche: `core/coa_lesen.php` sucht im PDF-Text nach Stichworten.
// Das scheitert an eingescannten Unterlagen (kein Text) und an jedem Layout, das nicht ins Muster
// passt. Die API bekommt das PDF als Dokument – sie liest auch Scans und versteht Tabellen.
//
// WICHTIG: Diese Funktionen SCHLAGEN nur vor. Gespeichert wird erst, wenn ein Mensch geprüft hat.
require_once __DIR__ . '/ki.php';
require_once __DIR__ . '/coa_lesen.php';

// Die Stammdatenfelder eines Rohstoffs, die in einer Spezifikation stehen können.
// Schlüssel = Spalte in `item`, Wert = [Klartext für die KI, Art].
function spec_ki_felder(): array {
    return [
        'name'             => ['Deutsche Bezeichnung des Rohstoffs (fremdsprachigen Produktnamen sinngemäß eindeutschen)', 'text'],
        'name_en'          => ['Englische Bezeichnung des Rohstoffs', 'text'],
        'name_lat'         => ['Lateinische/botanische Bezeichnung (z. B. Curcuma longa L.)', 'text'],
        'synonym'          => ['Synonym oder Kurzname', 'text'],
        'bot_quelle'       => ['Botanische Quelle mit Pflanzenteil (z. B. Curcuma longa, Wurzelstock)', 'text'],
        'cas'              => ['CAS-Nummer', 'text'],
        'ec_nr'            => ['EC- oder E-Nummer', 'text'],
        'herkunftsland'    => ['Herkunftsland des Rohstoffs', 'text'],
        'haltbarkeit'      => ['Mindesthaltbarkeit ab Herstellung (z. B. "24 Monate")', 'text'],
        'lagerbedingungen' => ['Lagerbedingungen (Temperatur, Feuchte, Licht)', 'text'],
        'zusaetze'         => ['Zusätze, Trägerstoffe, Fließmittel', 'text'],
        'allergene'        => ['Allergene; wenn ausdrücklich keine, dann "keine"', 'text'],
        'zertifikate'      => ['Zertifikate (z. B. Bio, Kosher, Halal, ISO, HACCP)', 'text'],
        'spec_nr'          => ['Spezifikations-Nummer des Lieferanten', 'text'],
        'spec_version'     => ['Version/Revision der Spezifikation', 'text'],
        'spec_gueltig_ab'  => ['Datum, ab dem die Spezifikation gilt', 'datum'],
        'vegan'            => ['Ist der Rohstoff als vegan ausgewiesen?', 'janein'],
        'gvo_frei'         => ['Ist er als GVO-frei / non-GMO ausgewiesen?', 'janein'],
        'bestrahlt'        => ['Wurde er bestrahlt (irradiated)? Steht "nicht bestrahlt", dann nein.', 'janein'],
        'tse_bse_frei'     => ['Ist er als TSE/BSE-frei ausgewiesen?', 'janein'],
        'dichte'           => ['Schüttdichte in g/ml als Zahl', 'zahl'],
    ];
}

// Der Auftrag an die KI. Bewusst streng: nichts erfinden, nichts umrechnen, nichts ergänzen.
function spec_ki_anweisung(): string {
    $felder = '';
    foreach (spec_ki_felder() as $k => $f) $felder .= "  \"$k\": " . $f[0] . ' [' . $f[1] . "]\n";
    $params = implode(', ', array_keys(coa_parameter()));
    // Offizielle NRV-Nährstoffnamen – die KI soll bei Wirkstoffen EXAKT diese Namen treffen,
    // damit die Nährwert-/NRV-Berechnung für den Kunden greift (sonst entsteht ein Eintrag ohne NRV).
    $nrvNamen = '';
    try {
        if (function_exists('all')) {
            $rows = all("SELECT name FROM naehrstoff WHERE ist_nrv=1 ORDER BY sort, name");
            $nrvNamen = implode(', ', array_map(fn($r) => (string)$r['name'], (array)$rows));
        }
    } catch (\Throwable $e) { $nrvNamen = ''; }
    if ($nrvNamen === '') $nrvNamen = 'Vitamin A, Vitamin D, Vitamin E, Vitamin K, Vitamin C, Thiamin (B1), Riboflavin (B2), Niacin (B3), Vitamin B6, Folsäure, Vitamin B12, Biotin, Pantothensäure, Kalium, Chlorid, Calcium, Phosphor, Magnesium, Eisen, Zink, Kupfer, Mangan, Fluorid, Selen, Chrom, Molybdän, Jod';

    return <<<TXT
Lies dieses Dokument eines Rohstoff-Lieferanten aus.

Bestimme zuerst die Art:
  "spec" = Spezifikation / Produktdatenblatt (beschreibt den Rohstoff allgemein, mit Grenzwerten)
  "coa"  = Analysenzertifikat / Certificate of Analysis (Messwerte EINER Charge)
  "beides" = enthält beides
  "unklar" = weder noch

Gib NUR dieses JSON zurück:
{
  "typ": "spec|coa|beides|unklar",
  "sicherheit": "hoch|mittel|niedrig",
  "stamm": { Felder siehe unten, nur die gefundenen },
  "wirkstoffe": [ { "name": "", "gehalt_prozent": null } ],
  "kennwerte": [ { "parameter": "", "wert": "" } ],
  "cas_vorschlag": null,
  "charge": { "charge_nr": null, "mhd": null, "herstelldatum": null, "menge": null, "einheit": null },
  "werte": [ { "parameter": "", "spezifikation": "", "ergebnis": "", "methode": "" } ],
  "hinweise": [ "kurze Anmerkungen, z. B. unleserliche Stellen" ]
}

Felder für "stamm":
$felder
Regeln, an die du dich halten musst:
- Übernimm NUR, was tatsächlich im Dokument steht. Nichts ergänzen, nichts aus Fachwissen ableiten, nichts raten. (Einzige Ausnahme: "cas_vorschlag", siehe unten.)
- Ein Feld, das nicht im Dokument steht, lässt du WEG (nicht null, nicht leerer Text).
- [datum] im Format JJJJ-MM-TT. [janein] als true oder false. [zahl] als Zahl ohne Einheit, Punkt als Dezimaltrennzeichen.
- [text]: ZIELSPRACHE IST DEUTSCH. Übersetze fremdsprachige Inhalte (auch Chinesisch) sinngemäß ins Deutsche – in KEINEM Feld darf fremde Schrift (chinesische Zeichen o. Ä.) stehen. Inhaltlich nichts hinzufügen, nur übersetzen und höchstens kürzen. UNVERÄNDERT bleiben nur: der lateinische/botanische Name (name_lat und der lateinische Teil der botanischen Quelle), Codes wie CAS/EC-Nummer und Spec-Nr./Version, sowie Zahlen, Einheiten und Grenzzeichen. Das gilt für ALLE Textfelder (auch Lagerbedingungen, Zusätze, Allergene, Herkunft, Zertifikate, Haltbarkeit).
- Sprache der Namensfelder: "name" ist IMMER Deutsch, "name_en" ist IMMER Englisch. Liegt der Produktname nur in einer Sprache vor, übersetze ihn für das jeweils andere Feld. Steht ein Name schon in der Zielsprache, unverändert übernehmen.
- Namens-Reihenfolge (beide Namensfelder): Der Name MUSS mit der Substanz/Bezeichnung beginnen, damit er alphabetisch auffindbar ist. Steht am ANFANG eine Prozent-/Gehalts-/Standardisierungsangabe, stelle sie ans ENDE (Wert unverändert). Beispiele: "50% Resveratrol Liposome" -> name_en "Resveratrol Liposome 50%", name "Resveratrol-Liposom 50%"; "Murraya koenigii leaf 3.0% Ferrous (Iron) powder extract" -> name_en unverändert (beginnt schon mit der Substanz), name "Murraya koenigii Blattextrakt Eisen (Ferrous) 3,0%".
- "wirkstoffe": die standardisierten Wirk-/Leitsubstanzen mit ihrem Gehalt. Der Assay bzw. die Standardisierung gehört hierher (z. B. "Assay [Content of Iron] NLT 3.0% NMT 5.0%", "Standardisation: Iron content" -> name "Eisen", gehalt_prozent 3.0). Bei einer Spanne den unteren Wert (NLT/min) als gehalt_prozent nehmen. Bei "name" den gebräuchlichen DEUTSCHEN bzw. international etablierten Substanznamen verwenden – KEINEN chinesischen/fremdsprachigen Text (z. B. 白藜芦醇 -> "Resveratrol", 维生素C -> "Vitamin C", "Iron" -> "Eisen"). WICHTIG: Entspricht der Wirkstoff einem dieser offiziellen NRV-Nährstoffe, nimm GENAU dessen Schreibweise aus dieser Liste (damit die NRV-Berechnung greift): $nrvNamen. Nur wenn keiner passt, einen eigenen Namen. Keine Verunreinigungen/Trägerstoffe, keine Excipients. Steht kein Gehalt, gehalt_prozent null. Nichts gefunden -> leere Liste.
- "kennwerte": charakteristische, unterscheidende Kennwerte als Parameter+Wert-Paare: Assay-Spanne, Extraktverhältnis/DEV (z. B. "10:1"), pH, Schüttdichte, Partikelgröße/Mesh, Wassergehalt/Loss on Drying, Löslichkeit. NICHT die reinen Sicherheits-Grenzwerte (Schwermetalle, Mikrobiologie, Mykotoxine, Pestizide, Lösungsmittelreste) – die bleiben im PDF. "parameter" und "wert" auf DEUTSCH mit lateinischen Zahlen/Einheiten – KEIN chinesischer/fremdsprachiger Text und keine fremde Schrift. Fremdsprachige Bezeichnungen und Werte sinngemäß übersetzen (z. B. Parameter "含量" -> "Gehalt", "目数" -> "Partikelgröße (Mesh)"; Wert "95% 以上通过 40 目筛" -> "95 % passieren 40 mesh"). Substanznamen im Parameter wie bei den Wirkstoffen eindeutschen ("Gehalt (Resveratrol)", nicht 白藜芦醇). Zahlen und Grenzzeichen (≥, ≤, %, :) unverändert lassen (z. B. "≥ 50 %", "10:1").
- "cas_vorschlag": NUR füllen, wenn im Dokument KEINE CAS-Nummer steht (oder dort "TBA"/"n/a") UND der Rohstoff ein eindeutiger Reinstoff mit allgemein bekannter CAS ist (z. B. Vitamine, definierte Salze/Verbindungen). Dann die bekannte CAS als Vorschlag. Bei Pflanzenextrakten, Mischungen oder Unsicherheit: null. Wenn gesetzt, in "hinweise" vermerken, dass die CAS aus Fachwissen kommt und geprüft werden muss.
- "werte": jede Zeile der Analysetabelle. "spezifikation" ist der Grenzwert (z. B. "≤ 3 ppm"), "ergebnis" der gemessene Wert. Steht nur eins von beidem, lass das andere leer.
- Gebräuchliche Parameternamen, wenn sie passen: $params. Sonst den Namen aus dem Dokument.
- Bei "sicherheit": "niedrig", wenn das Dokument schlecht lesbar ist oder du viel raten müsstest.
TXT;
}

// Ein Dokument auslesen. Rückgabe:
//   ['ok'=>true, 'typ'=>…, 'sicherheit'=>…, 'stamm'=>[…], 'charge'=>[…], 'werte'=>[…], 'hinweise'=>[…], 'usage'=>[…]]
//   ['ok'=>false, 'fehler'=>'Klartext']
function spec_ki_lesen(string $pfad): array {
    if (!ki_bereit()) return ['ok'=>false, 'fehler'=>'Die KI ist nicht eingerichtet (Einstellungen → KI).'];
    $r = ki_datei_frage($pfad, spec_ki_anweisung(), [
        'json'       => true,
        'denken'     => true,          // Tabellen und Grenzwerte sauber zu lesen lohnt das Nachdenken
        'max_tokens' => 8000,
        'timeout'    => 240,
        'zweck'      => 'spec-lesen',
    ]);
    if (!$r['ok']) return $r;

    $d = $r['daten'];
    $erlaubt = spec_ki_felder();
    $stamm = [];
    foreach ((array)($d['stamm'] ?? []) as $k => $v) {
        if (!isset($erlaubt[$k]) || $v === null || $v === '') continue;
        $stamm[$k] = spec_ki_wert($v, $erlaubt[$k][1]);
    }
    $werte = [];
    foreach ((array)($d['werte'] ?? []) as $z) {
        $p = trim((string)($z['parameter'] ?? ''));
        if ($p === '') continue;
        $werte[] = [
            'parameter'     => mb_substr($p, 0, 120),
            'spezifikation' => mb_substr(trim((string)($z['spezifikation'] ?? '')), 0, 120),
            'ergebnis'      => mb_substr(trim((string)($z['ergebnis'] ?? '')), 0, 120),
            'methode'       => mb_substr(trim((string)($z['methode'] ?? '')), 0, 120),
        ];
    }
    // Standardisierte Wirkstoffe (Assay/Standardisierung) – gehören in die Wirkstoff-Tabelle des Rohstoffs.
    $wirkstoffe = [];
    foreach ((array)($d['wirkstoffe'] ?? []) as $w) {
        $nm = trim((string)($w['name'] ?? ''));
        if ($nm === '') continue;
        $g = $w['gehalt_prozent'] ?? null;
        $wirkstoffe[] = [
            'name'           => mb_substr($nm, 0, 120),
            'gehalt_prozent' => ($g === null || $g === '') ? null : (float) str_replace(',', '.', (string)$g),
        ];
    }
    // Charakteristische Kennwerte (Assay-Spanne, Extraktverhältnis, pH, Dichte …) – ohne Sicherheits-Grenzwerte.
    $kennwerte = [];
    foreach ((array)($d['kennwerte'] ?? []) as $k) {
        $p = trim((string)($k['parameter'] ?? ''));
        if ($p === '') continue;
        $kennwerte[] = ['parameter' => mb_substr($p, 0, 120), 'wert' => mb_substr(trim((string)($k['wert'] ?? '')), 0, 120)];
    }
    $casVorschlag = trim((string)($d['cas_vorschlag'] ?? ''));
    if ($casVorschlag !== '') $casVorschlag = mb_substr($casVorschlag, 0, 30);
    $charge = [];
    foreach (['charge_nr', 'mhd', 'herstelldatum', 'menge', 'einheit'] as $k) {
        $v = $d['charge'][$k] ?? null;
        if ($v === null || $v === '') continue;
        $charge[$k] = in_array($k, ['mhd', 'herstelldatum'], true) ? spec_ki_wert($v, 'datum') : trim((string)$v);
    }
    $typ = (string)($d['typ'] ?? 'unklar');
    return [
        'ok'          => true,
        'typ'         => in_array($typ, ['spec', 'coa', 'beides', 'unklar'], true) ? $typ : 'unklar',
        'sicherheit'  => in_array(($d['sicherheit'] ?? ''), ['hoch', 'mittel', 'niedrig'], true) ? $d['sicherheit'] : 'mittel',
        'stamm'       => $stamm,
        'wirkstoffe'  => $wirkstoffe,
        'kennwerte'   => $kennwerte,
        'cas_vorschlag' => $casVorschlag,
        'charge'      => $charge,
        'werte'       => $werte,
        'hinweise'    => array_slice(array_map(fn($h) => mb_substr(trim((string)$h), 0, 300), (array)($d['hinweise'] ?? [])), 0, 8),
        'usage'       => $r['usage'] ?? [],
        'modell'      => $r['modell'] ?? '',
    ];
}

// Einen Wert in die Form bringen, die die Spalte erwartet.
function spec_ki_wert($v, string $art) {
    if ($art === 'janein') return is_bool($v) ? ($v ? 1 : 0) : (in_array(mb_strtolower(trim((string)$v)), ['ja','yes','true','1'], true) ? 1 : 0);
    if ($art === 'zahl')   return (float) str_replace(',', '.', (string)$v);
    if ($art === 'datum')  { $t = strtotime((string)$v); return $t ? date('Y-m-d', $t) : null; }
    return mb_substr(trim((string)$v), 0, 250);
}

// Direkt nach einem Upload auslesen und den Vorschlag am Dokument merken. Laeuft nur fuer
// Spezifikationen und CoA (nicht fuer Rechnungen o. ae.) und nur, wenn die KI eingerichtet ist.
// Gibt zurueck, ob etwas gelesen wurde - der Aufrufer entscheidet, ob er das anzeigt.
function spec_ki_nach_upload(int $dokument_id): bool {
    if ($dokument_id <= 0 || !ki_bereit()) return false;
    $d = one("SELECT typ, datei, objekt_typ, objekt_id, lieferant_id FROM dokument WHERE id=?", [$dokument_id]);
    if (!$d || !in_array((string)$d['typ'], ['coa', 'spec', 'analyse'], true)) return false;
    $pfad = BX_UPLOADS . '/' . basename((string)$d['datei']);
    if (!is_file($pfad)) return false;
    $r = spec_ki_lesen($pfad);
    if (!$r['ok']) return false;
    spec_ki_merken($dokument_id, $r);
    // Gehört das Dokument zu einem Rohstoff, dann direkt verwerten – egal ob Team oder Lieferant es
    // hochgeladen hat: bei einer CoA eine (Vorab-)Charge anlegen (mit dem hochladenden Lieferanten,
    // falls bekannt) und die Grenzwerte am Rohstoff ergänzen. Idempotent über die Chargennummer.
    if ((string)$d['objekt_typ'] === 'item' && (int)$d['objekt_id'] > 0) {
        $lief = !empty($d['lieferant_id']) ? (int)$d['lieferant_id'] : null;
        spec_ki_coa_charge((int)$d['objekt_id'], $r, $lief);
        spec_ki_grenzwerte((int)$d['objekt_id'], $r);
    }
    return true;
}

// Vorschlag am Dokument merken, damit ihn das Team später prüfen kann (auch wenn der Lieferant
// die Datei hochgeladen hat). Gespeichert wird der Rohvorschlag als JSON, nichts an den Stammdaten.
function spec_ki_merken(int $dokument_id, array $ergebnis): void {
    if ($dokument_id <= 0 || empty($ergebnis['ok'])) return;
    q("UPDATE dokument SET ki_daten=?, ki_stand=? WHERE id=?",
      [json_encode($ergebnis, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s'), $dokument_id]);
}
// Gemerkten Vorschlag zu einem Dokument holen (null = keiner da).
function spec_ki_vorschlag(int $dokument_id): ?array {
    $j = scalar("SELECT ki_daten FROM dokument WHERE id=?", [$dokument_id]);
    if (!$j) return null;
    $d = json_decode((string)$j, true);
    return is_array($d) ? $d : null;
}

// Vorschlag in die Stammdaten des Rohstoffs übernehmen. $felder = Liste der Spalten, die der
// Mensch freigegeben hat. Leere Felder am Artikel werden gefüllt; vorhandene nur mit $ueberschreiben.
// Rückgabe: Anzahl der geänderten Felder.
function spec_ki_uebernehmen(int $item_id, array $stamm, array $felder, bool $ueberschreiben = false): int {
    $erlaubt = spec_ki_felder();
    $it = one("SELECT * FROM item WHERE id=?", [$item_id]);
    if (!$it) return 0;
    $n = 0;
    foreach ($felder as $k) {
        if (!isset($erlaubt[$k]) || !array_key_exists($k, $stamm)) continue;
        $alt = $it[$k] ?? null;
        if (!$ueberschreiben && $alt !== null && trim((string)$alt) !== '') continue;
        q("UPDATE item SET `$k`=? WHERE id=?", [$stamm[$k], $item_id]);
        $n++;
    }
    if ($n) log_aktivitaet('item', $item_id, 'team', $n . ' Feld(er) aus einer Lieferantenunterlage übernommen (KI-Vorschlag, geprüft).', 'dokument', 'item', $item_id);
    return $n;
}

// Aus einer erfassten CoA sofort eine Charge anlegen – auch VOR dem Wareneingang, damit die
// Unterlage nicht verloren geht (wichtig für Import/Schriftverkehr). Menge 0, wareneingang NULL,
// Status quarantaene = „CoA vorab, Ware noch nicht da". Bei der Warenannahme wird die Charge über
// die Chargennummer abgeglichen (wareneingang_buchen) und mit Menge/Datum eingebucht.
// Idempotent: existiert schon eine (Vorab-)Charge mit derselben Nummer, werden nur die Analysewerte
// aktualisiert. Rückgabe: charge_id oder null.
function spec_ki_coa_charge(int $item_id, array $ergebnis, ?int $lieferant_id = null): ?int {
    if ($item_id <= 0) return null;
    $typ    = (string)($ergebnis['typ'] ?? '');
    $werte  = (array)($ergebnis['werte'] ?? []);
    $chgNr  = trim((string)($ergebnis['charge']['charge_nr'] ?? ''));
    // Nur bei CoA-Charakter (gemessene Werte einer Charge). Reine Spezifikation ohne Charge -> keine Charge.
    if (!in_array($typ, ['coa', 'beides'], true) && $chgNr === '') return null;
    if (!$werte && $chgNr === '') return null;
    $it = one("SELECT einheit FROM item WHERE id=?", [$item_id]);
    if (!$it) return null;
    $mhd = spec_ki_wert((string)($ergebnis['charge']['mhd'] ?? ''), 'datum');

    // Vorhandene Charge gleicher Nummer wiederverwenden (keine Dublette), sonst neu anlegen.
    $charge_id = 0;
    if ($chgNr !== '') $charge_id = (int) scalar("SELECT id FROM charge WHERE item_id=? AND charge_nr=? ORDER BY id LIMIT 1", [$item_id, $chgNr]);
    if (!$charge_id) {
        q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,lieferant_id,mhd,wareneingang,status,notiz,angelegt)
           VALUES (?,?,0,0,?,?,?,NULL,'quarantaene',?,?)",
          [$chgNr ?: null, $item_id, $it['einheit'], $lieferant_id ?: null, $mhd ?: null,
           'CoA vorab erfasst – Ware noch nicht eingegangen', gmdate('Y-m-d H:i:s')]);
        $charge_id = (int) insert_id();
    } elseif ($mhd) {
        q("UPDATE charge SET mhd=COALESCE(mhd,?) WHERE id=?", [$mhd, $charge_id]);
    }
    if ($werte) spec_ki_werte_speichern($charge_id, $werte);
    return $charge_id ?: null;
}

// Reinheits-/Sicherheits-Grenzwerte dauerhaft AM ROHSTOFF speichern (item_grenzwert), aus der
// Spezifikation/CoA. Nimmt alle Werte mit einem Grenzwert (spezifikation). Ersetzt bestehende nur,
// wenn $ueberschreiben=true; sonst werden fehlende ergänzt. Rückgabe: Anzahl gespeicherter Zeilen.
function spec_ki_grenzwerte(int $item_id, array $ergebnis, bool $ueberschreiben = false): int {
    if ($item_id <= 0) return 0;
    $werte = (array)($ergebnis['werte'] ?? []);
    $rows = [];
    foreach ($werte as $z) {
        $p = trim((string)($z['parameter'] ?? ''));
        $g = trim((string)($z['spezifikation'] ?? ''));
        if ($p === '' || $g === '') continue;   // nur echte Grenzwerte
        $rows[$p] = $g;   // je Parameter der letzte Grenzwert
    }
    if (!$rows) return 0;
    if ($ueberschreiben) q("DELETE FROM item_grenzwert WHERE item_id=?", [$item_id]);
    $n = 0; $sort = (int) scalar("SELECT COALESCE(MAX(sort),-1)+1 FROM item_grenzwert WHERE item_id=?", [$item_id]);
    foreach ($rows as $p => $g) {
        if (!$ueberschreiben && scalar("SELECT id FROM item_grenzwert WHERE item_id=? AND parameter=?", [$item_id, $p])) continue;
        q("INSERT INTO item_grenzwert (item_id,parameter,grenzwert,sort) VALUES (?,?,?,?)", [$item_id, mb_substr($p,0,120), mb_substr($g,0,120), $sort++]);
        $n++;
    }
    return $n;
}

// Analysewerte eines CoA an einer Charge speichern (ersetzt die bisherigen Zeilen).
function spec_ki_werte_speichern(int $charge_id, array $werte): int {
    if ($charge_id <= 0 || !$werte) return 0;
    q("DELETE FROM charge_analyse WHERE charge_id=?", [$charge_id]);
    $n = 0;
    foreach ($werte as $i => $z) {
        if (trim((string)($z['parameter'] ?? '')) === '') continue;
        q("INSERT INTO charge_analyse (charge_id,parameter,spezifikation,ergebnis,methode,sort) VALUES (?,?,?,?,?,?)",
          [$charge_id, $z['parameter'], ($z['spezifikation'] ?? '') ?: null, ($z['ergebnis'] ?? '') ?: null, ($z['methode'] ?? '') ?: null, $i]);
        $n++;
    }
    return $n;
}
