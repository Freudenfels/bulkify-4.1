<?php
// Zentrale Datenbank-Schicht bulkify 4.1 (nur MySQL/MariaDB, kein Dual-Treiber)
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// Kurz-Helfer: immer prepared statements
function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
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
