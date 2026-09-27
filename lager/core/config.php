<?php
// Grundeinstellungen des Lager-Programms.
//
// ZUGANGSDATEN: Das Lager arbeitet mit derselben Datenbank wie das Dashboard. Gesucht wird die
// secrets.php deshalb genau wie im CRM:
//   1. secrets.php in DIESEM Ordner (lager/). Darf auch nur eine Zeile enthalten:
//      <?php require '/pfad/zum/dashboard/secrets.php';
//   2. Pfad aus der Umgebungsvariablen BULKIFY_SECRETS.
//   3. eine Ebene hoeher - dort liegt sie, solange das Lager im Dashboard-Projekt steckt.
// Erst danach greifen die lokalen Vorgaben.
//
// Fuer die Hersteller-Cloud der Blinker koennen in der secrets.php zusaetzlich stehen:
//   LG_CLOUD_URL, LG_CLOUD_APP_ID, LG_CLOUD_SECRET   (siehe core/led.php)
define('BX_ROOT', dirname(__DIR__));
define('BX_MARKE', 'bulkify');
define('BX_TITEL', 'Lager');
// Dokumentenablage des Dashboards (Lieferscheine, CoA ...). Das Lager liegt im Dashboard-Projekt,
// die Uploads liegen also eine Ebene hoeher unter data/uploads.
define('BX_UPLOADS', dirname(BX_ROOT) . '/data/uploads');

$GLOBALS['lg_secrets_quelle'] = '';

// ACHTUNG: Das require MUSS im aeussersten Bereich stehen, nicht in einer Funktion - setzt die
// secrets.php Variablen ($DB_HOST = ...), waeren die sonst nur in der Funktion sichtbar.
$__kandidaten = [BX_ROOT . '/secrets.php'];
$__ausUmgebung = getenv('BULKIFY_SECRETS');
if ($__ausUmgebung !== false && trim($__ausUmgebung) !== '') $__kandidaten[] = trim($__ausUmgebung);
$__kandidaten[] = dirname(BX_ROOT) . '/secrets.php';

foreach ($__kandidaten as $__pfad) {
    if (!is_file($__pfad) || !is_readable($__pfad)) continue;
    require $__pfad;
    $GLOBALS['lg_secrets_quelle'] = $__pfad;
    break;
}
unset($__kandidaten, $__ausUmgebung, $__pfad);

// Aus Variablen Konstanten machen - falls die secrets.php die aeltere Schreibweise nutzt.
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_PORT',
          'LG_CLOUD_URL', 'LG_CLOUD_APP_ID', 'LG_CLOUD_SECRET'] as $__k) {
    if (!defined($__k) && isset($GLOBALS[$__k]) && $GLOBALS[$__k] !== '') define($__k, $GLOBALS[$__k]);
}
unset($__k);

if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', 3306);
if (!defined('DB_NAME')) define('DB_NAME', 'bulkify41');
if (!defined('DB_USER')) define('DB_USER', 'bulkify');
if (!defined('DB_PASS')) define('DB_PASS', 'bulkify');

// Intern immer UTC, Anzeige ueber fmt_zeit() in Berliner Zeit - genau wie im Dashboard.
date_default_timezone_set('UTC');

// Eigener Sitzungsname - Dashboard und CRM laufen auf derselben Domain.
define('BX_SESSION', 'BXLAGER');

// Ein Leuchtbefehl fuer die Bruecke, der so lange nicht abgeholt wurde, wird verworfen.
// Sonst leuchtet nach einem Ausfall der Bruecke ploetzlich ein ganzes Regal auf.
define('LG_BEFEHL_VERFALL_SEK', 30);

// Laeuft das hier auf dem eigenen Rechner? Nur dann sind Testhilfen erlaubt.
function ist_lokal(): bool {
    $h = (string)($_SERVER['HTTP_HOST'] ?? '');
    return str_starts_with($h, '127.0.0.1') || str_starts_with($h, 'localhost') || $h === '';
}

function lg_secrets_quelle(): string { return (string)($GLOBALS['lg_secrets_quelle'] ?? ''); }

// Jetzt in UTC, im Format der Datenbank.
function jetzt_utc(): string { return gmdate('Y-m-d H:i:s'); }
