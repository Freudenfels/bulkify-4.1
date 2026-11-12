<?php
// Abzug live -> test (Paket 12.11.2026 Teil 2). Kopiert alle Tabellen der Live-Datenbank in die Datenbank der
// Testumgebung und auf Wunsch fehlende Upload-Dateien. NUR in diese Richtung:
//  - geschrieben wird ausschließlich über db() = Datenbank der eigenen Installation (Test),
//  - die Live-Datenbank wird nur gelesen (eigene PDO-Verbindung, nur SHOW/SELECT),
//  - läuft nur, wenn bx_testumgebung() wahr ist UND Quelle und Ziel verschieden sind.
// Zugang zur Quelle steht in der secrets.php der Testumgebung: LIVE_DB_HOST, LIVE_DB_PORT, LIVE_DB_NAME,
// LIVE_DB_USER, LIVE_DB_PASS (am besten ein Benutzer nur mit Leserecht), optional LIVE_DATA_DIR (data-Ordner live).
// Aufruf: Dashboard ?p=test_abzug (Admin, Tabelle für Tabelle) oder CLI: php tools/test_abzug.php
// Siehe test_abzug.md.

function ta_wert(string $k, string $d = ''): string {
    if (defined($k)) return (string) constant($k);
    if (isset($GLOBALS[$k])) return (string) $GLOBALS[$k];
    return $d;
}

// Prüft, ob ein Abzug erlaubt ist. '' = ja, sonst Grund.
function ta_sperre(): string {
    if (!function_exists('bx_testumgebung') || !bx_testumgebung()) return 'Nur in der Testumgebung möglich. Auf live wird nie etwas überschrieben.';
    if (ta_wert('LIVE_DB_NAME') === '' || ta_wert('LIVE_DB_USER') === '') return 'In der secrets.php der Testumgebung fehlen LIVE_DB_NAME/LIVE_DB_USER (Zugang zur Live-Datenbank, nur lesen).';
    $zielHost = strtolower((string) DB_HOST); $zielName = (string) DB_NAME;
    if (strtolower(ta_wert('LIVE_DB_HOST', '127.0.0.1')) === $zielHost && ta_wert('LIVE_DB_NAME') === $zielName)
        return 'Quelle und Ziel sind dieselbe Datenbank – Abzug gesperrt.';
    // 13.11.2026: nicht nur die Texte vergleichen, sondern die echten Verbindungen (Server, Port, Datenbank, Server-UUID).
    try {
        $kennung = function (PDO $p): array {
            $r = $p->query("SELECT @@hostname AS h, @@port AS p, DATABASE() AS d")->fetch(PDO::FETCH_ASSOC) ?: [];
            try { $r['u'] = (string) $p->query("SELECT @@server_uuid")->fetchColumn(); } catch (\Throwable $e) { $r['u'] = ''; }   // MariaDB kennt server_uuid nicht
            return $r;
        };
        $q = $kennung(ta_live()); $z = $kennung(db());
        if ((string)$q['d'] === (string)$z['d'] && (((string)$q['u'] !== '' && (string)$q['u'] === (string)$z['u']) || ((string)$q['h'] === (string)$z['h'] && (string)$q['p'] === (string)$z['p'])))
            return 'Quelle und Ziel sind laut Datenbank-Server dieselbe Datenbank (' . $z['h'] . ':' . $z['p'] . '/' . $z['d'] . ') – Abzug gesperrt.';
        $zm = db()->query("SELECT v FROM app_meta WHERE k='umgebung'"); $zm = $zm ? (string)($zm->fetchColumn() ?: '') : '';
        if ($zm === 'live') return 'Das Ziel trägt den Live-Marker (app_meta umgebung=live) – Abzug gesperrt, live wird nie überschrieben.';
    } catch (\Throwable $e) { return 'Verbindungsprüfung fehlgeschlagen (' . $e->getMessage() . ') – Abzug gesperrt.'; }
    return '';
}

function ta_live(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . ta_wert('LIVE_DB_HOST', '127.0.0.1') . ';port=' . ta_wert('LIVE_DB_PORT', '3306') . ';dbname=' . ta_wert('LIVE_DB_NAME') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, ta_wert('LIVE_DB_USER'), ta_wert('LIVE_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("SET SESSION TRANSACTION READ ONLY");   // zusätzlicher Riegel: diese Verbindung kann nicht schreiben
    return $pdo;
}

function ta_tabellen(): array {
    $r = [];
    foreach (ta_live()->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $row) $r[] = (string)$row[0];
    // Reihenfolge: app_meta zuerst (Merker wie seed_demo_off/Einmal-Läufe gelten sofort), benutzer zuletzt
    // (danach passt die eigene Anmeldung ggf. nicht mehr – der Abzug ist dann schon durch).
    usort($r, fn($a, $b) => (($a === 'app_meta') ? -1 : (($a === 'benutzer') ? 1 : 0)) <=> (($b === 'app_meta') ? -1 : (($b === 'benutzer') ? 1 : 0)) ?: strcmp($a, $b));
    return $r;
}

// Eine Tabelle neu aufbauen (Struktur + Daten). Rückgabe Anzahl Zeilen.
function ta_tabelle(string $t): int {
    if ($s = ta_sperre()) throw new RuntimeException($s);
    if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) throw new RuntimeException('Ungültiger Tabellenname.');
    $live = ta_live();
    $create = (string) ($live->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1] ?? '');
    if ($create === '') throw new RuntimeException('Tabelle ' . $t . ' nicht lesbar.');
    $ziel = db();
    $ziel->exec('SET FOREIGN_KEY_CHECKS=0');
    $ziel->exec("DROP TABLE IF EXISTS `$t`");
    $ziel->exec($create);
    $n = 0; $paket = []; $spalten = null;
    $st = $live->query("SELECT * FROM `$t`", PDO::FETCH_ASSOC);
    $schreib = function () use (&$paket, &$spalten, $ziel, $t) {
        if (!$paket) return;
        $ph = '(' . implode(',', array_fill(0, count($spalten), '?')) . ')';
        $sql = "INSERT INTO `$t` (`" . implode('`,`', $spalten) . "`) VALUES " . implode(',', array_fill(0, count($paket), $ph));
        $werte = [];
        foreach ($paket as $z) foreach ($z as $v) $werte[] = $v;
        $ziel->prepare($sql)->execute($werte);
        $paket = [];
    };
    while ($row = $st->fetch()) {
        if ($spalten === null) $spalten = array_keys($row);
        $paket[] = array_values($row); $n++;
        if (count($paket) >= 300) $schreib();
    }
    $schreib();
    // 13.11.2026: der Live-Marker darf nie in der Testdatenbank stehen (sonst sperrt der Riegel – und er gehört nur live).
    if ($t === 'app_meta') { $ziel->exec("DELETE FROM app_meta WHERE k='umgebung'"); $ziel->exec("INSERT INTO app_meta (k, v) VALUES ('umgebung', 'test')"); }
    $ziel->exec('SET FOREIGN_KEY_CHECKS=1');
    return $n;
}

// Fehlende Upload-Dateien aus dem Live-data-Ordner kopieren (nur neue, nichts löschen, nichts überschreiben).
function ta_dateien(int $max = 2000): array {
    if ($s = ta_sperre()) return ['ok' => false, 'msg' => $s];
    $quelle = rtrim(ta_wert('LIVE_DATA_DIR'), '/\\');
    if ($quelle === '' || !is_dir($quelle . '/uploads')) return ['ok' => false, 'msg' => 'LIVE_DATA_DIR fehlt oder hat keinen Ordner uploads – Dateien werden nicht kopiert.'];
    $ziel = BX_UPLOADS;
    if (realpath($quelle . '/uploads') === realpath($ziel)) return ['ok' => false, 'msg' => 'Quelle und Ziel der Dateien sind derselbe Ordner – gesperrt.'];
    $kopiert = 0; $gesamt = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($quelle . '/uploads', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $gesamt++;
        $rel = substr($f->getPathname(), strlen($quelle . '/uploads'));
        $dst = $ziel . $rel;
        if (is_file($dst)) continue;
        if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0775, true);
        if (@copy($f->getPathname(), $dst)) $kopiert++;
        if ($kopiert >= $max) break;
    }
    return ['ok' => true, 'msg' => $kopiert . ' Datei(en) kopiert, ' . $gesamt . ' geprüft' . ($kopiert >= $max ? ' – noch nicht fertig, bitte erneut starten' : '') . '.'];
}

// Nach dem Abzug: Merker setzen, damit sichtbar ist, von wann der Datenstand ist.
function ta_fertig(): void {
    meta_set('test_abzug_am', gmdate('Y-m-d H:i:s'));
    // 13.11.2026: interne Schlüssel nach dem Abzug in test neu (sonst gälte der Live-Token auch hier).
    meta_set('einlager_api_token', bin2hex(random_bytes(16)));
    meta_set('umgebung', 'test');
}
