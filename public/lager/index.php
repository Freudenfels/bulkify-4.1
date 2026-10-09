<?php
// Einziger Web-Einstieg des Lager-Programms (Front Controller). Nur was in der Whitelist steht,
// laesst sich aufrufen. Der Code liegt ausserhalb von public/ in lager/.
require_once __DIR__ . '/../../lager/core/config.php';
require_once __DIR__ . '/../../lager/core/db.php';
require_once __DIR__ . '/../../lager/core/schema.php';
require_once __DIR__ . '/../../lager/core/auth.php';
require_once __DIR__ . '/../../lager/core/layout.php';
require_once __DIR__ . '/../../lager/core/led.php';
require_once __DIR__ . '/../../lager/core/leiste.php';
require_once __DIR__ . '/../../lager/core/kiste.php';

lg_session_start();
lg_schema();

$routen = [
    'login'          => 'auth/login.php',
    'uebersicht'     => 'start.php',   // Lager-Startseite (Dashboard/Überblick)
    // Grosses Lager
    'erwartet'       => 'bestand/erwartet.php',
    'einlagern'      => 'bestand/einlagern.php',      // Produktion -> Lager-Uebergabe (Ein-Klick)
    'we'             => 'bestand/wareneingang.php',   // vollwertiger Wareneingang (L1/L2 + KI-Scan)
    'bestand'        => 'bestand/liste.php',
    'eingang'        => 'bestand/eingang.php',
    'ausgang'        => 'bestand/ausgang.php',
    // Warenausgang / Versand (Sendungen planen, Lieferschein, spaeter DHL/Cargoboard)
    'versand'        => 'versand/liste.php',
    'versand_detail' => 'versand/detail.php',
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
    'suche'          => 'leiste/suche.php',
    // System
    'einstellungen'  => 'system/einstellungen.php',
    'sender'         => 'system/sender.php',
    'bruecke_skript' => 'system/bruecke_skript.php',
    'druck_job'      => 'system/druck_job.php',   // Etikett-Druckauftrag in die Warteschlange (kein Admin)
];
// Nur fuer Admins.
$nur_admin = ['einstellungen', 'sender', 'bruecke_skript'];

$p = isset($_GET['p']) ? preg_replace('/[^a-z0-9_]/', '', (string)$_GET['p']) : 'uebersicht';

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

if ($p !== 'login' && !lg_angemeldet()) {
    if ($p === 'klingeln' || $p === 'suche') json_antwort(['ok' => false, 'meldung' => 'Bitte neu anmelden.'], 401);
    weiter('?p=login');
}
if ($p === 'login' && lg_angemeldet()) weiter('?p=uebersicht');

if (!isset($routen[$p])) $p = lg_angemeldet() ? 'uebersicht' : 'login';
if (in_array($p, $nur_admin, true) && !lg_ist_admin()) weiter('?p=bestand');

require __DIR__ . '/../../lager/module/' . $routen[$p];
