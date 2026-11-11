<?php
if (is_file(__DIR__ . '/kunde_neutral.php')) require_once __DIR__ . '/kunde_neutral.php';   // Paket 12.11.2026 Teil 3: Lieferant nie für Kunden sichtbar
// Zusammenfassendes CoA einer FERTIGWARE-Charge (Nico 03.11.2026): eine Übersicht aus den Analysen der tatsächlich
// eingesetzten Rohstoff-Chargen und den Produktdaten – NICHT am Fertigprodukt gemessen. Die Rohstoffe werden stich-
// probenhaft geprüft; eine Fertigprodukt-Laboranalyse ist ein separater Zusatz. Pflicht (nicht wegkonfigurierbar):
// Hinweis im Kleingedruckten (nicht am Fertigprodukt gemessen), Quellchargen genannt, claims-neutral.
//  - Schwermetalle mit NEM-Höchstgehalt (Pb, Cd, Hg): Massenbilanz auf das Füllgut = Σ (Rohstoffwert × Massenanteil der Zutat).
//    Rohstoffwert = Messwert der verbrauchten Charge (charge_analyse; mehrere Chargen -> der höchste), sonst der Wert aus der
//    Rohstoff-Spezifikation (item_grenzwert). Auf dem Dokument steht nur die Bewertung „konform". Fehlt für EINE Zutat jede
//    Angabe, entfällt der Parameter ganz (Nico: nur reinschreiben, wenn die Infos da sind).
//  - Mikrobiologie/prozessabhängige Werte: NIE hochgerechnet – nur als Rohstoff-Stichprobe je Rohstoff-Charge.
// Ein echtes, am Fertigprodukt gemessenes CoA hat immer Vorrang (coa_fertig_gemessen_vorhanden()).
require_once __DIR__ . '/pdf_spec.php';

// Kanonische Kontaminanten der Massenbilanz: Schlüssel => [Anzeige, Regex, Höchstgehalt NEM (VO (EU) 2023/915) als Text, als Zahl mg/kg].
// Nur Parameter MIT gesetzlichem NEM-Höchstgehalt (Arsen hat keinen) – bewertet wird „konform" gegen diesen Wert (Nico 03.11.2026).
function coa_uebersicht_kontaminanten(): array {
    return [
        'pb' => ['Blei (Pb)',        '/\b(blei|lead|pb)\b/u',        '3,0 mg/kg',  3.0],
        'cd' => ['Cadmium (Cd)',     '/\b(cadmium|cd)\b/u',          '1,0 mg/kg',  1.0],
        'hg' => ['Quecksilber (Hg)', '/\b(quecksilber|mercury|hg)\b/u', '0,10 mg/kg', 0.10],
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
    // Lieferant nur als interne Lieferantennummer (wie auf dem Lager-Etikett) – nie der Name.
    foreach (all("SELECT DISTINCT pv.item_id, pv.charge_id, ch.charge_nr, l.lieferantennummer AS lieferant_nr FROM produktion_verbrauch pv
                  JOIN charge ch ON ch.id=pv.charge_id LEFT JOIN lieferanten l ON l.id=ch.lieferant_id
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
                      'chargen' => implode(', ', array_map(fn($ch) => (string)$ch['charge_nr']
                          . (trim((string)($ch['lieferant_nr'] ?? '')) !== '' ? ' (' . kunde_text_neutral(trim((string)$ch['lieferant_nr'])) . ')' : ''), $chs)) ?: '–',
                      'basis' => $quelleTxt ? (isset($quelleTxt['Grenzwert']) && count($quelleTxt) === 1 ? 'Spezifikation' : (isset($quelleTxt['Grenzwert']) ? 'Analyse + Spezifikation' : 'Analyse der Charge')) : 'keine Werte'];
    }
    // Bewertung je Parameter: „konform", wenn der berechnete Wert (Obergrenze) den NEM-Höchstgehalt einhält. Der Zahlenwert
    // bleibt intern (wert), auf dem Dokument steht nur die Bewertung. Überschreitung -> ueberschritten=true (Dokument nicht auto-sichtbar).
    $ergebnis = []; $ueber = false;
    foreach ($K as $k => [$lbl, $rx, $hg, $hgZahl]) {
        $b = $beitrag[$k];
        $ok = $zut && !$b['fehlt'];
        $konform = $ok && $b['summe'] <= $hgZahl + 1e-9;
        if ($ok && !$konform) $ueber = true;
        $ergebnis[] = ['parameter' => $lbl, 'referenz' => $hg, 'ok' => $ok, 'wert' => $ok ? round($b['summe'], 4) : null,
                       'ergebnis' => !$ok ? 'nicht berechenbar' : ($konform ? 'konform' : 'Höchstgehalt überschritten'),
                       'methode' => 'aus Rohstoff-Analysen'];
    }
    // Novel-Food-Status je Zutat (gespeicherter Abgleich mit Datum, wie im PIB) + Gesamtstatus des Produkts.
    // Beim Erzeugen frisch abgleichen (Datum = heute), sofern der EU-Katalog geladen ist – sonst gilt der gespeicherte Stand.
    $nfFrisch = function_exists('novelfood_begriffe') && function_exists('item_novelfood_aktualisieren') && novelfood_begriffe();
    $nf = [];
    foreach ($zut as $z) {
        if ($nfFrisch && !empty($z['item_id'])) item_novelfood_aktualisieren((int)$z['item_id']);
        $it = !empty($z['item_id']) ? one("SELECT novelfood_status, novelfood_geprueft_am FROM item WHERE id=?", [(int)$z['item_id']]) : null;
        $nf[] = ['zutat' => (string)$z['name'], 'status' => (string)($it['novelfood_status'] ?? ''), 'am' => (string)($it['novelfood_geprueft_am'] ?? '')];
    }
    $nfSnap = $pid && function_exists('produkt_novelfood_snapshot') ? produkt_novelfood_snapshot($pid) : ['status' => 'unklar', 'geprueft_am' => null, 'ungeprueft' => 0, 'auffaellig' => []];
    $form = (string)($rez['darreichungsform'] ?? '');
    return ['charge' => $c, 'pa' => $pa, 'produkt' => $prod, 'rezeptur' => $rez, 'name' => $name, 'form' => $form,
            'zutaten' => $zut, 'fuell_mg' => $summe, 'ergebnis' => $ergebnis, 'ueberschritten' => $ueber, 'mikro' => $mikro, 'quellen' => $quellen, 'novelfood' => $nf, 'nf_snap' => $nfSnap,
            'herstellung' => (string)($c['angelegt'] ?? ''), 'freigabe_am' => (string)($c['qm_freigabe_am'] ?? ''), 'freigabe_von' => (string)($c['qm_freigabe_von'] ?? '')];
}

// Hinweis im Kleingedruckten (Nico 03.11.2026: kein Kasten im Kopf, Name „Informationsdokument zum Produkt").
const COA_UEBERSICHT_HINWEIS = 'Dieses Informationsdokument fasst die Analysen der eingesetzten Rohstoffe (stichprobenhaft geprüft) und die Produktdaten zusammen; '
    . 'die Werte sind nicht am Fertigprodukt gemessen. Eine Laboranalyse des Fertigprodukts ist separat erhältlich.';
const COA_UEBERSICHT_TITEL = 'Informationsdokument zum Produkt';

function build_coa_uebersicht_pdf(int $charge_id): ?string {
    $d = coa_uebersicht_daten($charge_id);
    if (!$d) return null;
    $c = $d['charge']; $L = 40; $R = 555;
    $fmtD = fn($x) => $x ? date('d.m.Y', strtotime((string)$x)) : '–';
    $p = new MiniPDF();
    $y = 0.0;
    $klein = function (string $txt) use (&$y, $p, $L, $R): void {
        foreach ($p->wrap($txt, $R - $L, 8, false) as $wl) { if ($y > 785) { $p->addPage(); $y = 48; } $p->text($L, $y + 8, $wl, 8, false, SPEC_GRAU); $y += 11; }
    };
    // Abschnitts-Überschrift nie allein am Seitenende (Platz für Kopf + ein paar Zeilen).
    $abschnitt = function (string $titel, float $platz = 110) use (&$y, $p): void {
        if ($y + $platz > 790) { $p->addPage(); $y = 48; }
        $y = spec_h($p, $y, $titel);
    };
    spec_wasserzeichen($p);
    $y = spec_kopf($p, 'INFORMATIONSDOKUMENT', 'zum Produkt · ' . $d['name'] . ' · Charge ' . (string)($c['charge_nr'] ?: '–'));

    $kopf = [['Produkt', $d['name']], ['Charge', (string)($c['charge_nr'] ?: '–')], ['Mindesthaltbar bis', $fmtD($c['mhd'] ?? '')],
             ['Herstellungsdatum', $fmtD($d['herstellung'])]];
    if (!empty($d['rezeptur']['nummer'])) $kopf[] = ['Rezeptur', (string)$d['rezeptur']['nummer']];
    if ($d['form'] !== '') $kopf[] = ['Darreichungsform', function_exists('form_plural') ? ucfirst(form_plural($d['form'])) : $d['form']];
    $y = spec_grid($p, $y, $kopf);

    $abschnitt('Eingesetzte Rohstoffe und Quellchargen');
    $y = spec_table($p, $y, [[$L, 'Zutat'], [215, 'mg je Einheit'], [290, 'Anteil Füllgut'], [360, 'Rohstoff-Charge(n)'], [445, 'Datenbasis']],
                    array_map(fn($q) => [$q['zutat'], coa_uebersicht_zahl($q['mg']), number_format($q['anteil'] * 100, 1, ',', '.') . ' %', $q['chargen'], $q['basis']], $d['quellen']));

    // Novel-Food-Status je Zutat (gespeicherter automatischer Abgleich, mit Datum).
    $nfL = ['konform' => 'konform (nicht neuartig)', 'pruefung' => 'Status prüfen', 'novel_food' => 'Novel Food – Zulassung nötig'];
    if ($d['novelfood']) {
        $abschnitt('Novel-Food-Status');
        $y = spec_table($p, $y, [[$L, 'Zutat'], [300, 'Status'], [450, 'Abgleich am']],
                        array_map(fn($n) => [$n['zutat'], $nfL[$n['status']] ?? 'noch nicht abgeglichen', $n['am'] !== '' ? $fmtD($n['am']) : '–'], $d['novelfood']));
        $sn = $d['nf_snap'];
        if (($sn['status'] ?? '') === 'konform' && (int)($sn['ungeprueft'] ?? 0) === 0)
            $klein('Alle eingesetzten Rohstoffe gelten nach dem EU-Novel-Food-Katalog als nicht-neuartig (konform), Stand ' . $fmtD($sn['geprueft_am'] ?? '') . '.');
        $klein('Automatischer Abgleich mit dem EU-Novel-Food-Katalog – Einschätzung, keine Rechtsberatung; maßgeblich ist der jeweils aktuelle EU-Katalog.');
        $y += 6;
    }

    // Kontaminanten: nur Parameter, die sich für ALLE Zutaten aus Messwerten/Grenzwerten berechnen lassen.
    $berechnet = array_values(array_filter($d['ergebnis'], fn($e) => !empty($e['ok'])));
    if ($berechnet) {
        $abschnitt('Kontaminanten', 140);
        // Nico 03.11.2026: KEINE Höchstgehalt-Spalte – nur Gehalt (berechnet) + Bewertung, sonst verwirrt „Höchstwert + konform".
        $y = spec_table($p, $y, [[$L, 'Parameter'], [190, 'Gehalt'], [330, 'Bewertung'], [430, 'Grundlage']],
                        array_map(fn($e) => [$e['parameter'], coa_uebersicht_zahl((float)$e['wert']) . ' mg/kg', $e['ergebnis'], $e['methode']], $berechnet));
        $klein('Gehalt berechnet aus den Analysen bzw. Spezifikationen der eingesetzten Rohstoffe, bezogen auf das Füllgut; „konform“ = innerhalb der Höchstgehalte für Nahrungsergänzungsmittel nach VO (EU) 2023/915.');
        $y += 6;
    }

    if ($d['mikro']) {
        $abschnitt('Mikrobiologie – Rohstoff-Stichproben', 140);
        $y = spec_table($p, $y, [[$L, 'Rohstoff (Charge)'], [210, 'Parameter'], [340, 'Spezifikation'], [450, 'Ergebnis Rohstoff']],
                        array_map(fn($m) => [$m['zutat'] . ' (' . $m['charge'] . ')', $m['parameter'], $m['spezifikation'] ?: '–', $m['ergebnis'] ?: '–'], $d['mikro']));
        $klein('Werte der Rohstoff-Stichproben, nicht am Fertigprodukt gemessen.');
        $y += 6;
    }

    $y = spec_qr_panel($p, $y, function_exists('charge_echt_url') ? charge_echt_url($charge_id) : '', 'Echtheit online prüfen',
                       'QR-Code scannen – bestätigt Produkt, Charge und Mindesthaltbarkeit dieser Charge.');
    $y += 12;
    $klein(COA_UEBERSICHT_HINWEIS);
    // Kein Unterschrifts-/Stempelblock: spec_fuss schreibt Haftungsausschluss + „maschinell erstellt, ohne Unterschrift gültig".
    spec_fuss($p, $y + 14);
    return $p->output();
}
