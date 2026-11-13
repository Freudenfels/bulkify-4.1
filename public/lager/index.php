<?php
// Einziger Web-Einstieg des Lager-Programms (Front Controller). Nur was in der Whitelist steht,
// laesst sich aufrufen. Der Code liegt ausserhalb von public/ in lager/.
require_once __DIR__ . '/../../lager/core/config.php';
require_once __DIR__ . '/../../lager/core/db.php';
require_once __DIR__ . '/../../core/einmal.php'; einmal_pruefen();   // Fable-6 T2.6: doppelt abgeschickte Formulare ablehnen
require_once __DIR__ . '/../../lager/core/schema.php';
require_once __DIR__ . '/../../lager/core/auth.php';
require_once __DIR__ . '/../../lager/core/layout.php';
require_once __DIR__ . '/../../lager/core/led.php';
require_once __DIR__ . '/../../lager/core/leiste.php';
require_once __DIR__ . '/../../lager/core/kiste.php';
require_once __DIR__ . '/../../lager/core/inventur.php';

lg_session_start();
lg_schema();

$routen = [
    'login'          => 'auth/login.php',
    'uebersicht'     => 'start.php',   // Lager-Startseite (Dashboard/Überblick)
    // Kachel-Seiten (Menü, 08.11.2026): führen nur auf vorhandene Seiten
    'umbuchen'       => 'kacheln.php',
    'versand_start'  => 'kacheln.php',
    'lager1'         => 'kacheln.php',
    'lager2'         => 'kacheln.php',
    // Grosses Lager
    'erwartet'       => 'bestand/erwartet.php',
    'quarantaene'    => 'bestand/quarantaene.php',    // Ware in Quarantäne freigeben (alle Chargen, optional je Artikel)
    'einlagern'      => 'bestand/einlagern.php',      // Produktion -> Lager-Uebergabe (Ein-Klick)
    'we'             => 'bestand/wareneingang.php',   // vollwertiger Wareneingang (L1/L2 + KI-Scan)
    'bestand'        => 'bestand/liste.php',
    'eingang'        => 'bestand/eingang.php',
    'ausgang'        => 'bestand/ausgang.php',
    // Warenausgang / Versand (Sendungen planen, Lieferschein, spaeter DHL/Cargoboard)
    'versand'        => 'versand/liste.php',
    'umlagern'       => 'bestand/umlagern.php',        // fertige freie Ware Lager 1 -> Lager 2 des Kunden (mobil)
    'versand_bereit' => 'versand/bereit.php',          // Soll-Kette 7: versandbereite Aufträge -> Versandart, Sendung, Lager 2
    'versand_detail' => 'versand/detail.php',
    'lieferschein_neu' => 'versand/erstellen.php',   // einfacher Lieferschein-Generator (Katalog + Kartons)
    'lieferschein'   => 'versand/lieferschein.php',
    'versand_label'  => 'versand/label.php',
    'bewegungen'     => 'bestand/bewegungen.php',
    'papierkorb'     => 'bestand/papierkorb.php',
    'etikett'        => 'bestand/etikett.php',
    'etikett_ansicht'=> 'bestand/etikett_ansicht.php',   // In-App-Ansicht mit Zurück-Button
    'gebinde'        => 'bestand/gebinde.php',           // eigene Gebinde-Aufkleber (QR+Nr) + Scan-Auflösung
    'gebinde_etikett'=> 'bestand/gebinde_etikett.php',   // Gebinde-Aufkleber als PDF
    'charge'         => 'bestand/charge.php',
    'dok'            => 'bestand/dok.php',
    // Lager 2 (Fremdlager): Kundenware (charge.fremd_kunde_id)
    'l2_bestand'     => 'bestand/l2_bestand.php',
    'l2_eingang'     => 'bestand/l2_eingang.php',
    'l2_finden'      => 'bestand/l2_finden.php',
    'l2_artikel'     => 'bestand/l2_artikel.php',        // Lager-2-Artikelkatalog (Stammdaten)
    'l2_artikel_edit'=> 'bestand/l2_artikel_edit.php',
    'bild'           => 'bestand/bild.php',              // Bild aus data/uploads ausgeben
    'finden'         => 'leiste/finden.php',
    'leisten'        => 'leiste/liste.php',
    'batterie'       => 'leiste/batterie.php',
    'kisten'         => 'kiste/liste.php',
    'kiste'          => 'kiste/detail.php',
    'klingeln'       => 'led/klingeln.php',
    'api_blink'      => 'led/api_blink.php',   // interner Blink-Auslöser für andere Programme (Token/Loopback)
    'api_druck'      => 'system/api_druck.php',// interner Druck-Auslöser (Proben-Etikett) für andere Programme (Token/Loopback)
    'suche'          => 'leiste/suche.php',
    // System
    'einstellungen'  => 'system/einstellungen.php',
    'sender'         => 'system/sender.php',
    'bruecke_skript' => 'system/bruecke_skript.php',
    'druck_job'      => 'system/druck_job.php',
    'druck_vorschau' => 'system/druck_vorschau.php',   // nur Testumgebung: Druckaufträge als PDF ansehen (Paket 12.11.2026)   // Etikett-Druckauftrag in die Warteschlange (kein Admin)
    // Inventur (11.11.2026): eigener Modus, getrennt vom Tagesgeschäft. Nur Admin und Lager (lg_darf_inventur()).
    'inventur'           => 'inventur/liste.php',
    'inventur_neu'       => 'inventur/neu.php',          // nur Admin
    'inventur_lauf'      => 'inventur/lauf.php',
    'inventur_zaehlen'   => 'inventur/zaehlen.php',      // Zählen am Handy, blind
    'inventur_api'       => 'inventur/api.php',          // JSON für das Zählen
    'inventur_auswerten' => 'inventur/auswerten.php',    // nur Admin
    'inventur_protokoll' => 'inventur/protokoll.php',    // nur Admin
    'plaetze'            => 'inventur/plaetze.php',      // Lagerplätze Lager 1/2 (Anlegen nur Admin)
    'tara'               => 'inventur/tara.php',         // Gebindetypen mit Tara, nur Admin
];
// Nur fuer Admins.
$nur_admin = ['einstellungen', 'sender', 'bruecke_skript'];
// Inventur nur für Admin und Lager (Nico 11.11.2026); auswerten/anlegen/Protokoll/Tara nur Admin.
$nur_inventur = ['inventur', 'inventur_lauf', 'inventur_zaehlen', 'inventur_api', 'plaetze'];
array_push($nur_admin, 'inventur_neu', 'inventur_auswerten', 'inventur_protokoll', 'tara');

$p = isset($_GET['p']) ? preg_replace('/[^a-z0-9_]/', '', (string)$_GET['p']) : 'uebersicht';
// Alte Schnellmaske ?p=eingang stillgelegt (Soll-Handbuch 4.5, 08.11.2026): sie umging Gewicht je Karton, die Bulk-Regel und den
// Knopf „Neuer Artikel". Jeder Aufruf (alte Lesezeichen/Links) landet im vollwertigen Wareneingang ?p=we. Datei bleibt liegen.
if ($p === 'eingang') { header('Location: ?p=we'); exit; }
// Eine Suche über Lager 1 und Lager 2 (08.11.2026): die alte Lager-2-Suche öffnet die gemeinsame Suche mit Filter Lager 2.
if ($p === 'l2_finden') { header('Location: ?p=finden&lager=2' . (trim((string)($_GET['q'] ?? '')) !== '' ? '&q=' . rawurlencode(trim((string)$_GET['q'])) : '')); exit; }

if ($p === 'logout') { lg_logout(); weiter('?p=login'); }

// Direktlink beim Entwickeln - nur auf dem eigenen Rechner, nie auf dem Server.
if ($p === 'autologin') {
    if (ist_lokal()) {
        $u = erp_benutzer_per_token((string)($_GET['token'] ?? ''));
        if ($u && lg_darf_rein($u)) { $_SESSION['uid'] = (int)$u['id']; weiter('?p=uebersicht'); }
    }
    weiter('?p=login');
}

// Interner Blink-Endpunkt für andere Programme (z. B. Produktion): eigene Token-/Loopback-Auth,
// KEIN Lager-Login. Muss vor dem Login-Gate laufen.
if ($p === 'api_blink') { require __DIR__ . '/../../lager/module/led/api_blink.php'; exit; }
if ($p === 'api_druck') { require __DIR__ . '/../../lager/module/system/api_druck.php'; exit; }

if ($p !== 'login' && !lg_angemeldet()) {
    if ($p === 'klingeln' || $p === 'suche') json_antwort(['ok' => false, 'meldung' => 'Bitte neu anmelden.'], 401);
    weiter('?p=login');
}
if ($p === 'login' && lg_angemeldet()) weiter('?p=uebersicht');

if (!isset($routen[$p])) $p = lg_angemeldet() ? 'uebersicht' : 'login';
if (in_array($p, $nur_admin, true) && !lg_ist_admin()) weiter('?p=bestand');
if (in_array($p, $nur_inventur, true) && !lg_darf_inventur()) weiter('?p=bestand');

require __DIR__ . '/../../lager/module/' . $routen[$p];
