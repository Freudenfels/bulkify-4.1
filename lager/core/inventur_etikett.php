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
