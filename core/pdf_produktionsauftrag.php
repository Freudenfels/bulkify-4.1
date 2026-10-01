<?php
// Produktionsauftrag / Laufzettel als PDF – fürs Werk (kein Preis, keine USt).
// Zeigt: Produkt, Mengen (Packungen · Stück je Packung · Gesamt), Darreichung + Größe, Charge + MHD,
// Verpackung/Etikett, Rezeptur (Zutat · mg je Einheit · Gesamt benötigt) und die Schritt-Checkliste.
require_once __DIR__ . '/lib/minipdf.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/pdf_beleg.php';   // beleg_firma() + Logo-Wiederverwendung

function produktionsauftrag_pdf_bauen(int $pa_id): ?string {
    $pa = one("SELECT pa.*, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt_name, p.einheiten_pro_packung,
                      p.etikett_id, p.rezeptur_id AS p_rezeptur_id,
                      r.name AS rezeptur_name, r.darreichungsform, k.firma AS kunde_firma
               FROM produktionsauftrag pa
               LEFT JOIN produkt p ON p.id=pa.produkt_id
               LEFT JOIN rezeptur r ON r.id=COALESCE(pa.rezeptur_id, p.rezeptur_id)
               LEFT JOIN kunden k ON k.id=pa.kunde_id
               WHERE pa.id=?", [$pa_id]);
    if (!$pa) return null;

    $istBulk = empty($pa['produkt_id']) && !empty($pa['rezeptur_id']);
    $form    = (string)($pa['darreichungsform'] ?? '');
    $stkWort = in_array($form, ['kapsel','softgel'], true) ? 'Kapseln' : ($form === 'tablette' ? 'Tabletten' : 'Stück');
    $einhProP = (int)($pa['einheiten_pro_packung'] ?? 0);
    $menge    = (int)($pa['menge'] ?? 0);
    $gesamt   = $istBulk ? $menge : ($einhProP > 0 ? $menge * $einhProP : 0);
    $produktName = (string)($pa['produkt_name'] ?? '') ?: ((string)($pa['rezeptur_name'] ?? '') . ($istBulk ? ' · Bulk' : ''));
    if (trim($produktName) === '') $produktName = 'Produkt';
    $groesse = !empty($pa['produkt_id']) ? produktion_groesse_label((int)$pa['produkt_id']) : '';

    // Charge + MHD (wie auf der Detailseite): gebuchte Charge, sonst die geplante Standardnummer.
    $fCharge  = one("SELECT charge_nr, mhd FROM charge WHERE pa_id=? ORDER BY id LIMIT 1", [$pa_id]);
    $chargeNr = $fCharge ? (string)$fCharge['charge_nr'] : charge_naechste_nr($pa_id);
    $chargeMhd = ($fCharge && $fCharge['mhd']) ? (string)$fCharge['mhd'] : mhd_standard();

    $verpName = !empty($pa['verpackung_id']) ? (string) scalar("SELECT name FROM item WHERE id=?", [(int)$pa['verpackung_id']]) : '';
    $etikettName = !empty($pa['etikett_id']) ? (string) scalar("SELECT name FROM item WHERE id=?", [(int)$pa['etikett_id']]) : '';
    $artLbl = ($pa['produktionsart'] ?? 'eigen') === 'fremd' ? 'Fremdproduktion (Zukauf)' : 'Eigenproduktion';
    $statusLbl = match ((string)($pa['status'] ?? '')) { 'laufend'=>'läuft', 'erledigt'=>'fertig', default=>'offen' };

    // Rezeptur-Zutaten + Gesamtbedarf.
    $rid = (int)($pa['rezeptur_id'] ?? 0) ?: (int)($pa['p_rezeptur_id'] ?? 0);
    $zutaten = $rid ? all("SELECT z.menge_mg, COALESCE(NULLIF(z.bezeichnung,''), i.name) AS name, i.einheit
                           FROM rezeptur_zutat z LEFT JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=? ORDER BY z.sort, z.id", [$rid]) : [];
    $bedarf = produktion_materialbedarf($pa_id);
    $bedarfMap = []; foreach ($bedarf as $b) $bedarfMap[mb_strtolower((string)$b['name'])] = $b;

    $schritte = all("SELECT station, erledigt FROM produktion_schritt WHERE pa_id=? ORDER BY sort, id", [$pa_id]);

    // ---------- PDF ----------
    $INK = [44,44,42]; $GRAY = [95,94,90]; $GOLD = [184,146,58]; $LINE = [210,208,200]; $BOXBG = [245,244,240];
    $p = new MiniPDF();
    $L = 40; $R = 555;
    $mg = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');

    // Logo
    $logoImg = null; $lp = BX_ROOT . '/assets/bulkify-logo.jpg';
    if (is_file($lp)) { $d = @file_get_contents($lp); $s = @getimagesize($lp); if ($d && $s) $logoImg = ['data'=>$d,'w'=>$s[0],'h'=>$s[1]]; }
    if ($logoImg) {
        $id = $p->registerJpeg($logoImg['data'], $logoImg['w'], $logoImg['h']);
        $rr = $logoImg['h'] / max(1, $logoImg['w']); $w = 130; $hh = $w * $rr; if ($hh > 52) { $hh = 52; $w = $hh / max(0.01,$rr); }
        $p->drawImage($id, $R - (int)$w, 34, (int)$w, (int)$hh);
    }

    $p->text($L, 56, 'Produktionsauftrag', 20, true, $INK);
    $p->text($L, 76, $pa['nummer'] . '  ·  ' . $artLbl . '  ·  Status: ' . $statusLbl, 10, false, $GRAY);

    // Charge/MHD-Box (prominent)
    $by = 92; $bx1 = 330; $p->line($bx1, $by, $R, $by, 0.6, $LINE);
    $p->text($bx1, $by + 14, 'Charge', 8, false, $GRAY);
    $p->text($bx1, $by + 30, $chargeNr, 14, true, $INK);
    $p->textRight($R, $by + 14, 'MHD', 8, false, $GRAY);
    $p->textRight($R, $by + 30, $chargeMhd ? date('d.m.Y', strtotime($chargeMhd)) : '–', 14, true, $INK);

    // Eckdaten-Grid
    $y = 150;
    $p->line($L, $y - 8, $R, $y - 8, 0.8, $INK);
    $zeile = function(string $k, string $v) use ($p, $L, $INK, $GRAY, &$y) {
        $p->text($L, $y, $k, 9, false, $GRAY);
        foreach ($p->wrap($v !== '' ? $v : '–', 360, 11, true) as $i => $wl) { $p->text($L + 150, $y, $wl, 11, true, $INK); if ($i === 0) {} $y += 15; }
        $y += 3;
    };
    $zeile('Produkt', $produktName);
    if (!empty($pa['kunde_firma'])) $zeile('Kunde', (string)$pa['kunde_firma']);
    $zeile('Darreichung', ($form !== '' ? ucfirst($form) : '–') . ($groesse !== '' ? ' · ' . $groesse : ''));
    $zeile('Menge', number_format($menge, 0, ',', '.') . ' Packungen'
        . ($einhProP > 0 ? '  ·  ' . number_format($einhProP, 0, ',', '.') . ' ' . $stkWort . ' je Packung' : '')
        . ($gesamt > 0 ? '  ·  gesamt ' . number_format($gesamt, 0, ',', '.') . ' ' . $stkWort : ''));
    if ($verpName !== '')    $zeile('Verpackung', $verpName);
    if ($etikettName !== '') $zeile('Etikett', $etikettName);
    if (!empty($pa['geplant_am'])) $zeile('Geplant am', date('d.m.Y', strtotime((string)$pa['geplant_am'])));

    // Rezeptur
    $y += 8;
    $p->text($L, $y, 'Rezeptur (Zusammensetzung)', 12, true, $INK); $y += 6;
    $cA = $L; $cB = 330; $cC = $R;
    $p->text($cA, $y + 10, 'Zutat', 8, true, $INK);
    $p->textRight($cB, $y + 10, 'mg je ' . rtrim($stkWort, 'ns'), 8, true, $INK);
    $p->textRight($cC, $y + 10, 'Gesamt benötigt', 8, true, $INK);
    $p->line($L, $y + 14, $R, $y + 14, 0.6, $INK); $y += 18;
    if (!$zutaten) { $p->text($cA, $y + 8, 'Keine Zutaten hinterlegt.', 9, false, $GRAY); $y += 14; }
    foreach ($zutaten as $z) {
        if ($y > 770) { $p->addPage(); $y = 54; }
        $b = $bedarfMap[mb_strtolower((string)$z['name'])] ?? null;
        $ges = $b ? ($mg($b['benoetigt']) . ' ' . ($b['einheit'] ?: 'kg')) : ($gesamt > 0 ? $mg((float)$z['menge_mg'] * $gesamt / 1e6) . ' kg' : '–');
        $p->text($cA, $y + 8, $p->fit((string)$z['name'], $cB - $cA - 70, 9, false), 9, false, $INK);
        $p->textRight($cB, $y + 8, $mg($z['menge_mg']) . ' mg', 9, false, $INK);
        $p->textRight($cC, $y + 8, $ges, 9, true, $INK);
        $y += 13; $p->line($L, $y, $R, $y, 0.3, $LINE);
    }

    // Produktionsschritte als Checkliste
    $y += 14;
    if ($y > 720) { $p->addPage(); $y = 54; }
    $p->text($L, $y, 'Produktionsschritte', 12, true, $INK); $y += 10;
    if (!$schritte) { $p->text($L, $y + 6, 'Keine Schritte hinterlegt.', 9, false, $GRAY); $y += 14; }
    foreach ($schritte as $s) {
        if ($y > 790) { $p->addPage(); $y = 54; }
        $done = (int)$s['erledigt'] === 1;
        $boxTop = $y; $p->line($L, $boxTop, $L + 12, $boxTop, 1, $INK);         // Kästchen
        $p->line($L, $boxTop, $L, $boxTop - 11, 1, $INK); $p->line($L + 12, $boxTop, $L + 12, $boxTop - 11, 1, $INK);
        $p->line($L, $boxTop - 11, $L + 12, $boxTop - 11, 1, $INK);
        if ($done) $p->text($L + 2, $boxTop - 1, 'x', 10, true, $INK);
        $p->text($L + 22, $boxTop - 1, (string)$s['station'], 10, false, $INK);
        $y += 20;
    }

    // Fußzeile
    $fa = beleg_firma();
    $p->textCenter(($L + $R) / 2, 828, $p->fit('Interner Produktionsauftrag · ' . $fa['name'] . ' · gedruckt ' . date('d.m.Y H:i'), $R - $L, 7, false), 7, false, $GRAY);
    return $p->output();
}

function produktionsauftrag_pdf_ausliefern(int $pa_id, string $nummer): bool {
    $pdf = produktionsauftrag_pdf_bauen($pa_id);
    if ($pdf === null) return false;
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Produktionsauftrag_' . preg_replace('/[^A-Za-z0-9_-]/', '', $nummer) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf;
    return true;
}
