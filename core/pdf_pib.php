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

// Tabelle mit UMBRECHENDEN Zellen (statt abzuschneiden wie spec_table) – für lange Zutaten-/Nährstoffnamen.
function pib_table(MiniPDF $p, float $y, array $colDefs, array $rows): float {
    $L = 40; $R = 555; $W = $R - $L; $hH = 20; $lh = 13;
    $head = function ($yy) use ($p, $colDefs, $L, $W) {
        $p->rect($L, $yy, $W, 20, SPEC_CHARCOAL);
        foreach ($colDefs as $c) $p->text($c[0] + 6, $yy + 13, $c[1], 8, true, SPEC_WHITE);
    };
    if ($y > 740) { $p->addPage(); $y = 48; }
    $y0 = $y; $head($y); $y += $hH; $i = 0;
    foreach ($rows as $r) {
        $wrapped = []; $maxLines = 1;
        foreach ($colDefs as $ci => $c) {
            $x = $c[0]; $nx = $colDefs[$ci + 1][0] ?? $R;
            $txt = ((string)($r[$ci] ?? '') !== '' ? (string)$r[$ci] : '–');
            $lines = $p->wrap($txt, $nx - $x - 10, 8.5, false) ?: [$txt];
            $wrapped[$ci] = $lines; $maxLines = max($maxLines, count($lines));
        }
        $rH = $maxLines * $lh + 8;
        if ($y + $rH > 805) { $p->rectStroke($L, $y0, $W, $y - $y0, 0.6, SPEC_LINE); $p->addPage(); $y = 48; $y0 = $y; $head($y); $y += $hH; }
        if ($i % 2 === 1) $p->rect($L, $y, $W, $rH, SPEC_ALT);
        foreach ($colDefs as $ci => $c) {
            $ly = $y + 12;
            foreach ($wrapped[$ci] as $wl) { $p->text($c[0] + 6, $ly, $wl, 8.5, false, SPEC_INK); $ly += $lh; }
        }
        $y += $rH; $i++;
    }
    $p->rectStroke($L, $y0, $W, $y - $y0, 0.6, SPEC_LINE);
    foreach ($colDefs as $ci => $c) if ($ci > 0) $p->line($c[0], $y0, $c[0], $y, 0.4, SPEC_LINE);
    return $y;
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
    if ($kg)             $ident[] = ['Kapselgröße', (string)$kg['name'] . ((float)($kg['volumen_ml'] ?? 0) > 0 ? ' · ' . $mg($kg['volumen_ml']) . ' ml' : '')];
    if ($einh > 0)       $ident[] = ['Einheiten pro Packung', number_format($einh, 0, ',', '.') . ' ' . ($formLbl !== '' ? $formLbl : 'Stück')];
    $ident[] = ['Erstellt am', (function_exists('fmt_zeit') ? fmt_zeit(gmdate('Y-m-d H:i:s'), 'd.m.Y, H:i') : date('d.m.Y, H:i')) . ' Uhr'];
    $y = spec_grid($p, $y, $ident);

    // Verpackung & Etikett (+ Leergewichte für die Brutto-Rechnung, + Etikettmaße für die Gestaltung)
    $verp   = !empty($prod['verpackung_id']) ? one("SELECT name, volumen_ml, gewicht_g, etikett_final FROM item WHERE id=?", [(int)$prod['verpackung_id']]) : null;
    $versch = !empty($prod['verschluss_id']) ? one("SELECT name, gewicht_g FROM item WHERE id=?", [(int)$prod['verschluss_id']]) : null;
    $etik   = !empty($prod['etikett_id'])    ? one("SELECT name, gewicht_g, breite_mm, hoehe_mm, etikett_format FROM item WHERE id=?", [(int)$prod['etikett_id']]) : null;
    // Etikettmaße: bevorzugt Endformat am Behälter, sonst Maße/Format des Etikett-Artikels.
    // Wickeletiketten (Glas/PET): die BREITE (Umfang) ist die größere Zahl, die HÖHE die kleinere –
    // deshalb Breite = max, Höhe = min (die Stammdaten speichern die zwei Maße uneinheitlich).
    $dims = etikett_masse((string)($verp['etikett_final'] ?? ''));
    if (!$dims && $etik) $dims = ($etik['breite_mm'] && $etik['hoehe_mm']) ? [(float)$etik['breite_mm'], (float)$etik['hoehe_mm']] : etikett_masse((string)$etik['etikett_format']);
    $emass = $dims ? [max($dims[0], $dims[1]), min($dims[0], $dims[1])] : null;   // [Breite, Höhe]
    $vp = [];
    if ($verp)   $vp[] = ['Behälter', (string)$verp['name'] . ((float)($verp['volumen_ml'] ?? 0) > 0 ? ' · ' . $mg($verp['volumen_ml']) . ' ml' : '')];
    if ($versch) $vp[] = ['Verschluss', (string)$versch['name']];
    if ($etik)   $vp[] = ['Etikett', (string)$etik['name']];
    if ($emass)  $vp[] = ['Etikettmaße (B × H)', $mg($emass[0]) . ' × ' . $mg($emass[1]) . ' mm'];
    if ($vp) {
        $y = spec_h($p, $y, 'Verpackung & Etikett'); $y = spec_grid($p, $y, $vp);
        $y += 12;
        $p->text($L, $y, $emass ? 'Endformat der Etikettendatei (Wickeletikett); Druckvorlage separat im Portal. Bitte 2–3 mm Beschnitt einplanen.' : 'Etikettmaße noch nicht hinterlegt – Druckvorlage separat im Portal.', 8, false, [110, 110, 108]); $y += 16;
    }

    // Gewichte: je Kapsel/Einheit, netto (Inhalt) und brutto (Gesamtgewicht der Packung).
    if ($sumMg > 0 || $einh > 0) {
        // Leerkapsel-Gewicht: bevorzugt der hinterlegte Leerkapsel-Artikel, sonst der Standardwert der Kapselgröße.
        seed_kapsel_leergewicht();
        $shellMg = 0.0; $shellQuelle = '';
        if ($istKapsel) {
            if (!empty($prod['leerkapsel_id'])) { $shellMg = (float) scalar("SELECT leergewicht_mg FROM item WHERE id=?", [(int)$prod['leerkapsel_id']]); if ($shellMg > 0) $shellQuelle = 'Leerkapsel-Artikel'; }
            if ($shellMg <= 0 && $kg && (float)($kg['leergewicht_mg'] ?? 0) > 0) { $shellMg = (float)$kg['leergewicht_mg']; $shellQuelle = 'Standardwert ' . (string)$kg['name']; }
        }
        $kapselTotalMg = $sumMg + $shellMg;                              // eine gefüllte Kapsel gesamt (mg)
        $nettoGesamtG  = $einh > 0 ? $kapselTotalMg * $einh / 1000 : 0.0; // alle Kapseln = Nettofüllmenge für die Verpackung
        $gw = [];
        $gw[] = [$istKapsel ? 'Füllgewicht je Kapsel (Wirkstoffe)' : 'Gewicht je Einheit', $mg($sumMg) . ' mg'];
        if ($istKapsel && $shellMg > 0) $gw[] = ['Leerkapsel (Hülle)', $mg($shellMg) . ' mg' . ($shellQuelle ? ' · ' . $shellQuelle : '')];
        if ($istKapsel && $shellMg > 0) $gw[] = ['Kapselgewicht gesamt (gefüllt)', $mg($kapselTotalMg) . ' mg'];
        if ($nettoGesamtG > 0) $gw[] = [$istKapsel ? 'Nettofüllmenge je Packung (für die Verpackung)' : 'Nettofüllmenge je Packung', $mg($nettoGesamtG) . ' g' . ($einh > 0 ? ' (' . number_format($einh, 0, ',', '.') . ' × ' . $mg($kapselTotalMg) . ' mg)' : '')];
        $y = spec_h($p, $y, 'Gewichte');
        $y = spec_grid($p, $y, $gw);
        // Kapsel-Kapazitaet je Dichte als Info (Nachschlagewerk-Bezug) + genutzte Misch-Dichte.
        if ($istKapsel && $kg && (int)($kg['fuell_typ_mg'] ?? 0) > 0) {
            $mixD = rezeptur_mix_dichte($rid);
            $y += 11;
            $p->text($L, $y, 'Kapsel-Kapazität ' . (string)$kg['name'] . ': ' . number_format((float)$kg['fuell_light_mg'], 0, ',', '.') . ' / ' . number_format((float)$kg['fuell_typ_mg'], 0, ',', '.') . ' / ' . number_format((float)$kg['fuell_heavy_mg'], 0, ',', '.') . ' mg (leicht/typisch/dicht)'
                . ($mixD ? ' · Rezeptur-Dichte ~' . $mg($mixD) . ' g/ml' : ' · Dichte unbekannt (Backup-Wert)'), 8, false, [110, 110, 108]); $y += 14;
        }
        if ($istKapsel && $shellMg <= 0) { $y += 11; $p->text($L, $y, 'Leerkapsel-Gewicht nicht hinterlegt – Nettofüllmenge zeigt nur das Füllgewicht ohne Hülle.', 8, false, [110, 110, 108]); $y += 14; }
    }

    // Kapselhülle als eigene Zutat: bei Kapseln gehört die Hülle (HPMC/Gelatine) ins Zutatenverzeichnis.
    $shellMg = $shellMg ?? 0.0;
    $huelleTxt = '';
    if ($istKapsel) {
        // Wir verwenden ausschliesslich HPMC-Kapseln -> Standard-Deklaration. (Nur falls am Leerkapsel-Artikel
        // ausdruecklich Gelatine hinterlegt ist, wird das uebernommen.)
        $huMat = !empty($prod['leerkapsel_id']) ? mb_strtolower((string) scalar("SELECT CONCAT(COALESCE(material,''),' ',name) FROM item WHERE id=?", [(int)$prod['leerkapsel_id']])) : '';
        $huelleTxt = (strpos($huMat, 'gelatine') !== false || strpos($huMat, 'gelatin') !== false)
            ? 'Gelatine (Kapselhülle)'
            : 'Überzugsmittel Hydroxypropylmethylcellulose (Kapselhülle)';
    }

    // Zutaten je Einheit – ABSTEIGEND nach Menge (= gesetzliche Reihenfolge fürs Zutatenverzeichnis) + Gesamt.
    if ($zut) {
        $zutSort = array_map(fn($z) => ['bezeichnung' => (string)$z['bezeichnung'], 'menge_mg' => (float)$z['menge_mg']], $zut);
        if ($huelleTxt !== '') $zutSort[] = ['bezeichnung' => $huelleTxt, 'menge_mg' => $shellMg];   // Hülle als Zutat mitführen
        usort($zutSort, fn($a, $b) => $b['menge_mg'] <=> $a['menge_mg']);
        $gesamtMg = $sumMg + ($huelleTxt !== '' ? $shellMg : 0.0);
        $rows = array_map(fn($z) => [$z['bezeichnung'], ($z['menge_mg'] > 0 ? $mg($z['menge_mg']) . ' mg' : '–')], $zutSort);
        $rows[] = ['Gesamt', $mg($gesamtMg) . ' mg'];
        $y = spec_h($p, $y, 'Zutaten (je ' . $einheitWort . ', absteigend nach Menge)');
        $y = pib_table($p, $y, [[$L, 'Zutat'], [400, 'Menge je ' . $einheitWort]], $rows);
        // Fertiges Zutatenverzeichnis fürs Etikett (Reihenfolge nach Menge, zum Kopieren).
        $y += 12;
        $p->text($L, $y, 'Zutatenverzeichnis für das Etikett:', 9, true); $y += 13;
        $verz = implode(', ', array_map(fn($z) => $z['bezeichnung'], $zutSort)) . '.';
        foreach ($p->wrap($verz, $R - $L, 9, false) as $wl) {
            if ($y > 780) { $p->addPage(); $y = 48; }
            $p->text($L, $y, $wl, 9, false, [70, 70, 68]); $y += 13;
        }
        $y += 10;
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
        $y = pib_table($p, $y, [[$L, 'Nährstoff'], [330, 'je ' . $einheitWort], [455, '% NRV*']], $rows);
        // Pflicht-Fußnote direkt unter der Tabelle (wie auf dem Etikett).
        $y += 12;
        $p->text($L, $y, '* NRV = Prozentsatz der Nährstoffbezugswerte (Referenzmenge) gemäß Verordnung (EU) Nr. 1169/2011.', 8, false, [90, 90, 88]); $y += 16;
    } else {
        $y = spec_h($p, $y, 'Nährwert-/Wirkstoffdeklaration');
        $y += 11;
        $p->text($L, $y, 'Für die eingesetzten Rohstoffe sind noch keine Wirkstoffgehalte/NRV hinterlegt.', 9, false, [110, 110, 108]); $y += 16;
    }

    // Zugelassene Angaben (Health Claims) für die enthaltenen Nährstoffe (EU 432/2012).
    $claims = health_claims_fuer_rezeptur($rid);
    if ($claims) {
        $y = spec_h($p, $y, 'Zugelassene Angaben (Health Claims, EU 432/2012)');
        $y += 11;   // Grundlinie eine Zeile absetzen, sonst überlappt der erste Punkt die Überschrift
        foreach ($claims as $c) {
            foreach ($p->wrap('• ' . (string)$c['claim'], $R - $L, 9, false) as $i => $wl) {
                if ($y > 780) { $p->addPage(); $y = 48; }
                $p->text($i === 0 ? $L : $L + 10, $y, $wl, 9, false, [60, 60, 58]); $y += 13;
            }
            $y += 3;
        }
        $y += 2;
        $p->text($L, $y, 'Nur verwendbar, wenn die signifikante Menge (i. d. R. 15 % NRV je Tagesdosis) erreicht ist.', 8, false, [110, 110, 108]); $y += 16;
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

    // Pflichtangaben, die der Kunde auf das Etikett bringen MUSS (LMIV/VO 1169/2011 + NemV) – vorbefüllt, wo bekannt.
    $nettoTxt = (isset($nettoGesamtG) && $nettoGesamtG > 0)
        ? $mg($nettoGesamtG) . ' g' . ($einh > 0 ? ' (' . number_format($einh, 0, ',', '.') . ' ' . ($formLbl !== '' ? $formLbl : 'Stück') . ')' : '')
        : '____ (Nettofüllmenge eintragen)';
    $verzehrTxt = $proTag > 0
        ? number_format($proTag, 0, ',', '.') . ' ' . ($istKapsel ? ($proTag === 1 ? 'Kapsel' : 'Kapseln') : ($formLbl !== '' ? $formLbl : 'Einheiten')) . ' täglich mit ausreichend Flüssigkeit'
        : '____ (z. B. 1 Kapsel täglich mit ausreichend Flüssigkeit)';
    $pflicht = [
        'Bezeichnung: „Nahrungsergänzungsmittel" (ggf. ergänzt um die namensgebenden Nährstoffe).',
        'Nettofüllmenge: ' . $nettoTxt . '.',
        'Verzehrempfehlung: ' . $verzehrTxt . '.',
        'Die angegebene empfohlene tägliche Verzehrmenge darf nicht überschritten werden.',
        'Nahrungsergänzungsmittel sind kein Ersatz für eine ausgewogene und abwechslungsreiche Ernährung und eine gesunde Lebensweise.',
        'Außerhalb der Reichweite von kleinen Kindern aufbewahren.',
        'Kühl, trocken und lichtgeschützt lagern.',
        'Auf das Etikett gehört der Text „Mindestens haltbar bis: siehe Boden" (MHD und Charge bringen wir auf dem Boden auf).',
        'Verantwortlicher Lebensmittelunternehmer: Name und Anschrift angeben.',
    ];
    $y += 6;
    if ($y > 700) { $p->addPage(); $y = 48; }
    $y = spec_h($p, $y, 'Pflichtangaben für Ihr Etikett');
    $y += 11;   // Grundlinie eine Zeile absetzen (sonst überlappt der erste Punkt die Überschrift)
    foreach ($pflicht as $t) {
        foreach ($p->wrap('• ' . $t, $R - $L, 9, false) as $i => $wl) {
            if ($y > 785) { $p->addPage(); $y = 48; }
            $p->text($i === 0 ? $L : $L + 10, $y, $wl, 9, false, [60, 60, 58]); $y += 13;
        }
        $y += 2;
    }
    $y += 6;
    $p->text($L, $y, 'Fertige Etikettendatei bitte im Kundenportal hochladen.', 8, false, [110, 110, 108]); $y += 14;
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
