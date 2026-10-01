<?php
// Karton-Etikett (Wareneingang) als PDF, 100 mm breit x 70 mm hoch – kommt auf die Kartons.
// Inhalt: Name (Rohstoff/Rezeptur), Lieferant, Lieferanten-Charge, MHD, Menge, Anzahl Pakete
// (eine Seite je Karton: „Karton X / N") und ein QR-Code zur Charge im Lager.
//
// Einzeln:  ?p=etikett&id=<charge_id>            (Anzahl Kartons = beim Wareneingang erfasst)
// Stapel:   ?p=etikett&ids=1,2,3                 (je Charge deren Kartonzahl)
// Override: &pakete=<n>                          (Kartonzahl erzwingen, z. B. zum Nachdrucken)
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
$override = isset($_GET['pakete']) ? max(1, (int)$_GET['pakete']) : 0;

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
    $n = $override ?: lg_pakete($cid);
    $url = $scheme . '://' . $host . '/lager/?p=charge&id=' . $cid;
    for ($k = 1; $k <= $n; $k++) {
        if (!$erste) $pdf->addPage();
        $erste = false;
        lg_karton_etikett($pdf, $mm, $c, $url, $k, $n);
    }
}
if ($erste) { http_response_code(404); echo 'Charge nicht gefunden.'; exit; }

$out = $pdf->output();
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="karton-etikett.pdf"');
header('Content-Length: ' . strlen($out));
header('Cache-Control: no-store');
echo $out;
exit;

// Ein Karton-Etikett auf die aktuelle Seite zeichnen.
function lg_karton_etikett(MiniPDF $pdf, callable $mm, array $c, string $url, int $karton, int $gesamt): void {
    $W = $pdf->w; $H = $pdf->h;
    $dark = [20, 20, 20]; $muted = [120, 120, 120]; $line = [205, 205, 205];

    // Rahmen als Schnitt-/Klebehilfe.
    $pdf->rectStroke($mm(1.5), $mm(1.5), $W - $mm(3), $H - $mm(3), 0.6, $line);

    // QR rechts (zur Charge im Lager).
    $qrArea = $mm(37);
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
    // „Karton X / N" prominent unter dem QR.
    $pdf->textCenter($qx + $qrArea / 2, $qy + $qrArea + $mm(6), 'Karton ' . $karton . ' / ' . $gesamt, 11, true, $dark);

    // Textblock links.
    $lx = $mm(4);
    $tw = $qx - $lx - $mm(3);
    $pdf->text($lx, $mm(7), 'bulkify · Wareneingang', 7, false, $muted);

    $yy = $mm(13.5);
    $name = (string)($c['item_name'] ?? '');
    $nl = $pdf->wrap($name, $tw, 12, true);
    $zeilen = array_slice($nl, 0, 2);
    if (count($nl) > 2) $zeilen[1] = $pdf->fit($zeilen[1] . ' ' . $nl[2], $tw, 12, true);
    foreach ($zeilen as $ln) { $pdf->text($lx, $yy, $ln, 12, true, $dark); $yy += $mm(5.2); }
    if (!empty($c['artikelnummer'])) { $pdf->text($lx, $yy, (string)$c['artikelnummer'], 7.5, false, $muted); $yy += $mm(5); }
    $yy += $mm(1);

    // Kompakte Felder (Label grau + Wert fett).
    $feld = function (string $l, string $v) use ($pdf, $lx, &$yy, $muted, $dark, $mm, $tw): void {
        $pdf->text($lx, $yy, $l, 7, false, $muted);
        $pdf->text($lx, $yy + $mm(3.4), $pdf->fit($v !== '' ? $v : '–', $tw, 10.5, true), 10.5, true, $dark);
        $yy += $mm(8.4);
    };
    $feld('Lieferant', (string)($c['lieferant'] ?? ''));
    $feld('Charge (Lieferant)', (string)($c['charge_nr'] ?? ''));
    // MHD + Menge nebeneinander, um Platz zu sparen.
    $pdf->text($lx, $yy, 'MHD', 7, false, $muted);
    $pdf->text($lx, $yy + $mm(3.4), $c['mhd'] ? date('d.m.Y', strtotime((string)$c['mhd'])) : '–', 10.5, true, $dark);
    $midx = $lx + $tw / 2;
    $pdf->text($midx, $yy, 'Menge', 7, false, $muted);
    $pdf->text($midx, $yy + $mm(3.4), menge_txt($c['menge_verfuegbar']) . ' ' . (string)$c['einheit'], 10.5, true, $dark);
}
