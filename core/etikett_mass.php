<?php
// Etikettmaß kommt nur von der Verpackung (Regel Nico 13.11.2026, Fable-6 Teil 1 / sofortfix-etikettgroesse).
// Eine Kundendatei ändert das Maß am Etikett-Artikel nie; sie wird nur gegen dieses Soll geprüft (etp_soll()).
// - etikett_mass_von_verpackung(): Soll-Maß aus dem Behälter (generisches Etikett des Behälters, sonst etikett_final des Behälters)
// - etikett_mass_auf_verpackung(): Maß eines Etikett-Artikels auf die Verpackung zurücksetzen (Datenprüfung, protokolliert)
// - etikett_mass_zurueck_1311(): einmaliges Zurücksetzen auf live aus dem Änderungsprotokoll (Freigabe in der Anweisung, Punkt 3)
// Siehe etikett_mass.md.

const ETM_FELDER = ['breite_mm', 'hoehe_mm', 'etikett_format', 'etikett_final', 'etikett_druck'];

// Soll-Maß aus einem Behälter: ['breite_mm','hoehe_mm','etikett_format','etikett_final','etikett_druck','_vorlage'] oder [].
function etikett_mass_von_verpackung(int $behaelter_id): array {
    if ($behaelter_id <= 0) return [];
    $tid = function_exists('etikett_id_fuer_behaelter') ? (int) (etikett_id_fuer_behaelter($behaelter_id) ?? 0) : 0;
    if ($tid > 0) {
        $t = one("SELECT breite_mm,hoehe_mm,etikett_format,etikett_final,etikett_druck FROM item WHERE id=?", [$tid]) ?: [];
        if (!empty($t['breite_mm']) && !empty($t['hoehe_mm'])) { $t['_vorlage'] = $tid; return $t; }
    }
    $b = one("SELECT etikett_final, etikett_druck FROM item WHERE id=?", [$behaelter_id]);
    $m = $b ? etikett_masse((string)$b['etikett_final']) : null;
    if (!$m || $m[0] <= 0 || $m[1] <= 0) return $tid > 0 ? ['_vorlage' => $tid] : [];
    $z = fn($x) => rtrim(rtrim(number_format((float)$x, 1, '.', ''), '0'), '.');
    return ['breite_mm' => $m[0], 'hoehe_mm' => $m[1], 'etikett_format' => $z($m[0]) . ' x ' . $z($m[1]) . ' mm', 'etikett_final' => $z($m[0]) . ' x ' . $z($m[1]),
            'etikett_druck' => trim((string)($b['etikett_druck'] ?? '')) ?: null, '_vorlage' => $tid ?: null];
}

// Behälter, nach dem sich ein Kundenetikett richtet: der Behälter des jüngsten offenen Auftrags an diesem Etikett,
// sonst der tatsächliche Produkt-Behälter (produkt_behaelter_id).
function etikett_behaelter_fuer_item(int $item_id): int {
    $vp = (int) scalar("SELECT COALESCE(verpackung_id,0) FROM auftrag WHERE etikett_item_id=? AND status<>'storniert' AND COALESCE(verpackung_id,0)>0 ORDER BY id DESC LIMIT 1", [$item_id]);
    if ($vp > 0 && function_exists('verpackung_neutrale_basis')) { $n = verpackung_neutrale_basis($vp); if ($n > 0) $vp = $n; }
    if ($vp > 0) return $vp;
    $pid = (int) scalar("SELECT COALESCE(produkt_id,0) FROM item WHERE id=?", [$item_id]);
    return $pid > 0 && function_exists('produkt_behaelter_id') ? (int) (produkt_behaelter_id($pid) ?? 0) : 0;
}

// Maß eines Etikett-Artikels auf die Verpackung zurücksetzen. Protokolliert (route etikett_mass_verpackung). true = geändert.
function etikett_mass_auf_verpackung(int $item_id, string $grund = ''): bool {
    $vor = one("SELECT * FROM item WHERE id=? AND verpackung_rolle='etikett'", [$item_id]);
    if (!$vor) return false;
    $m = etikett_mass_von_verpackung(etikett_behaelter_fuer_item($item_id));
    if (empty($m['breite_mm']) || empty($m['hoehe_mm'])) return false;
    q("UPDATE item SET breite_mm=?, hoehe_mm=?, etikett_format=?, etikett_final=?, etikett_druck=? WHERE id=?",
      [$m['breite_mm'], $m['hoehe_mm'], $m['etikett_format'] ?? null, $m['etikett_final'] ?? null, $m['etikett_druck'] ?? null, $item_id]);
    $nach = one("SELECT * FROM item WHERE id=?", [$item_id]) ?: [];
    $n = aenderung_protokollieren('item', $item_id, $vor, $nach, 'etikett_mass_verpackung');
    if ($n > 0 && function_exists('log_aktivitaet'))
        log_aktivitaet('item', $item_id, 'team', 'Etikett-Maß auf die Verpackung zurückgesetzt (' . ($m['etikett_final'] ?? '') . ')' . ($grund !== '' ? ': ' . $grund : '') . '.', 'notiz');
    return $n > 0;
}

// Zurücksetzen aus dem Änderungsprotokoll (route etikett_pdf_*). $ausfuehren=false = nur Bericht.
// Je Etikett-Artikel und Feld: ältester Eintrag = Wert vorher, jüngster = Wert der Datei. Zurückgeschrieben wird nur, wenn
// das Feld heute noch den Wert der Datei hat und es ein „vorher“ gibt (Breite/Höhe nie leer). Sonst: „bitte Nico prüfen“.
function etikett_mass_zurueck_1311(bool $ausfuehren): array {
    $logs = all("SELECT id, objekt_id, feld, alt, neu, route, erstellt, benutzer FROM aenderung_log
                 WHERE objekt_typ='item' AND route LIKE 'etikett=_pdf=_%' ESCAPE '=' AND feld IN ('breite_mm','hoehe_mm','etikett_format','etikett_final','etikett_druck')
                 ORDER BY objekt_id, erstellt, id");
    $je = [];
    foreach ($logs as $l) $je[(int)$l['objekt_id']][] = $l;
    $gleich = function ($a, $b): bool {
        if ($a === null || $a === '') return $b === null || $b === '';
        if ($b === null || $b === '') return false;
        if (is_numeric($a) && is_numeric($b)) return abs((float)$a - (float)$b) < 0.05;
        return trim((string)$a) === trim((string)$b);
    };
    $bericht = [];
    foreach ($je as $iid => $rows) {
        $it = one("SELECT * FROM item WHERE id=?", [$iid]);
        if (!$it) continue;
        $alt = []; $neu = [];
        foreach ($rows as $r) { if (!array_key_exists($r['feld'], $alt)) $alt[$r['feld']] = $r['alt']; $neu[$r['feld']] = $r['neu']; }
        $auftraege = array_column(all("SELECT id, nummer FROM auftrag WHERE etikett_item_id=? ORDER BY id", [$iid]), 'nummer', 'id');
        $massJetzt = (string)($it['etikett_final'] ?: ((float)$it['breite_mm'] . ' x ' . (float)$it['hoehe_mm']));
        $zeile = ['item_id' => $iid, 'artikelnummer' => (string)$it['artikelnummer'], 'name' => (string)$it['name'], 'auftraege' => $auftraege,
                  'mass_falsch' => $massJetzt, 'mass_zurueck' => '', 'status' => '', 'grund' => '', 'quelle' => (string)end($rows)['route'],
                  'geaendert' => (string)end($rows)['erstellt'], 'von' => (string)(end($rows)['benutzer'] ?? '')];
        $probleme = [];
        foreach (['breite_mm', 'hoehe_mm'] as $f) if (array_key_exists($f, $alt) && ($alt[$f] === null || $alt[$f] === '' || (float)$alt[$f] <= 0)) $probleme[] = $f . ' war vorher leer';
        foreach ($alt as $f => $a) if (!$gleich($it[$f] ?? null, $neu[$f]) && !$gleich($it[$f] ?? null, $a)) $probleme[] = $f . ' seitdem anders geändert (jetzt ' . ($it[$f] ?? 'leer') . ')';
        $setz = [];
        foreach ($alt as $f => $a) if (!$gleich($it[$f] ?? null, $a)) $setz[$f] = ($a === '' ? null : $a);
        if (!$setz) { $zeile['status'] = 'schon richtig'; $zeile['mass_zurueck'] = $massJetzt; $bericht[] = $zeile; continue; }
        $ziel = array_merge($it, $setz);
        $zeile['mass_zurueck'] = (string)($ziel['etikett_final'] ?: ((float)$ziel['breite_mm'] . ' x ' . (float)$ziel['hoehe_mm']));
        if ($probleme) { $zeile['status'] = 'bitte Nico prüfen'; $zeile['grund'] = implode('; ', $probleme); $zeile['mass_zurueck'] = ''; $bericht[] = $zeile; continue; }
        if ($ausfuehren) {
            $sets = implode(',', array_map(fn($f) => "$f=?", array_keys($setz)));
            q("UPDATE item SET $sets WHERE id=?", array_merge(array_values($setz), [$iid]));
            $nach = one("SELECT * FROM item WHERE id=?", [$iid]) ?: [];
            aenderung_protokollieren('item', $iid, $it, $nach, 'etikett_mass_zurueck_1311');
            if (function_exists('log_aktivitaet')) log_aktivitaet('item', $iid, 'system', 'Maß zurück auf Verpackung, Regel 13.11. (' . $massJetzt . ' -> ' . $zeile['mass_zurueck'] . ').', 'notiz');
            $zeile['status'] = 'zurückgesetzt';
        } else {
            $zeile['status'] = 'würde zurückgesetzt';
        }
        $bericht[] = $zeile;
    }
    return $bericht;
}

// Einmal auf live (app_meta dpf_etmass_erledigt). Ergebnis: app_meta dpf_etmass_ergebnis (JSON), Anzeige in der Datenprüfung.
function dpf_etmass_einmal(): void {
    try {
        if (meta_get('dpf_etmass_erledigt', '') === '1' || !table_exists('aenderung_log')) return;
        meta_set('dpf_etmass_erledigt', '1');   // zuerst setzen: lieber nie als doppelt
        $b = etikett_mass_zurueck_1311(true);
        meta_set('dpf_etmass_ergebnis', json_encode(['zeit' => gmdate('Y-m-d H:i:s'), 'zeilen' => $b], JSON_UNESCAPED_UNICODE));
        if (function_exists('bedarf_bump')) bedarf_bump();
    } catch (\Throwable $e) {
        try { meta_set('dpf_etmass_ergebnis', json_encode(['fehler' => $e->getMessage()], JSON_UNESCAPED_UNICODE)); } catch (\Throwable $e2) {}
    }
}
