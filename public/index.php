<?php
// Einziger Web-Einstieg bulkify 4.1 (Front Controller)
session_start();
require_once __DIR__ . '/../core/schema.php';
require_once __DIR__ . '/../core/pdf_beleg.php';   // beleg_firma() – auch fuer den AGB-Entwurfstext
require_once __DIR__ . '/../core/agb.php';
require_once __DIR__ . '/../core/mail.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

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
    'produkte'        => 'produkt/liste.php',
    'produkt'         => 'produkt/detail.php',
    'angebote'        => 'angebot/liste.php',
    'angebot'         => 'angebot/detail.php',
    'angebot_pdf'     => 'angebot/pdf.php',
    'auftraege'       => 'auftrag/liste.php',
    'auftrag'         => 'auftrag/detail.php',
    'rechnungen'      => 'beleg/rechnungen_liste.php',
    'rechnung'        => 'beleg/detail.php',
    'portal'          => 'portal/kunde.php',
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
    'anfragen'           => 'anfrage/liste.php',
    'anfrage'            => 'anfrage/detail.php',
    'portal_anfragen'    => 'intern/portal_anfragen.php',
    'portal_anfrage'     => 'intern/portal_anfrage_detail.php',
    'einkauf'            => 'einkauf/liste.php',
    'bestellung'         => 'einkauf/detail.php',
    'bestellung_pdf'     => 'einkauf/pdf.php',
    'preis_anfragen'     => 'einkauf/preis_anfragen.php',
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
        $ziel = (function_exists('ist_lieferant') && ist_lieferant()) ? 'lieferant_portal'
              : ((function_exists('ist_produktionsbereich') && ist_produktionsbereich()) ? 'werk' : 'dashboard');
        header('Location: ?p=' . $ziel); exit;
    }
    header('Location: ?p=login'); exit;
}

// Öffentliche Routen (ohne internen Login): Login-Seite + Kundenportal (Token-basiert)
$PUBLIC = ['login', 'portal', 'portal_dok', 'lieferant_login', 'lieferant_einladung', 'ki_job'];   // portal_dok prüft Token + Freigabe selbst

// Nicht angemeldet -> zur Login-Seite (außer öffentliche Routen)
if (!in_array($p, $PUBLIC, true) && !is_logged_in()) { header('Location: ?p=login'); exit; }

// Produktionsmitarbeiter haben einen eigenen Bereich (Werk) statt des Verkaufs-Dashboards
$istWerk = is_logged_in() && function_exists('ist_produktionsbereich') && ist_produktionsbereich();

// Lieferanten haben ein eigenes Portal und duerfen NICHT in den internen Bereich.
$istLieferant = is_logged_in() && function_exists('ist_lieferant') && ist_lieferant();
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
