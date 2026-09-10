<?php
// Spezifikation und Analysenzertifikat (CoA) im bulkify-Layout – identische Optik wie die
// per Skill erzeugten Dokumente: zentrierter Titel, Charcoal-Tabellenköpfe (#232323) mit
// weißer Schrift, graue Label-Zellen, Zeilenschattierung, Firmen-Fußzeile und QS-Freigabe
// mit Signatur + Stempel („Tabea Albers").
//
// Warum eigene Dokumente: Die Unterlagen der Vorlieferanten laufen auf deren Briefpapier –
// die geben wir NICHT an den Kunden weiter. Wir stellen eigene Belege aus, gefüllt aus
// unseren Stammdaten (Artikel) bzw. den Analysewerten der Charge.
require_once __DIR__ . '/lib/minipdf.php';
require_once __DIR__ . '/pdf_beleg.php';   // beleg_firma()

// Farbwelt (wie das Skill-Design)
const SPEC_CHARCOAL = [35, 35, 35];
const SPEC_WHITE    = [255, 255, 255];
const SPEC_INK      = [44, 44, 42];
const SPEC_GRAU     = [95, 94, 90];
const SPEC_LABEL    = [237, 237, 237];
const SPEC_LINE     = [201, 201, 201];
const SPEC_ALT      = [247, 247, 247];

// Ja/Nein/unbekannt als Text – NULL heißt „nicht erklärt", nicht „nein".
function spec_jn($v, string $ja = 'ja', string $nein = 'nein'): string {
    if ($v === null || $v === '') return '–';
    return ((int)$v === 1) ? $ja : $nein;
}

// Kopf: Logo links, Firmenblock rechts, Trennlinie, zentrierter Titel + Untertitel.
function spec_kopf(MiniPDF $p, string $titel, string $untertitel): float {
    $fa = beleg_firma(); $L = 40; $R = 555;
    // Logo (links oben)
    $lp = BX_ROOT . '/assets/bulkify-logo.jpg';
    if (is_file($lp)) {
        $d = @file_get_contents($lp); $s = @getimagesize($lp);
        if ($d && $s) {
            $id = $p->registerJpeg($d, $s[0], $s[1]);
            $lw = 132; $lh = $lw * $s[1] / max(1, $s[0]);
            if ($lh > 46) { $lh = 46; $lw = $lh * $s[0] / max(1, $s[1]); }
            $p->drawImage($id, $L, 30, (int)$lw, (int)$lh);
        }
    } else {
        $p->text($L, 52, 'bulkify', 22, true, SPEC_CHARCOAL);
    }
    // Firmenblock rechts
    $cy = 34;
    $p->textRight($R, $cy, $fa['name'], 9, true, SPEC_INK); $cy += 12;
    if ($fa['strasse'] !== '') { $p->textRight($R, $cy, $fa['strasse'], 8.5, false, SPEC_GRAU); $cy += 11; }
    if ($fa['plz_ort'] !== '') { $p->textRight($R, $cy, $fa['plz_ort'] . ($fa['land'] !== '' ? ' · ' . $fa['land'] : ''), 8.5, false, SPEC_GRAU); $cy += 11; }
    if ($fa['ust_id'] !== '')  { $p->textRight($R, $cy, 'USt-IdNr. ' . $fa['ust_id'], 8.5, false, SPEC_GRAU); $cy += 11; }
    if ($fa['email'] !== '')   { $p->textRight($R, $cy, $fa['email'], 8.5, false, SPEC_GRAU); $cy += 11; }
    $top = max(88, $cy + 6);
    $p->line($L, $top, $R, $top, 0.6, SPEC_LINE);
    // Titel zentriert
    $ty = $top + 26;
    $p->textCenter(297.5, $ty, $titel, 20, true, SPEC_CHARCOAL);
    $p->textCenter(297.5, $ty + 17, $untertitel, 11, false, SPEC_GRAU);
    return $ty + 42;
}

// Label/Wert-Gitter: graue Label-Zellen links, Werte rechts, Rahmen.
function spec_grid(MiniPDF $p, float $y, array $rows): float {
    $L = 40; $R = 555; $W = $R - $L; $lw = 160; $rH = 18;
    if ($y > 770) { $p->addPage(); $y = 48; }
    $y0 = $y;
    foreach ($rows as $r) {
        if ($y > 795) { $p->rectStroke($L, $y0, $W, $y - $y0, 0.6, SPEC_LINE); $p->line($L + $lw, $y0, $L + $lw, $y, 0.4, SPEC_LINE); $p->addPage(); $y = 48; $y0 = $y; }
        $p->rect($L, $y, $lw, $rH, SPEC_LABEL);
        $p->text($L + 8, $y + 12, $p->fit((string)$r[0], $lw - 14, 8.5, true), 8.5, true, [70, 70, 68]);
        $p->text($L + $lw + 8, $y + 12, $p->fit(((string)$r[1] !== '' ? (string)$r[1] : '–'), $W - $lw - 14, 9, false), 9, false, SPEC_INK);
        $p->line($L, $y + $rH, $R, $y + $rH, 0.3, SPEC_LINE);
        $y += $rH;
    }
    $p->rectStroke($L, $y0, $W, $y - $y0, 0.6, SPEC_LINE);
    $p->line($L + $lw, $y0, $L + $lw, $y, 0.4, SPEC_LINE);
    return $y;
}

// Abschnittstitel (klein, charcoal, fett) mit etwas Luft darüber.
function spec_h(MiniPDF $p, float $y, string $titel): float {
    if ($y > 780) { $p->addPage(); $y = 48; }
    $y += 18;
    $p->text(40, $y, $titel, 11, true, SPEC_CHARCOAL);
    return $y + 6;
}

// Tabelle mit Charcoal-Kopf. $colDefs = [[xStart, Label], …] (letzte Spalte bis R),
// $rows = Liste gleich langer Arrays. Zeilen wechseln die Schattierung.
function spec_table(MiniPDF $p, float $y, array $colDefs, array $rows): float {
    $L = 40; $R = 555; $W = $R - $L; $hH = 20; $rH = 17;
    $head = function ($yy) use ($p, $colDefs, $L, $W) {
        $p->rect($L, $yy, $W, $hH = 20, SPEC_CHARCOAL);
        foreach ($colDefs as $c) $p->text($c[0] + 6, $yy + 13, $c[1], 8, true, SPEC_WHITE);
    };
    if ($y > 760) { $p->addPage(); $y = 48; }
    $y0 = $y; $head($y); $y += $hH; $i = 0;
    foreach ($rows as $r) {
        if ($y > 800) {
            $p->rectStroke($L, $y0, $W, $y - $y0, 0.6, SPEC_LINE);
            $p->addPage(); $y = 48; $y0 = $y; $head($y); $y += $hH;
        }
        if ($i % 2 === 1) $p->rect($L, $y, $W, $rH, SPEC_ALT);
        foreach ($colDefs as $ci => $c) {
            $x = $c[0]; $nx = $colDefs[$ci + 1][0] ?? $R;
            $p->text($x + 6, $y + 12, $p->fit(((string)($r[$ci] ?? '') !== '' ? (string)$r[$ci] : '–'), $nx - $x - 10, 8.5, false), 8.5, false, SPEC_INK);
        }
        $y += $rH; $i++;
    }
    $p->rectStroke($L, $y0, $W, $y - $y0, 0.6, SPEC_LINE);
    foreach ($colDefs as $ci => $c) if ($ci > 0) $p->line($c[0], $y0, $c[0], $y, 0.4, SPEC_LINE);
    return $y;
}

// QS-Freigabeblock: links „erstellt", rechts Freigabe mit Signatur + Stempel („Tabea Albers").
function spec_release(MiniPDF $p, float $y, string $datum): float {
    $L = 40; $R = 555; $colR = 320;
    if ($y > 660) { $p->addPage(); $y = 48; }
    $y += 30;
    $sy = $y;                       // Bereich für Signatur/Stempel
    $sig = BX_ROOT . '/assets/bulkify-signature.jpg';
    $stp = BX_ROOT . '/assets/bulkify-stamp.jpg';
    if (is_file($sig)) { $d = @file_get_contents($sig); $s = @getimagesize($sig); if ($d && $s) { $id = $p->registerJpeg($d, $s[0], $s[1]); $iw = 92; $ih = $iw * $s[1] / max(1, $s[0]); $p->drawImage($id, $colR, $sy, (int)$iw, (int)$ih); } }
    if (is_file($stp)) { $d = @file_get_contents($stp); $s = @getimagesize($stp); if ($d && $s) { $id = $p->registerJpeg($d, $s[0], $s[1]); $iw = 108; $ih = $iw * $s[1] / max(1, $s[0]); $p->drawImage($id, $colR + 108, $sy + 8, (int)$iw, (int)$ih); } }
    $ly = $sy + 58;                 // Signaturlinie
    $p->line($L, $ly, $colR - 30, $ly, 0.5, SPEC_GRAU);
    $p->line($colR, $ly, $R, $ly, 0.5, SPEC_GRAU);
    $p->text($L, $ly + 11, 'Erstellt: bulkify (maschinell) · ' . $datum, 8.5, false, SPEC_GRAU);
    $p->text($colR, $ly + 11, 'Tabea Albers · Qualitätssicherung · ' . $datum, 8.5, false, SPEC_GRAU);
    return $ly + 22;
}

// Fußzeile: rechtssichere Firmenzeile, unten auf der Seite. $maschHinweis=false unterdrückt den
// „ohne Unterschrift gültig"-Satz (bei Verträgen, die ja gerade unterschrieben werden sollen).
function spec_fuss(MiniPDF $p, float $y, bool $maschHinweis = true): void {
    $fa = beleg_firma(); $L = 40; $R = 555;
    if ($y > 748) { $p->addPage(); $y = 54; }
    if ($maschHinweis) $p->text($L, $y, 'Dieses Dokument wurde maschinell erstellt und ist ohne Unterschrift gültig.', 8, false, SPEC_GRAU);
    $foot = $fa['name'] . ' · ' . $fa['strasse'] . ' · ' . $fa['plz_ort']
          . ($fa['land'] !== '' ? ' · ' . $fa['land'] : '')
          . ($fa['ust_id'] !== '' ? ' · USt-IdNr. ' . $fa['ust_id'] : '')
          . ($fa['email'] !== '' ? ' · ' . $fa['email'] : '');
    $p->line($L, 800, $R, 800, 0.6, SPEC_LINE);
    $p->text($L, 812, $p->fit($foot, $R - $L, 7.5, false), 7.5, false, SPEC_GRAU);
    $p->textRight($R, 812, 'bulkify® · Marke der ' . $fa['name'], 7.5, false, SPEC_GRAU);
}

// ---------------------------------------------------------------------------
// Spezifikation eines Rohstoffs (Artikel-Ebene) – aus unseren Stammdaten.
// ---------------------------------------------------------------------------
function build_spec_pdf(int $item_id): ?string {
    $it = one("SELECT * FROM item WHERE id=?", [$item_id]);
    if (!$it) return null;
    $L = 40; $R = 555;
    $p = new MiniPDF();
    $y = spec_kopf($p, 'PRODUKTSPEZIFIKATION', 'Product Specification · ' . (string)$it['name']);
    $fmtD = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '';

    // Kopf-/Produktidentität als graues Label-Gitter
    $ident = [];
    $ident[] = ['Bezeichnung', (string)$it['name']];
    if (!empty($it['synonym']))    $ident[] = ['Synonyme', (string)$it['synonym']];
    if (!empty($it['bot_quelle'])) $ident[] = ['Botanische Quelle', (string)$it['bot_quelle']];
    if (!empty($it['cas']))        $ident[] = ['CAS-Nr.', (string)$it['cas']];
    if (!empty($it['ec_nr']))      $ident[] = ['EC-Nr.', (string)$it['ec_nr']];
    if (!empty($it['herkunftsland'])) $ident[] = ['Herkunftsland', (string)$it['herkunftsland']];
    if (!empty($it['zusaetze']))   $ident[] = ['Zusätze / Trägerstoffe', (string)$it['zusaetze']];
    if (!empty($it['artikelnummer'])) $ident[] = ['Artikel-Nr.', (string)$it['artikelnummer']];
    $ident[] = ['Spezifikations-Nr.', trim((string)($it['spec_nr'] ?? '') . '  ' . (string)($it['spec_version'] ?? '')) ?: '–'];
    $ident[] = ['Gültig ab', $fmtD($it['spec_gueltig_ab'] ?? '') ?: date('d.m.Y')];
    $y = spec_grid($p, $y, $ident);

    // Gehalt (Assay)
    $wirk = all("SELECT n.name, w.gehalt_prozent FROM item_wirkstoff w
                 JOIN naehrstoff n ON n.id=w.naehrstoff_id
                 WHERE w.item_id=? AND w.gehalt_prozent IS NOT NULL ORDER BY w.sort, n.name", [$item_id]);
    $wrows = array_map(fn($w) => [(string)$w['name'],
        rtrim(rtrim(number_format((float)$w['gehalt_prozent'], 2, ',', '.'), '0'), ',') . ' %'], $wirk);
    if ($wrows) { $y = spec_h($p, $y, 'Gehalt (Assay)'); $y = spec_table($p, $y, [[$L, 'Wirkstoff'], [320, 'Gehalt']], $wrows); }

    // Charakteristische Kennwerte
    $kw = all("SELECT parameter, wert FROM item_kennwert WHERE item_id=? ORDER BY sort, id", [$item_id]);
    $krows = array_map(fn($k) => [(string)$k['parameter'], (string)$k['wert']], $kw);
    if ($krows) { $y = spec_h($p, $y, 'Charakteristische Kennwerte'); $y = spec_table($p, $y, [[$L, 'Parameter'], [300, 'Wert']], $krows); }

    // Reinheit & Grenzwerte
    $gw = all("SELECT parameter, grenzwert FROM item_grenzwert WHERE item_id=? ORDER BY sort, id", [$item_id]);
    $grows = array_map(fn($g) => [(string)$g['parameter'], (string)$g['grenzwert']], $gw);
    if ($grows) { $y = spec_h($p, $y, 'Reinheit & Grenzwerte'); $y = spec_table($p, $y, [[$L, 'Parameter'], [300, 'Grenzwert']], $grows); }

    // Deklarationen
    $y = spec_h($p, $y, 'Deklarationen');
    $dekl = [
        ['Vegan',           spec_jn($it['vegan'] ?? null)],
        ['GVO-frei',        spec_jn($it['gvo_frei'] ?? null)],
        ['Nicht bestrahlt', spec_jn(isset($it['bestrahlt']) && $it['bestrahlt'] !== null ? (1 - (int)$it['bestrahlt']) : null)],
        ['TSE/BSE-frei',    spec_jn($it['tse_bse_frei'] ?? null)],
        ['Allergene',       (string)($it['allergene'] ?? '') !== '' ? (string)$it['allergene'] : 'keine deklarationspflichtigen Allergene'],
    ];
    if (!empty($it['zertifikate'])) $dekl[] = ['Zertifikate', (string)$it['zertifikate']];
    $y = spec_grid($p, $y, $dekl);

    // Lagerung & Haltbarkeit
    $lag = [];
    if (!empty($it['lagerbedingungen'])) $lag[] = ['Lagerung', (string)$it['lagerbedingungen']];
    if (!empty($it['haltbarkeit']))      $lag[] = ['Mindesthaltbarkeit', (string)$it['haltbarkeit']];
    if ($lag) { $y = spec_h($p, $y, 'Lagerung & Haltbarkeit'); $y = spec_grid($p, $y, $lag); }

    // Hinweistext + QS-Freigabe
    $y += 16;
    foreach ($p->wrap('Diese Spezifikation beschreibt den Rohstoff, wie er von uns eingesetzt und weitergegeben wird. '
                    . 'Die Analysenwerte der einzelnen Lieferung stehen im Analysenzertifikat (CoA) zur jeweiligen Charge.', $R - $L, 9, false) as $wl) {
        if ($y > 780) { $p->addPage(); $y = 48; }
        $p->text($L, $y, $wl, 9, false, SPEC_GRAU); $y += 12;
    }
    $y = spec_release($p, $y, date('d.m.Y'));
    spec_fuss($p, $y + 18);
    return $p->output();
}

// ---------------------------------------------------------------------------
// Analysenzertifikat (CoA) zu einer Charge – aus den erfassten Analysewerten.
// ---------------------------------------------------------------------------
function build_coa_pdf(int $charge_id): ?string {
    $c = one("SELECT c.*, i.name AS item_name, i.spec_nr, i.spec_version, i.herkunftsland, i.allergene
              FROM charge c JOIN item i ON i.id=c.item_id WHERE c.id=?", [$charge_id]);
    if (!$c) return null;
    $L = 40; $R = 555;
    $p = new MiniPDF();
    $y = spec_kopf($p, 'ANALYSENZERTIFIKAT', 'Certificate of Analysis · ' . (string)$c['item_name']);
    $fmtD = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '–';
    $num  = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');

    $kopf = [
        ['Rohstoff', (string)$c['item_name']],
        ['Charge', (string)($c['charge_nr'] ?: '–')],
        ['Menge', $num($c['menge']) . ' ' . (string)($c['einheit'] ?? '')],
        ['Wareneingang', $fmtD($c['wareneingang'] ?? '')],
        ['Mindesthaltbar bis', $fmtD($c['mhd'] ?? '')],
    ];
    if (!empty($c['herkunftsland'])) $kopf[] = ['Herkunft', (string)$c['herkunftsland']];
    $kopf[] = ['Spezifikation', trim((string)($c['spec_nr'] ?? '') . '  ' . (string)($c['spec_version'] ?? '')) ?: '–'];
    $y = spec_grid($p, $y, $kopf);

    // Analysewerte als Charcoal-Tabelle (Parameter | Spezifikation | Ergebnis | Methode)
    $werte = all("SELECT * FROM charge_analyse WHERE charge_id=? ORDER BY sort, id", [$charge_id]);
    $y = spec_h($p, $y, 'Analysenwerte');
    if ($werte) {
        $rows = array_map(fn($w) => [(string)$w['parameter'], (string)($w['spezifikation'] ?? '–'),
            (string)($w['ergebnis'] ?? '–'), (string)($w['methode'] ?? '')], $werte);
        $y = spec_table($p, $y, [[$L, 'Parameter'], [230, 'Spezifikation'], [360, 'Ergebnis'], [460, 'Methode']], $rows);
    } else {
        $y += 4; $p->text($L, $y + 8, 'Für diese Charge sind noch keine Analysenwerte erfasst.', 9, false, SPEC_GRAU); $y += 20;
    }

    if (!empty($c['allergene'])) { $y = spec_h($p, $y, 'Allergene'); $y = spec_grid($p, $y, [['Allergene', (string)$c['allergene']]]); }

    $y += 16;
    $freigabe = (string)$c['status'] === 'frei'
        ? 'Die Charge wurde geprüft und für die Verarbeitung freigegeben.'
        : 'Die Charge befindet sich in Quarantäne; die Freigabe steht noch aus.';
    foreach ($p->wrap($freigabe, $R - $L, 9, false) as $wl) { if ($y > 780) { $p->addPage(); $y = 48; } $p->text($L, $y, $wl, 9, false, SPEC_INK); $y += 12; }
    $y = spec_release($p, $y, $fmtD($c['wareneingang'] ?? '') !== '–' ? $fmtD($c['wareneingang'] ?? '') : date('d.m.Y'));
    spec_fuss($p, $y + 18);
    return $p->output();
}
