<?php
// Verpackungs-Konformitätsdokument (PPWR) je Bestellung – bulkify-Layout, abhängigkeitsfrei via MiniPDF.
require_once __DIR__ . '/lib/minipdf.php';
require_once __DIR__ . '/pdf_beleg.php';   // beleg_firma()

// Maße einer Verpackung als kompakten Text: Ø… × H… bzw. B×H(×T).
function ppwr_masse(array $it): string {
    $n = fn($x) => rtrim(rtrim(number_format((float)$x, 1, ',', '.'), '0'), ',');
    $p = [];
    if (!empty($it['durchmesser_mm'])) $p[] = 'Ø ' . $n($it['durchmesser_mm']);
    elseif (!empty($it['breite_mm']))  $p[] = 'B ' . $n($it['breite_mm']);
    if (!empty($it['hoehe_mm']))       $p[] = 'H ' . $n($it['hoehe_mm']);
    if (!empty($it['tiefe_mm']))       $p[] = 'T ' . $n($it['tiefe_mm']);
    return $p ? implode(' × ', $p) . ' mm' : '–';
}

/**
 * @param array $b   Kopf: nummer (Bestellung), datum, empfaenger, adresse, produkt, ust_id, kundennummer
 * @param array $komponenten  je Verpackung: rolle, name, material, gewicht_g, volumen_ml, masse, dokumente (int)
 */
function build_ppwr_pdf(array $b, array $komponenten): string
{
    $fa = beleg_firma();
    $INK = [44, 44, 42]; $GRAY = [95, 94, 90]; $GOLD = [184, 146, 58]; $LINE = [210, 208, 200]; $WHITE = [255, 255, 255];
    $p = new MiniPDF();
    $L = 40; $R = 555;
    $num = fn($x) => rtrim(rtrim(number_format((float)$x, 2, ',', '.'), '0'), ',');

    // Logo oben rechts
    $logoImg = null; $lp = BX_ROOT . '/assets/bulkify-logo.jpg';
    if (is_file($lp)) { $d = @file_get_contents($lp); $s = @getimagesize($lp); if ($d && $s) $logoImg = ['data'=>$d,'w'=>$s[0],'h'=>$s[1]]; }
    if ($logoImg) {
        $id = $p->registerJpeg($logoImg['data'], $logoImg['w'], $logoImg['h']);
        $rr = $logoImg['h'] / max(1, $logoImg['w']); $lw = 150; $lh = $lw * $rr; if ($lh > 60) { $lh = 60; $lw = $lh / max(0.01, $rr); }
        $p->drawImage($id, $R - (int)$lw, 34, (int)$lw, (int)$lh); $rTop = 34 + (int)$lh + 18;
    } else { $p->textRight($R, 62, 'bulkify', 26, true, $INK); $rTop = 82; }

    $absender = trim($fa['name'] . ', ' . $fa['strasse'] . ', ' . $fa['plz_ort'], ', ');
    $cy = $rTop;
    $p->textRight($R, $cy, $fa['name'], 9, true, $INK); $cy += 13;
    if ($fa['strasse'] !== '') { $p->textRight($R, $cy, $fa['strasse'], 9, false, $GRAY); $cy += 12; }
    if ($fa['plz_ort'] !== '') { $p->textRight($R, $cy, $fa['plz_ort'], 9, false, $GRAY); $cy += 12; }
    if ($fa['email'] !== '')   { $p->textRight($R, $cy, $fa['email'], 9, false, $GRAY); $cy += 12; }

    $ey = $rTop;
    $p->text($L, $ey - 12, $p->fit('bulkify | ' . $absender, 330, 7.5, false), 7.5, false, $GRAY);
    $p->text($L, $ey, $p->fit((string)($b['empfaenger'] ?? ''), 300, 11, true), 11, true, $INK); $ey += 14;
    foreach (array_slice(preg_split('/\r?\n/', (string)($b['adresse'] ?? '')), 0, 4) as $ln) {
        $ln = trim($ln); if ($ln === '') continue;
        $p->text($L, $ey, $p->fit($ln, 300, 10, false), 10, false, $INK); $ey += 13;
    }

    // Titel + Meta
    $ty = max($ey, $cy) + 40;
    $p->text($L, $ty, 'Verpackungs-Konformität', 18, true, $INK);
    $p->text($L, $ty + 15, 'Packaging & Packaging Waste Regulation (EU) 2025/40 (PPWR)', 9, false, $GRAY);
    $my = $ty + 34;
    $fmtD = function ($d) { $d = trim((string)$d); if ($d === '') return date('d.m.Y'); $t = strtotime($d); return $t ? date('d.m.Y', $t) : $d; };
    $meta = [['Bestellung', (string)($b['nummer'] ?? '-')], ['Datum', $fmtD($b['datum'] ?? '')], ['Kunden-Nr.', (string)($b['kundennummer'] ?? '') ?: '-'], ['Produkt', (string)($b['produkt'] ?? '-')]];
    foreach ($meta as $m) {
        $p->text($L, $my, $m[0] . ':', 9, false, $GRAY);
        $p->text($L + 90, $my, $p->fit($m[1], $R - ($L + 90), 9, true), 9, true, $INK);
        $my += 14;
    }

    // Komponenten-Tabelle
    $hy = $my + 12; $hb = $hy + 8;
    $cRolle = $L + 2; $cName = $L + 92; $cMat = 300; $cGewR = 430; $cMasse = 440;
    $p->text($cRolle, $hb, 'Komponente', 8, true, $INK);
    $p->text($cName, $hb, 'Bezeichnung', 8, true, $INK);
    $p->text($cMat, $hb, 'Material', 8, true, $INK);
    $p->textRight($cGewR, $hb, 'Gewicht', 8, true, $INK);
    $p->text($cMasse, $hb, 'Maße', 8, true, $INK);
    $p->line($L, $hy + 13, $R, $hy + 13, 0.8, $INK);
    $y = $hy + 17;
    foreach ($komponenten as $k) {
        $by = $y + 11;
        $p->text($cRolle, $by, $p->fit((string)$k['rolle'], 86, 8, false), 8, false, $INK);
        $p->text($cName, $by, $p->fit((string)$k['name'], $cMat - $cName - 6, 9, true), 9, true, $INK);
        $p->text($cMat, $by, $p->fit((string)($k['material'] ?: '–'), $cGewR - $cMat - 40, 8, false), 8, false, $INK);
        $p->textRight($cGewR, $by, $k['gewicht_g'] !== null && $k['gewicht_g'] !== '' ? $num($k['gewicht_g']) . ' g' : '–', 8, false, $INK);
        $p->text($cMasse, $by, $p->fit((string)$k['masse'], $R - $cMasse, 8, false), 8, false, $INK);
        $p->line($L, $y + 16, $R, $y + 16, 0.4, $LINE);
        $y += 16;
    }
    if (!$komponenten) { $p->text($cRolle, $y + 10, 'Keine Verpackung am Produkt hinterlegt.', 8, false, $GRAY); $y += 16; }

    // Konformitäts-Aussagen
    $y += 18;
    $p->text($L, $y, 'Konformitätserklärung', 11, true, $INK); $y += 6;
    $p->line($L, $y + 4, $R, $y + 4, 0.5, $LINE); $y += 14;
    $gewSum = 0.0; $hasGew = false;
    foreach ($komponenten as $k) { if ($k['gewicht_g'] !== null && $k['gewicht_g'] !== '') { $gewSum += (float)$k['gewicht_g']; $hasGew = true; } }
    $statements = [
        'Die oben genannten Verpackungskomponenten werden für die Herstellung des genannten Produkts eingesetzt.',
        'PFAS: Die eingesetzten Verpackungen enthalten keine absichtlich zugesetzten per- und polyfluorierten Alkylsubstanzen (PFAS).',
        'Recyclingfähigkeit: Die Verpackungen bestehen aus werkstofflich recycelbaren Materialien und sind bei getrennter Sammlung recyclingfähig.',
        'Für die eingesetzten Verpackungen liegen die Konformitätsnachweise (DoC) und Materialspezifikationen der Vorlieferanten bei der Maniso GmbH vor und werden auf Anforderung bereitgestellt.',
        'Registrierungs- und Meldepflichten für in Verkehr gebrachte Verpackungen (z. B. Verpackungsregister LUCID / EPR) obliegen dem Inverkehrbringer des Fertigprodukts.',
    ];
    if ($hasGew) array_unshift($statements, 'Verpackungsgewicht gesamt je Einheit: ca. ' . $num($gewSum) . ' g.');
    foreach ($statements as $s) {
        foreach ($p->wrap('•  ' . $s, $R - $L, 9, false) as $i => $wl) {
            if ($y > 770) { $p->addPage(); $y = 54; }
            $p->text($L + ($i > 0 ? 12 : 0), $y, $wl, 9, false, $INK); $y += 12;
        }
        $y += 3;
    }

    // Ort/Datum + Aussteller
    $y += 16;
    if ($y > 740) { $p->addPage(); $y = 54; }
    $p->text($L, $y, $fa['plz_ort'] !== '' ? (explode(' ', $fa['plz_ort'] . ' ')[1] ?? $fa['plz_ort']) . ', den ' . $fmtD($b['datum'] ?? '') : 'Datum: ' . $fmtD($b['datum'] ?? ''), 9, false, $INK);
    $p->text($L, $y + 26, $fa['name'], 9, true, $INK);
    $p->text($L, $y + 38, 'Diese Erklärung wurde maschinell erstellt und ist ohne Unterschrift gültig.', 8, false, $GRAY);

    // Fußzeile
    $foot = 'bulkify® ist eine Marke der ' . $fa['name'] . ', ' . $fa['strasse'] . ', ' . $fa['plz_ort']
          . ($fa['land'] !== '' ? ', ' . $fa['land'] : '')
          . ($fa['ust_id'] !== '' ? ', USt-Id: ' . $fa['ust_id'] : '')
          . ($fa['eori'] !== '' ? ', Eori: ' . $fa['eori'] : '');
    $p->textCenter(($L + $R) / 2, 828, $p->fit($foot, $R - $L, 7, false), 7, false, $GRAY);

    return $p->output();
}
