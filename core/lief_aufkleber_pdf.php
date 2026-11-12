<?php
// PDF der Lieferanten-Gebindeaufkleber (Paket 12.11.2026 Teil 5). MiniPDF wie bei den Etiketten, Text Englisch,
// Produktname deutsch und kundenneutral. Kein Lieferantenlogo (Entscheidung Nico), Hersteller als Text.
// Formate: 'a4_1' (ein großer Aufkleber je A4-Seite), 'a4_2' (zwei je A4-Seite), '100x150' (Etikettendrucker).
// Keine Bag-Nummer, kein „x of n“ – jeder Aufkleber ist gleichwertig, der Scan zählt.
require_once __DIR__ . '/lib/minipdf.php';
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/lief_aufkleber.php';

function la_pdf_formate(): array {
    return ['a4_1' => 'A4, 1 Aufkleber je Seite', 'a4_2' => 'A4, 2 Aufkleber je Seite', '100x150' => '100 x 150 mm (Etikettendrucker)'];
}
function la_datum_en(string $d): string {
    if ($d === '' || $d === '0000-00-00') return '–';
    $t = strtotime($d);
    return $t ? gmdate('d M Y', $t) : $d;
}

// Einen Aufkleber (Grundmaß 100 x 150 mm) bei ($ox,$oy) in pt mit Faktor $s zeichnen.
function la_aufkleber_zeichnen(MiniPDF $p, float $ox, float $oy, float $s, array $d): void {
    $mm = fn(float $v): float => $v / 25.4 * 72 * $s;
    $X = fn(float $v): float => $ox + $mm($v);
    $Y = fn(float $v): float => $oy + $mm($v);
    $f = fn(float $pt): float => $pt * $s;
    $ink = [20, 20, 20]; $grau = [110, 110, 108]; $linie = [190, 190, 186];
    $p->rectStroke($X(1), $Y(1), $mm(98), $mm(148), 0.6 * $s, $linie);

    $p->text($X(5), $Y(7), 'bulkify · INCOMING GOODS LABEL', $f(6.5), false, $grau);
    // QR rechts oben + Code darunter
    $qr = 33;
    $m = qr_matrix($d['code']);
    if ($m) {
        $n = count($m); $quiet = 2; $mod = $mm($qr) / ($n + 2 * $quiet);
        $p->rect($X(63), $Y(4), $mm($qr), $mm($qr), [255, 255, 255]);
        for ($yy = 0; $yy < $n; $yy++) for ($xx = 0; $xx < $n; $xx++)
            if ($m[$yy][$xx]) $p->rect($X(63) + ($xx + $quiet) * $mod, $Y(4) + ($yy + $quiet) * $mod, $mod + 0.2, $mod + 0.2, $ink);
    }
    $p->textCenter($X(79.5), $Y(40), $d['code'], $f(8), true, $ink);

    // Produktname (deutsch, kundenneutral) – max. 3 Zeilen
    $y = 14;
    foreach (array_slice($p->wrap($d['name'] !== '' ? $d['name'] : '–', $mm(56), $f(12), true), 0, 3) as $zl) { $p->text($X(5), $Y($y), $zl, $f(12), true, $ink); $y += 5.2; }
    $y += 1.5;
    $feld = function (string $label, string $wert, bool $fett = false) use ($p, $X, $Y, $f, $mm, &$y, $ink, $grau) {
        $p->text($X(5), $Y($y), $label, $f(6.5), false, $grau);
        $p->text($X(25), $Y($y), $p->fit($wert, $mm(36), $f($fett ? 10 : 8), $fett), $f($fett ? 10 : 8), $fett, $ink);
        $y += $fett ? 5.2 : 4.3;
    };
    $feld('Batch', $d['charge'] !== '' ? $d['charge'] : '–', true);
    $feld('Production', la_datum_en($d['herstell']));
    $feld('Expiry', la_datum_en($d['mhd']));
    $feld('Net / Gross', ($d['netto'] !== null ? la_num($d['netto']) . ' kg' : '–') . ' / ' . ($d['brutto'] !== null ? la_num($d['brutto']) . ' kg' : '–'));
    if ($d['stueck'] !== null) $feld('Quantity', number_format((float)$d['stueck'], 0, ',', '.') . ' pcs');
    $feld('PO No.', $d['po']);
    $y = max($y, 46) + 1;

    $abschnitt = function (string $titel) use ($p, $X, $Y, $f, $mm, &$y, $linie) {
        $p->line($X(5), $Y($y - 3.2), $X(95), $Y($y - 3.2), 0.4, $linie);
        $p->text($X(5), $Y($y), $titel, $f(7), true, [60, 60, 58]); $y += 3.8;
    };
    $zeilen = function (string $txt, float $pt = 6.8, int $max = 3) use ($p, $X, $Y, $f, $mm, &$y, $ink) {
        foreach (array_slice($p->wrap($txt, $mm(90), $f($pt)), 0, $max) as $zl) { $p->text($X(5), $Y($y), $zl, $f($pt), false, $ink); $y += $pt * 0.42; }
    };
    if ($d['spec']) { $abschnitt('Specifications'); $zeilen(implode(' · ', $d['spec']), 6.6, 3); $y += 1.2; }
    if ($d['formel']) {
        $abschnitt('Formula (per unit)');
        $platz = (int) floor((112 - $y) / 3.1);
        $rows = $d['formel']; $mehr = 0;
        if (count($rows) > $platz) { $mehr = count($rows) - max(1, $platz - 1); $rows = array_slice($rows, 0, max(1, $platz - 1)); }
        foreach ($rows as [$n, $mg, $pc]) {
            $p->text($X(5), $Y($y), $p->fit($n, $mm(60), $f(6.4)), $f(6.4), false, $ink);
            $p->textRight($X(80), $Y($y), la_num($mg, 2) . ' mg', $f(6.4), false, $ink);
            $p->textRight($X(95), $Y($y), number_format($pc, 1, '.', '') . ' %', $f(6.4), false, $ink);
            $y += 3.1;
        }
        if ($mehr) { $p->text($X(5), $Y($y), '+ ' . $mehr . ' more ingredients', $f(6.2), false, [110, 110, 108]); $y += 3.1; }
        $y += 1.2;
    }
    $abschnitt('Shelf life / Storage'); $zeilen($d['lagerung'], 6.8, 2); $y += 1.2;
    // Regel Nico 13.11.2026: nur die Lieferantennummer, kein Name, keine Adresse (der Aufkleber klebt im Lager).
    $abschnitt('Supplier');
    $zeilen('Supplier no.: ' . (($d['lieferant_nr'] ?? '') !== '' ? $d['lieferant_nr'] : '–'), 6.8, 1);
    if ($d['herkunft'] !== '') $zeilen('Origin: ' . $d['herkunft'], 6.8, 1);
    $y += 1.2;
    $abschnitt('Ship to');
    $zeilen(implode(', ', $d['ship_to']), 7.2, 2);
}

// PDF zu mehreren Aufkleber-IDs. Rückgabe PDF-Bytes oder null.
function la_pdf(array $ids, string $format = '100x150'): ?string {
    if (!la_schluessel_da()) return null;   // 13.11.2026: ohne Schlüssel kein Druck (Code wäre fälschbar)
    $daten = array_values(array_filter(array_map(fn($id) => la_daten((int)$id), $ids)));
    if (!$daten) return null;
    $pdf = new MiniPDF();
    $pt = fn(float $mm): float => $mm / 25.4 * 72;
    if ($format === '100x150') {
        $pdf->w = $pt(100); $pdf->h = $pt(150);
        foreach ($daten as $i => $d) { if ($i > 0) $pdf->addPage(); la_aufkleber_zeichnen($pdf, 0, 0, 1.0, $d); }
    } elseif ($format === 'a4_2') {
        $s = 138 / 150;   // zwei je Seite übereinander
        foreach ($daten as $i => $d) {
            if ($i > 0 && $i % 2 === 0) $pdf->addPage();
            la_aufkleber_zeichnen($pdf, ($pdf->w - $pt(100 * $s)) / 2, $pt(8 + ($i % 2) * 143), $s, $d);
        }
    } else {   // a4_1
        $s = 1.75;
        foreach ($daten as $i => $d) { if ($i > 0) $pdf->addPage(); la_aufkleber_zeichnen($pdf, ($pdf->w - $pt(100 * $s)) / 2, $pt(17), $s, $d); }
    }
    return $pdf->output();
}
