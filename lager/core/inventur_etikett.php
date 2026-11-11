<?php
// Etiketten der Inventur (100 x 70 mm, Druckbrücke): Platz-Etikett (Barcode LP-00012 am Regal).
require_once __DIR__ . '/etikett_pdf.php';
require_once __DIR__ . '/inventur.php';

// Platz-Etiketten für die Regale: Platzname groß, QR + Strichcode mit dem Platz-Code (LP-00012). Ein Etikett je Seite.
function inv_platz_etikett_pdf(array $ids): ?string {
    $plaetze = [];
    foreach ($ids as $id) { $p = inv_platz((int)$id); if ($p) $plaetze[] = $p; }
    if (!$plaetze) return null;
    $mm = fn(float $v): float => $v / 25.4 * 72;
    $pdf = new MiniPDF();
    $pdf->w = $mm(100); $pdf->h = $mm(70);
    $dark = [20, 20, 20]; $muted = [120, 120, 120]; $line = [205, 205, 205];
    foreach ($plaetze as $k => $p) {
        if ($k > 0) $pdf->addPage();
        $W = $pdf->w; $code = inv_platz_code((int)$p['id']);
        $pdf->rectStroke($mm(1.5), $mm(1.5), $W - $mm(3), $pdf->h - $mm(3), 0.6, $line);
        $qa = $mm(34); $qx = $W - $mm(4) - $qa; $qy = $mm(5);
        $m = qr_matrix($code);
        if ($m) {
            $n = count($m); $quiet = 2; $mod = $qa / ($n + 2 * $quiet);
            $pdf->rect($qx, $qy, $qa, $qa, [255, 255, 255]);
            for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++)
                if ($m[$y][$x]) $pdf->rect($qx + ($x + $quiet) * $mod, $qy + ($y + $quiet) * $mod, $mod + 0.25, $mod + 0.25, $dark);
        }
        $lx = $mm(5); $tw = $qx - $lx - $mm(3);
        $pdf->text($lx, $mm(9), 'bulkify · Lagerplatz · ' . ((string)$p['lager'] === 'lager2' ? 'Lager 2' : 'Lager 1'), 7.5, false, $muted);
        $name = inv_platz_name($p);
        $gr = 34; while ($gr > 14 && $pdf->strwidth($name, $gr, true) > $tw) $gr -= 2;
        $pdf->text($lx, $mm(24), $name, $gr, true, $dark);
        $pdf->text($lx, $mm(31), 'Bereich ' . (string)$p['bereich'] . ' · Regal ' . (int)$p['regal'] . ' · Ebene ' . (int)$p['ebene'] . ' · Fach ' . (int)$p['fach'], 8, false, $muted);
        lg_barcode128($pdf, $lx, $mm(44), $W - $lx - $mm(5), $mm(13), $code, $dark);
        $pdf->textCenter($W / 2, $mm(63), $code, 11, true, $dark);
    }
    return $pdf->output();
}

// Etikett für ein Gebinde, das beim Zählen ohne Etikett gefunden wurde (Klärfall): Artikel, abgetippte Charge, MHD, Menge,
// neue Inventarnummer groß als QR + Strichcode. Ab jetzt ist das Gebinde scanbar; beim Buchen bekommt das neue Gebinde diese Nummer.
function inv_klaerfall_etikett_pdf(int $zaehlung_id): ?string {
    $z = one("SELECT * FROM lg_inventur_zaehlung WHERE id=? AND obj='k'", [$zaehlung_id]);
    if (!$z || (string)$z['kf_inventar_nr'] === '') return null;
    $it = erp_inv_item((int)$z['item_id']);
    $mm = fn(float $v): float => $v / 25.4 * 72;
    $pdf = new MiniPDF();
    $pdf->w = $mm(100); $pdf->h = $mm(70);
    $dark = [20, 20, 20]; $muted = [120, 120, 120]; $line = [205, 205, 205];
    $W = $pdf->w; $nr = (string)$z['kf_inventar_nr'];
    $pdf->rectStroke($mm(1.5), $mm(1.5), $W - $mm(3), $pdf->h - $mm(3), 0.6, $line);
    $qa = $mm(30); $qx = $W - $mm(4) - $qa; $qy = $mm(5);
    $m = qr_matrix($nr);
    if ($m) {
        $n = count($m); $quiet = 2; $mod = $qa / ($n + 2 * $quiet);
        $pdf->rect($qx, $qy, $qa, $qa, [255, 255, 255]);
        for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++)
            if ($m[$y][$x]) $pdf->rect($qx + ($x + $quiet) * $mod, $qy + ($y + $quiet) * $mod, $mod + 0.25, $mod + 0.25, $dark);
    }
    $lx = $mm(5); $tw = $qx - $lx - $mm(3); $yy = $mm(9);
    $pdf->text($lx, $yy, 'bulkify · Inventur · Gebinde ohne Etikett', 7, false, $muted); $yy += $mm(6);
    foreach (array_slice($pdf->wrap((string)($it['name'] ?? ''), $tw, 11.5, true), 0, 2) as $ln) { $pdf->text($lx, $yy, $ln, 11.5, true, $dark); $yy += $mm(5); }
    $yy += $mm(1.5);
    $pdf->text($lx, $yy, 'Charge (abgetippt)', 7, false, $muted); $pdf->text($lx, $yy + $mm(3.6), $pdf->fit((string)($z['kf_charge_nr'] ?: '–'), $tw, 10.5, true), 10.5, true, $dark); $yy += $mm(8.4);
    $pdf->text($lx, $yy, 'MHD', 7, false, $muted); $pdf->text($lx, $yy + $mm(3.6), $z['kf_mhd'] ? date('d.m.Y', strtotime((string)$z['kf_mhd'])) : '–', 10.5, true, $dark);
    $pdf->text($lx + $tw / 2, $yy, 'Menge (gezählt)', 7, false, $muted); $pdf->text($lx + $tw / 2, $yy + $mm(3.6), inv_mz($z['ist']) . ' ' . (string)$z['einheit'], 10.5, true, $dark);
    $pdf->textCenter($qx + $qa / 2, $qy + $qa + $mm(5), 'Inventar-Nr.', 7, false, $muted);
    $pdf->textCenter($qx + $qa / 2, $qy + $qa + $mm(10.5), $nr, 13, true, $dark);
    lg_barcode128($pdf, $lx, $mm(51), $W - $lx - $mm(5), $mm(11), $nr, $dark);
    $pdf->text($lx, $mm(66), 'Klärfall – wird nach der Auswertung als Charge gebucht.', 6.5, false, $muted);
    return $pdf->output();
}
