<?php
// Grundeinstellungen des Produktions-Programms (eigene Seite /produktion/, eigene Sitzung,
// dieselbe Datenbank wie das Dashboard). Aufbau wie Lager/CRM.
//
// ZUGANGSDATEN: Dieselbe secrets.php wie Dashboard/Lager. Gesucht wird:
//   1. produktion/secrets.php (darf nur `<?php require '…/secrets.php';` enthalten)
//   2. Pfad aus Umgebungsvariable BULKIFY_SECRETS
//   3. eine Ebene höher (Dashboard-Projektstamm)
define('BX_ROOT', dirname(__DIR__));
define('BX_MARKE', 'bulkify');
define('BX_TITEL', 'Produktion');
// Uploads liegen im Dashboard-Projekt (eine Ebene höher).
define('BX_UPLOADS', dirname(BX_ROOT) . '/data/uploads');

$GLOBALS['pr_secrets_quelle'] = '';

$__kandidaten = [BX_ROOT . '/secrets.php'];
$__ausUmgebung = getenv('BULKIFY_SECRETS');
if ($__ausUmgebung !== false && trim($__ausUmgebung) !== '') $__kandidaten[] = trim($__ausUmgebung);
$__kandidaten[] = dirname(BX_ROOT) . '/secrets.php';
foreach ($__kandidaten as $__pfad) {
    if (!is_file($__pfad) || !is_readable($__pfad)) continue;
    require $__pfad;
    $GLOBALS['pr_secrets_quelle'] = $__pfad;
    break;
}
unset($__kandidaten, $__ausUmgebung, $__pfad);

foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_PORT'] as $__k) {
    if (!defined($__k) && isset($GLOBALS[$__k]) && $GLOBALS[$__k] !== '') define($__k, $GLOBALS[$__k]);
}
unset($__k);

if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', 3306);
if (!defined('DB_NAME')) define('DB_NAME', 'bulkify41');
if (!defined('DB_USER')) define('DB_USER', 'bulkify');
if (!defined('DB_PASS')) define('DB_PASS', 'bulkify');

// Intern immer UTC, Anzeige über fmt_zeit() in Berliner Zeit – wie im Dashboard.
date_default_timezone_set('UTC');

// Eigener Sitzungsname (Dashboard/CRM/Lager laufen auf derselben Domain).
define('BX_SESSION', 'BXPROD');

// Läuft das hier lokal? Nur dann sind Testhilfen (Autologin) erlaubt.
function ist_lokal(): bool {
    $h = (string)($_SERVER['HTTP_HOST'] ?? '');
    return str_starts_with($h, '127.0.0.1') || str_starts_with($h, 'localhost') || $h === '';
}
function pr_secrets_quelle(): string { return (string)($GLOBALS['pr_secrets_quelle'] ?? ''); }
function jetzt_utc(): string { return gmdate('Y-m-d H:i:s'); }
