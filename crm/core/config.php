<?php
// Grundeinstellungen.
//
// ZUGANGSDATEN: Das CRM arbeitet mit derselben Datenbank wie das Dashboard und darf denselben
// Anthropic-Schluessel benutzen. Es waere unnoetig, beides zweimal zu pflegen - zwei Kopien laufen
// frueher oder spaeter auseinander, und dann sucht man den Fehler an der falschen Stelle.
//
// Gesucht wird deshalb in dieser Reihenfolge:
//   1. secrets.php in DIESEM Projekt. Sie darf auch nur eine Zeile enthalten:
//      <?php require '/pfad/zum/dashboard/secrets.php';
//   2. Pfad aus der Umgebungsvariablen BULKIFY_SECRETS.
//   3. die ueblichen Nachbarordner - liegt das Dashboard daneben, findet es sich von selbst.
// Erst danach greifen die lokalen Vorgaben.
//
// Die Datei des Dashboards setzt ihre Werte per define(). Deshalb wird hier alles nur noch
// gesetzt, was nicht schon steht - so passen beide Schreibweisen.
define('BX_ROOT', dirname(__DIR__));
// Ablage fuer Log und kurzzeitige Uploads. MUSS gesetzt sein: core/ki.php schreibt sein
// Protokoll nach BX_DATA - ohne die Konstante bricht jeder KI-Aufruf ab.
define('BX_DATA', BX_ROOT . '/data');
define('BX_MARKE', 'bulkify');
define('BX_TITEL', 'CRM');

// Woher die Zugangsdaten kommen - fuer die Anzeige unter "Mehr", ohne etwas zu verraten.
$GLOBALS['crm_secrets_quelle'] = '';

// ACHTUNG: Das require MUSS hier im aeussersten Bereich stehen, nicht in einer Funktion.
// Setzt die secrets.php ihre Werte als Variablen ($DB_HOST = ...), waeren die sonst nur innerhalb
// der Funktion sichtbar und die Zugangsdaten kaemen nie an - ohne jede Fehlermeldung.
$__kandidaten = [BX_ROOT . '/secrets.php'];

$__ausUmgebung = getenv('BULKIFY_SECRETS');
if ($__ausUmgebung !== false && trim($__ausUmgebung) !== '') $__kandidaten[] = trim($__ausUmgebung);

// Liegt das CRM IM Dashboard-Projekt (crm/), steht dessen secrets.php eine Ebene hoeher.
$__kandidaten[] = dirname(BX_ROOT) . '/secrets.php';

// Sonst: Nachbarordner, falls das CRM als eigenes Projekt danebenliegt.
foreach (['bulkify-4.1', 'bulkify41', 'bulkify', 'dashboard', 'bulkify-dashboard'] as $__ordner) {
    $__kandidaten[] = dirname(BX_ROOT) . '/' . $__ordner . '/secrets.php';
}

foreach ($__kandidaten as $__pfad) {
    if (!is_file($__pfad) || !is_readable($__pfad)) continue;
    require $__pfad;
    $GLOBALS['crm_secrets_quelle'] = $__pfad;
    break;
}
unset($__kandidaten, $__ausUmgebung, $__ordner, $__pfad);

// Aus Variablen Konstanten machen - falls die secrets.php die aeltere Schreibweise nutzt.
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_PORT', 'ANTHROPIC_API_KEY'] as $__k) {
    if (!defined($__k) && isset($GLOBALS[$__k]) && $GLOBALS[$__k] !== '') define($__k, $GLOBALS[$__k]);
}
unset($__k);

// Lokale Vorgaben - greifen nur, wenn oben nichts gesetzt wurde.
if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', 3306);
if (!defined('DB_NAME')) define('DB_NAME', 'bulkify41');
if (!defined('DB_USER')) define('DB_USER', 'bulkify');
if (!defined('DB_PASS')) define('DB_PASS', 'bulkify');

// Intern immer UTC, Anzeige ueber fmt_zeit() in Berliner Zeit - genau wie im Dashboard.
date_default_timezone_set('UTC');

// Eigener Sitzungsname. Das Dashboard laeuft auf derselben Domain - ohne das hier wuerden sich
// die beiden Anmeldungen gegenseitig ueberschreiben.
define('BX_SESSION', 'BXCRM');

// Ab wann eine Zeile in der Liste warm bzw. heiss wird (Tage). Rein zur Anzeige.
define('CRM_WARM', 3);
define('CRM_HEISS', 6);

// Wie viele Tage nach dem Versand eines Angebots die Wiedervorlage faellig wird.
define('CRM_ANGEBOT_NACHFASSEN', 5);

// Laeuft das hier auf dem eigenen Rechner? Nur dann sind Testhilfen erlaubt.
function ist_lokal(): bool {
    $h = (string)($_SERVER['HTTP_HOST'] ?? '');
    return str_starts_with($h, '127.0.0.1') || str_starts_with($h, 'localhost') || $h === '';
}

// Woher stammen die Zugangsdaten? Nur der Pfad, nie ein Wert.
function crm_secrets_quelle(): string { return (string)($GLOBALS['crm_secrets_quelle'] ?? ''); }
