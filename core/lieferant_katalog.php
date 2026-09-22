<?php
// Katalog eines Lieferanten: was er anbietet, bevor es bei uns ein Artikel ist.
//
// Der Lieferant lädt seine Preisliste oder sein Produktblatt hoch, die KI liest daraus Zeilen.
// Diese Zeilen sind ein VORSCHLAG – sie stehen in `lieferant_katalog`, nicht im Artikelstamm.
// Erst wenn jemand aus dem Team sie prüft und übernimmt, entsteht ein `item` (und auf Wunsch
// ein Preis in `lieferant_preis`). So kann ein Lieferant nie unsere Stammdaten verändern.
require_once __DIR__ . '/ki.php';
require_once __DIR__ . '/schema.php';

// Was eine Katalogzeile beschreiben kann. Schlüssel = Spalte, Wert = Klartext für die KI.
function katalog_felder(): array {
    return [
        'name'          => 'Bezeichnung des Artikels auf DEUTSCH (bei fremdsprachigen Listen ins Deutsche übersetzen)',
        'name_en'       => 'Englische Bezeichnung (übersetzen, falls die Liste nicht englisch ist)',
        'name_original' => 'Der Name EXAKT wie in der Liste, in der Originalsprache (z. B. Chinesisch) – unverändert, nicht übersetzen',
        'name_lat'      => 'Lateinische/botanische Bezeichnung',
        'art'           => 'rohstoff oder fertigprodukt – Rohstoff ist Schüttware zum Verarbeiten, Fertigprodukt ist bereits Kapsel/Tablette/Pulver in Endform',
        'form'          => 'pulver, granulat, fluessig, oel, extrakt, kapsel, tablette, softgel oder stick',
        'cas'           => 'CAS-Nummer',
        'spezifikation' => 'Kurzangabe zur Qualität, z. B. "95 % Curcumin" oder "Ph. Eur."',
        'herkunft'      => 'Herkunftsland',
        'preis'         => 'Preis als Zahl, ohne Währung',
        'waehrung'      => 'Währung, z. B. EUR oder USD',
        'einheit'       => 'Bezugsgröße des Preises: kg, g, L, Stück',
        'menge_ab'      => 'Ab welcher Menge dieser Preis gilt (Mindestmenge/MOQ) als Zahl',
        'notiz'         => 'Sonstiges in einem kurzen Satz',
    ];
}

// Der Auftrag an die KI: eine Liste, keine Prosa.
function katalog_anweisung(): string {
    $f = '';
    foreach (katalog_felder() as $k => $t) $f .= "  \"$k\": $t\n";
    return <<<TXT
Dies ist die Preisliste oder das Produktblatt eines Lieferanten für Nahrungsergänzungsmittel.
Die Liste kann in JEDER Sprache sein (z. B. Chinesisch, Englisch, Deutsch).
Lies ALLE angebotenen Artikel heraus – auch wenn es viele sind.

Gib NUR dieses JSON zurück:
{
  "zeilen": [ { Felder siehe unten } ],
  "hinweise": [ "kurze Anmerkungen, z. B. wenn eine Seite unleserlich war" ]
}

Felder je Zeile:
$f
Regeln:
- Sprache: "name" IMMER auf Deutsch, "name_en" auf Englisch (bei Bedarf übersetzen). "name_original" bleibt WORTGETREU wie in der Liste (Originalsprache, z. B. Chinesisch). Ist die Liste schon deutsch, ist "name" = "name_original".
- Eine Zeile je angebotenem Artikel. Steht derselbe Artikel mit mehreren Staffelpreisen da, nimm den Preis der kleinsten Menge und schreibe die übrigen Staffeln in "notiz".
- Übernimm nur, was dasteht (außer Übersetzung des Namens). Fehlende Felder weglassen, nicht raten.
- "preis" und "menge_ab" als Zahl ohne Einheit, Punkt als Dezimaltrennzeichen.
- "name" ist Pflicht. Zeilen ohne erkennbare Bezeichnung lässt du weg.
- Überschriften, Seitenzahlen, Kontaktdaten und Fußnoten sind keine Artikel.
TXT;
}

// Eine hochgeladene Liste auslesen und als Vorschlagszeilen speichern.
// Rückgabe ['ok'=>bool, 'anzahl'=>n, 'hinweise'=>[], 'fehler'=>'…'].
function katalog_einlesen(int $lieferant_id, string $pfad, ?int $dokument_id = null): array {
    if (!ki_bereit()) return ['ok'=>false, 'fehler'=>'Die KI ist nicht eingerichtet.'];
    $r = ki_datei_frage($pfad, katalog_anweisung(), [
        'json'       => true,
        'denken'     => true,
        'max_tokens' => 16000,     // Kataloge sind lang
        'timeout'    => 300,
        'zweck'      => 'katalog-lesen',
    ]);
    if (!$r['ok']) return $r;

    $n = 0;
    foreach ((array)($r['daten']['zeilen'] ?? []) as $z) {
        $name = trim((string)($z['name'] ?? ''));
        if ($name === '') continue;
        $art  = in_array(($z['art'] ?? ''), ['rohstoff', 'fertigprodukt'], true) ? $z['art'] : 'rohstoff';
        $form = array_key_exists((string)($z['form'] ?? ''), katalog_formen()) ? $z['form'] : null;
        q("INSERT INTO lieferant_katalog (lieferant_id,dokument_id,name,name_en,name_original,name_lat,art,form,cas,spezifikation,herkunft,preis,waehrung,einheit,menge_ab,notiz,status,angelegt)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'neu',?)",
          [$lieferant_id, $dokument_id ?: null, mb_substr($name, 0, 190),
           mb_substr(trim((string)($z['name_en'] ?? '')), 0, 190) ?: null,
           mb_substr(trim((string)($z['name_original'] ?? '')), 0, 190) ?: null,
           mb_substr(trim((string)($z['name_lat'] ?? '')), 0, 190) ?: null,
           $art, $form, mb_substr(trim((string)($z['cas'] ?? '')), 0, 30) ?: null,
           mb_substr(trim((string)($z['spezifikation'] ?? '')), 0, 190) ?: null,
           mb_substr(trim((string)($z['herkunft'] ?? '')), 0, 120) ?: null,
           ($z['preis'] ?? '') !== '' ? (float)str_replace(',', '.', (string)$z['preis']) : null,
           mb_substr(trim((string)($z['waehrung'] ?? 'EUR')), 0, 3) ?: 'EUR',
           mb_substr(trim((string)($z['einheit'] ?? '')), 0, 20) ?: null,
           ($z['menge_ab'] ?? '') !== '' ? (float)str_replace(',', '.', (string)$z['menge_ab']) : null,
           mb_substr(trim((string)($z['notiz'] ?? '')), 0, 500) ?: null,
           gmdate('Y-m-d H:i:s')]);
        $n++;
    }
    log_aktivitaet('lieferant', $lieferant_id, 'lieferant', $n . ' Katalogzeile(n) aus einer hochgeladenen Liste gelesen.', 'katalog');
    return ['ok'=>true, 'anzahl'=>$n, 'hinweise'=>(array)($r['daten']['hinweise'] ?? []), 'usage'=>$r['usage'] ?? []];
}

// Ein vom Lieferanten hochgeladenes CoA/Spezifikation (JEDE Sprache) auslesen und als EINE
// Katalog-Vorschlagszeile (Rohstoff) fürs Team anlegen. Das volle KI-Ergebnis (Wirkstoffe/Kennwerte)
// wird in ki_json gemerkt und beim Anlegen (katalog_uebernehmen) an den Rohstoff angereichert.
function katalog_aus_spec(int $lieferant_id, string $pfad, ?int $dokument_id = null): array {
    if (!ki_bereit()) return ['ok'=>false, 'fehler'=>'Die KI ist nicht eingerichtet.'];
    require_once __DIR__ . '/spec_ki.php';
    $r = spec_ki_lesen($pfad);
    if (!($r['ok'] ?? false)) return ['ok'=>false, 'fehler'=>($r['fehler'] ?? 'Die Unterlage konnte nicht gelesen werden.')];
    $stamm = (array)($r['stamm'] ?? []);
    $name  = trim((string)($stamm['name'] ?? ''));
    if ($name === '') return ['ok'=>false, 'fehler'=>'Im Dokument wurde keine Bezeichnung erkannt.'];
    $cas = trim((string)($stamm['cas'] ?? '')) ?: trim((string)($r['cas_vorschlag'] ?? ''));
    // Kurz-Spezifikation aus dem ersten Wirkstoff (z. B. "Curcumin 95 %").
    $spez = '';
    foreach ((array)($r['wirkstoffe'] ?? []) as $w) {
        $g = $w['gehalt_prozent'] ?? null;
        $spez = trim((string)$w['name'] . ($g !== null ? ' ' . rtrim(rtrim(number_format((float)$g, 2, '.', ''), '0'), '.') . ' %' : ''));
        if ($spez !== '') break;
    }
    q("INSERT INTO lieferant_katalog (lieferant_id,dokument_id,name,name_en,name_lat,art,form,cas,spezifikation,herkunft,notiz,ki_json,status,angelegt)
       VALUES (?,?,?,?,?, 'rohstoff', NULL, ?,?,?,?,?, 'neu', ?)",
      [$lieferant_id, $dokument_id ?: null, mb_substr($name, 0, 190),
       mb_substr(trim((string)($stamm['name_en'] ?? '')), 0, 190) ?: null,
       mb_substr(trim((string)($stamm['name_lat'] ?? '')), 0, 190) ?: null,
       $cas !== '' ? mb_substr($cas, 0, 30) : null,
       mb_substr($spez, 0, 190) ?: null,
       mb_substr(trim((string)($stamm['herkunftsland'] ?? '')), 0, 120) ?: null,
       mb_substr('Aus ' . mb_strtoupper((string)$r['typ']) . ' des Lieferanten hochgeladen.', 0, 500),
       json_encode($r, JSON_UNESCAPED_UNICODE),
       gmdate('Y-m-d H:i:s')]);
    log_aktivitaet('lieferant', $lieferant_id, 'lieferant', 'CoA/Spezifikation für „' . $name . '" hochgeladen – Rohstoff-Vorschlag fürs Team.', 'katalog');
    return ['ok'=>true, 'name'=>$name, 'typ'=>$r['typ']];
}

// Formen, die eine Katalogzeile haben kann (deutsch beschriftet).
function katalog_formen(): array {
    return ['pulver'=>'Pulver','granulat'=>'Granulat','fluessig'=>'Flüssig','oel'=>'Öl','extrakt'=>'Extrakt',
            'kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick'];
}
function katalog_status(): array {
    return ['neu'=>'neu', 'uebernommen'=>'übernommen', 'abgelehnt'=>'abgelehnt'];
}

// Die Zeilen eines Lieferanten. $status = '' für alle.
function katalog_zeilen(int $lieferant_id, string $status = ''): array {
    $sql = "SELECT k.*, i.artikelnummer, i.name AS item_name FROM lieferant_katalog k
            LEFT JOIN item i ON i.id=k.item_id WHERE k.lieferant_id=?";
    $p = [$lieferant_id];
    if ($status !== '') { $sql .= " AND k.status=?"; $p[] = $status; }
    return all($sql . " ORDER BY (k.status<>'neu'), k.name", $p);
}
// Wie viele Zeilen warten beim Team auf eine Entscheidung? (für Badges in der Übersicht)
function katalog_offen(int $lieferant_id): int {
    return (int) scalar("SELECT COUNT(*) FROM lieferant_katalog WHERE lieferant_id=? AND status='neu'", [$lieferant_id]);
}
function katalog_offen_gesamt(): array {
    $out = [];
    foreach (all("SELECT lieferant_id, COUNT(*) n FROM lieferant_katalog WHERE status='neu' GROUP BY lieferant_id") as $r)
        $out[(int)$r['lieferant_id']] = (int)$r['n'];
    return $out;
}

// Gibt es den Artikel vielleicht schon? Sucht nach gleichem Namen oder gleicher CAS-Nummer,
// damit wir nicht denselben Rohstoff dreimal anlegen.
function katalog_treffer(array $zeile): ?array {
    $name = trim((string)$zeile['name']);
    if (!empty($zeile['cas'])) {
        $t = one("SELECT id, artikelnummer, name FROM item WHERE cas=? AND gesperrt=0 LIMIT 1", [$zeile['cas']]);
        if ($t) return $t;
    }
    if ($name === '') return null;
    return one("SELECT id, artikelnummer, name FROM item WHERE name=? AND gesperrt=0 LIMIT 1", [$name])
        ?: one("SELECT id, artikelnummer, name FROM item WHERE name LIKE ? AND gesperrt=0 LIMIT 1", ['%' . $name . '%']);
}

// Mehrere mögliche Treffer im Bestand finden – gleiche CAS, gleicher Name, ähnliche Namensteile.
// Damit wir nicht denselben Rohstoff doppelt anlegen. Rückgabe: Liste mit id, artikelnummer, name,
// cas, kategorie und 'grund' (warum es als Treffer gilt).
function katalog_aehnliche(array $z, int $limit = 6): array {
    $name = trim((string)($z['name'] ?? ''));
    $cas  = trim((string)($z['cas'] ?? ''));
    $found = [];
    $add = function(array $rows, string $grund) use (&$found) {
        foreach ($rows as $r) { $id = (int)$r['id']; if (!isset($found[$id])) { $r['grund'] = $grund; $found[$id] = $r; } }
    };
    $lim = max(1, (int)$limit);
    if ($cas !== '')  $add(all("SELECT id,artikelnummer,name,cas,kategorie FROM item WHERE cas=? AND gesperrt=0 LIMIT $lim", [$cas]), 'gleiche CAS');
    if ($name !== '') {
        $add(all("SELECT id,artikelnummer,name,cas,kategorie FROM item WHERE name=? AND gesperrt=0 LIMIT $lim", [$name]), 'gleicher Name');
        // Aussagekräftige Wörter aus dem Namen (>=4 Zeichen), längste zuerst.
        $worte = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name)) ?: [], fn($w) => mb_strlen($w) >= 4));
        usort($worte, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
        foreach (array_slice(array_unique($worte), 0, 2) as $w) {
            $add(all("SELECT id,artikelnummer,name,cas,kategorie FROM item WHERE gesperrt=0 AND name LIKE ? LIMIT $lim", ['%' . $w . '%']), 'ähnlicher Name');
        }
    }
    return array_slice(array_values($found), 0, $lim);
}

// Eine geprüfte Zeile übernehmen: Artikel anlegen (oder mit einem vorhandenen verknüpfen) und
// den Preis des Lieferanten dazuschreiben. Rückgabe ['ok'=>bool, 'item_id'=>n, 'msg'=>'…'].
function katalog_uebernehmen(int $zeile_id, ?int $item_id = null, bool $preis_uebernehmen = true): array {
    $z = one("SELECT * FROM lieferant_katalog WHERE id=?", [$zeile_id]);
    if (!$z) return ['ok'=>false, 'msg'=>'Zeile nicht gefunden.'];
    if ($z['status'] === 'uebernommen' && $z['item_id']) return ['ok'=>true, 'item_id'=>(int)$z['item_id'], 'msg'=>'War schon übernommen.'];

    if ($item_id) {
        $it = one("SELECT id FROM item WHERE id=?", [$item_id]);
        if (!$it) return ['ok'=>false, 'msg'=>'Artikel nicht gefunden.'];
    } else {
        // Neu anlegen. Fertigprodukte gehören in die Kategorie 'fertig', alles andere ist Rohstoff.
        $kat = $z['art'] === 'fertigprodukt' ? 'fertig' : 'rohstoff';
        $einheit = trim((string)$z['einheit']) ?: ($kat === 'fertig' ? 'Stück' : 'kg');
        q("INSERT INTO item (artikelnummer,name,name_en,name_lat,kategorie,form,cas,herkunftsland,einheit,preis_bezug,ek_preis,haupt_lieferant_id,notiz)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
          [naechste_nummer(item_prefix($kat)), $z['name'], $z['name_en'], $z['name_lat'], $kat,
           $z['form'] ?: ($kat === 'fertig' ? 'kapsel' : 'pulver'), $z['cas'], $z['herkunft'],
           $einheit, $einheit, $z['preis'] !== null ? (float)$z['preis'] : 0,
           (int)$z['lieferant_id'],
           trim('Aus dem Lieferantenkatalog. ' . (!empty($z['name_original']) && $z['name_original'] !== $z['name'] ? 'Original: ' . (string)$z['name_original'] . '. ' : '') . (string)$z['spezifikation'] . ' ' . (string)$z['notiz'])]);
        $item_id = (int) insert_id();
        log_aktivitaet('item', $item_id, 'team', 'Aus dem Katalog von ' . (string) scalar("SELECT firma FROM lieferanten WHERE id=?", [(int)$z['lieferant_id']]) . ' angelegt.', 'katalog');
        // Kam die Zeile aus einem hochgeladenen CoA/Spec? Dann Rohstoff anreichern: Wirkstoffe (mit Gehalt),
        // Kennwerte, Dichte – und das Dokument an den Rohstoff hängen.
        $ki = !empty($z['ki_json']) ? json_decode((string)$z['ki_json'], true) : null;
        if (is_array($ki)) {
            $si = 0;
            foreach ((array)($ki['wirkstoffe'] ?? []) as $w) {
                $nm = trim((string)($w['name'] ?? '')); if ($nm === '') continue;
                $nid = naehrstoff_id_by_name($nm);
                $g = $w['gehalt_prozent'] ?? null;
                if ($nid) q("INSERT INTO item_wirkstoff (item_id,naehrstoff_id,gehalt_prozent,gehalt_wert,gehalt_einheit,sort) VALUES (?,?,?,?, 'prozent', ?)",
                            [$item_id, $nid, ($g === null || $g === '') ? null : $g, ($g === null || $g === '') ? null : $g, $si++]);
            }
            $ki2 = 0;
            foreach ((array)($ki['kennwerte'] ?? []) as $kw) {
                $p = trim((string)($kw['parameter'] ?? '')); if ($p === '') continue;
                q("INSERT INTO item_kennwert (item_id,parameter,wert,sort) VALUES (?,?,?,?)", [$item_id, $p, trim((string)($kw['wert'] ?? '')), $ki2++]);
            }
            if (!empty($ki['stamm']['dichte'])) q("UPDATE item SET dichte=? WHERE id=?", [(float)$ki['stamm']['dichte'], $item_id]);
            if (!empty($z['dokument_id'])) {
                $dok = one("SELECT datei, datei_orig FROM dokument WHERE id=?", [(int)$z['dokument_id']]);
                if ($dok) q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,lieferant_id) VALUES ('item',?,?,?,?,?,?)",
                            [$item_id, (($ki['typ'] ?? '') === 'coa' ? 'coa' : 'spezifikation'), 'Vom Lieferanten hochgeladen', $dok['datei'], $dok['datei_orig'], (int)$z['lieferant_id']]);
            }
        }
    }

    // Preis als EK-Staffel des Lieferanten – dort rechnet die Kalkulation damit.
    if ($preis_uebernehmen && $z['preis'] !== null && (float)$z['preis'] > 0) {
        $ab = $z['menge_ab'] !== null ? (float)$z['menge_ab'] : 0;
        $vorhanden = scalar("SELECT id FROM lieferant_preis WHERE item_id=? AND lieferant_id=? AND menge_ab=?", [$item_id, (int)$z['lieferant_id'], $ab]);
        if ($vorhanden) q("UPDATE lieferant_preis SET preis=?, waehrung=?, stand=CURDATE() WHERE id=?", [(float)$z['preis'], $z['waehrung'] ?: 'EUR', (int)$vorhanden]);
        else q("INSERT INTO lieferant_preis (item_id,lieferant_id,menge_ab,preis,waehrung,stand) VALUES (?,?,?,?,?,CURDATE())",
               [$item_id, (int)$z['lieferant_id'], $ab, (float)$z['preis'], $z['waehrung'] ?: 'EUR']);
    }
    q("UPDATE lieferant_katalog SET status='uebernommen', item_id=?, entschieden=? WHERE id=?", [$item_id, gmdate('Y-m-d H:i:s'), $zeile_id]);
    return ['ok'=>true, 'item_id'=>$item_id, 'msg'=>''];
}

// Eine Zeile ablehnen (bleibt stehen, damit sie nicht beim nächsten Upload wieder auftaucht).
function katalog_ablehnen(int $zeile_id): void {
    q("UPDATE lieferant_katalog SET status='abgelehnt', entschieden=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $zeile_id]);
}
// Eine Zeile ganz löschen – nur solange sie noch nicht übernommen ist.
function katalog_loeschen(int $zeile_id, ?int $lieferant_id = null): void {
    if ($lieferant_id) q("DELETE FROM lieferant_katalog WHERE id=? AND lieferant_id=? AND status<>'uebernommen'", [$zeile_id, $lieferant_id]);
    else               q("DELETE FROM lieferant_katalog WHERE id=? AND status<>'uebernommen'", [$zeile_id]);
}
// Eine Zeile ändern (der Lieferant korrigiert seine eigene, das Team jede).
function katalog_speichern(int $zeile_id, array $daten, ?int $lieferant_id = null): void {
    $z = $lieferant_id
        ? one("SELECT id FROM lieferant_katalog WHERE id=? AND lieferant_id=? AND status='neu'", [$zeile_id, $lieferant_id])
        : one("SELECT id FROM lieferant_katalog WHERE id=?", [$zeile_id]);
    if (!$z) return;
    q("UPDATE lieferant_katalog SET name=?, art=?, form=?, spezifikation=?, herkunft=?, preis=?, waehrung=?, einheit=?, menge_ab=?, notiz=? WHERE id=?",
      [mb_substr(trim((string)($daten['name'] ?? '')), 0, 190),
       in_array(($daten['art'] ?? ''), ['rohstoff', 'fertigprodukt'], true) ? $daten['art'] : 'rohstoff',
       array_key_exists((string)($daten['form'] ?? ''), katalog_formen()) ? $daten['form'] : null,
       mb_substr(trim((string)($daten['spezifikation'] ?? '')), 0, 190) ?: null,
       mb_substr(trim((string)($daten['herkunft'] ?? '')), 0, 120) ?: null,
       trim((string)($daten['preis'] ?? '')) !== '' ? zahl_lesen((string)$daten['preis']) : null,
       mb_substr(trim((string)($daten['waehrung'] ?? 'EUR')), 0, 3) ?: 'EUR',
       mb_substr(trim((string)($daten['einheit'] ?? '')), 0, 20) ?: null,
       trim((string)($daten['menge_ab'] ?? '')) !== '' ? zahl_lesen((string)$daten['menge_ab'], true) : null,
       mb_substr(trim((string)($daten['notiz'] ?? '')), 0, 500) ?: null,
       $zeile_id]);
}
