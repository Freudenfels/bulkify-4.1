<?php
// Einziger Web-Einstieg des CRM (Front Controller). Kein direkter Dateizugriff moeglich:
// nur was in der Whitelist steht, laesst sich aufrufen.
require_once __DIR__ . '/../../crm/core/config.php';
require_once __DIR__ . '/../../crm/core/db.php';
require_once __DIR__ . '/../../crm/core/schema.php';
require_once __DIR__ . '/../../crm/core/auth.php';
require_once __DIR__ . '/../../crm/core/layout.php';

crm_session_start();
crm_schema();          // eigene Tabellen sicherstellen (idempotent, nur crm_*)

$routen = [
    'login'    => 'auth/login.php',
    'wartet'   => 'liste/wartet.php',
    'kontakte' => 'kontakt/liste.php',
    'kontakt'  => 'kontakt/detail.php',
    'erfassen' => 'kontakt/erfassen.php',
    'mail'     => 'mail/lesen.php',
    'termine'  => 'termin/liste.php',
    'kunden'   => 'kunde/liste.php',
    'kunde'    => 'kunde/detail.php',
    'mehr'     => 'system/mehr.php',
];

$p = isset($_GET['p']) ? preg_replace('/[^a-z0-9_]/', '', (string)$_GET['p']) : 'wartet';

if ($p === 'logout') { crm_logout(); header('Location: ?p=login'); exit; }

// Bequemer Direktlink beim Entwickeln - nur auf dem eigenen Rechner, nie auf dem Server.
if ($p === 'autologin') {
    if (ist_lokal()) {
        $u = erp_benutzer_per_token((string)($_GET['token'] ?? ''));
        if ($u && !in_array('lieferant', crm_rollen_von($u), true)) { $_SESSION['uid'] = (int)$u['id']; header('Location: ?p=wartet'); exit; }
    }
    header('Location: ?p=login'); exit;
}

// Alles ausser der Anmeldung braucht einen angemeldeten Mitarbeiter.
if ($p !== 'login' && !crm_angemeldet()) { header('Location: ?p=login'); exit; }
if ($p === 'login' && crm_angemeldet())  { header('Location: ?p=wartet'); exit; }

if (!isset($routen[$p])) $p = crm_angemeldet() ? 'wartet' : 'login';

require __DIR__ . '/../../crm/module/' . $routen[$p];
