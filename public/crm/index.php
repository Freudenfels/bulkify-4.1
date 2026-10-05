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
    'pipeline' => 'kontakt/pipeline.php',
    'todos'    => 'todo/liste.php',
    'kontakte' => 'kontakt/liste.php',
    'kontakt'  => 'kontakt/detail.php',
    'erfassen' => 'kontakt/erfassen.php',
    'mail'     => 'mail/lesen.php',
    'eingang'  => 'mail/eingang.php',
    'termine'  => 'termin/liste.php',
    'kalender' => 'termin/kalender.php',
    'kunden'   => 'kunde/liste.php',
    'kunde'    => 'kunde/detail.php',
    'einstellungen' => 'system/einstellungen.php',
    'mehr'     => 'system/einstellungen.php',   // Alt-Link: zeigt weiterhin auf die Einstellungen
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

// Token-geschuetzter Schema-Check: ?schemacheck=1&dbg=<lead_intake_token>. Zeigt, welche crm_-Tabellen
// fehlen und mit welchem Fehler eine Anlage scheitert. Nur zur Diagnose; wird danach entfernt.
if (($_GET['schemacheck'] ?? '') !== '' && hash_equals(lead_intake_token(), (string)($_GET['dbg'] ?? ''))) {
    header('Content-Type: text/plain; charset=utf-8');
    $tabs = ['crm_kontakt','crm_meta','crm_kontakt_datei','crm_mail_eingang','crm_kunde_profil','crm_todo','crm_mitarbeiter','crm_rezeptur_ki'];
    echo "DB: " . DB_NAME . "\n\nTabellen:\n";
    foreach ($tabs as $t) {
        $da = (bool) scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?", [$t]);
        echo '  ' . str_pad($t, 22) . ($da ? 'ok' : 'FEHLT') . "\n";
    }
    echo "\nCREATE-Test (crm_schema_test): ";
    try { q("CREATE TABLE IF NOT EXISTS crm_schema_test (id INT)"); echo "OK (CREATE erlaubt)\n"; q("DROP TABLE IF EXISTS crm_schema_test"); }
    catch (Throwable $e) { echo "FEHLER: " . $e->getMessage() . "\n"; }
    echo "\nreale crm_kontakt_datei-Anlage: ";
    try { q("CREATE TABLE IF NOT EXISTS crm_kontakt_datei (id INT AUTO_INCREMENT PRIMARY KEY, kontakt_id INT NOT NULL, kategorie VARCHAR(20) NOT NULL DEFAULT 'sonstiges', original VARCHAR(255) NOT NULL, stored VARCHAR(190) NOT NULL, groesse INT NOT NULL DEFAULT 0, benutzer_id INT NULL, angelegt DATETIME NOT NULL, KEY (kontakt_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); echo "OK\n"; }
    catch (Throwable $e) { echo "FEHLER: " . $e->getMessage() . "\n"; }
    exit;
}

// Fehler-Anzeiger: nur mit korrektem Token (?dbg=<lead_intake_token>) wird die genaue Meldung
// gezeigt - zum Aufspueren eines 500, ohne Server-Logzugriff. Sonst normaler Fehler (500).
try {
    require __DIR__ . '/../../crm/module/' . $routen[$p];
} catch (Throwable $e) {
    $dbg = (string)($_GET['dbg'] ?? '');
    if ($dbg !== '' && hash_equals(lead_intake_token(), $dbg)) {
        http_response_code(200);   // 200, damit der Browser den Text zeigt (statt 500-Fehlerseite)
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/plain; charset=utf-8');
        echo "FEHLER: " . $e->getMessage() . "\n@ " . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString();
        exit;
    }
    throw $e;
}
