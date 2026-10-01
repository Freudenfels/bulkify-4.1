<?php
// Charge-Etikett als PDF (100 mm breit x 70 mm hoch) mit QR-Code zum Aufkleben.
// QR fuehrt auf die Charge-Detailseite im Lager (Scan mit Handy -> alles zur Charge).
// Einzeln:  ?p=etikett&id=<charge_id>   |   Stapel: ?p=etikett&ids=1,2,3 (eine Seite je Charge).
require_once __DIR__ . '/../../core/qr.php';
require_once __DIR__ . '/../../../core/lib/minipdf.php';

$ids = [];
if (isset($_GET['ids'])) {
    foreach (explode(',', (string)$_GET['ids']) as $x) { $x = (int)trim($x); if ($x > 0) $ids[] = $x; }
} elseif (isset($_GET['id'])) {
    $id = (int)$_GET['id']; if ($id > 0) $ids[] = $id;
}
$ids = array_values(array_unique($ids));
if (!$ids) { http_response_code(404); echo 'Keine Charge angegeben.'; exit; }

$host   = (string)($_SERVER['HTTP_HOST'] ?? 'app.bulkify.pro');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

$pdf = new MiniPDF();
$pdf->w = 100 / 25.4 * 72;   // 283.46 pt = 100 mm
$pdf->h = 70 / 25.4 * 72;    //  198.43 pt = 70 mm
$mm = fn(float $v): float => $v / 25.4 * 72;

$erste = true;
foreach ($ids as $cid) {
    $c = erp_charge_voll($cid);
    if (!$c) continue;
    if (!$erste) $pdf->addPage();
    $erste = false;
    lg_etikett_zeichnen($pdf, $mm, $c, $scheme . '://' . $host . '/lager/?p=charge&id=' . $cid);
}
if ($erste) { http_response_code(404); echo 'Charge nicht gefunden.'; exit; }

$out = $pdf->output();
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="etikett' . (count($ids) > 1 ? '-' . count($ids) : '') . '.pdf"');
header('Content-Length: ' . strlen($out));
header('Cache-Control: no-store');
echo $out;
exit;

// Ein Etikett auf die aktuelle Seite zeichnen.
function lg_etikett_zeichnen(MiniPDF $pdf, callable $mm, array $c, string $url): void {
    $W = $pdf->w; $H = $pdf->h;
    $dark = [20, 20, 20]; $muted = [120, 120, 120]; $line = [205, 205, 205];

    // Duenner Rahmen als Schnitt-/Klebehilfe.
    $pdf->rectStroke($mm(1.5), $mm(1.5), $W - $mm(3), $H - $mm(3), 0.6, $line);

    // QR-Code rechts.
    $qrArea = $mm(40);
    $qx = $W - $mm(4) - $qrArea;
    $qy = $mm(4);
    $m = qr_matrix($url);
    if ($m) {
        $n = count($m); $quiet = 2; $mod = $qrArea / ($n + 2 * $quiet);
        $pdf->rect($qx, $qy, $qrArea, $qrArea, [255, 255, 255]);
        for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++) {
            if ($m[$y][$x]) $pdf->rect($qx + ($x + $quiet) * $mod, $qy + ($y + $quiet) * $mod, $mod + 0.25, $mod + 0.25, $dark);
        }
    }
    if (!empty($c['charge_nr'])) {
        $pdf->textCenter($qx + $qrArea / 2, $qy + $qrArea + $mm(5), (string)$c['charge_nr'], 8, false, $muted);
    }

    // Textblock links.
    $lx = $mm(4);
    $tw = $qx - $lx - $mm(3);
    $pdf->text($lx, $mm(7.5), 'bulkify · Lager', 7, false, $muted);

    $yy = $mm(14);
    $name = (string)($c['item_name'] ?? '');
    foreach (array_slice($pdf->wrap($name, $tw, 13, true), 0, 3) as $ln) {
        $pdf->text($lx, $yy, $ln, 13, true, $dark);
        $yy += $mm(5.4);
    }
    if (!empty($c['artikelnummer'])) { $pdf->text($lx, $yy, (string)$c['artikelnummer'], 8, false, $muted); $yy += $mm(5); }
    $yy += $mm(1.5);

    $feld = function (string $l, string $v) use ($pdf, $lx, &$yy, $muted, $dark, $mm, $tw): void {
        if ($v === '') return;
        $pdf->text($lx, $yy, $l, 7, false, $muted);
        $pdf->text($lx, $yy + $mm(3.6), $pdf->fit($v, $tw, 11, true), 11, true, $dark);
        $yy += $mm(9.2);
    };
    $feld('Charge', (string)($c['charge_nr'] ?: '–'));
    $feld('Menge', menge_txt($c['menge_verfuegbar']) . ' ' . (string)$c['einheit']);
    $feld('MHD', $c['mhd'] ? date('d.m.Y', strtotime((string)$c['mhd'])) : '–');
}
