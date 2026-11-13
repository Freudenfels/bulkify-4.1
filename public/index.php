<?php
// Einziger Web-Einstieg bulkify 4.1 (Front Controller)
define('BX_T0', microtime(true));   // Request-Start für die Diagnose-Messung
session_start();
require_once __DIR__ . '/../core/schema.php';
require_once __DIR__ . '/../core/dienstleistung.php';   // Dienstleistungen-Modul (Katalog) – stellt dienstleistung_schema() bereit
require_once __DIR__ . '/../core/pdf_beleg.php';   // beleg_firma() – auch fuer den AGB-Entwurfstext
require_once __DIR__ . '/../core/agb.php';
require_once __DIR__ . '/../core/mail.php';
require_once __DIR__ . '/../core/nachricht.php';   // Lieferanten-Chat + Nav-Zähler „Nachrichten" global verfügbar
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

// Loopback-API „Einlagern": die Lager-App ruft hier die KANONISCHE Fertigware-Buchung auf (einlager_buchen →
// produktion_fertigware_einbuchen: Chargennr./MHD/BSKU/Lager-2). Die Sub-App kann core/schema.php nicht requiren
// (db()-Kollision), daher dieser token-authentifizierte Endpunkt VOR dem Login-Gate (kein Session-Login). Nur POST.
if (($_GET['p'] ?? '') === 'api_einlager') {
    header('Content-Type: application/json; charset=utf-8');
    $soll  = trim((string) meta_get('einlager_api_token', ''));
    $token = (string)($_POST['token'] ?? '');
    if ($soll === '' || !hash_equals($soll, $token)) { http_response_code(401); echo json_encode(['ok'=>false, 'meldung'=>'Token ungültig.']); exit; }
    $pa_id = (int)($_POST['pa_id'] ?? 0);
    if ($pa_id <= 0) { http_response_code(400); echo json_encode(['ok'=>false, 'meldung'=>'pa_id fehlt.']); exit; }
    try {
        $r = einlager_buchen($pa_id, 'Lager-App');
        echo json_encode(empty($r['ok']) ? ['ok'=>false, 'meldung'=>(string)($r['msg'] ?? 'Einlagern nicht möglich.')]
                                         : ['ok'=>true, 'ziel'=>$r['ziel'] ?? null, 'label'=>$r['label'] ?? null, 'charge_id'=>$r['charge_id'] ?? null]);
    } catch (\Throwable $e) {
        http_response_code(500); echo json_encode(['ok'=>false, 'meldung'=>$e->getMessage()]);
    }
    exit;
}

// Loopback-API „QM-Freigabe Fertigware": die Lager-App gibt eine Charge frei (quarantaene -> frei) und meldet das hier,
// damit Produktionsbericht + zusammenfassendes CoA für den Kunden entstehen (fertigware_qm_freigabe_nach). Token wie api_einlager.
if (($_GET['p'] ?? '') === 'api_qm_freigabe') {
    header('Content-Type: application/json; charset=utf-8');
    $soll  = trim((string) meta_get('einlager_api_token', ''));
    $token = (string)($_POST['token'] ?? '');
    if ($soll === '' || !hash_equals($soll, $token)) { http_response_code(401); echo json_encode(['ok'=>false, 'meldung'=>'Token ungültig.']); exit; }
    $cid = (int)($_POST['charge_id'] ?? 0);
    if ($cid <= 0) { http_response_code(400); echo json_encode(['ok'=>false, 'meldung'=>'charge_id fehlt.']); exit; }
    // Soll-Kette 5: die Werk-App meldet frisch produzierte (sofort freie) Fertigware; Bericht nur, wenn die ganze Menge da ist.
    $r = fertigware_qm_freigabe_nach($cid, mb_substr(trim((string)($_POST['wer'] ?? '')), 0, 150), (string)($_POST['bericht'] ?? '1') === '1',
                                     ($_POST['anlass'] ?? '') === 'produktion' ? 'Produktionsabschluss' : 'QM-Freigabe');
    echo json_encode(['ok'=>$r['fehler'] === '', 'bericht'=>$r['bericht'], 'coa'=>$r['coa'], 'meldung'=>$r['fehler']]);
    exit;
}

// Loopback-API „Machbar frisch“ (13.11.2026, Werk-App Teil 1): die Werk-App fragt hier den Machbar-Stand aller offenen PAs ab.
// Gerechnet wird mit DERSELBEN Funktion wie der Plan (produktion_machbar_schritte, speichert auch pa_machbar) – nie ein alter Stand.
if (($_GET['p'] ?? '') === 'api_pa_machbar') {
    header('Content-Type: application/json; charset=utf-8');
    $soll  = trim((string) meta_get('einlager_api_token', ''));
    $token = (string)($_POST['token'] ?? '');
    if ($soll === '' || !hash_equals($soll, $token)) { http_response_code(401); echo json_encode(['ok'=>false, 'meldung'=>'Token ungültig.']); exit; }
    try {
        require_once BX_ROOT . '/core/produktion_plan.php';
        $out = []; $fehler = 0;
        foreach (all("SELECT id FROM produktionsauftrag WHERE status IN ('offen','laufend')") as $r) {
            try {   // Fable-5 T2.5: ein Fehler bei einem Auftrag kippt die anderen nicht (der fehlt dann und nutzt seinen gespeicherten Stand)
                $m = produktion_machbar_schritte((int)$r['id'], true);
                $out[(int)$r['id']] = ['machbar' => (int)$m['machbar'], 'stufe' => (string)$m['stufe'], 'grund' => (string)$m['grund'], 'etikett_gesperrt' => (int)$m['etikett_gesperrt']];
            } catch (\Throwable $e) { $fehler++; error_log('api_pa_machbar PA ' . (int)$r['id'] . ': ' . $e->getMessage()); }
        }
        echo json_encode(['ok' => true, 'pa' => $out, 'fehler' => $fehler]);
    } catch (\Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'meldung' => $e->getMessage()]); }
    exit;
}

// Soll-Kette 7: Loopback-API „Versand“ für die Lager-App – das Lager legt Sendungen an, die Buchung macht das Dashboard
// (Fertigware des Auftrags FEFO ausbuchen, Lieferschein-Beleg, Status, Rechnung bei Lieferung, Kundenmail) bzw. bucht ins Lager 2 um.
if (($_GET['p'] ?? '') === 'api_versand') {
    header('Content-Type: application/json; charset=utf-8');
    $soll  = trim((string) meta_get('einlager_api_token', ''));
    $token = (string)($_POST['token'] ?? '');
    if ($soll === '' || !hash_equals($soll, $token)) { http_response_code(401); echo json_encode(['ok'=>false, 'msg'=>'Token ungültig.']); exit; }
    $aid = (int)($_POST['auftrag_id'] ?? 0);
    $akt = (string)($_POST['aktion'] ?? '');
    // Sofortfix 11.11.2026: welche dieser Aufträge sind WIRKLICH versandbereit (alle Mengen + Labor da)? Eine Rechnung für
    // Dashboard, Kundenportal und Lager-App (auftrag_status_kunde -> auftrag_fortschritt_fertig).
    if ($akt === 'fertig') {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))), fn($x) => $x > 0));
        $fertig = [];
        foreach (array_slice($ids, 0, 500) as $i) { $row = one("SELECT * FROM auftrag WHERE id=?", [$i]); if ($row && auftrag_status_kunde($row) === 'erledigt') $fertig[] = $i; }
        echo json_encode(['ok' => true, 'fertig' => $fertig]); exit;
    }
    if ($aid <= 0) { echo json_encode(['ok'=>false, 'msg'=>'auftrag_id fehlt.']); exit; }
    if ($akt === 'versenden') {
        $m = (int)($_POST['menge'] ?? 0);
        $r = auftrag_versenden_menge($aid, $m > 0 ? $m : null);
        $r['ls_nummer'] = !empty($r['ls_id']) ? (string) scalar("SELECT nummer FROM beleg WHERE id=?", [(int)$r['ls_id']]) : '';
    } elseif ($akt === 'fremdlager') {
        $r = auftrag_ins_fremdlager($aid);
    } else $r = ['ok'=>false, 'msg'=>'Unbekannte Aktion.'];
    echo json_encode($r, JSON_UNESCAPED_UNICODE); exit;
}

// Router: Whitelist Seite -> Modul-Datei. Kein direkter Dateizugriff moeglich.
$routes = [
    'login'       => 'auth/login.php',
    'dashboard' => 'intern/dashboard.php',
    'kunden'      => 'kunde/liste.php',
    'kunde'       => 'kunde/detail.php',
    'kunde_ki'    => 'kunde/ki_anlegen.php',   // Neuer Kunde aus eingefügtem Text (KI) -> Formular vorbefüllen
    'lieferanten'    => 'lieferant/liste.php',
    'lief_anfragen'  => 'einkauf/lief_anfragen.php', // Übersicht Lieferanten-Anfragen + abgegebene Preise
    'lief_anfrage_detail' => 'einkauf/lief_anfrage_detail.php', // EINE Lieferanten-Anfrage (RFQ) im Detail + Rückfragen
    'lief_nachrichten' => 'einkauf/lief_nachrichten.php', // Team-Übersicht aller Lieferanten-Chats (ungelesen zuerst, Suche)
    'lieferant'      => 'lieferant/detail.php',
    'katalog_freigaben' => 'einkauf/katalog_freigaben.php',
    // Lieferantenportal (eigener Zugang, kein interner Bereich)
    'lieferant_login'        => 'lieferant/login.php',
    'lieferant_einladung'    => 'lieferant/einladung.php',
    'lieferant_bewerbung'    => 'lieferant/bewerbung.php',   // oeffentliche Selbst-Bewerbung (Landing Page)
    'lieferant_portal'       => 'lieferant/portal.php',
    'lieferant_onboarding'   => 'lieferant/onboarding.php',   // Pflicht-Erstlogin-Assistent (Stammdaten/Adresse)
    'lieferant_bestellung'   => 'lieferant/bestellung.php',
    'lieferant_bestellung_pdf'=> 'lieferant/bestellung_pdf.php',
    'lieferant_preisliste'   => 'lieferant/preisliste.php',
    'lieferant_rezepturpreise'=> 'lieferant/rezepturpreise.php',
    'lieferant_rezeptur'     => 'lieferant/rezeptur_ansicht.php',
    'lieferant_rohstoff_info'=> 'lieferant/rohstoff_info.php',
    'lieferant_anfrage'      => 'lieferant/anfrage.php',
    'lieferant_profil'       => 'lieferant/profil.php',
    'lieferant_logo'         => 'lieferant/logo.php',
    'lieferant_nachrichten'  => 'lieferant/nachrichten.php',
    'lieferant_dateien'      => 'lieferant/dateien.php',
    'lieferant_unterlagen'   => 'lieferant/unterlagen.php',   // Paket 12.11.2026 Teil 4: Aufgabenliste fehlende Unterlagen
    'lieferant_katalog'      => 'lieferant/katalog.php',
    'lieferant_hilfe'        => 'lieferant/hilfe.php',
    'lieferant_ueber'        => 'lieferant/ueber_uns.php',   // Portal „Über uns" (wer ist bulkify, Vision)
    'lieferant_dokument'     => 'lieferant/dokument.php',
    'partner'        => 'partner/liste.php',
    'partner_detail' => 'partner/detail.php',
    'rohstoffe'      => 'lager/rohstoffe_liste.php',
    'rohstoff_website' => 'lager/rohstoff_website.php', // Massen-Freigabe öffentliche Rohstoff-DB (Website)
    'rohstoff'       => 'lager/rohstoff_detail.php',
    'rohstoff_steckbrief' => 'lager/rohstoff_steckbrief.php',   // KI-Steckbrief erstellen/bearbeiten (nur intern, POST)
    'artikel_zusammenfuehren' => 'lager/artikel_zusammenfuehren.php',   // Dubletten zusammenführen (Einkauf gerade ziehen, Teil 5)
    'rohstoff_split' => 'lager/rohstoff_split.php',   // lange Namen (Varianten) aufschluesseln
    'freigaben'      => 'lager/freigaben.php',       // offene Kundenfreigaben (Spec/CoA)
    'rohstoffe_papiere' => 'lager/rohstoffe_ohne_papiere.php', // Rohstoffe in angebotenen Rezepturen ohne Papiere (CoA/Spec)
    'lieferant_preise'=> 'einkauf/lieferant_preise.php', // strukturierte Lieferantenpreise (Rohstoff/Fertigprodukt)
    'lief_preisliste'=> 'lager/lief_preisliste.php', // EK-Preisliste (Referenz aus v3)
    'spec_pdf'       => 'lager/spec_download.php',
    'spec_bulkify'   => 'lager/spec_bulkify.php',   // unsere Spezifikation (bulkify-Layout)
    'coa_bulkify'    => 'lager/spec_bulkify.php',   // unser Analysenzertifikat zur Charge
    'dokument'       => 'lager/dokument_download.php',
    'laboranalysen'  => 'lager/laboranalysen.php',   // Laborberichte/CoA hochladen + mit Produkt verknuepfen (KI-Vorschlag)
    'labor_senden'   => 'lager/labor_senden.php',    // Proben ans Labor: Auftragsformular des Labors (PDF) automatisch ausfuellen
    'naehrstoffe'    => 'lager/naehrstoffe_liste.php',
    'naehrstoff'     => 'lager/naehrstoff_detail.php',
    'verpackungen'   => 'lager/verpackungen_liste.php',
    'verpackung'     => 'lager/verpackung_detail.php',
    'verpackung_dok' => 'lager/verpackung_dok_download.php',
    'rezeptur'        => 'rezeptur/liste.php',
    'rezeptur_detail' => 'rezeptur/detail.php',
    'rezeptur_umbau'  => 'rezeptur/umbau.php',        // Worklist: importierte Rezepturen mit nicht gematchten Rohstoffen überarbeiten
    'rezeptur_dubletten' => 'rezeptur/dubletten.php', // Report: inhaltlich identische Rezepturen (zum Verknüpfen über Stammrezeptur)
    'produktbuilder'  => 'system/produktbuilder.php', // KI-Produktbuilder (Wunsch -> Rezeptur, exakter Dosis-Rechner)
    'einkauf_preise'  => 'einkauf/preise.php',       // ALLE Einkaufspreise (Fremdfertigung/Rohstoff/Verpackung/Zukauf)
    'rezeptur_popup'  => 'rezeptur/popup.php',        // schlanke read-only Rezeptur-Ansicht fuer Popups (iframe)
    'rezept_preise'   => 'rezeptur/lief_preise.php', // Alt: leitet auf einkauf_preise&tab=fremd weiter
    'kapsel_referenz' => 'system/kapsel_referenz.php',   // Nachschlagewerk Kapselgrößen
    'd3k2_rechner'    => 'system/d3k2_rechner.php',      // Werkzeug: D3K2-Tropfen-Rechner (Schnellberechnung, reine Rechenseite)
    'crmdemo'         => 'crmdemo/app.php',               // isolierte CRM-Demo (Lieferanten-Beta), versteckt
    'produkte'        => 'produkt/liste.php',
    'produkt'         => 'produkt/detail.php',
    'produkt_pib'     => 'produkt/pib.php',          // Produktinformationsblatt (PIB) ansehen (Auto oder hochgeladen)
    'rezeptur_pib'    => 'rezeptur/pib.php',        // PIB direkt aus einer Rezeptur (ohne Produkt)
    'novelfood'       => 'produkt/novelfood.php',   // Schnell-Nachschlage: ist ein Stoff Novel Food?
    'novelfood_import'=> 'produkt/novelfood_import.php',   // Katalog aktualisieren (Upload + Diff + EU-Direktabgleich)
    'novelfood_verlauf'=> 'produkt/novelfood_verlauf.php', // Aktualisierungs-Verlauf (was ist neu/geändert, je Lauf)
    'novelfood_export'=> 'produkt/novelfood_export.php',   // Öffentlicher JSON-Export des Katalogs (für die Website)
    'angebote'        => 'angebot/liste.php',
    'angebot'         => 'angebot/detail.php',
    'dienstleistungen'=> 'dienstleistung/liste.php',   // Service-Katalog (Dienstleistungen-Modul)
    'dienstleistung'  => 'dienstleistung/detail.php',  // Dienstleistung anlegen/bearbeiten
    'dl_angebote'     => 'dienstleistung/angebote.php',  // DL-Angebote (DA-)
    'dl_angebot'      => 'angebot/detail.php',   // DL-Angebote laufen über die reguläre Angebotsmaske (Dienstleistung + Produkte in EINEM Angebot)
    'dl_auftraege'    => 'dienstleistung/auftraege.php', // DL-Aufträge (DB-)
    'dl_auftrag'      => 'dienstleistung/auftrag.php',
    'dl_rechnungen'   => 'dienstleistung/rechnungen.php', // DL-Rechnungen (DR-)
    'rechnungen_ansicht' => 'intern/belege_ansicht.php',  // Nur-Lese-Rechnungsliste im Dashboard (Buchhaltung bleibt abgeschottet)
    'angebot_pdf'     => 'angebot/pdf.php',
    'vertrag_pdf'     => 'angebot/vertrag_pdf.php',   // Jahresabnahmevertrag (PDF) zum Angebot
    'auftraege'       => 'auftrag/liste.php',
    'kontingente'     => 'kontingent/liste.php',
    'kontingent'      => 'kontingent/detail.php',   // Jahresvertrag-Detail (Zusammensetzung + Rechnungs-Vorschau + Abrufe)
    'auftrag'         => 'auftrag/detail.php',
    'auftrag_pdf'     => 'auftrag/pdf.php',   // Auftragsbestätigung als PDF (Download/Druck)
    'lieferschein_pdf'=> 'auftrag/lieferschein_pdf.php',   // Lieferschein (LS) als PDF (Druck/Download)
    'auftrag_preise'  => 'auftrag/preisliste.php',
    'kundenpreise'    => 'produkt/kundenpreise.php',   // zentrale Kundenpreis-Liste: Herstellungspreis je Kunde x Produkt x Menge je Packung   // Arbeitsliste: Aufträge ohne Preis -> Rechnung-Upload füllt Preis (aufgeschlüsselt)
    // Buchhaltung/Belege sind ein EIGENES Programm unter /buchhaltung/ (public/buchhaltung/index.php).
    // Hier bewusst KEINE Finanz-Routen mehr – Links zeigen auf /buchhaltung/?p=… (siehe BUCHHALTUNG.md).
    'fasttrack'       => 'produktion/fasttrack.php',   // Schnelles Nachtragen: Auftrag-Status + Datum + Sprach-Notiz
    'portal'          => 'portal/kunde.php',
    'portal_login'    => 'portal/login.php',   // Kunden-Login (E-Mail + Passwort)
    'portal_registrieren' => 'portal/registrieren.php',   // Kunden-Selbstregistrierung (gesperrt bis Freigabe)
    'portal_dok'     => 'portal/dokument_download.php',
    'werk'               => 'intern/werk_cockpit.php',
    'aufgaben'           => 'intern/aufgaben.php',
    'aufgabe'            => 'intern/aufgabe_detail.php',
    'produktion'         => 'produktion/liste.php',
    'produktion_neu'     => 'produktion/neu.php',       // Produktion (neu): neue Übersicht im Aufbau, ersetzt später die Liste
    'produktionsauftrag' => 'produktion/detail.php',
    'produktionsauftrag_pdf' => 'produktion/pdf.php',   // Laufzettel fürs Werk (Druck/Download)
    'chargenprotokoll'   => 'produktion/chargenprotokoll.php',   // Chargenprotokoll je Unter-Charge (PDF, 11.11.2026)
    'produktion_run'     => 'produktion/run.php',
    'produktion_bericht' => 'produktion/bericht.php',
    'kalender'           => 'produktion/kalender.php',
    'produktion_planung' => 'produktion/planung.php',   // Termin (geplant_am) je Auftrag im Sammel-Setzen
    'produktion_vorbereitung' => 'produktion/vorbereitung.php',
    'weg_festlegen'      => 'auftrag/weg_festlegen.php',   // Pop-up „Weg festlegen“ (Einkauf gerade ziehen, Teil 6)   // Vor-Produktion / Freigabe (PreProduktionsauftrag)
    'easy_production'    => 'system/easy_production.php',   // Schnelle Produktionserfassung + Altauftrag-Nachbuchung (Teil 3 Block J)
    'warenart_pruefung'  => 'system/warenart_pruefung.php', // Bulkware vs. Fertigprodukt prüfen+korrigieren (Teil 3 Block K)
    'nachbestellungen'   => 'system/nachbestellungen.php', // Eingang einfacher Portal-Nachbestellungen (Preisprüfung + Freigabe)
    'lager'              => 'lager/bestand_liste.php',
    'lager2'             => 'lager/lager2.php',
    'betriebsmittel'     => 'lager/betriebsmittel_detail.php',
    'wareneingang'       => 'lager/wareneingang.php',
    'chargen'            => 'lager/chargen.php',
    'produktionschargen' => 'charge/liste.php',
    'produktionscharge'  => 'charge/detail.php',
    'versand'            => 'versand/liste.php',
    'einstellungen'      => 'system/einstellungen.php',
    'protokoll'          => 'system/protokoll.php',   // Admin-Protokoll: wer hat wann was gemacht
    'datenpruefung'      => 'system/datenpruefung.php', // Admin: read-only Datenprüfungen + Beheben je Zeile
    'wellgreen_abgleich' => 'system/wellgreen_abgleich.php', // Admin: Abgleich Wellgreen gegen Maggis Liste (12.11.2026)
    'test_abzug'         => 'system/test_abzug.php',    // nur Testumgebung (Admin): Datenstand live -> test holen (Paket 12.11.2026)
    'durchlaufzeiten'    => 'system/durchlaufzeiten.php', // Durchlaufzeiten-Tracker: Dauer je Meilenstein (Rezepturanfrage -> Produktion fertig)
    'benachrichtigungen' => 'system/benachrichtigungen.php', // Team-Postfach (als gelesen markieren)
    'apps'               => 'system/apps.php',                // Apps öffnen/installieren (13.11.2026)
    'maschinen'          => 'system/maschinen.php',    // Maschinenfuhrpark (Spec 9.3) – Dashboard-Verwaltung (pr_maschine/pr_raum)
    'menu_editor'        => 'system/menu_editor.php',   // Menü selbst anpassen (Drag & Drop), global
    'angebotsscan'       => 'system/angebotsscan.php',  // KI liest Angebote (Fremd/Alt) ein: Rezepturen + Preise erfassen (kundenunabhängig)
    'angebot_import'     => 'system/angebot_import.php',// KI liest ein Alt-Angebot -> einem bestehenden Angebot zuordnen (anreichern) ODER neues Angebot anlegen; Glas aus Kapselgröße inferieren
    'lager2_import'      => 'lager/lager2_import.php',  // Lager-2 Anfangsbestände: Produkte eines Fulfillment-Kunden aus der Auftragshistorie, Bestände in einem Rutsch buchen (mit Vorschau)
    'suche'              => 'system/suche.php',        // globale Suche (Admin) über alle Bereiche
    'db_import'          => 'system/db_import.php',   // einmalige DB-Übernahme (Admin) – Rohstoffe & Co. auf einen anderen Stand bringen
    'v3_import_upload'   => 'system/v3_import_upload.php',   // TEMPORÄR (v3-Migration): v3-Dump hochladen -> nach v4 importieren
    'alt_rechnungen'     => 'system/alt_rechnungen.php',     // TEMPORÄR: originale Rechnungen/Dokumente zu alten Auftraegen nachtragen
    'v3_dok_import'      => 'system/v3_dok_import.php',      // TEMPORÄR: v3-Dokumente (Dateien) nach v4 uebernehmen
    'testdaten'          => 'system/testdaten.php',         // Testdaten: Produktions-Durchspiel-Set anlegen/loeschen (online, Marker-isoliert) + Lager fuellen (lokal)
    'labor_bereinigung'  => 'system/labor_bereinigung.php', // Einmal-Bereinigung: Laboranalyse-Altlasten (Alt-Upsell) auf Auftrag buchen + Alt-Angebot zurueckziehen
    'dl_split_bereinigung' => 'system/dl_split_bereinigung.php', // Einmal-Bereinigung: faelschlich gesplittete Material-DL-Auftraege (Beutel/Glas/Etikett) entfernen
    'anfragen'           => 'anfrage/liste.php',
    'rohstoff_unterlagen' => 'anfrage/rohstoff_unterlagen.php',
    'rohstoff_erfassen'   => 'anfrage/rohstoff_erfassen.php',
    'anfrage'            => 'anfrage/detail.php',
    'portal_anfragen'    => 'intern/portal_anfragen.php',
    'portal_anfrage'     => 'intern/portal_anfrage_detail.php',
    'einkauf'            => 'einkauf/liste.php',
    'einkauf_mobil'      => 'einkauf/mobil.php',
    'kein_dokument'      => 'einkauf/kein_dokument.php',   // Sofortfix 13.11.2026: „kein Dokument nötig“ am Artikel setzen (Pop-up, POST)
    'bestellung'         => 'einkauf/detail.php',
    'bestellung_pop'     => 'einkauf/bestellung_pop.php',   // Pop-up an der Bestellung: Dokumente, Versand, Sendung, Notiz (13.11.2026)
    'bestellung_pdf'     => 'einkauf/pdf.php',
    'preis_anfragen'     => 'einkauf/preis_anfragen.php',
    'ek_import'          => 'einkauf/ek_import.php',   // eingelesene EK-Preislisten (CSV) + Zuordnung
    'dok_massenimport'   => 'system/dok_massenimport.php', // Specs/CoAs (PDF) massenweise -> Rohstoffe zuordnen
    'ek_lieferanten'     => 'einkauf/ek_lieferanten.php',   // Text-Lieferanten der EK-Preise zuordnen/anlegen
    'bedarf'             => 'einkauf/bedarf.php',
    'einkaufsliste'      => 'einkauf/einkaufsliste.php',
    'benutzer'           => 'system/benutzer_liste.php',
    'benutzer_detail'    => 'system/benutzer_detail.php',
    // Hintergrundarbeit der KI (kein Login, dafuer Schluessel) - siehe core/ki_job.php
    'ki_job'              => 'system/ki_job.php',
    'app'                => 'system/app.php',        // bulkify aufs Handy legen
    'fastaction'         => 'system/fastaction.php', // KI-Schnell-Posteingang (Nachricht -> Aufgabe + Vorschläge)
];

$p = isset($_GET['p']) ? preg_replace('/[^a-z0-9_]/', '', $_GET['p']) : 'dashboard';

// Produktion: EIN Weg (Nico 11.11.2026, plan-produktion-gefuehrt.md). Alte Seiten leiten weiter, nichts ist gelöscht:
// Liste = ?p=produktion (Reiter Plan/Läuft/Fertig), Detail = ?p=produktionsauftrag, Nachtragen = Knopf auf der PA-Seite.
if (in_array($p, ['produktion_neu', 'produktion_planung', 'kalender', 'produktion_run', 'easy_production'], true)) {
    $umPa = (int)($_GET['id'] ?? $_GET['pa'] ?? 0);
    $ziel = match ($p) {
        'produktion_neu'  => '?p=produktion',
        'produktion_run'  => $umPa ? '?p=produktionsauftrag&id=' . $umPa : '?p=produktion&tab=laufend',
        'easy_production' => $umPa ? '?p=produktionsauftrag&id=' . $umPa . '&nachtragen=1#nachtragen' : '?p=produktion&tab=laufend',
        default           => '?p=produktion&tab=plan',
    };
    header('Location: ' . $ziel); exit;
}

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
$PUBLIC = ['login', 'portal', 'portal_login', 'portal_registrieren', 'portal_dok', 'lieferant_login', 'lieferant_einladung', 'lieferant_bewerbung', 'ki_job', 'novelfood_export'];   // portal_dok prüft Token + Freigabe selbst; novelfood_export = öffentlicher JSON-Katalog

// Nicht angemeldet -> zur Login-Seite (außer öffentliche Routen)
if (!in_array($p, $PUBLIC, true) && !is_logged_in()) { header('Location: ?p=' . (!empty($_SESSION['zugang_gesperrt']) ? 'lieferant_login' : 'login')); exit; }   // gesperrter Lieferant -> Hinweis auf dem Lieferanten-Login

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
$LIEF_ROUTEN  = ['lieferant_portal', 'lieferant_onboarding', 'lieferant_bestellung', 'lieferant_bestellung_pdf', 'lieferant_anfrage', 'lieferant_profil', 'lieferant_logo',
                 'lieferant_nachrichten', 'lieferant_dateien', 'lieferant_dokument', 'lieferant_katalog', 'lieferant_preisliste', 'lieferant_rezepturpreise', 'lieferant_rezeptur', 'lieferant_rohstoff_info', 'lieferant_hilfe', 'lieferant_ueber', 'logout'];
if ($istLieferant && !in_array($p, $LIEF_ROUTEN, true) && !in_array($p, ['lieferant_login','lieferant_einladung'], true)) {
    header('Location: ?p=lieferant_portal'); exit;
}
// Pflicht-Onboarding: ein neuer Lieferant muss zuerst den Erstlogin-Assistenten (Stammdaten/Adresse) abschließen.
// Nur echte Lieferanten (nicht die Admin-Vorschau), alles außer dem Assistenten selbst + Logout wird umgeleitet.
if ($istLieferant) {
    $lfOnb = (int) scalar("SELECT onboarding_fertig FROM lieferanten WHERE id=?", [aktueller_lieferant_id()]);
    if ($lfOnb !== 1 && !in_array($p, ['lieferant_onboarding', 'logout'], true)) { header('Location: ?p=lieferant_onboarding'); exit; }
    if ($lfOnb === 1 && $p === 'lieferant_onboarding') { header('Location: ?p=lieferant_portal'); exit; }
}

// Bereits angemeldet und ruft Login auf -> ins passende Dashboard
if ($p === 'login' && is_logged_in()) { header('Location: ?p=' . ($istLieferant ? 'lieferant_portal' : ($istWerk ? 'werk' : 'dashboard'))); exit; }

// Buchhaltung/Belege sind ein eigenes Programm (/buchhaltung/): alte Finanz-Routen dorthin umleiten
// (Bookmarks/alte Links behalten so ihr Ziel, inkl. id/auftrag/tab/art).
$FINANZ_ROUTEN = ['buchhaltung','rechnungen','rechnung','rechnung_neu','rechnung_frei','rechnung_import',
                  'auftrag_import','gutschrift_neu','gutschrift_pdf','rechnung_pdf','rechnung_xml',
                  'beleg_export','lief_rechnung_neu','lief_rechnung'];
if (in_array($p, $FINANZ_ROUTEN, true)) {
    $qs = $_GET; unset($qs['p']);
    header('Location: /buchhaltung/?p=' . $p . ($qs ? '&' . http_build_query($qs) : '')); exit;
}

if (!isset($routes[$p])) $p = is_logged_in() ? ($istLieferant ? 'lieferant_portal' : ($istWerk ? 'werk' : 'dashboard')) : 'login';

// Menü-Umschalter „Einfach / Ausführlich" (Soll-Handbuch Abschnitt 3): Knopf unten in der Seitenleiste postet auf die
// aktuelle Seite; hier speichern wir am Nutzer und laden dieselbe Seite neu (GET). Ändert keine Rechte, nur die Anzeige.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['bx_menu_modus']) && is_logged_in()) {
    $mm = $_POST['bx_menu_modus'] === 'ausfuehrlich' ? 'ausfuehrlich' : 'einfach';
    q("UPDATE benutzer SET menu_modus=? WHERE id=?", [$mm, (int) current_user()['id']]);
    header('Location: ' . ((string)($_SERVER['REQUEST_URI'] ?? '') ?: '?p=dashboard')); exit;
}

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

// Zentrales Änderungsprotokoll (Feld-Ebene): beim Speichern den Hauptdatensatz vorher/nachher vergleichen (Aufräumen 8).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && function_exists('aenderung_beobachten'))
    aenderung_beobachten($p, (int)($_GET['id'] ?? $_POST['id'] ?? 0));

$file = BX_ROOT . '/module/' . $routes[$p];
if (!is_file($file)) { http_response_code(404); echo 'Seite nicht gefunden'; exit; }

require $file;
