<?php
// Einziger Web-Einstieg des Lager-Programms (Front Controller). Nur was in der Whitelist steht,
// laesst sich aufrufen. Der Code liegt ausserhalb von public/ in lager/.
require_once __DIR__ . '/../../lager/core/config.php';
require_once __DIR__ . '/../../lager/core/db.php';
require_once __DIR__ . '/../../lager/core/schema.php';
require_once __DIR__ . '/../../lager/core/auth.php';
require_once __DIR__ . '/../../lager/core/layout.php';
require_once __DIR__ . '/../../lager/core/led.php';
require_once __DIR__ . '/../../lager/core/platz.php';
require_once __DIR__ . '/../../lager/core/leiste.php';

lg_session_start();
lg_schema();

$routen = [
    'login'          => 'auth/login.php',
    // Grosses Lager (Chaos-Modell): Leiste an der Charge
    'finden'         => 'leiste/finden.php',
    'leisten'        => 'leiste/liste.php',
    'klingeln'       => 'led/klingeln.php',
    // Fulfillment (feste Plaetze)
    'plaetze'        => 'platz/liste.php',
    'platz'          => 'platz/detail.php',
    'zuordnen'       => 'platz/zuordnen.php',
    'leuchten'       => 'led/leuchten.php',
    // System
    'sender'         => 'system/sender.php',
    'bruecke_skript' => 'system/bruecke_skript.php',
];
// Nur fuer Admins.
$nur_admin = ['sender', 'bruecke_skript'];

$p = isset($_GET['p']) ? preg_replace('/[^a-z0-9_]/', '', (string)$_GET['p']) : 'plaetze';

if ($p === 'logout') { lg_logout(); weiter('?p=login'); }

// Direktlink beim Entwickeln - nur auf dem eigenen Rechner, nie auf dem Server.
if ($p === 'autologin') {
    if (ist_lokal()) {
        $u = erp_benutzer_per_token((string)($_GET['token'] ?? ''));
        if ($u && lg_darf_rein($u)) { $_SESSION['uid'] = (int)$u['id']; weiter('?p=finden'); }
    }
    weiter('?p=login');
}

if ($p !== 'login' && !lg_angemeldet()) {
    if ($p === 'leuchten' || $p === 'klingeln') json_antwort(['ok' => false, 'meldung' => 'Bitte neu anmelden.'], 401);
    weiter('?p=login');
}
if ($p === 'login' && lg_angemeldet()) weiter('?p=finden');

if (!isset($routen[$p])) $p = lg_angemeldet() ? 'finden' : 'login';
if (in_array($p, $nur_admin, true) && !lg_ist_admin()) weiter('?p=plaetze');

require __DIR__ . '/../../lager/module/' . $routen[$p];
