<?php
// Zusammenfassendes CoA einer FERTIGWARE-Charge (Nico 03.11.2026): eine Übersicht aus den Analysen der tatsächlich
// eingesetzten Rohstoff-Chargen und den Produktdaten – NICHT am Fertigprodukt gemessen. Die Rohstoffe werden stich-
// probenhaft geprüft; eine Fertigprodukt-Laboranalyse ist ein separater Zusatz. Pflicht (nicht wegkonfigurierbar):
// ehrliche Kennzeichnung im Kopf, Methode je berechneter Zeile, Quellchargen genannt, claims-neutral.
//  - Schwermetalle (Pb, Cd, Hg, As): Massenbilanz auf das Füllgut = Σ (Rohstoffwert × Massenanteil der Zutat).
//    Rohstoffwert = Messwert der verbrauchten Charge (charge_analyse; mehrere Chargen -> der höchste), sonst der
//    Rohstoff-Grenzwert (item_grenzwert, „Grenzwert/worst case"). Fehlt für eine Zutat beides -> „nicht berechenbar"
//    (kein geschönter Teilwert).
//  - Mikrobiologie/prozessabhängige Werte: NIE hochgerechnet – nur als Rohstoff-Stichprobe je Rohstoff-Charge.
// Ein echtes, am Fertigprodukt gemessenes CoA hat immer Vorrang (coa_fertig_gemessen_vorhanden()).
require_once __DIR__ . '/pdf_spec.php';

// Kanonische Kontaminanten der Massenbilanz: Schlüssel => [Anzeige, Regex, Höchstgehalt NEM (VO (EU) 2023/915) als Referenz]
function coa_uebersicht_kontaminanten(): array {
    return [
        'pb' => ['Blei (Pb)',        '/\b(blei|lead|pb)\b/u',        '3,0 mg/kg'],
        'cd' => ['Cadmium (Cd)',     '/\b(cadmium|cd)\b/u',          '1,0 mg/kg'],
        'hg' => ['Quecksilber (Hg)', '/\b(quecksilber|mercury|hg)\b/u', '0,10 mg/kg'],
        'as' => ['Arsen (As)',       '/\b(arsen|arsenic|as)\b/u',    '–'],
    ];
}
// Mikrobiologische Parameter (nur als Rohstoff-Stichprobe ausweisen).
function coa_uebersicht_ist_mikro(string $parameter): bool {
    return (bool) preg_match('/(mikrobiolog|keimzahl|gesamtkeim|koloniezahl|kbe|cfu|e\.? ?coli|escherichia|salmonell|staphyl|'
        . 'pseudomonas|hefe|schimmel|yeast|mould|mold|enterobact|coliform|listeri|bacillus|aerob|anaerob)/u', mb_strtolower($parameter));
}
// Zahl + Einheit aus einem Analysenwert in mg/kg. Rückgabe ['wert'=>float, 'grenze'=>bool („<"/„≤" = Obergrenze)] oder null.
function coa_uebersicht_mgkg(string $roh): ?array {
    $s = mb_strtolower(str_replace(["\xc2\xa0", ' '], '', $roh));
    if (!preg_match('/(<=|≤|<|max\.?)?([0-9]+(?:[.,][0-9]+)?)(mg\/kg|ppm|µg\/g|ug\/g|µg\/kg|ug\/kg|ppb)/u', $s, $m)) return null;
    $v = (float) str_replace(',', '.', $m[2]);
    if (in_array($m[3], ['µg/kg', 'ug/kg', 'ppb'], true)) $v /= 1000;   // µg/kg -> mg/kg
    return ['wert' => $v, 'grenze' => $m[1] !== ''];
}
function coa_uebersicht_zahl(float $v): string {
    $d = $v >= 1 ? 2 : ($v >= 0.01 ? 3 : 4);
    return rtrim(rtrim(number_format($v, $d, ',', '.'), '0'), ',');
}

// Liegt für diese Fertigcharge ein ECHTES, am Fertigprodukt gemessenes CoA vor? (Analysewerte an der Fertigcharge
// oder ein hochgeladenes Analyse-Dokument dieser Charge, das NICHT die automatische Übersicht ist.)
function coa_fertig_gemessen_vorhanden(int $charge_id): bool {
    if (charge_coa_hat_analysewerte($charge_id)) return true;
    $c = one("SELECT c.charge_nr, COALESCE(c.auftrag_id, pa.auftrag_id) AS aid FROM charge c LEFT JOIN produktionsauftrag pa ON pa.id=c.pa_id WHERE c.id=?", [$charge_id]);
    if (!$c || empty($c['aid'])) return false;
    return (int) scalar("SELECT COUNT(*) FROM dokument WHERE objekt_typ='auftrag' AND objekt_id=? AND typ='analyse'
                         AND COALESCE(hochgeladen_von,'') <> 'coa_ueb' AND (charge_nr IS NULL OR charge_nr='' OR charge_nr=?)",
                        [(int)$c['aid'], (string)$c['charge_nr']]) > 0;
}

// Daten der Übersicht (auch für Tests/Anzeige ohne PDF).
function coa_uebersicht_daten(int $charge_id): ?array {
    $c = one("SELECT c.*, i.name AS item_name, i.produkt_id AS item_produkt_id FROM charge c JOIN item i ON i.id=c.item_id WHERE c.id=?", [$charge_id]);
    if (!$c || empty($c['pa_id'])) return null;
    $pa = one("SELECT * FROM produktionsauftrag WHERE id=?", [(int)$c['pa_id']]);
    if (!$pa) return null;
    $pid = (int)($pa['produkt_id'] ?? 0) ?: (int)($c['item_produkt_id'] ?? 0);
    $prod = $pid ? one("SELECT * FROM produkt WHERE id=?", [$pid]) : null;
    $rid = (int)($pa['rezeptur_id'] ?? 0) ?: (int)($prod['rezeptur_id'] ?? 0);
    $rez = $rid ? one("SELECT * FROM rezeptur WHERE id=?", [$rid]) : null;
    $kid = (int)($pa['kunde_id'] ?? 0);
    $name = $pid && function_exists('produkt_kundenname') ? produkt_kundenname($pid, $kid ?: null) : '';
    if ($name === '') $name = (string)($prod['name'] ?? $c['item_name']);
    $zut = $rid ? all("SELECT rz.item_id, COALESCE(NULLIF(rz.bezeichnung,''), i.name) AS name, rz.menge_mg
                       FROM rezeptur_zutat rz LEFT JOIN item i ON i.id=rz.item_id WHERE rz.rezeptur_id=? AND rz.menge_mg > 0 ORDER BY rz.sort, rz.id", [$rid]) : [];
    $summe = array_sum(array_map(fn($z) => (float)$z['menge_mg'], $zut));
    // Verbrauchte Rohstoff-Chargen dieses PA je Artikel (Rückverfolgbarkeit).
    $verb = [];
    foreach (all("SELECT DISTINCT pv.item_id, pv.charge_id, ch.charge_nr FROM produktion_verbrauch pv JOIN charge ch ON ch.id=pv.charge_id
                  WHERE pv.pa_id=? AND pv.charge_id IS NOT NULL", [(int)$pa['id']]) as $v) $verb[(int)$v['item_id']][] = $v;
    $K = coa_uebersicht_kontaminanten();
    $quellen = []; $mikro = []; $beitrag = [];   // $beitrag[key] = ['summe'=>, 'grenze'=>bool, 'gw'=>bool, 'fehlt'=>[Zutat]]
    foreach ($K as $k => $_) $beitrag[$k] = ['summe' => 0.0, 'grenze' => false, 'gw' => false, 'fehlt' => []];
    foreach ($zut as $z) {
        $iid = (int)$z['item_id']; $anteil = $summe > 0 ? (float)$z['menge_mg'] / $summe : 0.0;
        $chs = $verb[$iid] ?? [];
        $werte = []; $mikroZ = [];
        foreach ($chs as $ch) foreach (all("SELECT parameter, spezifikation, ergebnis FROM charge_analyse WHERE charge_id=? ORDER BY sort, id", [(int)$ch['charge_id']]) as $a) {
            $par = (string)$a['parameter'];
            if (coa_uebersicht_ist_mikro($par)) { $mikroZ[] = ['zutat' => $z['name'], 'charge' => (string)$ch['charge_nr'], 'parameter' => $par,
                                                          'spezifikation' => (string)($a['spezifikation'] ?? ''), 'ergebnis' => (string)($a['ergebnis'] ?? '')]; continue; }
            foreach ($K as $k => [$lbl, $rx]) if (preg_match($rx, mb_strtolower($par)) && ($mv = coa_uebersicht_mgkg((string)($a['ergebnis'] ?? '')))) {
                if (!isset($werte[$k]) || $mv['wert'] > $werte[$k]['wert']) $werte[$k] = $mv + ['quelle' => 'Messwert Charge ' . $ch['charge_nr']];
            }
        }
        $gwRows = $iid ? all("SELECT parameter, grenzwert FROM item_grenzwert WHERE item_id=?", [$iid]) : [];
        $quelleTxt = [];
        foreach ($K as $k => [$lbl, $rx]) {
            $v = $werte[$k] ?? null;
            if (!$v) foreach ($gwRows as $g) if (preg_match($rx, mb_strtolower((string)$g['parameter'])) && ($gv = coa_uebersicht_mgkg((string)$g['grenzwert']))) {
                $v = ['wert' => $gv['wert'], 'grenze' => true, 'quelle' => 'Grenzwert'];
                $beitrag[$k]['gw'] = true; break;
            }
            if (!$v) { $beitrag[$k]['fehlt'][] = (string)$z['name']; continue; }
            $beitrag[$k]['summe'] += $v['wert'] * $anteil;
            if ($v['grenze']) $beitrag[$k]['grenze'] = true;
            $quelleTxt[$v['quelle']] = true;
        }
        $mikro = array_merge($mikro, $mikroZ);
        $quellen[] = ['zutat' => (string)$z['name'], 'mg' => (float)$z['menge_mg'], 'anteil' => $anteil,
                      'chargen' => implode(', ', array_map(fn($ch) => (string)$ch['charge_nr'], $chs)) ?: '–',
                      'basis' => $quelleTxt ? (isset($quelleTxt['Grenzwert']) && count($quelleTxt) === 1 ? 'Grenzwert (worst case)' : (isset($quelleTxt['Grenzwert']) ? 'Analyse + Grenzwert' : 'Analyse der Charge')) : 'keine Werte'];
    }
    $ergebnis = [];
    foreach ($K as $k => [$lbl, $rx, $hg]) {
        $b = $beitrag[$k];
        if (!$zut) { $erg = 'nicht berechenbar (keine Rezeptur)'; }
        elseif ($b['fehlt']) { $erg = 'nicht berechenbar – keine Werte für: ' . implode(', ', $b['fehlt']); }
        else { $erg = ($b['grenze'] ? 'max. ' : '') . coa_uebersicht_zahl($b['summe']) . ' mg/kg'; }   // „max." statt „≤" (PDF-Schrift)
        $ergebnis[] = ['parameter' => $lbl, 'referenz' => $hg, 'ergebnis' => $erg,
                       'methode' => 'aus Rohstoff-Analysen (Massenbilanz)' . ($b['gw'] && !$b['fehlt'] ? ', teils Grenzwert/worst case' : '')];
    }
    $form = (string)($rez['darreichungsform'] ?? '');
    return ['charge' => $c, 'pa' => $pa, 'produkt' => $prod, 'rezeptur' => $rez, 'name' => $name, 'form' => $form,
            'zutaten' => $zut, 'fuell_mg' => $summe, 'ergebnis' => $ergebnis, 'mikro' => $mikro, 'quellen' => $quellen,
            'herstellung' => (string)($c['angelegt'] ?? ''), 'freigabe_am' => (string)($c['qm_freigabe_am'] ?? ''), 'freigabe_von' => (string)($c['qm_freigabe_von'] ?? '')];
}

// Kennzeichnung im Kopf – fester Text (nicht wegkonfigurierbar).
const COA_UEBERSICHT_HINWEIS = 'Zusammenfassendes CoA – Übersicht aus den Analysen der eingesetzten Rohstoffe (stichprobenhaft geprüft) und den Produktdaten; '
    . 'nicht am Fertigprodukt gemessen. Eine Fertigprodukt-Laboranalyse ist separat erhältlich.';

function build_coa_uebersicht_pdf(int $charge_id): ?string {
    $d = coa_uebersicht_daten($charge_id);
    if (!$d) return null;
    $c = $d['charge']; $L = 40; $R = 555;
    $fmtD = fn($x) => $x ? date('d.m.Y', strtotime((string)$x)) : '–';
    $p = new MiniPDF();
    spec_wasserzeichen($p);
    $y = spec_kopf($p, 'ZUSAMMENFASSENDES CoA', 'Übersicht aus Rohstoff-Analysen · ' . $d['name']);
    // Pflicht-Kennzeichnung als hervorgehobener Kasten direkt unter dem Titel.
    $zeilen = $p->wrap(COA_UEBERSICHT_HINWEIS, $R - $L - 20, 9, true);
    $h = 14 + count($zeilen) * 12;
    $p->rect($L, $y, $R - $L, $h, SPEC_LABEL); $p->rectStroke($L, $y, $R - $L, $h, 0.6, SPEC_LINE);
    $yy = $y + 15; foreach ($zeilen as $zl) { $p->text($L + 10, $yy, $zl, 9, true, SPEC_INK); $yy += 12; }
    $y += $h + 14;

    $kopf = [['Produkt', $d['name']], ['Charge', (string)($c['charge_nr'] ?: '–')], ['Mindesthaltbar bis', $fmtD($c['mhd'] ?? '')],
             ['Herstellungsdatum', $fmtD($d['herstellung'])]];
    if (!empty($d['rezeptur']['nummer'])) $kopf[] = ['Rezeptur', (string)$d['rezeptur']['nummer']];
    if ($d['form'] !== '') $kopf[] = ['Darreichungsform', function_exists('form_plural') ? ucfirst(form_plural($d['form'])) : $d['form']];
    $y = spec_grid($p, $y, $kopf);

    $y = spec_h($p, $y, 'Kontaminanten (berechnet, bezogen auf das Füllgut ohne Kapselhülle)');
    $y = spec_table($p, $y, [[$L, 'Parameter'], [170, 'Höchstgehalt NEM (Referenz)'], [300, 'Ergebnis'], [430, 'Methode']],
                    array_map(fn($e) => [$e['parameter'], $e['referenz'], $e['ergebnis'], $e['methode']], $d['ergebnis']));
    foreach ($p->wrap('Referenz: Höchstgehalte für Nahrungsergänzungsmittel nach VO (EU) 2023/915. „max.“ = berechnete Obergrenze (Rohstoffwert als „kleiner als“ angegeben oder Grenzwert/worst case).', $R - $L, 8, false) as $wl) {
        if ($y > 780) { $p->addPage(); $y = 48; } $p->text($L, $y + 8, $wl, 8, false, SPEC_GRAU); $y += 11;
    }
    $y += 6;

    $y = spec_h($p, $y, 'Mikrobiologie – Rohstoff-Stichproben (nicht am Fertigprodukt gemessen)');
    if ($d['mikro']) {
        $y = spec_table($p, $y, [[$L, 'Rohstoff (Charge)'], [210, 'Parameter'], [340, 'Spezifikation'], [450, 'Ergebnis Rohstoff']],
                        array_map(fn($m) => [$m['zutat'] . ' (' . $m['charge'] . ')', $m['parameter'], $m['spezifikation'] ?: '–', $m['ergebnis'] ?: '–'], $d['mikro']));
    } else { $p->text($L, $y + 10, 'Für die eingesetzten Rohstoff-Chargen liegen keine mikrobiologischen Stichproben vor.', 9, false, SPEC_GRAU); $y += 22; }

    $y = spec_h($p, $y, 'Eingesetzte Rohstoffe und Quellchargen');
    $y = spec_table($p, $y, [[$L, 'Zutat'], [230, 'mg je Einheit'], [310, 'Anteil Füllgut'], [390, 'Rohstoff-Charge(n)'], [480, 'Datenbasis']],
                    array_map(fn($q) => [$q['zutat'], coa_uebersicht_zahl($q['mg']), number_format($q['anteil'] * 100, 1, ',', '.') . ' %', $q['chargen'], $q['basis']], $d['quellen']));

    $y = spec_qr_panel($p, $y, function_exists('charge_echt_url') ? charge_echt_url($charge_id) : '', 'Echtheit online prüfen',
                       'QR-Code scannen – bestätigt Produkt, Charge und Mindesthaltbarkeit dieser Charge.');
    $y += 14;
    foreach ($p->wrap('Diese Übersicht fasst die Analysenzertifikate der eingesetzten Rohstoff-Chargen und die Produktdaten zusammen. '
                    . 'Sie ersetzt keine Laboranalyse des Fertigprodukts.', $R - $L, 9, false) as $wl) {
        if ($y > 780) { $p->addPage(); $y = 48; } $p->text($L, $y, $wl, 9, false, SPEC_INK); $y += 12;
    }
    $y = spec_release($p, $y, $d['freigabe_am'] !== '' ? date('d.m.Y', strtotime($d['freigabe_am'])) : date('d.m.Y'));
    spec_fuss($p, $y + 18);
    return $p->output();
}
