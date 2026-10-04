<?php
// Finanz-EIGENE Tabellen des Buchhaltungs-Programms: beleg, beleg_position, beleg_status_log, zahlung.
// (Kreditoren-Tabellen lieferant_rechnung/_zahlung legt kreditor.php per kreditor_init() an.)
// CREATE IF NOT EXISTS bleibt idempotent – das Dashboard liest dieselben Tabellen weiter.
// GETEILTE Tabellen (benutzer/app_meta/nummernkreis/aktivitaet/kunden/auftrag/lieferanten) gehören NICHT
// hierher, sondern laufen über erp.php bzw. werden (für Belege) in finanz.php gelesen.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/erp.php';

function bu_ensure_column(string $t, string $c, string $definition): void {
    $da = (int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?", [$t, $c]);
    if ($da === 0 && tabelle_da($t)) db()->exec("ALTER TABLE `$t` ADD COLUMN `$c` $definition");
}
// Kompatibilität: kopierte Core-Dateien (z. B. kreditor.php) nutzen den Dashboard-Namen ensure_column().
if (!function_exists('ensure_column')) {
    function ensure_column(string $t, string $c, string $definition): void { bu_ensure_column($t, $c, $definition); }
}

function bu_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS beleg (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nummer VARCHAR(20) NULL,
        typ VARCHAR(20) NOT NULL DEFAULT 'rechnung',
        auftrag_id INT NULL,
        kunde_id INT NULL,
        netto DECIMAL(14,2) NOT NULL DEFAULT 0,
        ust_prozent DECIMAL(5,2) NOT NULL DEFAULT 0,
        ust_betrag DECIMAL(14,2) NOT NULL DEFAULT 0,
        brutto DECIMAL(14,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'offen',
        datum DATE NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_kunde (kunde_id), KEY idx_auftrag (auftrag_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    bu_ensure_column('beleg', 'storno_von_id', "INT NULL");
    bu_ensure_column('beleg', 'grund', "VARCHAR(255) NULL");
    bu_ensure_column('beleg', 'zahlungsziel_tage', "INT NULL");
    bu_ensure_column('beleg', 'faellig', "DATE NULL");
    bu_ensure_column('beleg', 'leistung_datum', "DATE NULL");
    bu_ensure_column('beleg', 'text', "TEXT NULL");
    bu_ensure_column('beleg', 'bearbeiter_id', "INT NULL");
    bu_ensure_column('beleg', 'original_datei', "VARCHAR(255) NULL");
    bu_ensure_column('beleg', 'original_orig', "VARCHAR(255) NULL");
    bu_ensure_column('beleg', 'kunde_sichtbar', "TINYINT(1) NOT NULL DEFAULT 0");

    $pdo->exec("CREATE TABLE IF NOT EXISTS beleg_position (
        id INT AUTO_INCREMENT PRIMARY KEY,
        beleg_id INT NOT NULL,
        sort INT NOT NULL DEFAULT 0,
        artikelnr VARCHAR(60) NULL,
        bezeichnung VARCHAR(255) NOT NULL,
        beschreibung VARCHAR(1000) NULL,
        menge DECIMAL(14,3) NOT NULL DEFAULT 0,
        einheit VARCHAR(20) NULL,
        preis_cent INT NOT NULL DEFAULT 0,
        mwst_satz DECIMAL(5,2) NOT NULL DEFAULT 0,
        KEY idx_beleg (beleg_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS beleg_status_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        beleg_id INT NOT NULL,
        status VARCHAR(30) NOT NULL,
        notiz VARCHAR(255) NULL,
        akteur VARCHAR(80) NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_beleg (beleg_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS zahlung (
        id INT AUTO_INCREMENT PRIMARY KEY,
        beleg_id INT NOT NULL,
        betrag DECIMAL(14,2) NOT NULL DEFAULT 0,
        datum DATE NULL,
        konto VARCHAR(40) NULL,
        art VARCHAR(30) NULL,
        notiz VARCHAR(255) NULL,
        akteur VARCHAR(80) NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_beleg (beleg_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS guthaben_bewegung (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kunde_id INT NOT NULL,
        gutschrift_id INT NULL,
        betrag DECIMAL(14,2) NOT NULL DEFAULT 0,
        typ VARCHAR(20) NOT NULL DEFAULT 'anrechnung',
        ref_beleg_id INT NULL,
        notiz VARCHAR(255) NULL,
        datum DATE NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_kunde (kunde_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

require_once __DIR__ . '/finanz.php';   // Beleg-/Rechnungs-/Gutschrift-Funktionen (aus dem Dashboard übernommen)
