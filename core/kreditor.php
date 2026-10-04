<?php
// Kreditoren / Verbindlichkeiten: Eingangsrechnungen von Lieferanten + unsere Zahlungen darauf.
// Spiegelbild zur Debitorenseite (beleg/zahlung). Eigene Tabellen (lieferant_rechnung,
// lieferant_zahlung) – kollisionsarm, kein Eingriff in core/schema.php. Geld in EUR (DECIMAL).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';      // naechste_nummer(), meta_get()
require_once __DIR__ . '/buchhaltung.php'; // bh_csv_*, bh_datev_* (für Exporte)

// Tabellen anlegen (idempotent). Am Anfang jeder Kreditor-Seite/Endpunkt aufrufen.
function kreditor_init(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS lieferant_rechnung (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nummer VARCHAR(30) NULL,                              -- interne Erfassungsnummer ER-xxxx
        lieferant_id INT NULL,
        bestellung_id INT NULL,                               -- optionaler Bezug zur Bestellung
        lief_nummer VARCHAR(80) NULL,                         -- Rechnungsnummer des Lieferanten
        datum DATE NULL,                                      -- Rechnungsdatum
        eingang_am DATE NULL,                                 -- bei uns eingegangen
        waehrung VARCHAR(3) NOT NULL DEFAULT 'EUR',
        netto DECIMAL(14,2) NOT NULL DEFAULT 0,
        ust_prozent DECIMAL(5,2) NOT NULL DEFAULT 0,
        ust_betrag DECIMAL(14,2) NOT NULL DEFAULT 0,
        brutto DECIMAL(14,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'offen',          -- offen|teilbezahlt|bezahlt|storniert
        zahlungsziel_tage INT NULL,
        faellig DATE NULL,
        notiz TEXT NULL,
        erfasst_von INT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_lieferant (lieferant_id), KEY idx_status (status), KEY idx_faellig (faellig)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lieferant_zahlung (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lief_rechnung_id INT NOT NULL,
        betrag DECIMAL(14,2) NOT NULL DEFAULT 0,
        datum DATE NULL,
        konto VARCHAR(40) NULL,
        art VARCHAR(30) NULL,
        notiz VARCHAR(255) NULL,
        akteur VARCHAR(80) NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_rechnung (lief_rechnung_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// --- Zahlstatus -----------------------------------------------------------
function kr_zahlung_summe(int $id): float {
    return (float) scalar("SELECT COALESCE(SUM(betrag),0) FROM lieferant_zahlung WHERE lief_rechnung_id=?", [$id]);
}

// Abgeleiteter Zahlstatus (wie beleg_zahlstatus). 'storniert' bleibt erhalten.
function kr_zahlstatus(array $r): array {
    $brutto = (float) $r['brutto'];
    $bezahlt = kr_zahlung_summe((int) $r['id']);
    $rest = round($brutto - $bezahlt, 2);
    if (($r['status'] ?? '') === 'storniert') $status = 'storniert';
    elseif ($bezahlt <= 0.005)                $status = 'offen';
    elseif ($rest > 0.005)                    $status = 'teilbezahlt';
    else                                      $status = 'bezahlt';
    return ['status' => $status, 'bezahlt' => $bezahlt, 'rest' => max(0, $rest), 'brutto' => $brutto];
}

// Status aus den Zahlungen neu berechnen und fortschreiben.
function kr_status_fortschreiben(int $id): array {
    $r = one("SELECT * FROM lieferant_rechnung WHERE id=?", [$id]);
    if (!$r) return ['status' => 'offen', 'bezahlt' => 0, 'rest' => 0, 'brutto' => 0];
    $zs = kr_zahlstatus($r);
    if ($zs['status'] !== ($r['status'] ?? '') && ($r['status'] ?? '') !== 'storniert') {
        q("UPDATE lieferant_rechnung SET status=? WHERE id=?", [$zs['status'], $id]);
    }
    return $zs;
}

function kr_faellig(?string $datum, $ziel): ?string {
    $ziel = (int) $ziel;
    if (!$datum || $ziel <= 0) return $datum ?: null;
    return date('Y-m-d', strtotime($datum . ' +' . $ziel . ' days'));
}

// --- Anlegen / Ändern ------------------------------------------------------
// Erfasst eine Eingangsrechnung. $d: lieferant_id, bestellung_id?, lief_nummer?, datum?, eingang_am?,
// netto, ust_prozent, brutto? (sonst berechnet), zahlungsziel_tage?, notiz?, erfasst_von?.
function kr_rechnung_anlegen(array $d): int {
    kreditor_init();
    $netto = round((float)($d['netto'] ?? 0), 2);
    $ustP  = (float)($d['ust_prozent'] ?? 0);
    $ust   = round($netto * $ustP / 100, 2);
    $brutto = isset($d['brutto']) && $d['brutto'] !== '' ? round((float)$d['brutto'], 2) : round($netto + $ust, 2);
    $datum = $d['datum'] ?? date('Y-m-d');
    $ziel  = isset($d['zahlungsziel_tage']) ? (int)$d['zahlungsziel_tage'] : null;
    $faellig = $ziel !== null ? kr_faellig($datum, $ziel) : ($d['faellig'] ?? null);
    q("INSERT INTO lieferant_rechnung
         (nummer, lieferant_id, bestellung_id, lief_nummer, datum, eingang_am, waehrung,
          netto, ust_prozent, ust_betrag, brutto, status, zahlungsziel_tage, faellig, notiz, erfasst_von)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('ER'), $d['lieferant_id'] ?: null, $d['bestellung_id'] ?? null,
       trim((string)($d['lief_nummer'] ?? '')) ?: null, $datum ?: null, $d['eingang_am'] ?? date('Y-m-d'),
       (string)($d['waehrung'] ?? 'EUR'), $netto, $ustP, $ust, $brutto, 'offen', $ziel, $faellig,
       trim((string)($d['notiz'] ?? '')) ?: null, $d['erfasst_von'] ?? null]);
    return insert_id();
}

// Kopf einer offenen (nicht stornierten) Eingangsrechnung ändern.
function kr_rechnung_update(int $id, array $d): void {
    $r = one("SELECT * FROM lieferant_rechnung WHERE id=?", [$id]);
    if (!$r || $r['status'] === 'storniert') return;
    $netto = round((float)($d['netto'] ?? $r['netto']), 2);
    $ustP  = (float)($d['ust_prozent'] ?? $r['ust_prozent']);
    $ust   = round($netto * $ustP / 100, 2);
    $brutto = isset($d['brutto']) && $d['brutto'] !== '' ? round((float)$d['brutto'], 2) : round($netto + $ust, 2);
    $datum = $d['datum'] ?? $r['datum'];
    $ziel  = array_key_exists('zahlungsziel_tage', $d) ? (int)$d['zahlungsziel_tage'] : $r['zahlungsziel_tage'];
    $faellig = $ziel !== null ? kr_faellig($datum, $ziel) : ($d['faellig'] ?? $r['faellig']);
    q("UPDATE lieferant_rechnung SET lieferant_id=?, bestellung_id=?, lief_nummer=?, datum=?, eingang_am=?,
          netto=?, ust_prozent=?, ust_betrag=?, brutto=?, zahlungsziel_tage=?, faellig=?, notiz=? WHERE id=?",
      [$d['lieferant_id'] ?? $r['lieferant_id'], $d['bestellung_id'] ?? $r['bestellung_id'],
       trim((string)($d['lief_nummer'] ?? $r['lief_nummer'])) ?: null, $datum ?: null, $d['eingang_am'] ?? $r['eingang_am'],
       $netto, $ustP, $ust, $brutto, $ziel, $faellig, trim((string)($d['notiz'] ?? $r['notiz'])) ?: null, $id]);
    kr_status_fortschreiben($id);
}

function kr_rechnung_stornieren(int $id, string $grund = ''): void {
    $g = trim($grund);
    q("UPDATE lieferant_rechnung SET status='storniert', notiz=TRIM(CONCAT(COALESCE(notiz,''), ?)) WHERE id=?",
      [$g !== '' ? "\n[storniert] " . $g : "\n[storniert]", $id]);
}

// Zahlung an den Lieferanten buchen; Status wird fortgeschrieben.
function kr_zahlung_buchen(int $id, float $betrag, ?string $datum, string $art = '', string $notiz = '', string $akteur = 'team'): void {
    if ($betrag == 0.0) return;
    q("INSERT INTO lieferant_zahlung (lief_rechnung_id, betrag, datum, art, notiz, akteur) VALUES (?,?,?,?,?,?)",
      [$id, round($betrag, 2), $datum ?: date('Y-m-d'), trim($art) ?: null, trim($notiz) ?: null, $akteur]);
    kr_status_fortschreiben($id);
}

function kr_zahlungen(int $id): array {
    return all("SELECT * FROM lieferant_zahlung WHERE lief_rechnung_id=? ORDER BY datum, id", [$id]);
}

// --- Kennzahlen / Listen ---------------------------------------------------
function kr_op_summe(): float {
    return (float) scalar(
        "SELECT COALESCE(SUM(r.brutto - COALESCE(z.bez,0)),0) FROM lieferant_rechnung r
           LEFT JOIN (SELECT lief_rechnung_id, SUM(betrag) bez FROM lieferant_zahlung GROUP BY lief_rechnung_id) z ON z.lief_rechnung_id=r.id
          WHERE r.status IN ('offen','teilbezahlt')");
}
function kr_op_ueberfaellig_summe(): float {
    return (float) scalar(
        "SELECT COALESCE(SUM(r.brutto - COALESCE(z.bez,0)),0) FROM lieferant_rechnung r
           LEFT JOIN (SELECT lief_rechnung_id, SUM(betrag) bez FROM lieferant_zahlung GROUP BY lief_rechnung_id) z ON z.lief_rechnung_id=r.id
          WHERE r.status IN ('offen','teilbezahlt') AND r.faellig IS NOT NULL AND r.faellig < CURDATE()");
}
function kr_anz_offen(): int {
    return (int) scalar("SELECT COUNT(*) FROM lieferant_rechnung WHERE status IN ('offen','teilbezahlt')");
}

function kr_op_je_lieferant(): array {
    return all(
        "SELECT l.id AS lieferant_id, l.firma,
                COUNT(*) AS anz,
                SUM(r.brutto - COALESCE(z.bez,0)) AS offen,
                SUM(CASE WHEN r.faellig IS NOT NULL AND r.faellig < CURDATE() THEN r.brutto - COALESCE(z.bez,0) ELSE 0 END) AS ueberfaellig
           FROM lieferant_rechnung r
           LEFT JOIN lieferanten l ON l.id=r.lieferant_id
           LEFT JOIN (SELECT lief_rechnung_id, SUM(betrag) bez FROM lieferant_zahlung GROUP BY lief_rechnung_id) z ON z.lief_rechnung_id=r.id
          WHERE r.status IN ('offen','teilbezahlt')
          GROUP BY l.id, l.firma ORDER BY offen DESC");
}

// Liste der Eingangsrechnungen (optional Status-Filter + Suche).
function kr_liste(string $status = '', string $q = ''): array {
    $where = '1=1'; $args = [];
    if ($status === 'offen')  { $where .= " AND r.status IN ('offen','teilbezahlt')"; }
    elseif ($status !== '')   { $where .= " AND r.status=?"; $args[] = $status; }
    $rows = all(
        "SELECT r.*, l.firma, COALESCE(z.bez,0) AS bezahlt, (r.brutto - COALESCE(z.bez,0)) AS rest
           FROM lieferant_rechnung r
           LEFT JOIN lieferanten l ON l.id=r.lieferant_id
           LEFT JOIN (SELECT lief_rechnung_id, SUM(betrag) bez FROM lieferant_zahlung GROUP BY lief_rechnung_id) z ON z.lief_rechnung_id=r.id
          WHERE $where ORDER BY (r.faellig IS NULL), r.faellig ASC, r.id DESC", $args);
    if ($q !== '') {
        $n = mb_strtolower($q);
        $rows = array_values(array_filter($rows, fn($r) =>
            mb_strpos(mb_strtolower((string)$r['firma']), $n) !== false
            || mb_strpos(mb_strtolower((string)$r['nummer']), $n) !== false
            || mb_strpos(mb_strtolower((string)$r['lief_nummer']), $n) !== false));
    }
    return $rows;
}

function kr_rechnung_get(int $id): ?array {
    return one("SELECT r.*, l.firma, l.land AS lief_land, l.ust_id AS lief_ustid, b.nummer AS bestell_nummer
                  FROM lieferant_rechnung r
                  LEFT JOIN lieferanten l ON l.id=r.lieferant_id
                  LEFT JOIN bestellung b ON b.id=r.bestellung_id
                 WHERE r.id=?", [$id]);
}

// Wert einer Bestellung (Summe menge*ek) – für die Vorbefüllung beim Erfassen.
function kr_bestellung_netto(int $bestellung_id): float {
    return (float) scalar("SELECT COALESCE(SUM(menge*ek_preis),0) FROM bestellung_position WHERE bestellung_id=?", [$bestellung_id]);
}

// --- Exporte ---------------------------------------------------------------
// Offene Verbindlichkeiten als CSV (UTF-8 + BOM).
function kr_export_vop_csv(): string {
    kreditor_init();
    $rows = kr_liste('offen');
    $csv  = "\xEF\xBB\xBF";
    $csv .= bh_csv_zeile(['Erfassungsnr', 'Lieferanten-Nr', 'Lieferant', 'Datum', 'Faellig', 'Brutto', 'Bezahlt', 'Offen', 'Tage ueberfaellig']);
    foreach ($rows as $r) {
        $tage = ($r['faellig'] && strtotime($r['faellig']) < strtotime(date('Y-m-d'))) ? (int)((strtotime(date('Y-m-d')) - strtotime($r['faellig'])) / 86400) : 0;
        $csv .= bh_csv_zeile([
            $r['nummer'], $r['lief_nummer'], $r['firma'],
            $r['datum'] ? date('d.m.Y', strtotime($r['datum'])) : '',
            $r['faellig'] ? date('d.m.Y', strtotime($r['faellig'])) : '',
            bh_csv_betrag((float)$r['brutto']), bh_csv_betrag((float)$r['bezahlt']),
            bh_csv_betrag((float)$r['rest']), $tage,
        ]);
    }
    return $csv;
}

// Eingangsrechnungen eines Zeitraums als CSV (UTF-8 + BOM).
function kr_export_belege_csv(string $von = '', string $bis = ''): string {
    kreditor_init();
    $where = "r.status<>'storniert'"; $args = [];
    if ($von !== '') { $where .= " AND r.datum >= ?"; $args[] = $von; }
    if ($bis !== '') { $where .= " AND r.datum <= ?"; $args[] = $bis; }
    $rows = all("SELECT r.*, l.firma, l.ust_id AS lief_ustid, l.land AS lief_land
                   FROM lieferant_rechnung r LEFT JOIN lieferanten l ON l.id=r.lieferant_id
                  WHERE $where ORDER BY r.datum ASC, r.id ASC", $args);
    $csv  = "\xEF\xBB\xBF";
    $csv .= bh_csv_zeile(['Erfassungsnr', 'Lieferanten-Nr', 'Lieferant', 'USt-IdNr', 'Land', 'Datum', 'Netto', 'VSt-Satz', 'VSt-Betrag', 'Brutto', 'Status']);
    foreach ($rows as $r) {
        $csv .= bh_csv_zeile([
            $r['nummer'], $r['lief_nummer'], $r['firma'], $r['lief_ustid'] ?? '', $r['lief_land'] ?? '',
            $r['datum'] ? date('d.m.Y', strtotime($r['datum'])) : '',
            bh_csv_betrag((float)$r['netto']), number_format((float)$r['ust_prozent'], 0) . '%',
            bh_csv_betrag((float)$r['ust_betrag']), bh_csv_betrag((float)$r['brutto']), status_text((string)$r['status']),
        ]);
    }
    return $csv;
}

// DATEV-EXTF-Buchungsstapel für Eingangsrechnungen (Kreditoren): Wareneingang/Aufwand an Kreditor.
function kr_export_datev(string $von = '', string $bis = ''): string {
    kreditor_init();
    $kreditor = (int) meta_get('datev_kreditor_sammel', '1600'); // SKR03 Verbindlichkeiten aLuL
    $aufwand19 = (int) meta_get('datev_aufwand_19', '3400');     // SKR03 Wareneingang 19% VSt (Automatik)
    $aufwandEU = (int) meta_get('datev_aufwand_eu', '3425');     // SKR03 innergem. Erwerb 19% VSt/USt
    $aufwand0  = (int) meta_get('datev_aufwand_0', '3300');      // SKR03 Wareneingang ohne VSt

    $where = "r.status<>'storniert'"; $args = [];
    if ($von !== '') { $where .= " AND r.datum >= ?"; $args[] = $von; }
    if ($bis !== '') { $where .= " AND r.datum <= ?"; $args[] = $bis; }
    $rows = all("SELECT r.*, l.firma, l.land AS lief_land, l.ust_id AS lief_ustid
                   FROM lieferant_rechnung r LEFT JOIN lieferanten l ON l.id=r.lieferant_id
                  WHERE $where ORDER BY r.datum ASC, r.id ASC", $args);

    $datVon = $von !== '' ? date('Ymd', strtotime($von)) : ($rows ? date('Ymd', strtotime($rows[0]['datum'] ?: 'now')) : date('Ymd'));
    $datBis = $bis !== '' ? date('Ymd', strtotime($bis)) : ($rows ? date('Ymd', strtotime(end($rows)['datum'] ?: 'now')) : date('Ymd'));
    $felder = bh_datev_felder();
    $spalten = count($felder);

    $csv  = bh_csv_zeile(bh_datev_kopf('Rechnungseingang', $datVon, $datBis));
    $csv .= bh_csv_zeile($felder);
    foreach ($rows as $r) {
        $land = strtoupper((string)($r['lief_land'] ?: 'DE'));
        $istEU = ($land !== 'DE' && strlen((string)$r['lief_ustid']) > 3);
        $ustP = (float)$r['ust_prozent'];
        if ($istEU)         $aufwand = $aufwandEU;
        elseif ($ustP > 0)  $aufwand = $aufwand19;
        else                $aufwand = $aufwand0;

        // Eingangsrechnung: Aufwand/Wareneingang an Kreditor -> Konto=Aufwand (Soll).
        $zeile = array_fill(0, $spalten, '');
        $zeile[0]  = bh_csv_betrag(abs((float)$r['brutto']));
        $zeile[1]  = 'S';
        $zeile[2]  = 'EUR';
        $zeile[6]  = $aufwand;                                   // Konto = Aufwand/Wareneingang
        $zeile[7]  = $kreditor;                                  // Gegenkonto = Kreditor-Sammel
        $zeile[9]  = $r['datum'] ? date('dm', strtotime($r['datum'])) : '';
        $zeile[10] = $r['lief_nummer'] ?: $r['nummer'];          // Belegfeld 1 = Lieferanten-Rechnungsnr
        $zeile[13] = mb_substr(trim('ER ' . ($r['firma'] ?? '') . ' ' . ($r['lief_nummer'] ?? '')), 0, 60);
        if ($istEU) { $zeile[39] = $land . (string)$r['lief_ustid']; }
        $zeile[113] = '1';
        $zeile[114] = $r['datum'] ? date('Ymd', strtotime($r['datum'])) : '';
        $csv .= bh_csv_zeile($zeile);
    }
    $cp = @mb_convert_encoding($csv, 'CP1252', 'UTF-8');
    return $cp !== false ? $cp : $csv;
}
