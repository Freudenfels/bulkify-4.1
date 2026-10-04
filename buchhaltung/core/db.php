<?php
// Datenbank – dieselben Helfer wie Dashboard/Lager/Produktion (q/all/one/scalar/insert_id), damit man
// nicht zwischen zwei Schreibweisen denken muss. Dieselbe DB wie das Dashboard.
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
        ]);
    }
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $st = db()->prepare($sql); $st->execute($p); return $st; }
function all(string $sql, array $p = []): array  { return q($sql, $p)->fetchAll(); }
function one(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r === false ? null : $r; }
function scalar(string $sql, array $p = [])      { $r = q($sql, $p)->fetch(PDO::FETCH_NUM); return $r === false ? null : $r[0]; }
function insert_id(): int                         { return (int) db()->lastInsertId(); }

// Gibt es die Tabelle? (Dashboard-Tabellen können auf einer frischen DB noch fehlen.)
function tabelle_da(string $name): bool {
    static $bekannt = [];
    if (array_key_exists($name, $bekannt)) return $bekannt[$name];
    try {
        $bekannt[$name] = (bool) scalar("SELECT COUNT(*) FROM information_schema.TABLES
                                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$name]);
    } catch (Throwable $e) { $bekannt[$name] = false; }
    return $bekannt[$name];
}
