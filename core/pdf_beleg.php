<?php
// Beleg-PDF (Angebot/AB/Rechnung) im bulkify-Layout – portiert aus v3 (bulkify-dashboard-v3/beleg_build.php).
// Abhängigkeitsfrei über MiniPDF. Firmendaten aus app_meta, Logo aus assets/bulkify-logo.jpg.
require_once __DIR__ . '/lib/minipdf.php';
require_once __DIR__ . '/schema.php';

// Firmenstammdaten fürs Dokument (aus den Einstellungen).
function beleg_firma(): array {
    return [
        'name'    => (string) meta_get('firma_name', 'Maniso GmbH'),
        'strasse' => trim((string) meta_get('firma_strasse', '') . ' ' . (string) meta_get('firma_hausnr', '')),
        'plz_ort' => trim((string) meta_get('firma_plz', '') . ' ' . (string) meta_get('firma_ort', '')),
        'land'    => (string) meta_get('firma_land', 'Deutschland'),
        'email'   => (string) meta_get('firma_email', ''),
        'ust_id'  => (string) meta_get('firma_ustid', ''),
        'eori'    => (string) meta_get('firma_eori', ''),
        'bank_de_name'  => (string) meta_get('bank_de_name', ''),
        'bank_de_iban'  => (string) meta_get('bank_de_iban', ''),
        'bank_de_bic'   => (string) meta_get('bank_de_bic', ''),
        'bank_int_name' => (string) meta_get('bank_int_name', ''),
        'bank_int_iban' => (string) meta_get('bank_int_iban', ''),
        'bank_int_bic'  => (string) meta_get('bank_int_bic', ''),
    ];
}

// Cent -> "1.234,56" (optional mit €-Zeichen)
function beleg_eur(int $c, string $suffix = ' €'): string { return number_format($c / 100, 2, ',', '.') . $suffix; }
function beleg_num(float $n): string { $s = number_format($n, 2, ',', '.'); return rtrim(rtrim($s, '0'), ','); }

/**
 * @param array $b   Belegkopf: belegart_label, nummer, empfaenger, adresse (mehrzeilig),
 *                   datum, gueltig_bis, kundennummer, version, bezug, bearbeiter, bearbeiter_email,
 *                   ust_id (Kunde), kopf_text, zahlungsbedingung, zahlungsart_label, hinweis, kleinunternehmer
 * @param array $positionen  je Position: artikelnr, bezeichnung, beschreibung, menge, einheit, preis_cent, gesamt_cent, mwst_satz
 * @param array $produktStaffel  optional: [ ['name'=>, 'mpp'=>Stück/Pkg, 'rows'=>[['ab'=>,'stueck_cent'=>,'pack_cent'=>], ...]], ... ]
 */
// Beschriftungen des Belegs. Deutsch ist der Normalfall; Einkaufsbelege an auslaendische
// Lieferanten laufen auf Englisch ($b['sprache'] = 'en'). Alles, was nicht uebersetzt ist,
// bleibt deutsch stehen – lieber ein deutsches Wort als ein leeres Feld.
function beleg_labels(string $sprache): array {
    $de = [
        'datum'=>'Datum', 'nummer_kunde'=>'Kunden-Nr.', 'nummer_lieferant'=>'Lieferanten-Nr.',
        'version'=>'Version', 'gueltig'=>'Gültig bis', 'faellig'=>'Fällig bis', 'bezug'=>'Bezug',
        'bearbeiter'=>'Bearbeiter', 'email'=>'E-Mail', 'ustid'=>'USt-Id Kunde',
        'pos'=>'Pos.', 'artnr'=>'Art.-Nr.', 'bezeichnung'=>'Bezeichnung', 'menge'=>'Menge',
        'einheit'=>'Einh.', 'preis'=>'Preis €', 'gesamt'=>'Gesamt €',
        'zwischensumme'=>'Zwischensumme', 'endsumme'=>'Endsumme', 'zahlungsart'=>'Zahlungsart',
    ];
    if (strtolower(trim($sprache)) !== 'en') return $de;
    return array_merge($de, [
        'datum'=>'Date', 'nummer_kunde'=>'Customer no.', 'nummer_lieferant'=>'Supplier no.',
        'version'=>'Version', 'gueltig'=>'Valid until', 'faellig'=>'Due', 'bezug'=>'Reference',
        'bearbeiter'=>'Contact', 'email'=>'E-mail', 'ustid'=>'VAT ID',
        'pos'=>'Item', 'artnr'=>'Part no.', 'bezeichnung'=>'Description', 'menge'=>'Qty',
        'einheit'=>'Unit', 'preis'=>'Price €', 'gesamt'=>'Total €',
        'zwischensumme'=>'Subtotal', 'endsumme'=>'Total', 'zahlungsart'=>'Payment terms',
    ]);
}
function build_beleg_pdf(array $b, array $positionen, array $produktStaffel = []): string
{
    $fa    = beleg_firma();
    $klein = (int) ($b['kleinunternehmer'] ?? 0) === 1;
    $T = beleg_labels((string)($b['sprache'] ?? 'de'));   // Beschriftungen (de/en)
    $istEinkauf = in_array((string)($b['belegart_label'] ?? ''), ['Bestellung','Purchase Order'], true);

    // Logo (JPEG) laden, falls vorhanden
    $logoImg = null;
    $lp = BX_ROOT . '/assets/bulkify-logo.jpg';
    if (is_file($lp)) { $d = @file_get_contents($lp); $s = @getimagesize($lp); if ($d && $s) $logoImg = ['data' => $d, 'w' => $s[0], 'h' => $s[1]]; }

    // Summen je USt-Satz
    $byRate = [];
    foreach ($positionen as $pp) {
        $g = (int) round((float) $pp['menge'] * (int) $pp['preis_cent']);
        $r = (string) (float) ($pp['mwst_satz'] ?? 0);
        if (!isset($byRate[$r])) $byRate[$r] = ['satz' => (float) ($pp['mwst_satz'] ?? 0), 'netto' => 0, 'ust' => 0];
        $byRate[$r]['netto'] += $g;
    }
    foreach ($byRate as &$v) { $v['ust'] = $klein ? 0 : (int) round($v['netto'] * $v['satz'] / 100); }
    unset($v);
    $rates = array_values($byRate);
    usort($rates, fn($a, $c) => $c['satz'] <=> $a['satz']);
    $nettoSum = 0; $ustSum = 0;
    foreach ($rates as $v) { $nettoSum += $v['netto']; $ustSum += $v['ust']; }
    $bruttoSum = $nettoSum + $ustSum;

    $INK = [44, 44, 42]; $GRAY = [95, 94, 90]; $GOLD = [184, 146, 58]; $LINE = [210, 208, 200];
    $p = new MiniPDF();
    $L = 40; $R = 555;

    // ---- Kopf ----
    $absender = trim($fa['name'] . ', ' . $fa['strasse'] . ', ' . $fa['plz_ort'], ', ');
    $logoId = null; $logoW = 0; $logoH = 0;
    if ($logoImg) {
        $logoId = $p->registerJpeg($logoImg['data'], $logoImg['w'], $logoImg['h']);
        $rr = $logoImg['h'] / max(1, $logoImg['w']);
        $logoW = 150; $logoH = $logoW * $rr;
        if ($logoH > 60) { $logoH = 60; $logoW = $logoH / max(0.01, $rr); }
        $logoW = (int) round($logoW); $logoH = (int) round($logoH);
    }
    if ($logoId !== null) { $p->drawImage($logoId, $R - $logoW, 34, $logoW, $logoH); $rTop = 34 + $logoH + 18; }
    else { $p->textRight($R, 62, 'bulkify', 26, true, $INK); $rTop = 82; }

    // Firmenblock rechts
    $cy = $rTop;
    $p->textRight($R, $cy, $fa['name'], 9, true, $INK); $cy += 13;
    if ($fa['strasse'] !== '') { $p->textRight($R, $cy, $fa['strasse'], 9, false, $GRAY); $cy += 12; }
    if ($fa['plz_ort'] !== '') { $p->textRight($R, $cy, $fa['plz_ort'], 9, false, $GRAY); $cy += 12; }
    if ($fa['email']   !== '') { $p->textRight($R, $cy, $fa['email'],   9, false, $GRAY); $cy += 12; }

    // Absenderzeile + Empfänger links
    $ey = $rTop;
    $p->text($L, $ey - 12, $p->fit('bulkify | ' . $absender, 330, 7.5, false), 7.5, false, $GRAY);
    $p->text($L, $ey, $p->fit((string) ($b['empfaenger'] ?? ''), 300, 11, true), 11, true, $INK);
    $ey += 14;
    foreach (array_slice(preg_split('/\r?\n/', (string) ($b['adresse'] ?? '')), 0, 4) as $ln) {
        $ln = trim($ln); if ($ln === '') continue;
        $p->text($L, $ey, $p->fit($ln, 300, 10, false), 10, false, $INK); $ey += 13;
    }

    // ---- Titel ----
    $ty = max($ey, $cy) + 40;
    $titel = (string) ($b['belegart_label'] ?? 'Angebot') . ' ' . (($b['nummer'] ?? '') !== '' ? $b['nummer'] : '(Entwurf)');
    $p->text($L, $ty, $titel, 18, true, $INK);

    // ---- Meta-Grid ----
    $my = $ty + 22;
    $fmtD = function ($d) { $d = trim((string) $d); if ($d === '') return '-'; $t = strtotime($d); return $t ? date('d.m.Y', $t) : $d; };
    $left  = [[$T['datum'], $fmtD($b['datum'] ?? '')], [$istEinkauf ? $T['nummer_lieferant'] : $T['nummer_kunde'], (string) ($b['kundennummer'] ?? '') ?: '-']];
    $right = [];
    $istAngebot = ((string) ($b['belegart_label'] ?? 'Angebot')) === 'Angebot';
    if ($istAngebot) {
        $left[]  = [$T['version'], (string) ((int) ($b['version'] ?? 1))];
        $right[] = [$T['gueltig'], $fmtD($b['gueltig_bis'] ?? '')];
    } elseif (!empty($b['faellig_bis'])) {
        $right[] = [$T['faellig'], $fmtD($b['faellig_bis'])];
    }
    if (!empty($b['bezug'])) $left[] = [$T['bezug'], (string) $b['bezug']];
    $right[] = [$T['bearbeiter'], (string) ($b['bearbeiter'] ?? '') ?: '-'];
    $right[] = [$T['email'], (string) ($b['bearbeiter_email'] ?? '') ?: '-'];
    if (!empty($b['ust_id'])) $right[] = [$T['ustid'], (string) $b['ust_id']];
    $n = max(count($left), count($right));
    for ($i = 0; $i < $n; $i++) {
        if (isset($left[$i]))  { $p->text($L, $my, $left[$i][0] . ':', 9, false, $GRAY); $p->text($L + 90, $my, $p->fit($left[$i][1], 170, 9, true), 9, true, $INK); }
        if (isset($right[$i])) { $p->textRight(452, $my, $right[$i][0] . ':', 9, false, $GRAY); $p->textRight($R, $my, $p->fit($right[$i][1], 100, 9, true), 9, true, $INK); }
        $my += 14;
    }

    // ---- Begleittext ----
    $kopf = trim((string) ($b['kopf_text'] ?? ''));
    if ($kopf !== '') {
        $my += 8;
        foreach (preg_split('/\r?\n/', $kopf) as $para) {
            $para = trim($para); if ($para === '') { $my += 6; continue; }
            foreach ($p->wrap($para, $R - $L, 9, false) as $wl) { $p->text($L, $my, $wl, 9, false, $INK); $my += 12; }
        }
        $my += 2;
    }

    // ---- Positionstabelle ----
    $cPos = $L + 2; $cArt = $L + 24; $cBez = $L + 92; $cMengeR = 438; $cEinh = 444; $cPreisR = 502; $cGesR = $R;
    $hy = $my + 10; $hb = $hy + 8;
    $p->text($cPos, $hb, $T['pos'], 8, true, $INK);
    $p->text($cArt, $hb, $T['artnr'], 8, true, $INK);
    $p->text($cBez, $hb, $T['bezeichnung'], 8, true, $INK);
    $p->textRight($cMengeR, $hb, $T['menge'], 8, true, $INK);
    $p->text($cEinh, $hb, $T['einheit'], 8, true, $INK);
    $p->textRight($cPreisR, $hb, $T['preis'], 8, true, $INK);
    $p->textRight($cGesR, $hb, $T['gesamt'], 8, true, $INK);
    $p->line($L, $hy + 13, $R, $hy + 13, 0.8, $INK);

    $y = $hy + 17; $bezMax = $cMengeR - 30 - $cBez; $i = 1;
    foreach ($positionen as $pos) {
        if ($y > 720) { $p->addPage(); $y = 54; }
        $by = $y + 11;
        $p->text($cPos, $by, (string) $i, 8, false, $INK);
        $p->text($cArt, $by, $p->fit((string) ($pos['artikelnr'] ?? ''), 62, 8, false), 8, false, $INK);
        $p->text($cBez, $by, $p->fit((string) $pos['bezeichnung'], $bezMax, 9, true), 9, true, $INK);
        $p->textRight($cMengeR, $by, beleg_num((float) $pos['menge']), 8, false, $INK);
        $p->text($cEinh, $by, $p->fit((string) ($pos['einheit'] ?? ''), 34, 8, false), 8, false, $INK);
        $p->textRight($cPreisR, $by, beleg_eur((int) $pos['preis_cent'], ''), 8, false, $INK);
        $p->textRight($cGesR, $by, beleg_eur((int) ($pos['gesamt_cent'] ?? round((float)$pos['menge']*(int)$pos['preis_cent'])), ''), 9, true, $INK);
        $y += 15;
        $besch = trim((string) ($pos['beschreibung'] ?? ''));
        if ($besch !== '') {
            foreach (array_slice(preg_split('/\r?\n/', $besch), 0, 12) as $bl) {
                $bl = trim($bl); if ($bl === '') continue;
                foreach ($p->wrap($bl, $bezMax, 7, false) as $wl) {
                    if ($y > 755) { $p->addPage(); $y = 54; }
                    $p->text($cBez, $y + 8, $wl, 7, false, $GRAY); $y += 9;
                }
            }
        }
        $p->line($L, $y + 2, $R, $y + 2, 0.4, $LINE); $y += 6; $i++;
    }

    // ---- Summen ----
    if ($y > 680) { $p->addPage(); $y = 54; }
    $sumX = 330; $valR = $R; $y += 10;
    $p->line($sumX, $y, $R, $y, 0.8, $INK); $y += 14;
    $p->text($sumX, $y, 'Positionen netto', 9, false, $INK);
    $p->textRight($valR, $y, beleg_eur($nettoSum), 9, true, $INK); $y += 14;
    if ($klein) {
        foreach ($p->wrap('Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.', $R - $sumX, 8, false) as $wl) { $p->text($sumX, $y, $wl, 8, false, $GRAY); $y += 11; }
    } else {
        foreach ($rates as $v) {
            if ($v['netto'] == 0) continue;
            if ($v['satz'] == 0) { $lbl = 'USt. 0,00% (steuerfrei)'; }
            else { $lbl = 'zzgl. USt. ' . number_format($v['satz'], 2, ',', '.') . '% auf ' . beleg_eur($v['netto'], ''); }
            $p->text($sumX, $y, $p->fit($lbl, ($valR - 60) - $sumX, 9, false), 9, false, $INK);
            $p->textRight($valR, $y, beleg_eur($v['ust']), 9, false, $INK); $y += 14;
        }
    }
    $p->line($sumX, $y - 2, $R, $y - 2, 0.5, $LINE);
    $p->text($sumX, $y + 10, $T['endsumme'], 10, true, $INK);
    $p->textRight($valR, $y + 10, beleg_eur($bruttoSum), 11, true, $INK); $y += 26;

    // ---- Preis je fertiges Produkt (Staffel) ----
    if ($produktStaffel) {
        if ($y > 620) { $p->addPage(); $y = 54; }
        $y += 8;
        $p->text($L, $y, 'Preis je fertiges Produkt', 10, true, $INK); $y += 6;
        $iAb = 330; $iKap = 445; $iDose = $R;
        $p->text($L, $y + 9, 'Produkt', 8, true, $INK);
        $p->textRight($iAb, $y + 9, 'ab Menge (Packungen)', 8, true, $INK);
        $p->textRight($iKap, $y + 9, 'Stückpreis', 8, true, $INK);
        $p->textRight($iDose, $y + 9, 'Preis/Packung', 8, true, $INK);
        $p->line($L, $y + 13, $R, $y + 13, 0.6, $INK); $y += 17;
        foreach ($produktStaffel as $g) {
            $label = $p->fit($g['name'] . (!empty($g['mpp']) ? ' (' . beleg_num((float) $g['mpp']) . ' Stück/Packung)' : ''), 260, 9, true);
            $first = true;
            foreach ($g['rows'] as $rw) {
                if ($y > 778) { $p->addPage(); $y = 54; }
                if ($first) { $p->text($L, $y + 8, $label, 9, true, $INK); $first = false; }
                $p->textRight($iAb, $y + 8, beleg_num((float) $rw['ab']), 9, false, $INK);
                $p->textRight($iKap, $y + 8, isset($rw['stueck_cent']) ? beleg_eur((int) $rw['stueck_cent']) : '–', 9, false, $INK);
                $p->textRight($iDose, $y + 8, beleg_eur((int) $rw['pack_cent']), 9, true, $INK);
                $y += 12; $p->line($L, $y, $R, $y, 0.3, $LINE);
            }
            $y += 3;
        }
        $y += 8;
    }

    // ---- Zahlung ----
    if ($y > 720) { $p->addPage(); $y = 54; }
    $zb = (string) ($b['zahlungsbedingung'] ?? '') ?: (string) meta_get('bh_zahlungsbedingung', 'Sofort zahlbar ohne Abzug');
    $za = (string) ($b['zahlungsart_label'] ?? 'Vorkasse');
    $p->text($L, $y, 'Zahlungsbedingung: ' . $zb, 9, false, $INK); $y += 13;
    $p->text($L, $y, $T['zahlungsart'] . ': ' . $za, 9, false, $INK); $y += 18;

    // ---- Hinweis zur Herstellung ----
    $hinweis = (string) ($b['hinweis'] ?? '') ?: (string) meta_get('bh_hinweis_herstellung', '');
    if (trim($hinweis) !== '') {
        if ($y > 700) { $p->addPage(); $y = 54; }
        $p->text($L, $y, 'Hinweis zur Herstellung', 9, true, $INK); $y += 12;
        foreach ($p->wrap($hinweis, $R - $L, 8, false) as $wl) {
            if ($y > 770) { $p->addPage(); $y = 54; }
            $p->text($L, $y, $wl, 8, false, $GRAY); $y += 10;
        }
    }

    // ---- Bankverbindungen ----
    if ($fa['bank_de_iban'] !== '' || $fa['bank_int_iban'] !== '') {
        if ($y > 720) { $p->addPage(); $y = 54; }
        $y += 4;
        $p->text($L, $y, 'Kontoverbindungen:', 9, true, $INK); $y += 13;
        $col2 = 300;
        $hatInt = $fa['bank_int_iban'] !== '';
        $p->text($L, $y, 'Deutschland:', 8, false, $GRAY);
        if ($hatInt) $p->text($col2, $y, 'International:', 8, false, $GRAY);
        $y += 12;
        $deL = array_values(array_filter([$fa['bank_de_name'], $fa['name'], $fa['bank_de_iban'], $fa['bank_de_bic']], fn($x) => trim((string)$x) !== ''));
        $inL = array_values(array_filter([$fa['bank_int_name'], $fa['name'], $fa['bank_int_iban'], $fa['bank_int_bic']], fn($x) => trim((string)$x) !== ''));
        $rowsN = max(count($deL), count($inL));
        for ($k = 0; $k < $rowsN; $k++) {
            if (isset($deL[$k])) $p->text($L, $y, $p->fit($deL[$k], 250, 8, false), 8, false, $INK);
            if ($hatInt && isset($inL[$k])) $p->text($col2, $y, $p->fit($inL[$k], 250, 8, false), 8, false, $INK);
            $y += 11;
        }
        $y += 6;
    }

    // ---- Fußzeile ----
    $foot = 'bulkify® ist eine Marke der ' . $fa['name'] . ', ' . $fa['strasse'] . ', ' . $fa['plz_ort']
          . ($fa['land'] !== '' ? ', ' . $fa['land'] : '')
          . ($fa['ust_id'] !== '' ? ', USt-Id: ' . $fa['ust_id'] : '')
          . ($fa['eori'] !== '' ? ', Eori: ' . $fa['eori'] : '');
    $p->textCenter(($L + $R) / 2, 828, $p->fit($foot, $R - $L, 7, false), 7, false, $GRAY);

    return $p->output();
}
