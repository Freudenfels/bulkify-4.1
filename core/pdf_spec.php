<?php
// Spezifikation und Analysenzertifikat (CoA) im bulkify-Layout.
//
// Warum das hier steht: Die Unterlagen der Vorlieferanten kommen auf deren Briefpapier.
// Die geben wir NICHT an den Kunden weiter – er soll nicht sehen, wer uns beliefert.
// Stattdessen stellen wir eigene Dokumente aus, gefüllt aus unseren Stammdaten
// (Artikel) bzw. den Analysewerten der Charge. Die Lieferantenunterlagen bleiben
// intern die Quelle und der Nachweis.
require_once __DIR__ . '/lib/minipdf.php';
require_once __DIR__ . '/pdf_beleg.php';   // beleg_firma()

// Ja/Nein/unbekannt als Text – NULL heißt „nicht erklärt", nicht „nein".
function spec_jn($v, string $ja = 'ja', string $nein = 'nein'): string {
    if ($v === null || $v === '') return '–';
    return ((int)$v === 1) ? $ja : $nein;
}

// Gemeinsamer Rahmen: Logo, Absender, Titel. Gibt [MiniPDF, y] zurück.
function spec_kopf(MiniPDF $p, string $titel, string $untertitel): float {
    $fa = beleg_firma();
    $INK = [44, 44, 42]; $GRAY = [95, 94, 90];
    $L = 40; $R = 555;
    $logoImg = null; $lp = BX_ROOT . '/assets/bulkify-logo.jpg';
    if (is_file($lp)) { $d = @file_get_contents($lp); $s = @getimagesize($lp); if ($d && $s) $logoImg = ['data'=>$d, 'w'=>$s[0], 'h'=>$s[1]]; }
    if ($logoImg) {
        $id = $p->registerJpeg($logoImg['data'], $logoImg['w'], $logoImg['h']);
        $rr = $logoImg['h'] / max(1, $logoImg['w']); $lw = 150; $lh = $lw * $rr;
        if ($lh > 60) { $lh = 60; $lw = $lh / max(0.01, $rr); }
        $p->drawImage($id, $R - (int)$lw, 34, (int)$lw, (int)$lh); $rTop = 34 + (int)$lh + 18;
    } else { $p->textRight($R, 62, 'bulkify', 26, true, $INK); $rTop = 82; }

    $cy = $rTop;
    $p->textRight($R, $cy, $fa['name'], 9, true, $INK); $cy += 13;
    if ($fa['strasse'] !== '') { $p->textRight($R, $cy, $fa['strasse'], 9, false, $GRAY); $cy += 12; }
    if ($fa['plz_ort'] !== '') { $p->textRight($R, $cy, $fa['plz_ort'], 9, false, $GRAY); $cy += 12; }
    if ($fa['email'] !== '')   { $p->textRight($R, $cy, $fa['email'], 9, false, $GRAY); $cy += 12; }

    $ty = $cy + 26;
    $p->text($L, $ty, $titel, 18, true, $INK);
    if ($untertitel !== '') $p->text($L, $ty + 15, $untertitel, 9, false, $GRAY);
    return $ty + 34;
}

// Eine Zeile „Feld: Wert" im Kopfblock.
function spec_zeile(MiniPDF $p, float $y, string $label, string $wert): float {
    $INK = [44, 44, 42]; $GRAY = [95, 94, 90]; $L = 40; $R = 555;
    $p->text($L, $y, $label . ':', 9, false, $GRAY);
    $p->text($L + 150, $y, $p->fit($wert !== '' ? $wert : '–', $R - ($L + 150), 9, true), 9, true, $INK);
    return $y + 14;
}

// Fußzeile wie bei den übrigen bulkify-Dokumenten.
function spec_fuss(MiniPDF $p, float $y): void {
    $fa = beleg_firma(); $GRAY = [95, 94, 90]; $LINE = [210, 208, 200]; $L = 40; $R = 555;
    if ($y > 740) { $p->addPage(); $y = 54; }
    $p->text($L, $y, 'Dieses Dokument wurde maschinell erstellt und ist ohne Unterschrift gültig.', 8, false, $GRAY);
    $foot = 'bulkify® ist eine Marke der ' . $fa['name'] . ', ' . $fa['strasse'] . ', ' . $fa['plz_ort']
          . ($fa['land'] !== '' ? ', ' . $fa['land'] : '')
          . ($fa['ust_id'] !== '' ? ', USt-Id: ' . $fa['ust_id'] : '');
    $p->line($L, 800, $R, 800, 0.5, $LINE);
    $p->text($L, 812, $p->fit($foot, $R - $L, 7.5, false), 7.5, false, $GRAY);
}

// ---------------------------------------------------------------------------
// Spezifikation eines Rohstoffs (Artikel-Ebene) – aus unseren Stammdaten.
// ---------------------------------------------------------------------------
function build_spec_pdf(int $item_id): ?string {
    $it = one("SELECT * FROM item WHERE id=?", [$item_id]);
    if (!$it) return null;
    $INK = [44, 44, 42]; $GRAY = [95, 94, 90]; $LINE = [210, 208, 200];
    $L = 40; $R = 555;
    $p = new MiniPDF();
    $y = spec_kopf($p, 'Spezifikation', 'Specification · ' . (string)$it['name']);

    $fmtD = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '';
    $y = spec_zeile($p, $y, 'Bezeichnung', (string)$it['name']);
    if (!empty($it['synonym']))       $y = spec_zeile($p, $y, 'Synonyme', (string)$it['synonym']);
    if (!empty($it['bot_quelle']))    $y = spec_zeile($p, $y, 'Botanische Quelle', (string)$it['bot_quelle']);
    if (!empty($it['cas']))           $y = spec_zeile($p, $y, 'CAS-Nr.', (string)$it['cas']);
    if (!empty($it['ec_nr']))         $y = spec_zeile($p, $y, 'EC-Nr.', (string)$it['ec_nr']);
    $y = spec_zeile($p, $y, 'Spezifikations-Nr.', trim((string)($it['spec_nr'] ?? '') . ' ' . (string)($it['spec_version'] ?? '')));
    $y = spec_zeile($p, $y, 'Gültig ab', $fmtD($it['spec_gueltig_ab'] ?? '') ?: date('d.m.Y'));
    if (!empty($it['herkunftsland']))    $y = spec_zeile($p, $y, 'Herkunft', (string)$it['herkunftsland']);
    if (!empty($it['zusaetze']))         $y = spec_zeile($p, $y, 'Zusätze / Trägerstoffe', (string)$it['zusaetze']);
    if (!empty($it['haltbarkeit']))      $y = spec_zeile($p, $y, 'Mindesthaltbarkeit', (string)$it['haltbarkeit']);
    if (!empty($it['lagerbedingungen'])) $y = spec_zeile($p, $y, 'Lagerung', (string)$it['lagerbedingungen']);
    $y = spec_zeile($p, $y, 'Allergene', (string)($it['allergene'] ?? '') !== '' ? (string)$it['allergene'] : 'keine deklarationspflichtigen Allergene');

    // Gehalt an Wirkstoffen – das, wonach der Kunde als Erstes fragt.
    $wirk = all("SELECT n.name, w.gehalt_prozent, n.einheit FROM item_wirkstoff w
                 JOIN naehrstoff n ON n.id=w.naehrstoff_id
                 WHERE w.item_id=? AND w.gehalt_prozent IS NOT NULL ORDER BY w.sort, n.name", [$item_id]);
    if ($wirk) {
        $y += 12;
        $p->text($L, $y, 'Gehalt', 11, true, $INK); $y += 6;
        $p->line($L, $y + 4, $R, $y + 4, 0.5, $LINE); $y += 16;
        foreach ($wirk as $w) {
            $p->text($L, $y, $p->fit((string)$w['name'], 300, 9, false), 9, false, $INK);
            $p->textRight($R, $y, rtrim(rtrim(number_format((float)$w['gehalt_prozent'], 2, ',', '.'), '0'), ',') . ' %', 9, true, $INK);
            $y += 13;
        }
    }

    // Erklärungen – NULL heißt „nicht erklärt", darum das ausdrückliche „–".
    $y += 14;
    $p->text($L, $y, 'Erklärungen', 11, true, $INK); $y += 6;
    $p->line($L, $y + 4, $R, $y + 4, 0.5, $LINE); $y += 16;
    foreach ([
        ['Vegan',            spec_jn($it['vegan'] ?? null)],
        ['GVO-frei',         spec_jn($it['gvo_frei'] ?? null)],
        ['Nicht bestrahlt',  spec_jn(isset($it['bestrahlt']) && $it['bestrahlt'] !== null ? (1 - (int)$it['bestrahlt']) : null)],
        ['TSE/BSE-frei',     spec_jn($it['tse_bse_frei'] ?? null)],
    ] as $e) {
        $p->text($L, $y, $e[0], 9, false, $INK);
        $p->text($L + 150, $y, $e[1], 9, true, $INK);
        $y += 13;
    }
    if (!empty($it['zertifikate'])) $y = spec_zeile($p, $y + 4, 'Zertifikate', (string)$it['zertifikate']);

    $y += 18;
    foreach ($p->wrap('Diese Spezifikation beschreibt den Rohstoff, wie er von uns eingesetzt und weitergegeben wird. '
                    . 'Die Analysenwerte der einzelnen Lieferung stehen im Analysenzertifikat (CoA) zur jeweiligen Charge.', $R - $L, 9, false) as $wl) {
        $p->text($L, $y, $wl, 9, false, $GRAY); $y += 12;
    }
    spec_fuss($p, $y + 20);
    return $p->output();
}

// ---------------------------------------------------------------------------
// Analysenzertifikat (CoA) zu einer Charge – aus den erfassten Analysewerten.
// ---------------------------------------------------------------------------
function build_coa_pdf(int $charge_id): ?string {
    $c = one("SELECT c.*, i.name AS item_name, i.spec_nr, i.spec_version, i.herkunftsland, i.allergene
              FROM charge c JOIN item i ON i.id=c.item_id WHERE c.id=?", [$charge_id]);
    if (!$c) return null;
    $INK = [44, 44, 42]; $GRAY = [95, 94, 90]; $LINE = [210, 208, 200];
    $L = 40; $R = 555;
    $p = new MiniPDF();
    $y = spec_kopf($p, 'Analysenzertifikat', 'Certificate of Analysis · ' . (string)$c['item_name']);

    $fmtD = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '–';
    $num  = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
    $y = spec_zeile($p, $y, 'Rohstoff', (string)$c['item_name']);
    $y = spec_zeile($p, $y, 'Charge', (string)($c['charge_nr'] ?: '–'));
    $y = spec_zeile($p, $y, 'Menge', $num($c['menge']) . ' ' . (string)($c['einheit'] ?? ''));
    $y = spec_zeile($p, $y, 'Wareneingang', $fmtD($c['wareneingang'] ?? ''));
    $y = spec_zeile($p, $y, 'Mindesthaltbar bis', $fmtD($c['mhd'] ?? ''));
    if (!empty($c['herkunftsland'])) $y = spec_zeile($p, $y, 'Herkunft', (string)$c['herkunftsland']);
    $y = spec_zeile($p, $y, 'Spezifikation', trim((string)($c['spec_nr'] ?? '') . ' ' . (string)($c['spec_version'] ?? '')) ?: '–');

    // Analysewerte
    $werte = all("SELECT * FROM charge_analyse WHERE charge_id=? ORDER BY sort, id", [$charge_id]);
    $y += 12;
    $p->text($L, $y, 'Analysenwerte', 11, true, $INK); $y += 6;
    $p->line($L, $y + 4, $R, $y + 4, 0.5, $LINE); $y += 8;
    $cPar = $L; $cSpec = 260; $cErg = 400; $cMet = 470;
    $p->text($cPar, $y + 10, 'Parameter', 8, true, $INK);
    $p->text($cSpec, $y + 10, 'Spezifikation', 8, true, $INK);
    $p->text($cErg, $y + 10, 'Ergebnis', 8, true, $INK);
    $p->text($cMet, $y + 10, 'Methode', 8, true, $INK);
    $p->line($L, $y + 15, $R, $y + 15, 0.8, $INK);
    $y += 19;
    foreach ($werte as $w) {
        if ($y > 760) { $p->addPage(); $y = 54; }
        $p->text($cPar, $y + 10, $p->fit((string)$w['parameter'], $cSpec - $cPar - 8, 9, false), 9, false, $INK);
        $p->text($cSpec, $y + 10, $p->fit((string)($w['spezifikation'] ?? '–'), $cErg - $cSpec - 8, 9, false), 9, false, $INK);
        $p->text($cErg, $y + 10, $p->fit((string)($w['ergebnis'] ?? '–'), $cMet - $cErg - 8, 9, true), 9, true, $INK);
        $p->text($cMet, $y + 10, $p->fit((string)($w['methode'] ?? ''), $R - $cMet, 8, false), 8, false, $GRAY);
        $p->line($L, $y + 15, $R, $y + 15, 0.4, $LINE);
        $y += 16;
    }
    if (!$werte) { $p->text($cPar, $y + 10, 'Für diese Charge sind noch keine Analysenwerte erfasst.', 9, false, $GRAY); $y += 16; }

    $y += 20;
    $freigabe = (string)$c['status'] === 'frei'
        ? 'Die Charge wurde geprüft und für die Verarbeitung freigegeben.'
        : 'Die Charge befindet sich in Quarantäne; die Freigabe steht noch aus.';
    foreach ($p->wrap($freigabe, $R - $L, 9, false) as $wl) { $p->text($L, $y, $wl, 9, false, $INK); $y += 12; }
    spec_fuss($p, $y + 20);
    return $p->output();
}
