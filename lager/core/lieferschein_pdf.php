<?php
// Lieferschein als PDF (A4 hoch) – aus einer geplanten Sendung (lg_versand + lg_versand_pos).
// Design wie die Karton-Etiketten (Charcoal/Grau, Arial via MiniPDF). Weltweit: Land wird ausgeschrieben.
//   lg_lieferschein_pdf(int $versand_id): ?string  -> PDF-Bytes oder null.
require_once __DIR__ . '/../../core/lib/minipdf.php';

// Ein paar gaengige Laendercodes ausschreiben; sonst bleibt der Code stehen.
function lg_land_name(string $code): string {
    $code = strtoupper(trim($code));
    $m = [
        'DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz', 'FR' => 'Frankreich',
        'IT' => 'Italien', 'ES' => 'Spanien', 'NL' => 'Niederlande', 'BE' => 'Belgien',
        'LU' => 'Luxemburg', 'DK' => 'Dänemark', 'PL' => 'Polen', 'CZ' => 'Tschechien',
        'SE' => 'Schweden', 'FI' => 'Finnland', 'GB' => 'Vereinigtes Königreich', 'IE' => 'Irland',
        'US' => 'USA', 'CA' => 'Kanada', 'NO' => 'Norwegen', 'PT' => 'Portugal',
    ];
    return $m[$code] ?? $code;
}

// Absenderzeilen aus den Einstellungen (Formate). Fallback: bulkify-Standard.
function lg_versand_absender(): array {
    $t = function_exists('lg_meta_lesen') ? trim((string) lg_meta_lesen('versand_absender', '')) : '';
    // Fallback: echte Firmendaten aus dem Dashboard (Einstellungen → Firma) statt Platzhalter „Musterstraße 1" (Aufräumen 10).
    if ($t === '' && function_exists('erp_firma_absender')) $t = implode("\n", erp_firma_absender());
    if ($t === '') $t = "bulkify / Maniso GmbH\nAhornstraße 45\n26842 Ostrhauderfehn\nDeutschland";
    return array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $t)), fn($x) => $x !== ''));
}

function lg_lieferschein_pdf(int $versand_id): ?string {
    if (!function_exists('lg_versand')) return null;
    $v = lg_versand($versand_id);
    if (!$v) return null;
    $pos = function_exists('lg_versand_pos_liste') ? lg_versand_pos_liste($versand_id) : [];

    $mm   = fn(float $x): float => $x / 25.4 * 72;
    $dark = [25, 25, 25]; $muted = [120, 120, 120]; $line = [205, 205, 205];
    $pdf = new MiniPDF();
    $pdf->w = $mm(210); $pdf->h = $mm(297);

    $lx = $mm(18); $rx = $mm(192); $tw = $rx - $lx;

    // Kopf
    $pdf->text($lx, $mm(20), 'bulkify', 20, true, $dark);
    $pdf->text($rx - $pdf->strwidth('Lieferschein', 18, true), $mm(20), 'Lieferschein', 18, true, $dark);
    $pdf->rectStroke($lx, $mm(24), $tw, 0.6, 0.6, $line);

    // Absender (klein) + Empfaenger
    $abs = lg_versand_absender();
    $ay = $mm(34);
    $pdf->text($lx, $ay, implode(' · ', $abs), 7.5, false, $muted);

    $ey = $mm(46);
    $pdf->text($lx, $ey, 'Empfänger', 8, false, $muted); $ey += $mm(5);
    $empfZeilen = [];
    if (trim((string)$v['empf_firma']) !== '')  $empfZeilen[] = (string)$v['empf_firma'];
    if (trim((string)$v['empf_name']) !== '')   $empfZeilen[] = (string)$v['empf_name'];
    $strasse = trim((string)$v['empf_strasse'] . ' ' . (string)$v['empf_hausnummer']);
    if ($strasse !== '') $empfZeilen[] = $strasse;
    $plzort = trim((string)$v['empf_plz'] . ' ' . (string)$v['empf_ort']);
    if ($plzort !== '') $empfZeilen[] = $plzort;
    $empfZeilen[] = lg_land_name((string)$v['empf_land']);
    foreach ($empfZeilen as $z) { $pdf->text($lx, $ey, $pdf->fit($z, $tw / 2, 12, true), 12, true, $dark); $ey += $mm(5.6); }

    // Meta rechts (Nummer, Datum, Typ)
    $meta = [
        ['Lieferschein-Nr.', (string)($v['nummer'] ?? '')],
        ['Datum', date('d.m.Y')],
        ['Versandart', ['palette' => 'Palette / Fracht', 'abholung' => 'Selbstabholung'][(string)$v['typ']] ?? 'Paket'],
        ['Pakete', (string)(int)($v['pakete'] ?? 1)],
    ];
    $pakete = max(1, (int)($v['pakete'] ?? 1));
    if (($v['gewicht_kg'] ?? null) !== null && (float)$v['gewicht_kg'] > 0) {
        $gw = (float)$v['gewicht_kg'];
        $meta[] = ['Gewicht', number_format($gw, 3, ',', '.') . ' kg' . ($pakete > 1 ? ' (' . number_format($gw / $pakete, 3, ',', '.') . ' kg/Paket)' : '')];
    }
    if (($v['versicherung_cent'] ?? null) !== null && (int)$v['versicherung_cent'] > 0)
        $meta[] = ['Warenwert (vers.)', number_format((int)$v['versicherung_cent'] / 100, 2, ',', '.') . ' €'];
    if (trim((string)($v['tracking'] ?? '')) !== '') $meta[] = ['Sendungsnr.', (string)$v['tracking']];
    $my = $mm(46); $mlx = $lx + $tw / 2 + $mm(10);
    foreach ($meta as $row) {
        $pdf->text($mlx, $my, $row[0], 8, false, $muted);
        $pdf->text($mlx + $mm(32), $my, $pdf->fit($row[1], $rx - ($mlx + $mm(32)), 10, true), 10, true, $dark);
        $my += $mm(6);
    }

    // Positionstabelle
    $ty = max($ey, $my) + $mm(8);
    $cPos = $lx; $cBez = $lx + $mm(12); $cChg = $rx - $mm(58); $cMng = $rx - $mm(30);
    $pdf->rect($lx, $ty - $mm(4.5), $tw, $mm(7), [243, 243, 243]);
    $pdf->text($cPos, $ty, 'Pos', 8, false, $muted);
    $pdf->text($cBez, $ty, 'Bezeichnung', 8, false, $muted);
    $pdf->text($cChg, $ty, 'Kartons / Charge', 8, false, $muted);
    $pdf->text($cMng, $ty, 'Menge', 8, false, $muted);
    $ty += $mm(8);

    $i = 0;
    foreach ($pos as $p) {
        $i++;
        $bez = (string)($p['bezeichnung'] ?? '');
        $zeilen = array_slice($pdf->wrap($bez, $cChg - $cBez - $mm(3), 10, false), 0, 2);
        $menge = rtrim(rtrim(number_format((float)($p['menge'] ?? 0), 3, ',', '.'), '0'), ',') . ' ' . (string)($p['einheit'] ?? '');
        $pdf->text($cPos, $ty, (string)$i, 10, false, $dark);
        $first = true;
        foreach ($zeilen as $zl) { $pdf->text($cBez, $ty + ($first ? 0 : $mm(4.6)), $zl, 10, false, $dark); $first = false; }
        // Kartons × Menge/Karton (Lieferschein-Generator) – sonst die Chargennummer.
        $kt = (int)($p['kartons'] ?? 0);
        $chgTxt = $kt > 0
            ? $kt . ' Kt' . (($p['menge_karton'] ?? null) ? ' × ' . rtrim(rtrim(number_format((float)$p['menge_karton'], 3, ',', '.'), '0'), ',') : '')
            : (string)($p['charge_nr'] ?? '');
        $pdf->text($cChg, $ty, $pdf->fit($chgTxt, $cMng - $cChg - $mm(2), 9.5, false), 9.5, false, $dark);
        $pdf->text($cMng, $ty, $pdf->fit($menge, $rx - $cMng, 10, true), 10, true, $dark);
        $ty += $mm(6.4) * max(1, count($zeilen)) + $mm(1.6);
        $pdf->rectStroke($lx, $ty - $mm(2.4), $tw, 0.3, 0.3, $line);
        if ($ty > $mm(275)) break;   // eine Seite reicht fuer Phase 1
    }
    if (!$pos) { $pdf->text($cBez, $ty, 'Keine Positionen erfasst.', 10, false, $muted); $ty += $mm(6); }

    // Soll-Kette 7b: Gliederung nach Packstück (Paletten mit ihren Kartons, lose Kartons/Pakete) – EIN Gesamtlieferschein.
    $pst = function_exists('lg_packstueck_liste') ? lg_packstueck_liste($versand_id) : [];
    if ($pst) {
        $pal = array_values(array_filter($pst, fn($x) => $x['art'] === 'palette'));
        $nPal = count($pal);
        $zeile = function (array $x, float $einzug) use ($pdf, $mm, $lx, $rx, $tw, &$ty, $dark, $muted, $line, $cChg, $cMng): void {
            if ($ty > $mm(272)) { $pdf->addPage(); $ty = $mm(20); }
            $lbl = ($x['art'] === 'karton' ? 'Karton ' : 'Paket ') . (int)$x['nr'];
            $pdf->text($lx + $einzug, $ty, $lbl, 9.5, true, $dark);
            $pdf->text($lx + $einzug + $mm(22), $ty, $pdf->fit((string)($x['bezeichnung'] ?? ''), $cChg - $lx - $einzug - $mm(24), 9.5, false), 9.5, false, $dark);
            $pdf->text($cChg, $ty, $pdf->fit((string)($x['charge_nr'] ?? ''), $cMng - $cChg - $mm(2), 9.5, false), 9.5, false, $dark);
            if ($x['menge'] !== null) $pdf->text($cMng, $ty, rtrim(rtrim(number_format((float)$x['menge'], 3, ',', '.'), '0'), ',') . ' Pkg', 9.5, true, $dark);
            $ty += $mm(5.4);
        };
        $ty += $mm(6);
        if ($ty > $mm(260)) { $pdf->addPage(); $ty = $mm(20); }
        $pdf->text($lx, $ty, 'Packstücke', 11, true, $dark); $ty += $mm(6.5);
        foreach ($pal as $i => $pp) {
            if ($ty > $mm(268)) { $pdf->addPage(); $ty = $mm(20); }
            $kinder = array_values(array_filter($pst, fn($x) => (int)$x['parent_id'] === (int)$pp['id']));
            $sum = array_sum(array_map(fn($x) => (float)$x['menge'], $kinder));
            $pdf->rect($lx, $ty - $mm(4.5), $tw, $mm(6.6), [243, 243, 243]);
            $pdf->text($lx + $mm(1), $ty, 'Palette ' . (int)$pp['nr'] . ' von ' . $nPal . ' · ' . count($kinder) . ' Karton(s) · ' . rtrim(rtrim(number_format($sum, 3, ',', '.'), '0'), ',') . ' Pkg'
                . ($pp['gewicht_kg'] !== null ? ' · ' . rtrim(rtrim(number_format((float)$pp['gewicht_kg'], 3, ',', '.'), '0'), ',') . ' kg' : ''), 10, true, $dark);
            $ty += $mm(7);
            foreach ($kinder as $kd) $zeile($kd, $mm(4));
            $ty += $mm(2);
        }
        $lose = array_values(array_filter($pst, fn($x) => $x['art'] !== 'palette' && empty($x['parent_id'])));
        if ($lose) {
            if ($ty > $mm(268)) { $pdf->addPage(); $ty = $mm(20); }
            $pdf->text($lx, $ty, $nPal ? 'Ohne Palette' : 'Kartons / Pakete', 10, true, $dark); $ty += $mm(6);
            foreach ($lose as $kd) $zeile($kd, $mm(0));
        }
    }

    // Notiz + Fuss
    if (trim((string)($v['notiz'] ?? '')) !== '') {
        $ty += $mm(6);
        $pdf->text($lx, $ty, 'Hinweis', 8, false, $muted); $ty += $mm(5);
        foreach (array_slice($pdf->wrap((string)$v['notiz'], $tw, 9.5, false), 0, 4) as $zl) { $pdf->text($lx, $ty, $zl, 9.5, false, $dark); $ty += $mm(4.8); }
    }
    if ($ty > $mm(280)) { $pdf->addPage(); $ty = $mm(20); }
    $pdf->text($lx, $mm(284), $pdf->fit(pdf_hinweis_maschinell(), $tw, 7.5, false), 7.5, false, $muted);
    $pdf->text($lx, $mm(288), 'Erstellt am ' . (function_exists('fmt_zeit') ? fmt_zeit(gmdate('Y-m-d H:i:s')) : date('d.m.Y H:i')) . ' · ' . (string)($v['nummer'] ?? '') . ' · bulkify Lager', 7.5, false, $muted);

    return $pdf->output();
}

// Paletten-Etiketten einer Sendung (Soll-Kette 7b): je Palette 100 x 150 mm – „Palette 2 von 3“, Sendung, Empfänger, Inhalt dieses Packstücks.
function lg_paletten_etikett_pdf(int $versand_id): ?string {
    $v = function_exists('lg_versand') ? lg_versand($versand_id) : null;
    $pst = function_exists('lg_packstueck_liste') ? lg_packstueck_liste($versand_id) : [];
    $pal = array_values(array_filter($pst, fn($x) => $x['art'] === 'palette'));
    if (!$v || !$pal) return null;
    $mm = fn(float $x): float => $x / 25.4 * 72;
    $pdf = new MiniPDF(); $pdf->w = $mm(100); $pdf->h = $mm(150);
    $dark = [20, 20, 20]; $muted = [120, 120, 120]; $line = [205, 205, 205];
    $W = $pdf->w; $lx = $mm(6); $tw = $W - $mm(12); $n = count($pal);
    foreach ($pal as $i => $pp) {
        if ($i > 0) $pdf->addPage();
        $pdf->rectStroke($mm(2), $mm(2), $W - $mm(4), $pdf->h - $mm(4), 0.6, $line);
        $pdf->text($lx, $mm(10), 'PALETTE · ' . (string)$v['nummer'], 10, true, $muted);
        $pdf->text($lx, $mm(24), 'Palette ' . (int)$pp['nr'] . ' von ' . $n, 26, true, $dark);
        $y = $mm(34);
        $pdf->text($lx, $y, 'Empfänger', 8, false, $muted); $y += $mm(5);
        foreach (array_filter([(string)$v['empf_firma'], trim((string)$v['empf_strasse'] . ' ' . (string)$v['empf_hausnummer']), trim((string)$v['empf_plz'] . ' ' . (string)$v['empf_ort'])]) as $z) {
            $pdf->text($lx, $y, $pdf->fit($z, $tw, 12, true), 12, true, $dark); $y += $mm(5.4);
        }
        $y += $mm(3);
        $kinder = array_values(array_filter($pst, fn($x) => (int)$x['parent_id'] === (int)$pp['id']));
        $pdf->text($lx, $y, 'Inhalt: ' . count($kinder) . ' Karton(s)', 8, false, $muted); $y += $mm(5.5);
        $grp = [];   // gleiche Produkt/Charge zusammenfassen
        foreach ($kinder as $kd) { $k = (string)$kd['bezeichnung'] . '|' . (string)$kd['charge_nr']; $grp[$k] = ($grp[$k] ?? ['b' => (string)$kd['bezeichnung'], 'c' => (string)$kd['charge_nr'], 'm' => 0.0, 'n' => 0]); $grp[$k]['m'] += (float)$kd['menge']; $grp[$k]['n']++; }
        foreach ($grp as $g) {
            if ($y > $mm(120)) break;
            foreach (array_slice($pdf->wrap($g['b'] !== '' ? $g['b'] : '–', $tw, 11, true), 0, 2) as $ln) { $pdf->text($lx, $y, $ln, 11, true, $dark); $y += $mm(4.8); }
            $pdf->text($lx, $y, 'Charge ' . ($g['c'] !== '' ? $g['c'] : '–') . ' · ' . $g['n'] . ' Kt · ' . rtrim(rtrim(number_format($g['m'], 3, ',', '.'), '0'), ',') . ' Pkg', 10, false, $dark);
            $y += $mm(7);
        }
        if ($pp['gewicht_kg'] !== null) $pdf->text($lx, $mm(128), 'Gewicht ' . rtrim(rtrim(number_format((float)$pp['gewicht_kg'], 3, ',', '.'), '0'), ',') . ' kg', 11, true, $dark);
        $code = (string)$v['nummer'] . '-P' . str_pad((string)(int)$pp['nr'], 2, '0', STR_PAD_LEFT);
        if (function_exists('lg_barcode128')) lg_barcode128($pdf, $lx - $mm(1), $pdf->h - $mm(18), $tw + $mm(2), $mm(9), $code, $dark);
        $pdf->text($lx, $pdf->h - $mm(5.5), $code, 9, true, $dark);
    }
    return $pdf->output();
}
