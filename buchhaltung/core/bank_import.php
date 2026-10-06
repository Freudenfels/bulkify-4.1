<?php
// Kontoauszug-Import + Zuordnung. CSV wird eingelesen, je Zeile nach Vorzeichen in Eingang (Zahlungen von
// Kunden -> Debitoren/beleg) und Ausgang (unsere Zahlungen -> Kreditoren/lieferant_rechnung) getrennt.
// Zuordnen & Buchen läuft über die vorhandenen Zahlungsfunktionen (zahlung_erfassen / kr_zahlung_buchen).
// Eigene Tabelle bank_import (eine Zeile je Auszugsposten). Kein Eingriff in geteilte Tabellen.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';     // finanz.php (zahlung_erfassen, beleg_zahlstatus)
require_once __DIR__ . '/kreditor.php';   // kr_zahlung_buchen, kr_liste

function bank_init(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS bank_import (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch VARCHAR(40) NULL,                         -- Import-Gruppe (Zeitstempel)
        richtung VARCHAR(10) NOT NULL DEFAULT 'eingang',-- eingang (Geld rein) | ausgang (Geld raus)
        datum DATE NULL,
        betrag DECIMAL(14,2) NOT NULL DEFAULT 0,        -- immer positiv (Betrag der Buchung)
        gegenname VARCHAR(255) NULL,
        verwendungszweck VARCHAR(1000) NULL,
        gegen_iban VARCHAR(40) NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'offen',    -- offen | gebucht | ignoriert
        beleg_id INT NULL,                              -- bei Eingang: zugeordnete Rechnung (beleg)
        lief_rechnung_id INT NULL,                      -- bei Ausgang: zugeordnete Eingangsrechnung
        gebucht_am DATETIME NULL,
        akteur VARCHAR(80) NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_batch (batch), KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// --- CSV-Parsing -----------------------------------------------------------
// Trennzeichen raten (Semikolon, Tab, Komma) anhand der ersten nicht-leeren Zeile.
function bank_delimiter(string $content): string {
    foreach (preg_split('/\r\n|\r|\n/', $content) as $z) {
        if (trim($z) === '') continue;
        $sc = substr_count($z, ';'); $tb = substr_count($z, "\t"); $co = substr_count($z, ',');
        if ($sc >= $tb && $sc >= $co && $sc > 0) return ';';
        if ($tb >= $co && $tb > 0) return "\t";
        if ($co > 0) return ',';
        return ';';
    }
    return ';';
}

// Content -> Array von Zeilen (jede Zeile = Array von Zellen). Leere Zeilen raus.
function bank_rohzeilen(string $content, string $delim): array {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $content) as $z) {
        if (trim($z) === '') continue;
        $out[] = str_getcsv($z, $delim);
    }
    return $out;
}

// Deutsche/engl. Betragsangabe -> float (mit Vorzeichen). '1.234,56' / '1,234.56' / '-50,00' / '50,00-'.
function bank_num(string $s): ?float {
    $s = trim($s);
    if ($s === '') return null;
    $neg = (strpos($s, '-') !== false) || (bool) preg_match('/^\(.*\)$/', $s) || (bool) preg_match('/-\s*$/', $s);
    $s = preg_replace('/[^0-9.,]/', '', $s);
    if ($s === '') return null;
    $hatK = strpos($s, ',') !== false; $hatP = strpos($s, '.') !== false;
    if ($hatK && $hatP) {
        // letztes Trennzeichen ist das Dezimaltrennzeichen
        if (strrpos($s, ',') > strrpos($s, '.')) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
        else                                     { $s = str_replace(',', '', $s); }
    } elseif ($hatK) {
        $s = str_replace(',', '.', $s);
    } // nur '.' -> unverändert (Dezimalpunkt)
    if (!is_numeric($s)) return null;
    $v = (float) $s;
    return $neg ? -abs($v) : $v;
}

// Spalten automatisch erkennen (Index je Feld) anhand der Kopfzeile. -1 = nicht gefunden.
function bank_spalten_erkennen(array $kopf): array {
    $norm = array_map(fn($h) => mb_strtolower(trim((string)$h)), $kopf);
    $find = function(array $keys) use ($norm): int {
        foreach ($norm as $i => $h) foreach ($keys as $k) if ($h !== '' && mb_strpos($h, $k) !== false) return $i;
        return -1;
    };
    return [
        'datum'  => $find(['buchungstag', 'buchungsdatum', 'valuta', 'datum', 'date']),
        'betrag' => $find(['betrag', 'umsatz', 'amount', 'wert']),
        'zweck'  => $find(['verwendungszweck', 'buchungstext', 'vorgang', 'zweck', 'reference', 'text']),
        'name'   => $find(['beguenstigter', 'begünstigter', 'auftraggeber', 'empfänger', 'empfaenger', 'name', 'zahlungspflichtiger', 'kontoinhaber']),
        'iban'   => $find(['iban', 'kontonummer', 'konto']),
    ];
}

// Datum robust nach Y-m-d. Erkennt dd.mm.yyyy, yyyy-mm-dd, dd/mm/yyyy, dd.mm.yy.
function bank_datum(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('#^(\d{1,2})[.\/](\d{1,2})[.\/](\d{2,4})#', $s, $m)) {
        $y = (int)$m[3]; if ($y < 100) $y += 2000;
        return sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1]);
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}

// Parse mit Spaltenzuordnung. $map: ['datum'=>idx,'betrag'=>idx,'zweck'=>idx,'name'=>idx,'iban'=>idx].
// $hatKopf: erste Zeile ist Überschrift. Gibt normalisierte Posten (nur mit gültigem Betrag).
function bank_parse(string $content, array $map, bool $hatKopf, string $delim = ''): array {
    if ($delim === '') $delim = bank_delimiter($content);
    $zeilen = bank_rohzeilen($content, $delim);
    if ($hatKopf && $zeilen) array_shift($zeilen);
    $out = [];
    foreach ($zeilen as $z) {
        $get = fn($f) => ($map[$f] ?? -1) >= 0 && isset($z[$map[$f]]) ? (string)$z[$map[$f]] : '';
        $betrag = bank_num($get('betrag'));
        if ($betrag === null || abs($betrag) < 0.005) continue;
        $out[] = [
            'datum'  => bank_datum($get('datum')),
            'betrag' => $betrag,
            'zweck'  => trim($get('zweck')),
            'name'   => trim($get('name')),
            'iban'   => trim($get('iban')),
        ];
    }
    return $out;
}

// Posten speichern (ein Batch). Richtung aus Vorzeichen. Gibt ['batch'=>, 'anzahl'=>].
function bank_import_speichern(array $posten, string $akteur = 'team'): array {
    bank_init();
    $batch = date('YmdHis');
    $n = 0;
    foreach ($posten as $p) {
        $betrag = (float)$p['betrag'];
        if (abs($betrag) < 0.005) continue;
        $richtung = $betrag < 0 ? 'ausgang' : 'eingang';
        q("INSERT INTO bank_import (batch, richtung, datum, betrag, gegenname, verwendungszweck, gegen_iban, akteur)
           VALUES (?,?,?,?,?,?,?,?)",
          [$batch, $richtung, $p['datum'] ?: null, round(abs($betrag), 2),
           mb_substr(trim((string)$p['name']), 0, 255) ?: null,
           mb_substr(trim((string)$p['zweck']), 0, 1000) ?: null,
           mb_substr(trim((string)$p['iban']), 0, 40) ?: null, $akteur]);
        $n++;
    }
    return ['batch' => $batch, 'anzahl' => $n];
}

// --- Offene Rechnungen (einmal laden, in PHP matchen) ----------------------
function bank_open_debitoren(): array {
    return all(
        "SELECT b.id, b.nummer, k.firma AS kunde_firma, b.datum,
                (b.brutto - COALESCE(z.bez,0)) AS rest
           FROM beleg b LEFT JOIN kunden k ON k.id=b.kunde_id
           LEFT JOIN (SELECT beleg_id, SUM(betrag) bez FROM zahlung GROUP BY beleg_id) z ON z.beleg_id=b.id
          WHERE b.typ='rechnung' AND b.status IN ('offen','teilbezahlt')
          HAVING rest > 0.005
          ORDER BY b.datum DESC, b.id DESC");
}
function bank_open_kreditoren(): array {
    bank_init();
    return all(
        "SELECT r.id, r.nummer, r.lief_nummer, l.firma, r.datum,
                (r.brutto - COALESCE(z.bez,0)) AS rest
           FROM lieferant_rechnung r LEFT JOIN lieferanten l ON l.id=r.lieferant_id
           LEFT JOIN (SELECT lief_rechnung_id, SUM(betrag) bez FROM lieferant_zahlung GROUP BY lief_rechnung_id) z ON z.lief_rechnung_id=r.id
          WHERE r.status IN ('offen','teilbezahlt')
          HAVING rest > 0.005
          ORDER BY r.datum DESC, r.id DESC");
}

// Match einer Auszugszeile gegen eine Liste offener Rechnungen. nummerfelder = Felder mit Belegnummern.
// Score: Belegnummer im Verwendungszweck (100) + Betrag == Rest (40) + Name-Treffer (30).
// Gibt ['best'=>id|0, 'score'=>int].
function bank_match(array $zeile, array $liste, array $nummerfelder, string $namefeld): array {
    $hay = mb_strtolower(($zeile['zweck'] ?? '') . ' ' . ($zeile['name'] ?? ''));
    $betrag = round((float)($zeile['betrag'] ?? 0), 2);
    $best = 0; $bestScore = 0;
    foreach ($liste as $r) {
        $score = 0;
        foreach ($nummerfelder as $nf) {
            $nr = mb_strtolower(trim((string)($r[$nf] ?? '')));
            if ($nr !== '' && mb_strlen($nr) >= 4 && mb_strpos($hay, $nr) !== false) { $score += 100; break; }
        }
        if (abs(round((float)$r['rest'], 2) - $betrag) < 0.005) $score += 40;
        $name = mb_strtolower(trim((string)($r[$namefeld] ?? '')));
        if ($name !== '' && mb_strlen($name) >= 3 && mb_strpos($hay, $name) !== false) $score += 30;
        if ($score > $bestScore) { $bestScore = $score; $best = (int)$r['id']; }
    }
    return ['best' => $bestScore >= 40 ? $best : 0, 'score' => $bestScore];
}

// --- Buchen / Ignorieren ---------------------------------------------------
function bank_zeile(int $id): ?array { bank_init(); return one("SELECT * FROM bank_import WHERE id=?", [$id]); }

// Eingang einer Rechnung (beleg) zuordnen und als Zahlung buchen.
function bank_buchen_eingang(int $zeile_id, int $beleg_id, string $akteur = 'team'): bool {
    $z = bank_zeile($zeile_id);
    if (!$z || $z['status'] !== 'offen' || $z['richtung'] !== 'eingang' || !$beleg_id) return false;
    zahlung_erfassen($beleg_id, round((float)$z['betrag'], 2), $z['datum'] ?: null, 'bank', 'ueberweisung',
                     'Kontoauszug: ' . mb_substr((string)$z['verwendungszweck'], 0, 180), $akteur);
    q("UPDATE bank_import SET status='gebucht', beleg_id=?, gebucht_am=NOW(), akteur=? WHERE id=?", [$beleg_id, $akteur, $zeile_id]);
    return true;
}
// Ausgang einer Eingangsrechnung (lieferant_rechnung) zuordnen und als Zahlung buchen.
function bank_buchen_ausgang(int $zeile_id, int $lief_rechnung_id, string $akteur = 'team'): bool {
    $z = bank_zeile($zeile_id);
    if (!$z || $z['status'] !== 'offen' || $z['richtung'] !== 'ausgang' || !$lief_rechnung_id) return false;
    kr_zahlung_buchen($lief_rechnung_id, round((float)$z['betrag'], 2), $z['datum'] ?: null, 'ueberweisung',
                      'Kontoauszug: ' . mb_substr((string)$z['verwendungszweck'], 0, 180), $akteur);
    q("UPDATE bank_import SET status='gebucht', lief_rechnung_id=?, gebucht_am=NOW(), akteur=? WHERE id=?", [$lief_rechnung_id, $akteur, $zeile_id]);
    return true;
}
function bank_ignorieren(int $id, bool $ja = true): void {
    bank_init();
    q("UPDATE bank_import SET status=? WHERE id=? AND status<>'gebucht'", [$ja ? 'ignoriert' : 'offen', $id]);
}

// --- Listen ----------------------------------------------------------------
function bank_batches(): array {
    bank_init();
    return all("SELECT batch, COUNT(*) AS anz,
                       SUM(status='offen') AS offen, SUM(status='gebucht') AS gebucht, SUM(status='ignoriert') AS ignoriert,
                       MIN(angelegt) AS angelegt
                  FROM bank_import GROUP BY batch ORDER BY batch DESC");
}
function bank_zeilen(string $batch, string $richtung = ''): array {
    bank_init();
    $where = "batch=?"; $args = [$batch];
    if ($richtung !== '') { $where .= " AND richtung=?"; $args[] = $richtung; }
    return all("SELECT * FROM bank_import WHERE $where ORDER BY datum, id", $args);
}
function bank_batch_loeschen(string $batch): void {
    bank_init();
    // Nur noch nicht gebuchte Posten entfernen (gebuchte Zahlungen bleiben erhalten).
    q("DELETE FROM bank_import WHERE batch=? AND status<>'gebucht'", [$batch]);
}
