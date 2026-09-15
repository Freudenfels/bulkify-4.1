<?php
// Zentrale Datenbank-Schicht bulkify 4.1 (nur MySQL/MariaDB, kein Dual-Treiber)
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $t0 = microtime(true);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
        ]);
        $GLOBALS['bx_db_connect_ms'] = (microtime(true) - $t0) * 1000;   // Verbindungsaufbau (einmal je Request)
    }
    return $pdo;
}

// Leichte Request-Zähler für die Diagnose (Einstellungen → Diagnose): Anzahl Abfragen + DB-Zeit.
// Vernachlässigbarer Aufwand, deshalb immer an.
function db_stats(): array {
    return [
        'anzahl'     => (int)  ($GLOBALS['bx_q_n']  ?? 0),
        'db_ms'      => (float)($GLOBALS['bx_q_ms'] ?? 0.0),
        'connect_ms' => (float)($GLOBALS['bx_db_connect_ms'] ?? 0.0),
    ];
}

// Kurz-Helfer: immer prepared statements
function q(string $sql, array $params = []): PDOStatement {
    $t0 = microtime(true);
    $st = db()->prepare($sql);
    $st->execute($params);
    $GLOBALS['bx_q_n']  = ($GLOBALS['bx_q_n']  ?? 0) + 1;
    $GLOBALS['bx_q_ms'] = ($GLOBALS['bx_q_ms'] ?? 0.0) + (microtime(true) - $t0) * 1000;
    return $st;
}
function one(string $sql, array $params = []): ?array {
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}
function all(string $sql, array $params = []): array {
    return q($sql, $params)->fetchAll();
}
function scalar(string $sql, array $params = []) {
    return q($sql, $params)->fetchColumn();
}
function insert_id(): int {
    return (int) db()->lastInsertId();
}
