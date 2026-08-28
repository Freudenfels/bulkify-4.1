<?php
// Zentrale Konfiguration bulkify 4.1
// Lokal: Defaults unten (127.0.0.1 / bulkify). Server/Live: secrets.php im Projektstamm
// (gitignored, nie committen) setzt die echten DB-Zugänge und wird ZUERST geladen.

if (!defined('BX41')) define('BX41', true);

// --- Live-Zugangsdaten zuerst laden (secrets.php im Projektstamm) ---
// Setzt DB_HOST/DB_NAME/DB_USER/DB_PASS/DB_PORT per define() → die Defaults unten werden dann übersprungen.
$__secrets = dirname(__DIR__) . '/secrets.php';
if (is_file($__secrets)) require $__secrets;

// --- Datenbank-Defaults (greifen nur, wenn secrets.php nichts gesetzt hat = lokale Entwicklung) ---
if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', '3306');
if (!defined('DB_NAME')) define('DB_NAME', 'bulkify41');
if (!defined('DB_USER')) define('DB_USER', 'bulkify');
if (!defined('DB_PASS')) define('DB_PASS', 'bulkify');

// --- Pfade ---
define('BX_ROOT', dirname(__DIR__));          // Projektwurzel
define('BX_DATA', BX_ROOT . '/data');         // Daten/Uploads (ausserhalb public)
define('BX_UPLOADS', BX_DATA . '/uploads');

// --- App ---
define('BX_VERSION', '4.1');
define('BX_MARKE', 'bulkify');

date_default_timezone_set('UTC'); // intern immer UTC, Anzeige via fmt_zeit()
