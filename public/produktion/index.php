<?php
// Einziger Web-Einstieg des Produktions-Programms (Front Controller). Nur Whitelist-Routen sind
// erreichbar. Der Code liegt außerhalb von public/ in produktion/. Muster wie /lager/.
require_once __DIR__ . '/../../produktion/core/config.php';
require_once __DIR__ . '/../../produktion/core/db.php';
require_once __DIR__ . '/../../produktion/core/schema.php';
require_once __DIR__ . '/../../produktion/core/auth.php';
require_once __DIR__ . '/../../produktion/core/layout.php';

pr_session_start();

$routen = [
    'login' => 'auth/login.php',
    'dash'  => 'produktion/dash.php',
    'liste' => 'produktion/liste.php',
    'pa'    => 'produktion/detail.php',
    'run'   => 'produktion/run.php',
    'etikett' => 'produktion/etikett.php',   // Kunden-Etikett-Datei ausliefern (inline)
    'qs'      => 'produktion/qs.php',         // QS & Labor: Rückstellmuster, Laborprobe, Freigabedokument
    'kalender'    => 'produktion/kalender.php',
    'reinigung'   => 'betrieb/reinigung.php',
    'anleitungen' => 'betrieb/anleitungen.php',
];

$p = isset($_GET['p']) ? preg_replace('/[^a-z0-9_]/', '', (string)$_GET['p']) : 'dash';

if ($p === 'logout') { pr_logout(); weiter('?p=login'); }

// Autologin nur lokal (Entwicklung), nie auf dem Server.
if ($p === 'autologin') {
    if (ist_lokal()) {
        $u = erp_benutzer_per_token((string)($_GET['token'] ?? ''));
        if ($u && pr_darf_rein($u)) { $_SESSION['uid'] = (int)$u['id']; weiter('?p=dash'); }
    }
    weiter('?p=login');
}

if ($p !== 'login' && !pr_angemeldet()) weiter('?p=login');
if ($p === 'login' && pr_angemeldet()) weiter('?p=dash');
if (!isset($routen[$p])) $p = pr_angemeldet() ? 'dash' : 'login';

require __DIR__ . '/../../produktion/module/' . $routen[$p];
