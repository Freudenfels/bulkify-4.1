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
    'dl_rechnungen'     => 'beleg/rechnungen_liste.php',  // vorgefilterte Liste (nur Dienstleistungs-Rechnungen DR-)
    'dl_rechnung_neu'   => 'beleg/dl_rechnung_neu.php',   // DL-Rechnung aus DL-Auftrag: Vorschau + verbindlich erstellen
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
    'angebote_ansicht'  => 'buchhaltung/angebote_ansicht.php',   // Nur-Lese-Angebote + Abgleich Angebot/Auftrag/Rechnung
    'import_bulk'       => 'buchhaltung/import_bulk.php',         // Bulk-Import alter Angebote+Rechnungen (KI)
    'einstellungen'     => 'system/einstellungen.php',           // GoBD scharfschalten u. a.
    'beleg_eingang'     => 'buchhaltung/beleg_eingang.php',       // Beleg-Posteingang (KI-Upload)
    'beleg_upload'      => 'buchhaltung/beleg_upload.php',        // Beleg hochladen (nach Login)
    'beleg_detail'      => 'buchhaltung/beleg_detail.php',        // Beleg prüfen/erfassen
    'beleg_datei'       => 'buchhaltung/beleg_datei.php',         // Beleg-Datei ausliefern
    'beleg_foto'        => 'buchhaltung/beleg_foto.php',          // öffentliche Handy-Foto-Seite (Token)
    'login'             => 'auth/login.php',
];

// Öffentliche Routen (ohne Buchhaltungs-Login): Login + token-geschützter Handy-Foto-Upload.
$PUBLIC = ['login', 'beleg_foto'];

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

if (!in_array($p, $PUBLIC, true) && !bu_angemeldet()) weiter('?p=login');
if ($p === 'login' && bu_angemeldet()) weiter('?p=buchhaltung');
if (!isset($routen[$p])) $p = bu_angemeldet() ? 'buchhaltung' : 'login';

require __DIR__ . '/../../buchhaltung/module/' . $routen[$p];
