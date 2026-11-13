<?php
// Chargenprotokoll je Unter-Charge (Nico 11.11.2026, plan-produktion-gefuehrt.md Teil 7). Gemeinsam für Dashboard und Werk-App
// (nur q/one/all/scalar, MiniPDF). Inhalt: verwendete Material-Chargen, Kapselgewichte, Teilmengen mit Zeit und Mitarbeiter, Kartons,
// Fotos (Linie leer, Stempel, Ware), Rückstellmuster, Abweichungen/Sperren, Abschluss. Nachweis für QS/Behörde und Prüfliste vor Versand.
require_once __DIR__ . '/lib/minipdf.php';

function pa_protokoll_daten(int $uc_id): ?array {
    $uc = pa_unter_charge($uc_id);
    if (!$uc) return null;
    $pa = one("SELECT pa.*, COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name, r.name) AS produkt, a.nummer AS auftrag_nr, k.firma AS kunde
               FROM produktionsauftrag pa LEFT JOIN auftrag a ON a.id=pa.auftrag_id LEFT JOIN produkt p ON p.id=pa.produkt_id
               LEFT JOIN rezeptur r ON r.id=pa.rezeptur_id LEFT JOIN kunden k ON k.id=COALESCE(a.kunde_id, pa.kunde_id) WHERE pa.id=?", [(int)$uc['pa_id']]) ?: [];
    $tm = all("SELECT * FROM pa_teilmenge WHERE unter_charge_id=? ORDER BY id", [$uc_id]);
    $tmIds = array_map(fn($t) => (int)$t['id'], $tm);
    // Regel Nico 13.11.2026: Lieferant im Chargenprotokoll nur als Lieferantennummer (L-xxxx), nie der Name.
    $verbrauch = $tmIds && pa_spalte_da('produktion_verbrauch', 'teilmenge_id')
        ? all("SELECT i.name, c.charge_nr, c.mhd, l.lieferantennummer AS lieferant, SUM(v.menge) AS menge, v.einheit FROM produktion_verbrauch v JOIN item i ON i.id=v.item_id
               LEFT JOIN charge c ON c.id=v.charge_id LEFT JOIN lieferanten l ON l.id=c.lieferant_id WHERE v.teilmenge_id IN (" . implode(',', $tmIds) . ")
               GROUP BY v.item_id, v.charge_id, v.einheit ORDER BY i.name", []) : [];
    return [
        'uc' => $uc, 'pa' => $pa, 'teilmengen' => $tm, 'verbrauch' => $verbrauch,
        'scans' => all("SELECT s.rolle, i.name, COALESCE(c.charge_nr, s.code) AS charge, s.zeit, s.von FROM pa_scan s LEFT JOIN item i ON i.id=s.item_id LEFT JOIN charge c ON c.id=s.charge_id WHERE s.unter_charge_id=? ORDER BY s.id", [$uc_id]),
        'ereignisse' => all("SELECT * FROM pa_ereignis WHERE unter_charge_id=? ORDER BY id", [$uc_id]),
        'kartons' => all("SELECT * FROM pa_karton WHERE unter_charge_id=? AND storniert_am IS NULL ORDER BY nummer", [$uc_id]),
        'proben' => $uc['fertig_charge_id'] ? all("SELECT anzahl, bezeichnung, angelegt FROM prod_probe WHERE charge_id=? AND ebene='endprodukt'", [(int)$uc['fertig_charge_id']]) : [],
    ];
}
function pa_protokoll_pdf(int $uc_id): ?string {
    $d = pa_protokoll_daten($uc_id);
    if (!$d) return null;
    $uc = $d['uc']; $pa = $d['pa'];
    $pdf = new MiniPDF();
    $mm = fn(float $v): float => $v / 25.4 * 72;
    $W = $pdf->w; $H = $pdf->h; $lx = $mm(16); $tw = $W - 2 * $lx;
    $dark = [30, 30, 30]; $muted = [115, 115, 115]; $line = [210, 210, 210]; $gruen = [29, 158, 117];
    $zeit = fn($utc) => $utc ? (new DateTime((string)$utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i') : '';
    $seite = 1; $y = $mm(18);
    $fuss = function () use ($pdf, $mm, $lx, $tw, $H, $muted, &$seite) {
        $pdf->text($lx, $H - $mm(10), $pdf->fit(pdf_hinweis_maschinell(), $tw - $mm(25), 7, false), 7, false, $muted);
        $pdf->textRight($lx + $tw, $H - $mm(10), 'Seite ' . $seite, 7, false, $muted);
    };
    $platz = function (float $h) use ($pdf, $mm, $H, &$y, &$seite, $fuss) { if ($y + $h > $H - $mm(20)) { $fuss(); $pdf->addPage(); $seite++; $y = $mm(18); } };
    $h2 = function (string $t) use ($pdf, $mm, $lx, &$y, $dark, $platz) { $platz($mm(14)); $y += $mm(4); $pdf->text($lx, $y, $t, 11, true, $dark); $y += $mm(6); };
    $zeile = function (string $t, array $c = [30, 30, 30], float $size = 8.5) use ($pdf, $mm, $lx, $tw, &$y, $platz) {
        foreach ($pdf->wrap($t, $tw, $size, false) as $ln) { $platz($mm(4.6)); $pdf->text($lx, $y, $ln, $size, false, $c); $y += $mm(4.4); } };

    $pdf->text($lx, $y, 'bulkify · Maniso GmbH', 8, false, $muted); $y += $mm(8);
    $pdf->text($lx, $y, 'Chargenprotokoll ' . $uc['charge_nr'], 18, true, $dark); $y += $mm(9);
    $bulk = $uc['bulk_charge_id'] ? (string) scalar("SELECT COALESCE(charge_nr, CONCAT('#',id)) FROM charge WHERE id=?", [(int)$uc['bulk_charge_id']])
          : ($uc['prod_charge_id'] ? 'Eimer ' . (string) scalar("SELECT CONCAT(nummer, COALESCE(sub_kennung,'')) FROM prod_charge WHERE id=?", [(int)$uc['prod_charge_id']]) : '–');
    foreach (['Produkt' => (string)($pa['produkt'] ?? ''), 'Kunde / Auftrag' => trim((string)($pa['kunde'] ?? '') . ' · ' . (string)($pa['auftrag_nr'] ?? ''), ' ·'),
              'Produktionsauftrag' => (string)($pa['nummer'] ?? ''), 'Kunden-Charge' => $uc['charge_nr'] . ($uc['charge_vorgabe'] ? ' (Vorgabe ' . $uc['charge_vorgabe'] . ', Grund: ' . $uc['abweichung_grund'] . ')' : ''),
              'MHD' => $uc['mhd'] ? date('d.m.Y', strtotime((string)$uc['mhd'])) : '–', 'Bulk-Charge / Eimer' => $bulk, 'Linie' => (string)($uc['linie'] ?: '–'),
              'Menge' => pa_mz(pa_uc_menge($uc_id)) . ' Stk', 'Status' => $uc['status'] === 'fertig' ? 'abgeschlossen ' . $zeit($uc['abgeschlossen_am']) : 'läuft'] as $k => $v) {
        $pdf->text($lx, $y, $k, 8.5, false, $muted); $pdf->text($lx + $mm(42), $y, $pdf->fit($v, $tw - $mm(42), 9, false), 9, false, $dark); $y += $mm(5);
    }
    $h2('Verwendete Material-Chargen');
    if ($d['verbrauch']) foreach ($d['verbrauch'] as $v) $zeile('· ' . $v['name'] . ' – Charge ' . ($v['charge_nr'] ?: '–') . ($v['lieferant'] ? ' (' . $v['lieferant'] . ')' : '') . ($v['mhd'] ? ', MHD ' . date('d.m.Y', strtotime((string)$v['mhd'])) : '') . ': ' . pa_mz($v['menge']) . ' ' . $v['einheit']);
    else $zeile('Kein Verbrauch je Teilmenge erfasst.', $muted);
    if ($d['scans']) { $zeile('Gescannt:', $muted); foreach ($d['scans'] as $s) $zeile('  ' . $zeit($s['zeit']) . ' · ' . $s['rolle'] . ' · ' . ($s['name'] ?: '') . ' · ' . $s['charge'] . ($s['von'] ? ' · ' . $s['von'] : ''), $muted, 7.5); }
    $kp = array_filter($d['ereignisse'], fn($e) => in_array($e['typ'], ['kapselprobe', 'sackgewicht'], true));
    if ($kp || $uc['kapsel_mg']) {
        $h2('Kapselgewichte');
        if ($uc['kapsel_mg']) $zeile('Ø-Kapselgewicht ' . pa_mz($uc['kapsel_mg']) . ' mg · Kapseln aus Sackgewicht ' . pa_mz($uc['kapseln_bulk'] ?? 0) . ' Stk');
        foreach ($kp as $e) $zeile('· ' . $zeit($e['zeit']) . ' · ' . $e['text'] . ($e['von'] ? ' · ' . $e['von'] : ''), $dark, 8);
    }
    $h2('Teilmengen');
    foreach ($d['teilmengen'] as $t) $zeile('· ' . $zeit($t['angelegt']) . ($t['art'] === 'nachtrag' ? ' (nachgetragen, Produktion ' . date('d.m.Y', strtotime((string)$t['datum'])) . ')' : '') . ' · ' . pa_mz($t['menge']) . ' Stk' . ($t['umfang'] === 'vor_etikett' ? ' bis vor dem Etikettieren' : '') . ' · Rückstellmuster ' . (int)$t['rueckstell'] . ($t['mitarbeiter'] ? ' · ' . $t['mitarbeiter'] : '') . ($t['geliefert'] ? ' · schon geliefert' : ''));
    if (!$d['teilmengen']) $zeile('Keine Teilmenge.', $muted);
    if ($d['kartons']) { $h2('Kartons'); $zeile(implode(' · ', array_map(fn($k) => 'K' . str_pad((string)$k['nummer'], 3, '0', STR_PAD_LEFT) . ' ' . pa_mz($k['menge']), $d['kartons'])) . ' – Summe ' . pa_mz(array_sum(array_column($d['kartons'], 'menge'))) . ' Stk'); }
    $rs = array_sum(array_map(fn($p) => (int)$p['anzahl'], $d['proben']));
    $h2('Rückstellmuster'); $zeile($rs > 0 ? $rs . ' Stk (je Unter-Charge eigene)' : 'keine erfasst', $rs > 0 ? $dark : $muted);
    $h2('Start, Chargenwechsel, Abweichungen, Abschluss');
    foreach ($d['ereignisse'] as $e) if (!in_array($e['typ'], ['kapselprobe', 'sackgewicht', 'teilmenge'], true))
        $zeile('· ' . $zeit($e['zeit']) . ' · ' . strtoupper((string)$e['typ']) . ': ' . $e['text'] . ($e['von'] ? ' · ' . $e['von'] : ''), in_array($e['typ'], ['abweichung', 'sperre'], true) ? [143, 35, 27] : $dark, 8);
    // Fotos (Linie leer, Stempel, Ware) – JPEG eingebettet, sonst Dateiname.
    $fotos = [];
    foreach ($d['ereignisse'] as $e) { if ($e['foto']) $fotos[] = [$e['foto'], in_array($e['typ'], ['start', 'chargenwechsel', 'wiederanlauf'], true) ? 'Linie leer · ' . $zeit($e['zeit']) : 'Ware · ' . $zeit($e['zeit'])]; if ($e['foto2']) $fotos[] = [$e['foto2'], 'Stempel · ' . $zeit($e['zeit'])]; }
    if ($fotos) {
        $h2('Fotos');
        $x = $lx; $bw = $mm(40); $bh = $mm(30);
        foreach ($fotos as [$f, $label]) {
            $pfad = pa_uploads() . '/' . basename((string)$f);
            $info = is_file($pfad) ? @getimagesize($pfad) : false;
            if ($x + $bw > $lx + $tw) { $x = $lx; $y += $bh + $mm(9); }
            $platz($bh + $mm(9));
            if ($info && ($info[2] ?? 0) === IMAGETYPE_JPEG) { $img = $pdf->registerJpeg((string)file_get_contents($pfad), (int)$info[0], (int)$info[1]); $pdf->drawImage($img, $x, $y, $bw, $bh); }
            else $pdf->rectStroke($x, $y, $bw, $bh, 0.5, $line);
            $pdf->text($x, $y + $bh + $mm(4), $pdf->fit($label, $bw, 6.5, false), 6.5, false, $muted);
            $x += $bw + $mm(4);
        }
        $y += $bh + $mm(9);
    }
    $fuss();
    return $pdf->output();
}
// PDF an Fertigware-Charge und PA ablegen (Dokument „chargenprotokoll“). Wird beim Schließen der Unter-Charge aufgerufen.
function pa_protokoll_ablegen(int $uc_id): int {
    try {
        $uc = pa_unter_charge($uc_id);
        if (!$uc) return 0;
        $pdf = pa_protokoll_pdf($uc_id);
        if (!$pdf) return 0;
        $dir = pa_uploads(); if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $datei = 'chargenprotokoll_' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$uc['charge_nr']) . '_' . bin2hex(random_bytes(4)) . '.pdf';
        if (@file_put_contents($dir . '/' . $datei, $pdf) === false) return 0;
        $titel = 'Chargenprotokoll ' . $uc['charge_nr'];
        $jetzt = gmdate('Y-m-d H:i:s'); $first = 0;
        foreach (array_filter([['charge', (int)$uc['fertig_charge_id']], ['produktionsauftrag', (int)$uc['pa_id']]], fn($x) => $x[1] > 0) as [$typ, $oid]) {
            q("INSERT INTO dokument (objekt_typ, objekt_id, typ, titel, datei, datei_orig, angelegt, kunde_sichtbar, charge_nr) VALUES (?,?,?,?,?,?,?,0,?)",
              [$typ, $oid, 'chargenprotokoll', $titel, $datei, $titel . '.pdf', $jetzt, (string)$uc['charge_nr']]);
            if (!$first) $first = insert_id();
        }
        if ($first) q("UPDATE pa_unter_charge SET protokoll_dokument_id=? WHERE id=?", [$first, $uc_id]);
        return $first;
    } catch (\Throwable $e) { error_log('pa_protokoll_ablegen: ' . $e->getMessage()); return 0; }
}
