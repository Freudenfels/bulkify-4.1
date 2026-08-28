<?php
// Zentrale Konfiguration bulkify 4.1
// Lokale Entwicklung. Live-Zugangsdaten kommen spaeter in eine gitignorierte secrets.php.

if (!defined('BX41')) define('BX41', true);

// --- Datenbank (lokal MariaDB) ---
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

// Lokale secrets.php ueberschreibt Defaults, wenn vorhanden (nie committen)
$__secrets = BX_ROOT . '/secrets.php';
if (is_file($__secrets)) require $__secrets;

date_default_timezone_set('UTC'); // intern immer UTC, Anzeige via fmt_zeit()
