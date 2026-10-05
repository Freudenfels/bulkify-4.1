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
    // Fremdwährung (z. B. USD bei China-Lieferanten): Originalbetrag + Kurs; netto/ust/brutto bleiben in EUR.
    ensure_column('lieferant_rechnung', 'fx_kurs', "DECIMAL(14,6) NULL");  // 1 Fremdwährung = X EUR (NULL/1 bei EUR)
    ensure_column('lieferant_rechnung', 'fw_netto', "DECIMAL(14,2) NULL"); // Netto in Rechnungswährung
    // Hochgeladene Original-Rechnung (PDF/Bild) des Lieferanten – relativ zu BX_UPLOADS (belege/…).
    ensure_column('lieferant_rechnung', 'datei', "VARCHAR(255) NULL");
    ensure_column('lieferant_rechnung', 'orig_name', "VARCHAR(255) NULL");
    ensure_column('lieferant_rechnung', 'mime', "VARCHAR(100) NULL");
}

// Verfügbare Währungen + Symbole.
function kr_waehrungen(): array { return ['EUR' => '€', 'USD' => '$', 'CNY' => '¥', 'GBP' => '£', 'CHF' => 'CHF']; }

// Statischer Offline-Fallback (1 Fremdwährung = X EUR), falls kein Live-Kurs verfügbar.
function kr_kurs_fallback(string $cur): float {
    $cur = strtoupper($cur);
    if ($cur === 'EUR') return 1.0;
    $vor = ['USD' => 0.92, 'CNY' => 0.127, 'GBP' => 1.17, 'CHF' => 1.05];
    return $vor[$cur] ?? 0.0;
}

// Holt den 30-Tage-Durchschnittskurs (EUR je 1 Fremdwährung) von der EZB über die Frankfurter-API
// (kein API-Key). Tagesweise 1/Kurs gemittelt. Rückgabe null bei jedem Fehler (Aufrufer fällt zurück).
function kr_kurs_fetch_avg(string $cur): ?float {
    $cur = strtoupper($cur);
    if ($cur === 'EUR') return 1.0;
    $start = date('Y-m-d', strtotime('-30 days'));
    $end   = date('Y-m-d');
    $url = "https://api.frankfurter.app/$start..$end?from=EUR&to=$cur";
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT => 'bulkify-dashboard']);
    $resp = curl_exec($ch); $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($resp === false || $http !== 200) return null;
    $j = json_decode($resp, true);
    if (!is_array($j) || empty($j['rates'])) return null;
    $sum = 0.0; $n = 0;
    foreach ($j['rates'] as $tag) {
        $r = (float)($tag[$cur] ?? 0); // Fremdwährung je 1 EUR
        if ($r > 0) { $sum += 1.0 / $r; $n++; }  // EUR je 1 Fremdwährung
    }
    return $n > 0 ? round($sum / $n, 6) : null;
}

// Vorschlags-Kurs (1 Fremdwährung = X EUR). Reihenfolge: manueller Fixkurs (app_meta kurs_<cur>_fix)
// > Live-Ø der letzten 30 Tage (gecacht 24 h) > alter Cache > Offline-Fallback. Darf Netz nutzen.
function kr_kurs_aktuell(string $cur): float {
    $cur = strtoupper($cur);
    if ($cur === 'EUR') return 1.0;
    $fix = meta_get('kurs_' . strtolower($cur) . '_fix', null);
    if ($fix !== null && (float) str_replace(',', '.', (string)$fix) > 0) return (float) str_replace(',', '.', (string)$fix);
    $key = 'kurs_' . strtolower($cur) . '_auto';
    $ts  = (int) meta_get($key . '_ts', '0');
    $val = (float) meta_get($key, '0');
    if ($val > 0 && (time() - $ts) < 86400) return $val; // frisch (< 24 h)
    $neu = kr_kurs_fetch_avg($cur);
    if ($neu !== null && $neu > 0) {
        meta_set($key, (string)$neu);
        meta_set($key . '_ts', (string)time());
        meta_set($key . '_stand', date('d.m.Y'));
        return $neu;
    }
    return $val > 0 ? $val : kr_kurs_fallback($cur);
}

// Nur-Cache-Lesen (kein Netz) – für Anzeige/JS-Vorbelegung. Gibt wert + Stand + Quelle.
function kr_kurs_cached(string $cur): array {
    $cur = strtoupper($cur);
    if ($cur === 'EUR') return ['wert' => 1.0, 'stand' => '', 'quelle' => 'eur'];
    $fix = meta_get('kurs_' . strtolower($cur) . '_fix', null);
    if ($fix !== null && (float) str_replace(',', '.', (string)$fix) > 0) return ['wert' => (float) str_replace(',', '.', (string)$fix), 'stand' => '', 'quelle' => 'manuell'];
    $val = (float) meta_get('kurs_' . strtolower($cur) . '_auto', '0');
    if ($val > 0) return ['wert' => $val, 'stand' => (string) meta_get('kurs_' . strtolower($cur) . '_auto_stand', ''), 'quelle' => 'auto'];
    return ['wert' => kr_kurs_fallback($cur), 'stand' => '', 'quelle' => 'standard'];
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
    $waehrung = strtoupper(trim((string)($d['waehrung'] ?? 'EUR'))) ?: 'EUR';
    $kurs = ($waehrung === 'EUR') ? 1.0 : (float) str_replace(',', '.', (string)($d['fx_kurs'] ?? 0));
    if ($kurs <= 0) $kurs = 1.0;
    $fwNetto = round((float) str_replace(',', '.', (string)($d['netto'] ?? 0)), 2); // Betrag in Rechnungswährung
    $netto = round($fwNetto * $kurs, 2);                                            // in EUR
    $ustP  = (float) str_replace(',', '.', (string)($d['ust_prozent'] ?? 0));
    $ust   = round($netto * $ustP / 100, 2);
    $brutto = round($netto + $ust, 2);
    $datum = $d['datum'] ?? date('Y-m-d');
    $ziel  = isset($d['zahlungsziel_tage']) ? (int)$d['zahlungsziel_tage'] : null;
    $faellig = $ziel !== null ? kr_faellig($datum, $ziel) : ($d['faellig'] ?? null);
    q("INSERT INTO lieferant_rechnung
         (nummer, lieferant_id, bestellung_id, lief_nummer, datum, eingang_am, waehrung, fx_kurs, fw_netto,
          netto, ust_prozent, ust_betrag, brutto, status, zahlungsziel_tage, faellig, notiz, erfasst_von,
          datei, orig_name, mime)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('ER'), $d['lieferant_id'] ?: null, $d['bestellung_id'] ?? null,
       trim((string)($d['lief_nummer'] ?? '')) ?: null, $datum ?: null, $d['eingang_am'] ?? date('Y-m-d'),
       $waehrung, $kurs, $fwNetto, $netto, $ustP, $ust, $brutto, 'offen', $ziel, $faellig,
       trim((string)($d['notiz'] ?? '')) ?: null, $d['erfasst_von'] ?? null,
       $d['datei'] ?? null, $d['orig_name'] ?? null, $d['mime'] ?? null]);
    return insert_id();
}

// Kopf einer offenen (nicht stornierten) Eingangsrechnung ändern.
function kr_rechnung_update(int $id, array $d): void {
    $r = one("SELECT * FROM lieferant_rechnung WHERE id=?", [$id]);
    if (!$r || $r['status'] === 'storniert') return;
    $waehrung = strtoupper(trim((string)($d['waehrung'] ?? $r['waehrung'] ?? 'EUR'))) ?: 'EUR';
    $kurs = ($waehrung === 'EUR') ? 1.0 : (float) str_replace(',', '.', (string)($d['fx_kurs'] ?? $r['fx_kurs'] ?? 0));
    if ($kurs <= 0) $kurs = 1.0;
    $fwNetto = round((float) str_replace(',', '.', (string)($d['netto'] ?? $r['fw_netto'] ?? $r['netto'])), 2);
    $netto = round($fwNetto * $kurs, 2);
    $ustP  = (float) str_replace(',', '.', (string)($d['ust_prozent'] ?? $r['ust_prozent']));
    $ust   = round($netto * $ustP / 100, 2);
    $brutto = round($netto + $ust, 2);
    $datum = $d['datum'] ?? $r['datum'];
    $ziel  = array_key_exists('zahlungsziel_tage', $d) ? (int)$d['zahlungsziel_tage'] : $r['zahlungsziel_tage'];
    $faellig = $ziel !== null ? kr_faellig($datum, $ziel) : ($d['faellig'] ?? $r['faellig']);
    q("UPDATE lieferant_rechnung SET lieferant_id=?, bestellung_id=?, lief_nummer=?, datum=?, eingang_am=?,
          waehrung=?, fx_kurs=?, fw_netto=?, netto=?, ust_prozent=?, ust_betrag=?, brutto=?, zahlungsziel_tage=?, faellig=?, notiz=? WHERE id=?",
      [$d['lieferant_id'] ?? $r['lieferant_id'], $d['bestellung_id'] ?? $r['bestellung_id'],
       trim((string)($d['lief_nummer'] ?? $r['lief_nummer'])) ?: null, $datum ?: null, $d['eingang_am'] ?? $r['eingang_am'],
       $waehrung, $kurs, $fwNetto, $netto, $ustP, $ust, $brutto, $ziel, $faellig, trim((string)($d['notiz'] ?? $r['notiz'])) ?: null, $id]);
    kr_status_fortschreiben($id);
}

// Hochgeladene Original-Rechnung an eine Eingangsrechnung hängen (relativ zu BX_UPLOADS).
function kr_datei_setzen(int $id, string $datei, string $orig, string $mime): void {
    q("UPDATE lieferant_rechnung SET datei=?, orig_name=?, mime=? WHERE id=?",
      [$datei ?: null, mb_substr($orig, 0, 255) ?: null, $mime ?: null, $id]);
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
    $csv .= bh_csv_zeile(['Erfassungsnr', 'Lieferanten-Nr', 'Lieferant', 'USt-IdNr', 'Land', 'Datum',
                          'Waehrung', 'Netto (Waehrung)', 'Kurs (EUR je Einheit)',
                          'Netto EUR', 'VSt-Satz', 'VSt-Betrag EUR', 'Brutto EUR', 'Status']);
    foreach ($rows as $r) {
        $cur = (string)($r['waehrung'] ?: 'EUR');
        $csv .= bh_csv_zeile([
            $r['nummer'], $r['lief_nummer'], $r['firma'], $r['lief_ustid'] ?? '', $r['lief_land'] ?? '',
            $r['datum'] ? date('d.m.Y', strtotime($r['datum'])) : '',
            $cur,
            bh_csv_betrag((float)($r['fw_netto'] ?? $r['netto'])),
            $cur === 'EUR' ? '1' : number_format((float)($r['fx_kurs'] ?: 1), 6, ',', ''),
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
