<?php
// Karton-Etikett als PDF – zentrale Erzeugung, damit die Etikett-Seite (etikett.php) UND die
// Druck-Brücke (public/lager/bruecke.php) dieselben Etiketten bauen.
//
// lg_etikett_pdf(array $ids, string $format, int $override=0): ?string  -> PDF-Bytes oder null.
//   $format: 'klein' = 100x70 quer, 'gross' = 100x150 hoch.
//   $override: Kartonzahl erzwingen (sonst je Charge lg_pakete()).
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/../../core/lib/minipdf.php';

// Lieferant fuers Etikett: NUR die Lieferantennummer, NIE der Name (Regel Nico).
// Ist keine Nummer hinterlegt, bleibt das Feld leer (Etikett zeigt dann "–").
function lg_lieferant_txt(array $c): string {
    return trim((string)($c['lieferant_nr'] ?? ''));
}

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
        // Blinker/Ort fuers Etikett (Kiste = Mischpalette, sonst Leisten-Code).
        $c['blinker_code'] = '';
        if (function_exists('blinker_fuer_charge')) {
            $bf = blinker_fuer_charge($cid);
            if (!empty($bf['kiste'])) $c['blinker_code'] = 'Kiste ' . (string)($bf['kiste']['kiste_name'] ?? '');
            elseif (!empty($bf['leiste'])) $c['blinker_code'] = (string)($bf['leiste']['code'] ?? '');
        }
        $split = $n > 1 && function_exists('lg_aufteilen') && lg_aufteilen($cid);
        $total = (float)($c['menge_verfuegbar'] ?? 0);
        // Stück/Tabletten/Kapseln sind ganzzahlig -> ganze Stück je Karton (keine Nachkommastellen).
        $istStueck = (bool) preg_match('/st(ü|u)?ck|^stk|tabl|kaps/i', (string)($c['einheit'] ?? ''));
        // Bei Aufteilung: gleiche Basismenge je Karton, der LETZTE bekommt den Rest (Summe = Gesamt).
        $basis = $split ? ($istStueck ? floor($total / $n) : floor(($total / $n) * 1000) / 1000) : 0.0;
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
    $lieferantTxt = lg_lieferant_txt($c);
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
    $halb('Charge (Lieferant)', (string)($c['charge_nr'] ?? ''), 'Blinker / Ort', (string)($c['blinker_code'] ?? ''));
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
    $lieferantTxt = lg_lieferant_txt($c);
    $eingangTxt   = !empty($c['wareneingang']) ? date('d.m.Y', strtotime((string)$c['wareneingang'])) : '–';
    $feld = function (string $l, string $v) use ($pdf, $lx, &$yy, $muted, $dark, $mm, $tw): void {
        $pdf->text($lx, $yy, $l, 8.5, false, $muted);
        $pdf->text($lx, $yy + $mm(4.2), $pdf->fit($v !== '' ? $v : '–', $tw, 13, true), 13, true, $dark);
        $yy += $mm(10.5);
    };
    // Volle Breite, aber Schrift schrumpft bis die GANZE Zahl passt (z. B. Chargennummer – nie abgeschnitten).
    $feldAuto = function (string $l, string $v) use ($pdf, $lx, &$yy, $muted, $dark, $mm, $tw): void {
        $v = $v !== '' ? $v : '–';
        $size = 13.0;
        while ($size > 7.5 && $pdf->strwidth($v, $size, true) > $tw) $size -= 0.5;
        $pdf->text($lx, $yy, $l, 8.5, false, $muted);
        $pdf->text($lx, $yy + $mm(4.2), $v, $size, true, $dark);
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
    $feldAuto('Lieferant', $lieferantTxt);                              // volle Breite -> Name · Nummer immer komplett
    $feldAuto('Charge (Lieferant)', (string)($c['charge_nr'] ?? ''));   // volle Breite, nie abgeschnitten
    $halb('MHD', $c['mhd'] ? date('d.m.Y', strtotime((string)$c['mhd'])) : '–',
          (string)($c['menge_label'] ?? 'Menge'), menge_txt($c['menge_anzeige'] ?? $c['menge_verfuegbar']) . ' ' . (string)$c['einheit']);
    $halb('Eingang', $eingangTxt, 'Blinker / Ort', (string)($c['blinker_code'] ?? ''));
}

// === Proben-Etikett (Rückstellmuster / Chargenprobe) =========================================
// Klein (100x70 quer): QR rechts (führt auf die Charge), Textblock links. Daten aus prod_probe
// über die Lager-Naht erp_probe_etikett_daten(). Wird lautlos über die Druck-Brücke gedruckt.
function lg_probe_etikett_pdf(int $probe_id): ?string {
    if (!function_exists('erp_probe_etikett_daten')) return null;
    $p = erp_probe_etikett_daten($probe_id);
    if (!$p) return null;

    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'app.bulkify.pro');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $cid    = (int)($p['charge_id'] ?? 0);
    $url    = $cid > 0 ? $scheme . '://' . $host . '/lager/?p=charge&id=' . $cid : '';

    $mm = fn(float $v): float => $v / 25.4 * 72;
    $pdf = new MiniPDF();
    $pdf->w = $mm(100);
    $pdf->h = $mm(70);

    $W = $pdf->w; $H = $pdf->h;
    $dark = [20, 20, 20]; $muted = [120, 120, 120]; $line = [205, 205, 205];
    $pdf->rectStroke($mm(1.5), $mm(1.5), $W - $mm(3), $H - $mm(3), 0.6, $line);

    // QR rechts (falls Charge bekannt) – führt auf die Charge im Lager.
    $lxRight = $W - $mm(5);
    if ($url !== '') {
        $qrArea = $mm(30); $qx = $W - $mm(4) - $qrArea; $qy = $mm(5);
        $m = qr_matrix($url);
        if ($m) {
            $n = count($m); $quiet = 2; $mod = $qrArea / ($n + 2 * $quiet);
            $pdf->rect($qx, $qy, $qrArea, $qrArea, [255, 255, 255]);
            for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++)
                if ($m[$y][$x]) $pdf->rect($qx + ($x + $quiet) * $mod, $qy + ($y + $quiet) * $mod, $mod + 0.25, $mod + 0.25, $dark);
        }
        $lxRight = $qx - $mm(3);
    }

    $lx = $mm(5); $tw = $lxRight - $lx;
    $pdf->text($lx, $mm(8), 'RÜCKSTELLMUSTER · PROBE', 11, true, $dark);
    $yy = $mm(16.5);
    $name = (string)($p['item_name'] ?? '');
    foreach (array_slice($pdf->wrap($name !== '' ? $name : '–', $tw, 13, true), 0, 2) as $ln) { $pdf->text($lx, $yy, $ln, 13, true, $dark); $yy += $mm(6); }
    $yy += $mm(1.5);

    $feld = function (string $l, string $v) use ($pdf, $lx, &$yy, $muted, $dark, $mm, $tw): void {
        $pdf->text($lx, $yy, $l, 7.5, false, $muted);
        $pdf->text($lx, $yy + $mm(3.6), $pdf->fit($v !== '' ? $v : '–', $tw, 11, true), 11, true, $dark);
        $yy += $mm(9);
    };
    $charge = (string)($p['batch_nr'] ?? '') !== '' ? (string)$p['batch_nr'] : (string)($p['charge_nr'] ?? '');
    $feld('Charge', $charge);
    $feld('Produktionsauftrag', (string)($p['pa_nummer'] ?? '–'));

    $dat = !empty($p['angelegt']) ? date('d.m.Y', strtotime((string)$p['angelegt'])) : date('d.m.Y');
    $von = (string)($p['erfasst_von'] ?? '');
    $midx = $lx + $tw / 2;
    $pdf->text($lx, $yy, 'Datum', 7.5, false, $muted);
    $pdf->text($lx, $yy + $mm(3.6), $dat, 11, true, $dark);
    $pdf->text($midx, $yy, 'Mitarbeiter', 7.5, false, $muted);
    $pdf->text($midx, $yy + $mm(3.6), $pdf->fit($von !== '' ? $von : '–', $tw / 2 - $mm(2), 11, true), 11, true, $dark);

    return $pdf->output();
}

// === Gebinde-/Karton-Aufkleber (Spec 5.6) ====================================================
// Je Gebinde EIN Aufkleber mit EIGENEM QR + EIGENER Nummer (GB-...). Der QR fuehrt auf die Scan-
// Aufloesung im Lager (?p=gebinde&nr=GB-...), ueber die sich Produkt/Wareneingang/Lieferant finden
// lassen (Regress). Format: 'klein' = 100x70 quer, 'gross' = 100x150 hoch.
function lg_gebinde_etikett_pdf(int $charge_id, string $format = 'gross'): ?string {
    if (!function_exists('lg_gebinde_liste')) return null;
    $geb = lg_gebinde_liste($charge_id);
    if (!$geb) return null;
    $c = erp_charge_voll($charge_id);
    if (!$c) return null;

    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'app.bulkify.pro');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $format = $format === 'klein' ? 'klein' : 'gross';
    $mm = fn(float $v): float => $v / 25.4 * 72;
    $pdf = new MiniPDF();
    $pdf->w = $mm(100);
    $pdf->h = $format === 'klein' ? $mm(70) : $mm(150);

    $gesamt = count($geb);
    $erste = true;
    foreach ($geb as $g) {
        if (!$erste) $pdf->addPage();
        $erste = false;
        $url = $scheme . '://' . $host . '/lager/?p=gebinde&nr=' . rawurlencode((string)$g['nummer']);
        $format === 'klein'
            ? lg_gebinde_label($pdf, $mm, $c, $g, $url, (int)$g['laufnr'], $gesamt)
            : lg_gebinde_label_hoch($pdf, $mm, $c, $g, $url, (int)$g['laufnr'], $gesamt);
    }
    return $pdf->output();
}

function lg_gebinde_label(MiniPDF $pdf, callable $mm, array $c, array $g, string $url, int $nr, int $gesamt): void {
    $W = $pdf->w; $H = $pdf->h;
    $dark = [20, 20, 20]; $muted = [120, 120, 120]; $line = [205, 205, 205];
    $pdf->rectStroke($mm(1.5), $mm(1.5), $W - $mm(3), $H - $mm(3), 0.6, $line);

    $qrArea = $mm(37); $qx = $W - $mm(4) - $qrArea; $qy = $mm(4);
    $m = qr_matrix($url);
    if ($m) {
        $n = count($m); $quiet = 2; $mod = $qrArea / ($n + 2 * $quiet);
        $pdf->rect($qx, $qy, $qrArea, $qrArea, [255, 255, 255]);
        for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++)
            if ($m[$y][$x]) $pdf->rect($qx + ($x + $quiet) * $mod, $qy + ($y + $quiet) * $mod, $mod + 0.25, $mod + 0.25, $dark);
    }
    $pdf->textCenter($qx + $qrArea / 2, $qy + $qrArea + $mm(6), 'Gebinde ' . $nr . ' / ' . $gesamt, 11, true, $dark);

    $lx = $mm(4); $tw = $qx - $lx - $mm(3);
    $pdf->text($lx, $mm(7), 'bulkify · Gebinde-Nr.', 7, false, $muted);
    $pdf->text($lx, $mm(14), $pdf->fit((string)$g['nummer'], $tw, 15, true), 15, true, $dark);

    $yy = $mm(22);
    $name = (string)($c['item_name'] ?? '');
    foreach (array_slice($pdf->wrap($name, $tw, 11, true), 0, 2) as $ln) { $pdf->text($lx, $yy, $ln, 11, true, $dark); $yy += $mm(5); }
    $yy += $mm(1);
    $feld = function (string $l, string $v) use ($pdf, $lx, &$yy, $muted, $dark, $mm, $tw): void {
        $pdf->text($lx, $yy, $l, 7, false, $muted);
        $pdf->text($lx, $yy + $mm(3.4), $pdf->fit($v !== '' ? $v : '–', $tw, 10, true), 10, true, $dark);
        $yy += $mm(8.2);
    };
    $feld('Lieferant', lg_lieferant_txt($c));
    $feld('Eingang', !empty($c['wareneingang']) ? date('d.m.Y', strtotime((string)$c['wareneingang'])) : '–');
}

function lg_gebinde_label_hoch(MiniPDF $pdf, callable $mm, array $c, array $g, string $url, int $nr, int $gesamt): void {
    $W = $pdf->w; $dark = [20, 20, 20]; $muted = [120, 120, 120]; $line = [205, 205, 205];
    $pdf->rectStroke($mm(2), $mm(2), $W - $mm(4), $pdf->h - $mm(4), 0.6, $line);
    $pdf->text($mm(6), $mm(9), 'bulkify · Gebinde', 9, false, $muted);

    $qrArea = $mm(52); $qx = ($W - $qrArea) / 2; $qy = $mm(12);
    $m = qr_matrix($url);
    if ($m) {
        $n = count($m); $quiet = 2; $mod = $qrArea / ($n + 2 * $quiet);
        $pdf->rect($qx, $qy, $qrArea, $qrArea, [255, 255, 255]);
        for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++)
            if ($m[$y][$x]) $pdf->rect($qx + ($x + $quiet) * $mod, $qy + ($y + $quiet) * $mod, $mod + 0.3, $mod + 0.3, $dark);
    }
    $pdf->textCenter($W / 2, $qy + $qrArea + $mm(9), (string)$g['nummer'], 16, true, $dark);
    $pdf->textCenter($W / 2, $qy + $qrArea + $mm(15), 'Gebinde ' . $nr . ' / ' . $gesamt, 11, false, $muted);

    $lx = $mm(6); $tw = $W - $mm(12); $yy = $qy + $qrArea + $mm(24);
    $name = (string)($c['item_name'] ?? '');
    foreach (array_slice($pdf->wrap($name, $tw, 14, true), 0, 3) as $ln) { $pdf->text($lx, $yy, $ln, 14, true, $dark); $yy += $mm(6); }
    $yy += $mm(2);
    $feld = function (string $l, string $v) use ($pdf, $lx, &$yy, $muted, $dark, $mm, $tw): void {
        $pdf->text($lx, $yy, $l, 8.5, false, $muted);
        $pdf->text($lx, $yy + $mm(4.2), $pdf->fit($v !== '' ? $v : '–', $tw, 12, true), 12, true, $dark);
        $yy += $mm(10);
    };
    $feld('Lieferant', lg_lieferant_txt($c));
    $feld('Charge (Lieferant)', (string)($c['charge_nr'] ?? ''));
    $feld('Eingang', !empty($c['wareneingang']) ? date('d.m.Y', strtotime((string)$c['wareneingang'])) : '–');
}
