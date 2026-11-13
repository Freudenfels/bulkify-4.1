<?php
// Vergleich einer Lieferanten-Spec/-CoA mit der bulkify-Spec (Paket 13.11.2026, Teil 5). EINE Stelle für:
// - das Pop-up „Vergleich mit der bulkify-Spec“ nach dem Upload am Rohstoff (spec_vergleich_dialog)
// - „Werte übernehmen (überschreiben)“ mit Verlauf alt -> neu (spec_vergleich_uebernehmen)
// - CoA einer Charge außerhalb der Spec -> Quarantäne + Aufgabe an QM (spec_coa_charge_pruefen)
// Die bulkify-Spec = Wirkstoffgehalte (item_wirkstoff, „mind. X %“), Kennwerte (item_kennwert) und Grenzwerte (item_grenzwert).
// Bewertet wird mit dem vorhandenen Grenzwert-Leser bch_wert_pruefen() (core/bestellung_chargen.php).
require_once __DIR__ . '/bestellung_chargen.php';
require_once __DIR__ . '/spec_ki.php';

// Art des Grenzwerts aus dem Text: 'mind.', 'max.', 'Bereich', 'negativ', '' (Text/unklar).
function spec_grenz_art(string $g): string {
    $l = mb_strtolower(trim($g));
    if ($l === '') return '';
    if (preg_match('/(negativ|negative|absent|nicht nachweisbar|not detected)/u', $l)) return 'negativ';
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:-|–|~|bis|to)\s*(\d+(?:[.,]\d+)?)/u', $l) && !preg_match('/x\s*10/u', $l)) return 'Bereich';
    if (preg_match('/(≤|<=|=<|<|max|nmt|not more than|höchstens|hoechstens|kleiner)/u', $l)) return 'max.';
    if (preg_match('/(≥|>=|=>|>|min|nlt|not less than|mindestens|größer|groesser)/u', $l)) return 'mind.';
    return '';
}
function spec_vgl_norm(string $p): string {
    $p = mb_strtolower($p);
    $p = strtr($p, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $p = preg_replace('/\(.*?\)|\[.*?\]/u', ' ', $p);
    return trim(preg_replace('/[^a-z0-9]+/u', ' ', $p));
}
// Passenden Wert im Dokument zu einem Parameter finden (genau oder enthalten).
function spec_vgl_suche(array $liste, string $param): ?array {
    $n = spec_vgl_norm($param); if ($n === '') return null;
    foreach ($liste as $z) if (spec_vgl_norm((string)($z['parameter'] ?? $z['name'] ?? '')) === $n) return $z;
    foreach ($liste as $z) { $m = spec_vgl_norm((string)($z['parameter'] ?? $z['name'] ?? '')); if ($m !== '' && (str_contains($m, $n) || str_contains($n, $m))) return $z; }
    return null;
}
// Liegt eine Spec-Angabe des Lieferanten innerhalb unserer Spec? Jede Zahl seiner Angabe muss unsere Grenze erfüllen
// (mind. 22 % bei unserem mind. 20 % = grün; max. 5 ppm bei unserem max. 10 ppm = grün; Bereich 4–6 in 3–7 = grün).
function spec_vgl_bewerten(string $unsere, string $dokWert, bool $istCoa): ?bool {
    if (trim($unsere) === '' || trim($dokWert) === '') return null;
    if ($istCoa) return bch_wert_pruefen($unsere, $dokWert);
    $direkt = bch_wert_pruefen($unsere, $dokWert);
    if (!preg_match_all('/\d+(?:[.,]\d+)?/u', $dokWert, $m) || count($m[0]) > 3 || preg_match('/x\s*10/u', mb_strtolower($dokWert))) return $direkt;
    $ok = true;
    foreach ($m[0] as $z) { $r = bch_wert_pruefen($unsere, $z); if ($r === null) return $direkt; $ok = $ok && $r; }
    return $ok;
}

// Vergleichstabelle. Zeilen: ['art'=>Wirkstoff|Kennwert|Grenzwert, 'parameter', 'unsere', 'grenz_art', 'dok', 'ergebnis'=>gruen|rot|grau|pruefen|neu].
function spec_vergleich(int $item_id, array $erg): array {
    $istCoa = in_array((string)($erg['typ'] ?? ''), ['coa'], true);
    $werte = (array)($erg['werte'] ?? []);
    $dokWert = fn($z) => $z === null ? '' : trim((string)($istCoa ? ($z['ergebnis'] ?? '') : (($z['spezifikation'] ?? '') !== '' ? $z['spezifikation'] : ($z['ergebnis'] ?? ''))));
    $rows = []; $gesehen = [];
    // Wirkstoffgehalte: unsere Spec = „mind. X %“
    $dokWs = (array)($erg['wirkstoffe'] ?? []); if (!$dokWs) $dokWs = spec_ki_wirkstoffe_aus_werte($werte);
    foreach (all("SELECT w.gehalt_prozent, w.gehalt_wert, w.gehalt_einheit, n.name FROM item_wirkstoff w JOIN naehrstoff n ON n.id=w.naehrstoff_id WHERE w.item_id=? ORDER BY w.sort, w.id", [$item_id]) as $w) {
        $g = $w['gehalt_wert'] !== null && $w['gehalt_wert'] !== '' ? (float)$w['gehalt_wert'] : ($w['gehalt_prozent'] !== null ? (float)$w['gehalt_prozent'] : null);
        $unsere = $g === null ? '' : '≥ ' . rtrim(rtrim(number_format($g, 3, ',', ''), '0'), ',') . ' %';
        $d = spec_vgl_suche($dokWs, (string)$w['name']); $dw = $d && ($d['gehalt_prozent'] ?? '') !== '' ? (string)$d['gehalt_prozent'] . ' %' : '';
        if ($dw === '') { $z = spec_vgl_suche($werte, (string)$w['name']); $dw = $dokWert($z); if ($z) $gesehen[spec_vgl_norm((string)$z['parameter'])] = 1; }
        $r = $dw === '' ? null : spec_vgl_bewerten($unsere, $dw, true);
        $rows[] = ['art' => 'Wirkstoff', 'parameter' => (string)$w['name'], 'unsere' => $unsere, 'grenz_art' => $unsere !== '' ? 'mind.' : '', 'dok' => $dw,
                   'ergebnis' => $dw === '' ? 'grau' : ($r === null ? 'pruefen' : ($r ? 'gruen' : 'rot'))];
        if ($d) $gesehen['ws:' . spec_vgl_norm((string)$d['name'])] = 1;
    }
    // Kennwerte und Grenzwerte
    foreach ([['Kennwert', all("SELECT parameter, wert AS g FROM item_kennwert WHERE item_id=? ORDER BY sort, id", [$item_id])],
              ['Grenzwert', all("SELECT parameter, grenzwert AS g FROM item_grenzwert WHERE item_id=? ORDER BY sort, id", [$item_id])]] as [$art, $liste]) {
        foreach ($liste as $k) {
            $z = spec_vgl_suche($werte, (string)$k['parameter']);
            if (!$z && $art === 'Kennwert') { $kz = spec_vgl_suche((array)($erg['kennwerte'] ?? []), (string)$k['parameter']); if ($kz) $z = ['parameter' => $kz['parameter'], 'spezifikation' => $kz['wert'] ?? '', 'ergebnis' => $kz['wert'] ?? '']; }
            $dw = $dokWert($z);
            if ($z) $gesehen[spec_vgl_norm((string)$z['parameter'])] = 1;
            $r = $dw === '' ? null : spec_vgl_bewerten((string)$k['g'], $dw, $istCoa);
            $rows[] = ['art' => $art, 'parameter' => (string)$k['parameter'], 'unsere' => (string)$k['g'], 'grenz_art' => spec_grenz_art((string)$k['g']), 'dok' => $dw,
                       'ergebnis' => $dw === '' ? 'grau' : ($r === null ? 'pruefen' : ($r ? 'gruen' : 'rot'))];
        }
    }
    // Werte im Dokument, die unsere Spec (noch) nicht hat
    foreach ($werte as $z) {
        $p = trim((string)($z['parameter'] ?? '')); if ($p === '' || isset($gesehen[spec_vgl_norm($p)])) continue;
        $dw = $dokWert($z); if ($dw === '') continue;
        $rows[] = ['art' => 'neu', 'parameter' => $p, 'unsere' => '', 'grenz_art' => '', 'dok' => $dw, 'ergebnis' => 'neu'];
    }
    return $rows;
}

// „Werte übernehmen (überschreiben)“: Kennwerte und Grenzwerte aus dem Dokument setzen (je Parameter ersetzen, andere bleiben),
// bei einer Spec auch die Wirkstoffgehalte. Bei einer CoA nur die Grenzwert-Spalte des Dokuments (nie Messwerte als Spec).
// Verlauf: aenderung_log (alt -> neu, wer, wann, Route) + Aktivität mit dem Dokument. Löst KEINE PIB-Neuprüfung aus.
function spec_vergleich_uebernehmen(int $item_id, int $dok_id, string $wer): array {
    $erg = spec_ki_vorschlag($dok_id);
    $dok = one("SELECT id, datei_orig, typ FROM dokument WHERE id=? AND objekt_typ='item' AND objekt_id=?", [$dok_id, $item_id]);
    if (!$erg || !$dok) return ['ok' => false, 'msg' => 'Dokument oder ausgelesene Werte nicht gefunden.'];
    $istCoa = (string)($erg['typ'] ?? '') === 'coa';
    $vorher = []; $nachher = [];
    $setze = function (string $tab, string $spalte, string $p, string $w) use ($item_id, &$vorher, &$nachher) {
        if ($p === '' || $w === '') return;
        $alt = one("SELECT id, parameter, $spalte AS v FROM $tab WHERE item_id=? AND parameter=? LIMIT 1", [$item_id, $p]);
        if (!$alt) {   // gleicher Kennwert unter leicht anderem Namen („Blei“ = „Blei (Pb)“) -> diesen ersetzen, keine Doppelzeile
            $treffer = spec_vgl_suche(all("SELECT id, parameter, $spalte AS v FROM $tab WHERE item_id=?", [$item_id]), $p);
            if ($treffer) { $alt = $treffer; $p = (string)$treffer['parameter']; }
        }
        $key = ($tab === 'item_grenzwert' ? 'Grenzwert: ' : 'Kennwert: ') . mb_substr($p, 0, 60);
        if ($alt) { if ((string)$alt['v'] === $w) return; q("UPDATE $tab SET $spalte=? WHERE id=?", [mb_substr($w, 0, 120), (int)$alt['id']]); $vorher[$key] = (string)$alt['v']; }
        else { $so = (int) scalar("SELECT COALESCE(MAX(sort),-1)+1 FROM $tab WHERE item_id=?", [$item_id]);
               q("INSERT INTO $tab (item_id, parameter, $spalte, sort) VALUES (?,?,?,?)", [$item_id, mb_substr($p, 0, 120), mb_substr($w, 0, 120), $so]); $vorher[$key] = ''; }
        $nachher[$key] = $w;
    };
    foreach ((array)($erg['werte'] ?? []) as $z) $setze('item_grenzwert', 'grenzwert', trim((string)($z['parameter'] ?? '')), trim((string)($z['spezifikation'] ?? '')));
    foreach ((array)($erg['kennwerte'] ?? []) as $z) { $p = trim((string)($z['parameter'] ?? '')); if ($p !== '' && spec_kennwert_relevant($p)) $setze('item_kennwert', 'wert', $p, trim((string)($z['wert'] ?? ''))); }
    if (!$istCoa) {
        $wsVor = []; foreach (all("SELECT n.name, w.gehalt_prozent FROM item_wirkstoff w JOIN naehrstoff n ON n.id=w.naehrstoff_id WHERE w.item_id=?", [$item_id]) as $w) $wsVor['Wirkstoff: ' . $w['name']] = (string)$w['gehalt_prozent'];
        spec_ki_wirkstoffe($item_id, $erg, false, true);
        foreach (all("SELECT n.name, w.gehalt_prozent FROM item_wirkstoff w JOIN naehrstoff n ON n.id=w.naehrstoff_id WHERE w.item_id=?", [$item_id]) as $w) {
            $k = 'Wirkstoff: ' . $w['name']; if (($wsVor[$k] ?? null) !== (string)$w['gehalt_prozent']) { $vorher[$k] = $wsVor[$k] ?? ''; $nachher[$k] = (string)$w['gehalt_prozent']; }
        }
    }
    if (function_exists('aenderung_protokollieren') && $nachher) aenderung_protokollieren('item', $item_id, $vorher, $nachher, 'spec_vergleich');
    if (function_exists('log_aktivitaet')) log_aktivitaet('item', $item_id, 'team', 'Spec-Werte aus Dokument „' . ($dok['datei_orig'] ?: '#' . $dok_id) . '“ übernommen (' . count($nachher) . ' Änderungen)' . ($wer !== '' ? ' – ' . $wer : ''), 'spec');
    q("UPDATE dokument SET ki_daten=JSON_SET(ki_daten, '$.vergleich', ?) WHERE id=?", ['uebernommen ' . gmdate('Y-m-d H:i:s') . ' ' . $wer, $dok_id]);
    spec_nach_datenaenderung($item_id, 'Spec-Vergleich übernommen');
    return ['ok' => true, 'msg' => count($nachher) . ' Werte übernommen.'];
}
function spec_vergleich_behalten(int $item_id, int $dok_id, string $wer): void {
    try { q("UPDATE dokument SET ki_daten=JSON_SET(ki_daten, '$.vergleich', ?) WHERE id=? AND objekt_typ='item' AND objekt_id=?", ['behalten ' . gmdate('Y-m-d H:i:s') . ' ' . $wer, $dok_id, $item_id]); } catch (Throwable $e) {}
    if (function_exists('log_aktivitaet')) log_aktivitaet('item', $item_id, 'team', 'Spec-Vergleich: Stand beibehalten (Dokument #' . $dok_id . ')' . ($wer !== '' ? ' – ' . $wer : ''), 'spec');
}

// CoA einer Charge mit der bulkify-Spec prüfen. Rot (Wert außerhalb) -> Charge in Quarantäne + Aufgabe „CoA außerhalb Spec“
// (dringend) an QM (Rolle labor, sonst Team). Rückgabe: Liste der roten Parameter.
function spec_coa_charge_pruefen(int $charge_id, int $item_id, array $erg): array {
    if ($charge_id <= 0 || (string)($erg['typ'] ?? '') === 'spec') return [];
    $rot = array_values(array_filter(spec_vergleich($item_id, ['typ' => 'coa'] + $erg), fn($r) => $r['ergebnis'] === 'rot'));
    if (!$rot) return [];
    $c = one("SELECT id, charge_nr, status FROM charge WHERE id=?", [$charge_id]);
    if (!$c) return [];
    if ((string)$c['status'] === 'frei') q("UPDATE charge SET status='quarantaene' WHERE id=? AND status='frei'", [$charge_id]);   // gesperrt bleibt gesperrt
    $txt = implode('; ', array_map(fn($r) => $r['parameter'] . ': ' . $r['dok'] . ' (Spec ' . $r['unsere'] . ')', $rot));
    $qm = (int) scalar("SELECT id FROM benutzer WHERE aktiv=1 AND FIND_IN_SET('labor', REPLACE(rollen,' ',''))>0 ORDER BY id LIMIT 1");
    $name = (string) scalar("SELECT name FROM item WHERE id=?", [$item_id]);
    $offen = (int) scalar("SELECT COUNT(*) FROM aufgabe WHERE status='offen' AND ref_typ='charge' AND ref_id=? AND titel LIKE 'CoA außerhalb Spec%'", [$charge_id]);
    if (!$offen && function_exists('aufgabe_neu'))
        aufgabe_neu('CoA außerhalb Spec – ' . $name . ' Charge ' . ($c['charge_nr'] ?: '#' . $charge_id), 'Werte außerhalb der bulkify-Spec: ' . $txt . '. Die Charge steht in Quarantäne. Bitte mit dem Lieferanten klären und über die Freigabe entscheiden.',
            1, $qm ?: null, null, null, 'charge', $charge_id, '?p=rohstoff&id=' . $item_id . '&tab=chargen');
    if (function_exists('log_aktivitaet')) log_aktivitaet('item', $item_id, 'system', 'CoA Charge ' . ($c['charge_nr'] ?: '#' . $charge_id) . ' außerhalb der Spec – Quarantäne: ' . $txt, 'spec');
    return $rot;
}

// Pop-up „Vergleich mit der bulkify-Spec“ (öffnet sich sofort). $zurueck = Adresse der Rohstoffseite.
function spec_vergleich_dialog(int $item_id, int $dok_id, string $zurueck): string {
    $erg = spec_ki_vorschlag($dok_id);
    $dok = one("SELECT id, datei_orig, typ FROM dokument WHERE id=? AND objekt_typ='item' AND objekt_id=?", [$dok_id, $item_id]);
    if (!$erg || !$dok) return '';
    $istCoa = (string)($erg['typ'] ?? '') === 'coa';
    $rows = spec_vergleich($item_id, $erg);
    $farbe = ['gruen' => ['#1D9E75', 'innerhalb'], 'rot' => ['#c0392b', 'außerhalb'], 'grau' => ['#8a8f94', 'nicht angegeben'], 'pruefen' => ['#b7791f', 'selbst prüfen'], 'neu' => ['#2b6cd4', 'nicht in bulkify-Spec']];
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $o = '<dialog id="dlgSpecVgl" class="bx-dialog" open style="max-width:980px"><h2>Vergleich mit der bulkify-Spec</h2>'
       . '<p class="muted" style="margin-top:0">Dokument: ' . $h($dok['datei_orig'] ?: '#' . $dok_id) . ' · ' . ($istCoa ? 'CoA (Werte einer Charge – ändert die Spec nie automatisch)' : 'Spezifikation des Lieferanten') . '</p>';
    if (!$rows) $o .= '<p class="muted">Keine vergleichbaren Werte ausgelesen.</p>';
    else {
        $o .= '<div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Kennwert</th><th>bulkify-Spec</th><th>Wert im Dokument</th><th>Ergebnis</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            [$fb, $lbl] = $farbe[$r['ergebnis']];
            $o .= '<tr><td>' . $h($r['parameter']) . ($r['art'] !== 'neu' ? ' <span class="muted" style="font-size:11px">' . $h($r['art']) . '</span>' : '') . '</td>'
                . '<td>' . ($r['unsere'] !== '' ? $h($r['unsere']) . ($r['grenz_art'] !== '' ? ' <span class="muted" style="font-size:11px">(' . $h($r['grenz_art'] === 'Bereich' ? 'Bereich' : $r['grenz_art']) . ')</span>' : '') : '<span class="muted">–</span>') . '</td>'
                . '<td>' . ($r['dok'] !== '' ? $h($r['dok']) : '<span class="muted">–</span>') . '</td>'
                . '<td><span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;color:#fff;background:' . $fb . '">' . $lbl . '</span></td></tr>';
        }
        $o .= '</tbody></table></div>';
    }
    $o .= '<p class="muted" style="font-size:12px">„Werte übernehmen“ ersetzt die Kennwerte/Grenzwerte' . ($istCoa ? ' (nur die Grenzwert-Spalte der CoA, nie die Messwerte)' : ' und Wirkstoffgehalte') . ' am Rohstoff durch die Werte des Dokuments (Verlauf alt → neu). Es löst keine PIB-Neuprüfung aus.</p>'
        . '<form method="post" action="' . $h($zurueck) . '" style="margin:0"><input type="hidden" name="dok_id" value="' . $dok_id . '">'
        . '<div class="bx-pop-fuss"><button class="btn btn-ghost" type="submit" name="aktion" value="spec_vgl_uebernehmen" onclick="return confirm(\'Werte des Dokuments am Rohstoff übernehmen (überschreiben)?\');">Werte übernehmen (überschreiben)</button>'
        . '<button class="btn btn-primary" type="submit" name="aktion" value="spec_vgl_behalten" autofocus>Stand beibehalten</button></div></form></dialog>';
    return $o;
}
