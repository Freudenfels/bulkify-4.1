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

    $host   = (function_exists('bx_intern_host') ? bx_intern_host() : (string)($_SERVER['HTTP_HOST'] ?? 'app.bulkify.pro'));
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
        // Teil 4: Gebinde-Datensätze vorhanden? Dann je Gebinde ein Etikett mit EIGENEM QR
        // (?p=gebinde&bg=<id> → genau dieses Gebinde). „Karton X / N" bleibt. Sonst der Alt-Weg unten.
        $gebinde = function_exists('erp_charge_gebinde_liste') ? erp_charge_gebinde_liste($cid) : [];
        if ($gebinde) {
            $ng = count($gebinde);
            foreach ($gebinde as $gi => $g) {
                if (!$erste) $pdf->addPage();
                $erste = false;
                // Gewicht & Bestand pro Gebinde: je Karton sein echtes (Rest-)Gewicht bzw. seine Menge.
                $c['menge_anzeige'] = (float)($g['menge_verfuegbar'] ?? ($g['menge'] ?? 0));
                $c['menge_label']   = preg_match('/^(kg|g|mg|l|ml)$/i', trim((string)($g['einheit'] ?: ($c['einheit'] ?? '')))) ? 'Gewicht/Karton' : 'Menge/Karton';
                $c['inventar_nr']   = (string)($g['inventar_nr'] ?? '');   // Etikettenrolle: 8-stellige Nummer (Altdaten 7) auf den Aufkleber (S-20)
                if (!empty($g['einheit'])) $c['einheit'] = $g['einheit'];
                $gurl = $scheme . '://' . $host . '/lager/?p=gebinde&bg=' . (int)$g['id'];
                $knr  = (int)($g['nummer'] ?? ($gi + 1));
                $format === 'gross'
                    ? lg_karton_etikett_hoch($pdf, $mm, $c, $gurl, $knr, $ng)
                    : lg_karton_etikett($pdf, $mm, $c, $gurl, $knr, $ng);
            }
            continue;
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

// === Code 128 (Teilversion B) für die Inventarnummer der Etikettenrollen =====================
// Die 7-stellige Inventarnummer muss auf den Rollen-Aufkleber – menschenlesbar UND scanbar (Scanner im Lager/Werk
// liest sie wie den aufgeklebten Maniso-Inventaraufkleber; automatisch vergebene Nummern 9000000… gibt es sonst nur
// im System, nie auf der Rolle). Muster: je Symbol 6 Elemente (Strich, Lücke, …) in Modulbreiten; Stopp 7 Elemente.
function lg_code128b_module(string $text): array {
    static $P = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231',
        '113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123',
        '212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321',
        '133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111',
        '111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112',
        '134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141',
        '114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];
    $codes = [104];                                           // Start B
    foreach (str_split($text) as $ch) { $v = ord($ch) - 32; $codes[] = ($v >= 0 && $v <= 95) ? $v : 0; }
    $sum = 104; foreach (array_slice($codes, 1) as $i => $v) $sum += ($i + 1) * $v;
    $codes[] = $sum % 103;                                    // Prüfsumme
    $codes[] = 106;                                           // Stopp
    $mods = [];
    foreach ($codes as $cd) foreach (str_split($P[$cd]) as $w) $mods[] = (int)$w;
    return $mods;
}
// Barcode zeichnen: Breite $w (inkl. je 10 Modulen Ruhezone), Höhe $h. Striche an geraden Stellen (0,2,4,…).
function lg_barcode128(MiniPDF $pdf, float $x, float $y, float $w, float $h, string $text, array $color): void {
    $mods = lg_code128b_module($text);
    $total = array_sum($mods) + 20;                           // 10 Module Ruhezone links + rechts
    $mw = $w / $total; $cx = $x + 10 * $mw;
    foreach ($mods as $i => $bw) {
        if ($i % 2 === 0) $pdf->rect($cx, $y, $bw * $mw + 0.12, $h, $color);
        $cx += $bw * $mw;
    }
}
// Inventar-Block (Etikettenrolle): Beschriftung, große Nummer, scanbarer Barcode – in die Box $x/$y/$w (oben links).
// Höhe des Blocks ca. 14 + $barH mm. Abstände in mm: Beschriftung (Grundlinie +3), Nummer (+$numBase), Barcode darunter.
function lg_inventar_block(MiniPDF $pdf, callable $mm, string $inv, float $x, float $y, float $w, float $numSize = 20.0, float $barH = 11.0, float $numBase = 11.5): void {
    $dark = [20, 20, 20]; $muted = [120, 120, 120];
    $pdf->text($x, $y + $mm(3), 'Inventar-Nr.', 8, false, $muted);
    $pdf->text($x, $y + $mm($numBase), $inv, $numSize, true, $dark);
    lg_barcode128($pdf, $x - $mm(1), $y + $mm($numBase + 2.5), $w + $mm(2), $mm($barH), $inv, $dark);
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
    // Etikettenrolle: 7-stellige Inventarnummer groß + scanbarer Barcode unter dem QR (rechte Spalte).
    $invK = trim((string)($c['inventar_nr'] ?? ''));
    if (preg_match('/^\d{7,8}$/', $invK)) {
        $pdf->textCenter($qx + $qrArea / 2, $qy + $qrArea + $mm(10.5), 'Inventar-Nr.', 6.5, false, $muted);
        $pdf->textCenter($qx + $qrArea / 2, $qy + $qrArea + $mm(15.5), $invK, 12, true, $dark);
        lg_barcode128($pdf, $qx - $mm(1), $qy + $qrArea + $mm(17.5), $qrArea + $mm(2), $mm(7), $invK, $dark);
    }

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

    // Etikettenrolle (7-stellige Inventarnummer): QR kleiner links, daneben Inventar-Block (Nummer groß + scanbarer
    // Barcode) und „Karton X / N". Ohne Inventarnummer bleibt das Layout wie bisher.
    $inv = trim((string)($c['inventar_nr'] ?? ''));
    $mitInv = (bool) preg_match('/^\d{7,8}$/', $inv);
    $qrArea = $mm($mitInv ? 36 : 52); $qx = $mitInv ? $mm(6) : ($W - $qrArea) / 2; $qy = $mm(12);
    $m = qr_matrix($url);
    if ($m) {
        $n = count($m); $quiet = 2; $mod = $qrArea / ($n + 2 * $quiet);
        $pdf->rect($qx, $qy, $qrArea, $qrArea, [255, 255, 255]);
        for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++) {
            if ($m[$y][$x]) $pdf->rect($qx + ($x + $quiet) * $mod, $qy + ($y + $quiet) * $mod, $mod + 0.3, $mod + 0.3, $dark);
        }
    }
    if ($mitInv) {
        $bx = $qx + $qrArea + $mm(5); $bw = $W - $mm(6) - $bx;
        lg_inventar_block($pdf, $mm, $inv, $bx, $qy, $bw, 19.0, 11.0, 11.0);
        $pdf->text($bx, $qy + $qrArea - $mm(1), 'Karton ' . $karton . ' / ' . $gesamt, 14, true, $dark);
        $yy0 = $qy + $qrArea + $mm(12);
    } else {
        $pdf->textCenter($W / 2, $qy + $qrArea + $mm(9), 'Karton ' . $karton . ' / ' . $gesamt, 14, true, $dark);
        $yy0 = $qy + $qrArea + $mm(17);
    }

    $lx = $mm(6); $tw = $W - $mm(12); $yy = $yy0;
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

    $host   = (function_exists('bx_intern_host') ? bx_intern_host() : (string)($_SERVER['HTTP_HOST'] ?? 'app.bulkify.pro'));
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

// === Kartonetiketten der Produktion (Soll-Kette 6) ===========================================
// 100 x 150 mm hoch, je Karton eine Seite: Produkt, Charge, MHD, Stückzahl im Karton, „Karton 5 von 40“, Auftrag/Kunde, Barcode.
// Barcode (Code 128) = Charge-Kartonnummer, z. B. „2701.A-K05“. Der letzte Karton trägt den Rest.
// Ein Karton aus der Produktion (pa_karton): Karton-Nummer (fortlaufend je PA, ohne „von n"), Menge in diesem Karton,
// Unter-Charge, MHD, Produkt, Kunde/Auftrag, Barcode <Charge>-K001 (Grundlage fürs Palettieren per Scan). 100 x 150 mm.
function lg_pa_karton_pdf(int $karton_id): ?string {
    $d = function_exists('erp_pa_karton_daten') ? erp_pa_karton_daten($karton_id) : null;
    if (!$d) return null;
    $mm = fn(float $v): float => $v / 25.4 * 72;
    $pdf = new MiniPDF(); $pdf->w = $mm(100); $pdf->h = $mm(150);
    $W = $pdf->w; $dark = [20, 20, 20]; $muted = [120, 120, 120]; $line = [205, 205, 205];
    $lx = $mm(6); $tw = $W - $mm(12);
    $pdf->rectStroke($mm(2), $mm(2), $W - $mm(4), $pdf->h - $mm(4), 0.6, $line);
    $pdf->text($lx, $mm(10), 'KARTON', 10, true, $muted);
    $pdf->text($lx, $mm(24), 'Karton ' . (int)$d['nummer'], 26, true, $dark);
    $yy = $mm(36);
    foreach (array_slice($pdf->wrap((string)($d['produkt'] ?: '–'), $tw, 15, true), 0, 3) as $ln) { $pdf->text($lx, $yy, $ln, 15, true, $dark); $yy += $mm(6.6); }
    $yy += $mm(3);
    $feld = function (string $l, string $v, float $size = 15) use ($pdf, $lx, &$yy, $muted, $dark, $mm, $tw): void {
        $pdf->text($lx, $yy, $l, 8, false, $muted); $off = $size * 0.85 + 2;
        $pdf->text($lx, $yy + $off, $pdf->fit($v !== '' ? $v : '–', $tw, $size, true), $size, true, $dark); $yy += $off + $mm(6.5);
    };
    $feld('Menge in diesem Karton', number_format((float)$d['menge'], 0, ',', '.') . ' Pkg', 20);
    $feld('Charge', (string)$d['charge_nr']);
    $feld('MHD', !empty($d['mhd']) ? date('d.m.Y', strtotime((string)$d['mhd'])) : '–');
    $feld('Auftrag', trim((string)($d['auftrag_nr'] ?? '') . ' · ' . (string)$d['pa_nr'], ' ·'));
    $feld('Kunde', (string)($d['kunde'] ?? ''), 12);
    $code = (string)$d['charge_nr'] . '-K' . str_pad((string)(int)$d['nummer'], 3, '0', STR_PAD_LEFT);
    lg_barcode128($pdf, $lx - $mm(1), $pdf->h - $mm(30), $tw + $mm(2), $mm(16), $code, $dark);
    $pdf->text($lx, $pdf->h - $mm(9), $code, 11, true, $dark);
    return $pdf->output();
}
function lg_produktion_karton_pdf(int $pa_id): ?string {
    if (!function_exists('erp_karton_etikett_daten')) return null;
    $d = erp_karton_etikett_daten($pa_id);
    if (!$d) return null;
    $je = (int)$d['karton_stueck']; $menge = (int)$d['karton_menge'];
    $n = (int) ceil($menge / $je);
    $mm = fn(float $v): float => $v / 25.4 * 72;
    $pdf = new MiniPDF();
    $pdf->w = $mm(100); $pdf->h = $mm(150);
    $W = $pdf->w; $dark = [20, 20, 20]; $muted = [120, 120, 120]; $line = [205, 205, 205];
    $charge = (string)($d['karton_charge'] ?? '');
    $mhd = !empty($d['karton_mhd']) ? date('d.m.Y', strtotime((string)$d['karton_mhd'])) : '–';
    for ($i = 1; $i <= $n; $i++) {
        if ($i > 1) $pdf->addPage();
        $imKarton = $i < $n ? $je : $menge - ($n - 1) * $je;
        $lx = $mm(6); $tw = $W - $mm(12);
        $pdf->rectStroke($mm(2), $mm(2), $W - $mm(4), $pdf->h - $mm(4), 0.6, $line);
        $pdf->text($lx, $mm(10), 'KARTON', 10, true, $muted);
        $pdf->text($lx, $mm(24), 'Karton ' . $i . ' von ' . $n, 26, true, $dark);
        $yy = $mm(36);
        foreach (array_slice($pdf->wrap((string)($d['produkt'] ?: '–'), $tw, 15, true), 0, 3) as $ln) { $pdf->text($lx, $yy, $ln, 15, true, $dark); $yy += $mm(6.6); }
        $yy += $mm(3);
        $feld = function (string $l, string $v, float $size = 15) use ($pdf, $lx, &$yy, $muted, $dark, $mm, $tw): void {
            $pdf->text($lx, $yy, $l, 8, false, $muted);
            $off = $size * 0.85 + 2;                          // Grundlinie des Werts unter der Beschriftung, mit der Schriftgröße
            $pdf->text($lx, $yy + $off, $pdf->fit($v !== '' ? $v : '–', $tw, $size, true), $size, true, $dark);
            $yy += $off + $mm(6.5);
        };
        $feld('Stückzahl im Karton', number_format($imKarton, 0, ',', '.') . ' Pkg', 20);
        $feld('Charge', $charge);
        $feld('MHD', $mhd);
        $feld('Auftrag', trim((string)($d['auftrag_nr'] ?? '') . ' · ' . (string)$d['nummer'], ' ·'));
        $feld('Kunde', (string)($d['kunde'] ?? ''), 12);
        $code = ($charge !== '' ? $charge : (string)$d['nummer']) . '-K' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        lg_barcode128($pdf, $lx - $mm(1), $pdf->h - $mm(30), $tw + $mm(2), $mm(16), $code, $dark);
        $pdf->text($lx, $pdf->h - $mm(9), $code, 11, true, $dark);
    }
    return $pdf->output();
}

// === Eimer-/Mischgebinde-Etikett (ein Etikett je Eimer beim Mischen) =========================
// Klein (100x70 quer): QR (= Gebinde-/Chargennummer zum Scannen) rechts, Textblock links.
function lg_mischgebinde_etikett_pdf(int $sub_id): ?string {
    if (!function_exists('erp_mischgebinde_etikett_daten')) return null;
    $d = erp_mischgebinde_etikett_daten($sub_id);
    if (!$d) return null;

    $nummer = (string)($d['nummer'] ?? '');
    $mm = fn(float $v): float => $v / 25.4 * 72;
    $pdf = new MiniPDF();
    $pdf->w = $mm(100);
    $pdf->h = $mm(70);

    $W = $pdf->w; $dark = [20, 20, 20]; $muted = [120, 120, 120]; $line = [205, 205, 205];
    $pdf->rectStroke($mm(1.5), $mm(1.5), $W - $mm(3), $pdf->h - $mm(3), 0.6, $line);

    // QR = Chargennummer des Gebindes (zum Scannen/Zuordnen).
    $lxRight = $W - $mm(5);
    if ($nummer !== '') {
        $qrArea = $mm(30); $qx = $W - $mm(4) - $qrArea; $qy = $mm(5);
        $m = qr_matrix($nummer);
        if ($m) {
            $n = count($m); $quiet = 2; $mod = $qrArea / ($n + 2 * $quiet);
            $pdf->rect($qx, $qy, $qrArea, $qrArea, [255, 255, 255]);
            for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++)
                if ($m[$y][$x]) $pdf->rect($qx + ($x + $quiet) * $mod, $qy + ($y + $quiet) * $mod, $mod + 0.25, $mod + 0.25, $dark);
        }
        $lxRight = $qx - $mm(3);
    }

    $lx = $mm(5); $tw = $lxRight - $lx;
    $pdf->text($lx, $mm(8), 'MISCHGEBINDE', 11, true, $dark);
    $yy = $mm(16.5);
    // Produkt (bis 2 Zeilen)
    $name = (string)($d['produkt_name'] ?? '');
    foreach (array_slice($pdf->wrap($name !== '' ? $name : '–', $tw, 12, true), 0, 2) as $ln) { $pdf->text($lx, $yy, $ln, 12, true, $dark); $yy += $mm(5.6); }
    $yy += $mm(1.5);

    $feld = function (string $l, string $v) use ($pdf, $lx, &$yy, $muted, $dark, $mm, $tw): void {
        $pdf->text($lx, $yy, $l, 7.5, false, $muted);
        $pdf->text($lx, $yy + $mm(3.6), $pdf->fit($v !== '' ? $v : '–', $tw, 11.5, true), 11.5, true, $dark);
        $yy += $mm(9);
    };
    // „Gebinde X/Y (… kg)" – prominent.
    $feld('Gebinde', (string)($d['gebinde'] ?? '–'));
    $feld('Mischcharge', $nummer);

    $menge = $d['menge'] !== null ? rtrim(rtrim(number_format((float)$d['menge'], 3, ',', '.'), '0'), ',') . ' ' . (string)($d['einheit'] ?: 'kg') : '–';
    $dat   = !empty($d['tag']) ? date('d.m.Y', strtotime((string)$d['tag'])) : date('d.m.Y');
    $midx  = $lx + $tw / 2;
    $pdf->text($lx, $yy, 'Menge', 7.5, false, $muted);
    $pdf->text($lx, $yy + $mm(3.6), $pdf->fit($menge, $tw / 2 - $mm(2), 11.5, true), 11.5, true, $dark);
    $pdf->text($midx, $yy, 'Datum', 7.5, false, $muted);
    $pdf->text($midx, $yy + $mm(3.6), $dat, 11.5, true, $dark);
    $yy += $mm(9);
    if (!empty($d['pa_nummer'])) $pdf->text($lx, $yy, 'Auftrag ' . (string)$d['pa_nummer'], 8, false, $muted);

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

    $host   = (function_exists('bx_intern_host') ? bx_intern_host() : (string)($_SERVER['HTTP_HOST'] ?? 'app.bulkify.pro'));
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
