<?php
// Buchhaltung – Auswertungen, GoBD-Nummernkreis-Prüfung und Exporte (CSV/DATEV).
// Reine Lese-/Aufbereitungslogik; keine schreibenden Aktionen. Von module/buchhaltung/* genutzt.
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/pdf_beleg.php'; // beleg_firma()
require_once __DIR__ . '/ui.php';        // status_text()

// ---------------------------------------------------------------------------
// Kennzahlen / Listen
// ---------------------------------------------------------------------------

// Offene Posten je Kunde (Brutto minus Zahlungen; überfälliger Anteil separat).
function bh_op_je_kunde(): array {
    return all(
        "SELECT k.id AS kunde_id, k.firma,
                COUNT(*) AS anz,
                SUM(b.brutto - COALESCE(z.bez,0)) AS offen,
                SUM(CASE WHEN b.faellig IS NOT NULL AND b.faellig < CURDATE()
                         THEN b.brutto - COALESCE(z.bez,0) ELSE 0 END) AS ueberfaellig
           FROM beleg b
           LEFT JOIN kunden k ON k.id=b.kunde_id
           LEFT JOIN (SELECT beleg_id, SUM(betrag) bez FROM zahlung GROUP BY beleg_id) z ON z.beleg_id=b.id
          WHERE b.typ='rechnung' AND b.status IN ('offen','teilbezahlt')
          GROUP BY k.id, k.firma
          ORDER BY offen DESC");
}

// Offene Posten je Rechnung (Debitoren, Zeilenebene). $filter: '' = alle offenen, 'ueberfaellig' = nur fällige.
// Liefert Rest (brutto − bezahlt), Tage überfällig und die aktuelle Mahnstufe. Nur Rechnungen (kein Storno).
function bh_op_rechnungen(string $filter = '', int $kunde_id = 0): array {
    $where = "b.typ='rechnung' AND b.status IN ('offen','teilbezahlt')";
    $args = [];
    if ($kunde_id) { $where .= " AND b.kunde_id=?"; $args[] = $kunde_id; }
    if ($filter === 'ueberfaellig') $where .= " AND b.faellig IS NOT NULL AND b.faellig < CURDATE()";
    return all(
        "SELECT b.id, b.nummer, b.datum, b.faellig, b.brutto, b.status, b.kunde_id,
                COALESCE(b.mahnstufe,0) AS mahnstufe, b.letzte_mahnung, b.kategorie,
                k.firma AS kunde_firma,
                COALESCE(z.bez,0) AS bezahlt,
                (b.brutto - COALESCE(z.bez,0)) AS rest,
                CASE WHEN b.faellig IS NOT NULL AND b.faellig < CURDATE()
                     THEN DATEDIFF(CURDATE(), b.faellig) ELSE 0 END AS tage_ueberfaellig
           FROM beleg b
           LEFT JOIN kunden k ON k.id=b.kunde_id
           LEFT JOIN (SELECT beleg_id, SUM(betrag) bez FROM zahlung GROUP BY beleg_id) z ON z.beleg_id=b.id
          WHERE $where
          ORDER BY (b.faellig IS NULL), b.faellig ASC, b.id ASC", $args);
}

// Jahre, für die es Belege gibt (neueste zuerst) – für die Jahr-Auswahl.
function bh_jahre(): array {
    $rows = all("SELECT DISTINCT YEAR(datum) AS j FROM beleg WHERE datum IS NOT NULL ORDER BY j DESC");
    $out = array_values(array_filter(array_map(fn($r) => (int)$r['j'], $rows)));
    if (!$out) $out = [(int)date('Y')];
    return $out;
}

// Umsatz je Monat eines Jahres (Rechnung minus Gutschrift, ohne Storno).
function bh_umsatz_monate(int $jahr): array {
    $raw = all(
        "SELECT MONTH(datum) AS m,
                SUM(CASE WHEN typ='gutschrift' THEN -netto      ELSE netto      END) AS netto,
                SUM(CASE WHEN typ='gutschrift' THEN -ust_betrag ELSE ust_betrag END) AS ust,
                SUM(CASE WHEN typ='gutschrift' THEN -brutto     ELSE brutto     END) AS brutto
           FROM beleg
          WHERE typ IN ('rechnung','gutschrift') AND status<>'storniert'
            AND datum IS NOT NULL AND YEAR(datum)=?
          GROUP BY MONTH(datum)", [$jahr]);
    $monate = [];
    for ($m = 1; $m <= 12; $m++) $monate[$m] = ['netto' => 0.0, 'ust' => 0.0, 'brutto' => 0.0];
    foreach ($raw as $r) {
        $m = (int)$r['m'];
        if ($m >= 1 && $m <= 12) $monate[$m] = ['netto'=>(float)$r['netto'], 'ust'=>(float)$r['ust'], 'brutto'=>(float)$r['brutto']];
    }
    return $monate;
}

// Umsatz je Steuersatz eines Jahres (für USt-Übersicht).
function bh_umsatz_steuersatz(int $jahr): array {
    return all(
        "SELECT ust_prozent,
                SUM(CASE WHEN typ='gutschrift' THEN -netto      ELSE netto      END) AS netto,
                SUM(CASE WHEN typ='gutschrift' THEN -ust_betrag ELSE ust_betrag END) AS ust
           FROM beleg
          WHERE typ IN ('rechnung','gutschrift') AND status<>'storniert'
            AND datum IS NOT NULL AND YEAR(datum)=?
          GROUP BY ust_prozent
          ORDER BY ust_prozent DESC", [$jahr]);
}

// ---------------------------------------------------------------------------
// GoBD: Nummernkreis-Prüfung (Lücken, Dubletten, Rückdatierung, Storno-Bezug)
// ---------------------------------------------------------------------------

// Prüft die fortlaufenden Belegnummern je Präfix. Rein lesend.
// Rückgabe: ['kreise'=>[...je Präfix...], 'rueckdatiert'=>[...], 'storno'=>[...]]
function bh_nummernkreis_pruefung(array $praefixe = ['RE', 'GS', 'DA', 'DB', 'DR']): array {
    $kreise = [];
    $rueckdatiert = [];
    foreach ($praefixe as $pfx) {
        $rows = all("SELECT id, nummer, datum, typ, status FROM beleg WHERE nummer LIKE ? ORDER BY nummer ASC", [$pfx . '-%']);
        $nums = [];       // laufende Nummer => [belege]
        $parsed = [];     // chronologie: [n, datum, nummer]
        foreach ($rows as $r) {
            if (!preg_match('/^' . preg_quote($pfx, '/') . '-0*(\d+)$/', (string)$r['nummer'], $mm)) continue;
            $n = (int)$mm[1];
            $nums[$n][] = $r;
            $parsed[] = ['n' => $n, 'datum' => $r['datum'], 'nummer' => $r['nummer']];
        }
        $vorhanden = array_keys($nums);
        sort($vorhanden);
        $luecken = [];
        $dubletten = [];
        if ($vorhanden) {
            $min = $vorhanden[0];
            $max = end($vorhanden);
            for ($i = $min; $i <= $max; $i++) {
                if (!isset($nums[$i])) $luecken[] = $pfx . '-' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
            }
            foreach ($nums as $n => $list) if (count($list) > 1) $dubletten[] = $pfx . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
        }
        // Chronologie: nach laufender Nummer sortiert darf das Datum nicht zurückspringen
        usort($parsed, fn($a, $b) => $a['n'] <=> $b['n']);
        $prev = null;
        foreach ($parsed as $p) {
            if ($p['datum'] && $prev && strtotime($p['datum']) < strtotime($prev['datum'])) {
                $rueckdatiert[] = ['nummer' => $p['nummer'], 'datum' => $p['datum'], 'vorher' => $prev['nummer'], 'vorher_datum' => $prev['datum']];
            }
            if ($p['datum']) $prev = $p;
        }
        $kreise[$pfx] = [
            'prefix'     => $pfx,
            'anzahl'     => count($vorhanden),
            'min'        => $vorhanden ? $pfx . '-' . str_pad((string)$vorhanden[0], 4, '0', STR_PAD_LEFT) : '–',
            'max'        => $vorhanden ? $pfx . '-' . str_pad((string)end($vorhanden), 4, '0', STR_PAD_LEFT) : '–',
            'luecken'    => $luecken,
            'dubletten'  => $dubletten,
            'zaehler'    => (int) scalar("SELECT naechste FROM nummernkreis WHERE prefix=?", [$pfx]),
        ];
    }
    // Storno-Integrität: stornierte Rechnungen + ob eine Gutschrift darauf verweist
    $storno = all(
        "SELECT b.id, b.nummer, b.datum, b.grund,
                (SELECT GROUP_CONCAT(g.nummer) FROM beleg g WHERE g.typ='gutschrift' AND g.storno_von_id=b.id) AS gutschriften
           FROM beleg b
          WHERE b.typ='rechnung' AND b.status='storniert'
          ORDER BY b.datum DESC, b.id DESC");
    return ['kreise' => $kreise, 'rueckdatiert' => $rueckdatiert, 'storno' => $storno];
}

// ---------------------------------------------------------------------------
// Exporte
// ---------------------------------------------------------------------------

// Eine CSV-Zeile bauen (Semikolon-getrennt, deutsches Excel-Format, Werte sauber escaped).
function bh_csv_zeile(array $felder): string {
    $out = [];
    foreach ($felder as $f) {
        $f = (string)$f;
        if (preg_match('/[";\r\n]/', $f)) $f = '"' . str_replace('"', '""', $f) . '"';
        $out[] = $f;
    }
    return implode(';', $out) . "\r\n";
}

function bh_csv_betrag(float $x): string { return number_format($x, 2, ',', ''); } // 1234,56 (kein Tausenderpunkt)

// Offene-Posten-Liste als CSV (UTF-8 mit BOM, Excel-freundlich).
function bh_export_op_csv(): string {
    $rows = all(
        "SELECT b.nummer, b.datum, b.faellig, k.firma, b.brutto,
                COALESCE(z.bez,0) AS bezahlt, (b.brutto - COALESCE(z.bez,0)) AS offen,
                CASE WHEN b.faellig IS NOT NULL AND b.faellig < CURDATE() THEN DATEDIFF(CURDATE(), b.faellig) ELSE 0 END AS tage_ueberfaellig
           FROM beleg b
           LEFT JOIN kunden k ON k.id=b.kunde_id
           LEFT JOIN (SELECT beleg_id, SUM(betrag) bez FROM zahlung GROUP BY beleg_id) z ON z.beleg_id=b.id
          WHERE b.typ='rechnung' AND b.status IN ('offen','teilbezahlt')
          ORDER BY b.faellig ASC, b.nummer ASC");
    $csv  = "\xEF\xBB\xBF"; // BOM
    $csv .= bh_csv_zeile(['Rechnungsnr', 'Datum', 'Faellig', 'Kunde', 'Brutto', 'Bezahlt', 'Offen', 'Tage ueberfaellig']);
    foreach ($rows as $r) {
        $csv .= bh_csv_zeile([
            $r['nummer'],
            $r['datum'] ? date('d.m.Y', strtotime($r['datum'])) : '',
            $r['faellig'] ? date('d.m.Y', strtotime($r['faellig'])) : '',
            $r['firma'],
            bh_csv_betrag((float)$r['brutto']),
            bh_csv_betrag((float)$r['bezahlt']),
            bh_csv_betrag((float)$r['offen']),
            (int)$r['tage_ueberfaellig'],
        ]);
    }
    return $csv;
}

// Belege/Umsatz eines Zeitraums als CSV (UTF-8 mit BOM). $von/$bis = 'Y-m-d' oder leer.
function bh_export_belege_csv(string $von = '', string $bis = ''): string {
    $where = "typ IN ('rechnung','gutschrift')";
    $args = [];
    if ($von !== '') { $where .= " AND datum >= ?"; $args[] = $von; }
    if ($bis !== '') { $where .= " AND datum <= ?"; $args[] = $bis; }
    $rows = all(
        "SELECT b.*, k.firma, k.ust_id AS kunde_ustid, k.land AS kunde_land
           FROM beleg b LEFT JOIN kunden k ON k.id=b.kunde_id
          WHERE $where ORDER BY b.datum ASC, b.nummer ASC", $args);
    $csv  = "\xEF\xBB\xBF";
    $csv .= bh_csv_zeile(['Belegnr', 'Typ', 'Datum', 'Leistungsdatum', 'Kunde', 'USt-IdNr Kunde', 'Land',
                          'Netto', 'USt-Satz', 'USt-Betrag', 'Brutto', 'Status']);
    foreach ($rows as $r) {
        $vz = ($r['typ'] === 'gutschrift') ? -1 : 1;
        $csv .= bh_csv_zeile([
            $r['nummer'],
            $r['typ'] === 'gutschrift' ? 'Gutschrift' : 'Rechnung',
            $r['datum'] ? date('d.m.Y', strtotime($r['datum'])) : '',
            !empty($r['leistung_datum']) ? date('d.m.Y', strtotime($r['leistung_datum'])) : '',
            $r['firma'],
            $r['kunde_ustid'] ?? '',
            $r['kunde_land'] ?? '',
            bh_csv_betrag($vz * (float)$r['netto']),
            number_format((float)$r['ust_prozent'], 0) . '%',
            bh_csv_betrag($vz * (float)$r['ust_betrag']),
            bh_csv_betrag($vz * (float)$r['brutto']),
            status_text((string)$r['status']),
        ]);
    }
    return $csv;
}

// Feldnamen-Kopf des DATEV-EXTF-Buchungsstapels (Format 700): genau 125 Felder, feste Reihenfolge.
function bh_datev_felder(): array {
    return [
        'Umsatz (ohne Soll/Haben-Kz)','Soll/Haben-Kennzeichen','WKZ Umsatz','Kurs','Basis-Umsatz','WKZ Basis-Umsatz',
        'Konto','Gegenkonto (ohne BU-Schlüssel)','BU-Schlüssel','Belegdatum','Belegfeld 1','Belegfeld 2','Skonto','Buchungstext',
        'Postensperre','Diverse Adressnummer','Geschäftspartnerbank','Sachverhalt','Zinssperre','Beleglink',
        'Beleginfo - Art 1','Beleginfo - Inhalt 1','Beleginfo - Art 2','Beleginfo - Inhalt 2','Beleginfo - Art 3','Beleginfo - Inhalt 3',
        'Beleginfo - Art 4','Beleginfo - Inhalt 4','Beleginfo - Art 5','Beleginfo - Inhalt 5','Beleginfo - Art 6','Beleginfo - Inhalt 6',
        'Beleginfo - Art 7','Beleginfo - Inhalt 7','Beleginfo - Art 8','Beleginfo - Inhalt 8',
        'KOST1 - Kostenstelle','KOST2 - Kostenstelle','KOST-Menge','EU-Land u. UStID (Bestimmung)','EU-Steuersatz (Bestimmung)',
        'Abw. Versteuerungsart','Sachverhalt L+L','Funktionsergänzung L+L','BU 49 Hauptfunktionstyp','BU 49 Hauptfunktionsnummer','BU 49 Funktionsergänzung',
        'Zusatzinformation - Art 1','Zusatzinformation- Inhalt 1','Zusatzinformation - Art 2','Zusatzinformation- Inhalt 2',
        'Zusatzinformation - Art 3','Zusatzinformation- Inhalt 3','Zusatzinformation - Art 4','Zusatzinformation- Inhalt 4',
        'Zusatzinformation - Art 5','Zusatzinformation- Inhalt 5','Zusatzinformation - Art 6','Zusatzinformation- Inhalt 6',
        'Zusatzinformation - Art 7','Zusatzinformation- Inhalt 7','Zusatzinformation - Art 8','Zusatzinformation- Inhalt 8',
        'Zusatzinformation - Art 9','Zusatzinformation- Inhalt 9','Zusatzinformation - Art 10','Zusatzinformation- Inhalt 10',
        'Zusatzinformation - Art 11','Zusatzinformation- Inhalt 11','Zusatzinformation - Art 12','Zusatzinformation- Inhalt 12',
        'Zusatzinformation - Art 13','Zusatzinformation- Inhalt 13','Zusatzinformation - Art 14','Zusatzinformation- Inhalt 14',
        'Zusatzinformation - Art 15','Zusatzinformation- Inhalt 15','Zusatzinformation - Art 16','Zusatzinformation- Inhalt 16',
        'Zusatzinformation - Art 17','Zusatzinformation- Inhalt 17','Zusatzinformation - Art 18','Zusatzinformation- Inhalt 18',
        'Zusatzinformation - Art 19','Zusatzinformation- Inhalt 19','Zusatzinformation - Art 20','Zusatzinformation- Inhalt 20',
        'Stück','Gewicht','Zahlweise','Forderungsart','Veranlagungsjahr','Zugeordnete Fälligkeit','Skontotyp','Auftragsnummer',
        'Buchungstyp','USt-Schlüssel (Anzahlungen)','EU-Land (Anzahlungen)','Sachverhalt L+L (Anzahlungen)','EU-Steuersatz (Anzahlungen)','Erlöskonto (Anzahlungen)',
        'Herkunft-Kz','Buchungs GUID','KOST-Datum','SEPA-Mandatsreferenz','Skontosperre','Gesellschaftername','Beteiligtennummer',
        'Identifikationsnummer','Zeichnernummer','Postensperre bis','Bezeichnung SoBil-Sachverhalt','Kennzeichen SoBil-Buchung',
        'Festschreibung','Leistungsdatum','Datum Zuord. Steuerperiode','Fälligkeit','Generalumkehr (GU)','Steuersatz','Land',
        'Abrechnungsreferenz','BVV-Position','EU-Land u. UStID (Ursprung)','EU-Steuersatz (Ursprung)','Abw. Skontokonto',
    ];
}

// EXTF-Kopfzeile (Zeile 1) des DATEV-Buchungsstapels. $bez = Stapel-Bezeichnung (z. B. Rechnungsausgang).
function bh_datev_kopf(string $bez, string $datVon, string $datBis): array {
    $firma = beleg_firma();
    $berater = (int) meta_get('datev_berater', '0');
    $mandant = (int) meta_get('datev_mandant', '0');
    $sachkl  = (int) meta_get('datev_sachkontenlaenge', '4');
    return ['EXTF', 700, 21, 'Buchungsstapel', 13, date('YmdHis') . '000', '', 'bulkify', $firma['name'] ?: 'bulkify',
            $berater, $mandant, date('Y') . '0101', $sachkl, $datVon, $datBis, $bez, '', 1, 0, 'EUR',
            '', '', '', '', 0, '', 1, '', '', ''];
}

// DATEV-EXTF-Buchungsstapel (Format 700). Rechnungsausgang je Beleg eine Buchung.
// Konten per Einstellungen überschreibbar (SKR03-Defaults). Rückgabe: CSV-String in CP1252.
// WICHTIG: Kontenrahmen/Konten vor Produktiv-Import mit dem Steuerberater abstimmen.
function bh_export_datev(string $von = '', string $bis = ''): string {
    $debitor = (int) meta_get('datev_debitor_sammel', '1400'); // SKR03 Forderungen aLuL
    $erloes19 = (int) meta_get('datev_erloes_19', '8400');     // SKR03 Erlöse 19% USt (Automatikkonto)
    $erloesEU = (int) meta_get('datev_erloes_eu', '8125');     // SKR03 steuerfreie innergem. Lieferung
    $erloes0  = (int) meta_get('datev_erloes_0', '8200');      // SKR03 Erlöse (ohne USt / Kleinunternehmer)

    $where = "b.typ IN ('rechnung','gutschrift') AND b.status<>'storniert'";
    $args = [];
    if ($von !== '') { $where .= " AND b.datum >= ?"; $args[] = $von; }
    if ($bis !== '') { $where .= " AND b.datum <= ?"; $args[] = $bis; }
    $rows = all(
        "SELECT b.*, k.firma, k.ust_id AS kunde_ustid, k.land AS kunde_land
           FROM beleg b LEFT JOIN kunden k ON k.id=b.kunde_id
          WHERE $where ORDER BY b.datum ASC, b.nummer ASC", $args);

    $datVon = $von !== '' ? date('Ymd', strtotime($von)) : ($rows ? date('Ymd', strtotime($rows[0]['datum'] ?: 'now')) : date('Ymd'));
    $datBis = $bis !== '' ? date('Ymd', strtotime($bis)) : ($rows ? date('Ymd', strtotime(end($rows)['datum'] ?: 'now')) : date('Ymd'));
    $felder = bh_datev_felder();
    $spalten = count($felder); // 125

    $csv  = bh_csv_zeile(bh_datev_kopf('Rechnungsausgang', $datVon, $datBis));
    $csv .= bh_csv_zeile($felder);

    foreach ($rows as $r) {
        $land = strtoupper((string)($r['kunde_land'] ?: 'DE'));
        $istEU = ($land !== 'DE' && (float)$r['ust_betrag'] <= 0.005 && strlen((string)$r['kunde_ustid']) > 3);
        $ustP = (float)$r['ust_prozent'];
        if ($ustP > 0)      $erloes = $erloes19;
        elseif ($istEU)     $erloes = $erloesEU;
        else                $erloes = $erloes0;

        $gutschrift = ($r['typ'] === 'gutschrift');
        // Ausgangsrechnung: Debitor an Erlöse -> Konto=Debitor, SH='S'. Gutschrift kehrt um -> 'H'.
        $sh = $gutschrift ? 'H' : 'S';
        $umsatz = bh_csv_betrag(abs((float)$r['brutto']));

        $zeile = array_fill(0, $spalten, '');
        $zeile[0]  = $umsatz;                                   // Umsatz
        $zeile[1]  = $sh;                                       // Soll/Haben
        $zeile[2]  = 'EUR';                                     // WKZ
        $zeile[6]  = $debitor;                                  // Konto (Sammeldebitor)
        $zeile[7]  = $erloes;                                   // Gegenkonto (Erlöskonto)
        $zeile[9]  = $r['datum'] ? date('dm', strtotime($r['datum'])) : ''; // Belegdatum TTMM
        $zeile[10] = $r['nummer'];                              // Belegfeld 1 = Belegnummer
        $zeile[13] = mb_substr(trim(($gutschrift ? 'Gutschrift ' : 'Rechnung ') . ($r['firma'] ?? '')), 0, 60); // Buchungstext
        if ($istEU) { $zeile[39] = $land . (string)$r['kunde_ustid']; } // EU-Land u. UStID (Bestimmung)
        $zeile[113] = '1';                                      // Festschreibung = ja
        $zeile[114] = !empty($r['leistung_datum']) ? date('Ymd', strtotime($r['leistung_datum']))
                    : ($r['datum'] ? date('Ymd', strtotime($r['datum'])) : ''); // Leistungsdatum
        $csv .= bh_csv_zeile($zeile);
    }

    // DATEV erwartet CP1252/ANSI
    $cp = @mb_convert_encoding($csv, 'CP1252', 'UTF-8');
    return $cp !== false ? $cp : $csv;
}
