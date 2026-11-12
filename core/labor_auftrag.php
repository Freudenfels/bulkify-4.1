<?php
// Proben ans Labor (Nico 05.11.2026): das Auftragsformular des Labors (beschreibbares PDF) aus bulkify-Daten füllen.
// Auftraggeber = Kunde (Produkt-Eigentümer, steht auf dem Untersuchungsbericht); Rechnungsempfänger + Einsender =
// bulkify (Firmenstammdaten, Abrechnung über bulkify); Berichtsempfänger = bulkify oder Kunde (Einstellung am Labor).
// Labore sind Lieferanten mit Kategorie „labor“ und einer Formular-Vorlage (labor_formular). Mechanik generisch:
// je Vorlage eine Feldzuordnung in labor_formulare(); umgesetzt ist GBA Food.
require_once __DIR__ . '/pdf_formular.php';

// Formular-Vorlagen: Datei + Feldzuordnung (AcroForm-Feldnamen). 'bloecke' je Adressblock in der Reihenfolge
// Firma, Ansprechpartner, Straße, PLZ/Ort, Land, USt-Id, Telefon, E-Mail.
function labor_formulare(): array {
    return [
        'gba_food' => [
            'titel'      => 'GBA Food – Analyseauftrag',
            'datei'      => BX_ROOT . '/core/labor_vorlagen/gba_food.pdf',
            'max_proben' => 5,
            'erste_ja'   => 'Kontrollkästchen23', 'erste_nein' => 'Kontrollkästchen24',
            'datum'      => ['Datum26_af_date', 'Datum27_af_date'],
            'standorte'  => ['Hamburg' => 'Kontrollkästchen1', 'Hameln' => 'Kontrollkästchen2'],
            'ansprechpartner_labor' => 'Text3', 'angebotsnr' => 'Text4',
            'bloecke'    => [
                'auftraggeber'      => ['Text5', 'Text6', 'Text7', 'Text8', 'Text9', 'Text10', 'Text11', 'Text12'],
                'rechnung'          => ['Text13', '14', '15', '16', '17', '18', '19', '20'],
                'einsender'         => ['21', '22', '23', '24', '25', '26', '27', '28'],
                'berichtsempfaenger'=> ['29', '30', '31', '32', '33', '34', '35', '36'],
            ],
            'bemerkung'  => '37',
            'firma_s2'   => 'Text22',
            // je Probe: Nr, Material/Produkt, Rückverfolgbarkeit, Umfang 1-3, Beurteilung, Express
            'proben'     => [
                ['38', '39', '40', ['41', '42', '43'], '44', '45'],
                ['46', '47', '48', ['49', '50', '51'], '52', '53'],
                ['54', '55', '56', ['57', '58', '59'], '60', '61'],
                ['62', '63', '64', ['65', '66', '67'], '68', '69'],
                ['70', '71', '72', ['73', '74', '75'], '76', '77'],
            ],
        ],
    ];
}

// Labore = Lieferanten mit Kategorie „labor“ und hinterlegter Formular-Vorlage.
function labor_lieferanten(bool $nurMitFormular = true): array {
    $rows = all("SELECT * FROM lieferanten WHERE FIND_IN_SET('labor', REPLACE(COALESCE(kategorien,''),' ','')) AND COALESCE(gesperrt,0)=0 ORDER BY firma");
    if ($nurMitFormular) $rows = array_values(array_filter($rows, fn($l) => isset(labor_formulare()[(string)($l['labor_formular'] ?? '')])));
    return $rows;
}
function labor_standorte(array $labor): array {
    $s = array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', (string)($labor['labor_standorte'] ?? '')))));
    return $s;
}

function labor_land_name(string $land): string {
    $m = ['DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz', 'NL' => 'Niederlande', 'BE' => 'Belgien', 'LU' => 'Luxemburg',
          'FR' => 'Frankreich', 'IT' => 'Italien', 'ES' => 'Spanien', 'PL' => 'Polen', 'DK' => 'Dänemark', 'CZ' => 'Tschechien', 'GB' => 'Vereinigtes Königreich'];
    $l = trim($land);
    return $m[strtoupper($l)] ?? $l;
}
// Adressblock des Kunden (Auftraggeber). 'fehlt' = Pflichtangaben, die das Labor verlangt (USt-Id!).
function labor_block_kunde(int $kunde_id): array {
    $k = $kunde_id > 0 ? one("SELECT * FROM kunden WHERE id=?", [$kunde_id]) : null;
    if (!$k) return ['werte' => array_fill(0, 8, ''), 'fehlt' => ['Kunde'], 'firma' => ''];
    $w = [(string)$k['firma'], (string)($k['ansprechpartner'] ?? ''), trim(($k['strasse'] ?? '') . ' ' . ($k['hausnummer'] ?? '')),
          trim(($k['plz'] ?? '') . ' ' . ($k['ort'] ?? '')), labor_land_name((string)($k['land'] ?? '')), trim((string)($k['ust_id'] ?? '')),
          (string)($k['telefon'] ?? ''), (string)($k['email'] ?? '')];
    $fehlt = [];
    if ($w[5] === '') $fehlt[] = 'USt-IdNr.';
    if ($w[2] === '' || $w[3] === '') $fehlt[] = 'Anschrift';
    return ['werte' => $w, 'fehlt' => $fehlt, 'firma' => (string)$k['firma']];
}
// bulkify aus den Firmenstammdaten (Einstellungen), nicht hartkodiert. Ansprechpartner = wer das Formular erstellt.
function labor_block_bulkify(string $ansprechpartner = ''): array {
    if (!function_exists('beleg_firma')) require_once __DIR__ . '/pdf_beleg.php';
    $f = beleg_firma();
    $ap = $ansprechpartner !== '' ? $ansprechpartner : (string) meta_get('firma_ansprechpartner', '');
    return [(string)$f['name'], $ap, (string)$f['strasse'], (string)$f['plz_ort'], (string)$f['land'], (string)$f['ust_id'],
            (string) meta_get('firma_telefon', ''), (string)$f['email']];
}

// Aufträge, die eine Probe ans Labor brauchen: Kunde will Drittlabor oder Laboranalyse dazugebucht, noch kein
// freigegebener Bericht. Bereits versendete stehen dabei (mit Datum), damit man ein Formular neu drucken kann.
function labor_proben_kandidaten(): array {
    $rows = all("SELECT a.id, a.nummer, a.kunde_id, a.produkt_id, a.labor_versendet_am, a.laboranalyse_gebucht, a.status, k.firma,
                        COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt
                 FROM auftrag a JOIN kunden k ON k.id=a.kunde_id LEFT JOIN produkt p ON p.id=a.produkt_id
                 WHERE a.status NOT IN ('storniert') AND (a.laboranalyse_gebucht=1 OR k.labortest_extern=1)
                 ORDER BY (a.labor_versendet_am IS NOT NULL), a.id DESC LIMIT 200");
    $out = [];
    foreach ($rows as $r) {
        $st = auftrag_labortest_status((int)$r['id'], (int)($r['produkt_id'] ?? 0));
        if ($st['status'] === 'abgeschlossen') continue;
        $r['labor_status'] = $st['status'];
        $out[] = $r;
    }
    return $out;
}
// Vorbelegung einer Probenzeile aus dem Auftrag: Produktname, Charge + MHD der Fertigware, dazu die Auftragsnummer.
function labor_probe_vorschlag(int $auftrag_id, string $umfang = ''): array {
    $a = one("SELECT a.*, COALESCE(NULLIF(p.kundenname,''), NULLIF(a.produkt_bezeichnung,''), p.name) AS produkt FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$auftrag_id]);
    if (!$a) return [];
    // Name, unter dem der Kunde das Produkt führt (kundenspezifischer Produktname), sonst Kunden-/Produktname.
    $name = (table_exists('produkt_kunde_name') && !empty($a['produkt_id']))
        ? (string) scalar("SELECT name FROM produkt_kunde_name WHERE produkt_id=? AND kunde_id=?", [(int)$a['produkt_id'], (int)$a['kunde_id']]) : '';
    if ($name === '') $name = (string)($a['produkt'] ?? '');
    $rv = [];
    foreach (auftrag_fertigware_chargen($auftrag_id) as $c) {
        // Fable-3 T3.2: Probenbezeichnung mit der Grundcharge (der Kunde ist Auftraggeber des Labors).
        $t = 'Charge ' . kunde_charge_anzeige((string)$c['charge_nr']) . (!empty($c['mhd']) ? "\nMHD " . date('d.m.Y', strtotime((string)$c['mhd'])) : '');
        if (!in_array($t, $rv, true)) $rv[] = $t;
        if (count($rv) >= 2) break;
    }
    $rv[] = 'Auftrag ' . (string)$a['nummer'];
    $u = array_slice(array_pad(preg_split('/\r\n|\r|\n/', trim($umfang)) ?: [], 3, ''), 0, 3);
    return ['auftrag_id' => $auftrag_id, 'kunde_id' => (int)$a['kunde_id'], 'nummer' => (string)$a['nummer'],
            'material' => $name, 'rueckverfolgbarkeit' => implode("\n", $rv), 'umfang' => $u, 'beurteilung' => false, 'express' => false];
}

// Feldwerte für EIN Formular (ein Auftraggeber, max. 5 Proben).
function labor_formular_werte(array $labor, string $standort, int $kunde_id, array $proben, string $bemerkung, string $datum, string $ansprechpartner = ''): array {
    $f = labor_formulare()[(string)$labor['labor_formular']];
    $w = [];
    $erste = (int)($labor['labor_erste_proben'] ?? 0) === 1;
    $w[$f['erste_ja']] = $erste; $w[$f['erste_nein']] = !$erste;
    foreach ($f['datum'] as $df) $w[$df] = date('d.m.Y', strtotime($datum));
    foreach ($f['standorte'] as $name => $feld) $w[$feld] = (mb_strtolower($name) === mb_strtolower($standort));
    $w[$f['ansprechpartner_labor']] = (string)($labor['ansprechpartner'] ?? '');
    $w[$f['angebotsnr']] = (string)($labor['labor_angebotsnr'] ?? '');
    $kunde = labor_block_kunde($kunde_id)['werte'];
    $bx = labor_block_bulkify($ansprechpartner);
    $blockWerte = ['auftraggeber' => $kunde, 'rechnung' => $bx, 'einsender' => $bx,
                   'berichtsempfaenger' => ((string)($labor['labor_bericht_an'] ?? 'bulkify')) === 'kunde' ? $kunde : $bx];
    foreach ($f['bloecke'] as $blk => $felder) foreach ($felder as $i => $feld) $w[$feld] = (string)($blockWerte[$blk][$i] ?? '');
    $w[$f['bemerkung']] = $bemerkung;
    $w[$f['firma_s2']] = $kunde[0];
    foreach (array_values($proben) as $i => $p) {
        if (!isset($f['proben'][$i])) break;
        [$nr, $mat, $rv, $um, $beu, $exp] = $f['proben'][$i];
        $w[$nr] = (string)($i + 1); $w[$mat] = (string)$p['material']; $w[$rv] = (string)$p['rueckverfolgbarkeit'];
        foreach ($um as $j => $uf) $w[$uf] = (string)($p['umfang'][$j] ?? '');
        $w[$beu] = !empty($p['beurteilung']); $w[$exp] = !empty($p['express']);
    }
    return $w;
}

// Formulare erzeugen + ablegen. $proben: Liste aus labor_probe_vorschlag() (bearbeitet). Je Auftraggeber (Kunde)
// und je max_proben ein Formular. Jedes Formular: Datei in data/uploads, Zeile in labor_versand, je Auftrag ein
// internes Dokument (typ 'labor_auftrag'), Auftrag „Probe beim Labor“ (labor_versendet_am, falls noch leer).
// Rückgabe: ['ok'=>bool, 'formulare'=>[['versand_id','datei','kunde','proben'=>n,'dok_id']], 'fehler'=>string].
function labor_versand_anlegen(int $lieferant_id, string $standort, array $proben, string $bemerkung, string $wer = ''): array {
    $labor = one("SELECT * FROM lieferanten WHERE id=?", [$lieferant_id]);
    if (!$labor || !isset(labor_formulare()[(string)$labor['labor_formular']])) return ['ok' => false, 'formulare' => [], 'fehler' => 'Labor ohne Formular-Vorlage.'];
    $f = labor_formulare()[(string)$labor['labor_formular']];
    $vorlage = @file_get_contents($f['datei']);
    if ($vorlage === false) return ['ok' => false, 'formulare' => [], 'fehler' => 'Vorlage nicht gefunden.'];
    $orte = labor_standorte($labor);
    if ($orte && !in_array($standort, $orte, true)) return ['ok' => false, 'formulare' => [], 'fehler' => 'Bitte den Standort wählen.'];
    $jeKunde = [];
    foreach ($proben as $p) if (!empty($p['auftrag_id'])) $jeKunde[(int)$p['kunde_id']][] = $p;
    if (!$jeKunde) return ['ok' => false, 'formulare' => [], 'fehler' => 'Keine Probe gewählt.'];
    if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
    $heute = gmdate('Y-m-d');
    $out = [];
    foreach ($jeKunde as $kid => $liste) {
        foreach (array_chunk($liste, (int)$f['max_proben']) as $teil) {
            $pdf = pdf_formular_fuellen($vorlage, labor_formular_werte($labor, $standort, $kid, $teil, $bemerkung, $heute, $wer));
            $kfirma = (string) scalar("SELECT firma FROM kunden WHERE id=?", [$kid]);
            $fn = 'laborauftrag_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
            file_put_contents(BX_UPLOADS . '/' . $fn, $pdf);
            q("INSERT INTO labor_versand (lieferant_id, standort, kunde_id, datum, bemerkung, proben, datei, angelegt_von) VALUES (?,?,?,?,?,?,?,?)",
              [$lieferant_id, $standort ?: null, $kid ?: null, $heute, $bemerkung !== '' ? $bemerkung : null, json_encode(array_values($teil), JSON_UNESCAPED_UNICODE), $fn, $wer ?: null]);
            $vid = (int) insert_id();
            $orig = 'Laborauftrag ' . (string)$labor['firma'] . ($standort !== '' ? ' ' . $standort : '') . ' – ' . $kfirma . ' – ' . date('d.m.Y') . '.pdf';
            $dokErst = 0;
            foreach ($teil as $p) {
                // je Auftrag eine eigene Datei-Kopie: so bleibt jedes Dokument für sich löschbar
                $fnA = $fn;
                if ($dokErst) { $fnA = 'laborauftrag_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf'; copy(BX_UPLOADS . '/' . $fn, BX_UPLOADS . '/' . $fnA); }
                q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,kunde_sichtbar) VALUES ('auftrag',?,?,?,?,?,0)",
                  [(int)$p['auftrag_id'], 'labor_auftrag', 'Laborauftrag (Probe an ' . (string)$labor['firma'] . ')', $fnA, mb_substr($orig, 0, 190)]);
                $did = (int) insert_id(); if (!$dokErst) $dokErst = $did;
                q("UPDATE auftrag SET labor_versendet_am=? WHERE id=? AND labor_versendet_am IS NULL", [$heute, (int)$p['auftrag_id']]);
                if (function_exists('log_aktivitaet'))
                    log_aktivitaet('auftrag', (int)$p['auftrag_id'], 'team', 'Probe ans Labor: Auftragsformular ' . (string)$labor['firma'] . ($standort !== '' ? ' ' . $standort : '') . ' erstellt.', 'notiz');
            }
            $out[] = ['versand_id' => $vid, 'datei' => $fn, 'kunde' => $kfirma, 'proben' => count($teil), 'dok_id' => $dokErst];
        }
    }
    return ['ok' => true, 'formulare' => $out, 'fehler' => ''];
}
// Bisher erzeugte Formulare (für die Liste unter Laborberichte).
function labor_versand_liste(int $limit = 30): array {
    if (!table_exists('labor_versand')) return [];
    return all("SELECT v.*, l.firma AS labor, k.firma AS kunde FROM labor_versand v LEFT JOIN lieferanten l ON l.id=v.lieferant_id
                LEFT JOIN kunden k ON k.id=v.kunde_id ORDER BY v.id DESC LIMIT " . (int)$limit);
}
