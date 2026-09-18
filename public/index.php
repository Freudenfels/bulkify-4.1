<?php
// Einziger Web-Einstieg bulkify 4.1 (Front Controller)
define('BX_T0', microtime(true));   // Request-Start für die Diagnose-Messung
session_start();
require_once __DIR__ . '/../core/schema.php';
require_once __DIR__ . '/../core/pdf_beleg.php';   // beleg_firma() – auch fuer den AGB-Entwurfstext
require_once __DIR__ . '/../core/agb.php';
require_once __DIR__ . '/../core/mail.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/perf.php';
// Am Ende jedes Requests eine Messzeile schreiben – nur wenn die Diagnose eingeschaltet ist.
// Bei eingeschalteter Diagnose zusätzlich die Abfrage-Muster mitzählen (core/db.php).
$GLOBALS['bx_q_trace'] = perf_aktiv();
register_shutdown_function(function () { perf_aufzeichnen(BX_T0); });

// Schema beim Start sicherstellen (idempotent) + ersten Admin anlegen
init_schema();
seed_benutzer_if_empty();

// Router: Whitelist Seite -> Modul-Datei. Kein direkter Dateizugriff moeglich.
$routes = [
    'login'       => 'auth/login.php',
    'dashboard' => 'intern/dashboard.php',
    'kunden'      => 'kunde/liste.php',
    'kunde'       => 'kunde/detail.php',
    'lieferanten'    => 'lieferant/liste.php',
    'lieferant'      => 'lieferant/detail.php',
    // Lieferantenportal (eigener Zugang, kein interner Bereich)
    'lieferant_login'        => 'lieferant/login.php',
    'lieferant_einladung'    => 'lieferant/einladung.php',
    'lieferant_portal'       => 'lieferant/portal.php',
    'lieferant_bestellung'   => 'lieferant/bestellung.php',
    'lieferant_bestellung_pdf'=> 'lieferant/bestellung_pdf.php',
    'lieferant_anfrage'      => 'lieferant/anfrage.php',
    'lieferant_profil'       => 'lieferant/profil.php',
    'lieferant_logo'         => 'lieferant/logo.php',
    'lieferant_nachrichten'  => 'lieferant/nachrichten.php',
    'lieferant_dateien'      => 'lieferant/dateien.php',
    'lieferant_katalog'      => 'lieferant/katalog.php',
    'lieferant_hilfe'        => 'lieferant/hilfe.php',
    'lieferant_dokument'     => 'lieferant/dokument.php',
    'partner'        => 'partner/liste.php',
    'partner_detail' => 'partner/detail.php',
    'rohstoffe'      => 'lager/rohstoffe_liste.php',
    'rohstoff'       => 'lager/rohstoff_detail.php',
    'rohstoff_split' => 'lager/rohstoff_split.php',   // lange Namen (Varianten) aufschluesseln
    'freigaben'      => 'lager/freigaben.php',       // offene Kundenfreigaben (Spec/CoA)
    'lieferant_preise'=> 'einkauf/lieferant_preise.php', // strukturierte Lieferantenpreise (Rohstoff/Fertigprodukt)
    'lief_preisliste'=> 'lager/lief_preisliste.php', // EK-Preisliste (Referenz aus v3)
    'spec_pdf'       => 'lager/spec_download.php',
    'spec_bulkify'   => 'lager/spec_bulkify.php',   // unsere Spezifikation (bulkify-Layout)
    'coa_bulkify'    => 'lager/spec_bulkify.php',   // unser Analysenzertifikat zur Charge
    'dokument'       => 'lager/dokument_download.php',
    'naehrstoffe'    => 'lager/naehrstoffe_liste.php',
    'naehrstoff'     => 'lager/naehrstoff_detail.php',
    'verpackungen'   => 'lager/verpackungen_liste.php',
    'verpackung'     => 'lager/verpackung_detail.php',
    'verpackung_dok' => 'lager/verpackung_dok_download.php',
    'rezeptur'        => 'rezeptur/liste.php',
    'rezeptur_detail' => 'rezeptur/detail.php',
    'rezept_preise'   => 'rezeptur/lief_preise.php', // Rezeptur-Preise (Fremdfertigung) – Uebersicht wie v3
    'kapsel_referenz' => 'system/kapsel_referenz.php',   // Nachschlagewerk Kapselgrößen
    'crmdemo'         => 'crmdemo/app.php',               // isolierte CRM-Demo (Lieferanten-Beta), versteckt
    'produkte'        => 'produkt/liste.php',
    'produkt'         => 'produkt/detail.php',
    'produkt_pib'     => 'produkt/pib.php',          // Produktinformationsblatt (PIB) ansehen (Auto oder hochgeladen)
    'novelfood'       => 'produkt/novelfood.php',   // Schnell-Nachschlage: ist ein Stoff Novel Food?
    'angebote'        => 'angebot/liste.php',
    'angebot'         => 'angebot/detail.php',
    'angebot_pdf'     => 'angebot/pdf.php',
    'vertrag_pdf'     => 'angebot/vertrag_pdf.php',   // Jahresabnahmevertrag (PDF) zum Angebot
    'auftraege'       => 'auftrag/liste.php',
    'kontingente'     => 'kontingent/liste.php',
    'auftrag'         => 'auftrag/detail.php',
    'rechnungen'      => 'beleg/rechnungen_liste.php',
    'rechnung'        => 'beleg/detail.php',
    'portal'          => 'portal/kunde.php',
    'portal_login'    => 'portal/login.php',   // Kunden-Login (E-Mail + Passwort)
    'portal_dok'     => 'portal/dokument_download.php',
    'werk'               => 'intern/werk_cockpit.php',
    'aufgaben'           => 'intern/aufgaben.php',
    'produktion'         => 'produktion/liste.php',
    'produktionsauftrag' => 'produktion/detail.php',
    'kalender'           => 'produktion/kalender.php',
    'lager'              => 'lager/bestand_liste.php',
    'lager2'             => 'lager/lager2.php',
    'betriebsmittel'     => 'lager/betriebsmittel_detail.php',
    'wareneingang'       => 'lager/wareneingang.php',
    'chargen'            => 'lager/chargen.php',
    'versand'            => 'versand/liste.php',
    'einstellungen'      => 'system/einstellungen.php',
    'suche'              => 'system/suche.php',        // globale Suche (Admin) über alle Bereiche
    'db_import'          => 'system/db_import.php',   // einmalige DB-Übernahme (Admin) – Rohstoffe & Co. auf einen anderen Stand bringen
    'anfragen'           => 'anfrage/liste.php',
    'anfrage'            => 'anfrage/detail.php',
    'portal_anfragen'    => 'intern/portal_anfragen.php',
    'portal_anfrage'     => 'intern/portal_anfrage_detail.php',
    'v3_reparatur'       => 'intern/v3_reparatur.php',   // TEMPORÄR (v3-Migration) – nach Abschluss entfernen
    'einkauf'            => 'einkauf/liste.php',
    'bestellung'         => 'einkauf/detail.php',
    'bestellung_pdf'     => 'einkauf/pdf.php',
    'preis_anfragen'     => 'einkauf/preis_anfragen.php',
    'ek_import'          => 'einkauf/ek_import.php',   // eingelesene EK-Preislisten (CSV) + Zuordnung
    'ek_lieferanten'     => 'einkauf/ek_lieferanten.php',   // Text-Lieferanten der EK-Preise zuordnen/anlegen
    'bedarf'             => 'einkauf/bedarf.php',
    'einkaufsliste'      => 'einkauf/einkaufsliste.php',
    'benutzer'           => 'system/benutzer_liste.php',
    'benutzer_detail'    => 'system/benutzer_detail.php',
    // Hintergrundarbeit der KI (kein Login, dafuer Schluessel) - siehe core/ki_job.php
    'ki_job'              => 'system/ki_job.php',
    'app'                => 'system/app.php',        // bulkify aufs Handy legen
];

$p = isset($_GET['p']) ? preg_replace('/[^a-z0-9_]/', '', $_GET['p']) : 'dashboard';

// Logout
if ($p === 'logout') { auth_logout(); header('Location: ?p=login'); exit; }

// Autologin per Token (nur localhost) – bequemer Direktlink zum Testen
if ($p === 'autologin') {
    if (auth_login_by_token($_GET['token'] ?? '')) {
        $ziel = (function_exists('ist_echter_lieferant') && ist_echter_lieferant()) ? 'lieferant_portal'
              : ((function_exists('ist_produktionsbereich') && ist_produktionsbereich()) ? 'werk' : 'dashboard');
        header('Location: ?p=' . $ziel); exit;
    }
    header('Location: ?p=login'); exit;
}

// Öffentliche Routen (ohne internen Login): Login-Seite + Kundenportal (Token-basiert)
$PUBLIC = ['login', 'portal', 'portal_login', 'portal_dok', 'lieferant_login', 'lieferant_einladung', 'ki_job'];   // portal_dok prüft Token + Freigabe selbst

// Nicht angemeldet -> zur Login-Seite (außer öffentliche Routen)
if (!in_array($p, $PUBLIC, true) && !is_logged_in()) { header('Location: ?p=login'); exit; }

// Produktionsmitarbeiter haben einen eigenen Bereich (Werk) statt des Verkaufs-Dashboards
$istWerk = is_logged_in() && function_exists('ist_produktionsbereich') && ist_produktionsbereich();

// Admin-Vorschau ins Lieferantenportal: ein Team-Mitglied schaut sich das Portal eines Lieferanten an
// (wie die interne Vorschau beim Kunden). Nur fuer angemeldete Team-Mitglieder, kein echter Lieferant.
if (is_logged_in() && function_exists('ist_echter_lieferant') && !ist_echter_lieferant()) {
    if ($p === 'lief_vorschau_start' && (int)($_GET['id'] ?? 0) > 0) { $_SESSION['lief_vorschau'] = (int)$_GET['id']; header('Location: ?p=lieferant_portal'); exit; }
    if ($p === 'lief_vorschau_stop') { $lz = (int)($_SESSION['lief_vorschau'] ?? 0); unset($_SESSION['lief_vorschau']); header('Location: ?p=' . ($lz ? 'lieferant&id=' . $lz : 'lieferanten')); exit; }
}

// Lieferanten haben ein eigenes Portal und duerfen NICHT in den internen Bereich.
// Fuer die ROUTE-Sperre zaehlt nur ein ECHTER Lieferant (Login am Lieferanten) – ein Team-Mitglied
// in der Vorschau bleibt frei navigierbar und kann zurueck in den Admin-Bereich.
$istLieferant = is_logged_in() && function_exists('ist_echter_lieferant') && ist_echter_lieferant();
$LIEF_ROUTEN  = ['lieferant_portal', 'lieferant_bestellung', 'lieferant_bestellung_pdf', 'lieferant_anfrage', 'lieferant_profil', 'lieferant_logo',
                 'lieferant_nachrichten', 'lieferant_dateien', 'lieferant_dokument', 'lieferant_katalog', 'lieferant_hilfe', 'logout'];
if ($istLieferant && !in_array($p, $LIEF_ROUTEN, true) && !in_array($p, ['lieferant_login','lieferant_einladung'], true)) {
    header('Location: ?p=lieferant_portal'); exit;
}

// Bereits angemeldet und ruft Login auf -> ins passende Dashboard
if ($p === 'login' && is_logged_in()) { header('Location: ?p=' . ($istLieferant ? 'lieferant_portal' : ($istWerk ? 'werk' : 'dashboard'))); exit; }

if (!isset($routes[$p])) $p = is_logged_in() ? ($istLieferant ? 'lieferant_portal' : ($istWerk ? 'werk' : 'dashboard')) : 'login';

// Werk-Mitarbeiter: Verkaufs-Dashboard -> Werk-Cockpit
if ($istWerk && $p === 'dashboard') { header('Location: ?p=werk'); exit; }

// Rechteprüfung (öffentliche Routen ausgenommen)
if (!in_array($p, $PUBLIC, true) && !route_erlaubt($p)) {
    render_header('', 'Kein Zugriff');
    echo '<div class="bx-panel" style="border-color:#e6c4c0"><h2 style="margin-top:0">Kein Zugriff</h2>'
       . '<p class="muted">Für diesen Bereich fehlt deiner Rolle die Berechtigung. Wende dich an einen Admin, wenn du Zugriff brauchst.</p>'
       . '<a class="btn btn-ghost" href="?p=dashboard">Zum Dashboard</a></div>';
    render_footer();
    exit;
}

$file = BX_ROOT . '/module/' . $routes[$p];
if (!is_file($file)) { http_response_code(404); echo 'Seite nicht gefunden'; exit; }

require $file;
