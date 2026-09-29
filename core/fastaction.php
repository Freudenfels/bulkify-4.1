<?php
// Fastaction – kurze, wichtige Nachricht (oft eine Kundenanfrage) reinwerfen; Claude fasst zusammen,
// schlaegt konkrete naechste Schritte im ERP vor und erkennt Kunde/Rezeptur/Produkt/Menge. Die Seite
// legt daraus automatisch eine Aufgabe an (nichts geht verloren) und zeigt die Vorschlaege.
require_once __DIR__ . '/ki.php';

function fastaction_prompt(): string {
    return <<<TXT
Du bist Assistent im ERP eines Nahrungsergaenzungs-Lohnherstellers (Marke bulkify). Ein Mitarbeiter wirft
dir eine kurze, aber wichtige Nachricht rein – oft eine Kundenanfrage (z. B. eine Nachbestellung oder die
Bitte um ein Angebot), manchmal mit angehaengter Datei/Bild. Analysiere die Nachricht und schlage konkrete
naechste Schritte im ERP vor.

Gib AUSSCHLIESSLICH dieses JSON zurueck:
{
  "zusammenfassung": "1 kurzer Satz, worum es geht",
  "aufgabe": "was das Team konkret tun muss, als Imperativ in 1 Satz",
  "dringlichkeit": "hoch|mittel|niedrig",
  "erkannt": { "kunde": null, "rezeptur": null, "produkt": null, "menge": null, "einheit": null },
  "rezepturen": [
    { "name": "Kurzname/Bezeichnung der Rezeptur", "darreichungsform": "kapsel|tablette|softgel|stick|pulver|granulat|fluessig",
      "zutaten": [ { "bezeichnung": "Rohstoffname wie auf der Vorlage, z. B. 'Bacopa Monnieri Extrakt 10:1'", "menge_mg": 150 } ],
      "zutaten_text": "dieselben Wirkstoffe als Fliesstext, z. B. 'Bacopa Monnieri Extrakt 10:1 150mg, MCC 50mg'" }
  ],
  "vorschlaege": [
    { "text": "konkreter Handlungsvorschlag als Frage, z. B. 'Fuer Kunde X und Rezeptur Y ein Angebot ueber 1.000 Dosen anlegen?'",
      "typ": "angebot|anfrage|nachricht|bestellung|produktion|sonstiges",
      "rezeptur": null, "produkt": null, "menge": null, "einheit": null }
  ]
}

Regeln:
- Nichts erfinden. Erkannte Namen/Mengen NUR uebernehmen, wenn sie in der Nachricht/Datei stehen; sonst null.
- "menge" als Zahl (ohne Tausenderpunkt), "einheit" z. B. "Dosen", "Stueck", "kg".
- "rezepturen": NUR wenn in der Nachricht/Datei eine konkrete Rezeptur/Zusammensetzung steht (z. B. auf einer alten
  Rechnung/Spezifikation). Je Rezeptur: Name + Darreichungsform + die einzelnen Zutaten (bezeichnung + menge_mg als
  Zahl in Milligramm) + zutaten_text. "menge_mg" nur wenn die Menge dasteht, sonst 0. Sonst leere Liste [].
- Pro Vorschlag, wenn es um ein KONKRETES Produkt/Rezeptur mit Menge geht (z. B. mehrere Positionen einer Bestellung),
  je EINEN Vorschlag mit rezeptur/produkt-Name (wie in der Nachricht) + menge (Zahl) + einheit. Sonst diese Felder null.
- 1 bis 5 Vorschlaege, der wichtigste zuerst. Alles auf Deutsch.
- Wenn unklar, "dringlichkeit" auf "mittel" und einen Vorschlag "beim Kunden nachfragen".
TXT;
}

// Analysiert Text (+ optionale Datei). Rueckgabe: ['ok'=>true,'daten'=>[...]] oder ['ok'=>false,'fehler'=>'...'].
function fastaction_analyse(string $text, ?string $pfad = null): array {
    if (!ki_bereit()) return ['ok' => false, 'fehler' => 'Die KI ist nur auf beta verfuegbar (Schluessel serverseitig).'];
    $text = trim($text);
    $opt = ['json' => true, 'denken' => true, 'max_tokens' => 2000, 'timeout' => 180, 'zweck' => 'fastaction'];
    if ($pfad !== null && is_file($pfad)) {
        $anw = fastaction_prompt() . "\n\nZusaetzlicher Text vom Mitarbeiter:\n" . ($text !== '' ? $text : '(kein Text – bitte die Datei auswerten)');
        $r = ki_datei_frage($pfad, $anw, $opt);
    } else {
        if ($text === '') return ['ok' => false, 'fehler' => 'Bitte einen Text eingeben oder eine Datei hochladen.'];
        $r = ki_json($text, ['system' => fastaction_prompt()] + $opt);
    }
    if (!$r['ok']) return ['ok' => false, 'fehler' => $r['fehler'] ?? 'Die KI konnte die Nachricht nicht auswerten.'];
    $d = $r['daten'] ?? null;
    if (!is_array($d)) return ['ok' => false, 'fehler' => 'Unerwartete Antwort der KI.'];
    return ['ok' => true, 'daten' => $d, 'usage' => $r['usage'] ?? [], 'modell' => $r['modell'] ?? ''];
}

// Erkannte Namen zu echten Datensaetzen aufloesen (fuer Direkt-Links). Gibt ['kunde'=>?,'rezeptur'=>?,'produkt'=>?].
function fastaction_aufloesen(array $erkannt): array {
    $res = ['kunde' => null, 'rezeptur' => null, 'produkt' => null];
    $kn = trim((string)($erkannt['kunde'] ?? ''));
    if ($kn !== '') $res['kunde'] = one("SELECT id, firma FROM kunden WHERE firma LIKE ? ORDER BY (LOWER(firma)=LOWER(?)) DESC, LENGTH(firma) LIMIT 1", ['%' . $kn . '%', $kn]);
    $rn = trim((string)($erkannt['rezeptur'] ?? ''));
    if ($rn !== '') $res['rezeptur'] = one("SELECT id, name FROM rezeptur WHERE name LIKE ? ORDER BY (LOWER(name)=LOWER(?)) DESC, LENGTH(name) LIMIT 1", ['%' . $rn . '%', $rn]);
    $pn = trim((string)($erkannt['produkt'] ?? ''));
    if ($pn !== '') $res['produkt'] = one("SELECT id, name FROM produkt WHERE name LIKE ? ORDER BY (LOWER(name)=LOWER(?)) DESC, LENGTH(name) LIMIT 1", ['%' . $pn . '%', $pn]);
    return $res;
}

// Dringlichkeit -> Prioritaet (1=hoch,2=mittel,3=niedrig).
function fastaction_prio(string $dringlichkeit): int {
    return ['hoch' => 1, 'mittel' => 2, 'niedrig' => 3][strtolower(trim($dringlichkeit))] ?? 2;
}

// Aus der KI-Auswertung eine persistente Fastaction-Notiz + abhakbare ToDo-Items (die Vorschlaege) anlegen.
// So bleibt nach dem Auswerten alles erhalten und kann spaeter Punkt fuer Punkt abgearbeitet werden.
function fastaction_notiz_anlegen(array $d, array $auf_e, string $eingabe, ?string $datei, string $origName, ?int $uid): int {
    q("INSERT INTO fastaction_notiz (eingabe,zusammenfassung,aufgabe_text,dringlichkeit,kunde_id,rezeptur_id,produkt_id,datei,datei_orig,erstellt_von,angelegt)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)",
      [$eingabe ?: null, mb_substr((string)($d['zusammenfassung'] ?? ''), 0, 255) ?: null,
       mb_substr((string)($d['aufgabe'] ?? ''), 0, 255) ?: null, (string)($d['dringlichkeit'] ?? 'mittel'),
       $auf_e['kunde']['id'] ?? null, $auf_e['rezeptur']['id'] ?? null, $auf_e['produkt']['id'] ?? null,
       $datei ? basename($datei) : null, $origName ?: null, $uid, gmdate('Y-m-d H:i:s')]);
    $nid = (int) insert_id();
    $sort = 0;
    foreach ((array)($d['vorschlaege'] ?? []) as $v) {
        $text = trim((string)($v['text'] ?? '')); if ($text === '') continue;
        $typ = (string)($v['typ'] ?? 'sonstiges');
        // Rezeptur/Produkt aus dem Vorschlag aufloesen (fuer die Ein-Klick-Aktion). Fallback: die erkannten
        // Haupt-Entitaeten der Nachricht.
        $rn = trim((string)($v['rezeptur'] ?? ''));
        $pn = trim((string)($v['produkt'] ?? ''));
        $rid = $rn !== '' ? (int) scalar("SELECT id FROM rezeptur WHERE name LIKE ? ORDER BY (LOWER(name)=LOWER(?)) DESC, LENGTH(name) LIMIT 1", ['%' . $rn . '%', $rn]) : 0;
        $pid = $pn !== '' ? (int) scalar("SELECT id FROM produkt WHERE COALESCE(NULLIF(kundenname,''),name) LIKE ? ORDER BY (LOWER(name)=LOWER(?)) DESC, LENGTH(name) LIMIT 1", ['%' . $pn . '%', $pn]) : 0;
        if (!$rid && !$pid) { $rid = $auf_e['rezeptur']['id'] ?? 0; $pid = $auf_e['produkt']['id'] ?? 0; }
        if (!$pid && $rid) { }   // Rezeptur reicht fuer Angebot/Preisanfrage
        $menge = ($v['menge'] ?? null) !== null && $v['menge'] !== '' ? (float) str_replace(',', '.', (string)$v['menge']) : null;
        $aktion = in_array($typ, ['angebot','bestellung'], true) ? 'angebot'
                : ($typ === 'anfrage' ? 'lieferantenpreise'
                : ($typ === 'nachricht' ? 'kunde' : ($typ === 'produktion' ? 'produktion' : 'sonstiges')));
        q("INSERT INTO fastaction_item (notiz_id,typ,text,rezeptur_id,produkt_id,menge,einheit,aktion,sort)
           VALUES (?,?,?,?,?,?,?,?,?)",
          [$nid, $typ, mb_substr($text, 0, 500), $rid ?: null, $pid ?: null, $menge,
           mb_substr(trim((string)($v['einheit'] ?? '')), 0, 20) ?: null, $aktion, $sort++]);
    }
    return $nid;
}

// Nachbestell-Angebot: das juengste Angebot des Kunden als ENTWURF klonen (gleiche Positionen, Preise,
// Verpackung) – Grundlage fuer die Nachbestellung; Mengen prueft/aendert der Mensch, dann senden. Rueckgabe:
// [neues_angebot_id, quelle_id] oder [0,0] wenn es kein fruederes Angebot gibt.
function fastaction_nachbestell_angebot(int $kunde_id): array {
    if ($kunde_id <= 0) return [0, 0];
    $src = one("SELECT * FROM angebot WHERE kunde_id=? AND (SELECT COUNT(*) FROM angebot_position WHERE angebot_id=angebot.id) > 0 ORDER BY id DESC LIMIT 1", [$kunde_id]);
    if (!$src) return [0, 0];
    q("INSERT INTO angebot (nummer,kunde_id,produkt_id,status,notiz,marge_override,produktionszeit_wochen) VALUES (?,?,?,?,?,?,?)",
      [naechste_nummer('AN'), $kunde_id, $src['produkt_id'] ?: null, 'offen',
       'Nachbestellung (aus Fastaction) – geklont aus ' . (string)$src['nummer'] . '. Mengen prüfen, dann senden.',
       $src['marge_override'] ?? null, $src['produktionszeit_wochen'] ?? null]);
    $nid = (int) insert_id();
    $sort = 0;
    foreach (all("SELECT * FROM angebot_position WHERE angebot_id=? ORDER BY sort, id", [(int)$src['id']]) as $p) {
        q("INSERT INTO angebot_position (angebot_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,ek_cent,mwst_satz,quelle,gruppe,rezeptur_id,stueck,verpackung_id)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
          [$nid, $sort++, $p['artikelnr'], $p['bezeichnung'], $p['beschreibung'], $p['menge'], $p['einheit'],
           $p['preis_cent'], $p['ek_cent'], $p['mwst_satz'], $p['quelle'], $p['gruppe'], $p['rezeptur_id'], $p['stueck'], $p['verpackung_id']]);
    }
    return [$nid, (int)$src['id']];
}

// Primaere Ein-Klick-Aktion je ToDo-Punkt: [label, href, primary]. Nutzt das strukturierte Ziel des Items
// (Rezeptur/Produkt/Menge) und den Kunden der Notiz. Leerer href = keine sinnvolle Direktaktion.
function fastaction_item_link(array $item, ?int $kunde_id): array {
    $rid = (int)($item['rezeptur_id'] ?? 0);
    $pid = (int)($item['produkt_id'] ?? 0);
    $menge = ($item['menge'] ?? null) !== null ? (int) round((float)$item['menge']) : 0;
    // Wirksame Aktion: gespeicherte Aktion, sonst aus dem Typ, sonst aus dem Text erkennen (robust, falls die
    // KI den Punkt nicht klar klassifiziert hat).
    $aktion = (string)($item['aktion'] ?? '');
    $typ = (string)($item['typ'] ?? '');
    $txt = mb_strtolower((string)($item['text'] ?? ''));
    if ($aktion === '' || $aktion === 'sonstiges') {
        if (in_array($typ, ['angebot','bestellung'], true)) $aktion = 'angebot';
        elseif ($typ === 'anfrage') $aktion = 'lieferantenpreise';
        elseif ($typ === 'nachricht') $aktion = 'kunde';
    }
    if ($aktion === '' || $aktion === 'sonstiges') {
        if (preg_match('/angebot|nachbestell|anlegen und versenden|dosen|packung/u', $txt)) $aktion = 'angebot';
        elseif (preg_match('/lieferantenpreis|beschaffung|rohstoff|karton|einkauf|bestand/u', $txt)) $aktion = 'lieferantenpreise';
        elseif (preg_match('/anrufen|nachfrage|nachfragen|kontakt|melden|best[äa]tigen/u', $txt)) $aktion = 'kunde';
    }
    $notizTxt = trim((($rid ? (string) scalar("SELECT name FROM rezeptur WHERE id=?", [$rid]) : ($pid ? (string) scalar("SELECT COALESCE(NULLIF(kundenname,''),name) FROM produkt WHERE id=?", [$pid]) : '')))
              . ($menge > 0 ? ' · ' . number_format($menge, 0, ',', '.') . ' ' . ((string)($item['einheit'] ?? '') ?: 'Stück') : ''));
    $notizTxt = $notizTxt !== '' ? 'Aus Fastaction: ' . $notizTxt : 'Aus Fastaction';
    if ($aktion === 'angebot') {
        $q = '?p=angebot&id=neu' . ($kunde_id ? '&kunde_id=' . $kunde_id : '') . '&fa_notiz=' . rawurlencode($notizTxt);
        return ['Angebot anlegen', $q, true];
    }
    if ($aktion === 'lieferantenpreise') {
        if ($rid) return ['Lieferantenpreise', '?p=rezeptur_detail&id=' . $rid, true];
        return ['Einkauf öffnen', '?p=preis_anfragen', true];
    }
    if ($aktion === 'kunde' && $kunde_id) return ['Kunde öffnen', '?p=kunde&id=' . $kunde_id, false];
    if ($aktion === 'produktion') return ['Produktion', '?p=produktion', false];
    // Fallback: das konkreteste vorhandene Ziel öffnen.
    if ($rid) return ['Rezeptur öffnen', '?p=rezeptur_detail&id=' . $rid, false];
    if ($pid) return ['Produkt öffnen', '?p=produkt&id=' . $pid, false];
    if ($kunde_id) return ['Kunde öffnen', '?p=kunde&id=' . $kunde_id, false];
    return ['', '', false];
}

// Rezeptur als ENTWURF anlegen – mit VORAUSGEFUELLTEN Zutaten-Zeilen. Je Zutat wird per Best-Match ein
// Rohstoff vorgeschlagen (rezeptur_ki_item_finden); wo keiner passt, bleibt die Zeile mit Bezeichnung + Menge
// stehen (item_id leer -> der Mensch waehlt den Rohstoff). Der zutaten_text bleibt zusaetzlich in der Notiz.
// $zutaten: Liste [ ['bezeichnung'=>..., 'menge_mg'=>...], ... ]. Rueckgabe: [rezeptur_id, gematcht, gesamt].
function fastaction_rezeptur_entwurf(string $name, string $form, array $zutaten, string $zutaten_text = ''): array {
    require_once __DIR__ . '/rezeptur_ki.php';
    $name = trim($name) ?: 'Neue Rezeptur';
    $erlaubt = ['kapsel','tablette','softgel','stick','pulver','granulat','fluessig','gummi'];
    $form = in_array($form, $erlaubt, true) ? $form : 'kapsel';
    $notiz = 'Aus Fastaction angelegt.' . ($zutaten_text !== '' ? "\nZutaten laut Vorlage: " . $zutaten_text : '');
    q("INSERT INTO rezeptur (nummer,name,darreichungsform,status,notiz) VALUES (?,?,?,?,?)",
      [naechste_nummer('RZ'), mb_substr($name, 0, 190), $form, 'entwurf', $notiz]);
    $rid = (int) insert_id();
    $sort = 0; $match = 0; $ges = 0;
    foreach ($zutaten as $z) {
        $bez = trim((string)($z['bezeichnung'] ?? '')); if ($bez === '') continue;
        $mg  = (float) str_replace(',', '.', (string)($z['menge_mg'] ?? 0));
        $iid = rezeptur_ki_item_finden($bez);   // Best-Match Rohstoff (oder null)
        if ($iid) $match++;
        $ges++;
        q("INSERT INTO rezeptur_zutat (rezeptur_id,item_id,bezeichnung,menge_mg,sort) VALUES (?,?,?,?,?)",
          [$rid, $iid, mb_substr($bez, 0, 190), $mg > 0 ? $mg : null, $sort++]);
    }
    return ['rezeptur_id' => $rid, 'gematcht' => $match, 'gesamt' => $ges];
}
