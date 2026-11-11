<?php
// Inventur – Abschluss und Protokoll (11.11.2026). Abschließen geht erst, wenn alle Plätze gezählt und alle Abweichungen
// entschieden sind (oder bewusst offen gelassen). Dann bekommen alle gezählten Gebinde ihren gezählten Platz, die Sperre der
// Plätze fällt, und das Inventurprotokoll (PDF + CSV) wird an der Inventur abgelegt (BX_UPLOADS).

function inv_abschliessen(array $inv): array {
    $id = (int)$inv['id'];
    if ((string)$inv['status'] === 'abgeschlossen') return ['ok' => false, 'msg' => 'Schon abgeschlossen.'];
    if ((string)$inv['status'] !== 'auswerten') return ['ok' => false, 'msg' => 'Erst „Zählung beenden“.'];
    $offenB = all("SELECT name FROM lg_inventur_bereich WHERE inventur_id=? AND status<>'fertig'", [$id]);
    if ($offenB) return ['ok' => false, 'msg' => 'Noch nicht gezählt: ' . implode(', ', array_column($offenB, 'name')) . '.'];
    $offen = (int) scalar("SELECT COUNT(*) FROM lg_inventur_diff WHERE inventur_id=? AND status IN ('offen','nachzaehlen')", [$id]);
    if ($offen) return ['ok' => false, 'msg' => $offen . ' Abweichung(en) noch nicht entschieden (oder bewusst „offen lassen“ mit Notiz).'];
    // Lagerplätze setzen: jedes gezählte Gebinde liegt jetzt auf dem Platz, auf dem es gezählt wurde (Kisten bleiben Kisten).
    $n = 0;
    foreach (all("SELECT z.obj, z.charge_id, z.gebinde_id, b.schluessel FROM lg_inventur_zaehlung z JOIN lg_inventur_bereich b ON b.id=z.bereich_id
                  WHERE z.inventur_id=? AND z.ersetzt=0 AND z.charge_id>0 AND z.art<>'leer' ORDER BY z.durchgang, z.id", [$id]) as $z) {
        if (!preg_match('/^p:(\d+)$/', (string)$z['schluessel'], $m)) continue;
        $alt = inv_ort_von((int)$z['charge_id'], (int)$z['gebinde_id']);
        if ($alt['key'] === 'p:' . $m[1]) continue;
        inv_platz_setzen((int)$z['charge_id'], (int)$z['gebinde_id'], (int)$m[1], 'Inventur ' . $inv['name']); $n++;
    }
    q("UPDATE lg_inventur SET status='abgeschlossen', abgeschlossen_am=?, abgeschlossen_von=? WHERE id=?", [jetzt_utc(), lg_uid() ?: null, $id]);
    q("UPDATE lg_inventur_bereich SET sperre=NULL WHERE inventur_id=?", [$id]);   // erst nach dem Status: Plätze sind wieder frei
    $inv = inv_get($id);
    $pdf = inv_protokoll_pdf($inv); $csv = inv_protokoll_csv($inv);
    $basis = 'inventur_' . $id . '_' . bin2hex(random_bytes(4));
    $ok = @file_put_contents(BX_UPLOADS . '/' . $basis . '.pdf', $pdf) !== false && @file_put_contents(BX_UPLOADS . '/' . $basis . '.csv', $csv) !== false;
    if ($ok) q("UPDATE lg_inventur SET protokoll_pdf=?, protokoll_csv=? WHERE id=?", [$basis . '.pdf', $basis . '.csv', $id]);
    return ['ok' => true, 'msg' => 'Inventur abgeschlossen. ' . $n . ' Lagerplatz-Zuordnung(en) gesetzt. ' . ($ok ? 'Protokoll (PDF/CSV) abgelegt.' : 'Protokoll konnte nicht gespeichert werden – Download erzeugt es neu.')];
}

// Zeilen des Protokolls: je gezähltem/abweichendem Artikel/Charge Soll, Ist, Differenz, Wert, Ursache.
function inv_protokoll_zeilen(array $inv): array {
    $U = function_exists('inv_ursachen') ? inv_ursachen() : []; $A = inv_diff_arten();
    $out = [];
    // „nicht gefunden", später doch gezählt (status passt) = überholt -> nicht ins Protokoll.
    foreach (all("SELECT * FROM lg_inventur_diff WHERE inventur_id=? AND art<>'falscher_platz' AND NOT (art='nicht_gefunden' AND status='passt' AND notiz='später gezählt') ORDER BY (status='passt'), ABS(COALESCE(wert_eur,0)) DESC, id", [(int)$inv['id']]) as $d) {
        $o = (int)$d['charge_id'] ? erp_inv_objekt((int)$d['gebinde_id'] ? 'g' : 'c', (int)$d['gebinde_id'] ?: (int)$d['charge_id']) : null;
        $out[] = [
            'artikel' => (string)(erp_inv_item((int)$d['item_id'])['name'] ?? ''),
            'charge'  => $o ? (string)($o['charge_nr'] ?: '#' . $o['charge_id']) . ($o['obj'] === 'g' ? ' G' . (int)$o['g_nr'] . '/' . (int)$o['g_von'] : '') : '',
            'ort'     => inv_ort_name((string)($d['ist_ort'] ?: $d['soll_ort'])),
            'soll'    => $d['soll'] !== null ? inv_mz($d['soll']) . ' ' . $d['soll_einheit'] : '',
            'ist'     => $d['ist'] !== null ? inv_mz($d['ist']) . ' ' . $d['einheit'] : '',
            'diff'    => $d['diff'] !== null && abs((float)$d['diff']) > 0.0005 ? (($d['diff'] > 0 ? '+' : '') . inv_mz($d['diff'])) : '0',
            'wert'    => $d['wert_eur'] !== null ? number_format((float)$d['wert_eur'], 2, ',', '.') : '',
            'art'     => $A[$d['art']] ?? $d['art'],
            'ursache' => $d['status'] === 'passt' ? 'passt' : ($U[$d['ursache']][0] ?? ($d['status'] === 'offen_gelassen' ? 'offen gelassen' : (string)$d['status'])),
            'ergebnis'=> trim((string)$d['ergebnis'] . ((string)$d['notiz'] !== '' && $d['status'] !== 'passt' ? ' · ' . $d['notiz'] : '')),
            'gebucht' => $d['status'] === 'gebucht' ? (float)($d['wert_eur'] ?? 0) : 0.0,
        ];
    }
    // Umlagerungen (falscher Platz) separat als eine Zeile je Gebinde
    foreach (all("SELECT * FROM lg_inventur_diff WHERE inventur_id=? AND art='falscher_platz'", [(int)$inv['id']]) as $d)
        $out[] = ['artikel' => (string)(erp_inv_item((int)$d['item_id'])['name'] ?? ''), 'charge' => '#' . (int)$d['charge_id'], 'ort' => inv_ort_name((string)$d['soll_ort']) . ' → ' . inv_ort_name((string)$d['ist_ort']),
                  'soll' => '', 'ist' => '', 'diff' => '', 'wert' => '', 'art' => 'Falscher Platz', 'ursache' => $d['status'] === 'gebucht' ? 'Umlagerung' : (string)$d['status'], 'ergebnis' => (string)$d['ergebnis'], 'gebucht' => 0.0];
    return $out;
}
function inv_protokoll_neu_etikettiert(array $inv): array {
    return all("SELECT z.kf_inventar_nr, z.kf_charge_nr, z.kf_mhd, z.ist, z.einheit, z.kf_charge_id, i.name FROM lg_inventur_zaehlung z LEFT JOIN item i ON i.id=z.item_id
                WHERE z.inventur_id=? AND z.obj='k' AND z.ersetzt=0 ORDER BY z.id", [(int)$inv['id']]);
}
function inv_protokoll_kopf(array $inv): array {
    $z = array_filter(array_map(fn($u) => erp_inv_benutzer_name((int)$u), explode(',', (string)$inv['zaehler'])));
    $gez = array_unique(array_filter(array_map(fn($r) => erp_inv_benutzer_name((int)$r['zaehler_id']), all("SELECT DISTINCT zaehler_id FROM lg_inventur_zaehlung WHERE inventur_id=?", [(int)$inv['id']]))));
    return ['Inventur' => (string)$inv['name'], 'Stichtag' => date('d.m.Y', strtotime((string)$inv['stichtag'])), 'Umfang' => inv_umfang_text($inv),
            'Zähler' => implode(', ', $gez ?: $z) ?: '–', 'Abgeschlossen' => $inv['abgeschlossen_am'] ? fmt_zeit((string)$inv['abgeschlossen_am']) . ' · ' . erp_inv_benutzer_name((int)$inv['abgeschlossen_von']) : 'noch offen'];
}
function inv_protokoll_csv(array $inv): string {
    $f = fopen('php://temp', 'w+');
    fwrite($f, "\xEF\xBB\xBF");
    foreach (inv_protokoll_kopf($inv) as $k => $v) fputcsv($f, [$k, $v], ';');
    $zeilen = inv_protokoll_zeilen($inv);
    fputcsv($f, ['Summe Wertkorrekturen (EUR)', number_format(array_sum(array_column($zeilen, 'gebucht')), 2, ',', '.')], ';');
    fputcsv($f, [], ';');
    fputcsv($f, ['Artikel', 'Charge/Gebinde', 'Ort', 'Soll', 'Ist', 'Differenz', 'Wert EUR', 'Art', 'Ursache', 'Ergebnis'], ';');
    foreach ($zeilen as $r) fputcsv($f, [$r['artikel'], $r['charge'], $r['ort'], $r['soll'], $r['ist'], $r['diff'], $r['wert'], $r['art'], $r['ursache'], $r['ergebnis']], ';');
    fputcsv($f, [], ';');
    fputcsv($f, ['Neu etikettierte Gebinde', 'Inventar-Nr', 'Charge', 'MHD', 'Menge', 'gebucht als Charge'], ';');
    foreach (inv_protokoll_neu_etikettiert($inv) as $k) fputcsv($f, [(string)$k['name'], (string)$k['kf_inventar_nr'], (string)$k['kf_charge_nr'], $k['kf_mhd'] ? date('d.m.Y', strtotime((string)$k['kf_mhd'])) : '', inv_mz($k['ist']) . ' ' . $k['einheit'], $k['kf_charge_id'] ? '#' . $k['kf_charge_id'] : 'nein'], ';');
    rewind($f); $s = stream_get_contents($f); fclose($f);
    return (string)$s;
}
function inv_protokoll_pdf(array $inv): string {
    require_once __DIR__ . '/../../core/lib/minipdf.php';
    $pdf = new MiniPDF();
    $mm = fn(float $v): float => $v / 25.4 * 72;
    $W = $pdf->w; $H = $pdf->h; $lx = $mm(14); $rx = $W - $mm(14); $tw = $rx - $lx;
    $dark = [30, 30, 30]; $muted = [115, 115, 115]; $line = [210, 210, 210]; $gruen = [29, 158, 117];
    $zeilen = inv_protokoll_zeilen($inv); $neu = inv_protokoll_neu_etikettiert($inv);
    $summe = array_sum(array_column($zeilen, 'gebucht'));
    $seite = 1;
    $fuss = function () use ($pdf, $mm, $lx, $tw, $H, $muted, &$seite, $inv) {
        $pdf->text($lx, $H - $mm(10), $pdf->fit(pdf_hinweis_maschinell(), $tw - $mm(30), 7, false), 7, false, $muted);
        $pdf->textRight($lx + $tw, $H - $mm(10), 'Seite ' . $seite, 7, false, $muted);
    };
    $y = $mm(18);
    $pdf->text($lx, $y, 'bulkify · Maniso GmbH', 8, false, $muted); $y += $mm(8);
    $pdf->text($lx, $y, 'Inventurprotokoll', 18, true, $dark); $y += $mm(8);
    foreach (inv_protokoll_kopf($inv) as $k => $v) {
        $pdf->text($lx, $y, $k, 8.5, false, $muted);
        $pdf->text($lx + $mm(32), $y, $pdf->fit($v, $tw - $mm(32), 9, false), 9, false, $dark); $y += $mm(5);
    }
    $y += $mm(2);
    $pdf->text($lx, $y, 'Summe der Wertkorrekturen: ' . number_format($summe, 2, ',', '.') . ' €  ·  ' . count($zeilen) . ' Positionen  ·  ' . count($neu) . ' neu etikettierte Gebinde', 9.5, true, $gruen);
    $y += $mm(8);
    // Tabelle
    $sp = [['Artikel', 40, 'l'], ['Charge', 22, 'l'], ['Ort', 15, 'l'], ['Soll', 18, 'r'], ['Ist', 18, 'r'], ['Diff.', 15, 'r'], ['Wert €', 14, 'r'], ['Ursache / Ergebnis', 40, 'l']];
    $summeMm = array_sum(array_column($sp, 1)); $f = $tw / $mm($summeMm);
    $kopf = function () use ($pdf, $sp, $mm, $lx, &$y, $f, $muted, $line) {
        $x = $lx;
        foreach ($sp as $k => [$t, $w, $al]) { $ww = $mm($w) * $f; $al === 'r' ? $pdf->textRight($x + $ww - 4, $y, $t, 7.5, false, $muted) : $pdf->text($x + ($k === 7 ? 2 : 0), $y, $t, 7.5, false, $muted); $x += $ww; }
        $y += $mm(2); $pdf->line($lx, $y, $lx + array_sum(array_map(fn($s) => $mm($s[1]) * $f, $sp)), $y, 0.5, $line); $y += $mm(4);
    };
    $kopf();
    foreach ($zeilen as $r) {
        $erg = trim($r['ursache'] . ($r['ergebnis'] !== '' ? ': ' . $r['ergebnis'] : ''));
        $ergZeilen = array_slice($pdf->wrap($erg, $mm($sp[7][1]) * $f - 4, 6.5, false), 0, 4);
        $h = max(1, count($ergZeilen)) * $mm(3.2) + $mm(1.6);
        if ($y + $h > $H - $mm(18)) { $fuss(); $pdf->addPage(); $seite++; $y = $mm(18); $kopf(); }
        $x = $lx; $vals = [$r['artikel'], $r['charge'], $r['ort'], $r['soll'], $r['ist'], $r['diff'], $r['wert']];
        foreach ($vals as $k => $v) { $ww = $mm($sp[$k][1]) * $f; $t = $pdf->fit((string)$v, $ww - 5, 7, false); $sp[$k][2] === 'r' ? $pdf->textRight($x + $ww - 4, $y, $t, 7, false, $dark) : $pdf->text($x, $y, $t, 7, false, $dark); $x += $ww; }
        foreach ($ergZeilen as $i => $ln) $pdf->text($x + 2, $y + $i * $mm(3.2), $ln, 6.5, false, $muted);
        $y += $h; $pdf->line($lx, $y - $mm(2.4), $lx + $tw, $y - $mm(2.4), 0.3, $line);
    }
    if ($neu) {
        if ($y + $mm(20) > $H - $mm(18)) { $fuss(); $pdf->addPage(); $seite++; $y = $mm(18); }
        $y += $mm(6); $pdf->text($lx, $y, 'Neu etikettierte Gebinde (gefunden ohne Etikett)', 10, true, $dark); $y += $mm(6);
        foreach ($neu as $k) {
            if ($y > $H - $mm(20)) { $fuss(); $pdf->addPage(); $seite++; $y = $mm(18); }
            $pdf->text($lx, $y, $pdf->fit('Inv ' . $k['kf_inventar_nr'] . '  ·  ' . (string)$k['name'] . '  ·  Charge ' . ($k['kf_charge_nr'] ?: '–') . '  ·  MHD ' . ($k['kf_mhd'] ? date('d.m.Y', strtotime((string)$k['kf_mhd'])) : '–') . '  ·  ' . inv_mz($k['ist']) . ' ' . $k['einheit'] . '  ·  ' . ($k['kf_charge_id'] ? 'gebucht als Charge #' . $k['kf_charge_id'] : 'nicht gebucht'), $tw, 7.5, false), 7.5, false, $dark);
            $y += $mm(4.5);
        }
    }
    $fuss();
    return $pdf->output();
}
