<?php
// Einziger Web-Einstieg des Buchhaltungs-Programms (Front Controller). Nur Whitelist-Routen sind
// erreichbar. Der Code liegt außerhalb von public/ in buchhaltung/. Muster wie /produktion/, /lager/.
require_once __DIR__ . '/../../buchhaltung/core/config.php';
require_once __DIR__ . '/../../buchhaltung/core/db.php';
require_once __DIR__ . '/../../buchhaltung/core/schema.php';   // Finanz-DDL + finanz.php
require_once __DIR__ . '/../../buchhaltung/core/auth.php';
require_once __DIR__ . '/../../buchhaltung/core/layout.php';

bu_session_start();
bu_schema();   // Finanz-Tabellen idempotent sicherstellen (beleg*, zahlung)

$routen = [
    'buchhaltung'       => 'buchhaltung/hub.php',          // Finanz-Hub (Übersicht/OP/Verbindlichkeiten/Auswertung/Prüfung/Export)
    'rechnungen'        => 'beleg/rechnungen_liste.php',
    'rechnung'          => 'beleg/detail.php',
    'rechnung_neu'      => 'beleg/rechnung_neu.php',
    'rechnung_frei'     => 'beleg/rechnung_frei.php',
    'rechnung_import'   => 'beleg/rechnung_import.php',
    'auftrag_import'    => 'beleg/auftrag_import.php',
    'gutschrift_neu'    => 'beleg/gutschrift_neu.php',
    'gutschrift_pdf'    => 'beleg/gutschrift_pdf.php',
    'rechnung_pdf'      => 'beleg/rechnung_pdf.php',
    'rechnung_xml'      => 'buchhaltung/rechnung_xml.php',
    'beleg_export'      => 'buchhaltung/export.php',
    'lief_rechnung_neu' => 'buchhaltung/lief_rechnung_neu.php',
    'lief_rechnung'     => 'buchhaltung/lief_rechnung.php',
    'login'             => 'auth/login.php',
];

$p = isset($_GET['p']) ? preg_replace('/[^a-z0-9_]/', '', (string)$_GET['p']) : 'buchhaltung';

function weiter(string $ziel): never { header('Location: ' . $ziel); exit; }

if ($p === 'logout') { bu_logout(); weiter('?p=login'); }

// Autologin nur lokal (Entwicklung), nie auf dem Server.
if ($p === 'autologin') {
    if (ist_lokal()) {
        $u = erp_benutzer_per_token((string)($_GET['token'] ?? ''));
        if ($u && bu_darf_rein($u)) { $_SESSION['uid'] = (int)$u['id']; weiter('?p=buchhaltung'); }
    }
    weiter('?p=login');
}

if ($p !== 'login' && !bu_angemeldet()) weiter('?p=login');
if ($p === 'login' && bu_angemeldet()) weiter('?p=buchhaltung');
if (!isset($routen[$p])) $p = bu_angemeldet() ? 'buchhaltung' : 'login';

require __DIR__ . '/../../buchhaltung/module/' . $routen[$p];
