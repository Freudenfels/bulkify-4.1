<?php
// Produktinformationsblatt (PIB) fürs Kundenportal – damit der Kunde sein Etikett gestalten kann.
// Zwei Quellen: ein vom Team je Produkt hochgeladenes PIB hat Vorrang; sonst wird automatisch eines
// aus den vorhandenen Produktdaten erzeugt (Zutaten + Nährwert-/Wirkstoffdeklaration + Darreichung).
// WICHTIG: kundensicher – es darf NIE ein Hinweis auf Zukauf/Bulk erscheinen (siehe Memory).
require_once __DIR__ . '/lib/minipdf.php';
require_once __DIR__ . '/pdf_spec.php';   // spec_kopf/spec_grid/spec_h/spec_table/spec_fuss wiederverwenden
require_once __DIR__ . '/schema.php';

// Nährwert-/Wirkstoffdeklaration je Einheit aus einer Rezeptur (wie im Portal, aber core-lokal).
function pib_naehr(int $rezeptur_id): array {
    if ($rezeptur_id <= 0) return [];
    $n = [];
    foreach (all("SELECT z.menge_mg, iw.gehalt_prozent, na.name, na.nrv_wert, na.einheit
                  FROM rezeptur_zutat z JOIN item_wirkstoff iw ON iw.item_id=z.item_id
                  JOIN naehrstoff na ON na.id=iw.naehrstoff_id
                  WHERE z.rezeptur_id=? AND iw.gehalt_prozent IS NOT NULL", [$rezeptur_id]) as $w) {
        $mgN = (float)$w['menge_mg'] * (float)$w['gehalt_prozent'] / 100;
        if (!isset($n[$w['name']])) $n[$w['name']] = ['name'=>$w['name'], 'mg'=>0.0, 'nrv'=>$w['nrv_wert'], 'einheit'=>$w['einheit']];
        $n[$w['name']]['mg'] += $mgN;
    }
    return array_values($n);
}

// Ein vom Team hochgeladenes PIB je Produkt (Vorrang vor dem Auto-PIB).
function pib_datei(int $produkt_id): ?array {
    return one("SELECT * FROM dokument WHERE objekt_typ='produkt' AND objekt_id=? AND typ='pib' ORDER BY id DESC LIMIT 1", [$produkt_id]);
}
function pib_upload(int $produkt_id, string $feld = 'pib'): bool {
    if ($produkt_id <= 0 || empty($_FILES[$feld]['name']) || ($_FILES[$feld]['error'] ?? 1) !== UPLOAD_ERR_OK) return false;
    if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
    $orig = $_FILES[$feld]['name'];
    $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
    if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg'], true)) return false;
    $fn = 'produkt_' . $produkt_id . '_pib_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$feld]['tmp_name'], BX_UPLOADS . '/' . $fn)) return false;
    q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig) VALUES ('produkt',?,?,?,?,?)",
      [$produkt_id, 'pib', 'Produktinformationsblatt', $fn, $orig]);
    return true;
}
function pib_del(int $produkt_id): void {
    $d = pib_datei($produkt_id);
    if ($d) { @unlink(BX_UPLOADS . '/' . basename((string)$d['datei'])); q("DELETE FROM dokument WHERE id=? AND typ='pib'", [(int)$d['id']]); }
}

// Auto-PIB als PDF-Bytes aus den vorhandenen Produktdaten. Null, wenn das Produkt fehlt.
function pib_pdf_bauen(int $produkt_id): ?string {
    $prod = one("SELECT p.*, COALESCE(NULLIF(p.kundenname,''), p.name) AS anzeige, r.darreichungsform, r.id AS rez_id
                 FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$produkt_id]);
    if (!$prod) return null;
    $L = 40; $R = 555;
    $mg = fn($x) => rtrim(rtrim(number_format((float)$x, 2, ',', '.'), '0'), ',');
    $formLbl = ['kapsel'=>'Kapseln','tablette'=>'Tabletten','softgel'=>'Softgels','stick'=>'Sticks',
                'pulver'=>'Pulver','granulat'=>'Granulat','fluessig'=>'Flüssig','gel'=>'Gel','gummi'=>'Fruchtgummi'][$prod['darreichungsform'] ?? ''] ?? (string)($prod['darreichungsform'] ?? '');
    $einh = (int)($prod['einheiten_pro_packung'] ?? 0);

    $p = new MiniPDF();
    $y = spec_kopf($p, 'PRODUKTINFORMATIONSBLATT', 'Grundlage für Ihre Etikettengestaltung · ' . (string)$prod['anzeige']);

    // Rezeptur/Zutaten + Kennzahlen vorab laden.
    $rid = (int)($prod['rez_id'] ?? 0);
    $zut = $rid ? all("SELECT bezeichnung, menge_mg FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [$rid]) : [];
    $sumMg = 0.0; foreach ($zut as $z) $sumMg += (float)$z['menge_mg'];
    $istKapsel = in_array($prod['darreichungsform'] ?? '', ['kapsel', 'softgel'], true);
    $einheitWort = $istKapsel ? 'Kapsel' : 'Einheit';
    $kg = ($istKapsel && $rid) ? rezeptur_kapselgroesse($rid) : null;

    // Identität
    $ident = [['Produkt', (string)$prod['anzeige']]];
    if ($formLbl !== '') $ident[] = ['Darreichungsform', $formLbl];
    if ($kg)             $ident[] = ['Kapselgröße', (string)$kg['name'] . ' (fasst bis ' . number_format((float)$kg['fuellmenge_mg'], 0, ',', '.') . ' mg)'];
    if ($einh > 0)       $ident[] = ['Einheiten pro Packung', number_format($einh, 0, ',', '.') . ' ' . ($formLbl !== '' ? $formLbl : 'Stück')];
    $ident[] = ['Stand', date('d.m.Y')];
    $y = spec_grid($p, $y, $ident);

    // Verpackung & Etikett (inkl. Etikettengröße B x H für die Gestaltung)
    $verp   = !empty($prod['verpackung_id']) ? one("SELECT name, volumen_ml, etikett_final FROM item WHERE id=?", [(int)$prod['verpackung_id']]) : null;
    $versch = !empty($prod['verschluss_id']) ? (string) scalar("SELECT name FROM item WHERE id=?", [(int)$prod['verschluss_id']]) : '';
    $etik   = !empty($prod['etikett_id'])    ? one("SELECT name, breite_mm, hoehe_mm, etikett_format FROM item WHERE id=?", [(int)$prod['etikett_id']]) : null;
    $emass  = etikett_masse((string)($verp['etikett_final'] ?? ''));
    if (!$emass && $etik) $emass = ($etik['breite_mm'] && $etik['hoehe_mm']) ? [(float)$etik['breite_mm'], (float)$etik['hoehe_mm']] : etikett_masse((string)$etik['etikett_format']);
    $vp = [];
    if ($verp)          $vp[] = ['Behälter', (string)$verp['name'] . ((float)($verp['volumen_ml'] ?? 0) > 0 ? ' · ' . $mg($verp['volumen_ml']) . ' ml' : '')];
    if ($versch !== '') $vp[] = ['Verschluss', $versch];
    if ($etik)          $vp[] = ['Etikett', (string)$etik['name']];
    if ($emass)         $vp[] = ['Etikettengröße (B × H)', $mg($emass[0]) . ' × ' . $mg($emass[1]) . ' mm'];
    if ($vp) { $y = spec_h($p, $y, 'Verpackung & Etikett'); $y = spec_grid($p, $y, $vp); }

    // Etikett-Maßskizze (wir kennen die Größe -> als kleine Druckvorlage-Skizze mit Maßen)
    if ($emass) {
        $bw = (float)$emass[0]; $bh = (float)$emass[1];   // mm
        if ($y > 610) { $p->addPage(); $y = 48; }
        $y = spec_h($p, $y, 'Etikett-Maße (Druckvorlage)');
        $sc = 150.0 / max($bw, $bh, 1); $wpt = $bw * $sc; $hpt = $bh * $sc;
        $bx = $L + 24; $by = $y + 6;
        $p->rectStroke($bx, $by, $wpt, $hpt, 0.8, [120, 120, 118]);
        $p->textCenter($bx + $wpt / 2, $by + $hpt / 2 + 3, 'Etikett', 8, false, [160, 160, 158]);
        // Breite (unten) + Höhe (rechts) bemaßen
        $p->line($bx, $by + $hpt + 8, $bx + $wpt, $by + $hpt + 8, 0.5, [150, 150, 148]);
        $p->textCenter($bx + $wpt / 2, $by + $hpt + 20, 'Breite ' . $mg($bw) . ' mm', 9, false, [90, 90, 88]);
        $p->line($bx + $wpt + 8, $by, $bx + $wpt + 8, $by + $hpt, 0.5, [150, 150, 148]);
        $p->text($bx + $wpt + 14, $by + $hpt / 2 + 3, 'Höhe ' . $mg($bh) . ' mm', 9, false, [90, 90, 88]);
        $y = $by + $hpt + 30;
        $p->text($L, $y, 'Endformat der Druckdatei (Breite × Höhe). Bitte 2–3 mm Beschnitt einplanen.', 8, false, [110, 110, 108]);
        $y += 18;
    }

    // Gewichte je Einheit / je Packung
    if ($sumMg > 0) {
        $gw = [[($istKapsel ? 'Füllgewicht je Kapsel' : 'Wirkstoffgewicht je Einheit'),
                $mg($sumMg) . ' mg' . ($sumMg >= 1000 ? ' · ' . $mg($sumMg / 1000) . ' g' : '')]];
        if ($einh > 0) $gw[] = ['Netto-Füllgewicht je Packung (Wirkstoffe)', $mg($sumMg * $einh / 1000) . ' g'];
        $y = spec_h($p, $y, 'Gewichte');
        $y = spec_grid($p, $y, $gw);
    }

    // Zutaten je Einheit – ABSTEIGEND nach Menge (= gesetzliche Reihenfolge fürs Zutatenverzeichnis) + Gesamt.
    if ($zut) {
        $zutSort = $zut;
        usort($zutSort, fn($a, $b) => (float)$b['menge_mg'] <=> (float)$a['menge_mg']);
        $rows = array_map(fn($z) => [(string)$z['bezeichnung'], $mg($z['menge_mg']) . ' mg'], $zutSort);
        $rows[] = ['Gesamt', $mg($sumMg) . ' mg'];
        $y = spec_h($p, $y, 'Zutaten (je ' . $einheitWort . ', absteigend nach Menge)');
        $y = spec_table($p, $y, [[$L, 'Zutat'], [400, 'Menge je ' . $einheitWort]], $rows);
        // Fertige Zutatenverzeichnis-Zeile fürs Etikett (Reihenfolge nach Menge, zum Kopieren).
        $verz = 'Zutaten: ' . implode(', ', array_map(fn($z) => (string)$z['bezeichnung'], $zutSort)) . '.';
        $y += 4;
        foreach ($p->wrap($verz, $R - $L, 9, false) as $wl) {
            if ($y > 780) { $p->addPage(); $y = 48; }
            $p->text($L, $y, $wl, 9, false, [70, 70, 68]); $y += 12;
        }
        $y += 6;
    }

    // Nährwert-/Wirkstoffdeklaration je Einheit (Name · Menge · % NRV)
    $nutr = pib_naehr($rid);
    if ($nutr) {
        $rows = [];
        foreach ($nutr as $n) {
            $betr = ($n['einheit'] === 'µg') ? $mg($n['mg'] * 1000) . ' µg' : $mg($n['mg']) . ' mg';
            $pct = '–';
            if ($n['nrv'] !== null && $n['nrv'] !== '') {
                $nrvMg = $n['einheit'] === 'µg' ? (float)$n['nrv'] / 1000 : (float)$n['nrv'];
                if ($nrvMg > 0) $pct = number_format($n['mg'] / $nrvMg * 100, 0, ',', '.') . ' %';
            }
            $rows[] = [(string)$n['name'], $betr, $pct];
        }
        $y = spec_h($p, $y, 'Nährwert-/Wirkstoffdeklaration (je ' . $einheitWort . ')');
        $y = spec_table($p, $y, [[$L, 'Nährstoff'], [330, 'je ' . $einheitWort], [455, '% NRV*']], $rows);
    } else {
        $y = spec_h($p, $y, 'Nährwert-/Wirkstoffdeklaration');
        $y += 2;
        $p->text($L, $y, 'Für die eingesetzten Rohstoffe sind noch keine Wirkstoffgehalte/NRV hinterlegt.', 9, false, [110, 110, 108]); $y += 16;
    }

    // Deklaration (Allergene / vegan / GVO) – aus den verknüpften Rohstoffen abgeleitet.
    $allerg = []; $vegF = []; $gvoF = [];
    foreach ($rid ? all("SELECT z.item_id, i.allergene, i.vegan, i.gvo_frei FROM rezeptur_zutat z LEFT JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=?", [$rid]) : [] as $z) {
        if (!$z['item_id']) continue;
        $al = trim((string)$z['allergene']);
        if ($al !== '' && mb_stripos($al, 'keine') === false) $allerg[] = $al;
        $vegF[] = $z['vegan']; $gvoF[] = $z['gvo_frei'];
    }
    // Nur behaupten, wenn ALLE Rohstoffe bekannt & konform sind (sonst weglassen).
    $aggFlag = function ($flags) { $known = array_filter($flags, fn($x) => $x !== null && $x !== ''); if (!$known || count($known) < count($flags)) return null; foreach ($known as $f) if ((int)$f === 0) return false; return true; };
    $decl = [];
    $prodAll = trim((string)($prod['allergene'] ?? ''));
    $decl[] = ['Allergene', $prodAll !== '' ? $prodAll : ($allerg ? implode(', ', array_values(array_unique($allerg))) : 'keine deklarationspflichtigen Allergene')];
    $vv = $aggFlag($vegF); if ($vv !== null) $decl[] = ['Vegan', $vv ? 'ja' : 'nein'];
    $gg = $aggFlag($gvoF); if ($gg !== null) $decl[] = ['GVO-frei', $gg ? 'ja' : 'nein'];
    $y = spec_h($p, $y, 'Deklaration');
    $y = spec_grid($p, $y, $decl);

    // Verzehrempfehlung + Nährwerte je Tagesdosis (wenn Einnahme/Tag am Produkt gepflegt).
    $proTag = (int)($prod['einnahme_pro_tag'] ?? 0);
    if ($proTag > 0) {
        $vz = [['Verzehrempfehlung', number_format($proTag, 0, ',', '.') . ' ' . ($istKapsel ? ($proTag === 1 ? 'Kapsel' : 'Kapseln') : ($formLbl !== '' ? $formLbl : 'Einheiten')) . ' pro Tag']];
        if ($sumMg > 0) $vz[] = ['Wirkstoffe je Tagesdosis', $mg($sumMg * $proTag) . ' mg' . ($sumMg * $proTag >= 1000 ? ' · ' . $mg($sumMg * $proTag / 1000) . ' g' : '')];
        $y = spec_h($p, $y, 'Verzehrempfehlung');
        $y = spec_grid($p, $y, $vz);
    }

    // Hinweis für die Etikettengestaltung
    $y += 14;
    $hinweis = 'Dieses Produktinformationsblatt fasst die für Ihr Etikett relevanten Angaben zusammen. '
             . 'Bitte ergänzen Sie auf dem Etikett die gesetzlich vorgeschriebenen Pflichtangaben (u. a. Nettofüllmenge, '
             . 'Verzehrempfehlung, Aufbewahrungshinweis, Warnhinweise, verantwortlicher Lebensmittelunternehmer, Los-/Chargenkennzeichnung, MHD). '
             . '*NRV = Nährstoffbezugswert (soweit vorhanden). Fertige Etikettendatei bitte im Kundenportal hochladen.';
    foreach ($p->wrap($hinweis, $R - $L, 9, false) as $wl) {
        if ($y > 780) { $p->addPage(); $y = 48; }
        $p->text($L, $y, $wl, 9, false, [110, 110, 108]); $y += 12;
    }
    spec_fuss($p, $y + 16);
    return $p->output();
}

// PIB ausliefern: hochgeladenes PIB (Vorrang) sonst Auto-PIB. Setzt Header + gibt Body aus. false = nichts da.
function pib_ausliefern(int $produkt_id, string $dateiname = 'Produktinformationsblatt'): bool {
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $dateiname) ?: 'Produktinformationsblatt';
    $d = pib_datei($produkt_id);
    if ($d) {
        $pf = BX_UPLOADS . '/' . basename((string)$d['datei']);
        if (is_file($pf)) {
            $ext = strtolower(pathinfo($pf, PATHINFO_EXTENSION));
            header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext)));
            header('Content-Disposition: inline; filename="' . $safe . '.' . ($ext ?: 'pdf') . '"');
            header('Content-Length: ' . filesize($pf));
            readfile($pf);
            return true;
        }
    }
    $pdf = pib_pdf_bauen($produkt_id);
    if ($pdf === null) return false;
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $safe . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    return true;
}
