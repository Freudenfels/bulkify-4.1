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
    if ($t === '') $t = "bulkify / Maniso GmbH\nMusterstraße 1\n00000 Musterstadt\nDeutschland";
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
        ['Versandart', ((string)$v['typ'] === 'palette' ? 'Palette / Fracht' : 'Paket')],
        ['Pakete', (string)(int)($v['pakete'] ?? 1)],
    ];
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
    $pdf->text($cChg, $ty, 'Charge', 8, false, $muted);
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
        $pdf->text($cChg, $ty, $pdf->fit((string)($p['charge_nr'] ?? ''), $cMng - $cChg - $mm(2), 9.5, false), 9.5, false, $dark);
        $pdf->text($cMng, $ty, $pdf->fit($menge, $rx - $cMng, 10, true), 10, true, $dark);
        $ty += $mm(6.4) * max(1, count($zeilen)) + $mm(1.6);
        $pdf->rectStroke($lx, $ty - $mm(2.4), $tw, 0.3, 0.3, $line);
        if ($ty > $mm(275)) break;   // eine Seite reicht fuer Phase 1
    }
    if (!$pos) { $pdf->text($cBez, $ty, 'Keine Positionen erfasst.', 10, false, $muted); $ty += $mm(6); }

    // Notiz + Fuss
    if (trim((string)($v['notiz'] ?? '')) !== '') {
        $ty += $mm(6);
        $pdf->text($lx, $ty, 'Hinweis', 8, false, $muted); $ty += $mm(5);
        foreach (array_slice($pdf->wrap((string)$v['notiz'], $tw, 9.5, false), 0, 4) as $zl) { $pdf->text($lx, $ty, $zl, 9.5, false, $dark); $ty += $mm(4.8); }
    }
    $pdf->text($lx, $mm(288), 'Erstellt am ' . date('d.m.Y H:i') . ' · ' . (string)($v['nummer'] ?? '') . ' · bulkify Lager', 7.5, false, $muted);

    return $pdf->output();
}
