<?php
// Einziger Web-Einstieg des Produktions-Programms (Front Controller). Nur Whitelist-Routen sind
// erreichbar. Der Code liegt außerhalb von public/ in produktion/. Muster wie /lager/.
require_once __DIR__ . '/../../produktion/core/config.php';
require_once __DIR__ . '/../../produktion/core/db.php';
require_once __DIR__ . '/../../produktion/core/auth.php';
require_once __DIR__ . '/../../produktion/core/layout.php';

pr_session_start();

$routen = [
    'login' => 'auth/login.php',
    'liste' => 'produktion/liste.php',
    'pa'    => 'produktion/detail.php',
    'run'   => 'produktion/run.php',
];

$p = isset($_GET['p']) ? preg_replace('/[^a-z0-9_]/', '', (string)$_GET['p']) : 'liste';

if ($p === 'logout') { pr_logout(); weiter('?p=login'); }

// Autologin nur lokal (Entwicklung), nie auf dem Server.
if ($p === 'autologin') {
    if (ist_lokal()) {
        $u = erp_benutzer_per_token((string)($_GET['token'] ?? ''));
        if ($u && pr_darf_rein($u)) { $_SESSION['uid'] = (int)$u['id']; weiter('?p=liste'); }
    }
    weiter('?p=login');
}

if ($p !== 'login' && !pr_angemeldet()) weiter('?p=login');
if ($p === 'login' && pr_angemeldet()) weiter('?p=liste');
if (!isset($routen[$p])) $p = pr_angemeldet() ? 'liste' : 'login';

require __DIR__ . '/../../produktion/module/' . $routen[$p];
