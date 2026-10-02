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
require_once __DIR__ . '/../../lager/core/kiste.php';

lg_session_start();
lg_schema();

$routen = [
    'login'          => 'auth/login.php',
    // Grosses Lager
    'erwartet'       => 'bestand/erwartet.php',
    'we'             => 'bestand/wareneingang.php',   // vollwertiger Wareneingang (L1/L2 + KI-Scan)
    'bestand'        => 'bestand/liste.php',
    'eingang'        => 'bestand/eingang.php',
    'ausgang'        => 'bestand/ausgang.php',
    'bewegungen'     => 'bestand/bewegungen.php',
    'etikett'        => 'bestand/etikett.php',
    'charge'         => 'bestand/charge.php',
    'dok'            => 'bestand/dok.php',
    // Lager 2 (Fremdlager): Kundenware (charge.fremd_kunde_id)
    'l2_bestand'     => 'bestand/l2_bestand.php',
    'l2_eingang'     => 'bestand/l2_eingang.php',
    'l2_finden'      => 'bestand/l2_finden.php',
    'finden'         => 'leiste/finden.php',
    'leisten'        => 'leiste/liste.php',
    'batterie'       => 'leiste/batterie.php',
    'kisten'         => 'kiste/liste.php',
    'kiste'          => 'kiste/detail.php',
    'klingeln'       => 'led/klingeln.php',
    'suche'          => 'leiste/suche.php',
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

$p = isset($_GET['p']) ? preg_replace('/[^a-z0-9_]/', '', (string)$_GET['p']) : 'bestand';

if ($p === 'logout') { lg_logout(); weiter('?p=login'); }

// Direktlink beim Entwickeln - nur auf dem eigenen Rechner, nie auf dem Server.
if ($p === 'autologin') {
    if (ist_lokal()) {
        $u = erp_benutzer_per_token((string)($_GET['token'] ?? ''));
        if ($u && lg_darf_rein($u)) { $_SESSION['uid'] = (int)$u['id']; weiter('?p=bestand'); }
    }
    weiter('?p=login');
}

if ($p !== 'login' && !lg_angemeldet()) {
    if ($p === 'leuchten' || $p === 'klingeln' || $p === 'suche') json_antwort(['ok' => false, 'meldung' => 'Bitte neu anmelden.'], 401);
    weiter('?p=login');
}
if ($p === 'login' && lg_angemeldet()) weiter('?p=bestand');

if (!isset($routen[$p])) $p = lg_angemeldet() ? 'bestand' : 'login';
if (in_array($p, $nur_admin, true) && !lg_ist_admin()) weiter('?p=bestand');

require __DIR__ . '/../../lager/module/' . $routen[$p];
