<?php
// Karton-Etikett als PDF – zentrale Erzeugung, damit die Etikett-Seite (etikett.php) UND die
// Druck-Brücke (public/lager/bruecke.php) dieselben Etiketten bauen.
//
// lg_etikett_pdf(array $ids, string $format, int $override=0): ?string  -> PDF-Bytes oder null.
//   $format: 'klein' = 100x70 quer, 'gross' = 100x150 hoch.
//   $override: Kartonzahl erzwingen (sonst je Charge lg_pakete()).
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/../../core/lib/minipdf.php';

// Zusatzzeile fuers Etikett: Warenart · Rezepturnummer · Kapselgröße (nur was zutrifft).
function lg_etikett_info(array $c): string {
    $teile = [];
    if (function_exists('erp_kategorie_label')) { $wa = erp_kategorie_label($c); if ($wa !== '') $teile[] = $wa; }
    $iid = (int)($c['item_id'] ?? 0); $aid = (int)($c['auftrag_id'] ?? 0);
    if (function_exists('erp_rezeptur_nr'))      { $rz = erp_rezeptur_nr($iid, $aid); if ($rz !== '') $teile[] = $rz; }
    if (function_exists('erp_kapselgroesse_label')) { $kg = erp_kapselgroesse_label($iid, $aid); if ($kg !== '') $teile[] = $kg; }
    return implode(' · ', $teile);
}

function lg_etikett_pdf(array $ids, string $format = 'klein', int $override = 0): ?string {
    $format = $format === 'gross' ? 'gross' : 'klein';
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0)));
    if (!$ids) return null;

    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'app.bulkify.pro');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    $mm = fn(float $v): float => $v / 25.4 * 72;
    $pdf = new MiniPDF();
    $pdf->w = $mm(100);
    $pdf->h = $format === 'gross' ? $mm(150) : $mm(70);

    $erste = true;
    foreach ($ids as $cid) {
        $c = erp_charge_voll($cid);
        if (!$c) continue;
        $n = $override ?: lg_pakete($cid);
        $split = $n > 1 && function_exists('lg_aufteilen') && lg_aufteilen($cid);
        $total = (float)($c['menge_verfuegbar'] ?? 0);
        // Bei Aufteilung: gleiche Basismenge je Karton, der LETZTE bekommt den Rest (Summe = Gesamt).
        $basis = $split ? floor(($total / $n) * 1000) / 1000 : 0.0;
        $url = $scheme . '://' . $host . '/lager/?p=charge&id=' . $cid;
        for ($k = 1; $k <= $n; $k++) {
            if (!$erste) $pdf->addPage();
            $erste = false;
            if ($split) {
                $c['menge_anzeige'] = ($k < $n) ? $basis : ($total - $basis * ($n - 1));
                $c['menge_label']   = 'Menge/Karton';
            } else {
                $c['menge_anzeige'] = $total;
                $c['menge_label']   = 'Menge';
            }
            $format === 'gross'
                ? lg_karton_etikett_hoch($pdf, $mm, $c, $url, $k, $n)
                : lg_karton_etikett($pdf, $mm, $c, $url, $k, $n);
        }
    }
    if ($erste) return null;
    return $pdf->output();
}

// Kleines Karton-Etikett 100 x 70 mm (quer). QR rechts, Textblock links.
function lg_karton_etikett(MiniPDF $pdf, callable $mm, array $c, string $url, int $karton, int $gesamt): void {
    $W = $pdf->w; $H = $pdf->h;
    $dark = [20, 20, 20]; $muted = [120, 120, 120]; $line = [205, 205, 205];
    $pdf->rectStroke($mm(1.5), $mm(1.5), $W - $mm(3), $H - $mm(3), 0.6, $line);

    $qrArea = $mm(37);
    $qx = $W - $mm(4) - $qrArea; $qy = $mm(4);
    $m = qr_matrix($url);
    if ($m) {
        $n = count($m); $quiet = 2; $mod = $qrArea / ($n + 2 * $quiet);
        $pdf->rect($qx, $qy, $qrArea, $qrArea, [255, 255, 255]);
        for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++) {
            if ($m[$y][$x]) $pdf->rect($qx + ($x + $quiet) * $mod, $qy + ($y + $quiet) * $mod, $mod + 0.25, $mod + 0.25, $dark);
        }
    }
    $pdf->textCenter($qx + $qrArea / 2, $qy + $qrArea + $mm(6), 'Karton ' . $karton . ' / ' . $gesamt, 11, true, $dark);

    $lx = $mm(4); $tw = $qx - $lx - $mm(3);
    $pdf->text($lx, $mm(7), 'bulkify · Wareneingang', 7, false, $muted);

    $yy = $mm(13.5);
    $name = (string)($c['item_name'] ?? '');
    $nl = $pdf->wrap($name, $tw, 12, true);
    $zeilen = array_slice($nl, 0, 2);
    if (count($nl) > 2) $zeilen[1] = $pdf->fit($zeilen[1] . ' ' . $nl[2], $tw, 12, true);
    foreach ($zeilen as $ln) { $pdf->text($lx, $yy, $ln, 12, true, $dark); $yy += $mm(5.2); }
    if (!empty($c['artikelnummer'])) { $pdf->text($lx, $yy, (string)$c['artikelnummer'], 7.5, false, $muted); $yy += $mm(4.6); }
    $info = lg_etikett_info($c);
    if ($info !== '') { $pdf->text($lx, $yy, $pdf->fit($info, $tw, 7.5, false), 7.5, false, $muted); $yy += $mm(4.6); }
    $yy += $mm(1);

    $feld = function (string $l, string $v) use ($pdf, $lx, &$yy, $muted, $dark, $mm, $tw): void {
        $pdf->text($lx, $yy, $l, 7, false, $muted);
        $pdf->text($lx, $yy + $mm(3.4), $pdf->fit($v !== '' ? $v : '–', $tw, 10.5, true), 10.5, true, $dark);
        $yy += $mm(8.4);
    };
    $midx = $lx + $tw / 2;
    $lieferantTxt = ((string)($c['lieferant_nr'] ?? '') !== '') ? (string)$c['lieferant_nr'] : (string)($c['lieferant'] ?? '');
    $eingangTxt   = !empty($c['wareneingang']) ? date('d.m.Y', strtotime((string)$c['wareneingang'])) : '–';
    $halb = function (string $l1, string $v1, string $l2, string $v2) use ($pdf, $lx, $midx, &$yy, $muted, $dark, $mm, $tw): void {
        $hw = $tw / 2 - $mm(2);
        $pdf->text($lx, $yy, $l1, 7, false, $muted);
        $pdf->text($lx, $yy + $mm(3.4), $pdf->fit($v1 !== '' ? $v1 : '–', $hw, 10.5, true), 10.5, true, $dark);
        $pdf->text($midx, $yy, $l2, 7, false, $muted);
        $pdf->text($midx, $yy + $mm(3.4), $pdf->fit($v2 !== '' ? $v2 : '–', $hw, 10.5, true), 10.5, true, $dark);
        $yy += $mm(8.4);
    };
    $halb('Lieferant', $lieferantTxt, 'Eingang', $eingangTxt);
    $feld('Charge (Lieferant)', (string)($c['charge_nr'] ?? ''));
    $halb('MHD', $c['mhd'] ? date('d.m.Y', strtotime((string)$c['mhd'])) : '–',
          (string)($c['menge_label'] ?? 'Menge'), menge_txt($c['menge_anzeige'] ?? $c['menge_verfuegbar']) . ' ' . (string)$c['einheit']);
}

// Großes Karton-Etikett 100 x 150 mm (hoch) für Etikettendrucker-Rollen.
function lg_karton_etikett_hoch(MiniPDF $pdf, callable $mm, array $c, string $url, int $karton, int $gesamt): void {
    $W = $pdf->w; $dark = [20, 20, 20]; $muted = [120, 120, 120]; $line = [205, 205, 205];
    $pdf->rectStroke($mm(2), $mm(2), $W - $mm(4), $pdf->h - $mm(4), 0.6, $line);
    $pdf->text($mm(6), $mm(9), 'bulkify · Wareneingang', 9, false, $muted);

    $qrArea = $mm(52); $qx = ($W - $qrArea) / 2; $qy = $mm(12);
    $m = qr_matrix($url);
    if ($m) {
        $n = count($m); $quiet = 2; $mod = $qrArea / ($n + 2 * $quiet);
        $pdf->rect($qx, $qy, $qrArea, $qrArea, [255, 255, 255]);
        for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++) {
            if ($m[$y][$x]) $pdf->rect($qx + ($x + $quiet) * $mod, $qy + ($y + $quiet) * $mod, $mod + 0.3, $mod + 0.3, $dark);
        }
    }
    $pdf->textCenter($W / 2, $qy + $qrArea + $mm(9), 'Karton ' . $karton . ' / ' . $gesamt, 14, true, $dark);

    $lx = $mm(6); $tw = $W - $mm(12); $yy = $qy + $qrArea + $mm(17);
    $name = (string)($c['item_name'] ?? '');
    $zeilen = array_slice($pdf->wrap($name, $tw, 15, true), 0, 3);
    foreach ($zeilen as $ln) { $pdf->text($lx, $yy, $ln, 15, true, $dark); $yy += $mm(6.3); }
    if (!empty($c['artikelnummer'])) { $pdf->text($lx, $yy, (string)$c['artikelnummer'], 9, false, $muted); $yy += $mm(5.5); }
    $info = lg_etikett_info($c);
    if ($info !== '') { $pdf->text($lx, $yy, $pdf->fit($info, $tw, 9, false), 9, false, $muted); $yy += $mm(6); }
    $yy += $mm(3);

    $midx = $lx + $tw / 2;
    $lieferantTxt = ((string)($c['lieferant_nr'] ?? '') !== '') ? (string)$c['lieferant_nr'] : (string)($c['lieferant'] ?? '');
    $eingangTxt   = !empty($c['wareneingang']) ? date('d.m.Y', strtotime((string)$c['wareneingang'])) : '–';
    $feld = function (string $l, string $v) use ($pdf, $lx, &$yy, $muted, $dark, $mm, $tw): void {
        $pdf->text($lx, $yy, $l, 8.5, false, $muted);
        $pdf->text($lx, $yy + $mm(4.2), $pdf->fit($v !== '' ? $v : '–', $tw, 13, true), 13, true, $dark);
        $yy += $mm(10.5);
    };
    $halb = function (string $l1, string $v1, string $l2, string $v2) use ($pdf, $lx, $midx, &$yy, $muted, $dark, $mm, $tw): void {
        $hw = $tw / 2 - $mm(3);
        $pdf->text($lx, $yy, $l1, 8.5, false, $muted);
        $pdf->text($lx, $yy + $mm(4.2), $pdf->fit($v1 !== '' ? $v1 : '–', $hw, 13, true), 13, true, $dark);
        $pdf->text($midx, $yy, $l2, 8.5, false, $muted);
        $pdf->text($midx, $yy + $mm(4.2), $pdf->fit($v2 !== '' ? $v2 : '–', $hw, 13, true), 13, true, $dark);
        $yy += $mm(10.5);
    };
    $halb('Lieferant', $lieferantTxt, 'Eingang', $eingangTxt);
    $feld('Charge (Lieferant)', (string)($c['charge_nr'] ?? ''));
    $halb('MHD', $c['mhd'] ? date('d.m.Y', strtotime((string)$c['mhd'])) : '–',
          (string)($c['menge_label'] ?? 'Menge'), menge_txt($c['menge_anzeige'] ?? $c['menge_verfuegbar']) . ' ' . (string)$c['einheit']);
}
