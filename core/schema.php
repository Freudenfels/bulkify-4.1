<?php
// Schema + additive Migrationen bulkify 4.1
// Regel: erst CREATE (frisch installierbar), dann additive Migrationen via ensure_column().
// Nie Spalten loeschen. Alles UTF-8 (utf8mb4).
require_once __DIR__ . '/db.php';

// Master-/Test-Scan: in der Produktion überspringt dieser Code Charge-Prüfung und Bestandsabbuchung
// und lässt zum nächsten Schritt durch (Durchklicken/Testen ohne echte Chargen).
if (!defined('BX_MASTER_SCAN')) define('BX_MASTER_SCAN', '888888888');
// Robust: JEDE reine 8er-Folge ab 6 Stellen zählt als Master-Scan (damit man sich nicht verzählt).
function ist_master_scan(?string $s): bool {
    $s = trim((string)$s);
    return $s !== '' && preg_match('/^8{6,}$/', $s) === 1;
}

function table_exists(string $t): bool {
    return (bool) scalar(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?",
        [DB_NAME, $t]
    );
}
function column_exists(string $t, string $c): bool {
    return (bool) scalar(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?",
        [DB_NAME, $t, $c]
    );
}
function ensure_column(string $t, string $c, string $definition): void {
    if (table_exists($t) && !column_exists($t, $c)) {
        db()->exec("ALTER TABLE `$t` ADD COLUMN `$c` $definition");
    }
}
// Index anlegen, falls er fehlt (MySQL kennt kein CREATE INDEX IF NOT EXISTS -> selbst prüfen).
function ensure_index(string $t, string $name, string $cols): void {
    if (!table_exists($t)) return;
    $da = (int) scalar("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=? AND table_name=? AND index_name=?", [DB_NAME, $t, $name]);
    if ($da === 0) { try { db()->exec("ALTER TABLE `$t` ADD INDEX `$name` ($cols)"); } catch (\Throwable $e) {} }
}

function init_schema(): void {
    $pdo = db();

    // app_meta: zentrale Schluessel/Wert-Einstellungen (eine Quelle)
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_meta (
        k VARCHAR(100) PRIMARY KEY,
        v TEXT NULL,
        aktualisiert DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Schnell-Pfad: Die Migrationen unten (~67 CREATE TABLE IF NOT EXISTS + ~165 ensure_column
    // à 2 information_schema-Abfragen + Backfills) sind idempotent, aber teuer – besonders auf einer
    // entfernten DB. Sie müssen nur nach einer Schema-Änderung laufen. Marker = mtime dieser Datei:
    // Nach jedem Deploy (Datei neu geschrieben) ändert sie sich -> Migrationen laufen genau einmal,
    // danach überspringt jeder Request den ganzen Block und macht nur EINE meta_get-Abfrage.
    // Zusaetzliche Schema-Dateien (eigene Module) fliessen mit ein, damit deren Aenderungen die
    // Migration ebenfalls genau einmal ausloesen (sonst liefe dienstleistung_schema() nie neu).
    $schemaBuild = (string) ((int) @filemtime(__FILE__) + (int) @filemtime(__DIR__ . '/dienstleistung.php'));
    if ($schemaBuild !== '' && meta_get('schema_build', '') === $schemaBuild) return;

    // users: interne Mitarbeiter + Portal-Logins (Rolle steuert die Sicht)
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        name VARCHAR(190) NULL,
        pass_hash VARCHAR(255) NULL,
        rolle VARCHAR(40) NOT NULL DEFAULT 'team',
        aktiv TINYINT(1) NOT NULL DEFAULT 1,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // kunden: EIN Kunden-Stamm. Lebenszyklus lead->aktiv im Feld status (CRM = Sicht, kein zweiter Topf).
    // Portal-Schalter kommen spaeter gebuendelt in einen eigenen Reiter, NICHT hier in die Stammdaten.
    $pdo->exec("CREATE TABLE IF NOT EXISTS kunden (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kundennummer VARCHAR(40) NULL,
        firma VARCHAR(190) NOT NULL,
        ansprechpartner VARCHAR(190) NULL,
        email VARCHAR(190) NULL,
        telefon VARCHAR(60) NULL,
        gesperrt TINYINT(1) NOT NULL DEFAULT 0,           -- 0 = aktiv, 1 = gesperrt (Schutzfunktion)
        -- Hauptadresse (strukturiert, wegen DHL-Etiketten)
        strasse VARCHAR(190) NULL,
        hausnummer VARCHAR(20) NULL,
        plz VARCHAR(20) NULL,
        ort VARCHAR(120) NULL,
        land VARCHAR(2) NOT NULL DEFAULT 'DE',
        ust_id VARCHAR(40) NULL,
        -- Rechnungsadresse (nur falls abweichend)
        rechnung_firma VARCHAR(190) NULL,
        rechnung_strasse VARCHAR(190) NULL,
        rechnung_hausnummer VARCHAR(20) NULL,
        rechnung_plz VARCHAR(20) NULL,
        rechnung_ort VARCHAR(120) NULL,
        rechnung_land VARCHAR(2) NULL,
        -- Lieferadresse (nur falls abweichend)
        liefer_strasse VARCHAR(190) NULL,
        liefer_hausnummer VARCHAR(20) NULL,
        liefer_plz VARCHAR(20) NULL,
        liefer_ort VARCHAR(120) NULL,
        liefer_land VARCHAR(2) NULL,
        zahlungsart VARCHAR(30) NOT NULL DEFAULT 'vorkasse',
        zahlungsziel_tage INT NOT NULL DEFAULT 0,
        rabatt_marge DECIMAL(5,2) NOT NULL DEFAULT 0,     -- % auf die Marge (nicht Endpreis)
        aufschlag_marge DECIMAL(5,2) NOT NULL DEFAULT 0,
        notiz TEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        aktualisiert DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_firma (firma)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // kunde_marke: ein Kunde kann mehrere Marken + Webseiten haben (White-Label). Eigenes Zuhause, kein Freitext.
    $pdo->exec("CREATE TABLE IF NOT EXISTS kunde_marke (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kunde_id INT NOT NULL,
        name VARCHAR(190) NULL,
        webseite VARCHAR(190) NULL,
        sort INT NOT NULL DEFAULT 0,
        KEY idx_kunde (kunde_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // kunde_portal_user: weitere Portal-Zugänge eines Kunden (Mitarbeiter) mit eigener Rolle.
    // rolle: besteller (voller Zugriff, darf verbindlich bestellen) | rezepte (nur Rezepturen ansehen) |
    // lager (nur „Mein Lager"). Der Haupt-Login über kunden.email bleibt der Inhaber (voller Zugriff).
    $pdo->exec("CREATE TABLE IF NOT EXISTS kunde_portal_user (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kunde_id INT NOT NULL,
        name VARCHAR(190) NOT NULL,
        email VARCHAR(190) NOT NULL,
        passwort VARCHAR(255) NULL,
        rolle VARCHAR(20) NOT NULL DEFAULT 'besteller',
        aktiv TINYINT(1) NOT NULL DEFAULT 1,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_email (email),
        KEY idx_kunde (kunde_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // aktivitaet: EIN zentrales Protokoll aller Ereignisse – für JEDES Objekt (Kunde, Lieferant, ...).
    // objekt_typ+objekt_id sagen, wozu der Eintrag gehört; akteur steuert die Chat-Seite.
    // Jedes künftige Modul ruft log_aktivitaet(...) auf -> Verlauf schreibt sich von selbst.
    $pdo->exec("CREATE TABLE IF NOT EXISTS aktivitaet (
        id INT AUTO_INCREMENT PRIMARY KEY,
        objekt_typ VARCHAR(20) NOT NULL,                -- kunde | lieferant | partner ...
        objekt_id INT NOT NULL,
        akteur VARCHAR(10) NOT NULL DEFAULT 'system',   -- team (wir) | system | sonst = Gegenstelle (Kunde/Lieferant)
        typ VARCHAR(40) NULL,                           -- login | rezeptur | angebot | bestellung | notiz ...
        text VARCHAR(500) NOT NULL,
        ref_typ VARCHAR(40) NULL,                       -- verknüpftes Objekt (später klickbar)
        ref_id INT NULL,
        erstellt DATETIME NOT NULL,                     -- UTC (via gmdate gesetzt)
        KEY idx_objekt (objekt_typ, objekt_id, erstellt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // lieferant_katalog: was ein Lieferant anbietet – als VORSCHLAG, bevor daraus ein Artikel wird.
    // Der Lieferant pflegt seine Liste selbst (oder lädt sie hoch, die KI liest sie); das Team
    // entscheidet je Zeile, ob daraus ein Artikel entsteht. Siehe core/lieferant_katalog.php.
    $pdo->exec("CREATE TABLE IF NOT EXISTS lieferant_katalog (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lieferant_id INT NOT NULL,
        dokument_id INT NULL,                              -- aus welcher hochgeladenen Liste
        name VARCHAR(190) NOT NULL,
        name_en VARCHAR(190) NULL,
        name_lat VARCHAR(190) NULL,
        art VARCHAR(20) NOT NULL DEFAULT 'rohstoff',       -- rohstoff | fertigprodukt
        form VARCHAR(20) NULL,                             -- pulver|granulat|fluessig|oel|extrakt|kapsel|tablette|softgel|stick
        cas VARCHAR(30) NULL,
        spezifikation VARCHAR(190) NULL,                   -- z. B. 95 % Curcumin
        herkunft VARCHAR(120) NULL,
        preis DECIMAL(12,4) NULL,
        waehrung VARCHAR(3) NOT NULL DEFAULT 'EUR',
        einheit VARCHAR(20) NULL,
        menge_ab DECIMAL(14,3) NULL,                       -- ab dieser Menge gilt der Preis (MOQ)
        notiz VARCHAR(500) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'neu',         -- neu | uebernommen | abgelehnt
        item_id INT NULL,                                  -- gesetzt, sobald daraus ein Artikel wurde
        angelegt DATETIME NOT NULL,
        entschieden DATETIME NULL,
        KEY idx_lieferant (lieferant_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column('lieferant_katalog', 'name_original', "VARCHAR(190) NULL");   // Name wortgetreu wie beim Lieferanten (Originalsprache, z. B. Chinesisch); name = ins Deutsche übersetzt
    ensure_column('lieferant_katalog', 'ki_json', "TEXT NULL");                  // volles KI-Ergebnis eines hochgeladenen CoA/Spec (Wirkstoffe/Kennwerte) -> beim Anlegen angereichert
    ensure_column('lieferant_katalog', 'bio', "TINYINT(1) NOT NULL DEFAULT 0");  // Lieferant kennzeichnet beim Anlegen, ob der Rohstoff Bio/organisch ist

    // nachricht: Rückfragen zwischen Team und Lieferant (core/nachricht.php). Hängt am Lieferanten,
    // optional zusätzlich an einer Bestellung oder Preisanfrage. Gelesen-Flags je Seite.
    $pdo->exec("CREATE TABLE IF NOT EXISTS nachricht (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lieferant_id INT NOT NULL,
        bezug_typ VARCHAR(20) NULL,                        -- bestellung | lieferant_anfrage
        bezug_id INT NULL,
        akteur VARCHAR(10) NOT NULL DEFAULT 'team',       -- team | lieferant
        autor VARCHAR(190) NULL,
        text TEXT NOT NULL,
        gelesen_team TINYINT(1) NOT NULL DEFAULT 0,
        gelesen_lieferant TINYINT(1) NOT NULL DEFAULT 0,
        erstellt DATETIME NOT NULL,
        KEY idx_lieferant (lieferant_id, id), KEY idx_bezug (bezug_typ, bezug_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // lieferanten: eigener Stamm (getrennt von Kunden). Adresse strukturiert; Sprache/Währung/Kategorien einkaufsrelevant.
    $pdo->exec("CREATE TABLE IF NOT EXISTS lieferanten (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lieferantennummer VARCHAR(40) NULL,
        firma VARCHAR(190) NOT NULL,
        ansprechpartner VARCHAR(190) NULL,
        email VARCHAR(190) NULL,
        telefon VARCHAR(60) NULL,
        gesperrt TINYINT(1) NOT NULL DEFAULT 0,
        sprache VARCHAR(5) NOT NULL DEFAULT 'de',         -- de | en | zh
        kategorien VARCHAR(190) NULL,                     -- CSV: rohstoff,verpackung,verbrauch,maschine,labor,fertigprodukt
        fertig_formen VARCHAR(190) NULL,                  -- CSV bei Fertige Produkte: kapsel,tablette,softgel,stick,pulver,fluessig
        webseite VARCHAR(190) NULL,
        strasse VARCHAR(190) NULL,
        hausnummer VARCHAR(20) NULL,
        plz VARCHAR(20) NULL,
        ort VARCHAR(120) NULL,
        land VARCHAR(2) NOT NULL DEFAULT 'DE',
        ust_id VARCHAR(40) NULL,
        waehrung VARCHAR(3) NOT NULL DEFAULT 'USD',       -- USD (Standard) | EUR | CNY
        zahlungsart VARCHAR(30) NOT NULL DEFAULT 'rechnung',
        zahlungsziel_tage INT NOT NULL DEFAULT 0,
        lieferzeit_tage INT NOT NULL DEFAULT 0,           -- Standard-Lieferzeit
        mindestbestellwert DECIMAL(10,2) NOT NULL DEFAULT 0,
        notiz TEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        aktualisiert DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_firma (firma)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // partner: HYBRID – kauft bei uns ein (Kunden-Seite) UND fertigt für uns (Lieferanten-Seite).
    // Deshalb Konditionen aus beiden Welten. Statt Marken hat der Partner SubKunden (eigene Tabelle).
    $pdo->exec("CREATE TABLE IF NOT EXISTS partner (
        id INT AUTO_INCREMENT PRIMARY KEY,
        partnernummer VARCHAR(40) NULL,
        firma VARCHAR(190) NOT NULL,
        ansprechpartner VARCHAR(190) NULL,
        email VARCHAR(190) NULL,
        telefon VARCHAR(60) NULL,
        gesperrt TINYINT(1) NOT NULL DEFAULT 0,
        sprache VARCHAR(5) NOT NULL DEFAULT 'de',
        webseite VARCHAR(190) NULL,
        strasse VARCHAR(190) NULL,
        hausnummer VARCHAR(20) NULL,
        plz VARCHAR(20) NULL,
        ort VARCHAR(120) NULL,
        land VARCHAR(2) NOT NULL DEFAULT 'DE',
        ust_id VARCHAR(40) NULL,
        -- Als Kunde (kauft bei uns)
        zahlungsart_kunde VARCHAR(30) NOT NULL DEFAULT 'vorkasse',
        zahlungsziel_kunde INT NOT NULL DEFAULT 0,
        rabatt_marge DECIMAL(5,2) NOT NULL DEFAULT 0,
        aufschlag_marge DECIMAL(5,2) NOT NULL DEFAULT 0,
        -- Als Lieferant (fertigt/liefert an uns)
        kategorien VARCHAR(190) NULL,
        fertig_formen VARCHAR(190) NULL,
        waehrung VARCHAR(3) NOT NULL DEFAULT 'EUR',
        zahlungsart_lief VARCHAR(30) NOT NULL DEFAULT 'rechnung',
        zahlungsziel_lief INT NOT NULL DEFAULT 0,
        lieferzeit_tage INT NOT NULL DEFAULT 0,
        mindestbestellwert DECIMAL(10,2) NOT NULL DEFAULT 0,
        notiz TEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        aktualisiert DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_firma (firma)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // partner_subkunde: die Kunden DES Partners (trägt der Partner ein) – später in der Produktion zur Unterscheidung.
    $pdo->exec("CREATE TABLE IF NOT EXISTS partner_subkunde (
        id INT AUTO_INCREMENT PRIMARY KEY,
        partner_id INT NOT NULL,
        name VARCHAR(190) NULL,
        kennung VARCHAR(60) NULL,                         -- kurzes Kürzel für Produktion/Etikett
        sort INT NOT NULL DEFAULT 0,
        KEY idx_partner (partner_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // naehrstoff: zentrale Nährstoff-/Wirkstoff-Referenz. Vorbefüllt mit allen NRV-Nährstoffen (Schnellauswahl),
    // erweiterbar um eigene ohne NRV (z. B. Curcumin). Der Rohstoff verweist per wirkstoff_id hierauf -> Aggregation.
    $pdo->exec("CREATE TABLE IF NOT EXISTS naehrstoff (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        kategorie VARCHAR(20) NOT NULL DEFAULT 'sonstige', -- vitamin | mineral | sonstige
        nrv_wert DECIMAL(12,4) NULL,                       -- NRV je Tag (NULL = keine NRV, z. B. Curcumin)
        einheit VARCHAR(10) NOT NULL DEFAULT 'mg',         -- mg | µg
        ist_nrv TINYINT(1) NOT NULL DEFAULT 0,             -- 1 = offizieller NRV-Nährstoff
        sort INT NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Zukunftssichere Einheiten-Formen (I.E./RE/α-TE/NE …):
    ensure_column('naehrstoff', 'ie_mg', "DECIMAL(18,10) NULL");        // mg dieses Nährstoffs je 1 I.E. (Umrechnung I.E.->Masse); NULL = keine I.E.-Umrechnung
    ensure_column('naehrstoff', 'einheit_anzeige', "VARCHAR(24) NULL"); // Anzeige-/Etiketteinheit (z. B. 'µg RE','mg α-TE','mg NE'); NULL = wie einheit

    // Health Claims (zugelassene Angaben, EU-VO 432/2012) je Naehrstoff. Wortlaut MUSS der offiziellen
    // Liste entsprechen; das Team pflegt/importiert die Texte. Fuers PIB werden sie je enthaltenem Naehrstoff gezeigt.
    $pdo->exec("CREATE TABLE IF NOT EXISTS health_claim (
        id INT AUTO_INCREMENT PRIMARY KEY,
        naehrstoff_id INT NULL,
        stoff VARCHAR(120) NULL,                           -- Klartext-Stoff (Fallback, wenn kein Naehrstoff verknuepft)
        claim TEXT NOT NULL,                               -- zugelassener Wortlaut
        bedingung TEXT NULL,                               -- Bedingung (kann lang sein, EU-Register)
        quelle VARCHAR(80) NOT NULL DEFAULT 'EU 432/2012',
        aktiv TINYINT(1) NOT NULL DEFAULT 1,
        sort INT NOT NULL DEFAULT 0,
        KEY idx_naehr (naehrstoff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // item: EIN Stamm für alle Warenlager-Artikel. kategorie steuert später die Strenge (Charge/Quarantäne).
    // Start-Fokus: Rohstoffe (Zutaten für Rezepturen). Nimmt später Verpackung/Verbrauch/Fertigware auf.
    $pdo->exec("CREATE TABLE IF NOT EXISTS item (
        id INT AUTO_INCREMENT PRIMARY KEY,
        artikelnummer VARCHAR(40) NULL,
        name VARCHAR(190) NOT NULL,
        name_en VARCHAR(190) NULL,
        name_lat VARCHAR(190) NULL,                       -- botanischer/lateinischer Name
        kategorie VARCHAR(20) NOT NULL DEFAULT 'rohstoff', -- rohstoff|verpackung|verbrauch|fertig|verkaufsfertig|maschine
        form VARCHAR(20) NOT NULL DEFAULT 'pulver',       -- pulver|granulat|fluessig|oel|paste|kristallin (Auswahl!)
        -- Wirkstoffe liegen in item_wirkstoff (mehrere je Rohstoff möglich)
        dichte DECIMAL(6,3) NULL,                          -- Schüttdichte g/ml (für Kapselfüllung)
        allergene VARCHAR(255) NULL,
        herkunft VARCHAR(120) NULL,
        overage_prozent DECIMAL(6,2) NOT NULL DEFAULT 0,  -- Standard-Overage/Verlust % beim Einsatz
        -- Verpackungs-Felder (nur bei kategorie=verpackung)
        verpackungsart VARCHAR(30) NULL,                  -- dose|flasche|blister|beutel|stick|karton|etikett
        material VARCHAR(60) NULL,                         -- z. B. PET, Braunglas, HDPE, Alu, Karton
        volumen_ml DECIMAL(10,2) NULL,                    -- Fassungsvermögen in ml
        farbe VARCHAR(60) NULL,
        einheit VARCHAR(20) NOT NULL DEFAULT 'kg',        -- Basiseinheit
        ek_preis DECIMAL(12,4) NOT NULL DEFAULT 0,        -- EK je preis_bezug
        preis_bezug VARCHAR(20) NOT NULL DEFAULT 'kg',    -- kg|Stück|L
        haupt_lieferant_id INT NULL,                      -- bevorzugter Lieferant (FK lieferanten)
        gesperrt TINYINT(1) NOT NULL DEFAULT 0,
        notiz TEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        aktualisiert DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_kategorie (kategorie),
        KEY idx_lieferant (haupt_lieferant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // item_wirkstoff: die Wirkstoffe eines Rohstoffs (mehrere möglich), je mit Gehalt %. Verweist auf naehrstoff.
    $pdo->exec("CREATE TABLE IF NOT EXISTS item_wirkstoff (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        naehrstoff_id INT NOT NULL,
        gehalt_prozent DECIMAL(6,2) NULL,                 -- Alt: Gehalt in % (m/m). Bleibt für Rückwärtskompat.; neuer Wert steht in gehalt_wert
        sort INT NOT NULL DEFAULT 0,
        KEY idx_item (item_id),
        KEY idx_naehrstoff (naehrstoff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Gehalt zukunftssicher: Wert + Einheit statt starrem %. Einheiten: prozent (% m/m) | ie_g (I.E./g) | ie_kg (I.E./kg) | mg_g (mg/g) | ug_g (µg/g)
    ensure_column('item_wirkstoff', 'gehalt_wert', "DECIMAL(18,6) NULL");
    ensure_column('item_wirkstoff', 'gehalt_einheit', "VARCHAR(12) NOT NULL DEFAULT 'prozent'");
    // Altbestand: bisheriger %-Gehalt einmalig in den generischen Wert übernehmen (idempotent)
    q("UPDATE item_wirkstoff SET gehalt_wert = gehalt_prozent WHERE gehalt_wert IS NULL AND gehalt_prozent IS NOT NULL");
    seed_naehrstoff_ie_faktoren();

    // rezeptur_anfrage: Kundenwunsch für eine Rezeptur (Laiensprache) -> von uns geprüft und in eine Rezeptur übersetzt.
    $pdo->exec("CREATE TABLE IF NOT EXISTS rezeptur_anfrage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nummer VARCHAR(20) NULL,
        kunde_id INT NULL,
        darreichungsform VARCHAR(20) NOT NULL DEFAULT 'kapsel',
        notiz TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'neu',          -- neu|in_bearbeitung|beantwortet|abgelehnt
        rezeptur_id INT NULL,                                -- die daraus erstellte Rezeptur
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        aktualisiert DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_kunde (kunde_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // rezeptur_anfrage_wunsch: eine Wunsch-Zeile (Kundenname + Menge) + unsere Zuordnung (Rohstoff + finale Menge).
    $pdo->exec("CREATE TABLE IF NOT EXISTS rezeptur_anfrage_wunsch (
        id INT AUTO_INCREMENT PRIMARY KEY,
        anfrage_id INT NOT NULL,
        bezeichnung VARCHAR(190) NULL,                       -- Wunschname des Kunden (Laiensprache)
        wunsch_menge VARCHAR(40) NULL,
        einheit VARCHAR(10) NULL,
        notiz VARCHAR(255) NULL,
        item_id INT NULL,                                    -- unsere Zuordnung zum Rohstoff
        menge_final DECIMAL(12,3) NULL,                      -- unsere Menge je Einheit/Portion (mg)
        sort INT NOT NULL DEFAULT 0,
        KEY idx_anfrage (anfrage_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // rezeptur: Kopf einer Formulierung. Mengen der Zutaten gelten PRO EINHEIT (Kapsel/Portion) – Kunde bestimmt Einnahme/Tag.
    $pdo->exec("CREATE TABLE IF NOT EXISTS rezeptur (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nummer VARCHAR(20) NULL,
        name VARCHAR(190) NOT NULL,
        kunde_id INT NULL,
        darreichungsform VARCHAR(20) NOT NULL DEFAULT 'kapsel', -- kapsel|tablette|softgel|stick|pulver|fluessig
        bezug VARCHAR(30) NOT NULL DEFAULT 'einheit',           -- Mengenbezug (pro Einheit)
        status VARCHAR(20) NOT NULL DEFAULT 'entwurf',          -- entwurf|vorschlag|freigegeben|eingefroren
        notiz TEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        aktualisiert DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column('rezeptur', 'ablehnung_grund', "TEXT NULL");   // Kunde lehnt Vorschlag ab (Pflicht-Grund), Team überarbeitet
    ensure_column('rezeptur', 'kapselgroesse_id', "INT NULL");   // gewählte Kapselgröße (nur Kapsel-Form) → vererbt ins Produkt + Packungsrechnung
    ensure_column('rezeptur', 'synonyme', "TEXT NULL");          // frühere/alternative Namen (Kunde benennt um) – intern bekannt, überall mitsuchbar

    // rezeptur_zutat: die Zutaten (Rohstoffe) einer Rezeptur, Menge in mg je Einheit.
    $pdo->exec("CREATE TABLE IF NOT EXISTS rezeptur_zutat (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rezeptur_id INT NOT NULL,
        item_id INT NULL,                                 -- Rohstoff (NULL = Freitext)
        bezeichnung VARCHAR(190) NULL,                    -- Name-Snapshot
        menge_mg DECIMAL(12,3) NOT NULL DEFAULT 0,        -- mg je Einheit
        sort INT NOT NULL DEFAULT 0,
        KEY idx_rezeptur (rezeptur_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // rezeptur_naehrwert: eigene Nährwert-Deklaration je Rezeptur (je Einheit). Normal werden die Werte live
    // aus den Rohstoffen (item_wirkstoff) abgeleitet; existieren hier Zeilen (naehrwerte_fixiert=1), gelten
    // DIESE – als beim Einfrieren festgeschriebener Snapshot (quelle='auto') und/oder manuell korrigiert
    // (quelle='manuell'). Dann verschiebt sich die Deklaration nicht mehr, auch wenn Rohstoffdaten wechseln.
    $pdo->exec("CREATE TABLE IF NOT EXISTS rezeptur_naehrwert (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rezeptur_id INT NOT NULL,
        naehrstoff_id INT NULL,                           -- Verweis naehrstoff (NULL = Freitext)
        name VARCHAR(190) NOT NULL,                       -- Nährstoffname (Snapshot/Anzeige)
        menge_mg DECIMAL(14,5) NOT NULL DEFAULT 0,        -- mg je Einheit
        nrv_wert DECIMAL(12,4) NULL,                      -- NRV-Bezug (Snapshot) für %-Berechnung
        einheit VARCHAR(10) NULL,                         -- 'mg' | 'µg' (wie naehrstoff.einheit)
        ie_mg DECIMAL(14,6) NULL,                         -- I.E.-Faktor (Snapshot)
        einheit_anzeige VARCHAR(20) NULL,                 -- Label (Snapshot)
        quelle VARCHAR(10) NOT NULL DEFAULT 'auto',       -- auto | manuell
        sort INT NOT NULL DEFAULT 0,
        KEY idx_rez (rezeptur_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column('rezeptur', 'naehrwerte_fixiert', "TINYINT(1) NOT NULL DEFAULT 0");  // 1 = Deklaration festgeschrieben (rezeptur_naehrwert gilt statt Live-Ableitung)

    // produkt: SKU = Rezeptur + Verpackung + Kunde. Verbindet die Stammdaten zum verkaufbaren Produkt.
    $pdo->exec("CREATE TABLE IF NOT EXISTS produkt (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nummer VARCHAR(20) NULL,
        name VARCHAR(190) NOT NULL,
        kunde_id INT NULL,
        rezeptur_id INT NULL,
        verpackung_id INT NULL,                            -- item (kategorie=verpackung)
        einheiten_pro_packung INT NOT NULL DEFAULT 0,      -- z. B. 120 Kapseln je Dose
        einnahme_pro_tag DECIMAL(6,2) NOT NULL DEFAULT 1,  -- Verzehrempfehlung (Einheiten/Tag)
        status VARCHAR(20) NOT NULL DEFAULT 'entwurf',     -- entwurf|aktiv|inaktiv
        notiz TEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        aktualisiert DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_kunde (kunde_id), KEY idx_rezeptur (rezeptur_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // angebot: das Team setzt hier die Preise (einzige Preisquelle). Kunde bestätigt eine Staffel.
    $pdo->exec("CREATE TABLE IF NOT EXISTS angebot (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nummer VARCHAR(20) NULL,
        kunde_id INT NULL,
        produkt_id INT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'offen',       -- offen|gesendet|bestaetigt|abgelehnt
        gueltig_bis DATE NULL,
        ablehnung_grund VARCHAR(500) NULL,
        notiz TEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        aktualisiert DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_kunde (kunde_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // angebot_staffel: Mengenstaffeln mit VK je Stück (Packung). bestaetigt = vom Kunden gewählte Staffel.
    $pdo->exec("CREATE TABLE IF NOT EXISTS angebot_staffel (
        id INT AUTO_INCREMENT PRIMARY KEY,
        angebot_id INT NOT NULL,
        menge INT NOT NULL DEFAULT 0,                      -- Anzahl Packungen
        stueck INT NOT NULL DEFAULT 0,                     -- Stück je Packung (Kapseln/Tabletten … bzw. Portionen bei Pulver)
        vk_stueck DECIMAL(12,4) NOT NULL DEFAULT 0,        -- VK je Packung
        bestaetigt TINYINT(1) NOT NULL DEFAULT 0,
        sort INT NOT NULL DEFAULT 0,
        KEY idx_angebot (angebot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column('angebot_staffel', 'stueck', "INT NOT NULL DEFAULT 0");

    // angebot_position: editierbare Belegpositionen (Hybrid) – automatisch erzeugt, überschreibbar.
    // Sind Zeilen vorhanden, haben sie Vorrang vor der automatischen Berechnung (Editor + PDF).
    $pdo->exec("CREATE TABLE IF NOT EXISTS angebot_position (
        id INT AUTO_INCREMENT PRIMARY KEY,
        angebot_id INT NOT NULL,
        sort INT NOT NULL DEFAULT 0,
        artikelnr VARCHAR(40) NULL,
        bezeichnung VARCHAR(255) NOT NULL DEFAULT '',
        beschreibung VARCHAR(1000) NULL,
        menge DECIMAL(12,3) NOT NULL DEFAULT 0,
        einheit VARCHAR(20) NULL,
        preis_cent INT NOT NULL DEFAULT 0,               -- VK je Einheit (Cent)
        ek_cent INT NOT NULL DEFAULT 0,                  -- EK je Einheit (Cent) – nur interne Marge
        mwst_satz DECIMAL(5,2) NOT NULL DEFAULT 0,
        quelle VARCHAR(20) NOT NULL DEFAULT 'manuell',   -- herstellung|verpackung|manuell
        gruppe VARCHAR(2) NULL,                          -- Multiprodukt-Gruppe (A–Z): koppelt Positionen eines Produkts
        KEY idx_angebot (angebot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Beschreibung ggf. verbreitern (falls Tabelle früher mit VARCHAR(255) angelegt wurde) – für die Rezeptur.
    $bl = one("SELECT CHARACTER_MAXIMUM_LENGTH len FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='angebot_position' AND COLUMN_NAME='beschreibung'");
    if ($bl && (int)$bl['len'] < 1000) $pdo->exec("ALTER TABLE angebot_position MODIFY beschreibung VARCHAR(1000) NULL");
    ensure_column('angebot_position', 'gruppe', "VARCHAR(2) NULL");
    // Bei einer Herstellungsposition festhalten, WAS angeboten wurde: Rezeptur x Menge je Packung + Behälter.
    // Nimmt der Kunde das Angebot an, entsteht daraus das Produkt – ohne diese drei Werte wäre nach dem
    // Absenden nicht mehr rekonstruierbar, welche Konfiguration gemeint war.
    ensure_column('angebot_position', 'rezeptur_id', "INT NULL");
    ensure_column('angebot_position', 'stueck', "INT NULL");
    ensure_column('angebot_position', 'verpackung_id', "INT NULL");
    // VK je Einheit in e4 (Euro x 10.000) fuer Sub-Cent-Preise (z. B. Bulk 0,0250 EUR/Stk). preis_cent allein
    // (ganze Cent) rundet 0,025 auf 0,03 -> falsche Summe. preis_e4 NULL = Altwert, dann gilt preis_cent*100.
    ensure_column('angebot_position', 'preis_e4', "INT NULL");

    // angebot_scan: per KI eingelesene (fremde/alte) Angebote – rein zur Erfassung von Rezeptur + Preisen.
    // Kundenunabhängig (System-Werkzeug). Preise werden als JSON-Aufschlüsselung mitgeführt (Herstellung,
    // Behälter, Etikett …). Die angelegte/zugeordnete Rezeptur steht in rezeptur_id.
    $pdo->exec("CREATE TABLE IF NOT EXISTS angebot_scan (
        id INT AUTO_INCREMENT PRIMARY KEY,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        produkt_name VARCHAR(190) NOT NULL DEFAULT '',
        darreichungsform VARCHAR(20) NOT NULL DEFAULT 'kapsel',
        stueck_je_packung INT NULL,
        rezeptur_id INT NULL,
        rezeptur_neu TINYINT(1) NOT NULL DEFAULT 0,
        kunde_id INT NULL,                    -- zugeordneter/angelegter Kunde (aus dem Angebot gelesen)
        kunde_neu TINYINT(1) NOT NULL DEFAULT 0,
        angebot_datum DATE NULL,              -- Datum des Angebots
        vk DECIMAL(12,4) NULL,                -- VK je Packung für diesen Kunden (netto)
        preise_json MEDIUMTEXT NULL,          -- Preis-Aufschlüsselung (Positionen) als JSON
        zutaten_json MEDIUMTEXT NULL,         -- ausgelesene Wirkstoffe als JSON (Beleg/Nachvollzug)
        datei VARCHAR(255) NULL,              -- gespeicherte Datei (data/uploads)
        original_orig VARCHAR(255) NULL,      -- Originaldateiname
        bemerkung VARCHAR(500) NULL,
        angelegt_von INT NULL,
        KEY idx_rezeptur (rezeptur_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column('angebot_scan', 'kunde_id', "INT NULL");
    ensure_column('angebot_scan', 'kunde_neu', "TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column('angebot_scan', 'angebot_datum', "DATE NULL");
    ensure_column('angebot_scan', 'vk', "DECIMAL(12,4) NULL");
    ensure_column('angebot_scan', 'staffeln_json', "MEDIUMTEXT NULL");   // Mengen-Staffeln (Menge->VK) als JSON

    // rezeptur_kundenpreis: welcher Kunde zu welchem Datum welchen Preis für eine Rezeptur hatte.
    // Quelle u. a. der Angebotsscan. So sind auf der Rezeptur die Preise ALLER Kunden sichtbar.
    $pdo->exec("CREATE TABLE IF NOT EXISTS rezeptur_kundenpreis (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rezeptur_id INT NOT NULL,
        kunde_id INT NOT NULL,
        datum DATE NULL,
        vk DECIMAL(12,4) NULL,                -- VK je Packung (netto)
        stueck_je_packung INT NULL,
        menge INT NULL,                       -- Bestellmenge Packungen (falls genannt)
        preise_json MEDIUMTEXT NULL,          -- Preis-Aufschlüsselung als JSON
        quelle VARCHAR(20) NOT NULL DEFAULT 'angebotsscan',
        scan_id INT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_rezeptur (rezeptur_id),
        KEY idx_kunde (kunde_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // auftrag: Auftragsbestätigung (AB-) – entsteht automatisch aus der bestätigten Angebots-Staffel.
    $pdo->exec("CREATE TABLE IF NOT EXISTS auftrag (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nummer VARCHAR(20) NULL,
        angebot_id INT NULL,
        kunde_id INT NULL,
        produkt_id INT NULL,
        menge INT NOT NULL DEFAULT 0,
        vk_stueck DECIMAL(12,4) NOT NULL DEFAULT 0,
        gesamt_netto DECIMAL(14,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'offen',       -- offen|in_produktion|erledigt
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        aktualisiert DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_kunde (kunde_id), KEY idx_angebot (angebot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // beleg: Rechnung/Gutschrift/Lieferschein (typ). RE- entsteht automatisch mit dem Auftrag.
    $pdo->exec("CREATE TABLE IF NOT EXISTS beleg (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nummer VARCHAR(20) NULL,
        typ VARCHAR(20) NOT NULL DEFAULT 'rechnung',        -- rechnung|gutschrift|lieferschein
        auftrag_id INT NULL,
        kunde_id INT NULL,
        netto DECIMAL(14,2) NOT NULL DEFAULT 0,
        ust_prozent DECIMAL(5,2) NOT NULL DEFAULT 0,
        ust_betrag DECIMAL(14,2) NOT NULL DEFAULT 0,
        brutto DECIMAL(14,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'offen',        -- offen|bezahlt|storniert
        datum DATE NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_kunde (kunde_id), KEY idx_auftrag (auftrag_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // beleg_position: Positionen eines Belegs (v. a. Gutschrift/Storno-Rechnung) – wie beim Angebot.
    $pdo->exec("CREATE TABLE IF NOT EXISTS beleg_position (
        id INT AUTO_INCREMENT PRIMARY KEY,
        beleg_id INT NOT NULL,
        sort INT NOT NULL DEFAULT 0,
        artikelnr VARCHAR(60) NULL,
        bezeichnung VARCHAR(255) NOT NULL,
        beschreibung VARCHAR(1000) NULL,
        menge DECIMAL(14,3) NOT NULL DEFAULT 0,
        einheit VARCHAR(20) NULL,
        preis_cent INT NOT NULL DEFAULT 0,          -- Einzelpreis in Cent (bei Gutschrift negativ)
        mwst_satz DECIMAL(5,2) NOT NULL DEFAULT 0,
        KEY idx_beleg (beleg_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column('beleg', 'storno_von_id', "INT NULL");     // Gutschrift/Storno -> Original-Rechnung
    ensure_column('beleg', 'rezeptur_id', "INT NULL");       // direkte Rezeptur-Verknuepfung (Override), z. B. freie Rechnung
    ensure_column('beleg', 'grund', "VARCHAR(255) NULL");    // Grund des Stornos / der Gutschrift
    ensure_column('beleg', 'zahlungsziel_tage', "INT NULL");  // Zahlungsziel in Tagen (Rechnung)
    ensure_column('beleg', 'faellig', "DATE NULL");          // Faelligkeit = datum + zahlungsziel_tage
    ensure_column('beleg', 'leistung_datum', "DATE NULL");   // Leistungs-/Lieferdatum
    ensure_column('beleg', 'text', "TEXT NULL");             // optionaler Rechnungstext/Hinweis
    ensure_column('beleg', 'bearbeiter_id', "INT NULL");     // Bearbeiter (Benutzer-ID) – erscheint auf der Rechnung
    ensure_column('beleg', 'original_datei', "VARCHAR(255) NULL");  // hochgeladene Original-Rechnung (Alt-Import) im Uploads-Ordner
    ensure_column('beleg', 'original_orig', "VARCHAR(255) NULL");   // Original-Dateiname der hochgeladenen Rechnung
    ensure_column('beleg', 'kunde_sichtbar', "TINYINT(1) NOT NULL DEFAULT 0");  // fuer den Kunden im Portal freigegeben?
    // Einmalig: bestehende Belege waren im Portal immer sichtbar -> freigeben, damit nichts verschwindet.
    if (meta_get('beleg_sichtbar_backfill', '') !== '1') {
        q("UPDATE beleg SET kunde_sichtbar=1 WHERE typ IN ('rechnung','gutschrift')");
        meta_set('beleg_sichtbar_backfill', '1');
    }
    ensure_column('auftrag', 'status_datum', "DATE NULL");   // Datum des aktuellen Status (Kunde sieht es); Fast-Track/v3-Style
    ensure_column('auftrag', 'energ_start', "DATE NULL");     // Energetisierung: Startdatum (aus v3); Status laeuft/abgeschlossen wird daraus abgeleitet
    ensure_column('auftrag', 'bezahlt_am', "DATE NULL");          // manuelles „bezahlt am" fuer Alt-Auftraege (altes System, ohne v4-Rechnung)
    ensure_column('auftrag', 'rezeptur_id', "INT NULL");          // direkte Rezeptur-Verknuepfung (Override), falls das Produkt keine hat / kein Produkt
    ensure_column('auftrag', 'bezahlt_betrag', "DECIMAL(14,2) NULL");  // optionaler Betrag fuer Alt-Auftraege (0-Wert-Faelle)

    // guthaben_bewegung: Verbrauch des Kunden-Guthabens (aus Gutschriften) – angerechnet auf Rechnung oder ausgezahlt.
    $pdo->exec("CREATE TABLE IF NOT EXISTS guthaben_bewegung (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kunde_id INT NOT NULL,
        gutschrift_id INT NULL,
        betrag DECIMAL(14,2) NOT NULL DEFAULT 0,        -- verbrauchtes Guthaben (positiv)
        typ VARCHAR(20) NOT NULL DEFAULT 'anrechnung',  -- anrechnung|auszahlung
        ref_beleg_id INT NULL,                          -- angerechnet auf diese Rechnung
        notiz VARCHAR(255) NULL,
        datum DATE NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_kunde (kunde_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // zahlung: einzelne Zahlungseingänge je Beleg. datum = echtes Überweisungsdatum (Valuta), angelegt = Erfassungszeit (UTC).
    $pdo->exec("CREATE TABLE IF NOT EXISTS zahlung (
        id INT AUTO_INCREMENT PRIMARY KEY,
        beleg_id INT NOT NULL,
        betrag DECIMAL(14,2) NOT NULL DEFAULT 0,
        datum DATE NULL,
        konto VARCHAR(40) NULL,
        art VARCHAR(30) NULL,
        notiz VARCHAR(255) NULL,
        akteur VARCHAR(80) NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_beleg (beleg_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // reservierung: Bestand einem Auftrag fest zuteilen (manuell). Reduziert die Netto-Verfügbarkeit für andere Aufträge.
    $pdo->exec("CREATE TABLE IF NOT EXISTS reservierung (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pa_id INT NULL,
        auftrag_id INT NULL,
        item_id INT NOT NULL,
        menge DECIMAL(14,3) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'aktiv',      -- aktiv|verbraucht|storniert
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_item (item_id, status), KEY idx_auftrag (auftrag_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // aufgabe: „Das musst du machen"-Aufgaben für den Werk-Bereich. zugewiesen_an NULL = ganzes Team.
    $pdo->exec("CREATE TABLE IF NOT EXISTS aufgabe (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titel VARCHAR(200) NOT NULL,
        beschreibung TEXT NULL,
        prio TINYINT NOT NULL DEFAULT 2,                  -- 1=Hoch, 2=Normal, 3=Niedrig
        status VARCHAR(20) NOT NULL DEFAULT 'offen',       -- offen|erledigt
        zugewiesen_an INT NULL,                            -- benutzer.id, NULL = Team
        erstellt_von INT NULL,                             -- benutzer.id
        faellig DATE NULL,
        ref_typ VARCHAR(30) NULL,                          -- z. B. produktionsauftrag|auftrag|charge
        ref_id INT NULL,
        erledigt_am DATETIME NULL,
        erledigt_von INT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status), KEY idx_zuw (zugewiesen_an)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Fastaction-Notepad: je Fastaction-Anfrage eine persistente Notiz mit einzelnen abhakbaren ToDo-Items
    // (die Vorschlaege). So geht nach dem Auswerten nichts verloren; man arbeitet die Punkte spaeter ab.
    $pdo->exec("CREATE TABLE IF NOT EXISTS fastaction_notiz (
        id INT AUTO_INCREMENT PRIMARY KEY,
        eingabe TEXT NULL,
        zusammenfassung VARCHAR(255) NULL,
        aufgabe_text VARCHAR(255) NULL,
        dringlichkeit VARCHAR(10) NULL,
        kunde_id INT NULL,
        rezeptur_id INT NULL,
        produkt_id INT NULL,
        datei VARCHAR(255) NULL,
        datei_orig VARCHAR(255) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'offen',       -- offen|erledigt
        erstellt_von INT NULL,
        erledigt_am DATETIME NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fastaction_item (
        id INT AUTO_INCREMENT PRIMARY KEY,
        notiz_id INT NOT NULL,
        typ VARCHAR(20) NULL,                              -- angebot|anfrage|nachricht|bestellung|produktion|rezeptur|sonstiges
        text VARCHAR(500) NOT NULL,
        erledigt TINYINT(1) NOT NULL DEFAULT 0,
        erledigt_am DATETIME NULL,
        sort INT NOT NULL DEFAULT 0,
        KEY idx_notiz (notiz_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Strukturierte Aktion je ToDo-Punkt: konkretes Produkt/Rezeptur + Menge -> ein-Klick-Aktion (Angebot/Lieferantenpreise).
    ensure_column('fastaction_item', 'rezeptur_id', "INT NULL");
    ensure_column('fastaction_item', 'produkt_id', "INT NULL");
    ensure_column('fastaction_item', 'menge', "DECIMAL(14,2) NULL");
    ensure_column('fastaction_item', 'einheit', "VARCHAR(20) NULL");
    ensure_column('fastaction_item', 'aktion', "VARCHAR(20) NULL");   // angebot|lieferantenpreise|kunde|rezeptur|nachricht|sonstiges

    // beleg_status_log: Statusverlauf je Beleg (wer/wann/welcher Status) – wichtig für Zahlungsnachweis.
    $pdo->exec("CREATE TABLE IF NOT EXISTS beleg_status_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        beleg_id INT NOT NULL,
        status VARCHAR(30) NOT NULL,
        notiz VARCHAR(255) NULL,
        akteur VARCHAR(80) NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_beleg (beleg_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // produktionsauftrag (PR-): entsteht automatisch mit dem Auftrag; läuft über feste Stationen/Gates.
    $pdo->exec("CREATE TABLE IF NOT EXISTS produktionsauftrag (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nummer VARCHAR(20) NULL,
        auftrag_id INT NULL,
        kunde_id INT NULL,
        produkt_id INT NULL,
        menge INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'offen',       -- offen|laufend|erledigt
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        aktualisiert DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_auftrag (auftrag_id), KEY idx_kunde (kunde_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column('produktionsauftrag', 'prio', "TINYINT NOT NULL DEFAULT 2");   // 1=Hoch, 2=Normal, 3=Niedrig
    ensure_column('produktionsauftrag', 'geplant_am', "DATE NULL");               // Baustein 2: geplantes Produktionsdatum
    ensure_column('produktionsauftrag', 'produktionsart', "VARCHAR(10) NOT NULL DEFAULT 'fremd'");   // eigen|fremd (Make-or-Buy); Standard = fremd (90% der Kapseln extern gefüllt)
    // Eigen/Fremd wird im Backend FESTGELEGT, bevor der Auftrag in die Produktion geht. NULL = noch offen
    // -> erscheint NICHT im Produktions-Arbeitsplatz. Entscheidung trifft Admin/Backend, nicht die Produktion.
    ensure_column('produktionsauftrag', 'art_festgelegt_am', "DATETIME NULL");
    // Produktionsplanung: zugeteilter Mitarbeiter (FK benutzer) – wer den Auftrag produziert.
    ensure_column('produktionsauftrag', 'mitarbeiter_id', "INT NULL");
    ensure_column('produktionsauftrag', 'bedarf_gemeldet', "DATETIME NULL");      // wann der Bedarf ans Einkauf gemeldet wurde
    ensure_column('produktionsauftrag', 'rezeptur_id', "INT NULL");               // Bulk-Produktion (nur Kapseln, ohne Verpackung): PA haengt an der Rezeptur statt am Produkt (produkt_id NULL)
    // PreProduktionsauftrag (Vor-Produktion): Kundenaufträge entstehen künftig im Status 'vorbereitung'.
    // Erst wenn der Admin im Dashboard freigibt (Glas/Eigen-Fremd/Etikett/Kartons/Rohstoffe/Gläser geprüft),
    // wird daraus ein echter, startbarer Produktionsauftrag ('offen'). Im Produktionsmodul ist der Vor-PA
    // sichtbar, aber GESPERRT (nicht startbar). Die Freigabe ist die harte Weiche – der Admin kann IMMER freigeben.
    ensure_column('produktionsauftrag', 'freigegeben_am', "DATETIME NULL");       // Vor-Produktion -> Produktion freigegeben am (UTC)
    ensure_column('produktionsauftrag', 'freigegeben_von', "VARCHAR(190) NULL");  // wer freigegeben hat
    // Geplante Produktionsmenge in EINHEITEN/Stück (Kapseln). NULL = genau der Auftragsbedarf. Höher = Überproduktion;
    // der Überschuss wird als Bestand auf den Rezeptur-Bulk gebucht und beim nächsten Auftrag gleicher Rezeptur verrechnet.
    ensure_column('produktionsauftrag', 'menge_produktion', "INT NULL");
    ensure_column('produktionsauftrag', 'mhd', "DATE NULL");                       // selbst festgelegtes MHD der Fertigware (gilt beim Einbuchen der Charge)

    // produktion_schritt: die Stationen/Gates eines Produktionsauftrags, der Reihe nach abzuarbeiten.
    $pdo->exec("CREATE TABLE IF NOT EXISTS produktion_schritt (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pa_id INT NOT NULL,
        station VARCHAR(80) NOT NULL,
        sort INT NOT NULL DEFAULT 0,
        erledigt TINYINT(1) NOT NULL DEFAULT 0,
        erledigt_at DATETIME NULL,
        KEY idx_pa (pa_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column('produktion_schritt', 'scan_charge', "VARCHAR(60) NULL");   // Baustein 6: gescannte Charge
    ensure_column('produktion_schritt', 'erledigt_von', "VARCHAR(190) NULL");  // wer den Schritt abgeschlossen hat (Produktionsbericht)
    // Produktionsbericht: freigebbar/editierbar fuer den Kunden.
    ensure_column('produktionsauftrag', 'bericht_notiz', "TEXT NULL");                    // Bemerkung, die der Kunde im Bericht sieht
    ensure_column('produktionsauftrag', 'bericht_freigegeben_am', "DATETIME NULL");       // fuer Kunden freigegeben am
    ensure_column('produktionsauftrag', 'bericht_freigegeben_von', "VARCHAR(190) NULL");  // durch wen freigegeben

    // produktion_verbrauch: welche Charge in welcher Menge für einen Produktionsauftrag entnommen wurde (Rückverfolgung).
    $pdo->exec("CREATE TABLE IF NOT EXISTS produktion_verbrauch (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pa_id INT NOT NULL,
        item_id INT NOT NULL,
        charge_id INT NULL,
        menge DECIMAL(14,3) NOT NULL DEFAULT 0,
        einheit VARCHAR(20) NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pa (pa_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // lieferant_preis: Staffelpreise je Rohstoff und Lieferant (aus Preisanfragen). Basis für den günstigsten EK.
    $pdo->exec("CREATE TABLE IF NOT EXISTS lieferant_preis (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        lieferant_id INT NOT NULL,
        menge_ab DECIMAL(14,3) NOT NULL DEFAULT 0,          -- Staffel: ab dieser Menge
        preis DECIMAL(12,4) NOT NULL DEFAULT 0,             -- Preis je Einheit
        waehrung VARCHAR(3) NOT NULL DEFAULT 'EUR',
        stand DATE NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_item (item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Lieferantenpreise koennen auch aus dem EK-Import stammen, wo der Lieferant nur als Rohname
    // (z. B. "Maggi", "Vitaactives") vorliegt und keinem lieferanten-Datensatz entspricht. Daher
    // Lieferant optional + Rohname als Text, und Herkunft/Bezug festhalten.
    ensure_column('lieferant_preis', 'lieferant_name', "VARCHAR(120) NULL");
    ensure_column('lieferant_preis', 'quelle', "VARCHAR(40) NULL");        // z. B. 'ek_import'
    ensure_column('lieferant_preis', 'ek_import_id', "INT NULL");          // Rueckverweis (idempotent)
    // Ein Preis ist nur mit Lieferbedingung vergleichbar: Incoterm (EXW/FOB/CIF/DAP/DDP - wer zahlt
    // Fracht/Zoll) und Versandart (Luft/See/Bahn/... - Preis UND Lieferzeit). Beide je Preiszeile.
    ensure_column('lieferant_preis', 'incoterm', "VARCHAR(8) NULL");
    ensure_column('lieferant_preis', 'versandart', "VARCHAR(20) NULL");
    try { $pdo->exec("ALTER TABLE lieferant_preis MODIFY lieferant_id INT NULL"); } catch (\Throwable $e) {}

    // produkt_lieferant_preis: Zukauf-Preise je Fertigprodukt (mehrere Lieferanten/Versandwege).
    // Analog zu lieferant_preis (Rohstoff), aber produktzentriert. Rein INTERN (Zukauf, nie Kundensicht).
    $pdo->exec("CREATE TABLE IF NOT EXISTS produkt_lieferant_preis (
        id INT AUTO_INCREMENT PRIMARY KEY,
        produkt_id INT NOT NULL,
        lieferant_id INT NULL,
        lieferant_name VARCHAR(120) NULL,      -- falls kein Lieferanten-Datensatz (Rohname aus EK-Import)
        menge_ab DECIMAL(14,3) NOT NULL DEFAULT 0,   -- Staffel: ab dieser Stueckzahl
        preis DECIMAL(14,6) NOT NULL DEFAULT 0,      -- Preis je Einheit (z. B. je Kapsel)
        einheit VARCHAR(16) NOT NULL DEFAULT 'kapsel',
        groesse VARCHAR(60) NULL,              -- z. B. #0, #00, tablette
        waehrung VARCHAR(3) NOT NULL DEFAULT 'EUR',
        incoterm VARCHAR(8) NULL,
        versandart VARCHAR(20) NULL,
        stand DATE NULL,
        quelle VARCHAR(40) NULL,               -- z. B. 'ek_import'
        ek_import_id INT NULL,                 -- Rueckverweis (idempotent)
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_produkt (produkt_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // kontingent: Rahmenvertrag/Jahresvertrag – Kunde ruft aus einer vereinbarten Gesamtmenge zum
    // Festpreis Teilmengen ab (jeder Abruf erzeugt einen Auftrag; abgerufen steigt, Rest sinkt).
    $pdo->exec("CREATE TABLE IF NOT EXISTS kontingent (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kunde_id INT NOT NULL,
        produkt_id INT NOT NULL,
        gesamt_menge INT NOT NULL DEFAULT 0,          -- vereinbarte Menge (in Packungen = Auftragseinheit)
        abgerufen INT NOT NULL DEFAULT 0,             -- Summe der bisher abgerufenen Mengen
        vk_stueck DECIMAL(12,4) NOT NULL DEFAULT 0,   -- vereinbarter VK je Packung
        gueltig_von DATE NULL,
        gueltig_bis DATE NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'aktiv',  -- aktiv | beendet
        notiz TEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_kunde (kunde_id), KEY idx_produkt (produkt_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ek_import: Staging fuer eingelesene EK-Preislisten (CSV) – Rohstoff-/Bulk-EK je kg und
    // Fertigprodukt-Kapselpreise, jeweils mit Lieferant. Rohnamen aus der CSV; die Zuordnung zu
    // konkreten v4-Rohstoffen (item_id) bzw. Produkten (produkt_id) passiert nachgelagert
    // (manuell oder KI-gestuetzt auf beta). Bestaetigte Rohstoff-Zeilen werden als lieferant_preis
    // uebernommen; Fertigprodukt-Zeilen sind die interne Fertigprodukt-Preisliste (nie Kundensicht).
    $pdo->exec("CREATE TABLE IF NOT EXISTS ek_import (
        id INT AUTO_INCREMENT PRIMARY KEY,
        typ VARCHAR(16) NOT NULL DEFAULT 'rohstoff',        -- rohstoff | fertigprodukt
        name VARCHAR(255) NOT NULL,                         -- Rohname aus der CSV
        formulierung TEXT NULL,
        groesse VARCHAR(60) NULL,                           -- #0, #00, kg, softgel, tablette ...
        lieferant VARCHAR(120) NULL,                        -- Roh-Lieferantenname aus der CSV
        preis DECIMAL(14,6) NOT NULL DEFAULT 0,             -- EUR/kg (rohstoff) bzw. EUR/Kapsel (fertig)
        einheit VARCHAR(10) NOT NULL DEFAULT 'kg',          -- kg | kapsel | stk | tablette
        menge DECIMAL(14,3) NULL,                           -- Stueckzahl bzw. kg aus der CSV
        item_id INT NULL,                                   -- Match: v4-Rohstoff
        produkt_id INT NULL,                                -- Match: v4-Produkt
        lieferant_id INT NULL,                              -- Match: v4-Lieferant
        status VARCHAR(16) NOT NULL DEFAULT 'offen',        -- offen | bestaetigt | verworfen
        ki_score TINYINT NULL,                              -- 0..100 KI-Zuversicht der Zuordnung
        ki_hinweis VARCHAR(255) NULL,
        quelle VARCHAR(80) NULL,                            -- CSV-Datei
        notiz VARCHAR(500) NULL,                            -- z. B. Marktplatz-Link (statt als Lieferant)
        zeile_hash CHAR(32) NOT NULL,                       -- Idempotenz
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_hash (zeile_hash),
        KEY idx_typ (typ), KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column('ek_import', 'notiz', "VARCHAR(500) NULL");   // additiv fuer bereits bestehende Tabellen (beta)

    // dok_import_job / dok_import_datei: Massen-Upload von Specs/CoAs. Viele PDFs auf einmal hochladen,
    // die KI liest JEDE Datei (nacheinander im Hintergrund, art='dokimport'), ordnet sie einem vorhandenen
    // Rohstoff zu und merkt sich den Vorschlag. Danach prueft der Mensch die Zuordnung (Match-Vorschau) und
    // uebernimmt mit einem Klick alle bestaetigten Zeilen (Original als internes Dokument + KI-Daten am Rohstoff).
    $pdo->exec("CREATE TABLE IF NOT EXISTS dok_import_job (
        id INT AUTO_INCREMENT PRIMARY KEY,
        status VARCHAR(16) NOT NULL DEFAULT 'offen',       -- offen (KI laeuft) | bereit (Vorschau) | fertig | abgebrochen
        anzahl INT NOT NULL DEFAULT 0,                      -- hochgeladene Dateien
        gelesen INT NOT NULL DEFAULT 0,                     -- davon KI-gelesen (inkl. Fehler)
        erstellt_von INT NULL,
        erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dok_import_datei (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        dateiname VARCHAR(255) NOT NULL,                   -- Original-Dateiname (Anzeige)
        pfad VARCHAR(255) NOT NULL,                         -- gespeicherter Dateiname in data/uploads
        status VARCHAR(16) NOT NULL DEFAULT 'offen',       -- offen | liest | gelesen | fehler | importiert | uebersprungen
        liest_seit DATETIME NULL,                           -- seit wann ein Worker diese Datei liest (Stale-Reclaim)
        dok_hash CHAR(64) NULL,                             -- SHA-256 des Datei-Inhalts (Duplikat-Erkennung)
        typ VARCHAR(10) NULL,                               -- spec | coa | beides | unklar (KI)
        sicherheit VARCHAR(10) NULL,                        -- hoch | mittel | niedrig (KI)
        item_id INT NULL,                                   -- zugeordneter Rohstoff (Vorschlag/bestaetigt)
        quelle VARCHAR(16) NULL,                            -- cas | name | fuzzy | manuell | '' (kein Treffer)
        ki_json LONGTEXT NULL,                              -- vollstaendiges spec_ki_lesen-Ergebnis (Wiederverwendung beim Import)
        fehler VARCHAR(255) NULL,
        erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_job (job_id), KEY idx_status (status), KEY idx_hash (dok_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column('dok_import_datei', 'liest_seit', "DATETIME NULL");   // additiv (beta)
    ensure_column('dok_import_datei', 'dok_hash', "CHAR(64) NULL");     // additiv (beta)

    // lieferant_alias: viele Lieferantennamen aus den Preislisten sind Kontakt-/Agenten-Namen
    // (Maggi, Diane, Amy ...), die in Wahrheit fuer eine Firma stehen (z. B. Maggi = Wellgreen).
    // Alias -> Firma (+ optional Kontakt). Wird auf ek_import angewendet, damit dort die echte Firma steht.
    $pdo->exec("CREATE TABLE IF NOT EXISTS lieferant_alias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        alias VARCHAR(120) NOT NULL,
        firma VARCHAR(120) NOT NULL,
        kontakt VARCHAR(120) NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_alias (alias)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Bekannte Kontakt->Firma-Zuordnungen (vom Team bestaetigt). Additiv per INSERT IGNORE:
    // fehlende werden ergaenzt, bestehende (auch manuell geaenderte) bleiben unberuehrt.
    try {
        foreach ([['Maggi','Wellgreen','Maggi'], ['Diane','Rainwood','Diane']] as $al) {
            $st = $pdo->prepare("INSERT IGNORE INTO lieferant_alias (alias,firma,kontakt) VALUES (?,?,?)");
            $st->execute($al);
        }
    } catch (\Throwable $e) {}

    // db_import_log: Protokoll der Datenuebernahmen (DB-Import). BEWUSST NICHT im mysqldump enthalten
    // (--ignore-table), damit der Verlauf server-lokal bleibt und ein Import ihn nicht ueberschreibt.
    $pdo->exec("CREATE TABLE IF NOT EXISTS db_import_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        db_name VARCHAR(64) NULL,
        dateiname VARCHAR(255) NULL,
        bytes INT NULL,
        stmts INT NULL,
        ok INT NULL,
        fehler INT NULL,
        rohstoffe INT NULL,
        kunden INT NULL,
        produkte INT NULL,
        benutzer VARCHAR(120) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Voller v3-Rohstoffname: der Import kappt name auf 190 Zeichen; die laengsten v3-Namen (mehrere
    // Varianten in einem Feld) sind dadurch abgeschnitten. name_v3 haelt den ungekuerzten Originalnamen
    // fuer das Aufschluesseln (tools/v3_namen_voll.php befuellt es aus der v3-Quelle).
    ensure_column('item', 'name_v3', "TEXT NULL");

    // rohstoff_variante_vorschlag: KI-Vorschlag, einen zu langen Rohstoffnamen (mehrere Varianten in
    // einem Feld) in einzelne Rohstoffe aufzuschluesseln. Der Mensch prueft/editiert vor der Uebernahme.
    $pdo->exec("CREATE TABLE IF NOT EXISTS rohstoff_variante_vorschlag (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        original_name VARCHAR(255) NULL,
        basis VARCHAR(190) NULL,
        varianten_json TEXT NULL,        -- JSON-Array sauberer Variantennamen
        ki_stand DATETIME NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'offen',   -- offen | uebernommen | verworfen
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_item (item_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // bestellung: Einkaufsbestellung beim Lieferanten (BE-). Positionen in bestellung_position.
    $pdo->exec("CREATE TABLE IF NOT EXISTS bestellung (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nummer VARCHAR(20) NULL,
        lieferant_id INT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'offen',        -- offen|bestellt|geliefert
        notiz TEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        aktualisiert DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_lieferant (lieferant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // bestellung_position: eine Bestellzeile (Item + Menge + EK).
    $pdo->exec("CREATE TABLE IF NOT EXISTS bestellung_position (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bestellung_id INT NOT NULL,
        item_id INT NULL,
        menge DECIMAL(14,3) NOT NULL DEFAULT 0,
        ek_preis DECIMAL(12,4) NOT NULL DEFAULT 0,
        einheit VARCHAR(20) NULL,
        sort INT NOT NULL DEFAULT 0,
        KEY idx_bestellung (bestellung_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Bestellung: Ablauf beim Lieferanten. Er bestaetigt mit geplantem Termin und pflegt danach
    // die Stationen; "versendet" verlangt Versandanbieter, Versandart und Sendungsnummer.
    ensure_column('bestellung', 'bestaetigt', "TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column('bestellung', 'bestaetigt_am', "DATETIME NULL");
    ensure_column('bestellung', 'bestaetigt_von', "VARCHAR(190) NULL");
    ensure_column('bestellung', 'eta_geplant', "DATE NULL");            // vom Lieferanten zugesagter Termin
    ensure_column('bestellung', 'produktion_geplant', "DATE NULL");
    ensure_column('bestellung', 'station', "VARCHAR(20) NOT NULL DEFAULT ''");   // '' | angenommen | produktion | qualitaet | versand | versendet
    ensure_column('bestellung', 'versandanbieter', "VARCHAR(60) NULL");
    ensure_column('bestellung', 'versandart', "VARCHAR(40) NULL");      // luft | see | kurier | spedition | post
    ensure_column('bestellung', 'tracking', "VARCHAR(120) NULL");
    ensure_column('bestellung', 'angekommen_am', "DATE NULL");          // tatsaechlicher Wareneingang (Team)
    ensure_column('bestellung', 'pakete_angekuendigt', "INT NULL");     // vom Lieferanten angekuendigte Kartons-/Paketanzahl (falls noch keine Nummern)

    // lieferung_paket: ein Datensatz je Karton/Sendung einer Bestellung. Der Lieferant gibt an, wie
    // viele Pakete er schickt und welche Tracking-/Sendungsnummern dazugehoeren; das Lager hakt sie
    // beim Wareneingang per Scan ab (angekommen). „Anzahl Kartons" = Zeilenanzahl je Bestellung.
    $pdo->exec("CREATE TABLE IF NOT EXISTS lieferung_paket (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        bestellung_id INT          NOT NULL,
        tracking      VARCHAR(80)  NOT NULL,
        spediteur     VARCHAR(40)  NULL,
        angekommen    TINYINT      NOT NULL DEFAULT 0,
        angekommen_am DATETIME     NULL,
        angelegt      DATETIME     NOT NULL,
        UNIQUE KEY uniq_tracking (tracking),
        KEY best (bestellung_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- Lieferantenzugang ---
    // Kein Magic-Link wie beim Kunden: Lieferanten arbeiten laufend im Tool, deshalb ein echter
    // Zugang mit Passwort. Eingeladen wird per Token-Link; das Passwort setzt der Lieferant selbst.
    ensure_column('benutzer', 'lieferant_id', "INT NULL");   // gesetzt = Lieferanten-Login, kein Teamkonto
    // Preisanfrage an einen Lieferanten. Entweder zu einem Lagerartikel (Rohstoff/Verpackung)
    // Analysewerte je Charge – die Grundlage fuer UNSER Analysenzertifikat (CoA) im bulkify-Layout.
    // Die Unterlagen der Vorlieferanten bleiben intern; weitergegeben wird unser eigenes Dokument.
    $pdo->exec("CREATE TABLE IF NOT EXISTS charge_analyse (
        id INT AUTO_INCREMENT PRIMARY KEY,
        charge_id INT NOT NULL,
        parameter VARCHAR(120) NOT NULL,
        spezifikation VARCHAR(120) NULL,                    -- Sollwert laut Spezifikation
        ergebnis VARCHAR(120) NULL,                         -- gemessener Wert der Charge
        methode VARCHAR(120) NULL,
        sort INT NOT NULL DEFAULT 0,
        KEY idx_charge (charge_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // item_grenzwert: dauerhafte Reinheits-/Sicherheits-Grenzwerte AM ROHSTOFF (Schwermetalle, Mikro-
    // biologie, Mykotoxine ...), aus der Spezifikation. Jede neue Charge kann dagegen geprueft werden.
    // Unterschied zu charge_analyse: dort stehen die GEMESSENEN Werte je Charge, hier die SOLL-Grenzwerte.
    $pdo->exec("CREATE TABLE IF NOT EXISTS item_grenzwert (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        parameter VARCHAR(120) NOT NULL,
        grenzwert VARCHAR(120) NULL,                        -- Sollwert/Grenzwert laut Spezifikation (z. B. NMT 3 ppm)
        sort INT NOT NULL DEFAULT 0,
        KEY idx_item (item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // oder als Freitext. Der Lieferant antwortet mit einem Angebot (siehe unten).
    $pdo->exec("CREATE TABLE IF NOT EXISTS lieferant_anfrage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nummer VARCHAR(20) NULL,
        lieferant_id INT NOT NULL,
        item_id INT NULL,                                   -- angefragter Artikel (optional)
        betreff VARCHAR(190) NULL,                          -- Freitext, wenn kein Artikel
        menge DECIMAL(14,3) NULL,                           -- angefragte Menge
        einheit VARCHAR(20) NULL,
        notiz TEXT NULL,
        coa_gewuenscht TINYINT(1) NOT NULL DEFAULT 1,       -- CoA/Spec mitliefern
        status VARCHAR(20) NOT NULL DEFAULT 'offen',        -- offen|beantwortet|geschlossen
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_lieferant (lieferant_id), KEY idx_item (item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Antwort des Lieferanten: Preis je Einheit, optional Mindestmenge und Lieferzeit.
    $pdo->exec("CREATE TABLE IF NOT EXISTS lieferant_angebot (
        id INT AUTO_INCREMENT PRIMARY KEY,
        anfrage_id INT NOT NULL,
        lieferant_id INT NOT NULL,
        preis DECIMAL(12,4) NOT NULL DEFAULT 0,
        einheit VARCHAR(20) NULL,
        waehrung VARCHAR(5) NOT NULL DEFAULT 'EUR',
        mindestmenge DECIMAL(14,3) NULL,
        lieferzeit_tage INT NULL,
        notiz TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'offen',        -- offen|angenommen|abgelehnt
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_anfrage (anfrage_id),
        KEY idx_lieferant (lieferant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Mengenstaffeln zum Angebot – daraus werden beim Annehmen die EK-Staffeln (lieferant_preis).
    $pdo->exec("CREATE TABLE IF NOT EXISTS lieferant_angebot_staffel (
        id INT AUTO_INCREMENT PRIMARY KEY,
        angebot_id INT NOT NULL,
        menge_ab DECIMAL(14,3) NOT NULL DEFAULT 0,
        preis DECIMAL(12,4) NOT NULL DEFAULT 0,
        KEY idx_angebot (angebot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column('lieferanten', 'sprache', "VARCHAR(5) NOT NULL DEFAULT 'de'");
    // Kontaktwege, die im Asiengeschaeft zaehlen – und das Logo des Lieferanten (nur intern).
    ensure_column('lieferanten', 'wechat', "VARCHAR(80) NULL");
    ensure_column('lieferanten', 'whatsapp', "VARCHAR(40) NULL");
    ensure_column('lieferanten', 'logo', "VARCHAR(255) NULL");   // Dateiname in data/uploads
    ensure_column('lieferanten', 'keine_anfragen', "TINYINT(1) NOT NULL DEFAULT 0");  // Onlineshop o. Ä. – keine Preisanfragen senden
    ensure_column('lieferanten', 'shop_login', "VARCHAR(190) NULL");     // gemeinsamer Shop-Login (Benutzer/E-Mail)
    ensure_column('lieferanten', 'shop_passwort', "VARCHAR(190) NULL");  // gemeinsames Shop-Passwort (intern, Team-Zugang)
    ensure_column('lieferanten', 'quelle', "VARCHAR(20) NOT NULL DEFAULT 'manuell'");  // manuell | bewerbung (Lieferant hat sich selbst beworben)
    ensure_column('lieferanten', 'bewerbung_nachricht', "TEXT NULL");    // Freitext aus der Selbst-Bewerbung (was bieten sie an)
    // Bankverbindung des Lieferanten – FORMATOFFEN (nicht IBAN-fix!). Chinesische Lieferanten zahlen oft
    // ueber Banken in Drittlaendern (HK/Singapur): dann SWIFT/BIC + Kontonummer statt IBAN, oft mit
    // Beguenstigtem, Bankadresse und ggf. Zwischen-/Korrespondenzbank. Jedes Feld optional; der Lieferant
    // fuellt, was zutrifft. Die Buchhaltung liest diese Felder spaeter fuer die Zahlung.
    ensure_column('lieferanten', 'bank_inhaber',     "VARCHAR(190) NULL");  // Kontoinhaber / Beguenstigter (kann von Firma abweichen)
    ensure_column('lieferanten', 'bank_name',        "VARCHAR(190) NULL");  // Name der Bank
    ensure_column('lieferanten', 'bank_land',        "VARCHAR(100) NULL");  // Land der Bank (z. B. Hongkong, Singapur)
    ensure_column('lieferanten', 'bank_iban',        "VARCHAR(60) NULL");   // IBAN (EU) – optional
    ensure_column('lieferanten', 'bank_swift',       "VARCHAR(30) NULL");   // SWIFT/BIC – international fuehrend
    ensure_column('lieferanten', 'bank_konto',       "VARCHAR(60) NULL");   // Kontonummer (wenn keine IBAN)
    ensure_column('lieferanten', 'bank_adresse',     "VARCHAR(255) NULL");  // Bankadresse (oft fuer Auslandsueberweisung noetig)
    ensure_column('lieferanten', 'bank_waehrung',    "VARCHAR(10) NULL");   // Zielwaehrung (USD/EUR/CNY …)
    ensure_column('lieferanten', 'bank_zwischenbank',"VARCHAR(255) NULL");  // Zwischen-/Korrespondenzbank (SWIFT + Konto), oft fuer USD/China
    ensure_column('lieferanten', 'bank_notiz',       "VARCHAR(500) NULL");  // Freitext: Routing/ABA, CNAPS, Branch-Code, Verwendungszweck …
    $pdo->exec("CREATE TABLE IF NOT EXISTS lieferant_einladung (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lieferant_id INT NOT NULL,
        token VARCHAR(64) NOT NULL,
        email VARCHAR(190) NULL,
        eingeloest TINYINT(1) NOT NULL DEFAULT 0,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_token (token),
        KEY idx_lieferant (lieferant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // charge: Bestand je Item als Chargen. Kategorie steuert die Strenge (Rohstoff -> Quarantäne, sonst frei).
    $pdo->exec("CREATE TABLE IF NOT EXISTS charge (
        id INT AUTO_INCREMENT PRIMARY KEY,
        charge_nr VARCHAR(60) NULL,                        -- Charge des Lieferanten (oder intern)
        item_id INT NOT NULL,
        menge DECIMAL(14,3) NOT NULL DEFAULT 0,            -- eingegangene Menge
        menge_verfuegbar DECIMAL(14,3) NOT NULL DEFAULT 0, -- verbleibend
        einheit VARCHAR(20) NULL,
        lieferant_id INT NULL,
        mhd DATE NULL,
        wareneingang DATE NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'quarantaene', -- quarantaene|frei|gesperrt|leer
        notiz TEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_item (item_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Baustein 4: Beschaffung ↔ Kundenauftrag verknüpfen (wofür wurde bestellt / eingebucht)
    ensure_column('bestellung_position', 'auftrag_id', "INT NULL");
    ensure_column('charge', 'auftrag_id', "INT NULL");
    ensure_column('charge', 'bestellung_position_id', "INT NULL");
    ensure_column('charge', 'tracking', "TEXT NULL");   // Tracking-Code(s) der eingegangenen Pakete/Palette (Wareneingang, je Zeile einer)
    ensure_column('charge', 'pa_id', "INT NULL");   // Produktionsauftrag der Fertigware-Charge (Rückverfolgung Zusammensetzung)
    ensure_column('charge', 'coa_freigegeben', "TINYINT(1) NOT NULL DEFAULT 0");   // bulkify-CoA dieser Charge fuer den Kunden freigegeben?
    ensure_column('charge', 'coa_freigabe_am', "DATETIME NULL");
    ensure_column('charge', 'coa_freigabe_von', "VARCHAR(190) NULL");
    // Fremdlager: Charge gehoert einem KUNDEN (Fulfillment) und ist NICHT unser Bestand. NULL = Warenlager (uns).
    // Fremdlager-Chargen werden aus item_bestand()/Reservierung/Produktion/Versand ausgeschlossen (Kundenware).
    ensure_column('charge', 'fremd_kunde_id', "INT NULL");
    // Energetisierung je Einlagerung (nur Kunden mit kunden.zeige_energetisierung) – der Kunde sieht es in „Mein Lager".
    ensure_column('charge', 'energetisiert_am', "DATETIME NULL");
    ensure_column('charge', 'energetisiert_von', "VARCHAR(190) NULL");
    // Standort der Ware (Spec 6.2): lager1 (Hauptlager) | produktion (entnommen, in der Fertigung) | lager2 (Fremdlager).
    // Blinker bleibt dran, nur der Standort wechselt. Default lager1. Genutzt von der Lager-/Produktions-Logik.
    ensure_column('charge', 'standort', "VARCHAR(20) NOT NULL DEFAULT 'lager1'");

    // === Produktions-Charge CH/CHE (Spec 7.5 + 16) =========================================================
    // EIGENE, durchsuchbare Entitaet fuer die PRODUKTIONS-/Misch-Charge – getrennt von der Fertigprodukt-Charge
    // (charge.charge_nr .A/.B bleibt wie sie ist!). CH… = intern gemischt/produziert, CHE… = extern zugekaufte
    // Bulkware. Unterchargen je Gebinde/Tag/Mitarbeiter als eigener Datensatz mit parent_id + sub_kennung (-A/-B).
    $pdo->exec("CREATE TABLE IF NOT EXISTS prod_charge (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nummer VARCHAR(40) NOT NULL,                       -- CH-2691 (intern) / CHE-2691 (extern); Untercharge CH-2691-A
        typ VARCHAR(10) NOT NULL DEFAULT 'intern',         -- intern | extern
        parent_id INT NULL,                                -- NULL = Hauptcharge; sonst Untercharge von dieser
        sub_kennung VARCHAR(4) NULL,                       -- -A/-B/-C je Gebinde/Tag/Mitarbeiter (nur Untercharge)
        pa_id INT NULL,                                    -- Produktionsauftrag
        rezeptur_id INT NULL,
        produkt_id INT NULL,
        gebinde VARCHAR(80) NULL,                          -- z. B. Eimer 8 kg oder Sack 25 kg
        menge DECIMAL(14,3) NULL,
        einheit VARCHAR(20) NULL,
        mitarbeiter_id INT NULL,
        maschine_id INT NULL,                              -- Maschine (Spec 9), wenn gekoppelt
        status VARCHAR(30) NOT NULL DEFAULT 'offen',       -- offen|gemischt|verarbeitet|abgefuellt|fertig
        tag DATE NULL,                                     -- Produktionstag (fuer Tageswechsel-Historie)
        notiz TEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        aktualisiert DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_nummer (nummer),
        KEY idx_pa (pa_id), KEY idx_parent (parent_id), KEY idx_rezeptur (rezeptur_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // prod_charge_rohstoff: DAS WICHTIGSTE (Spec 7.5) – jede CH-Charge → ALLE eingesetzten Rohstoff-Batchnummern.
    // Rohstoffe bekommen KEINE eigene Nummer: batch_nr = Hersteller-Batchnummer (= Rohstoff-Probenname, Regress).
    // Rueckverfolgung in beide Richtungen: Charge→Batches und Batch→alle Chargen/Produkte.
    $pdo->exec("CREATE TABLE IF NOT EXISTS prod_charge_rohstoff (
        id INT AUTO_INCREMENT PRIMARY KEY,
        prod_charge_id INT NOT NULL,
        item_id INT NULL,                                  -- Rohstoff (item)
        charge_id INT NULL,                                -- Lager-Charge (falls bekannt)
        batch_nr VARCHAR(80) NULL,                         -- Hersteller-Batchnummer
        menge DECIMAL(14,3) NULL,
        einheit VARCHAR(20) NULL,
        erfasst_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        erfasst_von VARCHAR(190) NULL,
        KEY idx_pc (prod_charge_id), KEY idx_item (item_id), KEY idx_batch (batch_nr)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // CH/CHE-Nummernkreis startet bei 2977 (4 Stellen -> CH-2977; waechst natuerlich auf 5 Stellen bei >=10000).
    // Seed auf frischen Systemen; Korrektur nur solange noch keine echte Charge dieses Typs vergeben wurde
    // (faengt Test-Hochzaehlungen ab, ohne je eine real vergebene Nummer zu ueberschreiben).
    foreach (['CH' => 'intern', 'CHE' => 'extern'] as $pfx => $typ) {
        q("INSERT IGNORE INTO nummernkreis (prefix, naechste, stellen) VALUES (?, 2977, 4)", [$pfx]);
        if ((int) scalar("SELECT COUNT(*) FROM prod_charge WHERE typ=?", [$typ]) === 0)
            q("UPDATE nummernkreis SET naechste=2977, stellen=4 WHERE prefix=? AND naechste<2977", [$pfx]);
    }

    // prod_probe: dreistufige physische Proben/Rueckstellmuster (Spec 8). ebene: rohstoff (pro eingesetztem
    // Rohstoff-Batch) | gebinde (pro Gebinde/Sub-Charge) | endprodukt (Rueckstellmuster) | labor (nur bei
    // Laborpruefung, 2 Stueck). Rueckstell-Mengenregel Endprodukt: max(5, Anzahl Gebinde) – siehe Helfer.
    $pdo->exec("CREATE TABLE IF NOT EXISTS prod_probe (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pa_id INT NULL,
        prod_charge_id INT NULL,
        item_id INT NULL,
        charge_id INT NULL,
        ebene VARCHAR(20) NOT NULL DEFAULT 'endprodukt',   -- rohstoff|gebinde|endprodukt|labor
        batch_nr VARCHAR(80) NULL,                         -- bei ebene=rohstoff: Hersteller-Batchnummer
        anzahl INT NULL,
        bezeichnung VARCHAR(190) NULL,
        etikett_gedruckt TINYINT(1) NOT NULL DEFAULT 0,
        labor VARCHAR(190) NULL,                           -- bei ebene=labor: Ziel-Labor
        versendet_am DATETIME NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        erfasst_von VARCHAR(190) NULL,
        KEY idx_pa (pa_id), KEY idx_pc (prod_charge_id), KEY idx_ebene (ebene)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    ensure_column('bestellung', 'bestelldatum', "DATE NULL");   // „gemeinsam bestellt am"
    ensure_column('bestellung_position', 'bezeichnung', "VARCHAR(200) NULL");   // Freitext (z. B. Bulk-Zukauf ohne Lagerartikel)

    // freibedarf: freier Einkaufsbedarf ohne Produktionsbezug (Kartons, Verbrauchsgüter, Inventar, Maschinen …).
    $pdo->exec("CREATE TABLE IF NOT EXISTS freibedarf (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bezeichnung VARCHAR(200) NOT NULL,
        menge DECIMAL(14,3) NOT NULL DEFAULT 1,
        einheit VARCHAR(20) NULL DEFAULT 'Stück',
        kategorie VARCHAR(20) NULL,                        -- karton|verbrauch|inventar|maschine|sonstiges (optional)
        lieferant_id INT NULL,
        elektrisch TINYINT NOT NULL DEFAULT 0,             -- elektronische Komponente → später Geräteprüfung
        notiz TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'offen',        -- offen|bestellt|erledigt
        bestellung_id INT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column('freibedarf', 'gemeldet_von', "VARCHAR(120) NULL");   // wer den Bedarf gemeldet hat (z. B. Werk-Mitarbeiter)

    // lager2_bewegung: Bewegungs-Ledger für die Fulfillment-Kopplung (Idempotenz je ref+typ).
    $pdo->exec("CREATE TABLE IF NOT EXISTS lager2_bewegung (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        typ VARCHAR(20) NOT NULL,                          -- verbrauch|retoure|defekt
        menge DECIMAL(14,3) NOT NULL DEFAULT 0,
        ref VARCHAR(120) NOT NULL,                         -- z. B. ff:oid:sku / ret:rid:sku
        quelle VARCHAR(20) NOT NULL DEFAULT 'fulfillment',
        notiz TEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ref_typ (ref, typ),
        KEY idx_item (item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // nummernkreis: EIN zentraler Zähler je Präfix (K, L, PA, R, RZ, P, AN, VP, FP, RE, AB ...).
    $pdo->exec("CREATE TABLE IF NOT EXISTS nummernkreis (
        prefix VARCHAR(10) PRIMARY KEY,
        naechste INT NOT NULL DEFAULT 1,
        stellen INT NOT NULL DEFAULT 4
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- additive Migrationen ab hier (Beispielmuster) ---
    ensure_column('kunden', 'zeige_energetisierung', "TINYINT(1) NOT NULL DEFAULT 0");   // Energetisierung im Kundenportal zeigen (Spezialkunde, z. B. Annapurna/Pure Health)
    ensure_column('kunden', 'labortest_extern', "TINYINT(1) NOT NULL DEFAULT 0");        // Externer Labortest (Drittlabor) als paralleler Verlaufs-Punkt im Portal (Spezialkunde, will immer eine Drittlabor-Analyse)
    ensure_column('kunden', 'benachrichtigung_aus', "TINYINT(1) NOT NULL DEFAULT 0");    // 1 = keine E-Mail-Benachrichtigungen an diesen Kunden (z. B. für Nachholbuchungen ohne Kunden-Mails)
    ensure_column('kunden', 'portal_token', "VARCHAR(64) NULL");   // Magic-Link-Zugang zum Kundenportal (Backup/Erstzugang)
    ensure_column('kunden', 'passwort', "VARCHAR(255) NULL");       // Passwort-Hash fuers Kunden-Login (password_hash); leer = noch nicht eingerichtet
    ensure_column('kunden', 'erstlogin_am', "DATETIME NULL");       // Zeitpunkt der Konto-Einrichtung (Erstzugang abgeschlossen)
    ensure_column('kunden', 'letzter_login', "DATETIME NULL");      // letzter erfolgreicher Kunden-Login
    // Standard-Produktionsweg (Ausbaustufen). Am Produkt = Standard; am Kunden = optionaler Override (NULL = erbt vom Produkt).
    // Nur Admin pflegt diese Schalter; sie setzen beim PA-Anlegen den Startzustand (im Produktions-Programm je Auftrag überschreibbar).
    ensure_column('produkt', 'synonyme', "TEXT NULL");          // frühere/alternative Produktnamen (Kunde benennt um) – intern bekannt, überall mitsuchbar
    ensure_column('produkt', 'weg_abfuellen',    "TINYINT(1) NOT NULL DEFAULT 1");   // Abfüllen/Verpacken (Primärgebinde)
    ensure_column('produkt', 'weg_etikettieren', "TINYINT(1) NOT NULL DEFAULT 1");   // Etikettieren
    ensure_column('produkt', 'weg_karton',       "TINYINT(1) NOT NULL DEFAULT 0");   // Umkarton/Umverpackung
    ensure_column('produkt', 'weg_beipack',      "TINYINT(1) NOT NULL DEFAULT 0");   // Beipackzettel beilegen
    ensure_column('kunden',  'weg_abfuellen',    "TINYINT NULL");                     // Override je Kunde (NULL = erbt vom Produkt)
    ensure_column('kunden',  'weg_etikettieren', "TINYINT NULL");
    ensure_column('kunden',  'weg_karton',       "TINYINT NULL");
    ensure_column('kunden',  'weg_beipack',      "TINYINT NULL");
    ensure_column('item', 'produkt_id', "INT NULL");               // Verkaufsfertig-Item <-> Produkt (Fertigware-Bestand)
    ensure_column('item', 'rezeptur_id', "INT NULL");              // Bulk-Item (Kategorie 'fertig') <-> Rezeptur (Bulk-Produktion ohne Verpackung)
    // Dokumente: erst nach ausdrücklicher Freigabe im Kundenportal sichtbar. Standard 0 – ein Lieferanten-Spec
    // darf nicht versehentlich beim Kunden landen, nur weil es am Rohstoff hängt.
    ensure_column('dokument', 'kunde_sichtbar', "TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column('dokument', 'hochgeladen_von', "VARCHAR(10) NOT NULL DEFAULT 'team'");   // team | lieferant (Ablage je Lieferant)
    // Was die KI aus dieser Unterlage gelesen hat – ein VORSCHLAG, bis ihn jemand geprüft hat.
    // Rezepturvorschlag der KI zu einer Kundenanfrage (core/rezeptur_ki.php) – Entwurf, kein Beschluss.
    ensure_column('rezeptur_anfrage', 'ki_daten', "MEDIUMTEXT NULL");
    ensure_column('rezeptur_anfrage', 'ki_stand', "DATETIME NULL");
    ensure_column('dokument', 'ki_daten', "MEDIUMTEXT NULL");
    ensure_column('dokument', 'ki_stand', "DATETIME NULL");
    ensure_column('dokument', 'datei_hash', "VARCHAR(32) NULL");   // md5 der Datei – gleiche Etiketten ueber Auftraege gruppieren
    ensure_column('dokument', 'dok_datum', "DATE NULL");   // Datum DES Dokuments (z. B. Analysendatum auf dem Laborbericht) – fuer Sortierung; leer = angelegt
    ensure_column('dokument', 'charge_nr', "VARCHAR(60) NULL");   // auf dem Bericht genannte Chargennummer (Laboranalyse) – ausgeschrieben im System hinterlegt
    ensure_column('dokument', 'befund', "VARCHAR(20) NULL");   // Laboranalyse-Befund fuer den Kunden: bestanden | auffaellig | unklar (KI-Vorschlag, editierbar)
    ensure_column('dokument', 'v3_ref', "VARCHAR(48) NULL");   // Herkunft aus v3-Dokument-Migration (z. B. dateien:123) – Idempotenz
    // Preisanfrage: was genau angefragt wird. Daraus ergibt sich die Einheit, in der der Lieferant seinen Preis nennt.
    ensure_column('lieferant_anfrage', 'art', "VARCHAR(20) NULL");               // rohstoff|fertigprodukt|verpackung|verbrauch|sonstiges
    ensure_column('lieferant_anfrage', 'form', "VARCHAR(20) NULL");              // bei Fertigprodukt: kapsel|tablette|softgel|stick|pulver|granulat|fluessig
    ensure_column('lieferant_anfrage', 'stueck_je_packung', "INT NULL");         // bei Fertigprodukt: Einheiten je Packung (z. B. 90 Kapseln)
    ensure_column('lieferant_anfrage', 'kapselgroesse_id', "INT NULL");          // bei Kapsel/Softgel: gewünschte Kapselgröße
    ensure_column('lieferant_anfrage', 'rezeptur_id', "INT NULL");               // optional: unsere Rezeptur als Vorlage
    ensure_column('lieferant_anfrage', 'incoterm', "VARCHAR(8) NULL");           // gewünschte Lieferbedingung (Standard DDP)
    ensure_column('lieferant_anfrage', 'versandart', "VARCHAR(20) NULL");        // gewünschte Versandart (Standard Luft)
    ensure_column('lieferant_anfrage', 'menge_staffel', "VARCHAR(190) NULL");    // gewünschte Mengen-Staffel (kommagetrennt) -> Lieferant sieht sie vorausgefüllt
    ensure_column('lieferant_angebot', 'preis_basis', "INT NOT NULL DEFAULT 1");  // Preis gilt je 1 oder je 1000 Einheiten
    ensure_column('lieferant_angebot', 'incoterm', "VARCHAR(8) NULL");            // vom Lieferanten angebotene Lieferbedingung
    ensure_column('lieferant_angebot', 'versandart', "VARCHAR(20) NULL");         // vom Lieferanten angebotene Versandart
    ensure_column('item', 'cas', "VARCHAR(30) NULL");              // CAS-Nummer (z. B. Ascorbinsäure 50-81-7)
    ensure_column('item', 'max_fuellgewicht_g', "DECIMAL(10,2) NULL"); // Verpackung: max. Füllgewicht (g) – für Pulver-Match (Glas/Dose)
    // Verpackungs-Stückliste: Rolle je Verpackungs-Item + Produkt-Slots für die komplette Stückliste
    ensure_column('item', 'verpackung_rolle', "VARCHAR(20) NULL");  // primaer|verschluss|etikett|karton|beipack (nur kategorie=verpackung)
    ensure_column('item', 'etikett_format', "VARCHAR(40) NULL");    // z. B. 100x70 mm (nur Etikett)
    ensure_column('item', 'etikett_druck', "VARCHAR(40) NULL");     // Etikett-Druckdatei-Maß je Behälter (B x H mm, = Endformat + 3mm rundum)
    ensure_column('item', 'etikett_final', "VARCHAR(40) NULL");     // finales Etikettenformat je Behälter (B x H mm)
    // Kundenetikett (pro Produkt, versioniert): der gedruckte Etikett-Lagerartikel eines Produkts. produkt_id
    // bindet ihn ans Produkt; etikett_version = v1/v2/…; etikett_datei_sig/_dokument_id = das Design, das diese
    // Version repräsentiert (für Bump-Erkennung); etikett_vorlage_id = generischer Glas-Etikett (Maß-Vorlage).
    ensure_column('item', 'etikett_version', "INT NULL");
    ensure_column('item', 'etikett_dokument_id', "INT NULL");
    ensure_column('item', 'etikett_datei_sig', "VARCHAR(64) NULL");
    ensure_column('item', 'etikett_vorlage_id', "INT NULL");
    ensure_column('produkt', 'verschluss_id', "INT NULL");          // Stückliste: Verschluss/Deckel
    ensure_column('produkt', 'etikett_id', "INT NULL");             // Stückliste: Etikett
    ensure_column('produkt', 'karton_id', "INT NULL");             // Stückliste: Faltschachtel/Umkarton
    ensure_column('produkt', 'beipack_id', "INT NULL");            // Stückliste: Beipackzettel
    // Leerkapsel (Rohstoff-Untertyp, form=kapselhuelle) – Material/Farbe reuse item.material/item.farbe
    ensure_column('item', 'kapselgroesse_id', "INT NULL");         // Leerkapsel: welche Kapselgröße (FK kapselgroesse)
    ensure_column('item', 'leergewicht_mg', "DECIMAL(8,2) NULL");  // Leerkapsel: Gewicht der leeren Hülle (mg)
    ensure_column('produkt', 'leerkapsel_id', "INT NULL");         // optionale manuelle Wahl der Leerkapsel (sonst automatisch nach Größe)
    // Produkt-Entkopplung: Katalog vs. exklusiv (kunde_id = Besitzer, nur wenn exklusiv)
    ensure_column('produkt', 'exklusiv', "TINYINT(1) NOT NULL DEFAULT 0");
    // Externe Kundenware: Produkt, das der Kunde woanders hat herstellen lassen und wir nur lagern/versenden
    // (keine Rezeptur/Produktion bei uns). Gehört immer dem Kunden (exklusiv=1). Fürs Fremdlager/Fulfillment.
    ensure_column('produkt', 'extern', "TINYINT(1) NOT NULL DEFAULT 0");
    // Portal-Freischaltungen je Kunde (welche Anfrage-Bereiche der Kunde sieht)
    ensure_column('kunden', 'portal_rezeptur', "TINYINT(1) NOT NULL DEFAULT 1");
    ensure_column('kunden', 'portal_produkte', "TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column('kunden', 'portal_rohstoffe', "TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column('kunden', 'portal_dienstleistung', "TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column('kunden', 'portal_rezeptur_ableiten', "TINYINT(1) NOT NULL DEFAULT 0");   // darf Katalog-Produkte/Rezepturen als Basis fuer eine eigene Rezeptur weiterentwickeln
    ensure_column('portal_anfrage', 'verpackung_typ', "VARCHAR(40) NULL");   // Kundenwunsch Verpackungstyp (Glas/PET/…); wir wählen den passenden Behälter
    ensure_column('portal_anfrage', 'fuellmenge_g', "DECIMAL(10,2) NULL");   // Pulver-Anfrage: Füllmenge je Packung (g) statt Stück je Packung
    ensure_column('portal_anfrage', 'wunsch_menge', "DECIMAL(12,3) NULL");   // Rohstoff-Anfrage: gewünschte Menge
    ensure_column('portal_anfrage', 'wunsch_einheit', "VARCHAR(10) NULL");   // Einheit dazu (kg/g/t/Stück/L)
    ensure_column('portal_anfrage', 'rohstoff_id', "INT NULL");              // Rohstoff-Anfrage: konkreter Rohstoff (item) für die Preisberechnung
    // Produktanfrage direkt aus einer REZEPTUR: der Kunde hat seine Rezeptur angenommen, ein Produkt
    // (Rezeptur x Menge + Verpackung) gibt es dafür noch nicht. Genau daraus entsteht es im Angebot.
    ensure_column('portal_anfrage', 'rezeptur_id', "INT NULL");
    ensure_column('portal_anfrage', 'absage_grund', "VARCHAR(500) NULL");   // wenn wir NICHT anbieten koennen: Begruendung fuer den Kunden
    ensure_column('portal_anfrage', 'zielpreis', "DECIMAL(12,2) NULL");     // Rohstoff-Anfrage: gewuenschter Zielpreis (optional)
    ensure_column('portal_anfrage', 'dienstleistung_typ', "VARCHAR(30) NULL"); // Dienstleistungsanfrage: Typ (labortest|abfuellung|sourcing|…)
    ensure_column('portal_anfrage', 'umkarton', "TINYINT(1) NOT NULL DEFAULT 0"); // Kunde wünscht einen Umkarton (nur wenn app_meta portal_umkarton=1); Details in der Notiz
    // Verbindliche Freigabe durch den Kunden: Name gilt als Unterschrift, Zeitpunkt daneben.
    ensure_column('rezeptur', 'freigabe_name', "VARCHAR(190) NULL");
    ensure_column('rezeptur', 'freigabe_am', "DATETIME NULL");
    ensure_column('angebot', 'freigabe_name', "VARCHAR(190) NULL");
    ensure_column('angebot', 'rezeptur_id', "INT NULL");          // direkte Rezeptur-Verknuepfung (Override)
    ensure_column('angebot', 'freigabe_am', "DATETIME NULL");
    ensure_column('rezeptur', 'agb_version', "VARCHAR(40) NULL");   // welche AGB-Fassung bei der Freigabe galt
    ensure_column('angebot', 'agb_version', "VARCHAR(40) NULL");
    // Rezeptur exklusiv (wie beim Produkt): kunde_id = Herkunft/Besitzer. exklusiv=1 -> nur dieser Kunde;
    // exklusiv=0 + freigegeben -> Katalog, fuer ALLE (auch wenn ein Kunde als Herkunft dranhaengt).
    ensure_column('rezeptur', 'exklusiv', "TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column('rezeptur', 'basis_rezeptur_id', "INT NULL");   // abgeleitet: Kunde hat diese Rezeptur aus einer Katalog-Rezeptur/-Produkt weiterentwickelt (intern sichtbar)
    ensure_column('health_claim', 'entry_id', "VARCHAR(120) NULL");   // Entry-Id aus dem EU-Register (idempotenter Import)
    ensure_column('kapselgroesse', 'leergewicht_mg', "DECIMAL(8,2) NULL");   // Leergewicht der Kapselhuelle je Groesse (fuers Gesamtgewicht/Nettofuellmenge)
    ensure_column('kapselgroesse', 'volumen_ml', "DECIMAL(6,3) NULL");        // theoretisches Fuellvolumen je Groesse (ml) – fuer dichteabhaengige Fuellmenge
    ensure_column('kapselgroesse', 'fuell_light_mg', "INT NULL");             // Referenz-Fuellgewicht bei Dichte 0,45 (leicht)
    ensure_column('kapselgroesse', 'fuell_typ_mg', "INT NULL");               // Referenz-Fuellgewicht bei Dichte 0,70 (typisch)
    ensure_column('kapselgroesse', 'fuell_heavy_mg', "INT NULL");             // Referenz-Fuellgewicht bei Dichte 1,00 (dicht)
    // Einmaliger Backfill: bestehende Rezepturen MIT Kunde waren bisher exklusiv (kunde_id = exklusiv).
    // Danach steuert nur noch das Flag - importierte Katalog-Rezepturen bleiben exklusiv=0.
    if (meta_get('rez_exklusiv_backfill', '') !== '1') {
        q("UPDATE rezeptur SET exklusiv=1 WHERE kunde_id IS NOT NULL");
        meta_set('rez_exklusiv_backfill', '1');
    }
    ensure_column('portal_anfrage_pos', 'rezeptur_id', "INT NULL");
    ensure_column('rezeptur_anfrage', 'produktname', "VARCHAR(190) NULL");    // Wunsch-Produktname des Kunden bei der Rezepturanfrage
    ensure_column('produkt', 'kundenname', "VARCHAR(190) NULL");              // vom Kunden gewünschter Produktname (intern = name)
    ensure_column('produkt', 'novelfood_status', "VARCHAR(20) NOT NULL DEFAULT 'unklar'"); // Novel-Food-Konformität: unklar|konform|novel_food|pruefung
    ensure_column('produkt', 'mwst_satz', "DECIMAL(5,2) NULL");   // USt-Satz des Produkts; NULL = Standard (ust_inland, i.d.R. 19 %). Nur Admin ändert ihn.
    ensure_column('produkt', 'haltbarkeit', "VARCHAR(60) NULL");              // Mindesthaltbarkeit (z. B. „24 Monate"), aus Spec
    ensure_column('produkt', 'allergene', "VARCHAR(255) NULL");               // Allergene des Fertigprodukts, aus Spec
    // Angebot als Preismatrix (Kunde wählt Zelle: Stückzahl × Bestellmenge) -> gewählte Werte fließen in Auftrag + Produktion
    ensure_column('auftrag', 'stueck', "INT NULL");
    ensure_column('auftrag', 'verpackung_id', "INT NULL");
    ensure_column('auftrag', 'etikett_item_id', "INT NULL"); // welche Kundenetikett-Version (item) für diesen Auftrag gilt (kundenreine Verbuchung + Rückverfolgung)
    ensure_column('auftrag', 'kontingent_id', "INT NULL");   // Abruf aus einem Rahmenvertrag/Kontingent
    ensure_column('auftrag', 'produkt_bezeichnung', "VARCHAR(190) NULL");  // Namens-SNAPSHOT: friert den (internen) Produktnamen zur Auftragszeit ein -> spaetere Umbenennung aendert Auftraege nicht
    ensure_column('auftrag', 'produkt_form', "VARCHAR(20) NULL");          // Fallback-Darreichungsform dazu (kapsel|tablette|pulver|…)
    // Altbestand einfrieren: wo noch kein Snapshot steht, den AKTUELLEN internen Produktnamen einsetzen.
    // Danach bekommt eine Produkt-Umbenennung laufende/abgeschlossene Auftraege NICHT mehr mit.
    q("UPDATE auftrag a JOIN produkt p ON p.id=a.produkt_id
       SET a.produkt_bezeichnung = p.name
       WHERE a.produkt_id IS NOT NULL AND (a.produkt_bezeichnung IS NULL OR a.produkt_bezeichnung='')");
    ensure_column('auftrag', 'import_ref', "VARCHAR(60) NULL");            // Referenz der importierten Alt-Rechnung/-AB (Dedup beim PDF-Import)
    // Etikettenfreigabe durch den Kunden – PFLICHT je Auftrag, auch bei Nachbestellung mit altem Etikett.
    // Ohne Freigabe: Etiketten nicht bestellbar + Produktion nicht machbar (harte Sperre).
    // Admin-Override „Rohstoff/Bulk angekommen" (für Alt-Aufträge / Zukauf ohne verknüpfte Charge).
    ensure_column('auftrag', 'rohstoff_angekommen_am', "DATETIME NULL");
    // Externer Labortest: Datum, an dem die Produktion die Probe ans Labor versendet hat (Zwischenstand).
    // Geschrieben wird es von der Produktion über die Naht; das Dashboard liest/zeigt es nur.
    ensure_column('auftrag', 'labor_versendet_am', "DATE NULL");
    ensure_column('auftrag', 'etikett_freigegeben', "TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column('auftrag', 'etikett_freigabe_am', "DATETIME NULL");
    ensure_column('auftrag', 'etikett_freigabe_von', "VARCHAR(190) NULL");
    // Einmalige Reparatur: 4 v3-importierte Zukauf-Auftraege (Annapurna) kamen ohne verknuepftes Produkt an.
    // Name/Form aus der v3-Datenbank (board.sqlite) hier fest hinterlegt – gezielt per Auftragsnummer, idempotent.
    if (meta_get('fix_auftrag_produkt_v3', '') !== '1') {
        foreach ([
            ['AB-3213', 'Gerstengrassaftpulver mono', 'pulver'],
            ['AB-3214', 'Beruhigungskomplex (Calm+)', 'kapsel'],
            ['AB-3215', 'Leberkomplex',                'kapsel'],
            ['AB-3217', 'Jod/Kelp',                    'kapsel'],
        ] as [$nr, $name, $form])
            q("UPDATE auftrag SET produkt_bezeichnung=?, produkt_form=? WHERE nummer=? AND (produkt_id IS NULL OR produkt_id=0) AND (produkt_bezeichnung IS NULL OR produkt_bezeichnung='')",
              [$name, $form, $nr]);
        meta_set('fix_auftrag_produkt_v3', '1');
    }
    // Einmalige Preis-Reparatur: alte v3-Importe speicherten bei manchen Bestellpositionen den GESAMTpreis
    // als Stueckpreis (ek_preis) -> Bestellwert (Menge x ek_preis) explodierte (z. B. 231 Mio. EUR). Der Import
    // ist laengst gefixt (Stueckpreis = Gesamt / Menge); diese Migration raeumt bereits importierte Altzeilen auf.
    // NUR eindeutig unmoegliche Faelle (Bestellwert je Position > 1 Mio. EUR – das erreicht hier keine echte
    // Einzelbestellung; in den Daten klafft eine grosse Luecke: naechster echter Wert ~85 Tsd. EUR). Korrektur =
    // ek_preis / Menge -> der Bestellwert wird wieder der urspruengliche, sinnvolle Gesamtpreis. Idempotent.
    if (meta_get('fix_bestellpos_gesamtpreis_v1', '') !== '1') {
        q("UPDATE bestellung_position SET ek_preis = ek_preis / menge
           WHERE menge > 0 AND ek_preis > 0 AND (menge * ek_preis) > 1000000");
        meta_set('fix_bestellpos_gesamtpreis_v1', '1');
    }
    // Einmalig: 4 offene Annapurna-Auftraege ohne verknuepftes Produkt auf Wunsch loeschen
    // (AB-3213/3214/3215/3217 mit PR-2729..2732). Sicherung: nur solange sie wirklich KEIN Produkt haben.
    if (meta_get('del_auftrag_ohne_produkt_v3', '') !== '1') {
        foreach (['AB-3213', 'AB-3214', 'AB-3215', 'AB-3217'] as $nr) {
            $r = one("SELECT id FROM auftrag WHERE nummer=? AND (produkt_id IS NULL OR produkt_id=0)", [$nr]);
            if ($r) auftrag_komplett_loeschen((int)$r['id']);
        }
        meta_set('del_auftrag_ohne_produkt_v3', '1');
    }
    ensure_column('produktionsauftrag', 'stueck', "INT NULL");
    ensure_column('produktionsauftrag', 'verpackung_id', "INT NULL");
    // Einmalig: Alt-Auftraege aus dem v3-Import hatten keinen verknuepften Behaelter (verpackung_id leer),
    // weil v3 "Weithalsglas 150 ml" schrieb und v4 "150 ml Weithalsglas" heisst -> der Namensvergleich im
    // Import schlug fehl. Folge: Produktion zieht kein/falsches Glas und es wird das falsche Etikett bestellt.
    // Fix aus der v3-Wahrheit (auftraege.verpackung = Material + ml), je v3_id fest hinterlegt; Zuordnung ueber
    // Material + Volumen auf den v4-Behaelter (bevorzugt Weithalsglas). Setzt NUR wo leer, keine Ueberschreibung,
    // keine Loeschung. Cascade auf produktionsauftrag (nur wo dort ebenfalls leer). Neu angelegte Produkte/
    // Auftraege verknuepfen den Behaelter bereits korrekt -> das hier betrifft ausschliesslich die Alt-Importe.
    if (meta_get('fix_auftrag_verpackung_v1', '') !== '1') {
        fix_auftrag_verpackung_backfill();
        meta_set('fix_auftrag_verpackung_v1', '1');
    }
    // Einmalig, Ergaenzung zu v1: die RESTLICHEN aktiven Auftraege ohne verpackung_id (nicht in der v3-Zuordnung)
    // aus Rezeptur + Stueck berechnen, damit auch die Produktion die richtige Glasgroesse fuehrt. Nur wenn das
    // Material eindeutig ist (Glas/PET/PLA aus dem Verpackungstext) – sonst bleibt es leer (manuelle Auswahl).
    if (meta_get('fix_auftrag_verpackung_v2', '') !== '1') {
        fix_auftrag_verpackung_v2_berechnet();
        meta_set('fix_auftrag_verpackung_v2', '1');
    }
    ensure_column('angebot', 'kunde_ausgeblendet', "TINYINT(1) NOT NULL DEFAULT 0");  // Kunde hat es aus seiner Liste entfernt (Löschen)
    ensure_column('angebot', 'marge_override', "DECIMAL(6,2) NULL");          // je Angebot gesetzte Marge % (überschreibt Marge-je-Typ; VK = EK×(1+Marge))
    ensure_column('angebot', 'produktionszeit_wochen', "DECIMAL(5,1) NULL");  // je Angebot gesetzte Produktionszeit (Wochen); leer = globaler Wert
    ensure_column('angebot', 'anfrage_id', "INT NULL");                       // Herkunft: portal_anfrage (angefragte Konfiguration fürs Angebots-PDF)
    ensure_column('angebot', 'jahresvertrag', "TINYINT(1) NOT NULL DEFAULT 0"); // Angebot ist ein Jahresabnahmevertrag (Kontingent statt Einzelauftrag)
    ensure_column('angebot', 'jahresmenge', "INT NULL");                       // vereinbarte Jahres-Gesamtmenge (Packungen)
    ensure_column('angebot', 'jahres_vk', "DECIMAL(12,4) NULL");               // Festpreis je Packung im Jahresvertrag
    ensure_column('angebot', 'jahres_laufzeit_monate', "INT NOT NULL DEFAULT 12"); // Laufzeit des Jahresvertrags in Monaten
    // Preis-Freigabe fuer den Kunden: erst wenn =1 sieht der Kunde das Angebot samt Preisen im Portal.
    // Getrennt vom Status, damit ein bereits gesendetes Angebot beim Nachbearbeiten NICHT ungewollt Preise zeigt.
    ensure_column('angebot', 'preise_kunde', "TINYINT(1) NOT NULL DEFAULT 0");
    // Einmalig: bestehende, dem Kunden bereits sichtbare Angebote (gesendet/bestaetigt/abgelehnt) freigeben,
    // damit laufende Angebote durch die neue Freigabe-Logik nicht ploetzlich verschwinden.
    if (meta_get('init_preise_kunde', '') !== '1') {
        q("UPDATE angebot SET preise_kunde=1 WHERE status IN ('gesendet','bestaetigt','abgelehnt')");
        meta_set('init_preise_kunde', '1');
    }
    // Einmalig NACH dem v3-Import: importierte Angebote (gesendet/bestaetigt/abgelehnt) freigeben. Der obere
    // Backfill lief einmalig VOR dem Import und lässt sich nicht wiederholen; die per Import neu angelegten
    // Angebote blieben daher auf preise_kunde=0 und waren für den Kunden unsichtbar (inkl. Staffeln).
    if (meta_get('init_preise_kunde_v3', '') !== '1') {
        // Nur relevant nach v3-Import (Spalte angebot.v3_id). Ohne die Spalte nichts zu tun – verhindert init_schema-Crash.
        if (scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='angebot' AND column_name='v3_id'"))
            q("UPDATE angebot SET preise_kunde=1 WHERE v3_id IS NOT NULL AND status IN ('gesendet','bestaetigt','abgelehnt')");
        meta_set('init_preise_kunde_v3', '1');
    }
    ensure_column('kontingent', 'angebot_id', "INT NULL");                     // Herkunft: aus welchem Jahresvertrags-Angebot entstanden
    ensure_column('kontingent', 'freigabe_name', "VARCHAR(190) NULL");         // Unterzeichner (Portal-Bestätigung)
    ensure_column('kontingent', 'freigabe_am', "DATETIME NULL");
    ensure_column('kontingent', 'min_abruf', "INT NOT NULL DEFAULT 0");         // Mindest-Abrufmenge je Abruf (0 = keine); Restmenge darf immer voll abgerufen werden
    // Einmalige Bereinigung: Der Zwischenstand „zurueckgezogen" ist entfallen – Zurückziehen heißt jetzt
    // schlicht zurück in den Entwurf. Bestehende Datensätze einmalig auf 'offen' ziehen.
    if (meta_get('fix_angebot_zurueck', '') !== '1') {
        q("UPDATE angebot SET status='offen' WHERE status='zurueckgezogen'");
        meta_set('fix_angebot_zurueck', '1');
    }
    // Einmalige Bereinigung: Angebots-Positionen dürfen nur die zulässigen MwSt-Sätze 0/7/19 tragen.
    // Falsch importierte Werte (z. B. 10) werden auf den nächstliegenden gültigen Satz gezogen.
    if (meta_get('fix_mwst_saetze', '') !== '1') {
        q("UPDATE angebot_position SET mwst_satz=0  WHERE mwst_satz NOT IN (0,7,19) AND mwst_satz < 3.5");
        q("UPDATE angebot_position SET mwst_satz=7  WHERE mwst_satz NOT IN (0,7,19) AND mwst_satz < 8.5");
        q("UPDATE angebot_position SET mwst_satz=19 WHERE mwst_satz NOT IN (0,7,19)");
        meta_set('fix_mwst_saetze', '1');
    }
    // Einmalig: bei aus v3 uebernommenen Rezepturanfragen fehlten die vom Kunden gewuenschten Inhaltsstoffe
    // (rezeptur_anfrage_wunsch). Hier je Anfrage-Nummer aus der v3-Datenbank fest hinterlegt und nachgetragen –
    // nur wenn die Anfrage existiert UND noch keine Wunschzeilen hat (idempotent, keine Ueberschreibung).
    if (meta_get('fix_anfrage_wunsch_v3', '') !== '1') {
        $V3WUNSCH = [
            'RZA-V3-45' => [['Methylcobalamin','0.25','mg'], ['Adenosylcobalamin Pulver [Vitamin B12]','0.25','mg']],
            'RZA-V3-49' => [['Amla (Phyllanthus emblica) Fruchtextrakt, 40 % Gerbstoffe (Titration), Pulver','800','mg'], ['Murraya koenigii','700','mg']],
            'RZA0033' => [['Chlorella','1600','mg']],
            'RZA0065' => [['Cotinus coggygria','1000','mg'], ['Quercetin','500','mg'], ['Weizen Triticum aestivum L Spermidin 0,02%','1250','mg']],
            'RZA0061' => [['Glycin','3000','mg']],
            'RZA0046' => [['Guave-Extrakt Zink 4%','20','mg']],
            'RZA0045' => [['Gurmarin aus Gymnema sylvestre','500','mg']],
            'RZA0039' => [['Phospholipide','1000','mg']],
            'RZA0017' => [['Leucin','800','mg'], ['Valin','800','mg'], ['Lysin','800','mg'], ['Threonin','800','mg'], ['Phenylalanin','700','mg'], ['Isoleucin','500','mg'], ['Taurin','500','mg'], ['Tryptophan','200','mg'], ['Tyrosin','200','mg'], ['Arginin','150','mg'], ['Methionin','150','mg'], ['Histidin','100','mg'], ['Cystein','100','mg'], ['Ribose','1000','mg'], ['Galaktose','1000','mg']],
            'RZA0003' => [['Ascorbinsäure Pulver [Vitamin C]','500','mg']],
            'RZA0001' => [['Ascorbinsäure Pulver [Vitamin C]','3000','mg'], ['Microcrystalline cellulose Pulver [PH101]','500','mg']],
            'RZA0007' => [['Guave-Extrakt Zink 4%','300','mg']],
            'RZA0015' => [['Ascorbinsäure Pulver [Vitamin C]','500','mg']],
            'RZA0024' => [['L-Carnitin','200','mg'], ['Grüner Tee-Extrakt','56.25','mg'], ['Coleus-forskohlii-Wurzelextrakt 10 % Forskolin','30','mg'], ['Cholin','20.625','mg'], ['Garcinia-cambogia-Fruchtschalenextrakt 60 % HCA','6.25','mg'], ['Shatavari-Wurzelextrakt 10:1','6','mg'], ['Bittermelonen-Extrakt','6','mg'], ['Chrompicolinat USP (CrPIX)','0.5','mg']],
            'RZA0026' => [['Magnesiumbisglycinat (ca. 14% Mg)','535','mg'], ['Melatonin','0.25','mg'], ['L-Theanin','50','mg'], ['Reismehl (Füllstoff)','15','mg'], ['Magnesiumstearat (Fließmittel)','5','mg']],
            'RZA0027' => [['Carnosin','2500','mg']],
            'RZA0030' => [['Magnesium (aus Magnesiumoxid)','150','mg'], ['Vitamin B6 (Pyridoxin-HCl)','0.7','mg'], ['Zink (aus Zinkbisglycinat)','5','mg'], ['Biotin','0.025','mg'], ['Mönchspfeffer-Extrakt (Vitex agnus-castus)','20','mg'], ['Reismehl (Füllstoff)','280','mg'], ['Magnesiumsalze der Speisefettsäuren (Fließmittel)','10','mg']],
            'RZA0047' => [['Triphala','500','mg']],
            'RZA0054' => [['Traubenkernextrakt (95% OPC)','500','mg']],
            'RZA0055' => [['L-Ascorbinsäure (Vitamin C)','400','mg']],
            'RZA0056' => [['Natriumselenit','21.9','mg'], ['Akazienfaser','225','mg']],
            'RZA0057' => [['Magnesiumbisglycinat (ca. 14% Magnesium)','1071','mg']],
            'RZA0058' => [['Ashwagandha-Wurzelextrakt (KSM-66, standardisiert auf 5% Withanolide)','600','mg']],
            'RZA0059' => [['Ashwagandha KSM66®','450','mg']],
            'RZA0072' => [['Carnosin','2500','mg']],
        ];
        foreach ($V3WUNSCH as $nr => $zeilen) {
            $aid = (int) scalar("SELECT id FROM rezeptur_anfrage WHERE nummer=?", [$nr]);
            if (!$aid) continue;
            if ((int) scalar("SELECT COUNT(*) FROM rezeptur_anfrage_wunsch WHERE anfrage_id=?", [$aid]) > 0) continue;
            $sort = 0;
            foreach ($zeilen as $z) {
                q("INSERT INTO rezeptur_anfrage_wunsch (anfrage_id,bezeichnung,wunsch_menge,einheit,sort) VALUES (?,?,?,?,?)",
                  [$aid, $z[0], $z[1], $z[2], $sort++]);
            }
        }
        meta_set('fix_anfrage_wunsch_v3', '1');
    }
    // Einmalig: Standard-„Hinweis zur Herstellung" für Belege setzen (wie in v3), falls noch keiner hinterlegt
    // ist. Überschreibt einen selbst gesetzten Text NICHT (nur bei leerem Wert, einmal per Marker).
    if (meta_get('seed_beleg_hinweise', '') !== '1') {
        if (trim((string) meta_get('bh_hinweis_herstellung', '')) === '') {
            meta_set('bh_hinweis_herstellung', 'Aufgrund der Verwendung natürlicher Rohstoffe sowie produktionstechnischer Prozesse können geringfügige Abweichungen in Farbe, Geruch, Geschmack und Optik auftreten. Ebenso sind bei den Füllmengen produktionsbedingte Schwankungen von bis zu ± 10 % möglich. Diese Abweichungen stellen keinen Qualitätsmangel dar.');
        }
        meta_set('seed_beleg_hinweise', '1');
    }
    // Etikettenformate je Behälter (item.etikett_final) + Etiketten-Artikel sicherstellen – damit jedes Glas/PET
    // standardmäßig ein passendes Etikett hat (Auto-Zuordnung im Angebot). Läuft genau einmal (eigener Marker).
    if (function_exists('seed_etikett_formate')) seed_etikett_formate();
    // Aus einer Rezepturanfrage angelegter Rohstoff: bleibt Entwurf (gesperrt) und kommt erst mit der
    // Lieferantenantwort (Preis oder CoA/Spezifikation) in den Katalog.
    ensure_column('item', 'anfrage_entwurf', "TINYINT(1) NOT NULL DEFAULT 0");
    // Rohstoff-Spezifikation (nur das Unterscheidende; Reinheits-Grenzwerte bleiben im PDF)
    ensure_column('item', 'synonym', "VARCHAR(60) NULL");            // z. B. RM940
    ensure_column('item', 'ec_nr', "VARCHAR(30) NULL");
    ensure_column('item', 'bot_quelle', "VARCHAR(190) NULL");        // botanische Quelle / Pflanzenteil
    ensure_column('item', 'art', "VARCHAR(20) NULL");                // Stoffklasse: vitamin|mineralstoff|pflanzenstoff|aminosaeure|fettsaeure|ballaststoff|probiotikum|enzym|sonstiges (Filter Rohstoffe)
    // Novel-Food-Status je Rohstoff (automatisch aus dem EU-Katalog, GESPEICHERT mit Prüfdatum = Snapshot fürs PIB).
    ensure_column('item', 'novelfood_status', "VARCHAR(20) NULL");    // konform|pruefung|novel_food|unklar
    ensure_column('item', 'novelfood_geprueft_am', "DATETIME NULL");  // wann der Status zuletzt gesetzt wurde
    ensure_column('item', 'novelfood_treffer', "TEXT NULL");          // JSON der Katalog-Treffer (Stoff + Status), für Anzeige/Beleg
    // Öffentliche Rohstoff-Datenbank (Website/SEO): je Rohstoff einzeln freigeben; nichts geht automatisch online.
    ensure_column('item', 'website_sichtbar', "TINYINT(1) NOT NULL DEFAULT 0"); // 1 = auf bulkify.pro-Rohstoff-DB zeigen
    ensure_column('item', 'web_slug', "VARCHAR(190) NULL");          // stabile URL (z. B. ashwagandha-wurzelextrakt)
    ensure_column('item', 'web_beschreibung', "TEXT NULL");          // neutrale, claims-sichere Beschreibung für die Website
    ensure_column('item', 'herkunftsland', "VARCHAR(120) NULL");
    ensure_column('item', 'haltbarkeit', "VARCHAR(120) NULL");
    ensure_column('item', 'lagerbedingungen', "TEXT NULL");
    ensure_column('item', 'zusaetze', "VARCHAR(255) NULL");          // Verarbeitungshilfsstoffe/Zusätze (E-Nummern)
    ensure_column('item', 'vegan', "TINYINT(1) NULL");               // 1=ja 0=nein NULL=unbekannt
    ensure_column('item', 'gvo_frei', "TINYINT(1) NULL");
    ensure_column('item', 'bestrahlt', "TINYINT(1) NULL");           // 1=bestrahlt 0=nicht bestrahlt
    ensure_column('item', 'tse_bse_frei', "TINYINT(1) NULL");
    ensure_column('item', 'zertifikate', "VARCHAR(255) NULL");       // Bio, Fair Trade …
    ensure_column('item', 'spec_nr', "VARCHAR(40) NULL");
    ensure_column('item', 'spec_version', "VARCHAR(20) NULL");
    ensure_column('item', 'spec_gueltig_ab', "DATE NULL");
    ensure_column('item', 'spec_pdf', "VARCHAR(255) NULL");          // Dateiname des Spec-PDF (in data/uploads)
    ensure_column('item', 'spec_freigegeben', "TINYINT(1) NOT NULL DEFAULT 0");   // bulkify-Spezifikation fuer den Kunden freigegeben?
    ensure_column('item', 'spec_freigabe_am', "DATETIME NULL");
    ensure_column('item', 'spec_freigabe_von', "VARCHAR(190) NULL");
    // Beschaffenheit: WAS der Rohstoff ist (Extrakt / reines Pulver / Isolat ...) + Extraktverhaeltnis (DEV, z. B. 1:10).
    // Getrennt von der physischen Form und der Spezifikation (Standardisierung). Fliesst in den Anzeigenamen ein
    // (rohstoff_anzeige_name(): z. B. "Ashwagandha Extrakt 10:1").
    ensure_column('item', 'beschaffenheit', "VARCHAR(30) NULL");
    ensure_column('item', 'dev', "VARCHAR(20) NULL");   // Droge-Extrakt-Verhaeltnis, z. B. 1:10 / 10:1 / 4:1
    ensure_column('item', 'vk_aufschlag_prozent', "DECIMAL(6,2) NULL"); // Rohstoff-Verkauf: eigener Aufschlag % (leer = globaler aufschlag_rohstoff)
    // KI-Kurzinfo je Rohstoff: nur auf Knopfdruck erzeugt (item_ki_info_erzeugen). Wird Team + Kunde als „KI-Info" gezeigt.
    ensure_column('item', 'ki_info', "TEXT NULL");
    ensure_column('item', 'ki_info_am', "DATETIME NULL");   // wann zuletzt erzeugt (UTC)
    // Lieferanten-Kürzel: je Lieferant ein kurzes Suffix aus dem Namen (Buxtrade→BX, Wellgreen→WG …).
    // Damit lässt sich derselbe Rohstoff je Lieferant eindeutig kennzeichnen: Kennung = R-Nummer + Kürzel.
    ensure_column('lieferanten', 'kuerzel', "VARCHAR(10) NULL");
    // Vorschlag befüllen (idempotent): die ersten beiden Buchstaben/Ziffern des Namens, GROSS. Manuell überschreibbar.
    q("UPDATE lieferanten SET kuerzel = LEFT(UPPER(REGEXP_REPLACE(firma, '[^A-Za-z0-9]', '')), 2)
       WHERE (kuerzel IS NULL OR kuerzel='') AND firma<>''");
    ensure_column('pack_ek_staffel', 'lieferant_id', "INT NULL");        // Verpackung: welcher Lieferant je EK-Staffelstufe
    // Verpackungs-Maße (mm) + Leergewicht (g) – u. a. für PPWR-Meldung / Etikettenmaße
    ensure_column('item', 'hoehe_mm', "DECIMAL(8,2) NULL");
    ensure_column('item', 'durchmesser_mm', "DECIMAL(8,2) NULL");   // runde Behälter
    ensure_column('item', 'breite_mm', "DECIMAL(8,2) NULL");        // eckige Behälter / Etikett
    ensure_column('item', 'tiefe_mm', "DECIMAL(8,2) NULL");         // Karton
    ensure_column('item', 'gewicht_g', "DECIMAL(8,2) NULL");        // Leergewicht der Verpackung (PPWR)
    // Lager 2 (Fremdlager, nur Fulfillment-Kunden): Brücke Dashboard↔Fulfillment am Verkaufsfertig-Item.
    ensure_column('item', 'bsku', "VARCHAR(10) NULL");                         // interne 5-stellige Lager-2-Nummer
    ensure_column('item', 'shopify_inventory_item_id', "VARCHAR(40) NULL");    // führender Schlüssel zum Fulfillment-Artikel
    ensure_column('kunden', 'nutzt_fulfillment', "TINYINT NOT NULL DEFAULT 0"); // Kunde nutzt unser Fulfillment → hat ein Lager 2
    ensure_column('kunden', 'eigener_dhl', "TINYINT NOT NULL DEFAULT 0"); // Kunde hat eigenen DHL-Vertrag → Monatsabrechnung ohne Paketzeilen, nur Fulfillment-Service
    // Betriebsmittel (Kartons/Verbrauchsgüter/Inventar/Maschinen/Sonstiges): einfacher Bestand + Geräteprüfung.
    ensure_column('item', 'bestand_menge', "DECIMAL(12,3) NOT NULL DEFAULT 0"); // manueller Bestand (keine Chargen)
    ensure_column('item', 'mindestbestand', "DECIMAL(12,3) NULL");              // Meldebestand (optional)
    ensure_column('item', 'elektrisch', "TINYINT NOT NULL DEFAULT 0");          // elektronisches Gerät → jährliche Prüfung
    ensure_column('item', 'pruef_intervall_monate', "INT NULL");                // Prüf-Intervall in Monaten (Standard 12)
    ensure_column('item', 'letzte_pruefung', "DATE NULL");                      // Datum der letzten Prüfung

    // kapselgroesse: Kapselgrößen mit nomineller Füllmenge (mg) – Basis für die „passt das rein?"-Prüfung.
    $pdo->exec("CREATE TABLE IF NOT EXISTS kapselgroesse (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(40) NOT NULL,
        fuellmenge_mg INT NOT NULL DEFAULT 0,
        sort INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // pack_kapazitaet: wie viele Kapseln je Kapselgröße in eine bestimmte Primärverpackung (Dose/Glas) passen.
    $pdo->exec("CREATE TABLE IF NOT EXISTS pack_kapazitaet (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,                             -- die Verpackung (Primär, Dose/Glas)
        kapselgroesse_id INT NOT NULL,                    -- welche Kapselgröße
        stueck INT NOT NULL DEFAULT 0,                    -- so viele Kapseln passen rein
        KEY idx_item (item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // benutzer: interne Mitarbeiter mit E-Mail-Login und Rollen-Set (CSV, z. B. 'finance,einkauf'). admin = alles.
    $pdo->exec("CREATE TABLE IF NOT EXISTS benutzer (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL,
        pass_hash VARCHAR(255) NOT NULL,
        rollen VARCHAR(255) NOT NULL DEFAULT '',          -- CSV: admin|sales|finance|einkauf|production|fulfillment|labor
        aktiv TINYINT(1) NOT NULL DEFAULT 1,
        letzter_login DATETIME NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column('benutzer', 'login_token', "VARCHAR(64) NULL");   // für lokalen Autologin-Link
    // Fehlende Login-Tokens auffüllen (nur leere)
    foreach (all("SELECT id FROM benutzer WHERE login_token IS NULL OR login_token=''") as $bu) {
        q("UPDATE benutzer SET login_token=? WHERE id=?", [bin2hex(random_bytes(16)), (int)$bu['id']]);
    }

    // pack_ek_staffel: Behälter-EK je Bestellmenge (feste EK-Preise für PET/Gläser, mengenabhängig).
    $pdo->exec("CREATE TABLE IF NOT EXISTS pack_ek_staffel (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,                             -- die Verpackung
        menge_ab INT NOT NULL DEFAULT 0,                  -- ab dieser Bestellmenge (Stück Gebinde)
        ek_preis DECIMAL(12,4) NOT NULL DEFAULT 0,        -- EK je Gebinde in dieser Staffel
        KEY idx_item (item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // pack_vk_staffel: optionaler direkter VK je Bestellmenge (überschreibt EK×Aufschlag) – Verkaufspreis von Hand.
    $pdo->exec("CREATE TABLE IF NOT EXISTS pack_vk_staffel (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        menge_ab INT NOT NULL DEFAULT 0,
        vk_preis DECIMAL(12,4) NOT NULL DEFAULT 0,        -- VK je Gebinde (ohne Kundenrabatt)
        KEY idx_item (item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // angebot_produkt: welche konkreten PRODUKTE ein Angebot bepreist hat (Rezeptur x Menge + Verpackung = Produkt).
    // Eine Anfrage über 90/120/180 Stück erzeugt drei Produkte – alle bleiben erhalten, auch wenn der Kunde nur eines nimmt.
    // Steuert zusätzlich, wer im Portal einen Preis sieht: nur Kunden, denen dieses Produkt angeboten wurde.
    $pdo->exec("CREATE TABLE IF NOT EXISTS angebot_produkt (
        id INT AUTO_INCREMENT PRIMARY KEY,
        angebot_id INT NOT NULL,
        produkt_id INT NOT NULL,
        stueck INT NOT NULL DEFAULT 0,                    -- Packungsgröße (Stück, bei Pulver/Flüssig g/ml)
        verpackung_id INT NULL,                           -- der konkrete Behälter dieses Artikels
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ang_prod (angebot_id, produkt_id),
        KEY idx_angebot (angebot_id),
        KEY idx_produkt (produkt_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // etikett_preis: Etiketten-EK je Gebinde als Mengenstaffel (Labelisten, Stand Juni 2026).
    // Pro Behälter (item_id = Verpackung) ein Preis je Bestellmenge: Gesamtpreis der Auflage + Preis je Etikett.
    $pdo->exec("CREATE TABLE IF NOT EXISTS etikett_preis (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,                             -- Gebinde (Verpackung), zu dem das Etikett gehört
        menge_ab INT NOT NULL DEFAULT 0,                  -- ab dieser Bestellmenge (Stück Etiketten)
        ek_gesamt DECIMAL(12,2) NOT NULL DEFAULT 0,       -- Gesamtpreis der Auflage in dieser Staffel
        ek_stueck DECIMAL(12,4) NOT NULL DEFAULT 0,       -- EK je Etikett (Gesamt / Menge, sub-Cent-genau)
        KEY idx_item (item_id, menge_ab)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // produkt_preis: generierte Preismatrix je Produkt (Stück je Packung × Verpackung × Bestellmenge -> EK/VK). Intern.
    $pdo->exec("CREATE TABLE IF NOT EXISTS produkt_preis (
        id INT AUTO_INCREMENT PRIMARY KEY,
        produkt_id INT NOT NULL,
        stueck INT NOT NULL,                              -- Stück je Packung (30/60/90…)
        verpackung_id INT NOT NULL,
        bestellmenge INT NOT NULL,                        -- Bestellmengen-Staffel (1000/2500…)
        ek_preis DECIMAL(12,4) NOT NULL DEFAULT 0,        -- EK je Packung
        vk_preis DECIMAL(12,4) NOT NULL DEFAULT 0,        -- VK je Packung (Basis, ohne Kundenrabatt)
        stand DATETIME NULL,
        KEY idx_produkt (produkt_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // produkt_kundenpreis: was wurde WELCHEM Kunden für dieses Produkt zu welcher Konfiguration
    // (Menge je Packung + Anzahl VPE) berechnet. Damit man je Produkt schnell sieht, welcher Kunde
    // welchen Preis hat. Quelle u. a. der v3-Import (bestätigte Produktanfragen).
    $pdo->exec("CREATE TABLE IF NOT EXISTS produkt_kundenpreis (
        id INT AUTO_INCREMENT PRIMARY KEY,
        produkt_id INT NOT NULL,
        kunde_id INT NOT NULL,
        menge_pro_vpe INT NULL,                            -- Stück je Packung (VPE)
        anzahl_vpe INT NULL,                               -- Anzahl VPE (Bestellmenge)
        verpackung VARCHAR(190) NULL,                      -- Freitext (Gebinde), solange nicht als Artikel verknüpft
        preis DECIMAL(12,4) NULL,                          -- vereinbarter VK je Packung (EUR)
        notiz VARCHAR(255) NULL,
        v3_id INT NULL,                                    -- Herkunft: v3 produktanfrage.id (idempotenter Import)
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_produkt (produkt_id), KEY idx_kunde (kunde_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // rezeptur_lief_angebot: Angebote von Lieferanten für die FREMDFERTIGUNG einer Rezeptur
    // (Preis je Einheit, z. B. je Kapsel). Aus v3 übernommen (lieferant_angebot). Anzeige an der Rezeptur.
    $pdo->exec("CREATE TABLE IF NOT EXISTS rezeptur_lief_angebot (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rezeptur_id INT NOT NULL,
        lieferant_id INT NULL,
        preis DECIMAL(12,4) NULL,
        einheit VARCHAR(30) NULL,
        menge DECIMAL(14,3) NULL,
        status VARCHAR(20) NULL,
        notiz VARCHAR(255) NULL,
        angenommen_am DATETIME NULL,
        v3_id INT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_rezeptur (rezeptur_id), KEY idx_lieferant (lieferant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column('rezeptur_lief_angebot', 'stand', "DATE NULL");   // wann der Preis zuletzt eingetragen/aktualisiert wurde (4-Wochen-Regel)
    // Mengenstaffeln je Fremdfertigungs-Angebot (ab_menge -> Preis je Einheit). Lieferanten unterbieten sich hierüber.
    $pdo->exec("CREATE TABLE IF NOT EXISTS rezeptur_lief_angebot_staffel (
        id INT AUTO_INCREMENT PRIMARY KEY,
        angebot_id INT NOT NULL,
        ab_menge DECIMAL(14,3) NOT NULL DEFAULT 0,
        preis DECIMAL(12,4) NULL,
        sort INT NOT NULL DEFAULT 0,
        KEY idx_ang (angebot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // lieferant_preisliste: Nachschlage-Liste der Rohstoff-Einkaufspreise (Name · Lieferant · EUR/kg),
    // aus v3 übernommen. Bewusst OHNE Verknüpfung zum v4-Lagerartikel (v4 hat einen eigenen Rohstoffstamm) –
    // reine Referenz zum schnellen „was zahlen wir wofür". Bei Bedarf später einem Artikel zuordnen.
    $pdo->exec("CREATE TABLE IF NOT EXISTS lieferant_preisliste (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rohstoff_name VARCHAR(190) NOT NULL,
        lieferant VARCHAR(190) NULL,
        eur_kg DECIMAL(12,4) NULL,
        stand DATE NULL,
        v3_id INT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_name (rohstoff_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column('lieferant_preisliste', 'lieferant_id', "INT NULL");        // Zuordnung zum Lieferanten (die Preisliste GEHOERT diesem Lieferanten)
    ensure_column('lieferant_preisliste', 'einheit', "VARCHAR(20) NULL");     // Bezug des Preises (Standard kg)
    ensure_column('lieferanten', 'preis_intervall_tage', "INT NOT NULL DEFAULT 28");   // 4-Wochen-Regel: Preise muessen so oft aktualisiert werden
    // Partner: ein Lieferant darf ZUSAETZLICH wie ein Kunde bei uns bestellen (Alex-Fall). Dann verknuepfen wir
    // ihn mit einem kunden-Datensatz (kunde_id) – so laeuft die ganze Kunden-/Marge-/Anfrage-Logik unveraendert.
    // Die Partner-Marge lebt an EINER Quelle: kunden.rabatt_marge des verknuepften Kunden (hier nichts doppeln).
    ensure_column('lieferanten', 'ist_partner', "TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column('lieferanten', 'kunde_id', "INT NULL");   // verknuepfter kunden-Datensatz, sobald Partner aktiviert
    ensure_index('lieferant_preisliste', 'idx_lief', 'lieferant_id');
    // Einmalig: importierte/vorhandene Preislisten-Zeilen dem Lieferanten per Namen zuordnen (firma == lieferant-Text).
    if (meta_get('preisliste_lief_link', '') !== '1') {
        q("UPDATE lieferant_preisliste pl JOIN lieferanten l ON l.firma = pl.lieferant
           SET pl.lieferant_id = l.id WHERE pl.lieferant_id IS NULL AND pl.lieferant IS NOT NULL AND pl.lieferant<>''");
        meta_set('preisliste_lief_link', '1');
    }

    // portal_anfrage: Kundenanfragen aus dem Portal für Produkt / Rohstoff / Dienstleistung (Rezeptur läuft separat über rezeptur_anfrage).
    // AGB, versioniert: eine Fassung ist aktiv, alte bleiben als Beleg stehen. Beim verbindlichen
    // Annehmen wird die Versionsbezeichnung am Vorgang gespeichert.
    $pdo->exec("CREATE TABLE IF NOT EXISTS agb (
        id INT AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(40) NOT NULL,
        inhalt MEDIUMTEXT NULL,
        aktiv TINYINT(1) NOT NULL DEFAULT 0,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_anfrage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nummer VARCHAR(20) NULL,
        kunde_id INT NULL,
        typ VARCHAR(20) NOT NULL DEFAULT 'produkt',        -- produkt|rohstoff|dienstleistung
        produkt_id INT NULL,                               -- bei Produktanfrage
        stueck INT NULL,                                   -- bei Produktanfrage: Stück je Packung
        verpackung_id INT NULL,
        menge INT NULL,                                    -- Bestellmenge
        betreff VARCHAR(190) NULL,
        notiz TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'neu',         -- neu|in_bearbeitung|beantwortet|abgelehnt
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        aktualisiert DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_kunde (kunde_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // portal_anfrage_pos: mehrere Produkte/Mengen je Produktanfrage (Multiprodukt). Ohne Zeilen = Einzelanfrage
    // aus den Inline-Feldern von portal_anfrage (Rückwärtskompatibilität). Gruppierung im Angebot je produkt_id (A–Z).
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_anfrage_pos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        anfrage_id INT NOT NULL,
        produkt_id INT NULL,
        stueck INT NULL,                                   -- Stück je Packung (Kapsel/Tablette/…)
        fuellmenge_g DECIMAL(10,2) NULL,                   -- Pulver: Füllmenge je Packung (g)
        verpackung_typ VARCHAR(40) NULL,                   -- Wunsch-Verpackungstyp
        menge INT NULL,                                    -- Bestellmenge (Packungen)
        sort INT NOT NULL DEFAULT 0,
        KEY idx_anfrage (anfrage_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Novel-Food-Katalog (EU) – Grundlage für den automatischen Abgleich der Produkt-Rezepturen.
    // Befüllt per tools/novelfood_import.php aus der JSON-Liste; Abgleich über produkt_novelfood_pruefen().
    $pdo->exec("CREATE TABLE IF NOT EXISTS novelfood_katalog (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(40) NULL,
        name VARCHAR(255) NOT NULL,
        trivial VARCHAR(500) NULL,
        syn VARCHAR(500) NULL,
        status VARCHAR(120) NULL,
        status_code VARCHAR(50) NULL,           -- NOT_NOVEL_IN_FOOD | NOT_NOVEL_IN_FOOD_SUPPLEMENTS | NOT_YET_AUTHORISED_NOVEL_FOOD | AUTHORISED_NOVEL_FOOD | SUBJECT_TO_A_CONSULTATION_REQUEST
        teil VARCHAR(120) NULL,
        beschreibung_de TEXT NULL,
        UNIQUE KEY uq_code (code),
        KEY idx_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Zusatzfelder für den automatischen EU-Abgleich (core/novelfood_sync.php): englisches Original,
    // Publikationsstatus und die EU-Datumsangaben. Additiv, damit der bestehende Import weiter läuft.
    ensure_column('novelfood_katalog', 'beschreibung', 'TEXT NULL AFTER beschreibung_de');   // englisches Original (aus EU-API)
    ensure_column('novelfood_katalog', 'pub',       "VARCHAR(40) NULL AFTER beschreibung");  // Publikationsstatus (z. B. PUBLISHED)
    ensure_column('novelfood_katalog', 'erstellt',  'VARCHAR(10) NULL AFTER pub');            // EU-Erstelldatum (YYYY-MM-DD)
    ensure_column('novelfood_katalog', 'geaendert', 'VARCHAR(10) NULL AFTER erstellt');       // EU-Änderungsdatum (YYYY-MM-DD)

    // novelfood_lauf: Kopf je Aktualisierungslauf (Button oder Monatsroutine). Gruppiert die Änderungen,
    // damit die Übersicht „was ist neu / was wurde geändert" pro Lauf zeigt.
    $pdo->exec("CREATE TABLE IF NOT EXISTS novelfood_lauf (
        id INT AUTO_INCREMENT PRIMARY KEY,
        gestartet_at DATETIME NOT NULL,            -- UTC
        beendet_at DATETIME NULL,                  -- UTC
        ausgeloest_von VARCHAR(20) NOT NULL DEFAULT 'manuell',   -- manuell | auto | cli
        benutzer VARCHAR(120) NULL,                -- wer den Button gedrückt hat
        status VARCHAR(20) NOT NULL DEFAULT 'laufend',           -- laufend | fertig | fehler
        quelle VARCHAR(190) NULL,                  -- API-URL/Herkunft
        katalog_stand VARCHAR(40) NULL,            -- gemeldeter Stand (falls vorhanden)
        anzahl_gesamt INT NOT NULL DEFAULT 0,
        anzahl_neu INT NOT NULL DEFAULT 0,
        anzahl_geaendert INT NOT NULL DEFAULT 0,
        anzahl_status INT NOT NULL DEFAULT 0,      -- davon mit Statuswechsel
        anzahl_entfernt INT NOT NULL DEFAULT 0,
        anzahl_uebersetzt INT NOT NULL DEFAULT 0,
        ki_tokens INT NOT NULL DEFAULT 0,
        dauer_ms INT NOT NULL DEFAULT 0,
        meldung TEXT NULL,
        KEY idx_gestartet (gestartet_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // novelfood_change: eine Zeile je geänderter Position eines Laufs (neu | geaendert | entfernt).
    $pdo->exec("CREATE TABLE IF NOT EXISTS novelfood_change (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lauf_id INT NOT NULL,
        art VARCHAR(12) NOT NULL,                  -- neu | geaendert | entfernt
        code VARCHAR(40) NULL,
        name VARCHAR(255) NOT NULL,
        felder VARCHAR(255) NULL,                  -- bei 'geaendert': welche Felder (CSV)
        status_wechsel TINYINT NOT NULL DEFAULT 0,
        status_alt VARCHAR(120) NULL,
        status_neu VARCHAR(120) NULL,
        status_code VARCHAR(50) NULL,
        KEY idx_lauf (lauf_id),
        KEY idx_art (art)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // verpackung_dokument: Dokumente je Verpackung (PPWR-Nachweise, DoC, Spez., Etikett-Druckdatei …).
    $pdo->exec("CREATE TABLE IF NOT EXISTS verpackung_dokument (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        titel VARCHAR(190) NULL,
        kategorie VARCHAR(30) NOT NULL DEFAULT 'ppwr',   -- ppwr|doc|spez|etikett|sonstiges
        datei VARCHAR(255) NOT NULL,                      -- Dateiname in data/uploads
        datei_orig VARCHAR(255) NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_item (item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // dokument: generische Dokumentenablage (COA/Spec/Analyse …) für Rohstoffe (objekt_typ=item) und Produkte (produkt).
    // Jedes Dokument kann einem Lieferanten zugeordnet sein (Nachweise sind mit dem Anbieter verknüpft).
    $pdo->exec("CREATE TABLE IF NOT EXISTS dokument (
        id INT AUTO_INCREMENT PRIMARY KEY,
        objekt_typ VARCHAR(20) NOT NULL DEFAULT 'item',   -- item | produkt
        objekt_id INT NOT NULL,
        typ VARCHAR(20) NOT NULL DEFAULT 'coa',            -- coa | spec | analyse | sonstiges
        lieferant_id INT NULL,
        titel VARCHAR(190) NULL,
        datei VARCHAR(255) NOT NULL,
        datei_orig VARCHAR(255) NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_obj (objekt_typ, objekt_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // item_kennwert: charakteristische Kennwerte je Rohstoff (Parameter + Wert), das Unterscheidende der Spec.
    $pdo->exec("CREATE TABLE IF NOT EXISTS item_kennwert (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        parameter VARCHAR(120) NOT NULL,
        wert VARCHAR(120) NULL,
        sort INT NOT NULL DEFAULT 0,
        KEY idx_item (item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Cache der Material-Bedarfsrechnung je Produktionsauftrag (auftrag_bedarf). Gilt, solange die globale
    // bedarf_version unverändert ist; relevante Schreibvorgänge (Wareneingang, Reservierung, Bestellung,
    // Chargen-Status, Auftragsänderung) erhöhen die Version -> Cache wird ungültig. Zusätzlich Sicherheits-TTL.
    $pdo->exec("CREATE TABLE IF NOT EXISTS bedarf_cache (
        pa_id INT PRIMARY KEY,
        version INT NOT NULL,
        daten MEDIUMTEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Zusätzliche Indizes für häufige Lookups, die sonst Volltabellen-Scans wären (v. a. auf beta mit
    // mehr Daten spürbar). Idempotent über ensure_index. Reihenfolge/Spaltenwahl aus den echten Abfragen.
    ensure_index('charge', 'idx_auftrag', 'auftrag_id');                 // Fertigware/Zukauf: WHERE auftrag_id=?
    ensure_index('charge', 'idx_fremd', 'fremd_kunde_id');               // Fremdlager: Bestand je Kunde / Ausschluss aus unserem Bestand
    ensure_index('item', 'idx_name', 'name');                           // Rohstoff-/Artikel-Lookup: WHERE name=?
    ensure_index('item', 'idx_kat_rolle', 'kategorie, verpackung_rolle'); // Etiketten/Verpackung: kategorie+rolle
    ensure_index('rezeptur_zutat', 'idx_item', 'item_id');              // Nährwert-Joins über item_id
    ensure_index('angebot', 'idx_anfrage', 'anfrage_id');               // Angebot je Anfrage
    ensure_index('angebot', 'idx_status', 'status');                    // offene/gesendete Angebote
    ensure_index('auftrag', 'idx_status', 'status');                    // Aufträge nach Status (Versand/Bedarf)
    ensure_index('produktionsauftrag', 'idx_status', 'status');         // Produktionslisten nach Status
    ensure_index('produkt', 'idx_rezeptur', 'rezeptur_id');            // Produkt -> Rezeptur
    ensure_index('rezeptur', 'idx_kunde', 'kunde_id');                  // eigene Rezepturen je Kunde
    ensure_index('rezeptur', 'idx_v3', 'v3_id');                        // v3-Import/Nachlieferung
    ensure_index('rezeptur', 'idx_basis', 'basis_rezeptur_id');         // abgeleitete Rezepturen je Basis
    ensure_index('dokument', 'idx_typ_obj', 'objekt_typ, objekt_id, typ'); // CoA/PIB/Etikett je Objekt+Typ
    ensure_index('beleg', 'idx_auftrag', 'auftrag_id');                // Rechnung je Auftrag
    ensure_index('beleg', 'idx_kunde_typ', 'kunde_id, typ');           // Rechnungen je Kunde

    // Jede Rezeptur bekommt genau EIN Lager-Bulk-Item (Kategorie 'fertig', item.rezeptur_id),
    // damit ankommende Ware im Lager der Rezeptur zugeordnet werden kann – auch wenn die Rezeptur
    // nie in Produktion geht. Einmal je Deploy: fehlende nachlegen (idempotent via rezeptur_bulkitem).
    if (table_exists('rezeptur') && table_exists('item')) {
        try {
            foreach (all("SELECT r.id FROM rezeptur r
                          WHERE NOT EXISTS (SELECT 1 FROM item i WHERE i.rezeptur_id=r.id AND i.kategorie='fertig')") as $__rz)
                rezeptur_bulkitem((int)$__rz['id']);
        } catch (\Throwable $e) { /* Backfill darf den Schema-Build nie blockieren */ }
    }

    // EINMALIG: bestehende Produktionsaufträge gelten als bereits „festgelegt" (nicht nachträglich sperren).
    // Nur NEUE Aufträge brauchen künftig die Eigen/Fremd-Festlegung, bevor sie in die Produktion gehen.
    if (table_exists('produktionsauftrag') && meta_get('pa_art_festgelegt_backfill', '') !== '1') {
        try { q("UPDATE produktionsauftrag SET art_festgelegt_am=COALESCE(angelegt, NOW()) WHERE art_festgelegt_am IS NULL"); } catch (\Throwable $e) {}
        meta_set('pa_art_festgelegt_backfill', '1');
    }

    // EINMALIG (PreProduktionsauftrag): noch nicht freigegebene Kunden-Produktionsaufträge in die neue
    // Vor-Produktion heben (Status 'vorbereitung') und für offene Aufträge OHNE Produktionsauftrag einen
    // Vor-PA anlegen, damit die Vor-Produktions-Liste sofort gefüllt ist. Alles additiv, try/catch, Kill-Schalter.
    if (table_exists('produktionsauftrag') && meta_get('pa_vorbereitung_backfill', '') !== '1'
        && meta_get('pa_vorbereitung_backfill_off', '') !== '1') {
        try {
            // 1) Unentschiedene (noch nicht in die Produktion freigegebene) Kunden-PAs -> Vorbereitung.
            q("UPDATE produktionsauftrag SET status='vorbereitung'
               WHERE status='offen' AND auftrag_id IS NOT NULL AND art_festgelegt_am IS NULL
                 AND NOT EXISTS (SELECT 1 FROM produktion_schritt s WHERE s.pa_id=produktionsauftrag.id AND s.erledigt=1)");
            // 2) Offene Aufträge mit Produkt, aber ohne jeden Produktionsauftrag -> Vor-PA anlegen (gedeckelt).
            $ohne = all("SELECT a.id FROM auftrag a
                         WHERE a.status='offen' AND a.produkt_id IS NOT NULL AND a.produkt_id>0
                           AND NOT EXISTS (SELECT 1 FROM produktionsauftrag pa WHERE pa.auftrag_id=a.id)
                         ORDER BY a.id DESC LIMIT 500");
            foreach ($ohne as $__a) produktionsauftrag_aus_auftrag((int)$__a['id']);   // legt im Status 'vorbereitung' an
        } catch (\Throwable $e) { /* Backfill darf den Schema-Build nie blockieren */ }
        meta_set('pa_vorbereitung_backfill', '1');
    }

    // Bedarf-Cache nach jedem Deploy einmal invalidieren – so greifen Änderungen an der Bedarfsrechnung
    // (z. B. Fallback produkt.einheiten_pro_packung -> auftrag.stueck) sofort und nicht erst nach TTL.
    bedarf_bump();

    // Dienstleistungen-Modul: eigenes Schema (core/dienstleistung.php). Guarded, damit andere
    // Einstiegspunkte (ds_api, tools) ohne diese Datei nicht abbrechen.
    if (function_exists('dienstleistung_schema')) dienstleistung_schema();

    // Migrationen durch -> Marker setzen, damit der nächste Request den Block überspringt.
    if ($schemaBuild !== '') meta_set('schema_build', $schemaBuild);
}

// Erster Admin, falls noch kein Benutzer existiert. Zugang danach bitte ändern.
function seed_benutzer_if_empty(): void {
    if ((int) scalar("SELECT COUNT(*) FROM benutzer") > 0) return;
    q("INSERT INTO benutzer (name,email,pass_hash,rollen,aktiv) VALUES (?,?,?,?,1)",
      ['Administrator', 'admin@bulkify.local', password_hash('admin', PASSWORD_DEFAULT), 'admin']);
}

// Standard-Kapselgrößen (nominelle Füllmenge ~ Pulver mittlerer Dichte). In Einstellungen anpassbar.
function seed_kapselgroesse_if_empty(): void {
    if ((int) scalar("SELECT COUNT(*) FROM kapselgroesse") > 0) return;
    $demo = [['Größe 5',130],['Größe 4',210],['Größe 3',280],['Größe 2',370],['Größe 1',500],['Größe 0',680],['Größe 00',950],['Größe 000',1370]];
    $i = 0;
    foreach ($demo as $d) q("INSERT INTO kapselgroesse (name,fuellmenge_mg,sort) VALUES (?,?,?)", [$d[0],$d[1],$i++]);
}

// Verpackungs-Rollen (Funktion in der Stückliste). primaer = hält das Produkt direkt.
function verpackung_rollen(): array {
    return ['primaer'=>'Primärverpackung','verschluss'=>'Verschluss/Deckel','etikett'=>'Etikett','karton'=>'Faltschachtel/Karton','beipack'=>'Beipackzettel'];
}

// Standard-Behälter (PET-Packer, Gläser …) mit Kapsel-Fassung je Kapselgröße – Herstellerwerte. Läuft genau einmal (Marker in app_meta), überschreibt keine Handeingaben.
function seed_behaelter_kapazitaet(): void {
    if (meta_get('seed_behaelter_kap', '') === '1') return;
    seed_kapselgroesse_if_empty();
    $kg = [];
    foreach (all("SELECT id, name FROM kapselgroesse") as $r) $kg[$r['name']] = (int)$r['id'];
    $cols = ['Größe 00', 'Größe 0', 'Größe 1', 'Größe 2'];   // Reihenfolge = Spalten #00 #0 #1 #2
    // [Name, verpackungsart, Material, Volumen ml, [#00, #0, #1, #2]] – null = passt nicht / kein Wert
    $data = [
        ['100 ml PET Packer', 'dose', 'PET', 100, [50, 80, 100, 120]],
        ['150 ml PET Packer', 'dose', 'PET', 150, [80, 110, 140, 160]],
        ['200 ml PET Packer', 'dose', 'PET', 200, [130, 180, 230, 240]],
        ['250 ml PET Packer', 'dose', 'PET', 250, [150, 200, 250, 300]],
        ['300 ml Flip Packer', 'dose', 'PET', 300, [180, 230, 270, null]],
        ['230 ml PLA Becher', 'dose', 'PLA', 230, [120, 180, 220, null]],
        ['100 ml Weithalsglas', 'dose', 'Glas', 100, [50, 65, 105, 125]],
        ['150 ml Weithalsglas', 'dose', 'Glas', 150, [85, 120, 150, 180]],
        ['200 ml Weithalsglas', 'dose', 'Glas', 200, [110, 150, 200, 220]],
        ['250 ml Weithalsglas', 'dose', 'Glas', 250, [140, 180, 320, null]],
    ];
    foreach ($data as $d) {
        [$name, $art, $mat, $vol, $caps] = $d;
        $iid = (int) scalar("SELECT id FROM item WHERE name=? AND kategorie='verpackung'", [$name]);
        if (!$iid) {
            q("INSERT INTO item (artikelnummer,name,kategorie,verpackung_rolle,verpackungsart,material,volumen_ml,einheit,preis_bezug)
               VALUES (?,?,?,?,?,?,?,?,?)",
              [naechste_nummer('VP'), $name, 'verpackung', 'primaer', $art, $mat, $vol, 'Stück', 'Stück']);
            $iid = insert_id();
        }
        if ((int) scalar("SELECT COUNT(*) FROM pack_kapazitaet WHERE item_id=?", [$iid]) === 0) {
            foreach ($cols as $i => $cn) {
                $stk = $caps[$i] ?? null;
                if ($stk !== null && isset($kg[$cn]))
                    q("INSERT INTO pack_kapazitaet (item_id,kapselgroesse_id,stueck) VALUES (?,?,?)", [$iid, $kg[$cn], (int)$stk]);
            }
        }
    }
    meta_set('seed_behaelter_kap', '1');
}

// Etiketten-EK je Gebinde (Labelisten, Stand Juni 2026) als Mengenstaffel. Läuft genau einmal (Marker),
// überschreibt keine Handeingaben (pro Gebinde nur, wenn noch keine Etikettenpreise vorhanden).
function seed_etikett_preise(): void {
    if (meta_get('seed_etikett_preise', '') === '1') return;
    // Gebinde-Name => [Bestellmenge => Gesamtpreis der Auflage in € ]. EK je Stück = Gesamt / Menge.
    $data = [
        '100 ml Weithalsglas' => [100=>65.98, 500=>88.80, 1000=>118.84, 2000=>174.29, 3000=>227.23, 4000=>277.38, 5000=>323.97],
        '150 ml Weithalsglas' => [100=>67.35, 500=>94.44, 1000=>129.25, 2000=>194.24, 3000=>254.82, 4000=>310.14, 5000=>362.02],
        '200 ml Weithalsglas' => [100=>71.89, 500=>109.50,1000=>153.82, 2000=>237.24, 3000=>313.37, 4000=>381.14, 5000=>441.11],
        '250 ml Weithalsglas' => [100=>68.48, 500=>111.79,1000=>159.51, 2000=>247.77, 3000=>326.68, 4000=>397.24, 5000=>458.51],
        '100 ml PET Packer'   => [100=>64.89, 500=>87.99, 1000=>116.85, 2000=>172.22, 3000=>224.51, 4000=>273.73, 5000=>319.86],
        '150 ml PET Packer'   => [100=>70.20, 500=>100.54,1000=>138.53, 2000=>210.56, 3000=>277.02, 4000=>337.93, 5000=>394.07],
        '200 ml PET Packer'   => [100=>71.90, 500=>107.82,1000=>152.28, 2000=>234.43, 3000=>309.59, 4000=>377.39, 5000=>436.84],
        '250 ml PET Packer'   => [100=>72.02, 500=>108.24,1000=>153.63, 2000=>237.77, 3000=>314.01, 4000=>381.81, 5000=>441.54],
    ];
    foreach ($data as $name => $staffel) {
        $iid = (int) scalar("SELECT id FROM item WHERE name=? AND kategorie='verpackung'", [$name]);
        if (!$iid) continue;
        if ((int) scalar("SELECT COUNT(*) FROM etikett_preis WHERE item_id=?", [$iid]) > 0) continue;
        foreach ($staffel as $menge => $gesamt) {
            $stueck = round(((float)$gesamt) / (int)$menge, 4);
            q("INSERT INTO etikett_preis (item_id,menge_ab,ek_gesamt,ek_stueck) VALUES (?,?,?,?)",
              [$iid, (int)$menge, (float)$gesamt, $stueck]);
        }
    }
    meta_set('seed_etikett_preise', '1');
}


// Etikettenformate je Gebinde (Herstellerblatt „Kapselgrößen / Etikettengrößen", Stand 09/2026): Druckdatei-Maß und
// Endformat (B x H mm) am Behälter, dazu je Endformat EIN Etiketten-Artikel (verpackung_rolle='etikett') mit
// EK-Staffel aus den Etikettenpreisen des Gebindes (etikett_preis, Labelisten). Damit findet der Angebots-Editor
// zu jedem Behälter das passende Etikett (passende_etiketten_fuer) und rechnet es mit Preis.
// Läuft genau einmal (Marker), füllt nur leere Felder und legt nur an, was fehlt.
function seed_etikett_formate(): void {
    if (meta_get('seed_etikett_formate', '') === '1') return;
    seed_behaelter_kapazitaet();   // die Gebinde müssen existieren
    seed_etikett_preise();         // … und ihre Etikettenpreise, daraus wird die Staffel des Etiketts
    // Gebinde => [Druckdatei B x H, Endformat B x H] in mm. Braunglas: Etikett innen links voraus.
    $data = [
        '100 ml PET Packer'   => ['62 x 149', '56 x 143'],
        '150 ml PET Packer'   => ['76 x 161', '72 x 155'],
        '200 ml PET Packer'   => ['81 x 185', '75 x 179'],
        '250 ml PET Packer'   => ['78 x 191', '72 x 185'],
        '100 ml Weithalsglas' => ['56 x 159', '50 x 153'],
        '150 ml Weithalsglas' => ['66 x 178', '60 x 172'],
        '200 ml Weithalsglas' => ['73 x 194', '67 x 188'],
        '250 ml Weithalsglas' => ['73 x 206', '67 x 200'],
        '10 ml Braunglas'     => ['78 x 36',  '72 x 30'],
        '30 ml Braunglas'     => ['103 x 51', '97 x 45'],
    ];
    // Die beiden Braungläser (Flüssig) fehlen als Artikel – anlegen, EK bleibt offen (Preis unbekannt).
    $neu = ['10 ml Braunglas' => 10, '30 ml Braunglas' => 30];
    foreach ($data as $name => [$druck, $final]) {
        $iid = (int) scalar("SELECT id FROM item WHERE name=? AND kategorie='verpackung'", [$name]);
        if (!$iid && isset($neu[$name])) {
            q("INSERT INTO item (artikelnummer,name,kategorie,verpackung_rolle,verpackungsart,material,volumen_ml,farbe,einheit,preis_bezug)
               VALUES (?,?,?,?,?,?,?,?,?,?)",
              [naechste_nummer('VP'), $name, 'verpackung', 'primaer', 'flasche', 'Braunglas', $neu[$name], 'braun', 'Stück', 'Stück']);
            $iid = (int) insert_id();
        }
        if (!$iid) continue;
        q("UPDATE item SET etikett_druck=COALESCE(NULLIF(etikett_druck,''),?), etikett_final=COALESCE(NULLIF(etikett_final,''),?) WHERE id=?",
          [$druck, $final, $iid]);
        // Etiketten-Artikel zum Endformat (einer je Maß; passende_etiketten_fuer vergleicht Breite/Höhe auf 2 mm)
        $m = etikett_masse($final);
        if (!$m) continue;
        $eid = (int) scalar("SELECT id FROM item WHERE kategorie='verpackung' AND verpackung_rolle='etikett' AND breite_mm=? AND hoehe_mm=? LIMIT 1", [$m[0], $m[1]]);
        if (!$eid) {
            q("INSERT INTO item (artikelnummer,name,kategorie,verpackung_rolle,verpackungsart,material,etikett_format,breite_mm,hoehe_mm,einheit,preis_bezug)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)",
              [naechste_nummer('VP'), 'Etikett ' . $final . ' mm (' . $name . ')', 'verpackung', 'etikett', 'etikett', 'Papier/Folie', $final . ' mm', $m[0], $m[1], 'Stück', 'Stück']);
            $eid = (int) insert_id();
        }
        // EK-Staffel des Etiketts aus den Etikettenpreisen des Gebindes – nur, wenn der Artikel noch keine hat.
        if ((int) scalar("SELECT COUNT(*) FROM pack_ek_staffel WHERE item_id=?", [$eid]) === 0) {
            $st = etikett_staffel($iid);
            foreach ($st as $s) q("INSERT INTO pack_ek_staffel (item_id,menge_ab,ek_preis) VALUES (?,?,?)", [$eid, (int)$s['menge_ab'], (float)$s['ek_stueck']]);
            if ($st) q("UPDATE item SET ek_preis=? WHERE id=? AND COALESCE(ek_preis,0)=0", [(float)$st[0]['ek_stueck'], $eid]);   // unter der kleinsten Staffel gilt deren Preis
        }
    }
    meta_set('seed_etikett_formate', '1');
}

// Etiketten-Staffel eines Gebindes als Liste [menge_ab, ek_gesamt, ek_stueck].
function etikett_staffel(int $item_id): array {
    return all("SELECT menge_ab, ek_gesamt, ek_stueck FROM etikett_preis WHERE item_id=? ORDER BY menge_ab", [$item_id]);
}

// EK je Etikett für eine Bestellmenge: passende Staffelstufe (höchste menge_ab <= Menge), sonst kleinste Stufe. null = keine Preise.
function etikett_ek_stueck(int $item_id, int $menge): ?float {
    $v = scalar("SELECT ek_stueck FROM etikett_preis WHERE item_id=? AND menge_ab<=? ORDER BY menge_ab DESC LIMIT 1", [$item_id, $menge]);
    if ($v === null || $v === false) $v = scalar("SELECT ek_stueck FROM etikett_preis WHERE item_id=? ORDER BY menge_ab ASC LIMIT 1", [$item_id]);
    return ($v === null || $v === false) ? null : (float)$v;
}

// Standbodenbeutel (Labelisten, Stand 28.08.2026) als Verpackungs-Artikel + EK-Mengenstaffel. Läuft einmal (Marker),
// überschreibt keine Handeingaben (je Beutel nur, wenn Name noch nicht existiert). Preise netto €/Stück inkl. Zipper.
function seed_standbodenbeutel(): void {
    if (meta_get('seed_sbb_beutel', '') === '1') return;
    // [Größe, B, H, T (mm), Volumen ml, €/500, €/1000, €/2500, €/5000]
    $data = [
        ['XS',      90,  100,  60,   50, 0.9928,  0.67505, 0.4799,   0.40735],
        ['S',       90,  160,  60,  100, 0.9928,  0.67505, 0.4799,   0.40735],
        ['S2',     100,  135,  50,  100, 0.68568, 0.5223,  0.419776, 0.3781],
        ['Mshort', 130,  160,  70,  200, 0.74474, 0.58105, 0.477588, 0.43435],
        ['M',      130,  200,  70,  250, 0.74474, 0.58105, 0.477588, 0.43435],
        ['L',      160,  225,  80,  500, 0.85106, 0.6868,  0.581652, 0.5356],
        ['XLshort',180,  250,  90,  750, 0.92192, 0.7573,  0.651024, 0.6031],
        ['XL',     180,  290,  90, 1000, 0.92192, 0.7573,  0.651024, 0.6031],
        ['XXLslim',230,  300, 110, 1250, 1.01642, 0.8513,  0.743524, 0.6931],
        ['XXL',    260,  300, 110, 1500, 1.04006, 0.8748,  0.766648, 0.7156],
    ];
    $tiers = [500, 1000, 2500, 5000];
    foreach ($data as $d) {
        [$g, $b, $h, $t, $vol, $p500, $p1000, $p2500, $p5000] = $d;
        $name = 'Standbodenbeutel ' . $g . ' (' . $vol . ' ml)';
        $iid = (int) scalar("SELECT id FROM item WHERE name=? AND kategorie='verpackung'", [$name]);
        if (!$iid) {
            $maxG = round($vol * 0.55);   // Startwert Füllgewicht (typ. Pulverdichte ~0,55 g/ml), je Beutel anpassbar
            q("INSERT INTO item (artikelnummer,name,kategorie,verpackung_rolle,verpackungsart,material,volumen_ml,max_fuellgewicht_g,breite_mm,hoehe_mm,tiefe_mm,einheit,preis_bezug,ek_preis)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
              [naechste_nummer('VP'), $name, 'verpackung', 'primaer', 'beutel', 'PP-Folie metallic matt',
               $vol, $maxG, $b, $h, $t, 'Stück', 'Stück', $p500]);
            $iid = insert_id();
        }
        if ((int) scalar("SELECT COUNT(*) FROM pack_ek_staffel WHERE item_id=?", [$iid]) === 0) {
            $preise = [$p500, $p1000, $p2500, $p5000];
            foreach ($tiers as $k => $mab)
                q("INSERT INTO pack_ek_staffel (item_id,menge_ab,ek_preis) VALUES (?,?,?)", [$iid, $mab, $preise[$k]]);
        }
    }
    meta_set('seed_sbb_beutel', '1');
}

// Behälter-EK von Packari (Angebotsliste Packari.com GmbH, Stand 31.08.2026): setzt bei den vorhandenen
// PET-Dosen und Weithalsgläsern (100–250 ml) EK + Mengenstaffel und legt die vier Deckel mit
// Pressure-Seal-Einlage als eigene Verschluss-Artikel an. Läuft genau einmal (Marker) und überschreibt
// keine Handeingaben: EK nur, wenn noch 0; Staffel nur, wenn noch keine hinterlegt ist.
function seed_packari_behaelter(): void {
    if (meta_get('seed_packari_behaelter', '') === '1') return;
    seed_behaelter_kapazitaet();   // die Gebinde müssen existieren, bevor ihr EK gesetzt wird
    $lief = (int) scalar("SELECT id FROM lieferanten WHERE firma LIKE 'Packari%' ORDER BY id LIMIT 1");

    // Gebinde, Preise „ohne Verschluss" (netto €/Stück): Name => [Gewinde, Farbe, EK, [menge_ab => EK]]
    $gebinde = [
        '100 ml PET Packer'   => ['38/400', 'braun oder weiß', 0.39, [287=>0.25, 8036=>0.16]],
        '150 ml PET Packer'   => ['38/400', 'braun oder weiß', 0.41, [254=>0.26, 6096=>0.17]],
        '200 ml PET Packer'   => ['45/400', 'braun oder weiß', 0.53, [364=>0.33, 4004=>0.22]],
        '250 ml PET Packer'   => ['45/400', 'braun oder weiß', 0.55, [310=>0.34, 3410=>0.22]],
        '100 ml Weithalsglas' => ['38/400', 'braun',           0.41, [168=>0.25, 5376=>0.18]],
        '150 ml Weithalsglas' => ['45/400', 'braun',           0.43, [156=>0.27, 3900=>0.19]],
        '200 ml Weithalsglas' => ['45/400', 'braun',           0.55, [108=>0.30, 3240=>0.21]],
        '250 ml Weithalsglas' => ['45/400', 'braun',           0.55, [ 70=>0.34, 2520=>0.23]],
    ];
    foreach ($gebinde as $name => [$gewinde, $farbe, $ek, $staffel]) {
        $it = one("SELECT id, ek_preis, volumen_ml, max_fuellgewicht_g, farbe, notiz FROM item WHERE name=? AND kategorie='verpackung'", [$name]);
        if (!$it) continue;
        $iid = (int)$it['id'];
        if ((float)$it['ek_preis'] <= 0)          q("UPDATE item SET ek_preis=? WHERE id=?", [$ek, $iid]);
        if (trim((string)$it['farbe']) === '')    q("UPDATE item SET farbe=? WHERE id=?", [$farbe, $iid]);
        if (trim((string)$it['notiz']) === '')    q("UPDATE item SET notiz=? WHERE id=?",
            ['Packari, Gewinde ' . $gewinde . '. EK ohne Verschluss – der Deckel ist ein eigener Artikel.', $iid]);
        if ($lief) q("UPDATE item SET haupt_lieferant_id=? WHERE id=? AND haupt_lieferant_id IS NULL", [$lief, $iid]);
        // Startwert Füllgewicht (typ. Pulverdichte ~0,55 g/ml) – je Gebinde anpassbar, macht Pulver/Tablette rechenbar
        if ($it['max_fuellgewicht_g'] === null && (float)$it['volumen_ml'] > 0)
            q("UPDATE item SET max_fuellgewicht_g=? WHERE id=?", [round((float)$it['volumen_ml'] * 0.55), $iid]);
        if ((int) scalar("SELECT COUNT(*) FROM pack_ek_staffel WHERE item_id=?", [$iid]) === 0)
            foreach ($staffel as $mab => $p)
                q("INSERT INTO pack_ek_staffel (item_id,menge_ab,ek_preis) VALUES (?,?,?)", [$iid, (int)$mab, $p]);
    }

    // Deckel mit Pressure-Seal-Einlage. Packari verkauft Dose und Deckel nur im Set – der Deckel-EK ist
    // deshalb die Differenz „Set minus Dose ohne Verschluss" der in der Notiz genannten Dose.
    $deckel = [
        ['Schraubverschluss 38/400 weiß, Pressure Seal',    '38/400', 'weiß',    0.22, [287=>0.15, 8036=>0.09], '100 ml PET Packer'],
        ['Schraubverschluss 38/400 schwarz, Pressure Seal', '38/400', 'schwarz', 0.27, [254=>0.16, 6096=>0.11], '150 ml PET Packer'],
        ['Schraubverschluss 45/400 weiß, Pressure Seal',    '45/400', 'weiß',    0.26, [310=>0.13, 3410=>0.13], '250 ml PET Packer'],
        ['Schraubverschluss 45/400 schwarz, Pressure Seal', '45/400', 'schwarz', 0.28, [364=>0.14, 4004=>0.13], '200 ml PET Packer'],
    ];
    foreach ($deckel as [$name, $gewinde, $farbe, $ek, $staffel, $quelle]) {
        $iid = (int) scalar("SELECT id FROM item WHERE name=? AND kategorie='verpackung'", [$name]);
        if (!$iid) {
            q("INSERT INTO item (artikelnummer,name,kategorie,verpackung_rolle,material,farbe,einheit,preis_bezug,ek_preis,haupt_lieferant_id,notiz)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)",
              [naechste_nummer('VP'), $name, 'verpackung', 'verschluss', 'PP', $farbe, 'Stück', 'Stück', $ek, $lief ?: null,
               'Packari, Gewinde ' . $gewinde . ', druckempfindliche Dichteinlage (Pressure Seal). EK abgeleitet: Set-Preis minus ' . $quelle . ' ohne Verschluss.']);
            $iid = insert_id();
        }
        if ((int) scalar("SELECT COUNT(*) FROM pack_ek_staffel WHERE item_id=?", [$iid]) === 0)
            foreach ($staffel as $mab => $p)
                q("INSERT INTO pack_ek_staffel (item_id,menge_ab,ek_preis) VALUES (?,?,?)", [$iid, (int)$mab, $p]);
    }
    meta_set('seed_packari_behaelter', '1');
}

// Kapsel-Fassung einer Verpackung als [kapselgroesse_id => stueck].
function pack_kapazitaet_fuer(int $item_id): array {
    $out = [];
    foreach (all("SELECT kapselgroesse_id, stueck FROM pack_kapazitaet WHERE item_id=?", [$item_id]) as $r)
        $out[(int)$r['kapselgroesse_id']] = (int)$r['stueck'];
    return $out;
}

// Kapselgröße einer Kapsel-Rezeptur: bevorzugt die AM REZEPT gespeicherte Größe (kapselgroesse_id),
// sonst die kleinste Größe, in die das Füllgewicht je Kapsel passt. Gibt Zeile aus kapselgroesse oder null.
// Etikett-Druckvorlage eines Produkts (am Etikett-Artikel als verpackung_dokument kategorie='druckvorlage').
function etikett_druckvorlage_datei(int $produkt_id): ?array {
    if ($produkt_id <= 0) return null;
    $p = one("SELECT etikett_id, verpackung_id FROM produkt WHERE id=?", [$produkt_id]);
    if (!$p) return null;
    $eids = [];
    if (!empty($p['etikett_id'])) $eids[] = (int)$p['etikett_id'];
    // Kein direkt verknuepftes Etikett? Dann den Etikett-Artikel ueber die Groesse des Behaelters
    // (item.etikett_final, B x H) finden – so genuegt EINE Druckvorlage je Etikettgroesse.
    if (!$eids && !empty($p['verpackung_id'])) {
        $m = etikett_masse((string) scalar("SELECT etikett_final FROM item WHERE id=?", [(int)$p['verpackung_id']]));
        if ($m) foreach (all("SELECT id, breite_mm, hoehe_mm FROM item WHERE kategorie='verpackung' AND verpackung_rolle='etikett' AND gesperrt=0") as $e) {
            if ((float)$e['breite_mm'] && (float)$e['hoehe_mm'] && abs((float)$e['breite_mm'] - $m[0]) <= 2 && abs((float)$e['hoehe_mm'] - $m[1]) <= 2) $eids[] = (int)$e['id'];
        }
    }
    if (!$eids) return null;
    return one("SELECT * FROM verpackung_dokument WHERE item_id IN (" . implode(',', array_map('intval', $eids)) . ") AND kategorie='druckvorlage' ORDER BY id DESC LIMIT 1");
}
// Kapsel-Referenztabelle (Nachschlagewerk): je Größe die Standardwerte.
// [Fuellgewicht 0.45 leicht, 0.70 typisch, 1.00 dicht (mg)], Volumen ml, Verschlusslänge mm,
// Kappe [Außen-Ø, Schnittlänge, Wandstärke], Körper [Außen-Ø, Schnittlänge, Wandstärke], Leergewicht Ø/100 (mg).
function kapsel_referenz_tabelle(): array {
    return [
        '000' => ['fill'=>[615,960,1370], 'vol'=>1.37, 'lock'=>26.14, 'cap'=>[9.91,12.95,0.112], 'body'=>[9.55,22.20,0.110], 'leer'=>163],
        '00'  => ['fill'=>[430,665,950],  'vol'=>0.95, 'lock'=>23.30, 'cap'=>[8.53,11.74,0.109], 'body'=>[8.18,20.22,0.107], 'leer'=>118],
        '0'   => ['fill'=>[305,475,680],  'vol'=>0.68, 'lock'=>21.70, 'cap'=>[7.65,10.72,0.107], 'body'=>[7.34,18.44,0.104], 'leer'=>96],
        '1'   => ['fill'=>[225,350,500],  'vol'=>0.50, 'lock'=>19.40, 'cap'=>[6.91,9.78,0.104],  'body'=>[6.63,16.61,0.102], 'leer'=>76],
        '2'   => ['fill'=>[165,260,370],  'vol'=>0.37, 'lock'=>18.00, 'cap'=>[6.35,8.94,0.102],  'body'=>[6.07,15.27,0.099], 'leer'=>61],
        '3'   => ['fill'=>[135,210,300],  'vol'=>0.30, 'lock'=>15.90, 'cap'=>[5.82,8.08,0.092],  'body'=>[5.56,13.59,0.089], 'leer'=>48],
        '4'   => ['fill'=>[95,145,210],   'vol'=>0.21, 'lock'=>14.30, 'cap'=>[5.31,7.21,0.096],  'body'=>[5.05,12.19,0.091], 'leer'=>38],
        '5'   => ['fill'=>[60,90,130],    'vol'=>0.13, 'lock'=>11.10, 'cap'=>[4.91,6.20,0.089],  'body'=>[4.68,9.32,0.086],  'leer'=>28],
    ];
}
// Referenzdaten in die kapselgroesse-Tabelle uebernehmen (nur wo noch leer – Team-Werte bleiben erhalten).
function seed_kapsel_referenz(): void {
    $ref = kapsel_referenz_tabelle();
    foreach (all("SELECT id, name FROM kapselgroesse") as $k) {
        if (!preg_match('/(\d+)\s*$/', trim((string)$k['name']), $m) || !isset($ref[$m[1]])) continue;
        $r = $ref[$m[1]];
        q("UPDATE kapselgroesse SET leergewicht_mg=COALESCE(leergewicht_mg,?), volumen_ml=COALESCE(volumen_ml,?),
             fuell_light_mg=COALESCE(fuell_light_mg,?), fuell_typ_mg=COALESCE(fuell_typ_mg,?), fuell_heavy_mg=COALESCE(fuell_heavy_mg,?) WHERE id=?",
          [$r['leer'], $r['vol'], $r['fill'][0], $r['fill'][1], $r['fill'][2], (int)$k['id']]);
    }
}
// Kompatibilitaet: alter Name ruft jetzt die vollstaendige Referenz-Befuellung.
function seed_kapsel_leergewicht(): void { seed_kapsel_referenz(); }

// Mittlere (Schuett-)Dichte einer Rezeptur in g/ml aus den Rohstoff-Dichten (massegewichtet). null, wenn nichts hinterlegt.
function rezeptur_mix_dichte(int $rezeptur_id): ?float {
    $mSum = 0.0; $vSum = 0.0;
    foreach (all("SELECT z.menge_mg, i.dichte FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id
                  WHERE z.rezeptur_id=? AND i.dichte IS NOT NULL AND i.dichte>0", [$rezeptur_id]) as $r) {
        $m = (float)$r['menge_mg']; $d = (float)$r['dichte'];
        if ($m <= 0 || $d <= 0) continue;
        $mSum += $m; $vSum += $m / $d;
    }
    return $vSum > 0 ? $mSum / $vSum : null;   // g/ml
}
// Fuellkapazitaet einer Kapselgröße in mg. Mit bekannter Dichte: Volumen × Dichte. Sonst Backup: fuellmenge_mg.
function kapsel_kapazitaet_mg(array $kg, ?float $dichte): float {
    $vol = (float)($kg['volumen_ml'] ?? 0);
    if ($dichte !== null && $dichte > 0 && $vol > 0) return $vol * $dichte * 1000.0;
    return (float)($kg['fuellmenge_mg'] ?? 0);   // Backup, wenn keine Dichte/Volumen
}
function rezeptur_kapselgroesse(int $rezeptur_id): ?array {
    $gid = (int) scalar("SELECT kapselgroesse_id FROM rezeptur WHERE id=?", [$rezeptur_id]);
    if ($gid > 0) { $kg = one("SELECT * FROM kapselgroesse WHERE id=?", [$gid]); if ($kg) return $kg; }
    seed_kapsel_referenz();
    $weight = (float) scalar("SELECT COALESCE(SUM(menge_mg),0) FROM rezeptur_zutat WHERE rezeptur_id=?", [$rezeptur_id]);
    if ($weight <= 0) return null;
    $dichte = rezeptur_mix_dichte($rezeptur_id);   // g/ml oder null -> Backup ueber fuellmenge_mg
    foreach (all("SELECT * FROM kapselgroesse ORDER BY fuellmenge_mg ASC") as $kg)
        if (kapsel_kapazitaet_mg($kg, $dichte) + 0.001 >= $weight) return $kg;
    return null;   // passt in keine Standardgröße
}

// ===== Health Claims (EU-VO 432/2012) =====
// Aktive Claims eines Naehrstoffs.
function health_claims_naehrstoff(int $naehrstoff_id): array {
    if ($naehrstoff_id <= 0) return [];
    return all("SELECT * FROM health_claim WHERE naehrstoff_id=? AND aktiv=1 ORDER BY sort, id", [$naehrstoff_id]);
}
// Zugelassene Claims fuer die Naehrstoffe einer Rezeptur (ueber die verknuepften Rohstoff-Wirkstoffe).
// Rueckgabe: [['stoff'=>Name, 'claim'=>Text, 'bedingung'=>...], ...] nach Naehrstoffname sortiert.
function health_claims_fuer_rezeptur(int $rezeptur_id): array {
    if ($rezeptur_id <= 0) return [];
    $nids = array_column(all("SELECT DISTINCT na.id FROM rezeptur_zutat z
                              JOIN item_wirkstoff iw ON iw.item_id=z.item_id
                              JOIN naehrstoff na ON na.id=iw.naehrstoff_id
                              WHERE z.rezeptur_id=?", [$rezeptur_id]), 'id');
    if (!$nids) return [];
    $in = implode(',', array_map('intval', $nids));
    return all("SELECT hc.claim, hc.bedingung, na.name AS stoff FROM health_claim hc
                JOIN naehrstoff na ON na.id=hc.naehrstoff_id
                WHERE hc.naehrstoff_id IN ($in) AND hc.aktiv=1 ORDER BY na.name, hc.sort, hc.id");
}
// Startsatz gaengiger Standard-Wortlaute (EU 432/2012). Nur wenn die Tabelle leer ist. Vom Team zu pruefen/ergaenzen.
function seed_health_claims_if_empty(): void {
    if ((int) scalar("SELECT COUNT(*) FROM health_claim") > 0) return;
    $seed = [
        'Vitamin C'   => ['Vitamin C trägt zu einer normalen Funktion des Immunsystems bei.', 'Vitamin C trägt zur Verringerung von Müdigkeit und Ermüdung bei.', 'Vitamin C erhöht die Eisenaufnahme.'],
        'Vitamin D'   => ['Vitamin D trägt zu einer normalen Funktion des Immunsystems bei.', 'Vitamin D trägt zur Erhaltung normaler Knochen bei.', 'Vitamin D trägt zu einer normalen Muskelfunktion bei.'],
        'Magnesium'   => ['Magnesium trägt zu einer normalen Muskelfunktion bei.', 'Magnesium trägt zur Verringerung von Müdigkeit und Ermüdung bei.', 'Magnesium trägt zu einer normalen Funktion des Nervensystems bei.'],
        'Zink'        => ['Zink trägt zu einer normalen Funktion des Immunsystems bei.', 'Zink trägt zum Schutz der Zellen vor oxidativem Stress bei.'],
        'Eisen'       => ['Eisen trägt zur Verringerung von Müdigkeit und Ermüdung bei.', 'Eisen trägt zu einem normalen Sauerstofftransport im Körper bei.'],
        'Calcium'     => ['Calcium trägt zur Erhaltung normaler Knochen bei.', 'Calcium wird für die Erhaltung normaler Zähne benötigt.'],
        'Vitamin B12' => ['Vitamin B12 trägt zur Verringerung von Müdigkeit und Ermüdung bei.', 'Vitamin B12 trägt zu einer normalen Funktion des Nervensystems bei.'],
        'Vitamin B6'  => ['Vitamin B6 trägt zu einem normalen Energiestoffwechsel bei.', 'Vitamin B6 trägt zu einer normalen Funktion des Immunsystems bei.'],
        'Folsäure'    => ['Folat trägt zur normalen Blutbildung bei.', 'Folat trägt zur Verringerung von Müdigkeit und Ermüdung bei.'],
        'Biotin'      => ['Biotin trägt zur Erhaltung normaler Haare bei.', 'Biotin trägt zur Erhaltung normaler Haut bei.'],
    ];
    $bed = 'Zulässig, wenn eine signifikante Menge (mind. 15 % NRV je Tagesdosis) enthalten ist.';
    foreach ($seed as $name => $claims) {
        $nid = (int) scalar("SELECT id FROM naehrstoff WHERE name=? LIMIT 1", [$name]);
        $sort = 0;
        foreach ($claims as $c)
            q("INSERT INTO health_claim (naehrstoff_id, stoff, claim, bedingung, quelle, sort) VALUES (?,?,?,?, 'EU 432/2012', ?)",
              [$nid ?: null, $name, $c, $bed, $sort++]);
    }
}

// Wie viele Kapseln der Kapselgröße dieser Rezeptur passen höchstens in eine (Standard-)Verpackung?
// = größte hinterlegte Kapazität (pack_kapazitaet) über alle Behälter. 0 = keine Angabe/keine Kapselform.
function kapsel_max_stueck_je_verpackung(int $rezeptur_id): int {
    $kg = rezeptur_kapselgroesse($rezeptur_id);
    if (!$kg) return 0;
    return (int) scalar("SELECT COALESCE(MAX(stueck),0) FROM pack_kapazitaet WHERE kapselgroesse_id=?", [(int)$kg['id']]);
}

// Welche Leerkapseln (Rohstoff, form=kapselhuelle) passen zur Kapselgröße eines Produkts? (für die Auto-Wahl / Auswahl bei Mehrdeutigkeit)
function produkt_leerkapsel_kandidaten(int $produkt_id): array {
    $p = one("SELECT rezeptur_id FROM produkt WHERE id=?", [$produkt_id]);
    if (!$p || !$p['rezeptur_id']) return [];
    if (($rz = one("SELECT darreichungsform FROM rezeptur WHERE id=?", [$p['rezeptur_id']])) === null || $rz['darreichungsform'] !== 'kapsel') return [];
    $kg = rezeptur_kapselgroesse((int)$p['rezeptur_id']);
    if (!$kg) return [];
    return all("SELECT id, name, kapselgroesse_id, leergewicht_mg FROM item
                WHERE kategorie='rohstoff' AND form='kapselhuelle' AND kapselgroesse_id=? AND gesperrt=0 ORDER BY id", [(int)$kg['id']]);
}

// Effektive Leerkapsel eines Produkts: manuelle Wahl (leerkapsel_id) hat Vorrang, sonst eindeutiger Größen-Treffer. null wenn nicht bestimmbar/mehrdeutig.
function produkt_leerkapsel_id(int $produkt_id): ?int {
    $manuell = scalar("SELECT leerkapsel_id FROM produkt WHERE id=?", [$produkt_id]);
    if ($manuell) return (int)$manuell;
    $k = produkt_leerkapsel_kandidaten($produkt_id);
    return count($k) === 1 ? (int)$k[0]['id'] : null;   // nur bei Eindeutigkeit automatisch
}

// Anzeige-Groesse fuer die Produktion: bei Kapsel/Softgel die Kapselgroesse (gepflegt an der
// Rezeptur, sonst aus dem Fuellgewicht berechnet = kleinste passende Kapsel); bei Tablette das
// Fuellgewicht in mg (echte Tablettengroesse kennt das Schema nicht). Sonst leer.
// $kurz=true kürzt „(berechnet)" zu „(b)" – für schmale Listen-Tabellen.
function produktion_groesse_label(int $produkt_id, bool $kurz = false): string {
    if ($produkt_id <= 0) return '';
    // Grunddaten (Form/Kapselgröße/Füllgewicht) – in der Liste per Bulk vorgeladen (Cache 'grl:'.id),
    // sonst Einzelabfrage. Spart je angezeigter Zeile eine Abfrage.
    if (isset($GLOBALS['bx_stock_cache']) && array_key_exists('grl:' . $produkt_id, $GLOBALS['bx_stock_cache'])) {
        $p = $GLOBALS['bx_stock_cache']['grl:' . $produkt_id];
    } else {
        $p = one("SELECT r.darreichungsform AS form, kg.name AS kapsel_name,
                         (SELECT COALESCE(SUM(z.menge_mg),0) FROM rezeptur_zutat z WHERE z.rezeptur_id=r.id) AS fg
                  FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
                  LEFT JOIN kapselgroesse kg ON kg.id=r.kapselgroesse_id WHERE p.id=?", [$produkt_id]);
        if (isset($GLOBALS['bx_stock_cache'])) $GLOBALS['bx_stock_cache']['grl:' . $produkt_id] = $p;
    }
    if (!$p) return '';
    $form = (string)($p['form'] ?? '');
    $fg   = (float)($p['fg'] ?? 0);
    if (in_array($form, ['kapsel', 'softgel'], true)) {
        if (!empty($p['kapsel_name'])) return (string)$p['kapsel_name'];
        if ($fg > 0) {
            $k = one("SELECT name FROM kapselgroesse WHERE fuellmenge_mg >= ? ORDER BY fuellmenge_mg ASC LIMIT 1", [$fg]);
            return $k ? $k['name'] . ($kurz ? ' (b)' : ' (berechnet)') : 'größer als größte Kapsel';
        }
        return '';
    }
    if ($form === 'tablette') return $fg > 0 ? '≈ ' . number_format($fg, 0, ',', '.') . ' mg' : '';
    return '';
}

// Kunde KOMPLETT entfernen: der Kunde und alle an ihm hängenden Vorgänge (Angebote, Aufträge,
// Belege, Produktionsaufträge, Rezepturen, Anfragen, Produkte, CRM) inkl. deren Unterzeilen.
// Es gibt keine echten Foreign Keys, deshalb wird jede Kind-Tabelle gezielt geleert.
// Sicherheitsstopp: produzierte Chargen (Lagerbezug/Rückverfolgung) werden NICHT blind gelöscht.
// Läuft in einer Transaktion (alles oder nichts). Rückgabe: ['ok'=>bool, 'geloescht'=>int] oder ['ok'=>false,'fehler'=>…].
// Einen Auftrag vollständig entfernen: der Auftrag selbst plus alles, was ausschließlich an ihm hängt
// (Produktionsauftrag/-schritte/-verbrauch, dessen Fertigware-Chargen, Rechnung/Lieferschein, Verlauf).
// Gemeinsame Daten bleiben: Lieferantenbestellungen werden nur vom Auftrag GELÖST (auftrag_id=NULL),
// nicht gelöscht. Gezielt per ID, kein pauschales DELETE. Rückgabe true, wenn der Auftrag existierte.
function auftrag_komplett_loeschen(int $auftrag_id): bool {
    if ($auftrag_id <= 0) return false;
    if (!one("SELECT id FROM auftrag WHERE id=?", [$auftrag_id])) return false;
    $paids = array_map('intval', array_column(all("SELECT id FROM produktionsauftrag WHERE auftrag_id=?", [$auftrag_id]), 'id'));
    if ($paids) {
        $in = implode(',', $paids);
        q("DELETE FROM produktion_schritt   WHERE pa_id IN ($in)");
        q("DELETE FROM produktion_verbrauch WHERE pa_id IN ($in)");
        q("DELETE FROM charge               WHERE pa_id IN ($in)");
        q("DELETE FROM produktionsauftrag   WHERE id IN ($in)");
    }
    q("DELETE FROM charge WHERE auftrag_id=?", [$auftrag_id]);
    q("DELETE FROM beleg  WHERE auftrag_id=?", [$auftrag_id]);                     // Rechnung/Lieferschein dieses Auftrags
    q("UPDATE bestellung_position SET auftrag_id=NULL WHERE auftrag_id=?", [$auftrag_id]);  // Bestellungen bleiben erhalten
    q("DELETE FROM aktivitaet WHERE objekt_typ='auftrag' AND objekt_id=?", [$auftrag_id]);
    q("DELETE FROM auftrag WHERE id=?", [$auftrag_id]);
    return true;
}

// Auftrag(sbestaetigung) loeschen und zurueck zur Anfrage: raeumt Produktion/Rechnung mit auf
// (auftrag_komplett_loeschen), setzt das zugehoerige Angebot von 'bestaetigt' zurueck auf 'gesendet'
// (Staffel-Haken zurueck) und liefert die Anfrage-/Angebots-ID fuer den Ruecksprung.
// Geblockt bei bezahlter Rechnung oder bereits versendetem Auftrag. Rueckgabe:
// ['ok'=>true,'anfrage_id'=>…,'angebot_id'=>…] | ['ok'=>false,'fehler'=>…].
function auftrag_zurueck_und_loeschen(int $auftrag_id): array {
    $a = one("SELECT id, nummer, angebot_id, kunde_id, status FROM auftrag WHERE id=?", [$auftrag_id]);
    if (!$a) return ['ok' => false, 'fehler' => 'Auftrag nicht gefunden.'];
    if ((string)$a['status'] === 'versendet') return ['ok' => false, 'fehler' => 'Auftrag ist bereits versendet – nicht mehr zurückholbar.'];
    $bezahlt = (int) scalar("SELECT COUNT(*) FROM beleg WHERE auftrag_id=? AND typ='rechnung' AND status='bezahlt'", [$auftrag_id]);
    if ($bezahlt > 0) return ['ok' => false, 'fehler' => 'Zu diesem Auftrag gibt es eine bezahlte Rechnung – bitte erst in der Buchhaltung klären.'];
    $angId = (int)($a['angebot_id'] ?? 0);
    $anfId = $angId ? (int) scalar("SELECT anfrage_id FROM angebot WHERE id=?", [$angId]) : 0;
    $angNr = $angId ? (string) scalar("SELECT nummer FROM angebot WHERE id=?", [$angId]) : '';
    auftrag_komplett_loeschen($auftrag_id);
    if ($angId) {
        q("UPDATE angebot SET status='gesendet', preise_kunde=1 WHERE id=? AND status='bestaetigt'", [$angId]);
        q("UPDATE angebot_staffel SET bestaetigt=0 WHERE angebot_id=?", [$angId]);
    }
    if ($anfId) q("UPDATE portal_anfrage SET status='beantwortet' WHERE id=? AND status<>'abgelehnt'", [$anfId]);
    if (!empty($a['kunde_id']) && function_exists('log_aktivitaet'))
        log_aktivitaet('kunde', (int)$a['kunde_id'], 'team', 'Auftragsbestätigung ' . (string)$a['nummer'] . ' gelöscht – zurück zur Anfrage' . ($angNr ? ' (Angebot ' . $angNr . ' wieder offen)' : '') . '.', 'auftrag', 'angebot', $angId ?: (int)$a['id']);
    return ['ok' => true, 'anfrage_id' => $anfId, 'angebot_id' => $angId];
}

function kunde_komplett_loeschen(int $kid): array {
    if ($kid <= 0) return ['ok' => false, 'fehler' => 'Ungültige Kunden-ID.'];
    if (!one("SELECT id FROM kunden WHERE id=?", [$kid])) return ['ok' => false, 'fehler' => 'Kunde nicht gefunden.'];

    $idl = function(string $sql) use ($kid): array {
        return array_map('intval', array_column(all($sql, [$kid]), 'id'));
    };
    $angebote  = $idl("SELECT id FROM angebot WHERE kunde_id=?");
    $auftraege = $idl("SELECT id FROM auftrag WHERE kunde_id=?");
    $pas       = $idl("SELECT id FROM produktionsauftrag WHERE kunde_id=?");
    $rezepte   = $idl("SELECT id FROM rezeptur WHERE kunde_id=?");
    $panfr     = $idl("SELECT id FROM portal_anfrage WHERE kunde_id=?");
    $ranfr     = $idl("SELECT id FROM rezeptur_anfrage WHERE kunde_id=?");
    $kontakte  = $idl("SELECT id FROM crm_kontakt WHERE kunde_id=?");
    $belege    = array_values(array_unique(array_merge(
        array_map('intval', array_column(all("SELECT id FROM beleg WHERE kunde_id=?", [$kid]), 'id')),
        $auftraege ? array_map('intval', array_column(all("SELECT id FROM beleg WHERE auftrag_id IN (" . implode(',', $auftraege) . ")"), 'id')) : []
    )));

    // Sicherheitsstopp: echte Chargen bedeuten Lagerbestand + Rückverfolgung – die nie blind löschen.
    $chargen = 0;
    if ($auftraege) $chargen += (int) scalar("SELECT COUNT(*) FROM charge WHERE auftrag_id IN (" . implode(',', $auftraege) . ")");
    if ($pas)       $chargen += (int) scalar("SELECT COUNT(*) FROM charge WHERE pa_id IN (" . implode(',', $pas) . ")");
    if ($chargen > 0) return ['ok' => false, 'fehler' => 'Dieser Kunde hat ' . $chargen . ' produzierte Charge(n) mit Lagerbezug. Bitte erst im Lager/den Chargen bereinigen – dann erneut löschen.'];

    $in = fn(array $a) => implode(',', array_map('intval', $a));
    $del = 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $x = function(string $sql) use (&$del) { $del += q($sql)->rowCount(); };
        if ($angebote)  { $s = $in($angebote);  $x("DELETE FROM angebot_position WHERE angebot_id IN ($s)"); $x("DELETE FROM angebot_produkt WHERE angebot_id IN ($s)"); $x("DELETE FROM angebot_staffel WHERE angebot_id IN ($s)"); }
        if ($auftraege) { $s = $in($auftraege); $x("DELETE FROM bestellung_position WHERE auftrag_id IN ($s)"); $x("DELETE FROM reservierung WHERE auftrag_id IN ($s)"); }
        if ($pas)       { $s = $in($pas);       $x("DELETE FROM produktion_schritt WHERE pa_id IN ($s)"); $x("DELETE FROM produktion_verbrauch WHERE pa_id IN ($s)"); $x("DELETE FROM reservierung WHERE pa_id IN ($s)"); }
        if ($belege)    { $s = $in($belege);    $x("DELETE FROM beleg_status_log WHERE beleg_id IN ($s)"); $x("DELETE FROM zahlung WHERE beleg_id IN ($s)"); }
        if ($rezepte)   { $s = $in($rezepte);   $x("DELETE FROM rezeptur_zutat WHERE rezeptur_id IN ($s)"); $x("DELETE FROM portal_anfrage_pos WHERE rezeptur_id IN ($s)"); }
        if ($panfr)     { $s = $in($panfr);     $x("DELETE FROM portal_anfrage_pos WHERE anfrage_id IN ($s)"); }
        if ($ranfr)     { $s = $in($ranfr);     $x("DELETE FROM rezeptur_anfrage_wunsch WHERE anfrage_id IN ($s)"); }
        if ($kontakte)  { $s = $in($kontakte);  $x("DELETE FROM crm_verlauf WHERE kontakt_id IN ($s)"); }
        // Direkt am Kunden hängende Tabellen
        foreach (['angebot','auftrag','beleg','produktionsauftrag','rezeptur','portal_anfrage','rezeptur_anfrage','produkt','produkt_kundenpreis','kunde_marke','crm_kontakt','crm_verlauf'] as $t)
            $x("DELETE FROM $t WHERE kunde_id=$kid");
        $x("DELETE FROM kunden WHERE id=$kid");
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'fehler' => 'Löschen abgebrochen: ' . $e->getMessage()];
    }
    return ['ok' => true, 'geloescht' => $del];
}

// Zwei Kunden zusammenführen (Dublette): ALLE Verweise von $quelle_id auf $ziel_id umhängen, dann den
// doppelten Quell-Kunden löschen. Dynamisch über alle Spalten, deren Name auf „kunde_id" endet (kunde_id,
// fremd_kunde_id, rechnung_kunde_id …) – so wandern auch künftige Tabellen automatisch mit. In einer
// Transaktion; bei Fehler wird ALLES zurückgerollt (kein Teil-Merge). Tabellen-/Spaltennamen stammen aus dem
// Schema (information_schema), nicht aus Nutzereingaben. Rückgabe ['ok','moved','quelle','ziel'|'fehler'].
function kunde_zusammenfuehren(int $quelle_id, int $ziel_id): array {
    if ($quelle_id <= 0 || $ziel_id <= 0) return ['ok'=>false, 'fehler'=>'Ungültige Kunden-ID.'];
    if ($quelle_id === $ziel_id)          return ['ok'=>false, 'fehler'=>'Quelle und Ziel sind derselbe Kunde.'];
    $q = one("SELECT id, firma FROM kunden WHERE id=?", [$quelle_id]);
    $z = one("SELECT id, firma FROM kunden WHERE id=?", [$ziel_id]);
    if (!$q || !$z) return ['ok'=>false, 'fehler'=>'Einer der Kunden wurde nicht gefunden.'];

    // Nur LIVE-Tabellen: Import-/Staging-Tabellen (v3imp_*, bu_imp_*) haben einen EIGENEN ID-Raum – ihr
    // kunde_id zeigt NICHT auf die Live-Kunden, die würden wir sonst korrumpieren. Ausschluss per Präfix.
    // ESCAPE '=' statt Backslash (Backslash-Escape crasht die Live-MySQL).
    $cols = all("SELECT TABLE_NAME AS t, COLUMN_NAME AS c
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND COLUMN_NAME LIKE '%kunde_id'
                   AND TABLE_NAME <> 'kunden'
                   AND TABLE_NAME NOT LIKE 'v3imp=_%' ESCAPE '='
                   AND TABLE_NAME NOT LIKE 'bu=_imp=_%' ESCAPE '='");
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $moved = [];
        foreach ($cols as $cc) {
            $t = (string)$cc['t']; $c = (string)$cc['c'];
            $n = q("UPDATE `$t` SET `$c`=? WHERE `$c`=?", [$ziel_id, $quelle_id])->rowCount();
            if ($n > 0) $moved[$t . '.' . $c] = $n;
        }
        q("DELETE FROM kunden WHERE id=?", [$quelle_id]);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false, 'fehler'=>'Zusammenführen abgebrochen (nichts geändert): ' . $e->getMessage()];
    }
    if (function_exists('log_aktivitaet'))
        log_aktivitaet('kunde', $ziel_id, 'team', 'Doppelten Kunden „' . (string)$q['firma'] . '" (ID ' . $quelle_id . ') hierher zusammengeführt.', 'merge');
    return ['ok'=>true, 'moved'=>$moved, 'quelle'=>(string)$q['firma'], 'ziel'=>(string)$z['firma']];
}

// Zwei Lieferanten zusammenführen (Dublette): ALLE Verweise von $quelle_id auf $ziel_id umhängen, dann den
// doppelten Quell-Lieferanten löschen. Dynamisch über alle Spalten, deren Name auf „lieferant_id" endet
// (lieferant_id, haupt_lieferant_id …) – so wandern auch item.haupt_lieferant_id und künftige Tabellen mit.
// In einer Transaktion (bei Fehler komplett Rollback). Import-/Staging-Tabellen (v3imp_*, bu_imp_*) sind
// ausgeschlossen (eigener ID-Raum). Keine UNIQUE-Keys auf Lieferant-Spalten in Live-Tabellen -> kollisionsfrei.
// ESCAPE '=' statt Backslash (Backslash-Escape crasht die Live-MySQL).
function lieferant_zusammenfuehren(int $quelle_id, int $ziel_id): array {
    if ($quelle_id <= 0 || $ziel_id <= 0) return ['ok'=>false, 'fehler'=>'Ungültige Lieferanten-ID.'];
    if ($quelle_id === $ziel_id)          return ['ok'=>false, 'fehler'=>'Quelle und Ziel sind derselbe Lieferant.'];
    $q = one("SELECT id, firma FROM lieferanten WHERE id=?", [$quelle_id]);
    $z = one("SELECT id, firma FROM lieferanten WHERE id=?", [$ziel_id]);
    if (!$q || !$z) return ['ok'=>false, 'fehler'=>'Einer der Lieferanten wurde nicht gefunden.'];

    $cols = all("SELECT TABLE_NAME AS t, COLUMN_NAME AS c
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND COLUMN_NAME LIKE '%lieferant_id'
                   AND TABLE_NAME <> 'lieferanten'
                   AND TABLE_NAME NOT LIKE 'v3imp=_%' ESCAPE '='
                   AND TABLE_NAME NOT LIKE 'bu=_imp=_%' ESCAPE '='");
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $moved = [];
        foreach ($cols as $cc) {
            $t = (string)$cc['t']; $c = (string)$cc['c'];
            $n = q("UPDATE `$t` SET `$c`=? WHERE `$c`=?", [$ziel_id, $quelle_id])->rowCount();
            if ($n > 0) $moved[$t . '.' . $c] = $n;
        }
        q("DELETE FROM lieferanten WHERE id=?", [$quelle_id]);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false, 'fehler'=>'Zusammenführen abgebrochen (nichts geändert): ' . $e->getMessage()];
    }
    if (function_exists('log_aktivitaet'))
        log_aktivitaet('lieferant', $ziel_id, 'team', 'Doppelten Lieferanten „' . (string)$q['firma'] . '" (ID ' . $quelle_id . ') hierher zusammengeführt.', 'merge');
    return ['ok'=>true, 'moved'=>$moved, 'quelle'=>(string)$q['firma'], 'ziel'=>(string)$z['firma']];
}

// Lieferant/Partner KOMPLETT löschen (nur Admin, unwiderruflich) – inkl. ALLER Preise, Anfragen, Angebote,
// Bestellungen, Preislisten, Kataloge, Dokumente, Portal-Login und Kreditoren-Rechnungen. Sicherheitsstopp:
// produzierte/eingebuchte Chargen mit Bezug zu diesem Lieferanten (Lagerbestand/Rückverfolgung) blockieren.
// item.haupt_lieferant_id wird nur gelöst (Artikel/Bestand bleiben). Rückgabe wie kunde_komplett_loeschen.
function lieferant_komplett_loeschen(int $lid): array {
    if ($lid <= 0) return ['ok' => false, 'fehler' => 'Ungültige Lieferanten-ID.'];
    $firma = (string) scalar("SELECT firma FROM lieferanten WHERE id=?", [$lid]);
    if ($firma === '' && !one("SELECT id FROM lieferanten WHERE id=?", [$lid])) return ['ok' => false, 'fehler' => 'Lieferant nicht gefunden.'];

    // Sicherheitsstopp: echte Chargen mit Lieferantenbezug (Wareneingang) bedeuten Lagerbestand – nie blind löschen.
    $chargen = table_exists('charge') ? (int) scalar("SELECT COUNT(*) FROM charge WHERE lieferant_id=?", [$lid]) : 0;
    if ($chargen > 0) return ['ok' => false, 'fehler' => 'Dieser Lieferant hat ' . $chargen . ' eingebuchte Charge(n) mit Lagerbezug. Bitte erst im Lager/den Chargen bereinigen – dann erneut löschen.'];

    $del = 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // $x: DELETE nur, wenn die Tabelle existiert (Sub-Apps/Buchhaltung ggf. nicht initialisiert).
        $x = function(string $tabelle, string $wo) use (&$del, $lid) {
            if (!table_exists($tabelle)) return;
            $del += q("DELETE FROM $tabelle WHERE $wo", [$lid])->rowCount();
        };
        // Staffeln zuerst (hängen an den Angeboten dieses Lieferanten).
        if (table_exists('rezeptur_lief_angebot_staffel') && table_exists('rezeptur_lief_angebot'))
            $del += q("DELETE FROM rezeptur_lief_angebot_staffel WHERE angebot_id IN (SELECT id FROM rezeptur_lief_angebot WHERE lieferant_id=?)", [$lid])->rowCount();
        if (table_exists('lieferant_angebot_staffel') && table_exists('lieferant_angebot'))
            $del += q("DELETE FROM lieferant_angebot_staffel WHERE angebot_id IN (SELECT id FROM lieferant_angebot WHERE lieferant_id=?)", [$lid])->rowCount();
        // Bestellpositionen vor den Bestellungen.
        if (table_exists('bestellung_position') && table_exists('bestellung'))
            $del += q("DELETE FROM bestellung_position WHERE bestellung_id IN (SELECT id FROM bestellung WHERE lieferant_id=?)", [$lid])->rowCount();
        // Kreditoren-Zahlungen vor den Kreditoren-Rechnungen (Buchhaltung).
        if (table_exists('lieferant_zahlung') && table_exists('lieferant_rechnung'))
            $del += q("DELETE FROM lieferant_zahlung WHERE lief_rechnung_id IN (SELECT id FROM lieferant_rechnung WHERE lieferant_id=?)", [$lid])->rowCount();

        // Alle direkt am Lieferanten hängenden Tabellen (inkl. ALLER Preise).
        $x('rezeptur_lief_angebot', 'lieferant_id=?');
        $x('lieferant_angebot',     'lieferant_id=?');
        $x('lieferant_anfrage',     'lieferant_id=?');
        $x('lieferant_preis',       'lieferant_id=?');       // Rohstoff-EK je Lieferant
        $x('produkt_lieferant_preis','lieferant_id=?');      // Fertigprodukt-EK je Lieferant
        $x('pack_ek_staffel',       'lieferant_id=?');       // Verpackungs-EK-Staffeln
        $x('lieferant_preisliste',  'lieferant_id=?');       // Nachschlage-Preisliste
        $x('ek_import',             'lieferant_id=?');       // EK-Import-Staging
        $x('lieferant_katalog',     'lieferant_id=?');
        $x('lieferant_einladung',   'lieferant_id=?');
        // lieferant_alias ordnet über den Firmennamen zu (kein lieferant_id).
        if ($firma !== '' && table_exists('lieferant_alias')) $del += q("DELETE FROM lieferant_alias WHERE firma=?", [$firma])->rowCount();
        $x('lieferant_chat',        'lieferant_id=?');       // Rückfragen-Chat (falls vorhanden)
        $x('bestellung',            'lieferant_id=?');
        $x('lieferant_rechnung',    'lieferant_id=?');       // Kreditoren-Rechnungen (Buchhaltung)
        $x('benutzer',              'lieferant_id=?');        // Lieferanten-Portal-Login
        if (table_exists('dokument')) $del += q("DELETE FROM dokument WHERE objekt_typ='lieferant' AND objekt_id=?", [$lid])->rowCount();

        // Shared/Bestand: NICHT löschen, nur den Bezug lösen (Artikel & Bestand bleiben erhalten).
        if (table_exists('item')) q("UPDATE item SET haupt_lieferant_id=NULL WHERE haupt_lieferant_id=?", [$lid]);

        $del += q("DELETE FROM lieferanten WHERE id=?", [$lid])->rowCount();
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'fehler' => 'Löschen abgebrochen: ' . $e->getMessage()];
    }
    return ['ok' => true, 'geloescht' => $del];
}

// Station Verkapselung: Leerkapseln nach FEFO abbuchen (menge × einheiten je Packung). Blockiert bei zu wenig Bestand.
function produktion_kapseln_entnehmen(int $pa_id): array {
    $pa = one("SELECT menge, produkt_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return ['ok'=>true, 'fehlt'=>[]];
    $kid = produkt_leerkapsel_id((int)$pa['produkt_id']);
    if (!$kid) return ['ok'=>true, 'fehlt'=>[]];                       // kein Kapselprodukt / nicht bestimmbar -> nichts abbuchen
    if ((int) scalar("SELECT COUNT(*) FROM produktion_verbrauch WHERE pa_id=? AND item_id=?", [$pa_id, $kid]) > 0) return ['ok'=>true, 'fehlt'=>[]];
    $einh = produktion_stueck_je_packung($pa);                         // mit Fallback auf auftrag.stueck (v3-Import)
    $benoetigt = (float)$pa['menge'] * $einh;                          // Gesamt-Kapseln
    $verf = item_bestand($kid, true);
    if ($verf + 0.0001 < $benoetigt) {
        return ['ok'=>false, 'fehlt'=>[['name'=> scalar("SELECT name FROM item WHERE id=?", [$kid]), 'benoetigt'=>$benoetigt, 'verfuegbar'=>$verf, 'fehlt'=>$benoetigt-$verf, 'einheit'=>'Stück']]];
    }
    $rest = $benoetigt;
    foreach (all("SELECT * FROM charge WHERE item_id=? AND status='frei' AND menge_verfuegbar>0 AND fremd_kunde_id IS NULL ORDER BY (mhd IS NULL), mhd ASC, id ASC", [$kid]) as $c) {
        if ($rest <= 0.0001) break;
        $nimm = min($rest, (float)$c['menge_verfuegbar']);
        $neu = (float)$c['menge_verfuegbar'] - $nimm;
        q("UPDATE charge SET menge_verfuegbar=?, status=? WHERE id=?", [$neu, $neu <= 0.0001 ? 'leer' : 'frei', $c['id']]);
        q("INSERT INTO produktion_verbrauch (pa_id,item_id,charge_id,menge,einheit,angelegt) VALUES (?,?,?,?,?,?)",
          [$pa_id, $kid, $c['id'], $nimm, 'Stück', gmdate('Y-m-d H:i:s')]);
        $rest -= $nimm;
    }
    return ['ok'=>true, 'fehlt'=>[]];
}

// Zugekaufte fertige Bulkware (Kategorie 'fertig') für den Auftrag FEFO abbuchen (Station „Fertigware bereitstellen").
function produktion_fertigware_entnehmen(int $pa_id): array {
    $pa = one("SELECT menge, produkt_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa || !$pa['auftrag_id']) return ['ok'=>true, 'fehlt'=>[]];
    $einh = produktion_stueck_je_packung($pa);                         // mit Fallback auf auftrag.stueck (v3-Import)
    $benoetigt = (float)$pa['menge'] * $einh;
    if ($benoetigt <= 0) return ['ok'=>true, 'fehlt'=>[]];
    $chargen = all("SELECT c.* FROM charge c JOIN item i ON i.id=c.item_id
                    WHERE c.auftrag_id=? AND i.kategorie='fertig' AND c.status='frei' AND c.menge_verfuegbar>0
                    ORDER BY (c.mhd IS NULL), c.mhd ASC, c.id ASC", [(int)$pa['auftrag_id']]);
    $verf = array_sum(array_map(fn($c)=> (float)$c['menge_verfuegbar'], $chargen));
    if ($verf + 0.0001 < $benoetigt)
        return ['ok'=>false, 'fehlt'=>[['name'=>'Fertige Bulkware', 'benoetigt'=>$benoetigt, 'verfuegbar'=>$verf, 'fehlt'=>$benoetigt-$verf, 'einheit'=>'Stück']]];
    $rest = $benoetigt;
    foreach ($chargen as $c) {
        if ($rest <= 0.0001) break;
        $nimm = min($rest, (float)$c['menge_verfuegbar']);
        $neu = (float)$c['menge_verfuegbar'] - $nimm;
        q("UPDATE charge SET menge_verfuegbar=?, status=? WHERE id=?", [$neu, $neu <= 0.0001 ? 'leer' : 'frei', $c['id']]);
        q("INSERT INTO produktion_verbrauch (pa_id,item_id,charge_id,menge,einheit,angelegt) VALUES (?,?,?,?,?,?)",
          [$pa_id, (int)$c['item_id'], $c['id'], $nimm, 'Stück', gmdate('Y-m-d H:i:s')]);
        $rest -= $nimm;
    }
    return ['ok'=>true, 'fehlt'=>[]];
}

// Lager-Artikel (Verkaufsfertig) zu einem Produkt holen oder anlegen – für den Fertigwaren-Bestand.
function produkt_lageritem(int $produkt_id): ?int {
    $id = scalar("SELECT id FROM item WHERE produkt_id=? AND kategorie='verkaufsfertig' LIMIT 1", [$produkt_id]);
    if ($id) return (int)$id;
    $p = one("SELECT name FROM produkt WHERE id=?", [$produkt_id]);
    if (!$p) return null;
    q("INSERT INTO item (artikelnummer,name,kategorie,einheit,preis_bezug,produkt_id) VALUES (?,?,?,?,?,?)",
      [naechste_nummer('VF'), $p['name'], 'verkaufsfertig', 'Stück', 'Stück', $produkt_id]);
    return insert_id();
}

// ===== Fremdlager je Artikel (Ware gehoert einem Kunden, physisch bei uns, NICHT unser Bestand) =====
// Freier Fremdlager-Bestand eines Artikels. $kunde_id=null => Summe ueber alle Kunden.
function item_fremdbestand(int $item_id, ?int $kunde_id = null): float {
    if ($kunde_id) return (float) scalar("SELECT COALESCE(SUM(menge_verfuegbar),0) FROM charge WHERE item_id=? AND status='frei' AND fremd_kunde_id=?", [$item_id, $kunde_id]);
    return (float) scalar("SELECT COALESCE(SUM(menge_verfuegbar),0) FROM charge WHERE item_id=? AND status='frei' AND fremd_kunde_id IS NOT NULL", [$item_id]);
}
// Fremdlager-Bestand eines Artikels je Kunde (fuer die Anzeige auf der Artikel-Seite).
function item_fremdbestand_je_kunde(int $item_id): array {
    return all("SELECT c.fremd_kunde_id AS kunde_id, k.firma, COALESCE(SUM(c.menge_verfuegbar),0) AS menge
               FROM charge c LEFT JOIN kunden k ON k.id=c.fremd_kunde_id
               WHERE c.item_id=? AND c.status='frei' AND c.fremd_kunde_id IS NOT NULL
               GROUP BY c.fremd_kunde_id, k.firma HAVING menge > 0 ORDER BY k.firma", [$item_id]);
}
// Menge aus UNSEREM Bestand (Warenlager) ins Fremdlager eines Kunden umbuchen: unsere freien Chargen
// FEFO reduzieren und eine neue Fremd-Charge (dem Kunden gehoerend) mit derselben Menge anlegen.
function fremdlager_umbuchen(int $item_id, int $kunde_id, float $menge, ?string $charge_nr, ?string $mhd, string $notiz = ''): array {
    if ($item_id <= 0 || $kunde_id <= 0) return ['ok'=>false, 'msg'=>'Artikel und Kunde sind nötig.'];
    if ($menge <= 0) return ['ok'=>false, 'msg'=>'Bitte eine Menge größer 0 angeben.'];
    $frei = item_bestand($item_id, true);   // nur unser Bestand (Fremdlager ist hier schon ausgeschlossen)
    if ($frei + 1e-9 < $menge) return ['ok'=>false, 'msg'=>'Nicht genug im Warenlager (' . rtrim(rtrim(number_format($frei, 3, ',', '.'), '0'), ',') . ' verfügbar).'];
    $einheit = (string) scalar("SELECT einheit FROM item WHERE id=?", [$item_id]) ?: 'Stück';
    // FEFO aus unseren freien Chargen abbuchen (Fremdlager-Chargen sind ausgeschlossen).
    $rest = $menge;
    foreach (all("SELECT id, menge_verfuegbar FROM charge WHERE item_id=? AND status='frei' AND menge_verfuegbar>0 AND fremd_kunde_id IS NULL ORDER BY (mhd IS NULL), mhd ASC, id ASC", [$item_id]) as $c) {
        if ($rest <= 1e-9) break;
        $nimm = min($rest, (float)$c['menge_verfuegbar']);
        $neu  = (float)$c['menge_verfuegbar'] - $nimm;
        q("UPDATE charge SET menge_verfuegbar=?, status=? WHERE id=?", [$neu, $neu <= 1e-9 ? 'leer' : 'frei', $c['id']]);
        $rest -= $nimm;
    }
    // Neue Fremd-Charge (Kundenware) anlegen.
    q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,mhd,wareneingang,status,fremd_kunde_id,notiz,angelegt)
       VALUES (?,?,?,?,?,?,CURDATE(),'frei',?,?,?)",
      [$charge_nr ?: null, $item_id, $menge, $menge, $einheit, $mhd ?: null, $kunde_id, $notiz ?: 'Umbuchung ins Fremdlager', gmdate('Y-m-d H:i:s')]);
    $cid = insert_id();
    $kfirma = (string) scalar("SELECT firma FROM kunden WHERE id=?", [$kunde_id]);
    log_aktivitaet('item', $item_id, 'team', 'Menge ' . rtrim(rtrim(number_format($menge, 3, ',', '.'), '0'), ',') . ' ' . $einheit . ' ins Fremdlager umgebucht (Kunde: ' . $kfirma . ').', 'notiz');
    return ['ok'=>true, 'msg'=>'Ins Fremdlager umgebucht.', 'charge_id'=>$cid];
}

// Basis-Chargennummer eines Produktionsauftrags = PR-Nummer ohne "PR-" Präfix (z. B. PR-2696 -> 2696).
function charge_basis_pa(int $pa_id): string {
    $nummer = (string) scalar("SELECT nummer FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $basis  = trim(preg_replace('/^PR[-\s]*/i', '', $nummer));
    return $basis !== '' ? $basis : ('PR' . $pa_id);
}
// Nächste (Teil-)Chargennummer für einen Produktionsauftrag: Basis + Tagesbuchstabe .A/.B/.C … (je Teilproduktion eine).
function charge_naechste_nr(int $pa_id): string {
    $basis = charge_basis_pa($pa_id);
    $n = (int) scalar("SELECT COUNT(*) FROM charge WHERE pa_id=?", [$pa_id]);   // bereits gebuchte Teilchargen
    $buchstabe = ($n >= 0 && $n < 26) ? chr(ord('A') + $n) : ('X' . ($n + 1));   // 0->A, 1->B, …
    return $basis . '.' . $buchstabe;
}
// Standard-MHD: Basisdatum (Standard heute) + konfigurierte Monate (Standard 18). Rückgabe Y-m-d.
function mhd_standard(?string $ab = null): string {
    $monate = (int) meta_get('mhd_monate_standard', 18);
    if ($monate <= 0) $monate = 18;
    $basis = $ab ?: date('Y-m-d');
    return date('Y-m-d', strtotime($basis . ' +' . $monate . ' months'));
}

// Wie viel Fertigware zu einem Produktionsauftrag schon als Charge(n) gebucht ist (Summe über .A, .B, .C …).
function produktion_gebucht(int $pa_id): float {
    return (float) scalar("SELECT COALESCE(SUM(menge),0) FROM charge WHERE pa_id=?", [$pa_id]);
}
// Was von der Produktionsmenge noch nicht gebucht ist.
function produktion_rest(int $pa_id): float {
    $menge = (float) scalar("SELECT menge FROM produktionsauftrag WHERE id=?", [$pa_id]);
    return max(0.0, $menge - produktion_gebucht($pa_id));
}

// === Produktions-Charge CH/CHE – Helfer (Spec 7.5 + 16) ====================================================
// Diese Funktionen sind die EINZIGE Schreib-/Rueckverfolgungs-Schnittstelle fuer die Produktionscharge. Die
// Sub-Apps (produktion/) rufen sie ueber ihre eigene core/erp.php-Naht auf – sie fassen core/schema.php nicht an.

// Neue HAUPT-Produktionscharge anlegen. $d['typ']='extern' -> CHE…, sonst CH…. Rueckgabe ['id','nummer'].
function prod_charge_anlegen(array $d): array {
    $typ    = (($d['typ'] ?? 'intern') === 'extern') ? 'extern' : 'intern';
    $nummer = naechste_nummer($typ === 'extern' ? 'CHE' : 'CH');
    q("INSERT INTO prod_charge (nummer,typ,pa_id,rezeptur_id,produkt_id,gebinde,menge,einheit,mitarbeiter_id,maschine_id,status,tag,notiz)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [$nummer, $typ, $d['pa_id'] ?? null, $d['rezeptur_id'] ?? null, $d['produkt_id'] ?? null,
       mb_substr(trim((string)($d['gebinde'] ?? '')), 0, 80) ?: null,
       ($d['menge'] ?? null), mb_substr(trim((string)($d['einheit'] ?? '')), 0, 20) ?: null,
       ($d['mitarbeiter_id'] ?? null), ($d['maschine_id'] ?? null),
       mb_substr((string)($d['status'] ?? 'offen'), 0, 30), ($d['tag'] ?? gmdate('Y-m-d')), ($d['notiz'] ?? null)]);
    return ['id' => insert_id(), 'nummer' => $nummer];
}
// Untercharge zu einer Hauptcharge (je Gebinde/Tag/Mitarbeiter). sub_kennung automatisch A/B/C… wenn leer.
function prod_charge_sub_anlegen(int $parent_id, array $d = []): array {
    $p = one("SELECT nummer, typ, pa_id, rezeptur_id, produkt_id FROM prod_charge WHERE id=?", [$parent_id]);
    if (!$p) return ['id' => 0, 'nummer' => ''];
    $sub = strtoupper(trim((string)($d['sub_kennung'] ?? '')));
    if ($sub === '') { $n = (int) scalar("SELECT COUNT(*) FROM prod_charge WHERE parent_id=?", [$parent_id]); $sub = ($n < 26) ? chr(ord('A') + $n) : ('X' . ($n + 1)); }
    $nummer = $p['nummer'] . '-' . $sub;
    q("INSERT INTO prod_charge (nummer,typ,parent_id,sub_kennung,pa_id,rezeptur_id,produkt_id,gebinde,menge,einheit,mitarbeiter_id,maschine_id,status,tag,notiz)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [$nummer, $p['typ'], $parent_id, $sub, $p['pa_id'], $p['rezeptur_id'], $p['produkt_id'],
       mb_substr(trim((string)($d['gebinde'] ?? '')), 0, 80) ?: null,
       ($d['menge'] ?? null), mb_substr(trim((string)($d['einheit'] ?? '')), 0, 20) ?: null,
       ($d['mitarbeiter_id'] ?? null), ($d['maschine_id'] ?? null),
       mb_substr((string)($d['status'] ?? 'offen'), 0, 30), ($d['tag'] ?? gmdate('Y-m-d')), ($d['notiz'] ?? null)]);
    return ['id' => insert_id(), 'nummer' => $nummer];
}
// Einen eingesetzten Rohstoff(-Batch) mit einer Produktionscharge verknuepfen (Spec 7.5). batch_nr = Hersteller-Batch.
function prod_charge_rohstoff_verknuepfen(int $prod_charge_id, array $d): int {
    if ($prod_charge_id <= 0) return 0;
    $batch = trim((string)($d['batch_nr'] ?? ''));
    if ($batch === '' && !empty($d['charge_id'])) $batch = (string) scalar("SELECT charge_nr FROM charge WHERE id=?", [(int)$d['charge_id']]);
    q("INSERT INTO prod_charge_rohstoff (prod_charge_id,item_id,charge_id,batch_nr,menge,einheit,erfasst_von)
       VALUES (?,?,?,?,?,?,?)",
      [$prod_charge_id, ($d['item_id'] ?? null), ($d['charge_id'] ?? null), mb_substr($batch, 0, 80) ?: null,
       ($d['menge'] ?? null), mb_substr(trim((string)($d['einheit'] ?? '')), 0, 20) ?: null,
       mb_substr(trim((string)($d['erfasst_von'] ?? '')), 0, 190) ?: null]);
    return insert_id();
}
// Rueckverfolgung RUECKWAERTS: alle eingesetzten Rohstoff-Batches einer Charge (inkl. ihrer Unterchargen).
function prod_charge_rohstoffe(int $prod_charge_id): array {
    $ids = [$prod_charge_id];
    foreach (all("SELECT id FROM prod_charge WHERE parent_id=?", [$prod_charge_id]) as $r) $ids[] = (int)$r['id'];
    $in = implode(',', array_map('intval', $ids));
    return all("SELECT pcr.*, i.name AS item_name, i.artikelnummer FROM prod_charge_rohstoff pcr
                LEFT JOIN item i ON i.id=pcr.item_id WHERE pcr.prod_charge_id IN ($in) ORDER BY pcr.id");
}
// Rueckverfolgung VORWAERTS: welche Produktionschargen haben einen Rohstoff-Batch verwendet (Regress/Rueckruf).
function prod_charge_vorwaerts(string $batch_nr): array {
    $batch_nr = trim($batch_nr); if ($batch_nr === '') return [];
    return all("SELECT pc.*, pcr.item_id, pcr.menge AS eingesetzt_menge
                FROM prod_charge_rohstoff pcr JOIN prod_charge pc ON pc.id=pcr.prod_charge_id
                WHERE pcr.batch_nr=? OR pcr.batch_nr LIKE ? ESCAPE '='",
               [$batch_nr, str_replace(['=', '%', '_'], ['==', '=%', '=_'], $batch_nr) . '%']);
}
// Eine Produktionscharge mit Unterchargen + Rohstoffen fuer das Chargen-Menue laden.
function prod_charge_voll(int $prod_charge_id): ?array {
    $pc = one("SELECT * FROM prod_charge WHERE id=?", [$prod_charge_id]);
    if (!$pc) return null;
    $pc['unterchargen'] = all("SELECT * FROM prod_charge WHERE parent_id=? ORDER BY sub_kennung, id", [$prod_charge_id]);
    $pc['rohstoffe']    = prod_charge_rohstoffe($prod_charge_id);
    return $pc;
}

// === Proben / Rueckstellmuster (Spec 8) ====================================================================
// Rueckstell-Mengenregel Endprodukt (Spec 8.4): mindestens 5 Stueck, bei mehr Gebinden eine Probe pro Gebinde.
function rueckstellmuster_sollzahl(int $gebinde_anzahl): int { return max(5, $gebinde_anzahl); }
// Eine Probe / ein Rueckstellmuster erfassen. ebene: rohstoff|gebinde|endprodukt|labor.
function prod_probe_anlegen(array $d): int {
    $ebenen = ['rohstoff', 'gebinde', 'endprodukt', 'labor'];
    q("INSERT INTO prod_probe (pa_id,prod_charge_id,item_id,charge_id,ebene,batch_nr,anzahl,bezeichnung,etikett_gedruckt,labor,erfasst_von)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)",
      [($d['pa_id'] ?? null), ($d['prod_charge_id'] ?? null), ($d['item_id'] ?? null), ($d['charge_id'] ?? null),
       in_array($d['ebene'] ?? '', $ebenen, true) ? $d['ebene'] : 'endprodukt',
       mb_substr(trim((string)($d['batch_nr'] ?? '')), 0, 80) ?: null,
       isset($d['anzahl']) ? (int)$d['anzahl'] : null,
       mb_substr(trim((string)($d['bezeichnung'] ?? '')), 0, 190) ?: null,
       !empty($d['etikett_gedruckt']) ? 1 : 0,
       mb_substr(trim((string)($d['labor'] ?? '')), 0, 190) ?: null,
       mb_substr(trim((string)($d['erfasst_von'] ?? '')), 0, 190) ?: null]);
    return insert_id();
}
// Alle Proben eines Produktionsauftrags (nach Ebene sortiert).
function prod_proben_fuer_pa(int $pa_id): array {
    return all("SELECT * FROM prod_probe WHERE pa_id=? ORDER BY ebene, id", [$pa_id]);
}
// Eine Menge Fertigware zu einem Produktionsauftrag als eigene Charge einbuchen (Teilproduktion).
// Die Chargennummer ist die PR-Basis mit dem nächsten Buchstaben (.A, .B, .C …), das MHD standardmäßig
// heute + 18 Monate. Es kann nie mehr gebucht werden, als vom Auftrag noch offen ist.
// Rückgabe ['ok'=>bool, 'msg'=>string, 'charge_id'=>?int, 'charge_nr'=>string].
function produktion_teilmenge_einbuchen(int $pa_id, float $menge, ?string $mhd = null, string $notiz = ''): array {
    $pa = one("SELECT nummer, produkt_id, rezeptur_id, menge, auftrag_id, mhd FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return ['ok'=>false, 'msg'=>'Produktionsauftrag nicht gefunden.'];
    $istBulk = pa_ist_bulk($pa);
    if (!$pa['produkt_id'] && !$istBulk) return ['ok'=>false, 'msg'=>'Dem Produktionsauftrag fehlt das Produkt – ohne Produkt gibt es keinen Lagerartikel.'];
    $menge = round($menge);
    $rest  = produktion_rest($pa_id);
    if ($menge <= 0) return ['ok'=>false, 'msg'=>'Bitte eine Menge größer 0 angeben.'];
    if ($menge > $rest + 1e-6) return ['ok'=>false, 'msg'=>'Es sind nur noch ' . number_format($rest, 0, ',', '.') . ' Stück offen – mehr kann nicht gebucht werden.'];
    if ($istBulk) {
        // Bulk: als eigenen Bulk-Lagerartikel (Kategorie 'fertig') der Rezeptur einbuchen – kein Kunde/Fulfillment.
        $item_id = rezeptur_bulkitem((int)$pa['rezeptur_id']);
        if (!$item_id) return ['ok'=>false, 'msg'=>'Bulk-Lagerartikel zur Rezeptur konnte nicht angelegt werden.'];
    } else {
        $item_id = produkt_lageritem((int)$pa['produkt_id']);
        if (!$item_id) return ['ok'=>false, 'msg'=>'Lagerartikel zum Produkt konnte nicht angelegt werden.'];
        // Fulfillment-Kunde? → Fertigware gehört ins Lager 2, BSKU (Brücke) sicherstellen. Wer der Kunde
        // ist, sagt der Auftrag – das Produkt selbst ist kundenneutral (nur exklusive tragen einen Kunden).
        if (auftrag_ist_fulfillment((int)$pa['auftrag_id'])
            || (bool) scalar("SELECT k.nutzt_fulfillment FROM produkt p JOIN kunden k ON k.id=p.kunde_id WHERE p.id=?", [(int)$pa['produkt_id']]))
            bsku_ensure($item_id);
    }
    $charge_nr = charge_naechste_nr($pa_id);
    // MHD-Quelle: explizit übergeben > am Produktionsauftrag hinterlegt (wir legen es selbst fest) > Standard +18 M.
    if ($mhd && strtotime($mhd))            $mhd = date('Y-m-d', strtotime($mhd));
    elseif (!empty($pa['mhd']))             $mhd = (string)$pa['mhd'];
    else                                    $mhd = mhd_standard();
    $notiz = trim($notiz);
    q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,mhd,wareneingang,status,notiz,pa_id,angelegt)
       VALUES (?,?,?,?, 'Stück', ?, CURDATE(), 'frei', ?, ?, ?)",
      [$charge_nr, $item_id, $menge, $menge, $mhd, 'Aus Produktion ' . $pa['nummer'] . ($notiz !== '' ? ' – ' . mb_substr($notiz, 0, 200) : ''), $pa_id, gmdate('Y-m-d H:i:s')]);
    return ['ok'=>true, 'msg'=>'', 'charge_id'=>insert_id(), 'charge_nr'=>$charge_nr];
}
// Fertigware eines abgeschlossenen Produktionsauftrags als Charge einbuchen.
// Bucht den noch OFFENEN Rest der Produktionsmenge (Teilchargen, die vorher über
// produktion_teilmenge_einbuchen() gebucht wurden, sind abgezogen). Ist nichts mehr offen, passiert nichts.
// Chargennummer = PR-Basis + nächster Buchstabe (.A, .B …), MHD = heute + 18 Monate.
function produktion_fertigware_einbuchen(int $pa_id): ?int {
    $rest = produktion_rest($pa_id);
    if ($rest <= 0) return null;   // schon voll eingebucht
    $r = produktion_teilmenge_einbuchen($pa_id, $rest);
    return $r['ok'] ? (int)$r['charge_id'] : null;
}

// ===== Einlagern: Produktion → Lager-Übergabe (das LAGER bucht in Lager 1 oder 2) =====
// Ziel je Produktionsauftrag: Fulfillment-Kunde → Lager 2 (Fremdlager), sonst Lager 1 (Warenlager/Versand).
function einlager_ziel_fuer_pa(int $pa_id): array {
    // Einlagern geht IMMER zuerst in Lager 1 (Warenlager) – auch für Fulfillment-Kunden. Erst danach
    // entscheidet das Lager beim Versand: an den Kunden senden ODER an Lager 2 (Fremdlager) übergeben.
    return ['ziel'=>'lager1', 'label'=>'Lager 1 (Warenlager)'];
}
// Produktion an das Lager übergeben: legt eine Lager-Aufgabe „Einlagern … → Lager 1/2" an. Idempotent je PA.
function produktion_an_lager_uebergeben(int $pa_id): int {
    if (!table_exists('aufgabe') || !function_exists('aufgabe_neu')) return 0;
    if ((int) scalar("SELECT COUNT(*) FROM aufgabe WHERE ref_typ='einlagern' AND ref_id=? AND status='offen'", [$pa_id]) > 0) return 0;
    $pa = one("SELECT pa.nummer, pa.auftrag_id, pa.menge, a.nummer AS auftrag_nr,
                      COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name, rz.name) AS produkt, k.firma AS kunde
               FROM produktionsauftrag pa LEFT JOIN auftrag a ON a.id=pa.auftrag_id
               LEFT JOIN produkt p ON p.id=pa.produkt_id LEFT JOIN rezeptur rz ON rz.id=COALESCE(pa.rezeptur_id, p.rezeptur_id)
               LEFT JOIN kunden k ON k.id=pa.kunde_id WHERE pa.id=?", [$pa_id]);
    if (!$pa) return 0;
    $ziel  = einlager_ziel_fuer_pa($pa_id);
    $titel = 'Einlagern: ' . ($pa['produkt'] ?: ('PR ' . $pa['nummer'])) . ' → ' . $ziel['label'];
    $besch = trim(($pa['auftrag_nr'] ? 'Auftrag ' . $pa['auftrag_nr'] . ' · ' : '') . 'PR ' . $pa['nummer']
           . ($pa['kunde'] ? ' · ' . $pa['kunde'] : '') . ' · Menge ' . (int)$pa['menge']
           . '. Fertige Ware bitte in ' . $ziel['label'] . ' buchen.');
    return aufgabe_neu($titel, $besch, 2, null, null, null, 'einlagern', $pa_id);
}
// Das Lager bucht die Fertigware ein (Ziel L1/L2 ergibt sich aus Produkt/Kunde) und schließt die Einlager-Aufgabe.
// Idempotent: ist schon alles gebucht, wird nur die Aufgabe geschlossen. Rückgabe: ['ok','charge_id','ziel','label'].
function einlager_buchen(int $pa_id): array {
    // Bucht die Fertigware IMMER in Lager 1 (Warenlager). Der Auftrag bleibt danach „versandbereit" – auch
    // bei Fulfillment-Kunden. Die Weiterleitung (an Kunden senden ODER an Lager 2 übergeben) entscheidet das
    // Lager erst beim Versand (auftrag_versenden / auftrag_ins_fremdlager). So ist der Ablauf für alle gleich.
    $cid  = produktion_fertigware_einbuchen($pa_id);
    $ziel = einlager_ziel_fuer_pa($pa_id);
    foreach (all("SELECT id FROM aufgabe WHERE ref_typ='einlagern' AND ref_id=? AND status='offen'", [$pa_id]) as $a)
        aufgabe_erledigen((int)$a['id'], null);
    return ['ok'=>true, 'charge_id'=>$cid, 'ziel'=>$ziel['ziel'], 'label'=>$ziel['label']];
}

// ===== Lager 2 (Fremdlager) – nur für Fulfillment-Kunden (kunden.nutzt_fulfillment=1) =====
// Interne 5-stellige BSKU vergeben (fortlaufend ab 10000, kollisionssicher). Brücke zum Fulfillment.
function bsku_next(): string {
    $seq = (int) meta_get('bsku_seq', 10000);
    if ($seq < 10000) $seq = 10000;
    for ($i = 0; $i < 100000; $i++) {
        $kand = (string)($seq + $i);
        if (!scalar("SELECT COUNT(*) FROM item WHERE bsku=?", [$kand])) {
            meta_set('bsku_seq', (string)($seq + $i + 1));
            return $kand;
        }
    }
    return (string)($seq + 1);
}
// BSKU für das Verkaufsfertig-Item sicherstellen (einmalig vergeben).
function bsku_ensure(int $item_id): string {
    $b = (string) scalar("SELECT bsku FROM item WHERE id=?", [$item_id]);
    if ($b !== '') return $b;
    $b = bsku_next();
    q("UPDATE item SET bsku=? WHERE id=?", [$b, $item_id]);
    return $b;
}
// Frei verfügbarer Fertigwaren-Bestand eines Verkaufsfertig-Items (Summe freier Chargen).
function lager2_bestand(int $item_id): float {
    return (float) scalar("SELECT COALESCE(SUM(menge_verfuegbar),0) FROM charge WHERE item_id=? AND status='frei'", [$item_id]);
}
// Verkaufs-Statistik eines Lager-2-Artikels über die letzten $wochen (Default 8): netto verkauft
// (Verbrauch − Retoure), Rate pro Woche/Tag, Reichweite in Tagen + voraussichtliches Leer-Datum,
// Ampel (rot <14, gelb <30, sonst grün), Wochen-Verlauf (älteste zuerst). Quelle: lager2_bewegung.
function lager2_verkaufsstatistik(int $item_id, int $wochen = 8): array {
    $out = ['verkauf'=>0.0,'pro_woche'=>0.0,'pro_tag'=>0.0,'reichweite_tage'=>null,'leer_am'=>null,
            'bestand'=>0.0,'ampel'=>'keine','verlauf'=>array_fill(0, max(1,$wochen), 0.0)];
    if ($item_id <= 0 || !table_exists('lager2_bewegung')) return $out;
    $out['bestand'] = lager2_bestand($item_id);
    $tage = max(7, $wochen * 7);
    $cut  = date('Y-m-d H:i:s', time() - $tage * 86400);
    $verkauf = max(0.0, (float) scalar(
        "SELECT COALESCE(SUM(CASE typ WHEN 'verbrauch' THEN menge WHEN 'retoure' THEN -menge ELSE 0 END),0)
         FROM lager2_bewegung WHERE item_id=? AND angelegt >= ?", [$item_id, $cut]));
    // Effektiver Zeitraum: höchstens seit der ersten Bewegung im Fenster (sonst unterschätzt die Rate die Reichweite).
    $erste = scalar("SELECT MIN(angelegt) FROM lager2_bewegung WHERE item_id=? AND angelegt >= ?", [$item_id, $cut]);
    $spanTage = $tage;
    if ($erste) { $d = (int) floor((time() - strtotime((string)$erste)) / 86400); $spanTage = max(7, min($tage, $d > 0 ? $d : 7)); }
    $proTag = $verkauf > 0 ? $verkauf / $spanTage : 0.0;
    $out['verkauf'] = $verkauf; $out['pro_tag'] = $proTag; $out['pro_woche'] = $proTag * 7;
    if ($proTag > 0 && $out['bestand'] > 0) {
        $rt = (int) floor($out['bestand'] / $proTag);
        $out['reichweite_tage'] = $rt;
        $out['leer_am'] = date('Y-m-d', time() + $rt * 86400);
        $out['ampel'] = $rt < 14 ? 'rot' : ($rt < 30 ? 'gelb' : 'gruen');
    } elseif ($proTag > 0) {
        $out['reichweite_tage'] = 0; $out['leer_am'] = date('Y-m-d'); $out['ampel'] = 'rot';
    }
    foreach (all("SELECT FLOOR(DATEDIFF(NOW(), angelegt)/7) AS w,
                         SUM(CASE typ WHEN 'verbrauch' THEN menge WHEN 'retoure' THEN -menge ELSE 0 END) AS m
                  FROM lager2_bewegung WHERE item_id=? AND angelegt >= ? GROUP BY w", [$item_id, $cut]) as $row) {
        $w = (int)$row['w']; if ($w >= 0 && $w < $wochen) $out['verlauf'][$wochen - 1 - $w] = max(0.0, (float)$row['m']);
    }
    return $out;
}
// Alle Lager-2-Produkte (Verkaufsfertig-Items von Fulfillment-Kunden) mit Bestand + Brücken-Feldern.
function lager2_produkte(?int $kunde_id = null): array {
    // Wem gehört die Fertigware? Steht am Produkt ein Kunde (exklusives Produkt), gilt der.
    // Produkte aus dem Weg Rezeptur -> Angebot -> Auftrag sind aber kundenneutral; dort sagt der
    // AUFTRAG, für wen produziert wurde. Ohne diesen zweiten Weg bliebe das Fremdlager leer.
    $sql = "SELECT i.id AS item_id, i.artikelnummer, i.name, i.bsku, i.shopify_inventory_item_id,
                   p.id AS produkt_id, p.nummer AS produkt_nr, COALESCE(NULLIF(p.kundenname,''),p.name) AS anzeigename,
                   k.id AS kunde_id, k.firma AS kunde, k.kundennummer AS kundennummer
            FROM item i
            JOIN produkt p ON p.id=i.produkt_id
            JOIN kunden k ON k.nutzt_fulfillment=1
                 AND (k.id = p.kunde_id
                      OR (p.kunde_id IS NULL AND EXISTS (SELECT 1 FROM auftrag a WHERE a.produkt_id=p.id AND a.kunde_id=k.id)))
            WHERE i.kategorie='verkaufsfertig'";
    $params = [];
    if ($kunde_id) { $sql .= " AND k.id=?"; $params[] = $kunde_id; }
    $sql .= " ORDER BY k.firma, p.nummer";
    $rows = all($sql, $params);
    foreach ($rows as &$r) $r['bestand'] = lager2_bestand((int)$r['item_id']);
    unset($r);
    return $rows;
}
// Prüft, ob der Kunde eines Auftrags Fulfillment nutzt (→ Fertigware gehört ins Lager 2).
function auftrag_ist_fulfillment(int $auftrag_id): bool {
    return (bool) scalar("SELECT k.nutzt_fulfillment FROM auftrag a JOIN kunden k ON k.id=a.kunde_id WHERE a.id=?", [$auftrag_id]);
}
// Manuelle Lager-2-Einbuchung (Menge/Charge/MHD) auf das Verkaufsfertig-Item eines Produkts.
function lager2_einbuchen(int $produkt_id, float $menge, ?string $charge_nr, ?string $mhd, string $notiz = ''): ?int {
    if ($menge <= 0) return null;
    $item_id = produkt_lageritem($produkt_id);
    if (!$item_id) return null;
    bsku_ensure($item_id);
    q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,mhd,wareneingang,status,notiz,angelegt)
       VALUES (?,?,?,?, 'Stück', ?, CURDATE(), 'frei', ?, ?)",
      [$charge_nr ?: null, $item_id, $menge, $menge, $mhd ?: null, $notiz ?: 'Lager-2-Einbuchung', gmdate('Y-m-d H:i:s')]);
    $cid = (int) insert_id();
    // Energetisierung: bei freigeschalteten Kunden (kunden.zeige_energetisierung) wird JEDE Einlagerung energetisiert.
    $kid = (int) scalar("SELECT kunde_id FROM produkt WHERE id=?", [$produkt_id]);
    if ($kid && kunde_zeigt_energetisierung($kid)) {
        $wer = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? '') : '';
        q("UPDATE charge SET energetisiert_am=NOW(), energetisiert_von=? WHERE id=?", [$wer !== '' ? $wer : 'Team', $cid]);
    }
    return $cid;
}

// --- Fulfillment-Kopplung (ds_api): Artikel finden + Bestand ab-/zubuchen, idempotent per ref ---
// Führender Schlüssel = shopify_inventory_item_id, BSKU als Fallback. Nur Verkaufsfertig-Items.
function lager2_find_item(?string $iid, ?string $bsku): ?int {
    $iid = trim((string)$iid); $bsku = trim((string)$bsku);
    if ($iid !== '') { $id = scalar("SELECT id FROM item WHERE kategorie='verkaufsfertig' AND shopify_inventory_item_id=? LIMIT 1", [$iid]); if ($id) return (int)$id; }
    if ($bsku !== '') { $id = scalar("SELECT id FROM item WHERE kategorie='verkaufsfertig' AND bsku=? LIMIT 1", [$bsku]); if ($id) return (int)$id; }
    return null;
}
function lager2_ref_gesehen(string $ref, string $typ): bool {
    return (bool) scalar("SELECT COUNT(*) FROM lager2_bewegung WHERE ref=? AND typ=?", [$ref, $typ]);
}
// Versand → Lager 2 abbuchen (FEFO über freie Chargen). Idempotent per ref.
function lager2_verbrauch(int $item_id, float $menge, string $ref): array {
    if ($menge <= 0) return ['ok'=>true, 'skip'=>'menge<=0'];
    if (lager2_ref_gesehen($ref, 'verbrauch')) return ['ok'=>true, 'idempotent'=>true];
    $rest = $menge;
    foreach (all("SELECT id, menge_verfuegbar FROM charge WHERE item_id=? AND status='frei' AND menge_verfuegbar>0
                  ORDER BY (mhd IS NULL), mhd ASC, id ASC", [$item_id]) as $c) {
        if ($rest <= 1e-9) break;
        $nimm = min($rest, (float)$c['menge_verfuegbar']);
        $neu  = (float)$c['menge_verfuegbar'] - $nimm;
        q("UPDATE charge SET menge_verfuegbar=?, status=? WHERE id=?", [$neu, $neu <= 1e-9 ? 'leer' : 'frei', (int)$c['id']]);
        $rest -= $nimm;
    }
    $fehl = $rest > 1e-6 ? $rest : 0.0;
    q("INSERT INTO lager2_bewegung (item_id,typ,menge,ref,notiz) VALUES (?,?,?,?,?)",
      [$item_id, 'verbrauch', $menge, $ref, $fehl > 0 ? ('Unterdeckung ' . rtrim(rtrim(number_format($fehl,3,'.',''),'0'),'.')) : null]);
    return ['ok'=>true, 'fehlbestand'=>$fehl];
}
// Wiederverkäufliche Retoure → Lager 2 wieder hoch (neue Charge). Idempotent per ref.
function lager2_retoure(int $item_id, float $menge, string $ref): array {
    if ($menge <= 0) return ['ok'=>true, 'skip'=>'menge<=0'];
    if (lager2_ref_gesehen($ref, 'retoure')) return ['ok'=>true, 'idempotent'=>true];
    q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,wareneingang,status,notiz,angelegt)
       VALUES ('RETOURE',?,?,?, 'Stück', CURDATE(), 'frei', ?, ?)",
      [$item_id, $menge, $menge, 'Retoure (Fulfillment) ' . $ref, gmdate('Y-m-d H:i:s')]);
    q("INSERT INTO lager2_bewegung (item_id,typ,menge,ref) VALUES (?,?,?,?)", [$item_id, 'retoure', $menge, $ref]);
    return ['ok'=>true];
}
// Defekte/geöffnete Retoure → nur dokumentieren (kein Bestand). Idempotent per ref.
function lager2_defekt(int $item_id, float $menge, string $ref, string $zustand): array {
    if ($menge <= 0) return ['ok'=>true, 'skip'=>'menge<=0'];
    if (lager2_ref_gesehen($ref, 'defekt')) return ['ok'=>true, 'idempotent'=>true];
    q("INSERT INTO lager2_bewegung (item_id,typ,menge,ref,notiz) VALUES (?,?,?,?,?)",
      [$item_id, 'defekt', $menge, $ref, 'Zustand: ' . ($zustand ?: 'defekt')]);
    return ['ok'=>true];
}
// Token für die Fulfillment-Schnittstelle (bei Bedarf erzeugen).
function ds_api_token(): string {
    $t = (string) meta_get('ds_api_token', '');
    if ($t === '') { $t = bin2hex(random_bytes(24)); meta_set('ds_api_token', $t); }
    return $t;
}
// Token für den Lager-Scan-Endpunkt (Smartglass/Handscanner, read-only) – bei Bedarf erzeugen.
function lager_scan_token(): string {
    $t = (string) meta_get('lager_scan_token', '');
    if ($t === '') { $t = bin2hex(random_bytes(24)); meta_set('lager_scan_token', $t); }
    return $t;
}
// Richtung B: Dashboard zieht die Artikelliste aus dem Fulfillment (fulfillment-web/bulkify_feed.php),
// um Fremdlager-Produkte per inventory_item_id zu verknüpfen. Ergebnis wird gecacht (app_meta).
function ff_feed_pull(): array {
    $base = rtrim(trim((string) meta_get('ff_base_url', '')), '/');
    if ($base === '') return ['ok'=>false, 'error'=>'Keine Fulfillment-URL hinterlegt (Einstellungen → Fulfillment-Schnittstelle).'];
    $url = $base . '/bulkify_feed.php?token=' . rawurlencode(ds_api_token());
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => ['X-DS-Token: ' . ds_api_token(), 'Accept: application/json'],
    ]);
    if (defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($body === false) return ['ok'=>false, 'error'=>($cerr ?: 'Verbindung fehlgeschlagen')];
    $j = json_decode((string)$body, true);
    if (!is_array($j) || empty($j['ok'])) return ['ok'=>false, 'error'=>($j['error'] ?? ('HTTP ' . $code))];
    $artikel = $j['artikel'] ?? [];
    meta_set('ff_feed_cache', json_encode($artikel, JSON_UNESCAPED_UNICODE));
    meta_set('ff_feed_at', gmdate('Y-m-d H:i:s'));
    return ['ok'=>true, 'artikel'=>$artikel, 'count'=>count($artikel)];
}
// Zuletzt gezogene Fulfillment-Artikel aus dem Cache (leeres Array, wenn noch nie abgerufen).
function ff_feed_cached(): array {
    $raw = (string) meta_get('ff_feed_cache', '');
    if ($raw === '') return [];
    $a = json_decode($raw, true);
    return is_array($a) ? $a : [];
}

// Portal-Token eines Kunden holen (bei Bedarf erzeugen). Passwortloser Zugangslink.
function kunde_portal_token(int $kid): string {
    $t = scalar("SELECT portal_token FROM kunden WHERE id=?", [$kid]);
    if ($t) return $t;
    $t = bin2hex(random_bytes(16));
    q("UPDATE kunden SET portal_token=? WHERE id=?", [$t, $kid]);
    return $t;
}

// Kunden-Login per E-Mail + Passwort. Gibt die Kunden-Zeile zurueck oder null.
function kunde_login(string $email, string $passwort): ?array {
    $email = trim(mb_strtolower($email));
    if ($email === '' || $passwort === '') return null;
    $k = one("SELECT * FROM kunden WHERE LOWER(email)=? AND passwort IS NOT NULL AND passwort<>'' AND COALESCE(gesperrt,0)=0", [$email]);
    if (!$k || !password_verify($passwort, (string)$k['passwort'])) return null;
    q("UPDATE kunden SET letzter_login=UTC_TIMESTAMP() WHERE id=?", [(int)$k['id']]);
    return $k;
}

// Login eines Kunden-MITARBEITERS (kunde_portal_user). Rückgabe bei Erfolg:
// ['kunde'=>kunden-Zeile, 'user_id'=>int, 'rolle'=>string], sonst null.
function kunde_portal_login(string $email, string $passwort): ?array {
    $email = trim(mb_strtolower($email));
    if ($email === '' || $passwort === '' || !table_exists('kunde_portal_user')) return null;
    $u = one("SELECT * FROM kunde_portal_user WHERE LOWER(email)=? AND passwort IS NOT NULL AND passwort<>'' AND aktiv=1", [$email]);
    if (!$u || !password_verify($passwort, (string)$u['passwort'])) return null;
    $k = one("SELECT * FROM kunden WHERE id=? AND COALESCE(gesperrt,0)=0", [(int)$u['kunde_id']]);
    if (!$k) return null;
    return ['kunde' => $k, 'user_id' => (int)$u['id'], 'rolle' => (string)$u['rolle']];
}

// Passwort setzen/aendern (Erstzugang oder Wechsel). Mindestens 8 Zeichen. Speichert nur den Hash.
function kunde_passwort_setzen(int $kid, string $passwort): bool {
    if ($kid <= 0 || strlen($passwort) < 8) return false;
    q("UPDATE kunden SET passwort=?, erstlogin_am=COALESCE(erstlogin_am, UTC_TIMESTAMP()) WHERE id=?",
      [password_hash($passwort, PASSWORD_DEFAULT), $kid]);
    return true;
}

// Nächste feste Nummer für einen Präfix, z. B. naechste_nummer('K') -> "K-0001". Atomar hochgezählt.
function naechste_nummer(string $prefix): string {
    $prefix = strtoupper(trim($prefix));
    // Start bei 2690, dann frei hochzählen (Stellen wachsen mit)
    q("INSERT IGNORE INTO nummernkreis (prefix, naechste, stellen) VALUES (?, 2690, 4)", [$prefix]);
    q("UPDATE nummernkreis SET naechste = naechste + 1 WHERE prefix = ?", [$prefix]);
    $r = one("SELECT naechste - 1 AS nr, stellen FROM nummernkreis WHERE prefix = ?", [$prefix]);
    return $prefix . '-' . str_pad((string)$r['nr'], (int)$r['stellen'], '0', STR_PAD_LEFT);
}

// Eine gerade vergebene Nummer zurückgeben – nur wenn sie die zuletzt ausgegebene ist.
// Verhindert Lücken, wenn ein Angebot direkt nach dem Anlegen wieder verworfen wird.
function nummer_zurueckgeben(string $nummer): void {
    if (!preg_match('/^([A-Z]+)-(\d+)$/', strtoupper(trim($nummer)), $m)) return;
    q("UPDATE nummernkreis SET naechste = naechste - 1 WHERE prefix = ? AND naechste = ?", [$m[1], (int)$m[2] + 1]);
}

// Einen Angebots-ENTWURF spurlos verwerfen: nur Status „offen" (also nie beim Kunden gewesen),
// nie mit Auftrag. Positionen mit weg, Nummer zurück, Anfrage wieder frei für einen neuen Anlauf.
function angebot_entwurf_verwerfen(int $angebot_id): bool {
    $a = one("SELECT id, nummer, status, anfrage_id, kunde_id FROM angebot WHERE id=?", [$angebot_id]);
    if (!$a || $a['status'] !== 'offen') return false;
    if (scalar("SELECT id FROM auftrag WHERE angebot_id=?", [$angebot_id])) return false;
    q("DELETE FROM angebot_position WHERE angebot_id=?", [$angebot_id]);
    q("DELETE FROM angebot_staffel WHERE angebot_id=?", [$angebot_id]);
    q("DELETE FROM angebot_produkt WHERE angebot_id=?", [$angebot_id]);
    q("DELETE FROM angebot WHERE id=?", [$angebot_id]);
    nummer_zurueckgeben((string)$a['nummer']);
    if ($a['kunde_id']) log_aktivitaet('kunde', (int)$a['kunde_id'], 'team',
        'Angebots-Entwurf ' . $a['nummer'] . ' verworfen (war nie beim Kunden).', 'angebot');
    return true;
}

// Präfix für Warenlager-Items je Kategorie (Rohstoff=R, Verpackung=VP, Fertigware=FP ...).
function item_prefix(string $kategorie): string {
    return [
        'verpackung'     => 'VP',
        'fertig'         => 'FP',
        'karton'         => 'KA',
        'verbrauch'      => 'VB',
        'inventar'       => 'IN',
        'maschine'       => 'MA',
        'sonstiges'      => 'SO',
        'verkaufsfertig' => 'VF',
    ][$kategorie] ?? 'R';
}

// Lieferanten-Kürzel aus dem Firmennamen vorschlagen: erste zwei Buchstaben/Ziffern, GROSS (z. B. „Buxtrade"→„BU").
// Nur ein Vorschlag – das echte Kürzel (z. B. „BX") wird am Lieferanten gepflegt.
function lieferant_kuerzel_vorschlag(string $firma): string {
    $s = preg_replace('/[^A-Za-z0-9]/', '', $firma);
    return mb_strtoupper(mb_substr((string)$s, 0, 2));
}
// Rohstoff-Kennung JE LIEFERANT = Artikelnummer (R-Nummer) + Lieferant-Kürzel (z. B. R-12345BX).
// So lässt sich derselbe Rohstoff je Lieferant unterscheiden. Leer, wenn Nummer oder Kürzel fehlt.
function rohstoff_lief_kennung(?string $artikelnummer, ?string $kuerzel): string {
    $nr = trim((string)$artikelnummer); $k = trim((string)$kuerzel);
    return ($nr !== '' && $k !== '') ? $nr . $k : '';
}

// Braucht diese Kategorie eine Quarantäne beim Wareneingang?
function item_braucht_quarantaene(string $kategorie): bool {
    return in_array($kategorie, ['rohstoff','fertig','verkaufsfertig'], true);
}

// --- Betriebsmittel: Kategorien mit einfachem Bestand (keine Chargen/MHD) ---
function betriebsmittel_kategorien(): array {
    return [
        'karton'    => 'Kartons',
        'verbrauch' => 'Verbrauchsgüter',
        'inventar'  => 'Inventar',
        'maschine'  => 'Maschinen',
        'sonstiges' => 'Sonstiges',
    ];
}
function ist_betriebsmittel_kat(string $kategorie): bool {
    return array_key_exists($kategorie, betriebsmittel_kategorien());
}
// Nächster Prüftermin (letzte Prüfung + Intervall). Null, wenn nicht elektrisch oder keine letzte Prüfung.
function pruefung_naechste(array $it): ?string {
    if (empty($it['elektrisch'])) return null;
    $mon = (int)($it['pruef_intervall_monate'] ?? 0) ?: 12;
    if (empty($it['letzte_pruefung'])) return null;
    $ts = strtotime((string)$it['letzte_pruefung'] . ' +' . $mon . ' months');
    return $ts ? date('Y-m-d', $ts) : null;
}
// Prüfstatus: ['stufe'=>'faellig'|'bald'|'ok'|'offen', 'datum'=>naechste|null, 'label'=>…] – nur für elektrische Geräte.
function pruefung_status(array $it): ?array {
    if (empty($it['elektrisch'])) return null;
    if (empty($it['letzte_pruefung'])) return ['stufe'=>'offen', 'datum'=>null, 'label'=>'noch nie geprüft'];
    $n = pruefung_naechste($it);
    $tage = $n ? (int)floor((strtotime($n) - strtotime(date('Y-m-d'))) / 86400) : null;
    if ($tage === null)   return ['stufe'=>'ok', 'datum'=>$n, 'label'=>'ok'];
    if ($tage < 0)        return ['stufe'=>'faellig', 'datum'=>$n, 'label'=>'überfällig'];
    if ($tage <= 30)      return ['stufe'=>'bald', 'datum'=>$n, 'label'=>'fällig in ' . $tage . ' T'];
    return ['stufe'=>'ok', 'datum'=>$n, 'label'=>'geprüft'];
}
// Elektrische Geräte, deren Prüfung überfällig oder in den nächsten 30 Tagen fällig ist (bzw. nie geprüft).
function pruefungen_faellig(): array {
    $out = [];
    foreach (all("SELECT * FROM item WHERE elektrisch=1") as $it) {
        $s = pruefung_status($it);
        if ($s && in_array($s['stufe'], ['faellig','bald','offen'], true)) { $it['pruef'] = $s; $out[] = $it; }
    }
    return $out;
}

// Verfügbarer Bestand eines Items (Summe freier Chargen; optional inkl. Quarantäne).
// Optionaler request-lokaler Bestands-Cache. NUR aktiv, wenn $GLOBALS['bx_stock_cache'] gesetzt ist
// (das macht ausschließlich die schreibfreie Produktionsliste). Schreibpfade (z. B. reservieren)
// setzen ihn nie -> sie rechnen immer frisch, bleiben also korrekt.
function item_bestand(int $item_id, bool $nur_frei = true): float {
    $ck = 'b:' . $item_id . ':' . ($nur_frei ? 1 : 0);
    if (isset($GLOBALS['bx_stock_cache']) && array_key_exists($ck, $GLOBALS['bx_stock_cache'])) return $GLOBALS['bx_stock_cache'][$ck];
    $status = $nur_frei ? "status='frei'" : "status IN ('frei','quarantaene')";
    // Fremdlager-Chargen (fremd_kunde_id gesetzt) gehoeren dem Kunden -> zaehlen NICHT zu unserem Bestand.
    $v = (float) scalar("SELECT COALESCE(SUM(menge_verfuegbar),0) FROM charge WHERE item_id=? AND $status AND fremd_kunde_id IS NULL", [$item_id]);
    if (isset($GLOBALS['bx_stock_cache'])) $GLOBALS['bx_stock_cache'][$ck] = $v;
    return $v;
}

// Wareneingang buchen -> neue Charge. Rohstoffe landen in Quarantäne, Rest direkt frei.
function wareneingang_buchen(int $item_id, float $menge, string $charge_nr, ?string $mhd, ?int $lieferant_id, string $notiz = '', ?int $auftrag_id = null, ?int $bestellung_position_id = null): ?int {
    $it = one("SELECT kategorie, einheit FROM item WHERE id=?", [$item_id]);
    if (!$it || $menge <= 0) return null;
    $status = item_braucht_quarantaene($it['kategorie']) ? 'quarantaene' : 'frei';
    // Abgleich: Wurde die Charge vorab aus einer CoA angelegt (gleiche Nummer, noch keine Ware:
    // wareneingang IS NULL und menge 0)? Dann diese Charge einbuchen statt eine Dublette anzulegen -
    // die Analysewerte aus der CoA bleiben so an der Charge haengen.
    $charge_nr = trim($charge_nr);
    if ($charge_nr !== '') {
        $vorab = one("SELECT id FROM charge WHERE item_id=? AND wareneingang IS NULL AND menge<=0.0001 AND charge_nr=? ORDER BY id LIMIT 1",
                     [$item_id, $charge_nr]);
        if ($vorab) {
            $altNotiz = trim((string) scalar("SELECT notiz FROM charge WHERE id=?", [(int)$vorab['id']]));
            q("UPDATE charge SET menge=?, menge_verfuegbar=?, einheit=?, lieferant_id=COALESCE(?,lieferant_id), mhd=COALESCE(?,mhd), wareneingang=CURDATE(), status=?, notiz=?, auftrag_id=COALESCE(?,auftrag_id), bestellung_position_id=COALESCE(?,bestellung_position_id) WHERE id=?",
              [$menge, $menge, $it['einheit'], $lieferant_id ?: null, $mhd ?: null, $status,
               trim(($altNotiz !== '' ? $altNotiz . ' | ' : '') . ($notiz ?: 'Ware eingegangen, mit CoA-Charge abgeglichen')),
               $auftrag_id ?: null, $bestellung_position_id ?: null, (int)$vorab['id']]);
            return (int)$vorab['id'];
        }
    }
    q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,lieferant_id,mhd,wareneingang,status,notiz,auftrag_id,bestellung_position_id,angelegt)
       VALUES (?,?,?,?,?,?,?,CURDATE(),?,?,?,?,?)",
      [$charge_nr ?: null, $item_id, $menge, $menge, $it['einheit'], $lieferant_id ?: null, $mhd ?: null, $status, $notiz ?: null, $auftrag_id ?: null, $bestellung_position_id ?: null, gmdate('Y-m-d H:i:s')]);
    $neu = (int) insert_id();   // ID VOR bedarf_bump() sichern (meta_set() setzt LAST_INSERT_ID sonst auf 0)
    bedarf_bump();   // neuer Bestand -> Bedarf-Cache ungueltig
    return $neu;
}

// Bestellung als geliefert verbuchen: für jede Position eine Charge (Wareneingang) anlegen. Idempotent.
function bestellung_wareneingang(int $bestellung_id): bool {
    $b = one("SELECT * FROM bestellung WHERE id=?", [$bestellung_id]);
    if (!$b || $b['status'] === 'geliefert') return false;
    foreach (all("SELECT * FROM bestellung_position WHERE bestellung_id=?", [$bestellung_id]) as $p) {
        if (!$p['item_id'] || (float)$p['menge'] <= 0) continue;   // Bulk-Freitext (item_id NULL) manuell als Fertigware buchen
        wareneingang_buchen((int)$p['item_id'], (float)$p['menge'], 'zu ' . $b['nummer'], null,
            $b['lieferant_id'] ? (int)$b['lieferant_id'] : null, 'Aus Bestellung ' . $b['nummer'],
            !empty($p['auftrag_id']) ? (int)$p['auftrag_id'] : null, (int)$p['id']);
    }
    q("UPDATE bestellung SET status='geliefert' WHERE id=?", [$bestellung_id]);
    return true;
}

// Demo-Chargen (etwas Bestand für die Rohstoffe)
function seed_charge_if_empty(): void {
    if (meta_get('seed_demo_off','') === '1') return;   // Demo-Seeding nach Reset deaktiviert
    if ((int) scalar("SELECT COUNT(*) FROM charge") > 0) return;
    seed_item_if_empty();
    $demo = [
        ['Magnesiumcitrat', 25, 'LC-MG-2401', 'frei'],
        ['Magnesiumbisglycinat', 15, 'LC-MGB-2402', 'frei'],
        ['Vitamin C (Ascorbinsäure)', 10, 'LC-VC-2403', 'frei'],
        ['Kurkuma-Extrakt', 5, 'LC-KU-2404', 'quarantaene'],
    ];
    foreach ($demo as $d) {
        $iid = scalar("SELECT id FROM item WHERE name=?", [$d[0]]);
        if (!$iid) continue;
        q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,mhd,wareneingang,status,angelegt)
           VALUES (?,?,?,?, 'kg', DATE_ADD(CURDATE(), INTERVAL 2 YEAR), CURDATE(), ?, ?)",
          [$d[2], (int)$iid, $d[1], $d[1], $d[3], gmdate('Y-m-d H:i:s')]);
    }
}

// Demo-Lieferantenpreise (Staffel)
function seed_lieferant_preis_if_empty(): void {
    if ((int) scalar("SELECT COUNT(*) FROM lieferant_preis") > 0) return;
    seed_item_if_empty(); seed_lieferanten_if_empty();
    // [item-name, lieferant-firma, menge_ab, preis]
    $demo = [
        ['Kurkuma-Extrakt','Herbal Extracts Co.',25,36.0000],
        ['Kurkuma-Extrakt','Herbal Extracts Co.',100,33.5000],
        ['Kurkuma-Extrakt','NutriRaw B.V.',50,34.8000],
        ['Ashwagandha-Extrakt','Herbal Extracts Co.',25,42.0000],
        ['Ashwagandha-Extrakt','NutriRaw B.V.',25,40.5000],
    ];
    foreach ($demo as $d) {
        $iid = scalar("SELECT id FROM item WHERE name=?", [$d[0]]);
        $lid = scalar("SELECT id FROM lieferanten WHERE firma=?", [$d[1]]);
        if ($iid && $lid) q("INSERT INTO lieferant_preis (item_id,lieferant_id,menge_ab,preis,waehrung,stand) VALUES (?,?,?,?, 'EUR', CURDATE())", [(int)$iid, (int)$lid, $d[2], $d[3]]);
    }
}

// EK-Kosten einer Rezeptur je Einheit (Summe Zutat-mg × EK/mg).
// $einheiten = wie viele Einheiten (Kapseln, Portionen …) insgesamt produziert werden. Ist das
// bekannt, zählt je Zutat die Lieferanten-Staffel (`lieferant_preis`), die zur Gesamtmenge passt –
// so wird eine große Bestellung je Einheit günstiger (Mengenrabatt). Ohne Angabe: flacher item.ek_preis.
function rezeptur_kosten_pro_einheit(?int $rid, float $einheiten = 0): float {
    if (!$rid) return 0.0;
    $c = 0.0;
    foreach (all("SELECT z.item_id, z.menge_mg, i.ek_preis, i.preis_bezug, i.dichte
                  FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=?", [$rid]) as $z) {
        $mg = (float)$z['menge_mg']; $pb = $z['preis_bezug']; $ek = (float)$z['ek_preis'];
        if ($einheiten > 0) {
            // Gesamtbedarf der Zutat in ihrer Bezugseinheit (kg, g oder L) – damit die passende Staffel gilt.
            $bedarf = $pb === 'kg' ? $mg * $einheiten / 1e6
                    : ($pb === 'g' ? $mg * $einheiten / 1e3
                    : ($pb === 'L' && $z['dichte'] ? $mg * $einheiten / 1e6 / (float)$z['dichte'] : 0));
            if ($bedarf > 0) $ek = rohstoff_ek_bei_menge((int)$z['item_id'], $bedarf) ?? $ek;
        }
        $perMg = $pb === 'kg' ? $ek/1e6 : ($pb === 'g' ? $ek/1e3 : ($pb === 'L' && $z['dichte'] ? ($ek/(1000*(float)$z['dichte']))/1e3 : 0));
        $c += $mg * $perMg;
    }
    // Fallback: keine Rohstoff-EK bekannt -> Fremdfertigungspreis (Lohnherstellung) je Stück nutzen,
    // falls fuer diese Rezeptur ein Lieferanten-Angebot mit Preis vorliegt (aus rezept_preise).
    if ($c <= 0) {
        $f = rezeptur_fremd_ek_pro_einheit((int)$rid, $einheiten);
        if ($f !== null) return $f;
    }
    return $c;
}

// Fremdfertigungspreis je Stueck aus rezeptur_lief_angebot (nur Stueck-Formen: Kapsel/Tablette/…).
// Waehlt die guenstigste Zeile, deren Mengenstaffel fuer die gewuenschte Stueckzahl gilt.
function rezeptur_fremd_ek_pro_einheit(int $rid, float $einheiten = 0): ?float {
    if (!$rid) return null;
    $df = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rid]);
    if (!in_array($df, ['kapsel','tablette','softgel','stick','gummi','gel'], true)) return null;
    $rows = all("SELECT preis, menge FROM rezeptur_lief_angebot
                 WHERE rezeptur_id=? AND preis IS NOT NULL AND preis>0
                   AND (einheit IS NULL OR einheit='' OR LOWER(einheit) IN ('kapsel','tablette','softgel','stick','stueck','stück','stk','gummi','gel'))",
                [$rid]);
    if (!$rows) return null;
    $best = null;                                  // guenstigste Zeile, deren Staffel <= gewuenschte Menge
    foreach ($rows as $r) {
        $m = (float)$r['menge']; $p = (float)$r['preis'];
        if ($einheiten > 0 && $m > 0 && $m > $einheiten) continue;  // hoehere Staffel gilt noch nicht
        if ($best === null || $p < $best) $best = $p;
    }
    if ($best === null) foreach ($rows as $r) { $p = (float)$r['preis']; if ($best === null || $p < $best) $best = $p; } // sonst Basis
    return $best;
}

// EK-Kosten eines Produkts je Packung (Rezeptur × Einheiten + Verpackung-EK).
function produkt_ek_pack(?int $pid): float {
    if (!$pid) return 0.0;
    $p = one("SELECT rezeptur_id, verpackung_id, einheiten_pro_packung FROM produkt WHERE id=?", [$pid]);
    if (!$p) return 0.0;
    $verpEk = $p['verpackung_id'] ? (float) scalar("SELECT ek_preis FROM item WHERE id=?", [$p['verpackung_id']]) : 0;
    return rezeptur_kosten_pro_einheit($p['rezeptur_id'] ? (int)$p['rezeptur_id'] : null) * (int)$p['einheiten_pro_packung'] + $verpEk;
}

// ---- Preis-Engine (Phase A) ----
// Globale Margen aus den Einstellungen (app_meta).
function marge_min_prozent(): float { return (float) meta_get('marge_min', 30); }
function marge_typ_prozent(string $form): float { return (float) meta_get('marge_typ_' . $form, meta_get('marge_min', 30)); }
// Standard-Raster (pflegbar in den Einstellungen).
function std_stueckzahlen(): array {
    $r = array_map('intval', array_filter(array_map('trim', explode(',', (string) meta_get('std_stueck', '30,60,90,120,180')))));
    return $r ?: [30, 60, 90, 120, 180];
}
function std_bestellmengen(): array {
    $r = array_map('intval', array_filter(array_map('trim', explode(',', (string) meta_get('std_bestellmenge', '1000,2500,5000,10000')))));
    return $r ?: [1000, 2500, 5000, 10000];
}
// Standard-Füllgewichte für Pulver/Granulat (in Gramm) – Pulver wird nach Gewicht angeboten (z. B. 300 g), nicht nach Stückzahl.
function std_fuellgewichte(): array {
    $r = array_map('intval', array_filter(array_map('trim', explode(',', (string) meta_get('std_fuellgewicht_g', '150,300,500,1000')))));
    return $r ?: [150, 300, 500, 1000];
}
// Standard-Füllvolumen für Flüssig (in ml) – Flüssiges wird nach Volumen angeboten (z. B. 250 ml je Flasche).
function std_fuellvolumen_ml(): array {
    $r = array_map('intval', array_filter(array_map('trim', explode(',', (string) meta_get('std_fuellvolumen_ml', '50,100,250,500')))));
    return $r ?: [50, 100, 250, 500];
}
// Form-abhängiges Größenraster: Pulver/Granulat nach Füllgewicht (g), Flüssig nach Füllvolumen (ml), sonst Stückzahlen.
function std_groessen_fuer(string $form): array {
    if (in_array($form, ['pulver', 'granulat'], true)) return std_fuellgewichte();
    if (in_array($form, ['fluessig', 'gel'], true)) return std_fuellvolumen_ml();
    return std_stueckzahlen();   // Stückzahlen auch für Gummi
}
// Einheit der Packungsgröße je Darreichungsform: 'g' (Pulver/Granulat), 'ml' (Flüssig), '' = Stückzahl.
function form_groessen_einheit(string $form): string {
    if (in_array($form, ['pulver', 'granulat'], true)) return 'g';
    if (in_array($form, ['fluessig', 'gel'], true)) return 'ml';   // Gel wie Flüssig: nach Füllvolumen
    return '';
}
// Wird die Packungsgröße als Füllmenge (g/ml) angefragt statt als Stückzahl?
function form_ist_fuellmenge(string $form): bool { return form_groessen_einheit($form) !== ''; }
// Angefragte Größe je Packung – FORMRICHTIG lesen. Füllmengen-Formen (Pulver/Granulat/Flüssig/Gel)
// stecken in fuellmenge_g (g bzw. ml), Stück-Formen (Kapsel/Tablette/Softgel/Stick/Gummi) in stueck.
// Wichtig: bei einer Kapsel-Anfrage darf ein (z. B. importierter oder veralteter) fuellmenge_g-Wert
// die Kapselzahl NICHT überschreiben – sonst zeigt das System z. B. 69 statt der angefragten 60.
// Nur wenn das formrichtige Feld leer ist, wird das andere als Rückfall genutzt.
function anfrage_groesse($stueck, $fuellmenge_g, string $form): int {
    $stk = (float)($stueck ?? 0); $fg = (float)($fuellmenge_g ?? 0);
    if (form_ist_fuellmenge($form)) return (int) round($fg > 0 ? $fg : $stk);
    return (int) round($stk > 0 ? $stk : $fg);
}
// Plural der Stück-Einheit (nur für Formen, die nach Stückzahl verkauft werden).
function form_plural(string $form): string {
    return ['kapsel'=>'Kapseln', 'tablette'=>'Tabletten', 'softgel'=>'Softgels', 'stick'=>'Sticks', 'gummi'=>'Gummis'][$form] ?? 'Stück';
}
// Beschriftung einer Packungsgröße: „300 g", „250 ml", „120 Kapseln".
function form_groessen_label(string $form, float $wert): string {
    $e = form_groessen_einheit($form);
    if ($e !== '') return rtrim(rtrim(number_format($wert, 1, ',', '.'), '0'), ',') . ' ' . $e;
    return (int) $wert . ' ' . form_plural($form);
}

// Behälter-EK bei einer Bestellmenge: passende Staffel, sonst flacher item.ek_preis.
function pack_ek_bei_menge(int $verp_id, int $menge): float {
    static $cache = [];
    $ck = $verp_id . ':' . $menge;
    if (array_key_exists($ck, $cache)) return $cache[$ck];
    $st = one("SELECT ek_preis FROM pack_ek_staffel WHERE item_id=? AND menge_ab<=? ORDER BY menge_ab DESC LIMIT 1", [$verp_id, $menge]);
    return $cache[$ck] = $st ? (float) $st['ek_preis'] : (float) scalar("SELECT ek_preis FROM item WHERE id=?", [$verp_id]);
}
// Leerkapsel-EK je Stück für ein Produkt (0 wenn nicht bestimmbar / kein Kapselprodukt).
function produkt_kapsel_ek(int $produkt_id): float {
    $kid = produkt_leerkapsel_id($produkt_id);
    return $kid ? (float) scalar("SELECT ek_preis FROM item WHERE id=?", [$kid]) : 0.0;
}
// ---- Tablette: Presshilfsstoffe ----
// Eine Tablette besteht nicht nur aus den Wirkstoffen der Rezeptur: Füllstoff, Trennmittel und Überzug
// kommen dazu. Beides ist global pflegbar (Einstellungen -> Preise & Margen), weil es je Rezeptur kaum abweicht.
function tablette_hilfsstoff_prozent(): float { return max(0.0, (float) meta_get('tablette_hilfsstoff_prozent', 20)); }
function tablette_hilfsstoff_ek_kg(): float   { return max(0.0, (float) meta_get('tablette_hilfsstoff_ek_kg', 8)); }
// Wirkstoffgewicht einer Einheit (mg) laut Rezeptur.
function rezeptur_gewicht_mg(int $rezeptur_id): float {
    return (float) scalar("SELECT COALESCE(SUM(menge_mg),0) FROM rezeptur_zutat WHERE rezeptur_id=?", [$rezeptur_id]);
}
// Gewicht einer fertigen Tablette (mg) = Wirkstoffe + Presshilfsstoffe. Basis für die Behälter-Auswahl.
function tablette_gewicht_mg(int $rezeptur_id): float {
    return rezeptur_gewicht_mg($rezeptur_id) * (1 + tablette_hilfsstoff_prozent() / 100);
}
// EK der Presshilfsstoffe je Tablette (EUR).
function tablette_hilfsstoff_ek_stueck(int $rezeptur_id): float {
    $mg = rezeptur_gewicht_mg($rezeptur_id) * tablette_hilfsstoff_prozent() / 100;
    return $mg / 1e6 * tablette_hilfsstoff_ek_kg();   // mg -> kg
}
// ---- Flüssig ----
// Die Rezeptur beschreibt eine PORTION (z. B. 10 ml). Wie viel eine Portion ist und was die
// Trägerflüssigkeit (Wasser/Öl/Glycerin) kostet, steht global in den Einstellungen.
function fluessig_portion_ml(): float { return max(0.1, (float) meta_get('fluessig_portion_ml', 10)); }
function fluessig_basis_ek_l(): float { return max(0.0, (float) meta_get('fluessig_basis_ek_l', 3)); }

// ---- Fruchtgummi (Gummibärchen) ----
// Stückware wie Tablette, aber die Grundmasse (Pektin/Gelatine, Zucker/Sirup, Aroma) macht den
// Löwenanteil aus. Deshalb ein Zielgewicht je Gummi + EK der Grundmasse je kg (Einstellungen).
function gummi_gewicht_mg(): float  { return max(1.0, (float) meta_get('gummi_gewicht_mg', 3000)); }   // Fertiggewicht je Gummi (mg)
function gummi_basis_ek_kg(): float { return max(0.0, (float) meta_get('gummi_basis_ek_kg', 4)); }      // EK der Gummimasse je kg
// Fertiggewicht einer Gummi (mg) – Basis für die Behälter-Auswahl (mind. das Wirkstoffgewicht).
function gummi_gewicht_je_stueck(int $rezeptur_id): float { return max(gummi_gewicht_mg(), rezeptur_gewicht_mg($rezeptur_id)); }
// EK der Grundmasse je Gummi (EUR): Zielgewicht minus Wirkstoffgewicht als Grundmasse.
function gummi_basis_ek_stueck(int $rezeptur_id): float {
    $basisMg = max(0.0, gummi_gewicht_mg() - rezeptur_gewicht_mg($rezeptur_id));
    return $basisMg / 1e6 * gummi_basis_ek_kg();   // mg -> kg
}

// ---- Gel (Stick) ----
// Wie Flüssig portionsweise (Rezeptur = eine Portion), eigene zähflüssige Grundmasse je Liter.
function gel_portion_ml(): float { return max(0.1, (float) meta_get('gel_portion_ml', 15)); }
function gel_basis_ek_l(): float { return max(0.0, (float) meta_get('gel_basis_ek_l', 5)); }

// Herstellungs-EK je Packung (nur Rezeptur-Füllung + Leerkapseln bzw. Presshilfsstoffe/Trägerflüssigkeit).
// Der BEHÄLTER kommt separat als eigene Angebotsposition (Dose/Deckel/Etikett kommen extra – EK-Staffel × Verpackungs-Aufschlag).
// $bestellmenge (Packungen) macht die Mengendegression: die Rohstoffe werden mit der Staffel zur
// Gesamtmenge gerechnet – so unterscheiden sich die Zeilen der Preismatrix je Bestellmenge.
function produkt_variante_ek(int $produkt_id, int $stueck, int $verp_id = 0, int $bestellmenge = 0): float {
    $rid  = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [$produkt_id]);
    $form = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rid]) ?: 'kapsel';
    $bm   = max(0, $bestellmenge);
    if (in_array($form, ['pulver', 'granulat'], true)) {
        // $stueck = Füllgewicht in Gramm; Kosten je Gramm = Portionskosten / Portionsgewicht.
        $portionG = rezeptur_gewicht_mg($rid) / 1000;
        $servings = $portionG > 0 ? ((float)$stueck / $portionG) : 0.0;
        return rezeptur_kosten_pro_einheit($rid ?: null, $servings * $bm) * $servings;   // Füllung; Behälter kommt separat
    }
    if ($form === 'fluessig') {
        // $stueck = Füllvolumen in ml; Portionen = Volumen / Portionsvolumen, dazu die Trägerflüssigkeit je ml.
        $servings = (float)$stueck / fluessig_portion_ml();
        return rezeptur_kosten_pro_einheit($rid ?: null, $servings * $bm) * $servings + ((float)$stueck / 1000) * fluessig_basis_ek_l();
    }
    if ($form === 'gel') {
        // Wie Flüssig, aber mit der Gel-Grundmasse je ml.
        $servings = (float)$stueck / gel_portion_ml();
        return rezeptur_kosten_pro_einheit($rid ?: null, $servings * $bm) * $servings + ((float)$stueck / 1000) * gel_basis_ek_l();
    }
    $fuell = rezeptur_kosten_pro_einheit($rid ?: null, $stueck * $bm) * $stueck;
    // Tablette: statt der Leerkapsel kommen die Presshilfsstoffe dazu.
    if ($form === 'tablette') return $fuell + tablette_hilfsstoff_ek_stueck($rid) * $stueck;
    // Gummi: die Grundmasse je Gummi kommt dazu (statt Leerkapsel).
    if ($form === 'gummi') return $fuell + gummi_basis_ek_stueck($rid) * $stueck;
    $kaps  = produkt_kapsel_ek($produkt_id) * $stueck;
    return $fuell + $kaps;
}

// ---- Produkt = Rezeptur x Menge + Verpackung ----
// Passt ein Verpackungsartikel zum Wunsch-Typ aus der Portal-Anfrage (glas/pet/pla/beutel/stick/blister)?
function verpackung_passt_zu_typ(int $item_id, ?string $typ): bool {
    if (!$typ) return true;   // kein Wunsch geäußert -> alles passt
    $it = one("SELECT material, verpackungsart FROM item WHERE id=?", [$item_id]);
    if (!$it) return false;
    $mat = mb_strtolower((string)$it['material']); $art = mb_strtolower((string)$it['verpackungsart']);
    return match ($typ) {
        'glas'    => str_contains($mat, 'glas'),
        'pet'     => str_contains($mat, 'pet'),
        'pla'     => str_contains($mat, 'pla'),
        'beutel'  => $art === 'beutel',
        'stick'   => $art === 'stick',
        'blister' => $art === 'blister',
        'karton'  => $art === 'karton',
        default   => true,
    };
}

// Wunsch-Verpackungstypen (Schlüssel aus $VTYPEN) je Darreichungsform. Der Kunde soll nur sinnvolle
// Kombinationen wählen können (z. B. Sticks in Standbodenbeutel/Karton, nicht in Glas oder „Stick im Stick").
// ZUR PRÜFUNG durch das Team – zentrale Stelle, hier bei Bedarf anpassen.
function verpackung_typen_fuer_form(?string $form): array {
    return match ($form) {
        'kapsel', 'tablette', 'softgel', 'gummi' => ['glas', 'pet', 'beutel', 'blister'],
        'pulver', 'granulat'                     => ['glas', 'pet', 'pla', 'beutel', 'stick'],
        'stick'                                  => ['beutel', 'karton'],
        'fluessig', 'gel'                        => ['glas', 'pet', 'stick'],
        default                                  => ['glas', 'pet', 'pla', 'beutel', 'stick', 'blister', 'karton'],
    };
}
// Den konkreten Behälter für eine Packungsgröße wählen: bevorzugt den Wunsch-Typ des Kunden,
// sonst den Behälter des Vorlage-Produkts, sonst den ersten machbaren. Null = nicht machbar.
// WICHTIG: Hat der Kunde einen Typ gewünscht (z. B. Glas) und es passt keiner, wird NICHT still auf ein
// anderes Material ausgewichen – die Konfiguration gilt dann als nicht machbar.
function behaelter_fuer_groesse(int $vorlage_produkt_id, int $stueck, ?string $typ = null): ?int {
    $rows = all("SELECT DISTINCT verpackung_id FROM produkt_preis WHERE produkt_id=? AND stueck=? ORDER BY verpackung_id", [$vorlage_produkt_id, $stueck]);
    if (!$rows) return null;
    $ids = array_map(fn($r) => (int)$r['verpackung_id'], $rows);
    foreach ($ids as $vid) if (verpackung_passt_zu_typ($vid, $typ)) return $vid;   // Wunsch-Typ zuerst
    if ($typ) return null;                                                          // Wunsch nicht erfüllbar -> nicht machbar
    $eigen = (int) scalar("SELECT verpackung_id FROM produkt WHERE id=?", [$vorlage_produkt_id]);
    if ($eigen && in_array($eigen, $ids, true)) return $eigen;
    return $ids[0];
}
// Das Produkt zu (Rezeptur x Packungsgröße + Behälter) finden – oder anlegen. Ohne Vorlage-Produkt:
// genau der Fall „Kunde hat eine Rezeptur, daraus soll ein Produkt werden". Katalogprodukt (nicht exklusiv).
// $vorlage_produkt_id (optional) liefert Deckel/Etikett/Karton/Beipack und die Verzehrempfehlung.
function produkt_aus_rezeptur(int $rezeptur_id, int $stueck, ?int $verp_id, ?int $vorlage_produkt_id = null): ?int {
    if ($rezeptur_id <= 0 || $stueck <= 0) return null;
    $treffer = one("SELECT id FROM produkt WHERE rezeptur_id=? AND einheiten_pro_packung=? AND (verpackung_id <=> ?) AND exklusiv=0 ORDER BY id LIMIT 1",
                   [$rezeptur_id, $stueck, $verp_id]);
    if ($treffer) return (int)$treffer['id'];
    $v = $vorlage_produkt_id ? one("SELECT * FROM produkt WHERE id=?", [$vorlage_produkt_id]) : null;
    $form = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rezeptur_id]) ?: 'kapsel';
    $rez  = (string) scalar("SELECT name FROM rezeptur WHERE id=?", [$rezeptur_id]) ?: 'Produkt';
    $behName = $verp_id ? (string) scalar("SELECT name FROM item WHERE id=?", [$verp_id]) : '';
    $name = produkt_name_versioniert($rez . ' · ' . form_groessen_label($form, (float)$stueck) . ($behName !== '' ? ' · ' . $behName : ''));
    q("INSERT INTO produkt (nummer,name,kunde_id,rezeptur_id,verpackung_id,verschluss_id,etikett_id,karton_id,beipack_id,leerkapsel_id,exklusiv,einheiten_pro_packung,einnahme_pro_tag,status,notiz)
       VALUES (?,?,NULL,?,?,?,?,?,?,?,0,?,?,?,?)",
      [naechste_nummer('P'), $name, $rezeptur_id, $verp_id,
       $v['verschluss_id'] ?? null, $v['etikett_id'] ?? null, $v['karton_id'] ?? null, $v['beipack_id'] ?? null, $v['leerkapsel_id'] ?? null,
       $stueck, $v['einnahme_pro_tag'] ?? 1, 'aktiv',
       'Aus einer Kundenanfrage entstanden (Rezeptur x Menge + Verpackung).']);
    $neu = insert_id();
    produkt_matrix_generieren($neu);
    return $neu;
}

// Das Produkt zu (Rezeptur des Vorlage-Produkts x Packungsgröße + Behälter) finden – oder anlegen.
// Katalogprodukt (nicht exklusiv, ohne Kunde): der Preis ist kundenspezifisch, das Produkt nicht.
// Deckel/Etikett/Karton/Beipack und Verzehrempfehlung kommen aus dem Vorlage-Produkt.
function produkt_variante_id(int $vorlage_produkt_id, int $stueck, ?int $verp_id): ?int {
    $v = one("SELECT * FROM produkt WHERE id=?", [$vorlage_produkt_id]);
    if (!$v || !$v['rezeptur_id'] || $stueck <= 0) return null;
    $rid = (int)$v['rezeptur_id'];
    // Vorhandenes Produkt mit genau dieser Kombination wiederverwenden (Katalog oder dasselbe Vorlage-Produkt)
    $treffer = one("SELECT id FROM produkt WHERE rezeptur_id=? AND einheiten_pro_packung=?
                    AND (verpackung_id <=> ?) AND (exklusiv=0 OR id=?) ORDER BY id LIMIT 1",
                   [$rid, $stueck, $verp_id, $vorlage_produkt_id]);
    if ($treffer) return (int)$treffer['id'];
    // Sonst neu anlegen
    $form = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rid]) ?: 'kapsel';
    $rez  = (string) scalar("SELECT name FROM rezeptur WHERE id=?", [$rid]) ?: 'Produkt';
    // Der Behälter gehört in den Namen – sonst unterscheiden sich „120 Kapseln im Glas" und
    // „120 Kapseln in der PET-Dose" nur durch ein nichtssagendes v2.
    $behName = $verp_id ? (string) scalar("SELECT name FROM item WHERE id=?", [$verp_id]) : '';
    $name = produkt_name_versioniert($rez . ' · ' . form_groessen_label($form, (float)$stueck) . ($behName !== '' ? ' · ' . $behName : ''));
    q("INSERT INTO produkt (nummer,name,kunde_id,rezeptur_id,verpackung_id,verschluss_id,etikett_id,karton_id,beipack_id,leerkapsel_id,exklusiv,einheiten_pro_packung,einnahme_pro_tag,status,notiz)
       VALUES (?,?,NULL,?,?,?,?,?,?,?,0,?,?,?,?)",
      [naechste_nummer('P'), $name, $rid, $verp_id,
       $v['verschluss_id'], $v['etikett_id'], $v['karton_id'], $v['beipack_id'], $v['leerkapsel_id'],
       $stueck, $v['einnahme_pro_tag'], 'aktiv',
       'Automatisch aus der Angebotskalkulation entstanden (Rezeptur x Menge + Verpackung).']);
    $neu = insert_id();
    produkt_matrix_generieren($neu);   // eigene Preismatrix, damit das Produkt eigenständig kalkulierbar ist
    return $neu;
}
// Alle Konfigurationen eines Angebots als Produkte sichern (idempotent). Gibt die produkt_ids zurück.
// Wird beim Senden des Angebots aufgerufen: ab da kennt das System die Größe/Verpackung als eigenes Produkt.
function angebot_produkte_sichern(int $angebot_id): array {
    $a = one("SELECT * FROM angebot WHERE id=?", [$angebot_id]);
    if (!$a || empty($a['produkt_id'])) return [];
    $out = [];
    foreach (angebot_config_gruppen($a) as $g) {
        $pidV = (int)$g['produkt_id'];
        $form = (string) scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$pidV]) ?: 'kapsel';
        $matrix = angebot_matrix_fuer_gruppe($pidV, $g, $form, null);   // deckt auch frei eingetippte Größen ab
        [$featStk, ] = _angebot_feat($matrix, $form, $g);
        if (!$featStk) continue;
        $verp = behaelter_fuer_groesse($pidV, (int)$featStk, $g['verpackung_typ'] ?? null);
        if (!$verp) continue;   // kein passender Behälter (z. B. Glas gewünscht, keins fasst die Menge) -> kein Produkt
        $neu  = produkt_variante_id($pidV, (int)$featStk, $verp);
        if (!$neu) continue;
        q("INSERT IGNORE INTO angebot_produkt (angebot_id,produkt_id,stueck,verpackung_id) VALUES (?,?,?,?)",
          [$angebot_id, $neu, (int)$featStk, $verp]);
        $out[] = $neu;
    }
    return $out;
}
// Darf dieser Kunde den Preis dieses Produkts sehen? Nur, wenn es ihm angeboten wurde.
// Andere Kunden sehen „auf Anfrage" – Preise sind kundenspezifisch und wandern nicht zwischen Kunden.
function kunde_produkt_preise(int $kunde_id): array {
    $ids = [];
    foreach (all("SELECT DISTINCT produkt_id FROM angebot WHERE kunde_id=? AND produkt_id IS NOT NULL", [$kunde_id]) as $r) $ids[(int)$r['produkt_id']] = true;
    foreach (all("SELECT DISTINCT ap.produkt_id FROM angebot_produkt ap JOIN angebot a ON a.id=ap.angebot_id WHERE a.kunde_id=?", [$kunde_id]) as $r) $ids[(int)$r['produkt_id']] = true;
    return $ids;
}

// ---- Verpackung als eigene Position (Dose/Deckel/Etikett kommen extra) ----
// Aufschlag % für einen Verpackungsartikel: eigener Wert am Artikel, sonst globaler aufschlag_verpackung.
function verpackung_aufschlag_prozent(int $item_id): float {
    static $cache = [];
    if (array_key_exists($item_id, $cache)) return $cache[$item_id];
    $o = scalar("SELECT vk_aufschlag_prozent FROM item WHERE id=?", [$item_id]);
    if ($o !== null && trim((string) $o) !== '') return $cache[$item_id] = (float) $o;
    return $cache[$item_id] = (float) meta_get('aufschlag_verpackung', 30);
}
// VK je Stück eines Verpackungsartikels bei einer Bestellmenge = EK-Staffel × (1 + Aufschlag). Ohne Kundenrabatt.
function verpackung_vk_bei_menge(int $item_id, int $menge): float {
    static $cache = [];
    $ck = $item_id . ':' . $menge;
    if (array_key_exists($ck, $cache)) return $cache[$ck];
    // Direkter VK-Override je Bestellmenge hat Vorrang (von Hand im Verkauf-Reiter gesetzt).
    $vk = scalar("SELECT vk_preis FROM pack_vk_staffel WHERE item_id=? AND menge_ab<=? ORDER BY menge_ab DESC LIMIT 1", [$item_id, $menge]);
    if ($vk !== null && $vk !== false) return $cache[$ck] = (float) $vk;
    return $cache[$ck] = pack_ek_bei_menge($item_id, $menge) * (1 + verpackung_aufschlag_prozent($item_id) / 100);
}
// Eindeutiger interner Produktname: heißen mehrere Produkte gleich, wird fortlaufend „ v2, v3 …" angehängt.
// Der erste behält den Basisnamen (= implizit v1); Groß/Kleinschreibung wird ignoriert.
function produkt_name_versioniert(string $name, int $exclude_id = 0): string {
    $name = trim($name);
    if ($name === '') return $name;
    $base = preg_replace('/\s+v\d+$/i', '', $name);   // vorhandenes „ vN" abtrennen
    if ($base === '') $base = $name;
    $baseExists = false; $used = [];
    foreach (all("SELECT name FROM produkt WHERE id<>?", [$exclude_id]) as $o) {
        $on = trim((string) $o['name']);
        if (strcasecmp($on, $base) === 0) $baseExists = true;
        if (preg_match('/^' . preg_quote($base, '/') . '\s+v(\d+)$/i', $on, $m)) $used[(int) $m[1]] = true;
    }
    if (!$baseExists && !$used) return $base;          // eindeutig -> Basisname
    $n = 2; while (isset($used[$n])) $n++;              // nächste freie Version (Basis = v1)
    return $base . ' v' . $n;
}

// Verknüpfte Verpackungsartikel eines Produkts (Dose/Deckel/Etikett), die gesetzt sind.
// $verp_override: Behälter einer konkreten Matrixzelle – dann wird DER bepreist statt der am Produkt hinterlegte.
function produkt_verpackung_items(int $produkt_id, ?int $verp_override = null): array {
    static $cache = [];
    $ck = $produkt_id . ':' . ($verp_override ?? 0);
    if (array_key_exists($ck, $cache)) return $cache[$ck];
    $p = one("SELECT verpackung_id, verschluss_id, etikett_id FROM produkt WHERE id=?", [$produkt_id]);
    if (!$p) return $cache[$ck] = [];
    if ($verp_override) $p['verpackung_id'] = $verp_override;
    // Kein Etikett am Produkt hinterlegt? Passendes Etikett automatisch aus dem Behälter ableiten (Maße/Endformat
    // am Behälter, wie v3) – so erscheint das Etikett als Position + Preis, ohne es am Produkt pflegen zu müssen.
    if (empty($p['etikett_id']) && !empty($p['verpackung_id'])) {
        $autoEt = etikett_id_fuer_behaelter((int)$p['verpackung_id']);
        if ($autoEt) $p['etikett_id'] = $autoEt;
    }
    $out = [];
    foreach (['verpackung_id'=>'Verpackung', 'verschluss_id'=>'Deckel', 'etikett_id'=>'Etikett'] as $f => $rolle) {
        if (!empty($p[$f])) {
            $it = one("SELECT id, name, artikelnummer, volumen_ml, etikett_format FROM item WHERE id=?", [(int)$p[$f]]);
            if ($it) $out[] = ['rolle' => $rolle, 'id' => (int)$it['id'], 'name' => $it['name'], 'artikelnummer' => $it['artikelnummer'],
                               'volumen_ml' => $it['volumen_ml'], 'etikett_format' => $it['etikett_format']];
        }
    }
    return $cache[$ck] = $out;
}
// Überschrift + Beschreibung einer Verpackungs-/Etikett-Position: Art in die Überschrift, Größe in die Beschreibung.
// $vp: ['rolle'=>'Verpackung'|'Deckel'|'Etikett', 'name'=>…, 'volumen_ml'=>…?, 'etikett_format'=>…?]
function verpackung_zeile_teile(array $vp): array {
    $rolle = (string)($vp['rolle'] ?? 'Verpackung');
    $name  = trim((string)($vp['name'] ?? ''));
    if ($rolle === 'Etikett') {
        // Größe = Etikettenformat (B x H mm); der Behälter-Zusatz in Klammern entfällt in der Überschrift.
        $fmt = trim((string)($vp['etikett_format'] ?? ''));
        if ($fmt === '' && preg_match('/(\d+\s*[x×]\s*\d+\s*mm)/iu', $name, $m)) $fmt = trim($m[1]);
        return ['bezeichnung' => 'Etikett', 'beschreibung' => $fmt];
    }
    // Verpackung/Deckel: Volumen als Größe in die Beschreibung, reine Art in die Überschrift.
    $groesse = !empty($vp['volumen_ml']) ? (number_format((float)$vp['volumen_ml'], 0, ',', '.') . ' ml') : '';
    if ($groesse === '' && preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*ml\b/i', $name, $m)) $groesse = trim($m[1]) . ' ml';
    $art = trim(preg_replace('/^\s*\d+(?:[.,]\d+)?\s*ml\s*/i', '', $name));   // Volumen-Präfix raus
    if ($art === '') $art = $name;
    return ['bezeichnung' => $rolle . ': ' . $art, 'beschreibung' => $groesse];
}
// Summe Verpackungs-VK je Packung (Dose+Deckel+Etikett) bei einer Bestellmenge, mit Kundenrabatt.
function produkt_verpackung_vk_je_pack(int $produkt_id, int $menge, ?int $kunde_id): float {
    $s = 0.0;
    foreach (produkt_verpackung_items($produkt_id) as $vp) $s += verpackung_vk_bei_menge($vp['id'], $menge);
    return vk_fuer_kunde($s, $kunde_id);
}
// Verpackungs-Summe je Packung in CENT (jede Position einzeln auf Cent gerundet – wie auf dem Beleg).
// $verp_override: Behälter der gewählten Matrixzelle – dann wird DER bepreist (sonst der am Produkt hinterlegte).
function verpackung_cent_je_pack(int $produkt_id, int $bestellmenge, ?int $kunde_id, ?int $verp_override = null): int {
    $c = 0;
    foreach (produkt_verpackung_items($produkt_id, $verp_override) as $vp) $c += (int) round(vk_fuer_kunde(verpackung_vk_bei_menge($vp['id'], $bestellmenge), $kunde_id) * 100);
    return $c;
}
// Netto (Cent) für eine Angebotszelle: (Herstellung + Verpackung) je Packung, je Position auf Cent gerundet, × Bestellmenge.
// $verp_override sorgt dafür, dass der Behälter DER ZELLE bepreist wird – sonst weicht der berechnete
// Betrag vom angezeigten ab, sobald der Kunde einen anderen Behälter wählt als am Produkt hinterlegt.
function angebot_zelle_netto_cent(int $produkt_id, int $stueck, int $bestellmenge, ?int $kunde_id, ?int $verp_override = null): int {
    $vkH = scalar("SELECT vk_preis FROM produkt_preis WHERE produkt_id=? AND stueck=? AND bestellmenge=? ORDER BY vk_preis ASC LIMIT 1", [$produkt_id, $stueck, $bestellmenge]);
    if ($vkH === null || $vkH === false) return 0;
    $hCent = (int) round(vk_fuer_kunde((float)$vkH, $kunde_id) * 100);
    return ($hCent + verpackung_cent_je_pack($produkt_id, $bestellmenge, $kunde_id, $verp_override)) * $bestellmenge;
}

// ---- Angebots-Positionen (Hybrid: automatisch erzeugt, überschreibbar) ----
// USt-Satz für einen Kunden: Kleinunternehmer/EU-Ausland -> 0 %, sonst Inland-Satz.
function angebot_ust_satz(?int $kunde_id): float {
    return kunde_ust_satz((int)$kunde_id);
}
// Zentrale, korrekte USt-Logik für einen Kunden. WICHTIG: 0 % (steuerfreie innergemeinschaftliche
// Lieferung/Ausfuhr) NUR bei Nicht-DE MIT USt-IdNr. Leeres/unbekanntes Land ODER Nicht-DE OHNE USt-IdNr
// -> Inland-Satz (keine ungerechtfertigte 0 %, die sonst bei fehlender Adresse entstand). Groß/klein egal.
function kunde_ust_satz(int $kunde_id): float {
    if ((string) meta_get('kleinunternehmer', '0') === '1') return 0.0;
    $ustInland = (float) meta_get('ust_inland', 19);
    if ($kunde_id <= 0) return $ustInland;
    $k = one("SELECT land, rechnung_land, ust_id FROM kunden WHERE id=?", [$kunde_id]);
    if (!$k) return $ustInland;
    $land = strtoupper(trim((string)($k['rechnung_land'] ?? '') ?: (string)($k['land'] ?? '')));
    $istDE = ($land === '' || in_array($land, ['DE','D','DEUTSCHLAND','GERMANY'], true));
    if ($istDE) return $ustInland;
    $hatUstId = trim((string)($k['ust_id'] ?? '')) !== '';
    return $hatUstId ? 0.0 : $ustInland;   // Nicht-DE ohne USt-IdNr -> trotzdem Inland-Satz (sicher)
}
// USt-Satz für ein PRODUKT an einen Kunden: Reverse-Charge/Kleinunternehmer/EU-mit-USt-IdNr (= kunde_ust_satz
// liefert 0) gewinnt immer -> 0 %. Sonst der Produktsatz (produkt.mwst_satz), Standard = Inland (19 %).
// So sind Produkte standardmäßig 19 %, per Produkt überschreibbar; die Kunden-Steuerbefreiung bleibt erhalten.
function produkt_ust_satz(int $produkt_id, int $kunde_id): float {
    if (kunde_ust_satz($kunde_id) <= 0.0) return 0.0;   // steuerfrei (EU-IdNr/Export/Kleinunternehmer)
    $std = (float) meta_get('ust_inland', 19);
    if ($produkt_id <= 0) return $std;
    $p = scalar("SELECT mwst_satz FROM produkt WHERE id=?", [$produkt_id]);
    return ($p !== null && $p !== '') ? mwst_normalisieren((float)$p) : $std;
}
// Hat der Kunde eine brauchbare Rechnungsadresse (Haupt- ODER Rechnungsadresse vollständig)?
function kunde_hat_rechnungsadresse(int $kunde_id): bool {
    if ($kunde_id <= 0) return false;
    $k = one("SELECT strasse,plz,ort, rechnung_strasse,rechnung_plz,rechnung_ort FROM kunden WHERE id=?", [$kunde_id]);
    if (!$k) return false;
    $voll = fn($s,$p,$o) => trim((string)($k[$s] ?? '')) !== '' && trim((string)($k[$p] ?? '')) !== '' && trim((string)($k[$o] ?? '')) !== '';
    return $voll('strasse','plz','ort') || $voll('rechnung_strasse','rechnung_plz','rechnung_ort');
}
// Eine (offene) Rechnung neu berechnen: USt-Satz + Betrag + Brutto aus dem aktuellen Kunden neu setzen und –
// falls jetzt eine Adresse vorliegt – den „Adresse fehlt"-Hinweis entfernen. NICHT bei bezahlten/stornierten.
// Rückgabe: ['ok'=>bool, 'ust_prozent'=>float, 'sichtbar'=>bool, 'fehler'=>string].
// Die detaillierten Positionen (Produkt + Glas + Deckel + Etikett …) aus dem Auftrag/Angebot in die
// beleg_position-Tabelle MATERIALISIEREN – so sieht das Team im Buchhaltungs-Beleg dieselbe Aufschlüsselung
// wie der Kunde, die Team-PDF funktioniert und alle Summen/USt sind konsistent. $ustSatz = der anzuwendende
// Satz (sonst Produktsatz). Rückgabe: Anzahl Positionszeilen. Setzt auch die Kopf-Summen (netto/ust/brutto).
function beleg_positionen_materialisieren(int $beleg_id, ?float $ustSatz = null): int {
    $b = one("SELECT * FROM beleg WHERE id=? AND typ='rechnung'", [$beleg_id]);
    if (!$b || empty($b['auftrag_id'])) return 0;
    $auf = one("SELECT * FROM auftrag WHERE id=?", [(int)$b['auftrag_id']]);
    if (!$auf) return 0;
    if ($ustSatz === null) $ustSatz = produkt_ust_satz((int)($auf['produkt_id'] ?? 0), (int)$b['kunde_id']);
    $menge = max(1, (int)($auf['menge'] ?? 0));
    $nettoGesamt = (float)($b['netto'] ?? 0) ?: (float)($auf['gesamt_netto'] ?? 0);
    $pos = beleg_positionen_aus_auftrag(['angebot_id'=>$auf['angebot_id'] ?? null, 'menge'=>$menge, 'gesamt_netto'=>$nettoGesamt], $ustSatz);
    if (!$pos) {   // keine exakte Aufschlüsselung -> eine Sammelposition (Produktname)
        $bez = (string) scalar("SELECT COALESCE(NULLIF(kundenname,''),name) FROM produkt WHERE id=?", [(int)($auf['produkt_id'] ?? 0)]) ?: 'Produkt';
        $rezNr = (string) scalar("SELECT r.nummer FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [(int)($auf['produkt_id'] ?? 0)]);
        $pos = [['artikelnr'=>$rezNr, 'bezeichnung'=>$bez, 'beschreibung'=>'', 'menge'=>$menge, 'einheit'=>'Stk.',
                 'preis_cent'=>(int) round(($menge > 0 ? $nettoGesamt / $menge : $nettoGesamt) * 100), 'ust_satz'=>$ustSatz]];
    }
    q("DELETE FROM beleg_position WHERE beleg_id=?", [$beleg_id]);
    $sort = 0;
    foreach ($pos as $p) {
        q("INSERT INTO beleg_position (beleg_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,mwst_satz) VALUES (?,?,?,?,?,?,?,?,?)",
          [$beleg_id, $sort++, (string)($p['artikelnr'] ?? ''), (string)$p['bezeichnung'], (string)($p['beschreibung'] ?? ''),
           (float)$p['menge'], (string)($p['einheit'] ?? 'Stk.'), (int)$p['preis_cent'], (float)($p['ust_satz'] ?? $ustSatz)]);
    }
    $rows = all("SELECT menge, preis_cent, mwst_satz FROM beleg_position WHERE beleg_id=?", [$beleg_id]);
    $s = beleg_summen_aus_positionen($rows);
    q("UPDATE beleg SET netto=?, ust_prozent=?, ust_betrag=?, brutto=? WHERE id=?",
      [round((float)$s['netto'],2), $ustSatz, round((float)$s['ust'],2), round((float)$s['brutto'],2), $beleg_id]);
    return count($pos);
}

function beleg_neu_berechnen(int $beleg_id): array {
    $b = one("SELECT * FROM beleg WHERE id=? AND typ='rechnung'", [$beleg_id]);
    if (!$b) return ['ok'=>false, 'fehler'=>'Rechnung nicht gefunden.'];
    if (in_array((string)$b['status'], ['bezahlt','storniert'], true)) return ['ok'=>false, 'fehler'=>'Bezahlte/stornierte Rechnungen werden nicht neu berechnet.'];
    $pidB  = (int) scalar("SELECT produkt_id FROM auftrag WHERE id=?", [(int)($b['auftrag_id'] ?? 0)]);
    $ustP  = produkt_ust_satz($pidB, (int)$b['kunde_id']);
    // Positionen materialisieren (falls nur Gesamtbetrag) + auf den neuen Satz setzen; Kopf-Summen daraus.
    if (!empty($b['auftrag_id'])) {
        beleg_positionen_materialisieren($beleg_id, $ustP);
    } else {
        // Freie Rechnung ohne Auftrag: vorhandene Positionen auf den Satz setzen, Summen neu bilden.
        q("UPDATE beleg_position SET mwst_satz=? WHERE beleg_id=?", [$ustP, $beleg_id]);
        $rows = all("SELECT menge, preis_cent, mwst_satz FROM beleg_position WHERE beleg_id=?", [$beleg_id]);
        if ($rows) { $s = beleg_summen_aus_positionen($rows); $netto = round((float)$s['netto'],2); $ust = round((float)$s['ust'],2); $brutto = round((float)$s['brutto'],2); }
        else       { $netto = (float)$b['netto']; $ust = round($netto * $ustP / 100, 2); $brutto = round($netto + $ust, 2); }
        q("UPDATE beleg SET ust_prozent=?, ust_betrag=?, brutto=? WHERE id=?", [$ustP, $ust, $brutto, $beleg_id]);
    }
    $hatAdr = kunde_hat_rechnungsadresse((int)$b['kunde_id']);
    $text   = (string)($b['text'] ?? '');
    if ($hatAdr) { $text = trim(preg_replace('/Rechnungsadresse fehlt[^\n]*/u', '', $text)); q("UPDATE beleg SET text=? WHERE id=?", [$text !== '' ? $text : null, $beleg_id]); }
    return ['ok'=>true, 'ust_prozent'=>$ustP, 'sichtbar'=>$hatAdr, 'fehler'=>''];
}
// Die einzig zulaessigen deutschen Mehrwertsteuersaetze. Nichts anderes darf in einer Position stehen.
function mwst_saetze(): array { return [0.0, 7.0, 19.0]; }
// Beliebigen (evtl. falsch importierten) Wert auf einen zulaessigen Satz ziehen.
// Grenzen: unter 3,5 -> 0 %, bis unter 8,5 -> 7 % (nur nahe an echten 7), sonst 19 %.
// Nahrungsergaenzung ist hier praktisch immer 19 %; ein falscher Wert wie 10 wird darum zu 19,
// nicht zu 7. Ein echter 7er bleibt 7. Im Angebot laesst sich der Satz per Dropdown korrigieren.
function mwst_normalisieren(float $v): float {
    if ($v < 3.5) return 0.0;
    if ($v < 8.5) return 7.0;
    return 19.0;
}
// Preismatrix eines Produkts, Marge-Override berücksichtigt: [stueck][bestellmenge] = ['vk'=>, 'ek'=>].
function angebot_matrix(int $produkt_id, ?float $marge_override): array {
    $m = [];
    foreach (all("SELECT stueck,bestellmenge,ek_preis,vk_preis FROM produkt_preis WHERE produkt_id=? ORDER BY vk_preis ASC", [$produkt_id]) as $r) {
        $s = (int)$r['stueck']; $bm = (int)$r['bestellmenge'];
        $vk = $marge_override !== null ? (float)$r['ek_preis'] * (1 + $marge_override/100) : (float)$r['vk_preis'];
        if (!isset($m[$s][$bm])) $m[$s][$bm] = ['vk'=>$vk, 'ek'=>(float)$r['ek_preis']];
    }
    return $m;
}
// Angefragte Positionen eines Angebots (Multiprodukt): aus portal_anfrage_pos, sonst Inline-Einzelanfrage,
// sonst nur das Angebots-Produkt. Rückgabe je Item: produkt_id, stueck, fuellmenge_g, verpackung_typ, menge.
function angebot_anfrage_items(array $a): array {
    $anfId = (int)($a['anfrage_id'] ?? 0);
    $items = [];
    if ($anfId) {
        $rows = all("SELECT produkt_id,stueck,fuellmenge_g,verpackung_typ,menge FROM portal_anfrage_pos WHERE anfrage_id=? ORDER BY sort,id", [$anfId]);
        if ($rows) $items = array_map(fn($r) => [
            'produkt_id'=>(int)$r['produkt_id'], 'stueck'=>(int)$r['stueck'],
            'fuellmenge_g'=>$r['fuellmenge_g'] !== null ? (float)$r['fuellmenge_g'] : 0.0,
            'verpackung_typ'=>$r['verpackung_typ'], 'menge'=>(int)$r['menge']], $rows);
        else {
            $h = one("SELECT produkt_id,stueck,fuellmenge_g,verpackung_typ,menge FROM portal_anfrage WHERE id=?", [$anfId]);
            if ($h && $h['produkt_id']) $items = [[
                'produkt_id'=>(int)$h['produkt_id'], 'stueck'=>(int)$h['stueck'],
                'fuellmenge_g'=>$h['fuellmenge_g'] !== null ? (float)$h['fuellmenge_g'] : 0.0,
                'verpackung_typ'=>$h['verpackung_typ'], 'menge'=>(int)$h['menge']]];
        }
    }
    if (!$items && !empty($a['produkt_id'])) $items = [['produkt_id'=>(int)$a['produkt_id'], 'stueck'=>0, 'fuellmenge_g'=>0.0, 'verpackung_typ'=>null, 'menge'=>0]];
    // Fehlt die Stückzahl (kein Anfragewert), Produkt-Standardmenge (einheiten_pro_packung) nutzen – nicht die kleinste Matrixstufe.
    foreach ($items as &$it) {
        if ((int)$it['stueck'] <= 0 && (float)$it['fuellmenge_g'] <= 0 && !empty($it['produkt_id'])) {
            $it['stueck'] = (int) scalar("SELECT einheiten_pro_packung FROM produkt WHERE id=?", [(int)$it['produkt_id']]);
        }
    }
    unset($it);
    return $items;
}
// Konfigurationsgruppen: je (Produkt · Stück/Füllmenge · Verpackung) eine Gruppe, mit allen angefragten Mengen (Staffeln).
function angebot_config_gruppen(array $a): array {
    $groups = [];
    foreach (angebot_anfrage_items($a) as $it) {
        if (empty($it['produkt_id'])) continue;
        $key = $it['produkt_id'] . '|' . (int)($it['stueck'] ?? 0) . '|' . (float)($it['fuellmenge_g'] ?? 0) . '|' . ($it['verpackung_typ'] ?? '');
        if (!isset($groups[$key])) $groups[$key] = ['produkt_id'=>(int)$it['produkt_id'], 'stueck'=>(int)($it['stueck'] ?? 0), 'fuellmenge_g'=>(float)($it['fuellmenge_g'] ?? 0), 'verpackung_typ'=>$it['verpackung_typ'] ?? null, 'mengen'=>[]];
        if ((int)($it['menge'] ?? 0) > 0 && !in_array((int)$it['menge'], $groups[$key]['mengen'], true)) $groups[$key]['mengen'][] = (int)$it['menge'];
    }
    foreach ($groups as &$g) sort($g['mengen']);
    return array_values($groups);
}
// Für eine Gruppe: primäre Packungsgröße + Bestellmenge aus der Matrix bestimmen (mit Fallback aufs Standardraster).
// Bei Füllmengen-Formen (Pulver/Granulat g, Flüssig ml) steckt die Größe in fuellmenge_g, sonst in stueck.
function _angebot_feat(array $matrix, string $form, array $g): array {
    $featStk = form_ist_fuellmenge($form) ? (float)($g['fuellmenge_g'] ?? 0) : (int)($g['stueck'] ?? 0);
    if (!$featStk || !isset($matrix[$featStk])) { foreach (std_groessen_fuer($form) as $s2) if (isset($matrix[$s2])) { $featStk = $s2; break; } }
    $primaer = $g['mengen'] ? min($g['mengen']) : 0;
    if (!$primaer || !isset($matrix[$featStk][$primaer])) { $primaer = 0; foreach (std_bestellmengen() as $bm2) if (isset($matrix[$featStk][$bm2])) { $primaer = $bm2; break; } }
    return [$featStk, $primaer];
}
// Positionen EINER Konfigurationsgruppe (Herstellung mit Rezeptur + Verpackung). $letter = Gruppenbuchstabe oder null.
// Ohne Präfix in der Bezeichnung – die A)/B)-Kennzeichnung macht angebot_positionen_prefix() bzw. der PDF-/Editor-Renderer.
function angebot_gruppe_positionen(array $g, ?float $mo, ?int $kid, ?string $letter = null): array {
    $pid = (int)$g['produkt_id'];
    $ust = angebot_ust_satz($kid);
    $form = (string) scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$pid]) ?: 'kapsel';
    $matrix = angebot_matrix_fuer_gruppe($pid, $g, $form, $mo);   // deckt auch frei eingetippte Größen ab
    // Portionsweise Formen: Pulver/Granulat (g), Flüssig (ml) und Sticks beschreiben die Rezeptur je Portion.
    $jePortion = form_ist_fuellmenge($form) || $form === 'stick';
    [$featStk, $featMenge] = _angebot_feat($matrix, $form, $g);
    $pname = (string) scalar("SELECT COALESCE(NULLIF(kundenname,''), name) FROM produkt WHERE id=?", [$pid]) ?: 'Produkt';
    $stkLabel = form_groessen_label($form, (float)$featStk);
    $cell = ($featStk && $featMenge && isset($matrix[$featStk][$featMenge])) ? $matrix[$featStk][$featMenge] : ['vk'=>0.0,'ek'=>0.0];
    $rid = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [$pid]);
    $rezLines = []; $totalMg = 0.0;
    foreach ($rid ? all("SELECT bezeichnung, menge_mg FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [$rid]) : [] as $z) {
        $mg = (float)$z['menge_mg']; $totalMg += $mg;
        $rezLines[] = $z['bezeichnung'] . ' ' . rtrim(rtrim(number_format($mg, 2, ',', ''), '0'), ',') . 'mg';
    }
    $sumMg = (int) round($totalMg / 10) * 10;
    if ($jePortion) $summary = '~' . $sumMg . 'mg je Portion, ' . $stkLabel . ' je Packung';
    else {
        $summary = '~' . $sumMg . 'mg, ' . $stkLabel;
        $kg = in_array($form, ['kapsel','softgel'], true) ? rezeptur_kapselgroesse($rid ?: 0) : null;   // Kapselgröße nur bei Kapseln
        if ($kg && !empty($kg['name'])) $summary .= ', #' . trim(str_ireplace(['Größe', 'Gr.', 'Gr'], '', $kg['name']));
    }
    $besch = $rezLines ? (implode("\n", $rezLines) . "\n" . $summary) : $summary;
    $rezNr = $rid ? (string) scalar("SELECT nummer FROM rezeptur WHERE id=?", [$rid]) : '';
    $out = [[
        'artikelnr'=>$rezNr, 'bezeichnung'=>$pname, 'beschreibung'=>$besch,
        'menge'=>(float)($featMenge ?: 1), 'einheit'=>'Pkg.',
        'preis_cent'=>(int) round(vk_fuer_kunde($cell['vk'], $kid) * 100),
        'ek_cent'=>(int) round($cell['ek'] * 100), 'mwst_satz'=>$ust, 'quelle'=>'herstellung', 'gruppe'=>$letter,
    ]];
    foreach (produkt_verpackung_items($pid) as $vp) {
        $t = verpackung_zeile_teile($vp);   // Art in die Überschrift, Größe/Format in die Beschreibung
        $out[] = [
            'artikelnr'=>$vp['artikelnummer'] ?? '', 'bezeichnung'=>$t['bezeichnung'], 'beschreibung'=>$t['beschreibung'],
            'menge'=>(float)($featMenge ?: 1), 'einheit'=>'Stück',
            'preis_cent'=>(int) round(vk_fuer_kunde(verpackung_vk_bei_menge($vp['id'], $featMenge ?: 1), $kid) * 100),
            'ek_cent'=>(int) round(pack_ek_bei_menge($vp['id'], $featMenge ?: 1) * 100), 'mwst_satz'=>$ust, 'quelle'=>'verpackung', 'gruppe'=>$letter,
        ];
    }
    return $out;
}
// Bezeichnungen mit Gruppen-Präfix „A) …" versehen, wenn mehr als eine Gruppe vorhanden ist (sonst Präfixe entfernen).
function angebot_positionen_prefix(array $pos): array {
    $letters = [];
    foreach ($pos as $p) if (!empty($p['gruppe'])) $letters[$p['gruppe']] = true;
    $mehrere = count($letters) > 1;
    foreach ($pos as &$p) {
        $b = preg_replace('/^[A-Z]\)\s+/', '', (string)$p['bezeichnung']);   // vorhandenes Präfix weg
        $p['bezeichnung'] = ($mehrere && !empty($p['gruppe'])) ? $p['gruppe'] . ') ' . $b : $b;
    }
    unset($p);
    return $pos;
}
// Automatische Positionen: je Konfigurationsgruppe Herstellung + Verpackung. Mehrere Gruppen -> Buchstaben A–Z.
function angebot_positionen_auto(array $a): array {
    $kid = (int)($a['kunde_id'] ?? 0) ?: null;
    $mo = ($a['marge_override'] ?? '') !== '' && $a['marge_override'] !== null ? (float)$a['marge_override'] : null;
    $groups = angebot_config_gruppen($a);
    $mehrere = count($groups) > 1;
    $pos = []; $li = 0;
    foreach ($groups as $g) {
        $letter = $mehrere ? chr(65 + $li) : null;
        foreach (angebot_gruppe_positionen($g, $mo, $kid, $letter) as $row) $pos[] = $row;
        $li++;
    }
    return angebot_positionen_prefix($pos);
}
// „Preis je fertiges Produkt" je Gruppe: All-in (Herstellung + Verpackung) für ALLE angefragten Mengen untereinander.
function angebot_staffel_gruppen(array $a): array {
    $kid = (int)($a['kunde_id'] ?? 0) ?: null;
    $mo = ($a['marge_override'] ?? '') !== '' && $a['marge_override'] !== null ? (float)$a['marge_override'] : null;
    $groups = angebot_config_gruppen($a);
    $mehrere = count($groups) > 1;
    $out = []; $idx = 0;
    foreach ($groups as $g) {
        $pid = (int)$g['produkt_id'];
        $form = (string) scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$pid]) ?: 'kapsel';
        $matrix = angebot_matrix_fuer_gruppe($pid, $g, $form, $mo);   // deckt auch frei eingetippte Größen ab
        $istFuell = form_ist_fuellmenge($form);   // Größe ist eine Füllmenge (g/ml) -> kein Stückpreis
        [$featStk, $primaer] = _angebot_feat($matrix, $form, $g);
        $mengen = $g['mengen'] ?: ($primaer ? [$primaer] : []);
        $rows = [];
        foreach ($mengen as $bm) {
            if (!isset($matrix[$featStk][$bm])) continue;
            $allinCent = (int) round(vk_fuer_kunde($matrix[$featStk][$bm]['vk'], $kid) * 100) + verpackung_cent_je_pack($pid, (int)$bm, $kid);
            $rows[] = ['ab'=>(int)$bm, 'stueck_cent'=>(!$istFuell && $featStk) ? (int) round($allinCent / $featStk) : null, 'pack_cent'=>$allinCent];
        }
        if ($rows) {
            $pname = (string) scalar("SELECT COALESCE(NULLIF(kundenname,''), name) FROM produkt WHERE id=?", [$pid]) ?: 'Produkt';
            $letter = $mehrere ? chr(65 + $idx) . ') ' : '';
            $out[] = ['name'=>$letter . $pname . ' · ' . form_groessen_label($form, (float)$featStk), 'mpp'=>$istFuell ? 0 : $featStk, 'rows'=>$rows];
        }
        $idx++;
    }
    return $out;
}
// Aktuelle (auto oder gespeicherte) Positionen als angebot_position festschreiben, falls noch keine gespeichert sind.
function angebot_positionen_freeze(int $angebot_id): void {
    if (angebot_hat_positionen($angebot_id)) return;
    $a = one("SELECT * FROM angebot WHERE id=?", [$angebot_id]); if (!$a) return;
    $sort = 0;
    foreach (angebot_positionen_auto($a) as $p) {
        q("INSERT INTO angebot_position (angebot_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,ek_cent,mwst_satz,quelle,gruppe) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
          [$angebot_id, $sort++, $p['artikelnr'] ?? '', $p['bezeichnung'], $p['beschreibung'] ?? '', (float)$p['menge'], $p['einheit'] ?? '', (int)$p['preis_cent'], (int)($p['ek_cent'] ?? 0), (float)($p['mwst_satz'] ?? 0), $p['quelle'] ?? 'manuell', $p['gruppe'] ?? null]);
    }
}
// Ein Produkt (eine Konfiguration) als neue Gruppe an ein Angebot anhängen; friert vorher die Automatik ein.
function angebot_produkt_hinzufuegen(int $angebot_id, int $produkt_id, int $stueck, array $mengen): void {
    $a = one("SELECT * FROM angebot WHERE id=?", [$angebot_id]); if (!$a || !$produkt_id) return;
    angebot_positionen_freeze($angebot_id);
    q("UPDATE angebot_position SET gruppe='A' WHERE angebot_id=? AND (gruppe IS NULL OR gruppe='')", [$angebot_id]);
    $anzGrp = (int) scalar("SELECT COUNT(*) FROM (SELECT DISTINCT gruppe FROM angebot_position WHERE angebot_id=? AND gruppe IS NOT NULL AND gruppe<>'') t", [$angebot_id]);
    $letter = chr(65 + $anzGrp);   // erste Gruppe A, dann B, C …
    $mo = ($a['marge_override'] ?? '') !== '' && $a['marge_override'] !== null ? (float)$a['marge_override'] : null;
    $kid = (int)($a['kunde_id'] ?? 0) ?: null;
    if (!$stueck) $stueck = (int) scalar("SELECT einheiten_pro_packung FROM produkt WHERE id=?", [$produkt_id]);
    $g = ['produkt_id'=>$produkt_id, 'stueck'=>$stueck, 'fuellmenge_g'=>0.0, 'verpackung_typ'=>null, 'mengen'=>$mengen];
    $sort = (int) scalar("SELECT COALESCE(MAX(sort),-1)+1 FROM angebot_position WHERE angebot_id=?", [$angebot_id]);
    foreach (angebot_gruppe_positionen($g, $mo, $kid, $letter) as $p) {
        q("INSERT INTO angebot_position (angebot_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,ek_cent,mwst_satz,quelle,gruppe) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
          [$angebot_id, $sort++, $p['artikelnr'] ?? '', $p['bezeichnung'], $p['beschreibung'] ?? '', (float)$p['menge'], $p['einheit'] ?? '', (int)$p['preis_cent'], (int)($p['ek_cent'] ?? 0), (float)($p['mwst_satz'] ?? 0), $p['quelle'] ?? 'herstellung', $letter]);
    }
    // Bezeichnungen mit A)/B)-Präfix normalisieren
    $rows = all("SELECT id,bezeichnung,gruppe FROM angebot_position WHERE angebot_id=? ORDER BY sort,id", [$angebot_id]);
    foreach (angebot_positionen_prefix($rows) as $p) q("UPDATE angebot_position SET bezeichnung=? WHERE id=?", [$p['bezeichnung'], (int)$p['id']]);
}
// Leerkapsel-EK je Stück passend zur Kapselgröße einer Rezeptur (0 wenn keine hinterlegt / kein Kapselprodukt).
function leerkapsel_ek_fuer_rezeptur(int $rid): float {
    $kg = rezeptur_kapselgroesse($rid);
    if (!$kg) return 0.0;
    $ek = scalar("SELECT ek_preis FROM item WHERE kategorie='rohstoff' AND form='kapselhuelle' AND kapselgroesse_id=? AND gesperrt=0 ORDER BY ek_preis ASC LIMIT 1", [(int)$kg['id']]);
    return $ek !== null ? (float)$ek : 0.0;
}
// Beliebige Positionszeilen als neue Gruppe an ein Angebot anhängen (friert vorher die Automatik ein, vergibt Buchstaben).
function angebot_gruppe_anhaengen(int $aid, array $rows): void {
    if (!$rows) return;
    angebot_positionen_freeze($aid);
    q("UPDATE angebot_position SET gruppe='A' WHERE angebot_id=? AND (gruppe IS NULL OR gruppe='')", [$aid]);
    $anzGrp = (int) scalar("SELECT COUNT(*) FROM (SELECT DISTINCT gruppe FROM angebot_position WHERE angebot_id=? AND gruppe IS NOT NULL AND gruppe<>'') t", [$aid]);
    $letter = chr(65 + $anzGrp);   // erste Gruppe A, dann B, C …
    $sort = (int) scalar("SELECT COALESCE(MAX(sort),-1)+1 FROM angebot_position WHERE angebot_id=?", [$aid]);
    foreach ($rows as $p) {
        q("INSERT INTO angebot_position (angebot_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,preis_e4,ek_cent,mwst_satz,quelle,gruppe,rezeptur_id,stueck,verpackung_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
          [$aid, $sort++, $p['artikelnr'] ?? '', $p['bezeichnung'], $p['beschreibung'] ?? '', (float)$p['menge'], $p['einheit'] ?? '', (int)$p['preis_cent'], (($p['preis_e4'] ?? null) !== null ? (int)$p['preis_e4'] : null), (int)($p['ek_cent'] ?? 0), (float)($p['mwst_satz'] ?? 0), $p['quelle'] ?? 'manuell', $letter,
           $p['rezeptur_id'] ?? null, $p['stueck'] ?? null, $p['verpackung_id'] ?? null]);
    }
    $all = all("SELECT id,bezeichnung,gruppe FROM angebot_position WHERE angebot_id=? ORDER BY sort,id", [$aid]);
    foreach (angebot_positionen_prefix($all) as $p) q("UPDATE angebot_position SET bezeichnung=? WHERE id=?", [$p['bezeichnung'], (int)$p['id']]);
}
// Positionszeilen aus einer REZEPTUR (frei gewählte Stückzahl + Verpackungen), ohne Produkt-SKU.
function angebot_rezeptur_zeilen(int $rid, int $stueck, array $verp_ids, int $menge, ?float $mo, ?int $kid): array {
    $r = one("SELECT name, darreichungsform FROM rezeptur WHERE id=?", [$rid]); if (!$r) return [];
    $form = $r['darreichungsform'] ?: 'kapsel';
    $jePortion = form_ist_fuellmenge($form) || $form === 'stick';
    $istKapsel = in_array($form, ['kapsel','softgel'], true);
    $ust = angebot_ust_satz($kid);
    $menge = max(1, $menge);
    // EK je Packung – je Form: Pulver/Granulat nach Gramm, Flüssig nach ml, sonst je Einheit (+ Kapsel/Presshilfsstoffe).
    // Die Rohstoffe werden mit der Staffel zur GESAMTMENGE (Einheiten je Packung × Packungen) gerechnet –
    // darum ist dieselbe Konfiguration bei mehr Packungen je Packung günstiger.
    if (in_array($form, ['pulver','granulat'], true)) {
        $portionG = rezeptur_gewicht_mg($rid) / 1000;
        $servings = $portionG > 0 ? $stueck / $portionG : 0;
        $ekH = rezeptur_kosten_pro_einheit($rid, $servings * $menge) * $servings;
    } elseif ($form === 'fluessig') {
        $servings = $stueck / fluessig_portion_ml();
        $ekH = rezeptur_kosten_pro_einheit($rid, $servings * $menge) * $servings + ($stueck / 1000) * fluessig_basis_ek_l();
    } elseif ($form === 'gel') {
        $servings = $stueck / gel_portion_ml();
        $ekH = rezeptur_kosten_pro_einheit($rid, $servings * $menge) * $servings + ($stueck / 1000) * gel_basis_ek_l();
    } else {
        $ekH = rezeptur_kosten_pro_einheit($rid, $stueck * $menge) * $stueck
             + ($istKapsel ? leerkapsel_ek_fuer_rezeptur($rid) * $stueck : 0)
             + ($form === 'tablette' ? tablette_hilfsstoff_ek_stueck($rid) * $stueck : 0)
             + ($form === 'gummi' ? gummi_basis_ek_stueck($rid) * $stueck : 0);
    }
    $marge = $mo !== null ? $mo : max(marge_typ_prozent($form), marge_min_prozent());
    $vkH = vk_fuer_kunde($ekH * (1 + $marge/100), $kid);
    // Rezeptur-Beschreibung (je Zutat eine Zeile + Zusammenfassung)
    $rezLines = []; $totalMg = 0.0;
    foreach (all("SELECT bezeichnung, menge_mg FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [$rid]) as $z) {
        $mg = (float)$z['menge_mg']; $totalMg += $mg;
        $rezLines[] = $z['bezeichnung'] . ' ' . rtrim(rtrim(number_format($mg, 2, ',', ''), '0'), ',') . 'mg';
    }
    $sumMg = (int) round($totalMg / 10) * 10;
    $stkLabel = form_groessen_label($form, (float)$stueck);
    if ($jePortion) $summary = '~' . $sumMg . 'mg je Portion, ' . $stkLabel . ' je Packung';
    else {
        $summary = '~' . $sumMg . 'mg, ' . $stkLabel;
        $kg = $istKapsel ? rezeptur_kapselgroesse($rid) : null;   // Kapselgröße nur bei Kapseln
        if ($kg && !empty($kg['name'])) $summary .= ', #' . trim(str_ireplace(['Größe', 'Gr.', 'Gr'], '', $kg['name']));
    }
    $besch = $rezLines ? (implode("\n", $rezLines) . "\n" . $summary) : $summary;
    // Die angebotene Konfiguration mitspeichern – daraus entsteht beim Annehmen das Produkt.
    $primaer = null; $hatEtikett = false;
    foreach ($verp_ids as $vid) {
        if (!$vid) continue;
        $rolle = (string) scalar("SELECT COALESCE(verpackung_rolle,'primaer') FROM item WHERE id=?", [(int)$vid]);
        if ($rolle === 'primaer' && !$primaer) $primaer = (int)$vid;
        if ($rolle === 'etikett') $hatEtikett = true;
    }
    // Etikett automatisch aus dem Behälter ableiten (Endformat/Maße am Behälter), wenn keins gewählt wurde – wie v3.
    if ($primaer && !$hatEtikett) { $eid = etikett_id_fuer_behaelter($primaer); if ($eid) $verp_ids[] = $eid; }
    $rows = [[
        'artikelnr'=>'', 'bezeichnung'=>$r['name'], 'beschreibung'=>$besch,
        'menge'=>(float)$menge, 'einheit'=>'Pkg.', 'preis_cent'=>(int) round($vkH * 100),
        'ek_cent'=>(int) round($ekH * 100), 'mwst_satz'=>$ust, 'quelle'=>'herstellung',
        'rezeptur_id'=>$rid, 'stueck'=>$stueck, 'verpackung_id'=>$primaer,
    ]];
    foreach ($verp_ids as $vid) {
        $vid = (int)$vid; if (!$vid) continue;
        $vp = one("SELECT name, artikelnummer, verpackung_rolle, volumen_ml, etikett_format FROM item WHERE id=? AND kategorie='verpackung'", [$vid]);
        if (!$vp) continue;
        $rolleLbl = ['primaer'=>'Verpackung','verschluss'=>'Deckel','etikett'=>'Etikett','karton'=>'Karton','beipack'=>'Beipack'][$vp['verpackung_rolle'] ?? 'primaer'] ?? 'Verpackung';
        $t = verpackung_zeile_teile(['rolle'=>$rolleLbl, 'name'=>$vp['name'], 'volumen_ml'=>$vp['volumen_ml'], 'etikett_format'=>$vp['etikett_format']]);
        $rows[] = [
            'artikelnr'=>$vp['artikelnummer'] ?? '', 'bezeichnung'=>$t['bezeichnung'], 'beschreibung'=>$t['beschreibung'],
            'menge'=>(float)$menge, 'einheit'=>'Stück',
            'preis_cent'=>(int) round(vk_fuer_kunde(verpackung_vk_bei_menge($vid, $menge), $kid) * 100),
            'ek_cent'=>(int) round(pack_ek_bei_menge($vid, $menge) * 100), 'mwst_satz'=>$ust, 'quelle'=>'verpackung',
        ];
    }
    return $rows;
}
// Positionszeile aus einem ROHSTOFF (Weiterverkauf): EK-Staffel × Aufschlag, Kundenrabatt.
function angebot_rohstoff_zeile(int $item_id, float $menge, string $einheit, ?int $kid): array {
    $it = one("SELECT name, artikelnummer, preis_bezug, beschaffenheit, dev FROM item WHERE id=? AND kategorie='rohstoff'", [$item_id]); if (!$it) return [];
    $menge = $menge > 0 ? $menge : 1;
    $vk = vk_fuer_kunde(rohstoff_vk_bei_menge($item_id, $menge) ?? 0.0, $kid);
    $ek = rohstoff_ek_bei_menge($item_id, $menge) ?? 0.0;
    return [[
        'artikelnr'=>$it['artikelnummer'] ?? '', 'bezeichnung'=>rohstoff_anzeige_name($it), 'beschreibung'=>'',
        'menge'=>(float)$menge, 'einheit'=>$einheit ?: ($it['preis_bezug'] ?: 'kg'),
        'preis_cent'=>(int) round($vk * 100), 'ek_cent'=>(int) round($ek * 100),
        'mwst_satz'=>angebot_ust_satz($kid), 'quelle'=>'rohstoff',
    ]];
}
// Positionen eines Angebots: gespeicherte (überschrieben) haben Vorrang, sonst automatisch.
// Angebot aus einer alten Mengen-Staffel (angebot_staffel) in Positionen abbilden – je Staffel eine
// Gruppe. Fallback fuer v3-importierte Angebote, deren echter Inhalt nur in der Staffel steht (die
// Kundenkarte liest die Staffel; PDF/Detail brauchen Positionen). Rein berechnet, nichts gespeichert.
function angebot_positionen_aus_staffel(array $a, array $staffeln): array {
    $basis = one("SELECT bezeichnung, rezeptur_id, verpackung_id FROM angebot_position WHERE angebot_id=? ORDER BY sort, id LIMIT 1", [(int)$a['id']]);
    $bez   = trim((string)($basis['bezeichnung'] ?? '')) ?: ((string) scalar("SELECT COALESCE(NULLIF(kundenname,''), name) FROM produkt WHERE id=?", [(int)($a['produkt_id'] ?? 0)]) ?: 'Position');
    $rezId = !empty($basis['rezeptur_id']) ? (int)$basis['rezeptur_id'] : ((int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)($a['produkt_id'] ?? 0)]) ?: null);
    $verpId= !empty($basis['verpackung_id']) ? (int)$basis['verpackung_id'] : null;
    $mwst  = produkt_ust_satz((int)($a['produkt_id'] ?? 0), (int)($a['kunde_id'] ?? 0));
    $rezNr = $rezId ? (string) scalar("SELECT nummer FROM rezeptur WHERE id=?", [$rezId]) : '';
    $mehrere = count($staffeln) > 1; $out = []; $i = 0;
    foreach ($staffeln as $s) {
        $besch = ((int)$s['stueck'] > 0 ? (int)$s['stueck'] . ' je Packung · ' : '') . 'Preis je Packung inkl. Verpackung & Etikett';
        $out[] = ['artikelnr'=>$rezNr, 'bezeichnung'=>$bez, 'beschreibung'=>$besch,
            'menge'=>(float)$s['menge'], 'einheit'=>'Pkg.', 'preis_cent'=>(int) round((float)$s['vk_stueck'] * 100),
            'ek_cent'=>0, 'mwst_satz'=>$mwst, 'quelle'=>'staffel', 'gruppe'=>($mehrere ? chr(65 + $i) : null),
            'rezeptur_id'=>$rezId, 'stueck'=>(int)$s['stueck'] ?: null, 'verpackung_id'=>$verpId];
        $i++;
    }
    return $out;
}
// ---- Energetisierung (aus v3): nur für freigeschaltete Kunden (kunden.zeige_energetisierung). ----
// Zeit-/Info-Phase am Auftrag: Startdatum (auftrag.energ_start) -> ab Start „läuft", nach N Tagen „abgeschlossen".
// Dauer global über app_meta['energ_tage'] (Standard 14). Nichts wird persistiert – immer aus dem Datum gerechnet.
function energ_tage(): int { return max(1, (int) meta_get('energ_tage', 14)); }
function kunde_zeigt_energetisierung(int $kunde_id): bool {
    return $kunde_id > 0 && (int) scalar("SELECT zeige_energetisierung FROM kunden WHERE id=?", [$kunde_id]) === 1;
}
// Status aus dem Startdatum: '' = kein Start | 'laeuft' | 'abgeschlossen'.
function energ_status(?string $start): string {
    $start = trim((string)$start); if ($start === '') return '';
    $ts = strtotime($start); if (!$ts) return '';
    return (time() >= $ts + energ_tage() * 86400) ? 'abgeschlossen' : 'laeuft';
}
function energ_rest_tage(?string $start): ?int {
    $ts = trim((string)$start) !== '' ? strtotime((string)$start) : false; if (!$ts) return null;
    return (int) ceil((($ts + energ_tage() * 86400) - time()) / 86400);
}
function energ_fertig_am(?string $start): ?string {
    $ts = trim((string)$start) !== '' ? strtotime((string)$start) : false; if (!$ts) return null;
    return date('Y-m-d', $ts + energ_tage() * 86400);
}
// Kurz-Label der Energetisierung ab einem Startdatum (z. B. charge.energetisiert_am): „läuft · fertig am …
// (noch X Tg)" bzw. „abgeschlossen am …". Dauer fix über energ_tage() (Standard 14).
function energ_text_kurz(?string $start): string {
    if (trim((string)$start) === '') return '';
    $fertig = energ_fertig_am($start);
    $fmt    = $fertig ? date('d.m.Y', strtotime($fertig)) : '';
    if (energ_status($start) === 'abgeschlossen') return 'Energetisierung abgeschlossen' . ($fmt ? ' am ' . $fmt : '');
    $rest = energ_rest_tage($start);
    return 'Energetisierung läuft' . ($fmt ? ' · fertig am ' . $fmt : '') . ($rest !== null && $rest > 0 ? ' (noch ' . $rest . ' Tg)' : '');
}

// ---- Externer Labortest (Drittlabor): paralleler Verlaufs-Punkt, nur für freigeschaltete Kunden. ----
// „Erledigt" automatisch, sobald für den Auftrag (oder dessen Produkt) ein Laborbericht hochgeladen UND
// für den Kunden freigegeben ist (dokument typ='analyse', kunde_sichtbar=1). Vorher „läuft" (Proben beim Labor).
function kunde_will_labortest(int $kunde_id): bool {
    return $kunde_id > 0 && (int) scalar("SELECT labortest_extern FROM kunden WHERE id=?", [$kunde_id]) === 1;
}
// Status des externen Labortests für EINEN Auftrag. Rückgabe: ['status'=>'geplant'|'laeuft'|'abgeschlossen', 'datum'=>?string, 'dok_id'=>?int].
// Ablauf: Probe geht ERST nach der Produktion (mit fertiger Verpackung) ans Labor. Vorher 'geplant' (dunkel),
// ab dem Versand ans Labor 'laeuft' (mit Versanddatum), nach Bericht-Upload 'abgeschlossen' (mit Berichtsdatum).
function auftrag_labortest_status(int $auftrag_id, ?int $produkt_id = null): array {
    if ($auftrag_id <= 0) return ['status' => 'geplant', 'datum' => null, 'dok_id' => null, 'versendet_am' => null];
    if ($produkt_id === null) $produkt_id = (int) scalar("SELECT produkt_id FROM auftrag WHERE id=?", [$auftrag_id]);
    $pid = (int)$produkt_id;
    // Laborbericht zu genau diesem Auftrag ODER (falls vorhanden) zum Produkt des Auftrags, nur wenn freigegeben.
    $d = one("SELECT id, COALESCE(dok_datum, DATE(angelegt)) AS datum FROM dokument
              WHERE typ='analyse' AND kunde_sichtbar=1
                AND ((objekt_typ='auftrag' AND objekt_id=?) OR (objekt_typ='produkt' AND objekt_id=? AND ?>0))
              ORDER BY datum DESC, id DESC LIMIT 1", [$auftrag_id, $pid, $pid]);
    if ($d) return ['status' => 'abgeschlossen', 'datum' => $d['datum'], 'dok_id' => (int)$d['id'], 'versendet_am' => null];
    // Probe ans Labor gesendet (labor_versendet_am) -> läuft; vorher geplant (dunkel, noch nicht beim Labor).
    $vers = scalar("SELECT labor_versendet_am FROM auftrag WHERE id=?", [$auftrag_id]) ?: null;
    if ($vers) return ['status' => 'laeuft', 'datum' => null, 'dok_id' => null, 'versendet_am' => $vers];
    return ['status' => 'geplant', 'datum' => null, 'dok_id' => null, 'versendet_am' => null];
}
// Parallel laufende Zusatz-Schritte eines Auftrags (Energetisierung, externer Labortest) – nur für
// freigeschaltete Kunden. Laufen NEBEN dem Hauptablauf (können früher beginnen / parallel zur Prüfung),
// deshalb nicht in die lineare Phasenkette eingereiht, sondern als eigene Punkte mit eigenem Status.
// Rückgabe je Eintrag: ['label','status'('geplant'|'laeuft'|'abgeschlossen'),'sub','dok_id'?].
function kunde_auftrag_parallel(array $a): array {
    $kid = (int)($a['kunde_id'] ?? 0);
    if ($kid <= 0) return [];
    $out = [];
    // Reihenfolge wie in der Kette gewünscht: erst Laboranalyse, dann Energetisierung (beide nach QC).
    if (kunde_will_labortest($kid)) {
        $lt = auftrag_labortest_status((int)($a['id'] ?? 0), isset($a['produkt_id']) ? (int)$a['produkt_id'] : null);
        $out[] = ['label' => 'Laboranalyse',
                  'status' => $lt['status'],   // geplant | laeuft | abgeschlossen
                  'sub'    => $lt['status'] === 'abgeschlossen'
                                ? ($lt['datum'] ? 'abgeschlossen am ' . date('d.m.Y', strtotime((string)$lt['datum'])) : 'Bericht liegt vor')
                                : ($lt['status'] === 'laeuft'
                                    ? (!empty($lt['versendet_am']) ? 'versendet am ' . date('d.m.Y', strtotime((string)$lt['versendet_am'])) : 'beim Labor')
                                    : 'nach Produktion ans Labor'),   // geplant -> dunkel
                  'dok_id' => $lt['dok_id']];
    }
    if (kunde_zeigt_energetisierung($kid)) {
        $start  = (string)($a['energ_start'] ?? '');
        $stat   = $start !== '' ? energ_status($start) : '';
        $fertig = $start !== '' ? energ_fertig_am($start) : null;
        $out[] = ['label' => 'Energetisierung',
                  'status' => $stat === 'abgeschlossen' ? 'abgeschlossen' : ($stat === 'laeuft' ? 'laeuft' : 'geplant'),
                  // Abgeschlossen: nicht das (vergangene) Fertig-Datum zeigen, sondern „abgeschlossen".
                  'sub'    => $stat === 'abgeschlossen' ? 'abgeschlossen'
                              : ($fertig ? 'bis ' . date('d.m.Y', strtotime((string)$fertig)) : null),
                  'dok_id' => null];
    }
    return $out;
}

// Feste Kunden-Phasen (wie v3) – gleiche Spur im Kundenportal UND in der internen Auftragsansicht.
// Bei Fulfillment-Kunden wird nichts versendet, sondern ins Fremdlager (Lager 2) eingelagert – die
// letzten beiden Phasen heißen dann „Bereit zur Einlagerung" / „Eingelagert" (= abgeschlossen).
function auftrag_phasen(bool $fulfillment = false): array {
    $base = ['Bestätigt', 'Rohstoff bestellt', 'Rohstoff angekommen', 'In Produktion', 'Etikettiert', 'Qualitätsprüfung'];
    // Laboranalyse + Energetisierung kommen (nur für freigeschaltete Kunden) als parallele Punkte
    // nach „Qualitätsprüfung" dazu – eingefügt in kunde_auftrag_track(), nicht hier in der festen Kette.
    return $fulfillment
        ? array_merge($base, ['Bereit zur Einlagerung', 'Eingelagert'])
        : array_merge($base, ['Versandbereit', 'Eingelagert', 'Versendet']);
}
// Aktuelle Phase + Datum je Phase aus den vorhandenen Signalen ableiten.
// Positionen: 0 Bestätigt · 1 Rohstoff bestellt · 2 Rohstoff angekommen · 3 In Produktion · 4 Etikettiert ·
// 5 Qualitätsprüfung · 6 Versandbereit/Bereit zur Einlagerung · 7 Eingelagert · 8 Versendet (nur non-Fulfillment).
function kunde_auftrag_phase(array $a): array {
    $aid = (int)$a['id']; $st = (string)$a['status'];
    $ff  = array_key_exists('nutzt_fulfillment', $a) ? !empty($a['nutzt_fulfillment'])
         : ($aid > 0 ? auftrag_ist_fulfillment($aid) : false);
    $dates = array_fill(0, 9, null);
    $dates[0] = $a['angelegt'] ?? null;                                  // Bestätigt
    $best = one("SELECT COALESCE(MIN(b.bestelldatum), MIN(b.angelegt)) d FROM bestellung b
                 JOIN bestellung_position bp ON bp.bestellung_id=b.id WHERE bp.auftrag_id=?", [$aid]);
    $bestellt = $best && !empty($best['d']);
    if ($bestellt) $dates[1] = $best['d'];
    $angDate = null;
    $we = one("SELECT COALESCE(MIN(wareneingang), MIN(angelegt)) d FROM charge WHERE auftrag_id=?", [$aid]);
    if ($we && !empty($we['d'])) $angDate = $we['d'];
    if (!$angDate) {
        $ba = one("SELECT MIN(b.angekommen_am) d FROM bestellung b JOIN bestellung_position bp ON bp.bestellung_id=b.id
                   WHERE bp.auftrag_id=? AND b.angekommen_am IS NOT NULL", [$aid]);
        if ($ba && !empty($ba['d'])) $angDate = $ba['d'];
    }
    // Admin-Override (manuell gesetzt, z. B. für Alt-Aufträge oder Zukauf ohne verknüpfte Charge/Bestellung).
    if (!$angDate && !empty($a['rohstoff_angekommen_am'])) $angDate = $a['rohstoff_angekommen_am'];
    $angekommen = $angDate !== null;
    if ($angekommen) { $dates[2] = $angDate; $bestellt = true; }
    // Produktionsschritte: Start, „Etikettieren", „Qualitätsprüfung".
    $pa = one("SELECT id FROM produktionsauftrag WHERE auftrag_id=? ORDER BY id DESC LIMIT 1", [$aid]);
    $qcDate = null; $prodStart = null; $etikDate = null; $hasEtikStep = false;
    if ($pa) {
        foreach (all("SELECT station, erledigt, erledigt_at FROM produktion_schritt WHERE pa_id=? ORDER BY sort,id", [(int)$pa['id']]) as $s) {
            $station = (string)$s['station'];
            if (stripos($station, 'Etikett') !== false) $hasEtikStep = true;
            if ((int)$s['erledigt'] === 1 && !empty($s['erledigt_at'])) {
                if ($prodStart === null || $s['erledigt_at'] < $prodStart) $prodStart = $s['erledigt_at'];
                if (stripos($station, 'Qualität') !== false) $qcDate  = $s['erledigt_at'];
                if (stripos($station, 'Etikett')  !== false) $etikDate = $s['erledigt_at'];
            }
        }
    }
    $qcDone        = $qcDate !== null;
    $versandbereit = in_array($st, ['erledigt', 'versendet'], true);
    $versendet     = $st === 'versendet';
    // Etikettiert: der Etikettieren-Schritt ist erledigt – oder das Produkt wird gar nicht etikettiert
    // (kein solcher Schritt), dann gilt es als erledigt, sobald QC/Versandbereit erreicht ist (nie blockierend).
    $etikettiert   = ($etikDate !== null) || (!$hasEtikStep && ($qcDone || $versandbereit));
    $prodGestartet = $prodStart !== null || in_array($st, ['in_produktion', 'erledigt', 'versendet'], true);
    // Eingelagert: Fertigware zum Produktionsauftrag ist als Charge gebucht (Lager 1/2) – oder versendet.
    $eingelagert   = ($pa && produktion_gebucht((int)$pa['id']) > 0.0001) || $versendet;

    if ($prodGestartet) $dates[3] = $prodStart ?: ($st === 'in_produktion' ? ($a['status_datum'] ?? null) : null);
    $dates[4] = $etikDate;
    $dates[5] = $qcDate;
    if ($versandbereit) $dates[6] = $a['status_datum'] ?? ($a['aktualisiert'] ?? null);
    if ($versendet) {
        $end = $a['aktualisiert'] ?? null;
        if ($ff) $dates[7] = $end;
        else { $dates[8] = $end; $dates[7] = $dates[7] ?? $end; }
    }
    // Aktuelle Phase (Index des gerade aktiven Schritts).
    if ($versendet)            $idx = $ff ? 7 : 8;
    elseif ($versandbereit)    $idx = $eingelagert ? 7 : 6;
    elseif ($qcDone)           $idx = 5;
    elseif ($etikettiert)      $idx = 5;                 // Etikettieren fertig -> Qualitätsprüfung aktiv
    elseif ($prodGestartet)    $idx = 3;                 // In Produktion aktiv (Etikettieren noch offen)
    elseif ($angekommen)       $idx = 2;
    elseif ($bestellt)         $idx = 1;
    else                       $idx = 0;
    return ['idx' => $idx, 'dates' => $dates];
}
// Fortschritts-Schritte je Auftrag: feste Phasen + kundenspezifische Zusatz-Schritte (Energetisierung,
// Labortest) nach „Qualitätsprüfung". done=Haken, current=läuft (Sanduhr), sonst leer.
function kunde_auftrag_track(array $a): array {
    $ff = array_key_exists('nutzt_fulfillment', $a) ? !empty($a['nutzt_fulfillment'])
        : (!empty($a['id']) ? auftrag_ist_fulfillment((int)$a['id']) : false);
    $AUFSTEPS = auftrag_phasen($ff);
    $ph = kunde_auftrag_phase($a); $cur = (int)$ph['idx']; $complete = ($a['status'] ?? '') === 'versendet';
    $track = [];
    foreach ($AUFSTEPS as $i => $lbl) {
        $track[] = ['label'=>$lbl, 'date'=>$ph['dates'][$i] ?? null, 'sub'=>null, 'dok_id'=>null,
                    'done'=>($complete || $i < $cur), 'current'=>(!$complete && $i === $cur)];
    }
    $zusatz = kunde_auftrag_parallel($a);
    if ($zusatz) {
        $ins = [];
        foreach ($zusatz as $pz) {
            $ins[] = ['label'=>$pz['label'], 'date'=>null, 'sub'=>$pz['sub'], 'dok_id'=>$pz['dok_id'] ?? null,
                      'done'=>($pz['status']==='abgeschlossen'), 'current'=>($pz['status']==='laeuft')];
        }
        // Direkt NACH „Qualitätsprüfung" einfügen (Position dynamisch, da die Kette variabel lang ist).
        $qi = array_search('Qualitätsprüfung', array_column($track, 'label'), true);
        array_splice($track, $qi !== false ? $qi + 1 : 6, 0, $ins);
    }
    return $track;
}
// Einheitliches Status-Icon fuer die Fortschritts-Punkte: Haken (erledigt), Sanduhr (laeuft), sonst leer.
function auftrag_track_icon(string $state): string {
    static $sand = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-1px"><path d="M6 2h12M6 22h12M6 2c0 4 3 6 6 10 3-4 6-6 6-10M6 22c0-4 3-6 6-10 3 4 6 6 6 10"/></svg>';
    if ($state === 'done' || $state === 'abgeschlossen') return '&#10003;';
    if ($state === 'current' || $state === 'laeuft')      return $sand;
    return '';
}

// Kundenseitiger Status-Text einer Bestellung (für Suche/Listen). Bei Fulfillment-Kunden
// (kunden.nutzt_fulfillment) endet die Bestellung mit „eingelagert" (Lager 2) statt „versendet".
function kunde_bestell_status_label(array $a, ?array $k = null): string {
    $st = (string)($a['status'] ?? '');
    $ff = !empty($k['nutzt_fulfillment']);
    return match ($st) {
        'storniert'     => 'storniert',
        'versendet'     => $ff ? 'eingelagert (abgeschlossen)' : 'versendet (abgeschlossen)',
        'erledigt'      => $ff ? 'wird eingelagert' : 'versandbereit',
        'in_produktion' => 'in Produktion',
        'offen'         => 'in Bearbeitung',
        default         => $st !== '' ? $st : 'in Bearbeitung',
    };
}

// Pro Angebot innerhalb eines Requests mehrfach aufgerufen (Übersicht + Karte) – request-lokal cachen.
// VK je Einheit in e4 (Euro x 10.000): preis_e4 falls gesetzt, sonst aus ganzen Cent (preis_cent) hochgerechnet.
function angpos_e4(array $r): int {
    return (isset($r['preis_e4']) && $r['preis_e4'] !== null && $r['preis_e4'] !== '') ? (int)$r['preis_e4'] : (int)($r['preis_cent'] ?? 0) * 100;
}
// Zeilensumme (netto) in Cent: Menge x VK sub-cent-genau gerechnet, am Ende auf Cent gerundet.
function angpos_netto_cent(array $r): int { return (int) round((float)($r['menge'] ?? 0) * angpos_e4($r) / 100); }
// VK je Einheit als Euro-Zahl (fuer Anzeige mit bis zu 4 Nachkommastellen).
function angpos_vk_eur(array $r): float { return angpos_e4($r) / 10000; }
// VK je Einheit als deutscher Preis-String: ganze Cent -> 2 Nachkommastellen, Sub-Cent -> bis 4 (ohne Null-Schwanz).
function angpos_vk_str(array $r): string {
    $v = angpos_vk_eur($r);
    if (abs($v * 100 - round($v * 100)) <= 1e-9) return number_format($v, 2, ',', '.');
    return rtrim(number_format($v, 4, ',', '.'), '0');
}
function angebot_positionen(int $angebot_id): array {
    static $cache = [];
    if (!array_key_exists($angebot_id, $cache)) $cache[$angebot_id] = angebot_positionen_calc($angebot_id);
    return $cache[$angebot_id];
}
function angebot_positionen_calc(int $angebot_id): array {
    $rows = all("SELECT * FROM angebot_position WHERE angebot_id=? ORDER BY sort, id", [$angebot_id]);
    // Kaputte Null-Positionen (v3-Import: menge=0 & preis=0) ignorieren – sonst verdecken sie den echten Inhalt.
    $echt = array_values(array_filter($rows, fn($r) => (float)$r['menge'] > 1e-9 || (int)$r['preis_cent'] > 0));
    if ($echt) return array_map(fn($r) => [
        'artikelnr'=>$r['artikelnr'], 'bezeichnung'=>$r['bezeichnung'], 'beschreibung'=>$r['beschreibung'],
        'menge'=>(float)$r['menge'], 'einheit'=>$r['einheit'], 'preis_cent'=>(int)$r['preis_cent'],
        'preis_e4'=>((isset($r['preis_e4']) && $r['preis_e4'] !== null && $r['preis_e4'] !== '') ? (int)$r['preis_e4'] : (int)$r['preis_cent'] * 100),
        'ek_cent'=>(int)$r['ek_cent'], 'mwst_satz'=>(float)$r['mwst_satz'], 'quelle'=>$r['quelle'], 'gruppe'=>$r['gruppe'] ?? null,
        'rezeptur_id'=>$r['rezeptur_id'] ?? null, 'stueck'=>$r['stueck'] ?? null, 'verpackung_id'=>$r['verpackung_id'] ?? null,
    ], $echt);
    $a = one("SELECT * FROM angebot WHERE id=?", [$angebot_id]);
    if (!$a) return [];
    // Kein echter Positionsinhalt: erst aus der Staffel ableiten, sonst automatisch aus der Anfrage rechnen.
    $staffeln = all("SELECT menge, stueck, vk_stueck FROM angebot_staffel WHERE angebot_id=? ORDER BY menge", [$angebot_id]);
    if ($staffeln) return angebot_positionen_aus_staffel($a, $staffeln);
    return angebot_positionen_auto($a);
}

// Ein per KI aus einem Angebots-PDF ausgelesenes Angebot in v4 uebernehmen: schreibt die Positionen
// (Herstellung + Glas + Etikett je Staffel, gruppiert nach Menge) und die Staffel („Preis je fertiges
// Produkt"). Ersetzt vorhandene Positionen/Staffeln dieses Angebots. Rueckgabe: Anzahl Positionen.
// Verpackungs-/Etikett-Artikel semantisch finden: v3-Namen ("Weithals … 250 ml") auf den v4-Artikel
// mappen (Volumen + Typ), da die Artikelnummern (VG-/ET- vs. VP-) nicht deckungsgleich sind.
function verpackung_item_finden(string $name, string $rolle): ?int {
    $n = mb_strtolower($name);
    preg_match('/(\d+)\s*(ml|g)\b/u', $n, $m);
    $vol = $m ? $m[1] . $m[2] : '';                       // z. B. "250ml"
    $typ = '';
    foreach (['weithals'=>'weithalsglas', 'pet'=>'petpacker', 'braunglas'=>'braunglas', 'pla'=>'pla', 'becher'=>'pla', 'doypack'=>'doypack', 'beutel'=>'doypack', 'blister'=>'blister', 'dose'=>'dose'] as $k => $v)
        if (mb_strpos($n, $k) !== false) { $typ = $v; break; }
    if ($vol === '' && $typ === '') return null;
    foreach (all("SELECT id, name FROM item WHERE kategorie='verpackung' AND gesperrt=0 AND verpackung_rolle=?", [$rolle]) as $it) {
        $in = str_replace(' ', '', mb_strtolower((string)$it['name']));
        if (($vol === '' || mb_strpos($in, $vol) !== false) && ($typ === '' || mb_strpos($in, $typ) !== false)) return (int)$it['id'];
    }
    return null;
}
// Teil B: nach dem PDF-Einlesen die erkannte Verpackung/Etikett dauerhaft am Produkt hinterlegen
// (nur leere Slots), damit KÜNFTIGE Angebote/Aufträge dieses Produkts automatisch aufschlüsseln.
function angebot_ki_produkt_verpackung(int $angebot_id, array $d): void {
    $a = one("SELECT produkt_id, anfrage_id FROM angebot WHERE id=?", [$angebot_id]);
    $pid = (int)($a['produkt_id'] ?? 0);
    if (!$pid && !empty($a['anfrage_id'])) $pid = (int) scalar("SELECT produkt_id FROM portal_anfrage WHERE id=?", [(int)$a['anfrage_id']]);
    if (!$pid) return;
    $verp = 0; $etik = 0;
    foreach ((array)($d['positionen'] ?? []) as $p) {
        $bez = trim((string)($p['bezeichnung'] ?? '')); if ($bez === '') continue;
        if (mb_stripos($bez, 'etikett') !== false) { if (!$etik) $etik = (int) verpackung_item_finden($bez, 'etikett'); }
        elseif (!$verp) { $verp = (int) verpackung_item_finden($bez, 'primaer'); }
    }
    $stueck = 0; foreach ((array)($d['staffel'] ?? []) as $s) { $stueck = (int)($s['stueck'] ?? 0); if ($stueck > 0) break; }
    $cur = one("SELECT verpackung_id, etikett_id, einheiten_pro_packung FROM produkt WHERE id=?", [$pid]);
    if (!$cur) return;
    $sets = []; $args = [];
    if ($verp && empty($cur['verpackung_id']))                              { $sets[] = 'verpackung_id=?';         $args[] = $verp; }
    if ($etik && empty($cur['etikett_id']))                                 { $sets[] = 'etikett_id=?';            $args[] = $etik; }
    if ($stueck > 0 && (int)($cur['einheiten_pro_packung'] ?? 0) <= 0)      { $sets[] = 'einheiten_pro_packung=?'; $args[] = $stueck; }
    if ($sets) { $args[] = $pid; q("UPDATE produkt SET " . implode(',', $sets) . " WHERE id=?", $args); }
}
function angebot_ki_pdf_uebernehmen(int $angebot_id, array $d): int {
    $a = one("SELECT id, kunde_id, produkt_id FROM angebot WHERE id=?", [$angebot_id]);
    if (!$a) return 0;
    $pos = array_values(array_filter((array)($d['positionen'] ?? []), fn($p) => trim((string)($p['bezeichnung'] ?? '')) !== ''));
    if (!$pos) return 0;
    $mwst = produkt_ust_satz((int)($a['produkt_id'] ?? 0), (int)$a['kunde_id']);
    q("DELETE FROM angebot_position WHERE angebot_id=?", [$angebot_id]);
    q("DELETE FROM angebot_staffel WHERE angebot_id=?", [$angebot_id]);
    $letter = []; $next = 0; $sort = 0;
    foreach ($pos as $p) {
        $menge = (float) str_replace(',', '.', (string)($p['menge'] ?? 0));
        $key = (string)(int)$menge;
        if (!isset($letter[$key])) $letter[$key] = chr(65 + $next++);
        $preis = (float) str_replace(',', '.', (string)($p['preis'] ?? 0));
        q("INSERT INTO angebot_position (angebot_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,ek_cent,mwst_satz,quelle,gruppe)
           VALUES (?,?,?,?,?,?,?,?,0,?,'ki_pdf',?)",
          [$angebot_id, $sort++, mb_substr(trim((string)($p['artikelnr'] ?? '')), 0, 40),
           mb_substr(trim((string)$p['bezeichnung']), 0, 200), trim((string)($p['beschreibung'] ?? '')),
           $menge, mb_substr(trim((string)($p['einheit'] ?? 'Stk.')), 0, 20), (int) round($preis * 100), $mwst, $letter[$key]]);
    }
    $ssort = 0;
    foreach ((array)($d['staffel'] ?? []) as $s) {
        $m = (int) round((float) str_replace(',', '.', (string)($s['menge'] ?? 0)));
        if ($m <= 0) continue;
        q("INSERT INTO angebot_staffel (angebot_id,menge,stueck,vk_stueck,bestaetigt,sort) VALUES (?,?,?,?,0,?)",
          [$angebot_id, $m, (int)($s['stueck'] ?? 0), (float) str_replace(',', '.', (string)($s['preis_pkg'] ?? 0)), $ssort++]);
    }
    // Teil B: Verpackung/Etikett + Stück je Packung dauerhaft am Produkt hinterlegen (nur leere Slots).
    angebot_ki_produkt_verpackung($angebot_id, $d);
    return count($pos);
}
// Rechnungs-/AB-Positionen zu einem Auftrag: die EINZELPOSITIONEN der bestaetigten Konfiguration
// (Herstellung + Verpackung + Deckel + Etikett …) wie im Angebot, statt einer Sammelposition.
// Streng: nur wenn die Summe (Preis je Packung × Menge) EXAKT den Auftrags-Netto ergibt – sonst [].
// So kann eine Rechnung nie eine falsche Summe zeigen; der Aufrufer faellt dann auf die Sammelposition zurueck.
function beleg_positionen_aus_auftrag(array $auf, float $ustSatz): array {
    $angId = (int)($auf['angebot_id'] ?? 0);
    if ($angId <= 0) return [];
    $menge = max(1, (int)($auf['menge'] ?? 0));
    $zielCent = (int) round((float)($auf['gesamt_netto'] ?? 0) * 100);
    if ($zielCent <= 0) return [];
    $pos = angebot_positionen($angId);
    if (!$pos) return [];
    // Nach Konfigurations-Gruppe buendeln (A/B/C …; leer = einzige Gruppe).
    $grp = [];
    foreach ($pos as $p) { $grp[trim((string)($p['gruppe'] ?? ''))][] = $p; }
    foreach ($grp as $rows) {
        $sumPack = 0; foreach ($rows as $r) $sumPack += (int)$r['preis_cent'];   // Preis je Packung (alle Positionen)
        if ($sumPack * $menge !== $zielCent) continue;                            // nur die exakt passende Gruppe
        $out = [];
        foreach ($rows as $r) {
            $bez = preg_replace('/^[A-Z]\)\s*/', '', (string)$r['bezeichnung']);   // Gruppen-Buchstabe raus (eine Konfig)
            $out[] = ['artikelnr'=>(string)($r['artikelnr'] ?? ''),
                      'bezeichnung'=>$bez, 'beschreibung'=>(string)($r['beschreibung'] ?? ''),
                      'menge'=>$menge, 'einheit'=>($r['einheit'] ?: 'Stk.'),
                      'preis_cent'=>(int)$r['preis_cent'], 'ust_satz'=>$ustSatz];
        }
        return $out;
    }
    return [];
}
// „Preis je fertiges Produkt"-Zeile fuer Rechnung/AB (eine Konfiguration) – wie im Angebot.
function beleg_staffel_aus_auftrag(array $auf): array {
    $menge = max(1, (int)($auf['menge'] ?? 0));
    $netto = (float)($auf['gesamt_netto'] ?? 0);
    if ($netto <= 0) return [];
    $packCent = (int) round($netto * 100 / $menge);
    $stk = (int)($auf['stueck'] ?? 0);
    // Name = Produktname (die Größe ergänzt build_beleg_pdf über mpp als „(N Stück/Packung)").
    $name = (string)($auf['produkt_name'] ?? '') ?: 'Produkt';
    return [[
        'name' => $name, 'mpp' => $stk ?: 0,
        'rows' => [['ab'=>$menge, 'stueck_cent'=>($stk>0 ? (int) round($packCent / $stk) : null), 'pack_cent'=>$packCent]],
    ]];
}
function angebot_hat_positionen(int $angebot_id): bool {
    // Nur echte Positionen zählen – reine Null-Zeilen (v3-Import) gelten nicht als „manuell überschrieben".
    return (int) scalar("SELECT COUNT(*) FROM angebot_position WHERE angebot_id=? AND (menge>0 OR preis_cent>0)", [$angebot_id]) > 0;
}
// VK je Packung = EK × (1 + Marge je Typ), Boden = Mindestmarge. Ohne Kundenrabatt (der kommt beim Angebot).
function produkt_variante_vk(int $produkt_id, float $ek): float {
    $form = (string) scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$produkt_id]) ?: 'kapsel';
    $m = max(marge_typ_prozent($form), marge_min_prozent());
    return $ek * (1 + $m / 100);
}
// VK mit Kundenrabatt/-aufschlag (kunden.rabatt_marge: positiv = Rabatt %).
function vk_fuer_kunde(float $vk, ?int $kunde_id): float {
    if (!$kunde_id) return $vk;
    // Rabatt je Kunde ist innerhalb eines Requests konstant, wird aber pro Preiszeile
    // aufgerufen (Angebots-/Matrixseiten hunderte Male) – daher request-lokal cachen.
    static $rabCache = [];
    if (!array_key_exists($kunde_id, $rabCache))
        $rabCache[$kunde_id] = (float) scalar("SELECT rabatt_marge FROM kunden WHERE id=?", [$kunde_id]);
    return $vk * (1 - $rabCache[$kunde_id] / 100);
}

// ---- Rohstoff-Preise (Weiterverkauf an Kunden): EK-Staffel + Aufschlag ----
// Günstigster Lieferanten-EK je Bezugseinheit bei einer Menge (je Lieferant die passende
// Staffel menge_ab<=Menge, dann der günstigste Lieferant). Fallback: flacher item.ek_preis.
// Hinweis: rechnet in der Bezugseinheit des Rohstoffs; Fremdwährungen (waehrung != EUR)
// werden aktuell nicht umgerechnet (später ergänzbar).
// Günstigsten Lieferanten für einen Rohstoff bei einer Menge finden.
// Rückgabe: ['lieferant_id'=>…, 'firma'=>…, 'preis'=>…] oder null, wenn kein Lieferantenpreis existiert.
function rohstoff_bester_lieferant(int $item_id, float $menge): ?array {
    // Passende Staffel je Lieferant: größte Staffel, deren menge_ab <= Bedarf. Liegt der Bedarf
    // unter allen Staffeln (unter MOQ), gilt die KLEINSTE Staffel – so viel müsste man mindestens abnehmen.
    $rows = all("SELECT lp.lieferant_id, lp.preis, lp.menge_ab, l.firma FROM lieferant_preis lp
                 LEFT JOIN lieferanten l ON l.id=lp.lieferant_id
                 WHERE lp.item_id=? AND (lp.waehrung IS NULL OR lp.waehrung='EUR')
                 ORDER BY lp.lieferant_id, lp.menge_ab", [$item_id]);
    if (!$rows) return null;
    $jeLief = [];
    foreach ($rows as $r) {
        $lid = (int)$r['lieferant_id'];
        if (!isset($jeLief[$lid])) $jeLief[$lid] = ['firma'=>(string)$r['firma'], 'kleinste'=>(float)$r['preis'], 'passend'=>null];
        if ((float)$r['menge_ab'] <= $menge) $jeLief[$lid]['passend'] = (float)$r['preis'];   // Staffeln aufsteigend -> letzte passende gewinnt
    }
    $best = null;
    foreach ($jeLief as $lid => $d) {
        $pr = $d['passend'] ?? $d['kleinste'];
        if ($best === null || $pr < $best['preis']) $best = ['lieferant_id'=>$lid, 'firma'=>$d['firma'], 'preis'=>$pr, 'unter_moq'=>($d['passend'] === null)];
    }
    return $best;
}

// Rohstoffkosten eines Angebots je Zutat – für die Kalkulation VOR dem Preis.
// Nimmt die größte Bestellmenge über alle Optionen (dort greifen die günstigsten Staffeln) und
// löst die Rezeptur in ihre Rohstoffe auf. Zeigt je Zutat: benötigte Menge, bester Lieferanten-EK
// bei dieser Menge, welcher Lieferant – und ob überhaupt ein Lieferantenpreis bekannt ist.
// Rückgabe: ['zeilen'=>[…], 'summe'=>float, 'ohne_preis'=>int (Zutaten ohne Lieferantenpreis)]
function angebot_rohstoffkosten(int $angebot_id): array {
    $pos = all("SELECT rezeptur_id, stueck, menge FROM angebot_position
                WHERE angebot_id=? AND quelle='herstellung' AND rezeptur_id IS NOT NULL AND stueck>0", [$angebot_id]);
    // Je Rezeptur die größte Gesamtstückzahl (stueck je Packung × Packungen) über die Optionen.
    $maxEinheiten = [];
    foreach ($pos as $p) {
        $rid = (int)$p['rezeptur_id'];
        $e = (int)$p['stueck'] * (int) round((float)$p['menge']);
        if ($e > ($maxEinheiten[$rid] ?? 0)) $maxEinheiten[$rid] = $e;
    }
    $zeilen = []; $summe = 0.0; $ohne = 0;
    foreach ($maxEinheiten as $rid => $einheiten) {
        foreach (all("SELECT z.item_id, z.menge_mg, i.name, i.preis_bezug, i.einheit, i.dichte
                      FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=?", [$rid]) as $z) {
            $pb = (string)($z['preis_bezug'] ?: $z['einheit'] ?: 'kg');
            $mg = (float)$z['menge_mg'] * $einheiten;
            // Gesamtbedarf der Zutat in ihrer Bezugseinheit
            $bedarf = $pb === 'kg' ? $mg / 1e6 : ($pb === 'g' ? $mg / 1e3
                    : ($pb === 'L' && $z['dichte'] ? $mg / 1e6 / (float)$z['dichte'] : $mg / 1e6));
            $best = rohstoff_bester_lieferant((int)$z['item_id'], $bedarf);
            $kosten = $best ? $best['preis'] * $bedarf : null;
            if ($best) $summe += $kosten; else $ohne++;
            $zeilen[] = [
                'item_id'   => (int)$z['item_id'], 'name' => (string)$z['name'],
                'bedarf'    => $bedarf, 'bezug' => $pb,
                'ek'        => $best['preis'] ?? null, 'lieferant' => $best['firma'] ?? '',
                'lieferant_id' => $best['lieferant_id'] ?? 0, 'kosten' => $kosten,
            ];
        }
    }
    return ['zeilen'=>$zeilen, 'summe'=>$summe, 'ohne_preis'=>$ohne];
}
function rohstoff_ek_bei_menge(int $item_id, float $menge): ?float {
    $rows = all("SELECT lieferant_id, preis FROM lieferant_preis
                 WHERE item_id=? AND menge_ab<=? AND (waehrung IS NULL OR waehrung='EUR')
                 ORDER BY lieferant_id, menge_ab DESC", [$item_id, $menge]);
    $best = null; $seen = [];
    foreach ($rows as $r) {
        $lid = (int) $r['lieferant_id'];
        if (isset($seen[$lid])) continue;   // erste Zeile je Lieferant = größte passende Staffel
        $seen[$lid] = true;
        $p = (float) $r['preis'];
        if ($best === null || $p < $best) $best = $p;
    }
    if ($best !== null) return $best;
    $flat = scalar("SELECT ek_preis FROM item WHERE id=?", [$item_id]);
    return ($flat !== null && (float) $flat > 0) ? (float) $flat : null;
}
// Aufschlag % für einen Rohstoff: eigener Wert am Rohstoff, sonst globaler aufschlag_rohstoff.
function rohstoff_aufschlag_prozent(int $item_id): float {
    $o = scalar("SELECT vk_aufschlag_prozent FROM item WHERE id=?", [$item_id]);
    if ($o !== null && trim((string) $o) !== '') return (float) $o;
    return (float) meta_get('aufschlag_rohstoff', 30);
}
// VK je Bezugseinheit (ohne Kundenrabatt) = EK × (1 + Aufschlag %). Null wenn kein EK bekannt.
function rohstoff_vk_bei_menge(int $item_id, float $menge): ?float {
    $ek = rohstoff_ek_bei_menge($item_id, $menge);
    if ($ek === null) return null;
    return $ek * (1 + rohstoff_aufschlag_prozent($item_id) / 100);
}



// ---- Preisanfrage: Art, Form und Einheit -----------------------------------
// Was fragen wir an? Davon hängt ab, welche Felder sinnvoll sind und in welcher Einheit
// der Lieferant seinen Preis nennt.
function anfrage_arten(): array {
    return ['rohstoff'=>'Rohstoff', 'fertigprodukt'=>'Fertigprodukt (Bulk)', 'verpackung'=>'Verpackung', 'verbrauch'=>'Verbrauchsmaterial', 'sonstiges'=>'Sonstiges (Freitext)'];
}
// Formen, die eine Anfrage haben kann: beim Fertigprodukt die Darreichungsform (wie in der
// Rezeptur), beim Rohstoff die Lieferform. So heißt es überall gleich – „Rohstoff · Pulver"
// steht neben „Fertigprodukt · Kapseln".
function anfrage_formen(): array {
    return ['kapsel'=>'Kapsel', 'tablette'=>'Tablette', 'softgel'=>'Softgel', 'stick'=>'Stick',
            'gummi'=>'Fruchtgummi', 'gel'=>'Gel',
            'pulver'=>'Pulver', 'granulat'=>'Granulat', 'fluessig'=>'Flüssig', 'oel'=>'Öl', 'extrakt'=>'Extrakt'];
}
// Welche Formen zu welcher Art passen. Verpackung und Verbrauch haben keine.
function anfrage_formen_fuer_art(string $art): array {
    $alle = anfrage_formen();
    if ($art === 'fertigprodukt') return array_intersect_key($alle, array_flip(['kapsel','tablette','softgel','stick','gummi','gel','pulver','granulat','fluessig']));
    if ($art === 'rohstoff')      return array_intersect_key($alle, array_flip(['pulver','granulat','fluessig','oel','extrakt']));
    return [];
}
// Form eines Artikels (Rohstoffe tragen sie in item.form).
function anfrage_form_fuer_item(?int $item_id): string {
    if (!$item_id) return '';
    $f = (string) scalar("SELECT form FROM item WHERE id=?", [$item_id]);
    return array_key_exists($f, anfrage_formen()) ? $f : '';
}
// Einheit, in der ein Fertigprodukt dieser Form eingekauft wird. Stückware wird in der Form
// selbst bepreist (je Kapsel, je Tablette …) – so heißt der Preis beim Lieferanten auch so.
// Pulver/Granulat gehen nach Kilogramm, Flüssiges nach Liter.
function anfrage_einheit_fuer_form(string $form): string {
    return ['kapsel'=>'Kapsel', 'tablette'=>'Tablette', 'softgel'=>'Softgel', 'stick'=>'Stick',
            'gummi'=>'kg', 'gel'=>'L',
            'pulver'=>'kg', 'granulat'=>'kg', 'extrakt'=>'kg', 'fluessig'=>'L', 'oel'=>'L'][$form] ?? 'Stück';
}
// Die Einheit einer Anfrage – ohne dass jemand sie eintippen muss.
// Reihenfolge: was am Artikel steht (Bezugsgröße vor Lagereinheit), sonst die Form des
// Fertigprodukts, sonst die Art (Verpackung/Verbrauch = Stück). Leer nur bei reinem Freitext.
function anfrage_einheit(?int $item_id, string $art = '', string $form = ''): string {
    // Bei Stückware (Kapsel, Tablette …) gilt die Form: „je Kapsel" ist genauer als das
    // allgemeine „Stück" am Artikel. Bei Schüttgut hat die Bezugsgröße des Artikels Vorrang,
    // denn dort kann kg oder L abweichend gepflegt sein.
    $formStueck = $form !== '' && in_array($form, ['kapsel', 'tablette', 'softgel', 'stick'], true);
    if ($formStueck) return anfrage_einheit_fuer_form($form);
    if ($item_id) {
        $it = one("SELECT einheit, preis_bezug, kategorie, form FROM item WHERE id=?", [$item_id]);
        if ($it) {
            $e = trim((string)($it['preis_bezug'] ?? '')) ?: trim((string)($it['einheit'] ?? ''));
            if ($e !== '') return mb_substr($e, 0, 20);
        }
    }
    if ($form !== '' && array_key_exists($form, anfrage_formen())) return anfrage_einheit_fuer_form($form);
    if (in_array($art, ['verpackung', 'verbrauch'], true)) return 'Stück';
    if ($art === 'fertigprodukt') return 'Stück';
    if ($art === 'rohstoff') return 'kg';
    return '';
}
// Art einer Anfrage aus dem gewählten Artikel ableiten (wenn keine gesetzt ist).
function anfrage_art_fuer_item(?int $item_id): string {
    if (!$item_id) return '';
    $k = (string) scalar("SELECT kategorie FROM item WHERE id=?", [$item_id]);
    return ['rohstoff'=>'rohstoff', 'verpackung'=>'verpackung', 'verbrauch'=>'verbrauch', 'fertig'=>'fertigprodukt'][$k] ?? '';
}
// Eine eingetippte Zahl einlesen – egal in welcher Schreibweise. „12,50" und „12.50" sind beides
// 12,5. Stehen Punkt und Komma zusammen, trennt das HINTERE die Nachkommastellen (1.250,5 = 1250,5;
// 1,250.5 = 1250,5). Bei Mengenfeldern gilt zusätzlich: „250.000" ist die deutsche Schreibweise für
// 250000 – ein Punkt vor genau drei Ziffern trennt dort Tausender, keine Nachkommastellen.
// Bei Preisen bleibt „0.045" bewusst 0,045.
function zahl_lesen(string $roh, bool $mengenfeld = false, string $sprache = 'de'): float {
    $s = trim(str_replace(["\xc2\xa0", ' ', "'", '_'], '', $roh));
    if ($s === '') return 0.0;
    $punkt = strpos($s, '.') !== false;
    $komma = strpos($s, ',') !== false;
    // Welches Zeichen trennt in dieser Sprache die Tausender? Deutsch der Punkt, sonst das Komma.
    $tausender = $sprache === 'de' ? '.' : ',';
    // Eine Tausendergruppe beginnt nie mit einer Null: „0.045" ist ein Wert, keine 45.
    $muster = '/^-?[1-9]\d{0,2}(' . preg_quote($tausender, '/') . '\d{3})+$/';
    if ($punkt && $komma) {
        $dez = strrpos($s, ',') > strrpos($s, '.') ? ',' : '.';
        $s = str_replace($dez === ',' ? '.' : ',', '', $s);
        $s = str_replace($dez, '.', $s);
    } elseif ($mengenfeld && preg_match($muster, $s)) {
        $s = str_replace($tausender, '', $s);
    } elseif ($komma) {
        $s = str_replace(',', '.', $s);
    }
    return (float) $s;
}

// Einheit als Wort – in der Ein- oder Mehrzahl, je nach Menge, und in der Sprache des Lesers.
// „250.000 Kapseln", aber „Preis je Kapsel". Chinesisch kennt keine Mehrzahl.
// kg, g, L und ml bleiben immer, wie sie sind.
function einheit_wort(?string $e, float $menge = 1, string $sprache = 'de'): string {
    $e = trim((string)$e);
    if ($e === '') return '';
    $s = in_array($sprache, ['de', 'en', 'zh'], true) ? $sprache : 'en';
    $mehr = abs($menge) != 1;
    // [de-Einzahl, de-Mehrzahl, en-Einzahl, en-Mehrzahl, zh]
    $map = [
        'stück'    => ['Stück', 'Stück', 'piece', 'pieces', '个'],
        'stueck'   => ['Stück', 'Stück', 'piece', 'pieces', '个'],
        'kapsel'   => ['Kapsel', 'Kapseln', 'capsule', 'capsules', '粒'],
        'kapseln'  => ['Kapsel', 'Kapseln', 'capsule', 'capsules', '粒'],
        'tablette' => ['Tablette', 'Tabletten', 'tablet', 'tablets', '片'],
        'softgel'  => ['Softgel', 'Softgels', 'softgel', 'softgels', '软胶囊'],
        'stick'    => ['Stick', 'Sticks', 'stick', 'sticks', '条'],
        'gummi'    => ['Gummi', 'Gummis', 'gummy', 'gummies', '软糖'],
        'gummis'   => ['Gummi', 'Gummis', 'gummy', 'gummies', '软糖'],
        'packung'  => ['Packung', 'Packungen', 'pack', 'packs', '包装'],
        'beutel'   => ['Beutel', 'Beutel', 'bag', 'bags', '袋'],
        'liter'    => ['Liter', 'Liter', 'litre', 'litres', '升'],
    ];
    $k = mb_strtolower($e);
    if (!isset($map[$k])) return $e;                     // kg, g, L, ml … bleiben stehen
    $w = $map[$k];
    if ($s === 'zh') return $w[4];
    return $s === 'de' ? ($mehr ? $w[1] : $w[0]) : ($mehr ? $w[3] : $w[2]);
}

// Klartext für die Zeile „Produkttyp" – deutsch, englisch, chinesisch.
function anfrage_art_label(string $art, string $form = '', string $sprache = 'de'): string {
    $arten = [
        'rohstoff'      => ['de'=>'Rohstoff', 'en'=>'Raw material', 'zh'=>'原料'],
        'fertigprodukt' => ['de'=>'Fertigprodukt', 'en'=>'Finished product', 'zh'=>'成品'],
        'verpackung'    => ['de'=>'Verpackung', 'en'=>'Packaging', 'zh'=>'包装'],
        'verbrauch'     => ['de'=>'Verbrauchsmaterial', 'en'=>'Consumables', 'zh'=>'耗材'],
        'sonstiges'     => ['de'=>'Sonstiges', 'en'=>'Other', 'zh'=>'其他'],
    ];
    $formen = [
        'kapsel'   => ['de'=>'Kapseln', 'en'=>'Capsules', 'zh'=>'胶囊'],
        'tablette' => ['de'=>'Tabletten', 'en'=>'Tablets', 'zh'=>'片剂'],
        'softgel'  => ['de'=>'Softgels', 'en'=>'Softgels', 'zh'=>'软胶囊'],
        'stick'    => ['de'=>'Sticks', 'en'=>'Sticks', 'zh'=>'条包'],
        'gummi'    => ['de'=>'Fruchtgummi', 'en'=>'Gummies', 'zh'=>'软糖'],
        'gel'      => ['de'=>'Gel', 'en'=>'Gel', 'zh'=>'凝胶'],
        'pulver'   => ['de'=>'Pulver', 'en'=>'Powder', 'zh'=>'粉剂'],
        'granulat' => ['de'=>'Granulat', 'en'=>'Granulate', 'zh'=>'颗粒'],
        'fluessig' => ['de'=>'Flüssig', 'en'=>'Liquid', 'zh'=>'液体'],
        'oel'      => ['de'=>'Öl', 'en'=>'Oil', 'zh'=>'油'],
        'extrakt'  => ['de'=>'Extrakt', 'en'=>'Extract', 'zh'=>'提取物'],
    ];
    $s = in_array($sprache, ['de', 'en', 'zh'], true) ? $sprache : 'en';
    $a = $arten[$art][$s] ?? '';
    $f = $form !== '' ? ($formen[$form][$s] ?? '') : '';
    if ($a === '' && $f === '') return '';
    if ($f === '') return $a;
    return $a !== '' ? $a . ' · ' . $f : $f;
}

// Preisanfrage an einen Lieferanten stellen. Art/Form beschreiben, WAS angefragt wird; die Einheit
// ergibt sich daraus automatisch (anfrage_einheit), wenn sie nicht ausdrücklich mitgegeben wird.
function lieferant_anfrage_stellen(int $lieferant_id, ?int $item_id, string $betreff, ?float $menge, string $einheit, string $notiz, bool $coa = true, array $opt = []): int {
    $art  = (string)($opt['art'] ?? '');
    if ($art === '' || !array_key_exists($art, anfrage_arten())) $art = anfrage_art_fuer_item($item_id) ?: 'sonstiges';
    $form = (string)($opt['form'] ?? '');
    if (!array_key_exists($form, anfrage_formen_fuer_art($art))) $form = '';
    // Nichts gewählt? Dann sagt der Artikel selbst, was er ist (Rohstoffe tragen ihre Form).
    if ($form === '' && $item_id) {
        $fi = anfrage_form_fuer_item($item_id);
        if (array_key_exists($fi, anfrage_formen_fuer_art($art))) $form = $fi;
    }
    $einheit = trim($einheit) !== '' ? trim($einheit) : anfrage_einheit($item_id, $art, $form);
    $stk  = (int)($opt['stueck_je_packung'] ?? 0);
    $kg   = (int)($opt['kapselgroesse_id'] ?? 0);
    $rez  = (int)($opt['rezeptur_id'] ?? 0);
    $inco = array_key_exists((string)($opt['incoterm'] ?? ''), incoterm_liste()) ? (string)$opt['incoterm'] : null;
    $vers = array_key_exists((string)($opt['versandart'] ?? ''), versandart_liste()) ? (string)$opt['versandart'] : null;
    // Wunsch-Mengenstaffel (mehrere Mengen): als kommagetrennte Ganzzahlen ablegen; erste Menge = Hauptmenge.
    $staffel = [];
    foreach ((array)($opt['menge_staffel'] ?? []) as $mv) { $mi = (int) round((float) str_replace(',', '.', (string)$mv)); if ($mi > 0) $staffel[] = $mi; }
    $staffel = array_values(array_unique($staffel)); sort($staffel);
    $mengeStaffel = $staffel ? implode(',', $staffel) : null;
    if ((!$menge || $menge <= 0) && $staffel) $menge = (float) $staffel[0];
    q("INSERT INTO lieferant_anfrage (nummer,lieferant_id,item_id,betreff,menge,menge_staffel,einheit,notiz,coa_gewuenscht,status,art,form,stueck_je_packung,kapselgroesse_id,rezeptur_id,incoterm,versandart)
       VALUES (?,?,?,?,?,?,?,?,?,'offen',?,?,?,?,?,?,?)",
      [naechste_nummer('LA'), $lieferant_id, $item_id ?: null, mb_substr(trim($betreff), 0, 190) ?: null,
       $menge && $menge > 0 ? $menge : null, $mengeStaffel, mb_substr($einheit, 0, 20) ?: null, trim($notiz) ?: null, $coa ? 1 : 0,
       $art, $form ?: null, $stk > 0 ? $stk : null, $kg > 0 ? $kg : null, $rez > 0 ? $rez : null, $inco, $vers]);
    $id = insert_id();
    log_aktivitaet('lieferant', $lieferant_id, 'team', 'Preisanfrage ' . scalar("SELECT nummer FROM lieferant_anfrage WHERE id=?", [$id]) . ' gestellt.', 'anfrage', 'lieferant_anfrage', $id);
    return $id;
}

// Angebot des Lieferanten speichern (einmal je Anfrage – erneutes Senden ueberschreibt).
// $staffeln: Liste [menge_ab, preis]; leere Zeilen werden ignoriert.
function lieferant_angebot_speichern(int $anfrage_id, int $lieferant_id, float $preis, string $einheit,
                                     ?float $mindestmenge, ?int $lieferzeit, string $notiz, array $staffeln, int $preis_basis = 1, array $opt = []): string {
    $preis_basis = $preis_basis === 1000 ? 1000 : 1;
    $a = one("SELECT * FROM lieferant_anfrage WHERE id=? AND lieferant_id=?", [$anfrage_id, $lieferant_id]);
    if (!$a) return 'Anfrage nicht gefunden.';
    if ($preis <= 0) return 'Bitte einen Preis eintragen.';
    $inco = array_key_exists((string)($opt['incoterm'] ?? ''), incoterm_liste()) ? (string)$opt['incoterm'] : null;
    $vers = array_key_exists((string)($opt['versandart'] ?? ''), versandart_liste()) ? (string)$opt['versandart'] : null;
    $vorhanden = one("SELECT id FROM lieferant_angebot WHERE anfrage_id=?", [$anfrage_id]);
    if ($vorhanden) {
        q("UPDATE lieferant_angebot SET preis=?, einheit=?, preis_basis=?, mindestmenge=?, lieferzeit_tage=?, notiz=?, incoterm=?, versandart=?, status='offen', angelegt=UTC_TIMESTAMP() WHERE id=?",
          [$preis, $einheit ?: null, $preis_basis, $mindestmenge, $lieferzeit, trim($notiz) ?: null, $inco, $vers, (int)$vorhanden['id']]);
        $aid = (int)$vorhanden['id'];
        q("DELETE FROM lieferant_angebot_staffel WHERE angebot_id=?", [$aid]);
    } else {
        q("INSERT INTO lieferant_angebot (anfrage_id,lieferant_id,preis,einheit,preis_basis,mindestmenge,lieferzeit_tage,notiz,incoterm,versandart) VALUES (?,?,?,?,?,?,?,?,?,?)",
          [$anfrage_id, $lieferant_id, $preis, $einheit ?: null, $preis_basis, $mindestmenge, $lieferzeit, trim($notiz) ?: null, $inco, $vers]);
        $aid = insert_id();
    }
    foreach ($staffeln as $s) {
        $m = (float)($s[0] ?? 0); $pr = (float)($s[1] ?? 0);
        if ($m <= 0 || $pr <= 0) continue;
        q("INSERT INTO lieferant_angebot_staffel (angebot_id,menge_ab,preis) VALUES (?,?,?)", [$aid, $m, $pr]);
    }
    q("UPDATE lieferant_anfrage SET status='beantwortet' WHERE id=?", [$anfrage_id]);
    log_aktivitaet('lieferant', $lieferant_id, 'lieferant', 'Angebot zu ' . $a['nummer'] . ' abgegeben: ' . number_format($preis, 4, ',', '.') . ' je ' . ($einheit ?: 'Einheit') . '.', 'angebot', 'lieferant_anfrage', $anfrage_id);
    return '';
}

// Angebot annehmen: die Preise landen als EK-Staffeln am Artikel (lieferant_preis) – genau dort
// rechnet die Kalkulation damit. Ohne Artikel an der Anfrage gibt es nichts zu uebernehmen.
function lieferant_angebot_annehmen(int $angebot_id): string {
    $an = one("SELECT ag.*, af.item_id, af.art, af.rezeptur_id, af.nummer AS anfr_nummer FROM lieferant_angebot ag
               JOIN lieferant_anfrage af ON af.id=ag.anfrage_id WHERE ag.id=?", [$angebot_id]);
    if (!$an) return 'Angebot nicht gefunden.';
    if (!$an['item_id']) {
        // Fertigprodukt-Anfrage (per Rezeptur, kein item_id): Zukaufpreis an die Produkte der Rezeptur schreiben.
        if (($an['art'] ?? '') === 'fertigprodukt' && !empty($an['rezeptur_id']))
            return lieferant_fertig_angebot_annehmen($angebot_id, $an);
        return 'Diese Anfrage hängt an keinem Artikel – die Preise lassen sich nicht automatisch übernehmen.';
    }
    $item = (int)$an['item_id']; $lief = (int)$an['lieferant_id'];
    // Alte Staffeln dieses Lieferanten fuer diesen Artikel ersetzen – sonst mischen sich Staende.
    q("DELETE FROM lieferant_preis WHERE item_id=? AND lieferant_id=?", [$item, $lief]);
    $zeilen = all("SELECT menge_ab, preis FROM lieferant_angebot_staffel WHERE angebot_id=? ORDER BY menge_ab", [$angebot_id]);
    if (!$zeilen) $zeilen = [['menge_ab' => (float)($an['mindestmenge'] ?: 0), 'preis' => (float)$an['preis']]];
    // Der Lieferant darf je 1 oder je 1000 anbieten (bei Kapseln üblich). Am Artikel steht immer
    // der Preis je EINER Einheit – sonst rechnet die Kalkulation mit dem Tausendfachen.
    $basis = ((int)($an['preis_basis'] ?? 1)) === 1000 ? 1000 : 1;
    $inco = $an['incoterm'] ?: null; $vers = $an['versandart'] ?: null;   // Lieferbedingung vom Angebot mitnehmen
    foreach ($zeilen as $z)
        q("INSERT INTO lieferant_preis (item_id,lieferant_id,menge_ab,preis,waehrung,stand,incoterm,versandart) VALUES (?,?,?,?,?,CURDATE(),?,?)",
          [$item, $lief, (float)$z['menge_ab'], (float)$z['preis'] / $basis, (string)($an['waehrung'] ?: 'EUR'), $inco, $vers]);
    q("UPDATE lieferant_angebot SET status='angenommen' WHERE id=?", [$angebot_id]);
    q("UPDATE lieferant_anfrage SET status='geschlossen' WHERE id=?", [(int)$an['anfrage_id']]);
    log_aktivitaet('lieferant', $lief, 'team', 'Angebot zu ' . $an['anfr_nummer'] . ' angenommen – ' . count($zeilen) . ' EK-Staffel(n) übernommen.', 'angebot', 'item', $item);
    return '';
}

// Fertigprodukt-Angebot annehmen: der Preis gilt je Rezeptur (Bulk) und wird als Zukaufpreis an ALLE
// Produkte dieser Rezeptur geschrieben (produkt_lieferant_preis) – so erscheint er in „Lieferanten-Preise"
// (Reiter Fertigprodukt) und am Produkt/Auftrag. Preisbasis (je 1 / je 1000) wird auf „je Einheit" normiert.
function lieferant_fertig_angebot_annehmen(int $angebot_id, array $an): string {
    $lief = (int)$an['lieferant_id']; $rez = (int)$an['rezeptur_id'];
    $basis = ((int)($an['preis_basis'] ?? 1)) === 1000 ? 1000 : 1;
    $inco = $an['incoterm'] ?: null; $vers = $an['versandart'] ?: null; $wae = (string)($an['waehrung'] ?: 'EUR');
    $form = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rez]) ?: 'kapsel';
    $zeilen = all("SELECT menge_ab, preis FROM lieferant_angebot_staffel WHERE angebot_id=? ORDER BY menge_ab", [$angebot_id]);
    if (!$zeilen) $zeilen = [['menge_ab' => (float)($an['mindestmenge'] ?: 0), 'preis' => (float)$an['preis']]];
    $produkte = all("SELECT id, einheiten_pro_packung FROM produkt WHERE rezeptur_id=?", [$rez]);
    foreach ($produkte as $p) {
        // Alte Zukaufpreise dieses Lieferanten fuer dieses Produkt ersetzen (kein Mischen von Staenden).
        q("DELETE FROM produkt_lieferant_preis WHERE produkt_id=? AND lieferant_id=?", [(int)$p['id'], $lief]);
        $groesse = (int)($p['einheiten_pro_packung'] ?? 0) > 0 ? ((int)$p['einheiten_pro_packung'] . ' Stk') : null;
        foreach ($zeilen as $z)
            q("INSERT INTO produkt_lieferant_preis (produkt_id,lieferant_id,menge_ab,preis,einheit,groesse,waehrung,incoterm,versandart,stand,quelle)
               VALUES (?,?,?,?,?,?,?,?,?,CURDATE(),'angebot')",
              [(int)$p['id'], $lief, (float)$z['menge_ab'], (float)$z['preis'] / $basis, $form, $groesse, $wae, $inco, $vers]);
    }
    q("UPDATE lieferant_angebot SET status='angenommen' WHERE id=?", [$angebot_id]);
    q("UPDATE lieferant_anfrage SET status='geschlossen' WHERE id=?", [(int)$an['anfrage_id']]);
    log_aktivitaet('lieferant', $lief, 'team',
        'Fertigprodukt-Angebot zu ' . $an['anfr_nummer'] . ' angenommen – Zukaufpreise übernommen (' . count($produkte) . ' Produkt(e)).',
        'angebot', 'rezeptur', $rez);
    // Kein Produkt zur Rezeptur? Angebot ist angenommen, aber der Preis erscheint erst, wenn es ein Produkt gibt.
    return '';
}
// Einladung fuer einen Lieferanten erzeugen (oder die offene wiederverwenden) und den Link liefern.
function lieferant_einladung(int $lieferant_id, string $basis_url = ''): array {
    $offen = one("SELECT * FROM lieferant_einladung WHERE lieferant_id=? AND eingeloest=0 ORDER BY id DESC LIMIT 1", [$lieferant_id]);
    if (!$offen) {
        $token = bin2hex(random_bytes(24));
        $mail  = (string) scalar("SELECT email FROM lieferanten WHERE id=?", [$lieferant_id]);
        q("INSERT INTO lieferant_einladung (lieferant_id, token, email) VALUES (?,?,?)", [$lieferant_id, $token, $mail ?: null]);
        $offen = one("SELECT * FROM lieferant_einladung WHERE token=?", [$token]);
    }
    $offen['link'] = rtrim($basis_url, '/') . '/?p=lieferant_einladung&token=' . $offen['token'];
    return $offen;
}
// Hat der Lieferant schon einen Zugang?
function lieferant_hat_zugang(int $lieferant_id): bool {
    return (int) scalar("SELECT COUNT(*) FROM benutzer WHERE lieferant_id=? AND aktiv=1", [$lieferant_id]) > 0;
}
// Zugang aus einer Einladung anlegen. Rueckgabe: '' = angelegt, sonst der Grund.
function lieferant_zugang_anlegen(string $token, string $name, string $email, string $pass): string {
    $inv = one("SELECT * FROM lieferant_einladung WHERE token=? AND eingeloest=0", [$token]);
    if (!$inv) return 'Diese Einladung ist nicht mehr gültig.';
    $email = trim(mb_strtolower($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Bitte eine gültige E-Mail-Adresse angeben.';
    if (mb_strlen($pass) < 8) return 'Das Passwort muss mindestens 8 Zeichen haben.';
    if (scalar("SELECT id FROM benutzer WHERE email=?", [$email])) return 'Für diese E-Mail gibt es bereits einen Zugang.';
    q("INSERT INTO benutzer (name,email,pass_hash,rollen,aktiv,lieferant_id) VALUES (?,?,?,?,1,?)",
      [mb_substr(trim($name), 0, 190) ?: 'Lieferant', $email, password_hash($pass, PASSWORD_DEFAULT), 'lieferant', (int)$inv['lieferant_id']]);
    q("UPDATE lieferant_einladung SET eingeloest=1 WHERE id=?", [(int)$inv['id']]);
    log_aktivitaet('lieferant', (int)$inv['lieferant_id'], 'lieferant', 'Zugang zum Lieferantenportal angelegt (' . $email . ').', 'lieferant');
    return '';
}

// Selbst-Bewerbung eines Lieferanten (öffentliche Landing Page). Legt einen GESPERRTEN Lieferanten
// (quelle='bewerbung') + einen INAKTIVEN Login an – der Zugang greift erst nach Freigabe durch das Team.
// Rückgabe: ['ok'=>bool, 'fehler'=>string]. Fehlertexte sind generisch (öffentlich).
function lieferant_bewerbung_anlegen(array $d): array {
    $firma = trim((string)($d['firma'] ?? ''));
    $email = trim(mb_strtolower((string)($d['email'] ?? '')));
    $pass  = (string)($d['passwort'] ?? '');
    $name  = trim((string)($d['ansprechpartner'] ?? '')) ?: $firma;
    if ($firma === '')                               return ['ok'=>false, 'fehler'=>'Bitte den Firmennamen angeben.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))  return ['ok'=>false, 'fehler'=>'Bitte eine gültige E-Mail-Adresse angeben.'];
    if (mb_strlen($pass) < 8)                        return ['ok'=>false, 'fehler'=>'Das Passwort muss mindestens 8 Zeichen haben.'];
    if (scalar("SELECT id FROM benutzer WHERE email=?", [$email]))
                                                     return ['ok'=>false, 'fehler'=>'Für diese E-Mail gibt es bereits einen Zugang.'];
    if (scalar("SELECT id FROM lieferanten WHERE LOWER(firma)=LOWER(?)", [$firma]))
                                                     return ['ok'=>false, 'fehler'=>'Diese Firma ist bereits registriert. Bitte über den Login anmelden oder uns kontaktieren.'];
    $kat = array_values(array_intersect(
        array_map('strval', (array)($d['kategorien'] ?? [])),
        ['rohstoff','verpackung','verbrauch','maschine','labor','fertigprodukt']));
    $spr = in_array((string)($d['sprache'] ?? ''), ['de','en','zh'], true) ? (string)$d['sprache'] : 'en';
    $wae = in_array((string)($d['waehrung'] ?? ''), ['USD','EUR','CNY'], true) ? (string)$d['waehrung'] : 'USD';
    $land = strtoupper(substr(trim((string)($d['land'] ?? '')) ?: 'CN', 0, 2));
    q("INSERT INTO lieferanten (firma,ansprechpartner,email,telefon,webseite,land,sprache,waehrung,kategorien,bewerbung_nachricht,quelle,gesperrt)
       VALUES (?,?,?,?,?,?,?,?,?,?, 'bewerbung', 1)",
      [mb_substr($firma,0,190), mb_substr($name,0,190), $email, mb_substr(trim((string)($d['telefon'] ?? '')),0,60) ?: null,
       mb_substr(trim((string)($d['webseite'] ?? '')),0,190) ?: null, $land, $spr, $wae,
       implode(',', $kat) ?: null, trim((string)($d['nachricht'] ?? '')) ?: null]);
    $lid = (int) insert_id();
    q("INSERT INTO benutzer (name,email,pass_hash,rollen,aktiv,lieferant_id) VALUES (?,?,?,?,0,?)",
      [mb_substr($name,0,190) ?: 'Lieferant', $email, password_hash($pass, PASSWORD_DEFAULT), 'lieferant', $lid]);
    log_aktivitaet('lieferant', $lid, 'lieferant', 'Neue Lieferanten-Bewerbung über die Website (' . $email . '). Wartet auf Freigabe.', 'lieferant');
    return ['ok'=>true, 'fehler'=>''];
}

// Bewerbung freigeben (Team): Lieferant entsperren + zugehörige Logins aktivieren. Rückgabe: true bei Erfolg.
function lieferant_bewerbung_freigeben(int $lieferant_id): bool {
    if ($lieferant_id <= 0) return false;
    if (!scalar("SELECT id FROM lieferanten WHERE id=?", [$lieferant_id])) return false;
    q("UPDATE lieferanten SET gesperrt=0 WHERE id=?", [$lieferant_id]);
    q("UPDATE benutzer SET aktiv=1 WHERE lieferant_id=? AND rollen LIKE '%lieferant%'", [$lieferant_id]);
    log_aktivitaet('lieferant', $lieferant_id, 'team', 'Lieferanten-Bewerbung freigegeben – Zugang aktiviert.', 'lieferant');
    return true;
}
// Stationen einer Bestellung beim Lieferanten – in dieser Reihenfolge, kumulativ.
function bestellung_stationen(): array {
    return ['angenommen' => 'Auftrag angenommen', 'produktion' => 'in Produktion',
            'qualitaet'  => 'Qualitätsprüfung',   'versand'    => 'versandbereit',
            'versendet'  => 'versendet'];
}
function bestellung_stationen_en(): array {
    return ['angenommen' => 'Order accepted', 'produktion' => 'In production',
            'qualitaet'  => 'Quality check',  'versand'    => 'Ready to ship',
            'versendet'  => 'Shipped'];
}
function bestellung_stationen_zh(): array {
    return ['angenommen' => '已接受订单', 'produktion' => '生产中',
            'qualitaet'  => '质量检验',   'versand'    => '待发货',
            'versendet'  => '已发货'];
}

// Stationsnamen in der Sprache des Lieferanten (de|en|zh).
function bestellung_stationen_fuer(string $sprache): array {
    return $sprache === 'de' ? bestellung_stationen() : ($sprache === 'zh' ? bestellung_stationen_zh() : bestellung_stationen_en());
}
// Wie weit ist die Bestellung? -1 = noch keine Station gesetzt.
function bestellung_station_index(?string $station): int {
    $keys = array_keys(bestellung_stationen());
    $i = array_search((string)$station, $keys, true);
    return $i === false ? -1 : (int)$i;
}
// Versandarten (der Lieferant waehlt eine davon).
function versandarten(): array {
    return ['luft' => 'Luftfracht', 'see' => 'Seefracht', 'kurier' => 'Kurier (DHL/UPS/FedEx)',
            'spedition' => 'Spedition', 'post' => 'Post'];
}
// Station setzen – kumulativ bis zum Ziel. "versendet" nur mit vollstaendigen Versanddaten.
// Rueckgabe: '' = gesetzt, sonst der Grund, warum nicht.
function bestellung_station_setzen(int $bestellung_id, string $ziel, ?string $wer = null, string $sprache = 'de'): string {
    $m = fn(string $de, string $en, string $zh) => $sprache === 'de' ? $de : ($sprache === 'zh' ? $zh : $en);
    $b = one("SELECT * FROM bestellung WHERE id=?", [$bestellung_id]);
    if (!$b) return $m('Bestellung nicht gefunden.', 'Order not found.', '未找到订单。');
    if ((int)$b['bestaetigt'] !== 1) return $m('Bitte zuerst die Bestellung mit einem geplanten Termin bestätigen.', 'Please confirm the order with a planned date first.', '请先确认订单并填写计划交货日期。');
    if (!array_key_exists($ziel, bestellung_stationen())) return $m('Unbekannte Station.', 'Unknown step.', '未知步骤。');
    if ($ziel === 'versendet' && (trim((string)$b['versandanbieter']) === '' || trim((string)$b['versandart']) === '' || trim((string)$b['tracking']) === ''))
        return $m('Für „versendet" fehlen Versandanbieter, Versandart oder Sendungsnummer.', 'For "shipped" the carrier, shipping method or tracking number is missing.', '缺少承运商、运输方式或物流单号，无法设置为“已发货”。');
    q("UPDATE bestellung SET station=? WHERE id=?", [$ziel, $bestellung_id]);
    // Der interne Status laeuft mit: sobald der Lieferant produziert, ist die Bestellung "bestellt".
    if ((string)$b['status'] === 'offen') q("UPDATE bestellung SET status='bestellt' WHERE id=?", [$bestellung_id]);
    if ($b['lieferant_id']) log_aktivitaet('lieferant', (int)$b['lieferant_id'], $wer ? 'lieferant' : 'team',
        'Bestellung ' . $b['nummer'] . ': ' . bestellung_stationen()[$ziel] . ($wer ? ' (' . $wer . ')' : '') . '.', 'bestellung', 'bestellung', $bestellung_id);
    return '';
}
// Bestellung durch den Lieferanten bestaetigen (mit zugesagtem Termin).
function bestellung_bestaetigen(int $bestellung_id, string $eta, string $wer, string $sprache = 'de'): string {
    $m = fn(string $de, string $en, string $zh) => $sprache === 'de' ? $de : ($sprache === 'zh' ? $zh : $en);
    $b = one("SELECT id, nummer, bestaetigt, lieferant_id FROM bestellung WHERE id=?", [$bestellung_id]);
    if (!$b) return $m('Bestellung nicht gefunden.', 'Order not found.', '未找到订单。');
    if ((int)$b['bestaetigt'] === 1) return '';                     // schon bestaetigt: nichts tun
    if (trim($eta) === '' || !strtotime($eta)) return $m('Bitte einen geplanten Liefertermin angeben.', 'Please state a planned delivery date.', '请填写计划交货日期。');
    q("UPDATE bestellung SET bestaetigt=1, bestaetigt_am=UTC_TIMESTAMP(), bestaetigt_von=?, eta_geplant=?, station=?, status=IF(status='offen','bestellt',status) WHERE id=?",
      [mb_substr(trim($wer), 0, 190), date('Y-m-d', strtotime($eta)), 'angenommen', $bestellung_id]);
    if ($b['lieferant_id']) log_aktivitaet('lieferant', (int)$b['lieferant_id'], 'lieferant',
        'Bestellung ' . $b['nummer'] . ' bestätigt für ' . date('d.m.Y', strtotime($eta)) . ' durch ' . trim($wer) . '.', 'bestellung', 'bestellung', $bestellung_id);
    return '';
}
// --- Versand-Pakete einer Lieferung (Lieferant meldet Kartons + Tracking-Nummern) ---
// Alle Pakete einer Bestellung (neueste zuerst). Rückgabe inkl. angekommen/angekommen_am.
function lieferung_pakete(int $bestellung_id): array {
    if (!table_exists('lieferung_paket')) return [];
    return all("SELECT * FROM lieferung_paket WHERE bestellung_id=? ORDER BY (angekommen=1), id", [$bestellung_id]);
}
// Mehrere Tracking-Nummern (eine pro Zeile) zu einer Bestellung erfassen. Duplikate (global eindeutig
// über uniq_tracking) werden übersprungen. Rückgabe ['neu'=>int, 'doppelt'=>int].
function lieferung_pakete_hinzufuegen(int $bestellung_id, string $trackingBlock, ?string $spediteur = null): array {
    if (!table_exists('lieferung_paket') || $bestellung_id <= 0) return ['neu' => 0, 'doppelt' => 0];
    $sp = $spediteur !== null ? (mb_substr(trim($spediteur), 0, 40) ?: null) : null;
    $neu = 0; $dop = 0; $gesehen = [];
    foreach (preg_split('/[\r\n]+/', $trackingBlock) as $zeile) {
        $t = trim($zeile);
        $t = preg_replace('/\s+/', '', $t);          // Tracking ohne Leerzeichen
        if ($t === '') continue;
        $t = mb_substr($t, 0, 80);
        $key = mb_strtolower($t);
        if (isset($gesehen[$key])) { $dop++; continue; }
        $gesehen[$key] = true;
        if (scalar("SELECT id FROM lieferung_paket WHERE tracking=? LIMIT 1", [$t])) { $dop++; continue; }
        q("INSERT INTO lieferung_paket (bestellung_id,tracking,spediteur,angelegt) VALUES (?,?,?,?)",
          [$bestellung_id, $t, $sp, gmdate('Y-m-d H:i:s')]);
        $neu++;
    }
    return ['neu' => $neu, 'doppelt' => $dop];
}
// Ein Paket löschen – nur solange es noch nicht angekommen ist und zur Bestellung gehört.
function lieferung_paket_loeschen(int $paket_id, int $bestellung_id): bool {
    if (!table_exists('lieferung_paket')) return false;
    $p = one("SELECT id, angekommen FROM lieferung_paket WHERE id=? AND bestellung_id=?", [$paket_id, $bestellung_id]);
    if (!$p || (int)$p['angekommen'] === 1) return false;
    q("DELETE FROM lieferung_paket WHERE id=?", [$paket_id]);
    return true;
}

// Angebote gelten standardmäßig 14 Tage (Einstellungen: angebot_gueltig_tage) – ab heute gerechnet.
function angebot_gueltig_bis_default(): string {
    return date('Y-m-d', strtotime('+' . max(1, (int) meta_get('angebot_gueltig_tage', 14)) . ' days'));
}

// Etikettenmaß „56 x 143" (auch „56x143 mm") in [Breite, Höhe] zerlegen.
function etikett_masse(?string $s): ?array {
    if (!$s || !preg_match('/(\d+(?:[.,]\d+)?)\s*[x×*]\s*(\d+(?:[.,]\d+)?)/iu', $s, $m)) return null;
    return [(float) str_replace(',', '.', $m[1]), (float) str_replace(',', '.', $m[2])];
}

// Behälter (Primärverpackung) eines Produkts – mit Fallback auf den jüngsten Auftrag, falls am
// Produkt (noch) keiner steht. Die Verpackung wird oft erst JE AUFTRAG gewählt (auftrag.verpackung_id),
// z. B. bei Zukauf/Fremdproduktion – dann kennt das Produkt sie nicht, der Auftrag aber schon.
function produkt_behaelter_id(int $produkt_id): ?int {
    if ($produkt_id <= 0) return null;
    // Auftrags-Behälter hat Vorrang (Admin kann je Auftrag ein anderes Glas setzen – das treibt dann
    // auch Produktion/Einkauf/PIB). Nur wenn kein Auftrag einen Behälter nennt, gilt der am Produkt.
    $a = (int) scalar("SELECT verpackung_id FROM auftrag WHERE produkt_id=? AND verpackung_id IS NOT NULL ORDER BY id DESC LIMIT 1", [$produkt_id]);
    if ($a > 0) return $a;
    $v = (int) scalar("SELECT verpackung_id FROM produkt WHERE id=?", [$produkt_id]);
    return $v > 0 ? $v : null;
}
// Etikett-Endformat [Breite, Höhe] mm eines Produkts (aus dem Behälter), oder null wenn nicht hinterlegt.
function produkt_etikettmass(int $produkt_id): ?array {
    $bid = produkt_behaelter_id($produkt_id);
    if (!$bid) return null;
    $dims = etikett_masse((string) scalar("SELECT etikett_final FROM item WHERE id=?", [$bid]));
    return $dims ? [max($dims[0], $dims[1]), min($dims[0], $dims[1])] : null;
}

// Welche Etiketten passen auf diesen Behälter? Maßgeblich ist das am BEHÄLTER hinterlegte
// Endformat (`item.etikett_final`, B x H) – ein Etikett passt, wenn seine Breite und Höhe
// (aus breite_mm/hoehe_mm, sonst aus etikett_format) bis auf 2 mm dazu stimmen.
// Ohne Endformat am Behälter lässt sich nichts zuordnen: dann kommt eine leere Liste zurück,
// und die Oberfläche sagt, was fehlt – statt wahllos alle Etiketten anzubieten.
function passende_etiketten_fuer(?int $verpackung_id): array {
    static $alleCache = null, $resCache = [];
    $ck = (int)$verpackung_id;
    if (array_key_exists($ck, $resCache)) return $resCache[$ck];
    if ($alleCache === null) $alleCache = all("SELECT id, name, breite_mm, hoehe_mm, etikett_format FROM item
                 WHERE kategorie='verpackung' AND verpackung_rolle='etikett' AND gesperrt=0 AND produkt_id IS NULL ORDER BY name");
    $alle = $alleCache;
    if (!$verpackung_id) return $resCache[$ck] = $alle;
    $ziel = etikett_masse((string) scalar("SELECT etikett_final FROM item WHERE id=?", [$verpackung_id]));
    if (!$ziel) return $resCache[$ck] = [];
    $out = [];
    foreach ($alle as $e) {
        $m = ($e['breite_mm'] && $e['hoehe_mm']) ? [(float)$e['breite_mm'], (float)$e['hoehe_mm']] : etikett_masse($e['etikett_format']);
        if ($m && abs($m[0] - $ziel[0]) <= 2.0 && abs($m[1] - $ziel[1]) <= 2.0) $out[] = $e;
    }
    return $resCache[$ck] = $out;
}

// Das passende Etikett zu einem Behälter (erstes Endformat-Match) – für die automatische Zuordnung.
function etikett_id_fuer_behaelter(int $verp_id): ?int {
    if ($verp_id <= 0) return null;
    $et = passende_etiketten_fuer($verp_id);
    return $et ? (int)$et[0]['id'] : null;
}
// Passenden PRIMAER-Behaelter aus Rezeptur + Stueckzahl bestimmen (Kapselgroesse/Fuellmenge -> kleinster
// passender Behaelter je Material). $material_hint (Freitext wie "Weithalsglas"/"PET"/"PLA") waehlt das
// Material. Wird der gewuenschte Werkstoff nicht gefunden, gibt es NULL (lieber nichts als das falsche
// Material) – nur ohne Hinweis wird der kleinste passende Behaelter genommen. Genutzt von Etiketten-Anzeige,
// verpackung_id-Backfill und Produktion. Rueckgabe: item-id des Behaelters oder null.
function behaelter_aus_rezeptur_stueck(int $rezeptur_id, string $form, int $stueck, string $material_hint = ''): ?int {
    if ($rezeptur_id <= 0 || $stueck <= 0) return null;
    $cands = passende_behaelter_fuer($rezeptur_id, $form ?: 'kapsel', $stueck);
    if (!$cands) return null;
    $t = mb_strtolower($material_hint);
    $want = str_contains($t, 'glas') ? 'glas' : (str_contains($t, 'pet') ? 'pet' : (str_contains($t, 'pla') ? 'pla' : ''));
    foreach ($cands as $vid) {
        $mat = mb_strtolower((string) scalar("SELECT material FROM item WHERE id=?", [(int)$vid]));
        if ($want === '' || $mat === $want) return (int)$vid;
    }
    return $want === '' ? (int)$cands[0] : null;   // Material verlangt, aber nicht gefunden -> nicht raten
}
// Behälter -> passende Etiketten-IDs, für die Auswahl im Angebots-Editor (ohne Nachladen).
// Gebündelt: statt je Primärbehälter ein "SELECT etikett_final" (waren dutzende Abfragen, auf der
// Remote-DB der Haupt-Bremser im Editor) werden Etiketten UND Behälter je in EINER Abfrage geladen
// und das Zuordnungs-Raster in PHP gerechnet (gleiche Logik wie passende_etiketten_fuer).
function etikett_zuordnung(): array {
    $etks = all("SELECT id, breite_mm, hoehe_mm, etikett_format FROM item
                 WHERE kategorie='verpackung' AND verpackung_rolle='etikett' AND gesperrt=0 AND produkt_id IS NULL ORDER BY name");
    $etkMass = [];
    foreach ($etks as $e)
        $etkMass[(int)$e['id']] = ($e['breite_mm'] && $e['hoehe_mm']) ? [(float)$e['breite_mm'], (float)$e['hoehe_mm']] : etikett_masse($e['etikett_format']);
    $map = [];
    foreach (all("SELECT id, etikett_final FROM item
                  WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND gesperrt=0") as $v) {
        $ziel = etikett_masse((string)$v['etikett_final']);
        $ids = [];
        if ($ziel) foreach ($etks as $e) {
            $m = $etkMass[(int)$e['id']];
            if ($m && abs($m[0] - $ziel[0]) <= 2.0 && abs($m[1] - $ziel[1]) <= 2.0) $ids[] = (int)$e['id'];
        }
        $map[(int)$v['id']] = $ids;
    }
    return $map;
}
// Passende Behälter je Stückzahl bestimmen – je Darreichungsform über die richtige Kennzahl.
// Kapsel: fasst >= Stück Kapseln (pack_kapazitaet). Pulver/Granulat/Stick/Tablette: max. Füllgewicht (g).
// Flüssig: Fassungsvermögen (volumen_ml) >= Füllvolumen.
// Rückgabe: je Material der kleinste passende Behälter [item_id, ...].
function passende_behaelter_fuer(int $rezeptur_id, string $form, int $stueck): array {
    if (in_array($form, ['kapsel', 'softgel'], true)) {
        $kg = rezeptur_kapselgroesse($rezeptur_id);
        if (!$kg) return [];
        $cands = all("SELECT pk.item_id, i.material FROM pack_kapazitaet pk JOIN item i ON i.id=pk.item_id
                      WHERE pk.kapselgroesse_id=? AND pk.stueck>=? AND i.gesperrt=0
                      ORDER BY (i.material IS NULL), i.material, pk.stueck ASC", [(int)$kg['id'], $stueck]);
    } elseif (in_array($form, ['pulver', 'granulat'], true)) {
        // Pulver/Granulat: $stueck ist das gewünschte Füllgewicht in Gramm.
        $fillG = (float) $stueck;
        if ($fillG <= 0) return [];
        $cands = all("SELECT id AS item_id, material FROM item
                      WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND gesperrt=0
                        AND max_fuellgewicht_g IS NOT NULL AND max_fuellgewicht_g >= ?
                      ORDER BY (material IS NULL), material, max_fuellgewicht_g ASC", [$fillG]);
    } elseif ($form === 'stick') {
        // Stick: $stueck = Anzahl Sticks; Füllgewicht = Portion je Stick × Anzahl.
        $portionG = rezeptur_gewicht_mg($rezeptur_id) / 1000;
        if ($portionG <= 0) return [];
        $fillG = $portionG * $stueck;
        $cands = all("SELECT id AS item_id, material FROM item
                      WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND gesperrt=0
                        AND max_fuellgewicht_g IS NOT NULL AND max_fuellgewicht_g >= ?
                      ORDER BY (material IS NULL), material, max_fuellgewicht_g ASC", [$fillG]);
    } elseif ($form === 'tablette') {
        // Tablette: $stueck = Anzahl Tabletten; Füllgewicht = Tablettengewicht (inkl. Presshilfsstoffe) × Anzahl.
        $fillG = tablette_gewicht_mg($rezeptur_id) / 1000 * $stueck;
        if ($fillG <= 0) return [];
        $cands = all("SELECT id AS item_id, material FROM item
                      WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND gesperrt=0
                        AND max_fuellgewicht_g IS NOT NULL AND max_fuellgewicht_g >= ?
                      ORDER BY (material IS NULL), material, max_fuellgewicht_g ASC", [$fillG]);
    } elseif ($form === 'gummi') {
        // Gummi: $stueck = Anzahl Gummis; Füllgewicht = Gummigewicht × Anzahl.
        $fillG = gummi_gewicht_je_stueck($rezeptur_id) / 1000 * $stueck;
        if ($fillG <= 0) return [];
        $cands = all("SELECT id AS item_id, material FROM item
                      WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND gesperrt=0
                        AND max_fuellgewicht_g IS NOT NULL AND max_fuellgewicht_g >= ?
                      ORDER BY (material IS NULL), material, max_fuellgewicht_g ASC", [$fillG]);
    } elseif (in_array($form, ['fluessig', 'gel'], true)) {
        // Flüssig/Gel: $stueck = Füllvolumen in ml; Behälter über das Fassungsvermögen (volumen_ml).
        $fillMl = (float) $stueck;
        if ($fillMl <= 0) return [];
        $cands = all("SELECT id AS item_id, material FROM item
                      WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND gesperrt=0
                        AND volumen_ml IS NOT NULL AND volumen_ml >= ?
                      ORDER BY (material IS NULL), material, volumen_ml ASC", [$fillMl]);
    } else {
        return [];   // unbekannte Form
    }
    $best = [];
    foreach ($cands as $c) { $mat = $c['material'] ?: '?'; if (!isset($best[$mat])) $best[$mat] = (int)$c['item_id']; }
    return array_values($best);
}

// Preismatrix eines Produkts neu erzeugen: Stückzahlen × passende Behälter (kleinster je Material) × Bestellmengen.
function produkt_matrix_generieren(int $produkt_id): int {
    $rid = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [$produkt_id]);
    if (!$rid) return 0;
    $form = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rid]) ?: 'kapsel';
    q("DELETE FROM produkt_preis WHERE produkt_id=?", [$produkt_id]);
    $anz = 0;
    foreach (std_groessen_fuer($form) as $stueck)   // Pulver/Granulat: Füllgewicht (g); sonst Stückzahl
        $anz += produkt_preis_fuer_groesse($produkt_id, (int)$stueck);
    return $anz;
}

// Preiszeilen für EINE Packungsgröße nachrechnen – auch für Größen außerhalb des Standardrasters.
// Der Kunde darf seine Menge frei eintippen (250 g, 100 Kapseln …); dann muss auch dafür ein Preis
// entstehen, statt still auf die nächstbeste Rastergröße auszuweichen.
// Idempotent: sind für die Größe schon Zeilen da, passiert nichts. 0 = nicht machbar (kein Behälter passt).
function produkt_preis_fuer_groesse(int $produkt_id, int $stueck): int {
    if ($stueck <= 0) return 0;
    $rid = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [$produkt_id]);
    if (!$rid) return 0;
    if ((int) scalar("SELECT COUNT(*) FROM produkt_preis WHERE produkt_id=? AND stueck=?", [$produkt_id, $stueck]) > 0) return 0;
    $form = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rid]) ?: 'kapsel';
    $anz = 0;
    foreach (passende_behaelter_fuer($rid, $form, $stueck) as $vid) {
        foreach (std_bestellmengen() as $bm) {
            $ek = produkt_variante_ek($produkt_id, $stueck, $vid, $bm);
            $vk = produkt_variante_vk($produkt_id, $ek);
            q("INSERT INTO produkt_preis (produkt_id,stueck,verpackung_id,bestellmenge,ek_preis,vk_preis,stand)
               VALUES (?,?,?,?,?,?,?)",
              [$produkt_id, $stueck, $vid, $bm, round($ek, 4), round($vk, 4), gmdate('Y-m-d H:i:s')]);
            $anz++;
        }
    }
    return $anz;
}

// Matrix eines Produkts für EINE Anfrage-Konfiguration – stellt sicher, dass die vom Kunden
// gewünschte Größe darin vorkommt (auch außerhalb des Rasters), bevor die Matrix gelesen wird.
function angebot_matrix_fuer_gruppe(int $produkt_id, array $g, string $form, ?float $marge_override): array {
    if ((int) scalar("SELECT COUNT(*) FROM produkt_preis WHERE produkt_id=?", [$produkt_id]) === 0)
        produkt_matrix_generieren($produkt_id);
    $wunsch = form_ist_fuellmenge($form) ? (int) round((float)($g['fuellmenge_g'] ?? 0)) : (int)($g['stueck'] ?? 0);
    if ($wunsch > 0) produkt_preis_fuer_groesse($produkt_id, $wunsch);
    return angebot_matrix($produkt_id, $marge_override);
}

// Stationen/Gates einer Produktion je Darreichungsform.
// $wege (optional) = Ausbaustufen ['abfuellen'=>bool,'etikettieren'=>bool,'beipack'=>bool,'karton'=>bool].
// null = bisheriges Standardverhalten (Verpacken + Etikettieren an, Beipack/Karton aus). Die optionalen
// Stationen liegen – in dieser Reihenfolge – zwischen dem Bereitstellen/Herstellen-Block und der
// Qualitätsprüfung. Reihenfolge/Namen sind der „Vertrag" mit dem Produktions-Programm (/produktion/).
function produktionsschritte_fuer(string $form, bool $zukauf = false, bool $bulk = false, ?array $wege = null): array {
    $w = [
        'abfuellen'    => $wege === null ? true  : !empty($wege['abfuellen']),
        'etikettieren' => $wege === null ? true  : !empty($wege['etikettieren']),
        'beipack'      => $wege === null ? false : !empty($wege['beipack']),
        'karton'       => $wege === null ? false : !empty($wege['karton']),
    ];
    $ausbau = [];
    if ($w['abfuellen'])    $ausbau[] = 'Verpacken';
    if ($w['etikettieren']) $ausbau[] = 'Etikettieren';
    if ($w['beipack'])      $ausbau[] = 'Beipackzettel beilegen';
    if ($w['karton'])       $ausbau[] = 'Umkarton';

    // Zugekaufte fertige Bulkware (fertige Kapseln/Tabletten vom Lieferanten):
    // kein Rohstoff-Bereitstellen/Mischen/Verkapseln – nur bereitstellen, Ausbaustufen, prüfen.
    if ($zukauf) {
        return array_merge(['Fertigware bereitstellen'], $ausbau,
                ['Qualitätsprüfung', 'Produktions-Freigabe', 'Versand-Freigabe']);
    }
    $herstellung = match ($form) {
        'kapsel'   => 'Verkapselung',
        'tablette' => 'Tablettierung',
        'softgel'  => 'Softgel-Herstellung',
        'stick'    => 'Stick-Abfüllung',
        'pulver'   => 'Pulver-Abfüllung',
        'fluessig' => 'Abfüllung',
        'gummi'    => 'Gummi-Herstellung (Gießen)',
        'gel'      => 'Gel-Abfüllung',
        default    => 'Herstellung',
    };
    // Bulk-/Lagerproduktion (nur Kapseln, ohne Verpackung): ohne Ausbaustufen/Versand-Freigabe.
    if ($bulk) {
        return ['Rohstoffe bereitstellen', 'Mischen', $herstellung, 'Qualitätsprüfung', 'Einlagern (Bulk)'];
    }
    return array_merge(['Rohstoffe bereitstellen', 'Mischen', $herstellung], $ausbau,
            ['Qualitätsprüfung', 'Produktions-Freigabe', 'Versand-Freigabe']);
}

// Ausbaustufen-Wege für einen Produktionsauftrag auflösen. Priorität je Stufe:
// Kunde-Override (wenn gesetzt, NULL = erbt) → Produkt-Standard → global (abfuellen=1, etikettieren=1, karton=0, beipack=0).
// Nur Admin pflegt diese Schalter (Produkt- bzw. – später – Kundenseite); der Kunde selbst stellt nichts ein.
function produktion_wege_aufloesen(?int $produkt_id, ?int $kunde_id = null): array {
    $def = ['abfuellen' => 1, 'etikettieren' => 1, 'karton' => 0, 'beipack' => 0];
    $p = ($produkt_id && table_exists('produkt')) ? one("SELECT weg_abfuellen,weg_etikettieren,weg_karton,weg_beipack FROM produkt WHERE id=?", [(int)$produkt_id]) : null;
    $k = ($kunde_id && table_exists('kunden')) ? one("SELECT weg_abfuellen,weg_etikettieren,weg_karton,weg_beipack FROM kunden WHERE id=?", [(int)$kunde_id]) : null;
    $out = [];
    foreach (['abfuellen', 'etikettieren', 'karton', 'beipack'] as $f) {
        $col = 'weg_' . $f;
        if ($k && isset($k[$col]) && $k[$col] !== null && $k[$col] !== '') $out[$f] = (int)$k[$col];
        elseif ($p && isset($p[$col]) && $p[$col] !== null)                $out[$f] = (int)$p[$col];
        else                                                              $out[$f] = $def[$f];
    }
    return $out;
}

// Bulk-PA? (nur Kapseln, ohne Verpackung – haengt an einer Rezeptur statt an einem Produkt)
function pa_ist_bulk(array $pa): bool {
    return empty($pa['produkt_id']) && !empty($pa['rezeptur_id']);
}

// Leerkapsel-Kandidaten einer Rezeptur (fuer Bulk-Produktion – analog produkt_leerkapsel_kandidaten, aber rezeptur-basiert).
function rezeptur_leerkapsel_id(int $rezeptur_id): ?int {
    if ($rezeptur_id <= 0) return null;
    $rz = one("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rezeptur_id]);
    if (!$rz || $rz['darreichungsform'] !== 'kapsel') return null;
    $kg = rezeptur_kapselgroesse($rezeptur_id);
    if (!$kg) return null;
    $k = all("SELECT id FROM item WHERE kategorie='rohstoff' AND form='kapselhuelle' AND kapselgroesse_id=? AND gesperrt=0 ORDER BY id", [(int)$kg['id']]);
    return count($k) === 1 ? (int)$k[0]['id'] : null;   // nur bei Eindeutigkeit automatisch
}

// Bulk-Lagerartikel (Kategorie 'fertig') zu einer Rezeptur – finden oder anlegen. Hier landet die Bulk-Fertigware.
function rezeptur_bulkitem(int $rezeptur_id): ?int {
    if ($rezeptur_id <= 0) return null;
    $id = scalar("SELECT id FROM item WHERE rezeptur_id=? AND kategorie='fertig' LIMIT 1", [$rezeptur_id]);
    if ($id) return (int)$id;
    $rz = one("SELECT name, darreichungsform FROM rezeptur WHERE id=?", [$rezeptur_id]);
    if (!$rz) return null;
    $einheit = ($rz['darreichungsform'] === 'pulver') ? 'g' : (in_array($rz['darreichungsform'], ['fluessig','gel'], true) ? 'ml' : 'Stück');
    q("INSERT INTO item (artikelnummer,name,kategorie,form,einheit,preis_bezug,rezeptur_id) VALUES (?,?,?,?,?,?,?)",
      [naechste_nummer('BULK'), $rz['name'] . ' – Bulk', 'fertig', (string)$rz['darreichungsform'], $einheit, $einheit, $rezeptur_id]);
    return insert_id();
}

// Auftrags-Art: ist das ein Erstauftrag oder eine Nachbestellung? Basis: frühere, nicht stornierte
// Aufträge. 'nach' = Produkt schon mal bestellt; 'neu_prod' = neues Produkt, Rezeptur aber bekannt;
// 'neu_rez' = neue Rezeptur (noch nie produziert); 'none' = ohne Produkt/Rezeptur-Zuordnung.
function auftrag_art(int $auftrag_id): string {
    $a = one("SELECT a.id, a.produkt_id, p.rezeptur_id
              FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$auftrag_id]);
    if (!$a) return 'none';
    $pid = (int)($a['produkt_id'] ?? 0); $rid = (int)($a['rezeptur_id'] ?? 0); $id = (int)$a['id'];
    if ($pid <= 0 && $rid <= 0) return 'none';
    if ($pid > 0 && (int) scalar("SELECT COUNT(*) FROM auftrag WHERE produkt_id=? AND status<>'storniert' AND id<?", [$pid, $id]) > 0)
        return 'nach';
    if ($rid > 0 && (int) scalar("SELECT COUNT(*) FROM auftrag a JOIN produkt p ON p.id=a.produkt_id
                                  WHERE p.rezeptur_id=? AND a.status<>'storniert' AND a.id<?", [$rid, $id]) > 0)
        return 'neu_prod';
    return 'neu_rez';
}
// Label/Stil/Hinweis je Auftrags-Art – EINE Quelle für Liste und Detail.
function auftrag_art_meta(string $key): array {
    return match ($key) {
        'nach'     => ['Nachbestellung', '',     'Dieses Produkt wurde vorher schon bestellt.'],
        'neu_prod' => ['Neues Produkt',  'info', 'Neues Produkt – die Rezeptur haben wir aber schon gemacht.'],
        'neu_rez'  => ['Neue Rezeptur',  'warn', 'Erstauftrag – diese Rezeptur haben wir noch nie produziert.'],
        default    => ['', '', ''],
    };
}

// Bulk-Produktionsauftrag anlegen: nur Kapseln (o. Ä.) ohne Verpackung, auf Basis einer REZEPTUR.
// Menge = Stueck (Kapseln). Gibt die neue pa-id zurueck (0 bei ungueltiger Rezeptur).
function produktionsauftrag_bulk_erstellen(int $rezeptur_id, int $stueck, int $prio = 2): int {
    $rezeptur_id = (int)$rezeptur_id;
    if ($rezeptur_id <= 0) return 0;
    $rz = one("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rezeptur_id]);
    if (!$rz) return 0;
    $stueck = max(0, $stueck);
    $prio   = max(1, min(3, $prio));
    $form   = (string)($rz['darreichungsform'] ?: 'kapsel');
    q("INSERT INTO produktionsauftrag (nummer,auftrag_id,kunde_id,produkt_id,rezeptur_id,menge,produktionsart,status,prio)
       VALUES (?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('PR'), null, null, null, $rezeptur_id, $stueck, 'eigen', 'offen', $prio]);
    $paid = (int) insert_id();
    foreach (produktionsschritte_fuer($form, false, true) as $i => $station)
        q("INSERT INTO produktion_schritt (pa_id,station,sort,erledigt) VALUES (?,?,?,0)", [$paid, $station, $i]);
    bedarf_bump();
    return $paid;
}

// Produktionsbereitschaft: ist das Material komplett da, um den Auftrag zu produzieren?
// Rückgabe: ['status'=>bereit|wartet|laeuft|fertig|unbekannt, 'fehlend'=>[['name','benoetigt','verfuegbar','einheit'], ...]]
function produktion_bereitschaft(int $pa_id): array {
    $pa = pa_row_cached($pa_id);
    if (!$pa) return ['status'=>'unbekannt', 'fehlend'=>[]];
    if ($pa['status'] === 'erledigt') return ['status'=>'fertig', 'fehlend'=>[]];
    // Anzahl erledigter Schritte – in der Liste per Bulk vorgeladen (Cache 'sch:'.pa_id), sonst Einzelabfrage.
    $schritteDone = isset($GLOBALS['bx_stock_cache']) && array_key_exists('schr:' . $pa_id, $GLOBALS['bx_stock_cache'])
        ? (int) $GLOBALS['bx_stock_cache']['schr:' . $pa_id]
        : (int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=? AND erledigt=1", [$pa_id]);
    if ($schritteDone > 0) return ['status'=>'laeuft', 'fehlend'=>[]];

    // Voller Stücklisten-Bedarf inkl. Verpackung/Etiketten, netto (Reservierungen anderer abgezogen).
    $fehlend = [];
    foreach (auftrag_bedarf_cached($pa_id) as $r)
        if ((float)$r['fehlt'] > 1e-6) $fehlend[] = $r;
    return ['status'=>$fehlend ? 'wartet' : 'bereit', 'fehlend'=>$fehlend];
}
function bereitschaft_badge(string $s): string {
    return match ($s) {
        'bereit' => bx_badge('produktionsbereit','ok'),
        'wartet' => bx_badge('wartet auf Material','warn'),
        'laeuft' => bx_badge('in Produktion','info'),
        'fertig' => bx_badge('fertig','ok'),
        default  => bx_badge('–'),
    };
}

// Wurde für diesen Auftrag fertige Bulkware zugekauft? (Charge eines Items der Kategorie 'fertig')
function produktion_ist_zukauf(int $auftrag_id): bool {
    if (!$auftrag_id) return false;
    return auftrag_fertigware_cached($auftrag_id)['n'] > 0;
}

// Produktionsschritte neu erzeugen (Weg umstellen) – nur solange KEIN Schritt erledigt ist.
function produktion_schritte_regenerieren(int $pa_id, bool $zukauf): bool {
    if ((int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=? AND erledigt=1", [$pa_id]) > 0) return false;
    $pa = one("SELECT produkt_id, rezeptur_id, kunde_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return false;
    $istBulk = pa_ist_bulk($pa);
    if ($istBulk) {
        $form = (string) (scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [(int)$pa['rezeptur_id']]) ?: 'kapsel');
    } else {
        $form = (string) (scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [(int)$pa['produkt_id']]) ?: 'kapsel');
    }
    q("DELETE FROM produktion_schritt WHERE pa_id=?", [$pa_id]);
    $wege = $istBulk ? null : produktion_wege_aufloesen((int)$pa['produkt_id'], (int)($pa['kunde_id'] ?? 0));
    foreach (produktionsschritte_fuer($form, $zukauf && !$istBulk, $istBulk, $wege) as $i => $station)
        q("INSERT INTO produktion_schritt (pa_id,station,sort,erledigt) VALUES (?,?,?,0)", [$pa_id, $station, $i]);
    q("UPDATE produktionsauftrag SET status='offen' WHERE id=?", [$pa_id]);
    return true;
}

// Lagerproduktion: einen Produktionsauftrag OHNE Kunde/Auftrag anlegen (Vorrats-/Lagerproduktion).
// Gibt die neue pa-id zurück (0 bei ungültigem Produkt). Menge = Packungen; Material skaliert über
// die Einheiten je Packung des Produkts – genau wie bei einem Kundenauftrag.
function produktionsauftrag_lager_erstellen(int $produkt_id, int $menge, string $art = 'eigen', int $prio = 2): int {
    $produkt_id = (int)$produkt_id;
    if ($produkt_id <= 0 || !scalar("SELECT id FROM produkt WHERE id=?", [$produkt_id])) return 0;
    $art   = $art === 'fremd' ? 'fremd' : 'eigen';
    $menge = max(0, $menge);
    $prio  = max(1, min(3, $prio));
    $form  = (string) (scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$produkt_id]) ?: 'kapsel');
    q("INSERT INTO produktionsauftrag (nummer,auftrag_id,kunde_id,produkt_id,menge,produktionsart,status,prio)
       VALUES (?,?,?,?,?,?,?,?)",
      [naechste_nummer('PR'), null, null, $produkt_id, $menge, $art, 'offen', $prio]);
    $paid = (int) insert_id();
    foreach (produktionsschritte_fuer($form, $art === 'fremd', false, produktion_wege_aufloesen($produkt_id, null)) as $i => $station)
        q("INSERT INTO produktion_schritt (pa_id,station,sort,erledigt) VALUES (?,?,?,0)", [$paid, $station, $i]);
    bedarf_bump();
    return $paid;
}

// Produktionsart eines Auftrags umstellen (eigen ↔ fremd) inkl. passender Schritte.
// Gibt false zurück, wenn schon ein Schritt erledigt ist (dann nicht mehr umstellbar).
function produktionsauftrag_art_setzen(int $pa_id, string $art): bool {
    $art = $art === 'eigen' ? 'eigen' : 'fremd';
    // Gesperrt, sobald für den Auftrag bestellt wurde – Eigen/Fremd ist dann nicht mehr änderbar.
    $aidA = (int) scalar("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if ($aidA && (int) scalar("SELECT COUNT(*) FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id
                               WHERE bp.auftrag_id=? AND b.status<>'storniert'", [$aidA]) > 0) return false;
    if (!produktion_schritte_regenerieren($pa_id, $art === 'fremd')) return false;   // fremd = verkürzter (Zukauf-)Weg
    // Festlegen = Freigabe an die Produktion: produktionsart + Zeitstempel. Erst jetzt erscheint der Auftrag im Werk.
    q("UPDATE produktionsauftrag SET produktionsart=?, art_festgelegt_am=NOW() WHERE id=?", [$art, $pa_id]);
    bedarf_bump();   // Eigen/Fremd geaendert -> andere Stueckliste
    return true;
}

// ===== PreProduktionsauftrag / Vor-Produktion =====================================================
// Checkliste eines Vor-Produktionsauftrags: ist alles da, um die Produktion durchzuführen?
// Rückgabe: ['pa'=>…, 'auftrag_id'=>…, 'einheiten_bedarf'=>…, 'checks'=>[['key','label','ok','wert','kritisch'], …],
//            'fehlend'=>[…Material…], 'bereit'=>bool]. „bereit" ist nur die Ampel – der Admin kann IMMER freigeben.
function pa_vorbereitung_checks(int $pa_id): array {
    $pa = one("SELECT * FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return ['pa'=>null, 'checks'=>[], 'fehlend'=>[], 'bereit'=>false, 'einheiten_bedarf'=>0];
    $aid = (int)$pa['auftrag_id'];
    $pid = (int)$pa['produkt_id'];
    $slots = $pid ? one("SELECT verpackung_id, etikett_id FROM produkt WHERE id=?", [$pid]) : null;
    $af    = $aid ? one("SELECT verpackung_id FROM auftrag WHERE id=?", [$aid]) : null;
    $verpEff = !empty($af['verpackung_id']) ? (int)$af['verpackung_id'] : (int)($slots['verpackung_id'] ?? 0);

    $checks = [];
    // 1) Glas/Behälter gewählt (Konfiguration). Ohne Behälter kann weder Einkauf noch Produktion rechnen.
    $checks[] = ['key'=>'glas', 'label'=>'Verpackung / Glas gewählt', 'kritisch'=>true,
                 'ok'=>$verpEff > 0, 'wert'=>$verpEff > 0 ? (string) scalar("SELECT name FROM item WHERE id=?", [$verpEff]) : 'nicht gewählt'];
    // 2) Etikett freigegeben (Kunde/Team) – nur wenn das Produkt ein Etikett braucht.
    if (function_exists('auftrag_braucht_etikett') && $aid && auftrag_braucht_etikett($aid)) {
        $frei = function_exists('etikett_freigegeben') && etikett_freigegeben($aid);
        $checks[] = ['key'=>'etikett', 'label'=>'Etikett freigegeben', 'kritisch'=>true,
                     'ok'=>(bool)$frei, 'wert'=>$frei ? 'freigegeben' : 'noch nicht freigegeben'];
    }
    // 3) Material: Rohstoffe/Bulk angekommen, genug Gläser, Kartons … – je Rolle aus der Stückliste.
    $einh = max(0, (int) produktion_stueck_je_packung($pa)) * max(0, (int)$pa['menge']);
    $fehlend = []; $proRolle = [];
    foreach (auftrag_bedarf($pa_id) as $r) {
        $rolle = (string)$r['rolle'];
        $fehlt = (float)$r['fehlt'];
        if (!isset($proRolle[$rolle])) $proRolle[$rolle] = 0.0;
        $proRolle[$rolle] += $fehlt;
        if ($fehlt > 1e-6) $fehlend[] = $r;
    }
    // Rollen zu sprechenden Vor-Produktions-Checks bündeln.
    $rollenMap = [
        'Material angekommen (Rohstoffe/Bulk)' => ['Rohstoff','Fertigware','Leerkapsel'],
        'Gläser / Verpackung vorrätig'         => ['Verpackung','Deckel'],
        'Kartons vorrätig'                     => ['Karton'],
        'Etikett vorrätig'                     => ['Etikett'],
        'Beipackzettel vorrätig'               => ['Beipackzettel'],
    ];
    foreach ($rollenMap as $label => $rollen) {
        $betroffen = array_intersect_key($proRolle, array_flip($rollen));
        if (!$betroffen) continue;   // diese Rolle kommt beim Auftrag gar nicht vor
        $fehltSumme = array_sum($betroffen);
        $checks[] = ['key'=>'mat_'.md5($label), 'label'=>$label, 'kritisch'=>false,
                     'ok'=>$fehltSumme <= 1e-6, 'wert'=>$fehltSumme <= 1e-6 ? 'vollständig da'
                            : ('fehlt noch ' . (fmod($fehltSumme, 1) == 0 ? (string)(int)$fehltSumme : number_format($fehltSumme, 2, ',', '.')))];
    }
    $bereit = true;
    foreach ($checks as $c) if (!$c['ok']) { $bereit = false; break; }
    return ['pa'=>$pa, 'auftrag_id'=>$aid, 'einheiten_bedarf'=>$einh, 'checks'=>$checks, 'fehlend'=>$fehlend, 'bereit'=>$bereit];
}

// Alle Vor-Produktionsaufträge (Status 'vorbereitung') mit Eckdaten für die Dashboard-Liste.
function vorbereitung_liste(): array {
    if (!table_exists('produktionsauftrag')) return [];
    return all("SELECT pa.id AS pa_id, pa.nummer, pa.auftrag_id, pa.produktionsart, pa.menge, pa.prio,
                       a.nummer AS auftrag_nr, a.angelegt AS auftrag_eingang,
                       COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name, rz.name) AS produkt,
                       rz.name AS rezeptur, k.firma AS kunde
                FROM produktionsauftrag pa
                LEFT JOIN auftrag a   ON a.id=pa.auftrag_id
                LEFT JOIN produkt p   ON p.id=pa.produkt_id
                LEFT JOIN rezeptur rz ON rz.id=COALESCE(pa.rezeptur_id, p.rezeptur_id)
                LEFT JOIN kunden k    ON k.id=pa.kunde_id
                WHERE pa.status='vorbereitung'
                ORDER BY COALESCE(pa.prio,2), pa.id DESC");
}

// Vor-Produktionsauftrag ZUR PRODUKTION FREIGEBEN. Harte Weiche: danach ist es ein echter, startbarer PA.
// Der Admin kann IMMER freigeben (auch bei roten Checks – die sind dann nur Warnung). Setzt Eigen/Fremd
// (inkl. passender Schritte), optional eine höhere Produktionsmenge (Überschuss -> Rezeptur-Bulk) und
// kippt den Status auf 'offen'. Rückgabe: ['ok'=>bool, 'fehler'=>?string].
function produktionsauftrag_freigeben(int $pa_id, string $art = 'fremd', ?int $menge_produktion = null, string $wer = ''): array {
    $pa = one("SELECT * FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return ['ok'=>false, 'fehler'=>'Produktionsauftrag nicht gefunden.'];
    if (($pa['status'] ?? '') !== 'vorbereitung') return ['ok'=>true, 'fehler'=>null];   // schon freigegeben -> idempotent
    $art = $art === 'eigen' ? 'eigen' : 'fremd';
    // Schritte passend zu Eigen/Fremd neu erzeugen (setzt Status intern auf 'offen'); bei vorbereitung ist nie ein Schritt erledigt.
    produktion_schritte_regenerieren($pa_id, $art === 'fremd');
    // Überproduktion: geplante Menge in Einheiten; nur speichern, wenn höher als der reine Auftragsbedarf.
    $bedarfEinh = max(0, (int) produktion_stueck_je_packung($pa)) * max(0, (int)$pa['menge']);
    $mp = ($menge_produktion !== null && $menge_produktion > $bedarfEinh) ? (int)$menge_produktion : null;
    q("UPDATE produktionsauftrag
       SET status='offen', produktionsart=?, art_festgelegt_am=NOW(), freigegeben_am=NOW(), freigegeben_von=?, menge_produktion=?
       WHERE id=?", [$art, $wer !== '' ? $wer : null, $mp, $pa_id]);
    bedarf_bump();
    $kid = (int)($pa['kunde_id'] ?? 0);
    if ($kid) log_aktivitaet('kunde', $kid, 'team',
        'Produktionsauftrag ' . (string)$pa['nummer'] . ' zur Produktion freigegeben (' . $art
        . ($mp ? ', Produktionsmenge ' . $mp . ' Einheiten' : '') . ').',
        'auftrag', 'auftrag', (int)($pa['auftrag_id'] ?? 0));
    return ['ok'=>true, 'fehler'=>null];
}

// Behälter-Empfehlung: das kleinste passende Glas/die kleinste Dose für Kapselgröße + Stückzahl je Packung
// (aus pack_kapazitaet – max. Kapseln je Behälter und Größe). Rückgabe item-id oder null.
function verpackung_empfehlung(int $kapselgroesse_id, int $stueck): ?int {
    if ($kapselgroesse_id <= 0 || $stueck <= 0 || !table_exists('pack_kapazitaet')) return null;
    $row = one("SELECT pk.item_id FROM pack_kapazitaet pk JOIN item i ON i.id=pk.item_id
                WHERE pk.kapselgroesse_id=? AND pk.stueck>=? AND i.kategorie='verpackung'
                  AND COALESCE(i.verpackung_rolle,'primaer')='primaer' AND COALESCE(i.gesperrt,0)=0
                ORDER BY pk.stueck ASC, i.id ASC LIMIT 1", [$kapselgroesse_id, $stueck]);
    return $row ? (int)$row['item_id'] : null;
}
// Behälter-Empfehlung für einen (Vor-)Produktionsauftrag: Kapselgröße der Rezeptur + Stück je Packung.
function verpackung_empfehlung_fuer_pa(int $pa_id): ?int {
    $pa = one("SELECT pa.stueck, p.rezeptur_id, p.einheiten_pro_packung
               FROM produktionsauftrag pa LEFT JOIN produkt p ON p.id=pa.produkt_id WHERE pa.id=?", [$pa_id]);
    if (!$pa || empty($pa['rezeptur_id'])) return null;
    $kg  = (int) scalar("SELECT kapselgroesse_id FROM rezeptur WHERE id=?", [(int)$pa['rezeptur_id']]);
    $stk = (int)($pa['einheiten_pro_packung'] ?? 0) ?: (int)($pa['stueck'] ?? 0);
    return ($kg && $stk) ? verpackung_empfehlung($kg, $stk) : null;
}
// Alle noch nicht gestarteten Kunden-Produktionsaufträge in die Vor-Produktion holen (Status 'vorbereitung').
// Lässt bereits begonnene (ein Schritt erledigt) und erledigte/stornierte unberührt. Rückgabe: Anzahl.
function vorbereitung_alle_holen(): int {
    if (!table_exists('produktionsauftrag')) return 0;
    $ids = all("SELECT pa.id FROM produktionsauftrag pa
                WHERE pa.status IN ('offen','laufend') AND pa.auftrag_id IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=1)");
    $n = 0;
    foreach ($ids as $r) { q("UPDATE produktionsauftrag SET status='vorbereitung', freigegeben_am=NULL, freigegeben_von=NULL WHERE id=?", [(int)$r['id']]); $n++; }
    if ($n) bedarf_bump();
    return $n;
}

// --- Bestandsreservierung (manuell) ---
function item_reserviert_andere(int $item_id, int $auftrag_id): float {
    $ck = 'ra:' . $item_id . ':' . $auftrag_id;
    if (isset($GLOBALS['bx_stock_cache']) && array_key_exists($ck, $GLOBALS['bx_stock_cache'])) return $GLOBALS['bx_stock_cache'][$ck];
    $v = (float) scalar("SELECT COALESCE(SUM(menge),0) FROM reservierung WHERE item_id=? AND status='aktiv' AND (auftrag_id IS NULL OR auftrag_id<>?)", [$item_id, $auftrag_id]);
    if (isset($GLOBALS['bx_stock_cache'])) $GLOBALS['bx_stock_cache'][$ck] = $v;
    return $v;
}
function item_reserviert_eigen(int $item_id, int $auftrag_id): float {
    $ck = 're:' . $item_id . ':' . $auftrag_id;
    if (isset($GLOBALS['bx_stock_cache']) && array_key_exists($ck, $GLOBALS['bx_stock_cache'])) return $GLOBALS['bx_stock_cache'][$ck];
    $v = (float) scalar("SELECT COALESCE(SUM(menge),0) FROM reservierung WHERE item_id=? AND status='aktiv' AND auftrag_id=?", [$item_id, $auftrag_id]);
    if (isset($GLOBALS['bx_stock_cache'])) $GLOBALS['bx_stock_cache'][$ck] = $v;
    return $v;
}

// Flag-gesteuerte (nur in der schreibfreien Produktionsliste aktive) Cache-Helfer, damit dieselbe
// Zeile nicht mehrfach je Auftrag geholt wird. Ohne aktiven Cache = normale Einzelabfrage.
function pa_row_cached(int $pa_id): ?array {
    if (!isset($GLOBALS['bx_stock_cache'])) return one("SELECT * FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $ck = 'pa:' . $pa_id;
    if (!array_key_exists($ck, $GLOBALS['bx_stock_cache'])) $GLOBALS['bx_stock_cache'][$ck] = one("SELECT * FROM produktionsauftrag WHERE id=?", [$pa_id]);
    return $GLOBALS['bx_stock_cache'][$ck];
}
function produkt_row_cached(int $produkt_id): ?array {
    if (!isset($GLOBALS['bx_stock_cache'])) return one("SELECT * FROM produkt WHERE id=?", [$produkt_id]);
    $ck = 'prod:' . $produkt_id;
    if (!array_key_exists($ck, $GLOBALS['bx_stock_cache'])) $GLOBALS['bx_stock_cache'][$ck] = one("SELECT * FROM produkt WHERE id=?", [$produkt_id]);
    return $GLOBALS['bx_stock_cache'][$ck];
}
function item_name_cached(int $item_id): string {
    if (!isset($GLOBALS['bx_stock_cache'])) return (string) scalar("SELECT name FROM item WHERE id=?", [$item_id]);
    $ck = 'iname:' . $item_id;
    if (!array_key_exists($ck, $GLOBALS['bx_stock_cache'])) $GLOBALS['bx_stock_cache'][$ck] = (string) scalar("SELECT name FROM item WHERE id=?", [$item_id]);
    return $GLOBALS['bx_stock_cache'][$ck];
}
function auftrag_row_cached(int $auftrag_id): ?array {
    if (!isset($GLOBALS['bx_stock_cache'])) return one("SELECT * FROM auftrag WHERE id=?", [$auftrag_id]);
    $ck = 'auf:' . $auftrag_id;
    if (!array_key_exists($ck, $GLOBALS['bx_stock_cache'])) $GLOBALS['bx_stock_cache'][$ck] = one("SELECT * FROM auftrag WHERE id=?", [$auftrag_id]);
    return $GLOBALS['bx_stock_cache'][$ck];
}
// Fertigware-Zukauf je Auftrag: ['n'=>Anzahl fertig-Chargen, 'frei'=>Summe verfügbar (status frei)].
// Aus zwei Skalaren zusammengesetzt (Verhalten wie bisher); in der Liste per Cache/Bulk nur einmal.
function auftrag_fertigware_cached(int $auftrag_id): array {
    if (isset($GLOBALS['bx_stock_cache'])) {
        $ck = 'fw:' . $auftrag_id;
        if (array_key_exists($ck, $GLOBALS['bx_stock_cache'])) return $GLOBALS['bx_stock_cache'][$ck];
    }
    $r = [
        'n'    => (int) scalar("SELECT COUNT(*) FROM charge c JOIN item i ON i.id=c.item_id WHERE c.auftrag_id=? AND i.kategorie='fertig'", [$auftrag_id]),
        'frei' => (float) scalar("SELECT COALESCE(SUM(c.menge_verfuegbar),0) FROM charge c JOIN item i ON i.id=c.item_id WHERE c.auftrag_id=? AND i.kategorie='fertig' AND c.status='frei'", [$auftrag_id]),
    ];
    if (isset($GLOBALS['bx_stock_cache'])) $GLOBALS['bx_stock_cache']['fw:' . $auftrag_id] = $r;
    return $r;
}
// Netto verfügbar FÜR diesen Auftrag = freier Bestand − Reservierungen ANDERER Aufträge (eigene Reservierung zählt als verfügbar).
function item_verfuegbar_fuer(int $item_id, int $auftrag_id): float {
    return max(0.0, item_bestand($item_id, true) - item_reserviert_andere($item_id, $auftrag_id));
}
// Für diesen Auftrag den aktuell freien (noch nicht anderweitig reservierten) Bestand fest reservieren. Gibt Anzahl neuer Reservierungen zurück.
function auftrag_reservieren(int $pa_id): int {
    $pa = one("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $aid = $pa ? (int)$pa['auftrag_id'] : 0;
    $n = 0;
    foreach (auftrag_bedarf($pa_id) as $r) {
        $iid = (int)$r['item_id']; if ($iid <= 0) continue;
        $need   = (float)$r['benoetigt'];
        $eigen  = item_reserviert_eigen($iid, $aid);
        $frei   = item_bestand($iid, true);
        $andere = item_reserviert_andere($iid, $aid);
        $frei_ungebunden = $frei - $andere - $eigen;          // noch nicht reservierter freier Bestand
        $reserve = min($need - $eigen, $frei_ungebunden);
        if ($reserve > 1e-6) {
            q("INSERT INTO reservierung (pa_id,auftrag_id,item_id,menge,status,angelegt) VALUES (?,?,?,?,'aktiv',?)",
              [$pa_id, $aid, $iid, $reserve, gmdate('Y-m-d H:i:s')]);
            $n++;
        }
    }
    if ($n > 0) bedarf_bump();   // Reservierungen geaendert -> Bedarf-Cache ungueltig
    return $n;
}
function auftrag_reservierung_freigeben(int $pa_id): void {
    q("UPDATE reservierung SET status='storniert' WHERE pa_id=? AND status='aktiv'", [$pa_id]);
}
// Bei physischer Entnahme: aktive Reservierung des Auftrags für dieses Item schließen (sonst doppelte Sperre).
function reservierung_verbrauchen(int $auftrag_id, int $item_id): void {
    if (!$auftrag_id) return;
    q("UPDATE reservierung SET status='verbraucht' WHERE auftrag_id=? AND item_id=? AND status='aktiv'", [$auftrag_id, $item_id]);
    bedarf_bump();
}
// Nach Produktionsschritten: Reservierungen für bereits (teil)entnommene Items schließen.
function reservierung_abgleichen(int $pa_id): void {
    $aid = (int) scalar("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$aid) return;
    q("UPDATE reservierung SET status='verbraucht'
       WHERE auftrag_id=? AND status='aktiv' AND item_id IN (SELECT item_id FROM produktion_verbrauch WHERE pa_id=?)", [$aid, $pa_id]);
    bedarf_bump();
}

// Bezeichnung + Darreichungsform + Stück-Einheit des zuzukaufenden Bulks eines Produkts.
// Statt generisch „Bulk (Kapseln/Tabletten/Pulver)" -> Produktname + konkrete Form aus der Rezeptur.
// Lieferbedingungen für Lieferantenpreise – Auswahllisten (Schlüssel = gespeicherter Wert).
function incoterm_liste(): array {
    return ['EXW'=>'EXW – ab Werk','FCA'=>'FCA','FOB'=>'FOB','CFR'=>'CFR','CIF'=>'CIF','DAP'=>'DAP – frei Haus, ohne Zoll','DDP'=>'DDP – frei Haus, alles inkl.'];
}
function versandart_liste(): array {
    return ['luft'=>'Luft (Air)','see'=>'See (Sea)','bahn'=>'Bahn (Train)','lkw'=>'LKW / Straße','express'=>'Express','standard'=>'Standard'];
}
// Eine Versandart mehrsprachig beschriften. Deckt auch die alten Bestell-Werte (kurier/spedition/post)
// mit ab, damit früher gespeicherte Bestellungen lesbar bleiben. Fallback: der Schlüssel selbst.
function versandart_label(string $key, string $sprache = 'de'): string {
    $m = [
        'luft'      => ['de'=>'Luftfracht',   'en'=>'Air freight',       'zh'=>'空运'],
        'see'       => ['de'=>'Seefracht',    'en'=>'Sea freight',       'zh'=>'海运'],
        'bahn'      => ['de'=>'Bahn',         'en'=>'Rail',              'zh'=>'铁路'],
        'lkw'       => ['de'=>'LKW / Straße', 'en'=>'Truck / Road',      'zh'=>'公路'],
        'express'   => ['de'=>'Express',      'en'=>'Express',           'zh'=>'快递'],
        'standard'  => ['de'=>'Standard',     'en'=>'Standard',          'zh'=>'标准'],
        // Alt-Werte aus früheren Bestellungen:
        'kurier'    => ['de'=>'Kurier',       'en'=>'Courier',           'zh'=>'快递'],
        'spedition' => ['de'=>'Spedition',    'en'=>'Freight forwarder', 'zh'=>'货运代理'],
        'post'      => ['de'=>'Post',         'en'=>'Postal',            'zh'=>'邮政'],
    ];
    $spr = in_array($sprache, ['de','en','zh'], true) ? $sprache : 'de';
    return $m[$key][$spr] ?? ($m[$key]['de'] ?? $key);
}

function produkt_bulk_info(int $produkt_id, string $fbName = '', string $fbForm = ''): array {
    $p = null;
    if ($produkt_id) {
        $sql = "SELECT p.name, COALESCE(r.darreichungsform,'') AS form FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?";
        if (isset($GLOBALS['bx_stock_cache'])) {
            $ck = 'pbulk:' . $produkt_id;
            if (!array_key_exists($ck, $GLOBALS['bx_stock_cache'])) $GLOBALS['bx_stock_cache'][$ck] = one($sql, [$produkt_id]);
            $p = $GLOBALS['bx_stock_cache'][$ck];
        } else {
            $p = one($sql, [$produkt_id]);
        }
    }
    $formMap = ['kapsel'=>'Kapseln','tablette'=>'Tabletten','softgel'=>'Softgels','stick'=>'Sticks',
                'gummi'=>'Fruchtgummis','gel'=>'Gel','pulver'=>'Pulver','fluessig'=>'Flüssig'];
    $form = (string)($p['form'] ?? '') ?: trim($fbForm);          // Fallback-Form (v3-Auftrag ohne Produkt)
    $wort = $formMap[$form] ?? '';
    $name = trim((string)($p['name'] ?? '')) ?: (trim($fbName) ?: 'Produkt');
    $einheit = $form === 'pulver' ? 'g' : (in_array($form, ['fluessig','gel'], true) ? 'ml' : 'Stück');
    // Ohne bekannte Form (Produkt ohne Rezeptur) ehrlich als „Bulk (Form offen)" ausweisen.
    $bez = $name . ' – ' . ($wort !== '' ? $wort : 'Bulk (Form offen)') . ' (Zukauf)';
    return ['name'=>$name, 'form'=>$form, 'form_wort'=>$wort, 'einheit'=>$einheit, 'bezeichnung'=>$bez];
}

// Stück/Kapseln je Packung für einen Produktionsauftrag. Bevorzugt das Produkt
// (einheiten_pro_packung); ist das 0 (v3-Import ohne verknüpftes Produkt), fällt es
// auf den Auftrag zurück, der die Zahl als „stueck" trägt. Sonst 0.
// $pa = Zeile aus produktionsauftrag (braucht produkt_id + auftrag_id).
function produktion_stueck_je_packung(array $pa): int {
    if (!empty($pa['produkt_id'])) { $pr = produkt_row_cached((int)$pa['produkt_id']); $e = (int)($pr['einheiten_pro_packung'] ?? 0); if ($e > 0) return $e; }
    if (!empty($pa['auftrag_id'])) { $s = (int) scalar("SELECT stueck FROM auftrag WHERE id=?", [(int)$pa['auftrag_id']]); if ($s > 0) return $s; }
    return 0;
}

// Alle Daten fuer den Produktionsbericht (Herstellprotokoll) sammeln – genutzt von der internen
// Berichtseite UND der Kundenportal-Ansicht (gemeinsamer Render-Include _bericht_inhalt.php).
function produktion_bericht_daten(int $pa_id): ?array {
    $pa = one("SELECT pa.*, k.firma AS kunde_firma, p.name AS produkt_name, a.nummer AS auftrag_nr,
                      a.produkt_bezeichnung AS auftrag_produkt_bez, a.produkt_form AS auftrag_produkt_form,
                      rz.name AS rezeptur_name, rz.darreichungsform AS rezeptur_form
               FROM produktionsauftrag pa
               LEFT JOIN kunden k ON k.id=pa.kunde_id LEFT JOIN produkt p ON p.id=pa.produkt_id
               LEFT JOIN rezeptur rz ON rz.id=pa.rezeptur_id
               LEFT JOIN auftrag a ON a.id=pa.auftrag_id WHERE pa.id=?", [$pa_id]);
    if (!$pa) return null;

    $istBulk = pa_ist_bulk($pa);
    $form    = (string)($pa['rezeptur_form'] ?: $pa['auftrag_produkt_form'] ?: '');
    $wort    = in_array($form, ['kapsel','softgel'], true) ? 'Kapseln' : ($form === 'tablette' ? 'Tabletten' : ($form === 'stick' ? 'Sticks' : 'Stück'));
    $formLabel = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig','granulat'=>'Granulat'][$form] ?? ($form ?: '–');

    $einh   = produktion_stueck_je_packung($pa);
    $pack   = (int)$pa['menge'];
    $gesamt = $einh > 0 ? $pack * $einh : 0;

    $rezId = (int)($pa['rezeptur_id'] ?? 0);
    if (!$rezId && !empty($pa['produkt_id'])) $rezId = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]);
    $zutaten = $rezId ? all("SELECT z.menge_mg, COALESCE(NULLIF(z.bezeichnung,''), i.name) AS name
                             FROM rezeptur_zutat z LEFT JOIN item i ON i.id=z.item_id
                             WHERE z.rezeptur_id=? ORDER BY z.sort, z.id", [$rezId]) : [];

    $schritte  = all("SELECT * FROM produktion_schritt WHERE pa_id=? ORDER BY sort, id", [$pa_id]);
    // Entnommene Materialien inkl. Lieferant (intern) + Datum der Entnahme.
    $verbrauch = all("SELECT v.*, c.charge_nr, c.mhd AS charge_mhd, i.name AS item_name, l.firma AS lieferant
                      FROM produktion_verbrauch v
                      LEFT JOIN charge c ON c.id=v.charge_id LEFT JOIN item i ON i.id=v.item_id
                      LEFT JOIN lieferanten l ON l.id=c.lieferant_id
                      WHERE v.pa_id=? ORDER BY v.id", [$pa_id]);
    // Dem Auftrag zugeordnete Chargen (reserviert/eingegangen) – Charge-Vollstaendigkeit.
    $zugeChargen = $pa['auftrag_id'] ? all(
        "SELECT c.charge_nr, c.mhd, c.status, i.name AS item_name, i.kategorie, l.firma AS lieferant
         FROM charge c LEFT JOIN item i ON i.id=c.item_id LEFT JOIN lieferanten l ON l.id=c.lieferant_id
         WHERE c.auftrag_id=? ORDER BY i.kategorie, c.id", [(int)$pa['auftrag_id']]) : [];
    $fwChargen = all("SELECT c.charge_nr, c.menge, c.menge_verfuegbar, c.mhd, c.status, i.artikelnummer, i.name
                      FROM charge c JOIN item i ON i.id=c.item_id WHERE c.pa_id=? ORDER BY c.id", [$pa_id]);
    $groesse = !empty($pa['produkt_id']) ? produktion_groesse_label((int)$pa['produkt_id']) : '';

    $abg = null; foreach ($schritte as $s) if ((int)$s['erledigt'] === 1 && $s['erledigt_at'] && (!$abg || $s['erledigt_at'] > $abg)) $abg = $s['erledigt_at'];
    $done = count(array_filter($schritte, fn($s) => (int)$s['erledigt'] === 1));

    return [
        'pa'=>$pa, 'istBulk'=>$istBulk, 'form'=>$form, 'wort'=>$wort, 'formLabel'=>$formLabel,
        'einh'=>$einh, 'pack'=>$pack, 'gesamt'=>$gesamt, 'zutaten'=>$zutaten, 'schritte'=>$schritte,
        'verbrauch'=>$verbrauch, 'zugeChargen'=>$zugeChargen, 'fwChargen'=>$fwChargen, 'groesse'=>$groesse,
        'abg'=>$abg, 'done'=>$done, 'fertig'=>($pa['status']==='erledigt'),
    ];
}
// Kompletter Einkaufsbedarf eines Auftrags (Stückliste × Menge vs. freier Bestand).
// Rückgabe je Komponente: ['rolle','item_id','name','benoetigt','verfuegbar','fehlt','einheit']
function auftrag_bedarf(int $pa_id): array {
    $pa = pa_row_cached($pa_id);
    if (!$pa) return [];
    $aid = (int)$pa['auftrag_id'];
    $menge = (int)$pa['menge'];
    // Bulk-Produktion (nur Kapseln, ohne Verpackung): Menge = Stück, nur Rohstoffe + Leerkapseln, keine Verpackung.
    if (pa_ist_bulk($pa)) {
        $rows = [];
        foreach (produktion_materialbedarf($pa_id) as $m)
            $rows[] = ['rolle'=>'Rohstoff','item_id'=>$m['item_id'],'name'=>$m['name'],'benoetigt'=>$m['benoetigt'],'verfuegbar'=>$m['verfuegbar'],'fehlt'=>$m['fehlt'],'einheit'=>$m['einheit']];
        $kapId = rezeptur_leerkapsel_id((int)$pa['rezeptur_id']);
        if ($kapId && $menge > 0) {
            $verfK = item_bestand($kapId, true);
            $rows[] = ['rolle'=>'Leerkapsel','item_id'=>$kapId,'name'=>item_name_cached($kapId),'benoetigt'=>$menge,'verfuegbar'=>$verfK,'fehlt'=>max(0.0,$menge-$verfK),'einheit'=>'Stück'];
        }
        foreach ($rows as &$rb) {
            $iid = (int)$rb['item_id'];
            if ($iid > 0) { $rb['verfuegbar'] = item_verfuegbar_fuer($iid, 0); $rb['reserviert_eigen'] = 0.0; $rb['fehlt'] = max(0.0, (float)$rb['benoetigt'] - (float)$rb['verfuegbar']); }
            else $rb['reserviert_eigen'] = 0.0;
        }
        unset($rb);
        return $rows;
    }
    $einh  = produktion_stueck_je_packung($pa);
    $einheiten = $menge * $einh;
    $rows = [];
    // Zukauf des Bulks (Kapseln/Tabletten/Pulver) – wenn so entschieden (Fremdproduktion) ODER schon zugekauft eingegangen.
    // Verpackung + Etiketten braucht es TROTZDEM (werden unten immer angehängt).
    $zukauf = produktion_ist_zukauf((int)$pa['auftrag_id']) || ($pa['produktionsart'] ?? 'eigen') === 'fremd';
    if ($zukauf) {
        $verf = auftrag_fertigware_cached((int)$pa['auftrag_id'])['frei'];
        $af = auftrag_row_cached($aid);
        $bi = produkt_bulk_info((int)$pa['produkt_id'], (string)($af['produkt_bezeichnung'] ?? ''), (string)($af['produkt_form'] ?? ''));
        // Bedarf = Einheiten (Menge × Einheiten/Packung). Ist keine „Einheiten pro Packung" gepflegt
        // (z. B. Pulver/Füllprodukte), fällt der Bedarf auf die Packungsmenge zurück – sonst käme 0 heraus
        // und ein Fremdauftrag OHNE zugekaufte Fertigware würde faelschlich als „produktionsbereit" gelten.
        $benoetigt = $einheiten > 0 ? $einheiten : (float)$menge;
        $rows[] = ['rolle'=>'Fertigware','item_id'=>0,'name'=>$bi['bezeichnung'],'benoetigt'=>$benoetigt,'verfuegbar'=>$verf,'fehlt'=>max(0.0,$benoetigt-$verf),'einheit'=>$bi['einheit']];
    } else {
        foreach (produktion_materialbedarf($pa_id) as $m)
            $rows[] = ['rolle'=>'Rohstoff','item_id'=>$m['item_id'],'name'=>$m['name'],'benoetigt'=>$m['benoetigt'],'verfuegbar'=>$m['verfuegbar'],'fehlt'=>$m['fehlt'],'einheit'=>$m['einheit']];
        $kapId = produkt_leerkapsel_id((int)$pa['produkt_id']);
        if ($kapId && $einheiten > 0) {
            $verfK = item_bestand($kapId, true);
            $rows[] = ['rolle'=>'Leerkapsel','item_id'=>$kapId,'name'=>item_name_cached($kapId),'benoetigt'=>$einheiten,'verfuegbar'=>$verfK,'fehlt'=>max(0.0,$einheiten-$verfK),'einheit'=>'Stück'];
        }
    }
    // Verpackungs-Stückliste (alle Slots) – je Packung 1 Stück. Der Behälter des AUFTRAGS hat Vorrang
    // (Admin kann je Auftrag ein anderes Glas setzen); das passende Etikett wird dann aus DEM Behälter
    // abgeleitet – anderes Glas => anderes Etikett => anderer Einkauf.
    $slots = produkt_row_cached((int)$pa['produkt_id']);
    $afRow = auftrag_row_cached($aid);
    $verpEff = !empty($afRow['verpackung_id']) ? (int)$afRow['verpackung_id'] : (int)($slots['verpackung_id'] ?? 0);
    $etikettEff = (int)($slots['etikett_id'] ?? 0);
    if (!empty($afRow['verpackung_id'])) {                 // Auftrag hat eigenen Behälter -> Etikett dazu ableiten
        $au = etikett_id_fuer_behaelter((int)$afRow['verpackung_id']); if ($au) $etikettEff = $au;
    } elseif (!$etikettEff && $verpEff) {                  // sonst: fehlt Etikett am Produkt -> aus Behälter ableiten
        $au = etikett_id_fuer_behaelter($verpEff); if ($au) $etikettEff = $au;
    }
    $effSlots = ['verpackung_id'=>$verpEff, 'verschluss_id'=>(int)($slots['verschluss_id'] ?? 0),
                 'etikett_id'=>$etikettEff, 'karton_id'=>(int)($slots['karton_id'] ?? 0), 'beipack_id'=>(int)($slots['beipack_id'] ?? 0)];
    foreach (['verpackung_id'=>'Verpackung','verschluss_id'=>'Deckel','etikett_id'=>'Etikett','karton_id'=>'Karton','beipack_id'=>'Beipackzettel'] as $f => $rolle) {
        if (!empty($effSlots[$f]) && $menge > 0) {
            $iid = (int)$effSlots[$f]; $verf = item_bestand($iid, true);
            $rows[] = ['rolle'=>$rolle,'item_id'=>$iid,'name'=>item_name_cached($iid),'benoetigt'=>$menge,'verfuegbar'=>$verf,'fehlt'=>max(0.0,$menge-$verf),'einheit'=>'Stück'];
        }
    }
    // Netto-Verfügbarkeit: freier Bestand abzüglich Reservierungen anderer Aufträge; eigene Reservierung ausweisen.
    foreach ($rows as &$r) {
        $iid = (int)$r['item_id'];
        if ($iid > 0) {
            $r['verfuegbar']       = item_verfuegbar_fuer($iid, $aid);
            $r['reserviert_eigen'] = item_reserviert_eigen($iid, $aid);
            $r['fehlt']            = max(0.0, (float)$r['benoetigt'] - (float)$r['verfuegbar']);
        } else {
            $r['reserviert_eigen'] = 0.0;
        }
    }
    unset($r);
    return $rows;
}

// --- Bedarf-Cache: auftrag_bedarf ist teuer; Ergebnis je Auftrag zwischenspeichern. ------------------
// Gültig, solange bedarf_version unverändert ist. Jede materialrelevante Änderung ruft bedarf_bump().
function bedarf_version(): int { return (int) meta_get('bedarf_version', 1); }
function bedarf_bump(): void { meta_set('bedarf_version', (string)(bedarf_version() + 1)); }

// auftrag_bedarf mit Cache. Sicherheits-TTL 900 s (falls mal ein bump fehlt, heilt es sich).
function auftrag_bedarf_cached(int $pa_id): array {
    $ver = bedarf_version();
    $row = one("SELECT version, daten, angelegt FROM bedarf_cache WHERE pa_id=?", [$pa_id]);
    if ($row && (int)$row['version'] === $ver && strtotime((string)$row['angelegt'] . ' UTC') > time() - 900) {
        $d = json_decode((string)$row['daten'], true);
        if (is_array($d)) return $d;
    }
    $d = auftrag_bedarf($pa_id);
    q("INSERT INTO bedarf_cache (pa_id,version,daten,angelegt) VALUES (?,?,?,UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE version=VALUES(version), daten=VALUES(daten), angelegt=VALUES(angelegt)",
      [$pa_id, $ver, json_encode($d)]);
    return $d;
}

// Teilproduktions-Rechner. Liefert ZWEI Mengen (jeweils in Packungen, schon Produziertes abgezogen):
//  - vor_etikett: produzieren/abfüllen BIS VOR dem Etikettieren – begrenzt durch den knappsten Baustein
//    OHNE Etikett (Kapseln/Bulk, Glas, Deckel, Karton, Beipack).
//  - komplett: komplett FERTIG inkl. Etikett – zusätzlich begrenzt durch den Etikettenbestand UND die
//    Kundenfreigabe (ohne Freigabe = 0 fertigstellbar).
// Beispiel: 1.400 Gläser + 500 Etiketten -> vor_etikett=1.400, komplett=500.
// Rückgabe: ['menge','gebucht','rest','vor_etikett','komplett','limit_vor','limit_komplett',
//            'braucht_etikett','etikett_frei','etikett_machbar','fertig_moeglich','rollen'=>[...]].
function produktion_teilmenge_machbar(int $pa_id): array {
    $menge   = (int) scalar("SELECT menge FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $gebucht = (int) round(produktion_gebucht($pa_id));
    $rest    = max(0, $menge - $gebucht);
    $machbarVor = null; $limitVor = ''; $etikettMachbar = null; $rollen = [];
    foreach (auftrag_bedarf_cached($pa_id) as $r) {
        $rolle = (string)$r['rolle'];
        $benoetigt = (float)$r['benoetigt']; $verf = max(0.0, (float)$r['verfuegbar']);
        if ($benoetigt <= 1e-9 || $menge <= 0) continue;
        $jePack = $benoetigt / $menge;          // Verbrauch dieses Bausteins je Packung
        if ($jePack <= 1e-9) continue;
        $m = (int) floor($verf / $jePack);      // so viele Packungen deckt der aktuelle Bestand
        $rollen[] = ['rolle'=>$rolle, 'name'=>(string)($r['name'] ?? ''), 'verfuegbar'=>$verf, 'je_packung'=>$jePack, 'machbar'=>$m];
        if ($rolle === 'Etikett') { $etikettMachbar = $m; continue; }   // Etikett getrennt rechnen
        if ($machbarVor === null || $m < $machbarVor) { $machbarVor = $m; $limitVor = $rolle . (!empty($r['name']) ? ' (' . $r['name'] . ')' : ''); }
    }
    $machbarVor = max(0, (int)($machbarVor ?? $rest));
    $vorEtikett = min($machbarVor, $rest);      // bis vor Etikettieren jetzt produzierbar
    $aid = (int) scalar("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $brauchtEt = $aid > 0 && auftrag_braucht_etikett($aid);
    $frei      = $brauchtEt && etikett_freigegeben($aid);
    // Komplett fertig: ohne Etikett = wie vor_etikett; mit Etikett = zusätzlich Etikettenbestand + Freigabe.
    if (!$brauchtEt)      { $komplett = $vorEtikett; $limitKomplett = $limitVor; }
    elseif (!$frei)       { $komplett = 0;           $limitKomplett = 'Etikett nicht freigegeben'; }
    else {
        $etM = max(0, (int)($etikettMachbar ?? 0));
        $komplett = min($vorEtikett, $etM);
        $limitKomplett = ($etM <= $vorEtikett) ? 'Etikett' : $limitVor;
    }
    $komplett = min($komplett, $rest);
    $fertigMoeglich = $rest > 0 && $komplett >= $rest;   // der ganze offene Rest kann komplett fertig werden
    return ['menge'=>$menge, 'gebucht'=>$gebucht, 'rest'=>$rest,
            'vor_etikett'=>$vorEtikett, 'komplett'=>$komplett,
            'limit_vor'=>$limitVor, 'limit_komplett'=>$limitKomplett,
            'braucht_etikett'=>$brauchtEt, 'etikett_frei'=>$frei, 'etikett_machbar'=>$etikettMachbar,
            'fertig_moeglich'=>$fertigMoeglich, 'rollen'=>$rollen];
}

// Kombinierte Bestellung für EINEN Lieferant. $itemPositionen = [['item_id','menge','auftrag_id'(0=Lager)], ...] + Bulk (produkt_ids).
// $bulkMenge (produkt_id => Wunschmenge) hebt die Bestellmenge eines Bulk-Produkts über den reinen
// Auftragsbedarf: Überschuss (Wunsch - zu_bestellen) wird als auftragsloser Puffer-Posten ergänzt.
function bestellung_erstellen(array $itemPositionen, array $bulkProduktIds, ?int $lieferant, ?string $datum, array $freiIds = [], array $bulkMenge = []): int {
    $itemPositionen = array_values(array_filter($itemPositionen, fn($p) => (int)($p['item_id'] ?? 0) > 0 && (float)($p['menge'] ?? 0) > 0));
    $bulkProduktIds = array_values(array_filter(array_map('intval', $bulkProduktIds)));
    $freiIds        = array_values(array_filter(array_map('intval', $freiIds)));
    if (!$itemPositionen && !$bulkProduktIds && !$freiIds) return 0;
    $status = $datum ? 'bestellt' : 'offen';
    q("INSERT INTO bestellung (nummer,lieferant_id,status,notiz,bestelldatum) VALUES (?,?,?,?,?)",
      [naechste_nummer('BE'), $lieferant ?: null, $status, 'Aus Einkaufsliste', $datum ?: null]);
    $bid = (int) insert_id();
    $nummer = (string) scalar("SELECT nummer FROM bestellung WHERE id=?", [$bid]);
    $wann = $datum ? ' (bestellt am ' . date('d.m.Y', strtotime($datum)) . ')' : '';
    $i = 0; $betroffen = [];
    if ($itemPositionen) {
        $ordersByItem = [];
        foreach (bedarf_aggregiert(true) as $a) if (empty($a['etikett'])) $ordersByItem[$a['item_id']] = array_values(array_filter(array_map(fn($o)=> (int)$o['auftrag_id'], array_filter($a['orders'], fn($o)=> $o['need'] > 1e-6))));
        foreach ($itemPositionen as $p) {
            $iid = (int)$p['item_id']; $menge = (float)$p['menge']; $paufid = (int)($p['auftrag_id'] ?? 0);
            $ek = (float) scalar("SELECT ek_preis FROM item WHERE id=?", [$iid]);
            $einh = (string) scalar("SELECT einheit FROM item WHERE id=?", [$iid]);
            q("INSERT INTO bestellung_position (bestellung_id,item_id,menge,ek_preis,einheit,auftrag_id,sort) VALUES (?,?,?,?,?,?,?)",
              [$bid, $iid, $menge, $ek, $einh, $paufid ?: null, $i++]);
            if ($paufid > 0) $betroffen[$paufid] = true;                    // auftragsspezifisch (z. B. Etikett)
            else foreach ($ordersByItem[$iid] ?? [] as $aid) $betroffen[$aid] = true;
        }
    }
    if ($bulkProduktIds) {
        $gruppen = array_values(array_filter(bedarf_bulk(true), fn($g) => in_array($g['produkt_id'], $bulkProduktIds, true) && $g['zu_bestellen'] > 1e-6));
        foreach ($gruppen as $g) {
            foreach ($g['orders'] as $o) {
                $offen = (float) scalar("SELECT COALESCE(SUM(bp.menge),0) FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id
                                         WHERE bp.item_id IS NULL AND bp.auftrag_id=? AND b.status<>'geliefert'", [(int)$o['auftrag_id']]);
                $noch = (float)$o['need'] - $offen;
                if ($noch <= 1e-6) continue;
                q("INSERT INTO bestellung_position (bestellung_id,item_id,bezeichnung,menge,ek_preis,einheit,auftrag_id,sort) VALUES (?,?,?,?,?,?,?,?)",
                  [$bid, null, 'Bulk: ' . $g['produkt'] . ' (' . $o['auftrag_nr'] . ')', $noch, 0, 'Stück', (int)$o['auftrag_id'], $i++]);
                $betroffen[(int)$o['auftrag_id']] = true;
            }
            // Vom Einkauf angehobene Menge: Überschuss über den Auftragsbedarf als Puffer (ohne Auftrag) ergänzen.
            $wunsch = (float)($bulkMenge[(int)$g['produkt_id']] ?? 0);
            $ueber  = $wunsch - (float)$g['zu_bestellen'];
            if ($ueber > 1e-6) {
                q("INSERT INTO bestellung_position (bestellung_id,item_id,bezeichnung,menge,ek_preis,einheit,auftrag_id,sort) VALUES (?,?,?,?,?,?,?,?)",
                  [$bid, null, 'Bulk: ' . $g['produkt'] . ' (Puffer/Lager)', $ueber, 0, 'Stück', null, $i++]);
            }
        }
    }
    if ($freiIds) {
        foreach ($freiIds as $fid) {
            $fb = one("SELECT * FROM freibedarf WHERE id=? AND status='offen'", [$fid]);
            if (!$fb) continue;
            q("INSERT INTO bestellung_position (bestellung_id,item_id,bezeichnung,menge,ek_preis,einheit,sort) VALUES (?,?,?,?,?,?,?)",
              [$bid, null, $fb['bezeichnung'], (float)$fb['menge'], 0, $fb['einheit'] ?: 'Stück', $i++]);
            q("UPDATE freibedarf SET status='bestellt', bestellung_id=? WHERE id=?", [$bid, $fid]);
        }
    }
    foreach (array_keys($betroffen) as $aid) if ($aid > 0)
        log_aktivitaet('auftrag', $aid, 'team', 'Material per Bestellung ' . $nummer . $wann . ' bestellt.', 'bestellung', 'bestellung', $bid);
    bedarf_bump();   // bestellt -> offener Bedarf aendert sich
    return $bid;
}
// Offener freier Einkaufsbedarf (ohne Produktionsbezug), inkl. Lieferant-Firma.
function freibedarf_offen(): array {
    return all("SELECT f.*, l.firma AS lieferant_firma FROM freibedarf f
                LEFT JOIN lieferanten l ON l.id=f.lieferant_id
                WHERE f.status='offen' ORDER BY f.angelegt DESC");
}

// Meldebestand-Nachbestellung: Lagerartikel, deren freier Bestand + offen Bestelltes unter den
// gepflegten Meldebestand (item.mindestbestand) gefallen ist -> Nachbestell-Vorschlag für die Einkaufsliste.
function meldebestand_bedarf(): array {
    $out = [];
    foreach (all("SELECT id, name, einheit, mindestbestand, haupt_lieferant_id FROM item
                  WHERE mindestbestand IS NOT NULL AND mindestbestand > 0 AND gesperrt=0 ORDER BY name") as $it) {
        $iid = (int)$it['id'];
        $bestand = item_bestand($iid, true);
        $offen = (float) scalar("SELECT COALESCE(SUM(bp.menge),0) FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id
                                 WHERE bp.item_id=? AND b.status<>'geliefert'", [$iid]);
        $soll = (float)$it['mindestbestand'];
        $verf = $bestand + $offen;
        if ($verf + 1e-6 >= $soll) continue;   // genug (inkl. offener Bestellungen)
        $out[] = ['item_id'=>$iid, 'name'=>(string)$it['name'], 'einheit'=>(string)($it['einheit'] ?: 'Stück'),
                  'mindest'=>$soll, 'stock'=>$bestand, 'bestellt'=>$offen,
                  'zu_bestellen'=>max(0.0, $soll - $verf), 'haupt_lieferant'=>(int)($it['haupt_lieferant_id'] ?? 0)];
    }
    return $out;
}

// ===== Lieferanten-Preisliste (gehört EINEM Lieferanten) + 4-Wochen-Aktualisierungsregel =====
function lieferant_preisliste_fuer(int $lieferant_id): array {
    return all("SELECT * FROM lieferant_preisliste WHERE lieferant_id=? ORDER BY rohstoff_name", [$lieferant_id]);
}
// Partner-Verknuepfung: stellt sicher, dass der Lieferant einen verknuepften kunden-Datensatz hat, und gibt
// dessen id zurueck. Vorhandene Verknuepfung gewinnt; sonst Kunde mit exakt gleicher Firma verknuepfen (keine
// Dublette); sonst neu anlegen (Stammdaten aus dem Lieferanten uebernommen). Damit kann der Lieferant wie ein
// Kunde bei uns bestellen und bekommt seine Marge ueber kunden.rabatt_marge (eine Quelle).
function lieferant_partner_verknuepfen(int $lieferant_id): int {
    $l = one("SELECT * FROM lieferanten WHERE id=?", [$lieferant_id]);
    if (!$l) return 0;
    $kid = (int)($l['kunde_id'] ?? 0);
    if ($kid > 0 && one("SELECT id FROM kunden WHERE id=?", [$kid])) return $kid;
    $vorhanden = one("SELECT id FROM kunden WHERE firma=? LIMIT 1", [(string)$l['firma']]);
    if ($vorhanden) {
        $kid = (int)$vorhanden['id'];
    } else {
        q("INSERT INTO kunden (kundennummer, firma, ansprechpartner, email, telefon, strasse, hausnummer, plz, ort, land, ust_id)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)",
          [naechste_nummer('K'), mb_substr((string)$l['firma'], 0, 190), ($l['ansprechpartner'] ?: null), ($l['email'] ?: null),
           ($l['telefon'] ?: null), ($l['strasse'] ?: null), ($l['hausnummer'] ?: null), ($l['plz'] ?: null), ($l['ort'] ?: null),
           ((string)($l['land'] ?? '') ?: 'DE'), ($l['ust_id'] ?: null)]);
        $kid = insert_id();
    }
    q("UPDATE lieferanten SET kunde_id=? WHERE id=?", [$kid, $lieferant_id]);
    return $kid;
}
// Preis je Lieferant für EIN Item (gültiger Staffelpreis zur Menge). Rückgabe: [lieferant_id => preis(float)].
// Für die Lieferanten-Auswahl im Einkauf (Lieferant mit Preis anzeigen, günstigste zuerst).
function item_lieferant_preise(int $item_id, float $menge = 0): array {
    if ($item_id <= 0 || !table_exists('lieferant_preis')) return [];
    $out = [];
    foreach (all("SELECT lieferant_id, menge_ab, preis FROM lieferant_preis WHERE item_id=? AND lieferant_id IS NOT NULL ORDER BY menge_ab ASC", [$item_id]) as $r) {
        $lid = (int)$r['lieferant_id']; $ma = (float)$r['menge_ab']; $pr = (float)$r['preis'];
        if ($menge <= 0 || $ma <= $menge + 1e-9) $out[$lid] = $pr;   // größter passender Staffelwert gewinnt (ORDER BY ASC)
        elseif (!isset($out[$lid])) $out[$lid] = $pr;                // alle Staffeln > Menge -> kleinste als Fallback
    }
    return $out;
}
// Zukauf-Preise je Lieferant für ein Fertigprodukt (produkt_lieferant_preis), passend zur Stückzahl.
// Rückgabe: [lieferant_id => preis_je_einheit]. Nur EUR. Gleiche Staffel-Logik wie item_lieferant_preise.
function produkt_lieferant_preise(int $produkt_id, float $stueck = 0): array {
    if ($produkt_id <= 0 || !table_exists('produkt_lieferant_preis')) return [];
    $out = [];
    foreach (all("SELECT lieferant_id, menge_ab, preis FROM produkt_lieferant_preis
                  WHERE produkt_id=? AND lieferant_id IS NOT NULL AND (waehrung IS NULL OR waehrung='EUR')
                  ORDER BY menge_ab ASC", [$produkt_id]) as $r) {
        $lid = (int)$r['lieferant_id']; $ma = (float)$r['menge_ab']; $pr = (float)$r['preis'];
        if ($stueck <= 0 || $ma <= $stueck + 1e-9) $out[$lid] = $pr;   // größter passender Staffelwert gewinnt
        elseif (!isset($out[$lid])) $out[$lid] = $pr;                  // alle Staffeln > Menge -> kleinste als Fallback
    }
    return $out;
}
// Fremdfertigungs-Preise je Lieferant für eine Rezeptur (rezeptur_lief_angebot), passend zur Stückzahl.
// Rückgabe [lieferant_id => preis_je_stück]. So tauchen die Fremdfertiger (z. B. Wellgreen, Rainwood) auch
// im Bestellvorgang (Bulk/Fertigprodukt) auf – ihre Preise hängen an der Rezeptur, nicht am Produkt.
function rezeptur_fremd_lieferant_preise(int $rezeptur_id, float $stueck = 0): array {
    if ($rezeptur_id <= 0 || !table_exists('rezeptur_lief_angebot')) return [];
    $out = [];
    foreach (all("SELECT lieferant_id, menge, preis FROM rezeptur_lief_angebot
                  WHERE rezeptur_id = ? AND lieferant_id IS NOT NULL AND preis IS NOT NULL AND preis > 0
                  ORDER BY menge ASC", [$rezeptur_id]) as $r) {
        $lid = (int)$r['lieferant_id']; $m = (float)$r['menge']; $p = (float)$r['preis'];
        if ($stueck <= 0 || $m <= $stueck + 1e-9) $out[$lid] = $p;   // größte passende Staffel gewinnt
        elseif (!isset($out[$lid])) $out[$lid] = $p;                 // alle Staffeln > Menge -> kleinste als Fallback
    }
    return $out;
}
// Fremdfertigungs-Preise je Lieferant für ein Produkt (über dessen Rezeptur).
function produkt_fremd_lieferant_preise(int $produkt_id, float $stueck = 0): array {
    $rid = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id = ?", [$produkt_id]);
    return $rid ? rezeptur_fremd_lieferant_preise($rid, $stueck) : [];
}
// Zukauf-Preis je Einheit für EINEN Lieferanten + Fertigprodukt bei gegebener Stückzahl, oder null.
// Erst der Zukaufpreis (produkt_lieferant_preis), sonst der Fremdfertigungspreis (rezeptur_lief_angebot).
function produkt_zukauf_preis(int $produkt_id, ?int $lieferant_id, float $stueck = 0): ?float {
    if ($produkt_id <= 0) return null;
    $preise = produkt_lieferant_preise($produkt_id, $stueck);
    if ($lieferant_id && isset($preise[(int)$lieferant_id])) return (float)$preise[(int)$lieferant_id];
    $fremd = produkt_fremd_lieferant_preise($produkt_id, $stueck);
    if ($lieferant_id && isset($fremd[(int)$lieferant_id])) return (float)$fremd[(int)$lieferant_id];
    return null;
}
// KI-Kurzinfo zu einem Rohstoff erzeugen – NUR auf Knopfdruck. Neutral, Health-Claims-konform, Deutsch.
// Speichert den Text in item.ki_info (+ ki_info_am) und gibt ['ok','text','fehler'] zurück.
function item_ki_info_erzeugen(int $item_id): array {
    require_once __DIR__ . '/ki.php';
    if (!ki_bereit()) return ['ok'=>false, 'fehler'=>'Die KI ist nicht eingerichtet (ANTHROPIC_API_KEY in secrets.php).'];
    $it = one("SELECT id, name, name_en, name_lat, cas, kategorie, form FROM item WHERE id=?", [$item_id]);
    if (!$it) return ['ok'=>false, 'fehler'=>'Rohstoff nicht gefunden.'];
    // Wirkstoffe als Kontext (mit Gehalt), falls vorhanden.
    $wirk = [];
    foreach (all("SELECT n.name, iw.gehalt_wert, iw.gehalt_prozent FROM item_wirkstoff iw
                  JOIN naehrstoff n ON n.id=iw.naehrstoff_id WHERE iw.item_id=? ORDER BY iw.sort", [$item_id]) as $w) {
        $g = $w['gehalt_wert'] !== null ? $w['gehalt_wert'] : $w['gehalt_prozent'];
        $wirk[] = trim((string)$w['name'] . ($g !== null && $g !== '' ? ' (' . rtrim(rtrim(number_format((float)$g, 2, '.', ''), '0'), '.') . ')' : ''));
    }
    $ctx = 'Rohstoff: ' . (string)$it['name'];
    if ($it['name_lat']) $ctx .= ' (lat. ' . (string)$it['name_lat'] . ')';
    if ($it['cas'])      $ctx .= ', CAS ' . (string)$it['cas'];
    if ($it['form'])     $ctx .= ', Form ' . (string)$it['form'];
    if ($wirk)           $ctx .= '. Wirkstoffe: ' . implode(', ', $wirk);
    $system = 'Du bist Fachredakteur für Rohstoffe in Nahrungsergänzungsmitteln. Schreibe eine sachliche Kurzinfo auf DEUTSCH, '
            . '3 bis 5 Sätze: Was ist der Stoff, woraus/woher stammt er, und wofür wird er üblicherweise in Nahrungsergänzung eingesetzt. '
            . 'Allgemeinverständlich, neutral, werbefrei. WICHTIG: KEINE gesundheitsbezogenen Wirkversprechen und KEINE krankheitsbezogenen Aussagen '
            . '(keine Heilung, Linderung oder Vorbeugung), konform zur EU-Health-Claims-Verordnung. Keine Dosierungsempfehlung. '
            . 'Nur Fließtext, keine Überschrift, keine Aufzählung.';
    $r = ki_frage($ctx, ['system'=>$system, 'aufwand'=>'low', 'max_tokens'=>700, 'timeout'=>60, 'budget'=>90, 'zweck'=>'rohstoff-ki-info']);
    if (!($r['ok'] ?? false)) return ['ok'=>false, 'fehler'=>$r['fehler'] ?? 'Die KI konnte nicht antworten.'];
    $text = trim((string)($r['text'] ?? ''));
    if ($text === '') return ['ok'=>false, 'fehler'=>'Die KI hat keinen Text geliefert.'];
    q("UPDATE item SET ki_info=?, ki_info_am=? WHERE id=?", [$text, gmdate('Y-m-d H:i:s'), $item_id]);
    if (function_exists('log_aktivitaet')) log_aktivitaet('item', $item_id, 'team', 'KI-Kurzinfo erzeugt.', 'ki');
    return ['ok'=>true, 'text'=>$text];
}

// Neuester Preis-Stand des Lieferanten (Datum) oder null.
function lieferant_preise_stand(int $lieferant_id): ?string {
    $s = scalar("SELECT MAX(stand) FROM lieferant_preisliste WHERE lieferant_id=? AND stand IS NOT NULL", [$lieferant_id]);
    return $s ? (string)$s : null;
}
// Aktualisierungs-Intervall des Lieferanten (Standard 28 Tage = 4 Wochen).
function lieferant_preis_intervall(int $lieferant_id): int {
    $t = (int) scalar("SELECT preis_intervall_tage FROM lieferanten WHERE id=?", [$lieferant_id]);
    return $t > 0 ? $t : 28;
}
// Tage seit dem letzten Preis-Update (null = keine Preise / kein Stand).
function lieferant_preise_alter_tage(int $lieferant_id): ?int {
    $stand = lieferant_preise_stand($lieferant_id);
    if (!$stand) return null;
    return (int) floor((time() - strtotime($stand)) / 86400);
}
// Sind die Preise überfällig (älter als das Intervall)? Preise ohne Stand gelten als überfällig.
function lieferant_preise_veraltet(int $lieferant_id): bool {
    if ((int) scalar("SELECT COUNT(*) FROM lieferant_preisliste WHERE lieferant_id=?", [$lieferant_id]) === 0) return false;
    $alter = lieferant_preise_alter_tage($lieferant_id);
    if ($alter === null) return true;   // Preise vorhanden, aber ohne Stand-Datum
    return $alter > lieferant_preis_intervall($lieferant_id);
}
// Alle Preise des Lieferanten als „heute aktuell" bestätigen (Stand = heute) – erfüllt die 4-Wochen-Regel in einem Klick.
function lieferant_preise_bestaetigen(int $lieferant_id): int {
    q("UPDATE lieferant_preisliste SET stand=CURDATE() WHERE lieferant_id=?", [$lieferant_id]);
    return (int) scalar("SELECT COUNT(*) FROM lieferant_preisliste WHERE lieferant_id=?", [$lieferant_id]);
}
// Offener Fehlbedarf (nur bestellbare Positionen mit item_id, abzüglich schon offen bestellter Menge für diesen Auftrag).
function auftrag_fehlbedarf(int $pa_id): array {
    $pa = pa_row_cached($pa_id);
    $aid = $pa ? (int)$pa['auftrag_id'] : 0;
    $out = [];
    foreach (auftrag_bedarf_cached($pa_id) as $r) {
        if ($r['fehlt'] <= 1e-6 || (int)$r['item_id'] <= 0) continue;
        // schon offen bestellt: für diesen Auftrag ODER als Sammelbestellung (Lager, auftrag_id NULL)
        $offen = (float) scalar("SELECT COALESCE(SUM(bp.menge),0) FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id
                                 WHERE bp.item_id=? AND b.status<>'geliefert' AND (bp.auftrag_id=? OR bp.auftrag_id IS NULL)", [(int)$r['item_id'], $aid]);
        $noch = (float)$r['fehlt'] - $offen;
        if ($noch <= 1e-6) continue;
        $r['bestellt'] = $offen; $r['zu_bestellen'] = $noch;
        $out[] = $r;
    }
    return $out;
}
// --- Etikett-Design je Auftrag (kundenspezifisch) ---
function etikett_datei(int $auftrag_id): ?array {
    if ($auftrag_id <= 0) return null;
    return one("SELECT * FROM dokument WHERE objekt_typ='auftrag' AND objekt_id=? AND typ='etikett' ORDER BY id DESC LIMIT 1", [$auftrag_id]);
}
function etikett_vorhanden(int $auftrag_id): bool { return etikett_datei($auftrag_id) !== null; }
// Datei-Feldname $feld (z. B. 'etikett'). Gibt true bei erfolgreichem Upload.
function etikett_upload(int $auftrag_id, string $feld = 'etikett'): bool {
    if ($auftrag_id <= 0 || empty($_FILES[$feld]['name']) || ($_FILES[$feld]['error'] ?? 1) !== UPLOAD_ERR_OK) return false;
    if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
    $orig = $_FILES[$feld]['name'];
    $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
    $fn   = 'auftrag_' . $auftrag_id . '_etikett_' . bin2hex(random_bytes(6)) . ($ext ? '.' . $ext : '');
    if (!move_uploaded_file($_FILES[$feld]['tmp_name'], BX_UPLOADS . '/' . $fn)) return false;
    q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig) VALUES ('auftrag',?,?,?,?,?)",
      [$auftrag_id, 'etikett', 'Etikett-Design', $fn, $orig]);
    // Neues Design → bisherige Freigabe gilt nicht mehr, das neue muss erneut freigegeben werden.
    etikett_freigabe_zuruecksetzen($auftrag_id);
    return true;
}
function etikett_del(int $auftrag_id): void {
    $d = etikett_datei($auftrag_id);
    if ($d) { @unlink(BX_UPLOADS . '/' . basename((string)$d['datei'])); q("DELETE FROM dokument WHERE id=?", [(int)$d['id']]); }
    etikett_freigabe_zuruecksetzen($auftrag_id);
}

// --- Etikettenfreigabe (Kunde) -------------------------------------------------
// Braucht dieser Auftrag überhaupt ein Etikett? (nur Produkte mit Etikett-Slot). Ohne Produkt/Slot = nein.
function auftrag_braucht_etikett(int $auftrag_id): bool {
    $r = one("SELECT a.verpackung_id AS a_vp, p.etikett_id, p.verpackung_id AS p_vp
              FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$auftrag_id]);
    if (!$r) return false;
    if ((int)($r['etikett_id'] ?? 0) > 0) return true;           // expliziter Etikett-Slot am Produkt
    // Etikett ist Standard: jedes VERPACKTE Produkt (Glas/Dose) bekommt ein Etikett. Behälter des Auftrags
    // hat Vorrang (Admin kann je Auftrag ein anderes Glas setzen), sonst der des Produkts. Das Etikett (inkl.
    // Maß) leitet sich aus dem Behälter ab (etikett_id_fuer_behaelter). Nur reine Bulkware ohne Behälter braucht keins.
    $vp = (int)($r['a_vp'] ?? 0) ?: (int)($r['p_vp'] ?? 0);
    return $vp > 0;
}
// Hat der Kunde das Etikett für DIESEN Auftrag freigegeben?
function etikett_freigegeben(int $auftrag_id): bool {
    return (int) scalar("SELECT etikett_freigegeben FROM auftrag WHERE id=?", [$auftrag_id]) === 1;
}
// Das für die Freigabe relevante Etikett-Design: eigenes des Auftrags – sonst (Nachbestellung) das
// zuletzt verwendete Etikett eines FRÜHEREN Auftrags desselben Kunden+Produkts. Rückgabe dokument-Zeile
// plus 'alt' (bool: stammt aus einem früheren Auftrag) und 'quell_auftrag_id'.
function etikett_quelle(int $auftrag_id): ?array {
    $own = etikett_datei($auftrag_id);
    if ($own) { $own['alt'] = false; $own['quell_auftrag_id'] = $auftrag_id; return $own; }
    $a = one("SELECT kunde_id, produkt_id FROM auftrag WHERE id=?", [$auftrag_id]);
    if (!$a || empty($a['produkt_id'])) return null;
    $alt = one("SELECT d.* FROM dokument d JOIN auftrag a2 ON a2.id=d.objekt_id
                WHERE d.objekt_typ='auftrag' AND d.typ='etikett' AND a2.produkt_id=? AND a2.kunde_id=? AND a2.id<>?
                ORDER BY d.id DESC LIMIT 1", [(int)$a['produkt_id'], (int)$a['kunde_id'], $auftrag_id]);
    if (!$alt) return null;
    $alt['alt'] = true; $alt['quell_auftrag_id'] = (int)$alt['objekt_id'];
    return $alt;
}
// Freigabe setzen (durch den Kunden bzw. vom Team manuell bestätigt). Übernimmt bei einer
// Nachbestellung ohne eigenes Design das alte Etikett auf diesen Auftrag (als dessen freigegebenes
// Design). Rückgabe ['ok'=>bool, 'fehler'=>?].
function etikett_freigabe_setzen(int $auftrag_id, string $name, string $akteur = 'kunde'): array {
    $name = trim($name);
    if ($name === '') return ['ok' => false, 'fehler' => 'Bitte einen Namen für die Freigabe angeben.'];
    if (!auftrag_braucht_etikett($auftrag_id)) return ['ok' => false, 'fehler' => 'Für diesen Auftrag ist kein Etikett nötig.'];
    if (!etikett_datei($auftrag_id)) {
        $alt = etikett_quelle($auftrag_id);
        if (!$alt) return ['ok' => false, 'fehler' => 'Kein Etikett-Design vorhanden – bitte zuerst hochladen.'];
        // Altes Etikett als Design DIESES Auftrags übernehmen (Datei kopieren, neue dokument-Zeile).
        $src = BX_UPLOADS . '/' . basename((string)$alt['datei']);
        $ext = strtolower(pathinfo((string)$alt['datei'], PATHINFO_EXTENSION));
        $fn  = 'auftrag_' . $auftrag_id . '_etikett_' . bin2hex(random_bytes(6)) . ($ext ? '.' . $ext : '');
        if (is_file($src)) @copy($src, BX_UPLOADS . '/' . $fn);
        q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig) VALUES ('auftrag',?,'etikett',?,?,?)",
          [$auftrag_id, 'Etikett-Design (aus Vorbestellung übernommen)', $fn, (string)($alt['datei_orig'] ?: 'Etikett-Design')]);
    }
    q("UPDATE auftrag SET etikett_freigegeben=1, etikett_freigabe_am=UTC_TIMESTAMP(), etikett_freigabe_von=? WHERE id=?",
      [mb_substr($name, 0, 190), $auftrag_id]);
    // Kundenetikett-Artikel binden bzw. bei neuem Design eine neue Version anlegen (v2, v3 …).
    kundenetikett_nach_freigabe($auftrag_id);
    $kid = (int) scalar("SELECT kunde_id FROM auftrag WHERE id=?", [$auftrag_id]);
    log_aktivitaet('kunde', $kid, $akteur, 'Etikett zur Produktion freigegeben durch ' . $name . ($akteur === 'team' ? ' (vom Team bestätigt)' : '') . '.', 'auftrag', 'auftrag', $auftrag_id);
    return ['ok' => true];
}
// Freigabe zurücknehmen (z. B. wenn ein neues Design hochgeladen wird → muss erneut freigegeben werden).
function etikett_freigabe_zuruecksetzen(int $auftrag_id): void {
    q("UPDATE auftrag SET etikett_freigegeben=0, etikett_freigabe_am=NULL, etikett_freigabe_von=NULL WHERE id=?", [$auftrag_id]);
}
// Haftungsausschluss-Text, den der Kunde bei der Etikett-Freigabe bestätigen muss (editierbar in den Einstellungen).
function etikett_haftung_text(): string {
    $def = 'Ich bestätige, dass bulkify das Etikett genau in der freigegebenen/hochgeladenen Fassung in Druck und Bestellung gibt. '
         . 'Ein Korrektorat (Gegenlesen auf Rechtschreibung, Grammatik, inhaltliche oder rechtliche Richtigkeit) ist nicht Teil des '
         . 'Standardprozesses. Für Fehler jeglicher Art – insbesondere Rechtschreib-, Zahlen- oder Deklarationsfehler – übernimmt '
         . 'bulkify keine Haftung. Die Verantwortung für den Etiketteninhalt liegt beim Auftraggeber.';
    $t = trim((string) meta_get('etikett_haftung_text', ''));
    return $t !== '' ? $t : $def;
}

// ===== Etikett-Bestand (physisch vs. bezahlt) =====================================================
// Etiketten sind an ein Produkt gebunden. Der Lager-/Etikett-Artikel ist produkt.etikett_id, sonst aus
// dem Behälter abgeleitet. Rückgabe 0, wenn das Produkt kein Etikett hat.
function etikett_item_fuer_produkt(int $produkt_id): int {
    if ($produkt_id <= 0) return 0;
    $eid = (int) scalar("SELECT etikett_id FROM produkt WHERE id=?", [$produkt_id]);
    if ($eid > 0) return $eid;
    $vid = (int) scalar("SELECT verpackung_id FROM produkt WHERE id=?", [$produkt_id]);
    return $vid ? (int) (etikett_id_fuer_behaelter($vid) ?? 0) : 0;
}
// Für einen AUFTRAG: die gebundene Kundenetikett-Version (auftrag.etikett_item_id), sonst der aktuelle
// Produkt-Etikett-Artikel. So werden Etiketten kundenrein und je Design-Version verbucht/rückverfolgt.
function etikett_item_fuer_auftrag(int $auftrag_id): int {
    if ($auftrag_id <= 0) return 0;
    $a = one("SELECT etikett_item_id, produkt_id FROM auftrag WHERE id=?", [$auftrag_id]);
    if (!$a) return 0;
    if ((int)($a['etikett_item_id'] ?? 0) > 0) return (int)$a['etikett_item_id'];
    return etikett_item_fuer_produkt((int)($a['produkt_id'] ?? 0));
}

// ===== Kundenetikett-Artikel (pro Produkt, versioniert) ==========================================
// Jedes verpackte Produkt bekommt seinen EIGENEN Etikett-Lagerartikel (item, rolle 'etikett', produkt_id),
// in den das Lager die GEDRUCKTEN Kundenetiketten einbucht – kundenrein statt im generischen Glas-Topf.
// produkt.etikett_id zeigt immer auf die AKTUELLE Version. Neue Version (v2, v3 …) entsteht, wenn der Kunde
// ein NEUES Design hochlädt UND freigibt (Datei-Signatur weicht ab). Der generische Glas-Etikett bleibt nur
// noch Maß-Vorlage. Alte Versionen werden gesperrt (Restbestand bleibt historisch sichtbar), nie gelöscht.

// Signatur des Design-Files (zur Bump-Erkennung): sha1 des Inhalts, Fallback Dateiname.
function kundenetikett_datei_sig(string $datei): string {
    $p = BX_UPLOADS . '/' . basename($datei);
    if (is_file($p)) { $h = @sha1_file($p); if ($h) return $h; }
    return 'n:' . basename($datei);
}
// Maß-/Format-Vorlage aus dem generischen Glas-Etikett des Produkts.
function kundenetikett_vorlage(int $produkt_id): array {
    $vp = (int) scalar("SELECT COALESCE(verpackung_id,0) FROM produkt WHERE id=?", [$produkt_id]);
    if ($vp <= 0) return [];
    $tid = (int) (etikett_id_fuer_behaelter($vp) ?? 0);
    if ($tid <= 0) return ['_vorlage' => null];
    $t = one("SELECT breite_mm,hoehe_mm,etikett_format,etikett_final,etikett_druck FROM item WHERE id=?", [$tid]) ?: [];
    $t['_vorlage'] = $tid;
    return $t;
}
// Aktuellen (nicht gesperrten) Kundenetikett-Artikel eines Produkts holen (0 = keiner).
function kundenetikett_aktuell(int $produkt_id): int {
    if ($produkt_id <= 0) return 0;
    $eid = (int) scalar("SELECT etikett_id FROM produkt WHERE id=?", [$produkt_id]);
    if ($eid > 0 && (int) scalar("SELECT 1 FROM item WHERE id=? AND verpackung_rolle='etikett' AND produkt_id=?", [$eid, $produkt_id]))
        return $eid;
    return (int) scalar("SELECT id FROM item WHERE verpackung_rolle='etikett' AND produkt_id=? AND COALESCE(gesperrt,0)=0
                         ORDER BY COALESCE(etikett_version,1) DESC, id DESC LIMIT 1", [$produkt_id]);
}
// Neue Etikett-Version als Lagerartikel anlegen (interne Hilfe). Kopiert Maße aus der Glas-Vorlage und setzt
// produkt.etikett_id auf den neuen Artikel. Sperrt NICHT die alte Version (macht der Aufrufer). Rückgabe: item-id.
function kundenetikett_version_anlegen(int $produkt_id, int $version, ?int $dokument_id, string $datei_sig): int {
    $p = one("SELECT p.name, p.kundenname, p.kunde_id, k.firma FROM produkt p LEFT JOIN kunden k ON k.id=p.kunde_id WHERE p.id=?", [$produkt_id]);
    if (!$p) return 0;
    $m = kundenetikett_vorlage($produkt_id);
    $pname = trim((string)($p['kundenname'] ?: $p['name'])) ?: ('Produkt ' . $produkt_id);
    $kname = trim((string)($p['firma'] ?? ''));
    $name  = 'Etikett · ' . $pname . ($kname !== '' ? ' · ' . $kname : '') . ' · v' . $version;
    q("INSERT INTO item (name,kategorie,form,verpackungsart,verpackung_rolle,produkt_id,einheit,preis_bezug,
            breite_mm,hoehe_mm,etikett_format,etikett_final,etikett_druck,
            etikett_version,etikett_dokument_id,etikett_datei_sig,etikett_vorlage_id,gesperrt)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)",
      [$name,'verpackung','pulver','etikett','etikett',$produkt_id,'Stk','Stück',
       ($m['breite_mm'] ?? null),($m['hoehe_mm'] ?? null),($m['etikett_format'] ?? null),($m['etikett_final'] ?? null),($m['etikett_druck'] ?? null),
       $version,$dokument_id,($datei_sig ?: null),($m['_vorlage'] ?? null)]);
    $nid = (int) insert_id();
    q("UPDATE produkt SET etikett_id=? WHERE id=?", [$nid, $produkt_id]);
    return $nid;
}
// Stellt sicher, dass das Produkt einen aktuellen Kundenetikett-Artikel hat (legt v1 an, wenn nötig – nur wenn
// das Produkt ein Etikett braucht = Behälter gesetzt). Rückgabe: item-id (0 = kein Etikett nötig). Idempotent.
// Hook: Auftragsbestätigung (auftrag_aus_angebot).
function kundenetikett_sicherstellen(int $produkt_id): int {
    if ($produkt_id <= 0) return 0;
    if ((int) scalar("SELECT COALESCE(verpackung_id,0) FROM produkt WHERE id=?", [$produkt_id]) <= 0) return 0;
    $cur = kundenetikett_aktuell($produkt_id);
    if ($cur > 0) {
        if ((int) scalar("SELECT COALESCE(etikett_id,0) FROM produkt WHERE id=?", [$produkt_id]) !== $cur)
            q("UPDATE produkt SET etikett_id=? WHERE id=?", [$cur, $produkt_id]);
        return $cur;
    }
    return kundenetikett_version_anlegen($produkt_id, 1, null, '');
}
// Nach einer Etikett-Freigabe aufrufen: bindet das freigegebene Design an die aktuelle Version – oder legt
// bei einem NEUEN Design (abweichende Signatur) eine neue Version an und sperrt die alte. Bindet den Auftrag
// an die gültige Version (auftrag.etikett_item_id). Hook: etikett_freigabe_setzen.
function kundenetikett_nach_freigabe(int $auftrag_id): void {
    $pid = (int) scalar("SELECT COALESCE(produkt_id,0) FROM auftrag WHERE id=?", [$auftrag_id]);
    if ($pid <= 0 || !auftrag_braucht_etikett($auftrag_id)) return;
    $dok = etikett_datei($auftrag_id);         // nach Freigabe gibt es IMMER eine eigene Datei (ggf. aus Vorbestellung kopiert)
    if (!$dok) return;
    $sig = kundenetikett_datei_sig((string)$dok['datei']);
    $cur = kundenetikett_sicherstellen($pid);
    if ($cur <= 0) return;
    $curSig = (string) scalar("SELECT COALESCE(etikett_datei_sig,'') FROM item WHERE id=?", [$cur]);
    if ($curSig === '') {                       // v1 hat noch kein Design -> dieses übernehmen (kein Bump)
        q("UPDATE item SET etikett_datei_sig=?, etikett_dokument_id=? WHERE id=?", [$sig, (int)$dok['id'], $cur]);
        $use = $cur;
    } elseif ($curSig === $sig) {               // gleiches Design (Nachbestellung/Re-Freigabe) -> kein Bump
        $use = $cur;
    } else {                                    // NEUES Design freigegeben -> neue Version
        $ver = (int) scalar("SELECT COALESCE(etikett_version,1) FROM item WHERE id=?", [$cur]) + 1;
        q("UPDATE item SET gesperrt=1 WHERE id=?", [$cur]);
        $use = kundenetikett_version_anlegen($pid, $ver, (int)$dok['id'], $sig);
    }
    if ($use > 0) q("UPDATE auftrag SET etikett_item_id=? WHERE id=?", [$use, $auftrag_id]);
}
// Etikett-Bestand eines Produkts – mit strikter Trennung PHYSISCH (unser Lager, inkl. Puffer) vs.
// BEZAHLT (was der Kunde gekauft hat). Dem Kunden darf NIE mehr gezeigt werden als bezahlt.
//   physisch   = freier Lagerbestand des Etikett-Artikels (inkl. der Reserve, die wir oft zusätzlich ordern)
//   bezahlt    = Summe der vom Kunden bestellten Etiketten (1 je Packung), nicht stornierte Aufträge
//   verbraucht = davon in abgeschlossenen Aufträgen produziert (Etiketten verbraucht)
//   kunde_rest = bezahlt − verbraucht (dem Kunden noch gehörende Etiketten)
//   kunde_sichtbar = min(physisch, kunde_rest)  -> gedeckelt, nie mehr als bezahlt
//   puffer     = physisch − kunde_rest (unsere zusätzliche Menge; dem Kunden NICHT zeigen)
// $kunde_id=null aggregiert über alle Kunden (interne Produktsicht). Für die Kundensicht die kunde_id setzen.
function etikett_bestand_info(int $produkt_id, ?int $kunde_id = null): array {
    $eid = etikett_item_fuer_produkt($produkt_id);
    $physisch = $eid ? (float) item_bestand($eid, true) : 0.0;
    $wo = "produkt_id=? AND status<>'storniert'"; $p = [$produkt_id];
    if ($kunde_id) { $wo .= " AND kunde_id=?"; $p[] = $kunde_id; }
    $bezahlt    = (int) scalar("SELECT COALESCE(SUM(menge),0) FROM auftrag WHERE $wo", $p);
    $verbraucht = (int) scalar("SELECT COALESCE(SUM(menge),0) FROM auftrag WHERE $wo AND status='erledigt'", $p);
    $kundeRest  = max(0, $bezahlt - $verbraucht);
    return [
        'etikett_item_id' => $eid,
        'hat_etikett'     => $eid > 0,
        'physisch'        => $physisch,
        'bezahlt'         => $bezahlt,
        'verbraucht'      => $verbraucht,
        'kunde_rest'      => $kundeRest,
        'kunde_sichtbar'  => (int) min($physisch, $kundeRest),   // NIE mehr als bezahlt
        'puffer'          => max(0.0, $physisch - $kundeRest),    // interner Überschuss (nicht an Kunden zeigen)
    ];
}
// Liest die Seitenmaße einer Druckdatei. PDF → aus /MediaBox in mm ('210 × 297 mm'); Bild → Pixel. Sonst null.
function pdf_masse(string $pfad): ?array {
    if (!is_file($pfad)) return null;
    if (strtolower(pathinfo($pfad, PATHINFO_EXTENSION)) === 'pdf') {
        $data = @file_get_contents($pfad, false, null, 0, 800000);
        if ($data === false || !preg_match('/\/MediaBox\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s*\]/', $data, $m)) return null;
        $w = abs((float)$m[3] - (float)$m[1]) * 25.4 / 72.0;   // Punkte (1/72 Zoll) → mm
        $h = abs((float)$m[4] - (float)$m[2]) * 25.4 / 72.0;
        if ($w <= 0 || $h <= 0) return null;
        $r = fn($x) => rtrim(rtrim(number_format($x, 1, ',', ''), '0'), ',');
        return ['w' => $w, 'h' => $h, 'einheit' => 'mm', 'label' => $r($w) . ' × ' . $r($h) . ' mm'];
    }
    $g = @getimagesize($pfad);
    if ($g && !empty($g[0]) && !empty($g[1])) return ['w' => (float)$g[0], 'h' => (float)$g[1], 'einheit' => 'px', 'label' => $g[0] . ' × ' . $g[1] . ' px'];
    return null;
}
// Etikett-Info je Auftrag: ['dok'=>Datei-Datensatz|null, 'masse'=>pdf_masse()|null, 'produkt'=>Produktname|null]
function etikett_info(int $auftrag_id): array {
    $d = etikett_datei($auftrag_id);
    $masse = ($d && !empty($d['datei'])) ? pdf_masse(BX_UPLOADS . '/' . basename((string)$d['datei'])) : null;
    $produkt = scalar("SELECT COALESCE(NULLIF(p.kundenname,''), p.name)
                       FROM produktionsauftrag pa JOIN produkt p ON p.id=pa.produkt_id
                       WHERE pa.auftrag_id=? ORDER BY pa.id DESC LIMIT 1", [$auftrag_id]);
    return ['dok' => $d, 'masse' => $masse, 'produkt' => $produkt ?: null];
}

// Bedarfs-Typ (für Reiter/Sortierung): etikett | verpackung | rohstoff | fertig
function bedarf_typ(string $rolle): string {
    return match ($rolle) {
        'Etikett' => 'etikett',
        'Verpackung', 'Deckel', 'Karton', 'Beipackzettel' => 'verpackung',
        'Fertigware' => 'fertig',
        default => 'rohstoff',   // Rohstoff, Leerkapsel
    };
}
function bedarf_typ_label(string $typ): string {
    return ['etikett'=>'Etiketten','verpackung'=>'Verpackung','rohstoff'=>'Rohstoffe','fertig'=>'Fertige Produkte'][$typ] ?? $typ;
}

// Aggregierter Bedarf über ALLE offenen Aufträge, je Artikel summiert (für Sammel-/Bulk-Bestellungen).
// Rückgabe je Artikel: ['item_id','name','rolle','einheit','need'(Σ),'stock','bestellt','zu_bestellen','orders'=>[{pa_id,auftrag_id,auftrag_nr,need}]]
function bedarf_aggregiert(bool $nur_gemeldet = false): array {
    $agg = [];
    // Alle offenen Aufträge – auch Fremdproduktion braucht Verpackung/Etiketten. Der Bulk-Zukauf (item_id 0) fällt unten raus.
    // Nur FESTGELEGTE Aufträge (Eigen/Fremd entschieden) – sonst steht die Stückliste noch nicht fest (Rohstoffe vs. Bulk).
    $wo = "pa.status IN ('offen','laufend') AND pa.auftrag_id IS NOT NULL AND pa.art_festgelegt_am IS NOT NULL"
        . ($nur_gemeldet ? " AND pa.bedarf_gemeldet IS NOT NULL" : "");
    foreach (all("SELECT pa.id, pa.auftrag_id, a.nummer AS auftrag_nr FROM produktionsauftrag pa
                  LEFT JOIN auftrag a ON a.id=pa.auftrag_id WHERE $wo") as $pa) {
        foreach (auftrag_bedarf_cached((int)$pa['id']) as $r) {
            $iid = (int)$r['item_id']; if ($iid <= 0) continue;
            // Etiketten NICHT gruppieren (jedes braucht das Kundendesign) -> Schlüssel je (Item + Auftrag).
            $etikett = $r['rolle'] === 'Etikett';
            $key = $etikett ? ('e' . $iid . '_' . (int)$pa['auftrag_id']) : ('i' . $iid);
            if (!isset($agg[$key])) $agg[$key] = ['item_id'=>$iid,'name'=>$r['name'],'rolle'=>$r['rolle'],'typ'=>bedarf_typ($r['rolle']),'einheit'=>$r['einheit'],'need'=>0.0,'orders'=>[],'etikett'=>$etikett,'auftrag_id'=>$etikett?(int)$pa['auftrag_id']:0];
            $agg[$key]['need'] += (float)$r['benoetigt'];
            $agg[$key]['orders'][] = ['pa_id'=>(int)$pa['id'],'auftrag_id'=>(int)$pa['auftrag_id'],'auftrag_nr'=>$pa['auftrag_nr'],'need'=>(float)$r['benoetigt']];
        }
    }
    foreach ($agg as &$a) {
        $aid = (int)($a['auftrag_id'] ?? 0);
        if (!empty($a['etikett'])) {
            // Kundenspezifisch: kein generischer Lagerbestand, Bestell-Sperre bis Design da ist.
            $a['stock'] = 0.0;
            $a['bestellt'] = (float) scalar("SELECT COALESCE(SUM(bp.menge),0) FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id WHERE bp.item_id=? AND bp.auftrag_id=? AND b.status<>'geliefert'", [$a['item_id'], $aid]);
            $a['zu_bestellen'] = max(0.0, $a['need'] - $a['bestellt']);
            // Etiketten erst bestellbar, wenn der Kunde das Etikett freigegeben hat (nicht nur hochgeladen).
            $a['etikett_ok'] = etikett_freigegeben($aid);
        } else {
            $a['stock'] = item_bestand($a['item_id'], true);
            $a['bestellt'] = (float) scalar("SELECT COALESCE(SUM(bp.menge),0) FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id WHERE bp.item_id=? AND b.status<>'geliefert'", [$a['item_id']]);
            $a['zu_bestellen'] = max(0.0, $a['need'] - $a['stock'] - $a['bestellt']);
            $a['etikett_ok'] = true;
        }
        $a['haupt_lieferant'] = (int) scalar("SELECT haupt_lieferant_id FROM item WHERE id=?", [$a['item_id']]);
    }
    unset($a);
    $out = array_values($agg);
    usort($out, fn($x,$y) => ($y['zu_bestellen'] <=> $x['zu_bestellen']) ?: strcmp($x['name'], $y['name']));
    return $out;
}
// Bulk-Zukauf-Bedarf (Fremdproduktion): je Auftrag der fertige Bulk (Kapseln/Tabletten/Pulver), der extern beschafft wird.
// Erscheint im Reiter „Fertige Produkte". Netting gegen offene Freitext-Positionen (item_id NULL) dieses Auftrags.
// Offene Aufträge, bei denen Eigen/Fremd noch NICHT festgelegt ist – für den Hinweis im Einkaufsbedarf
// („erst festlegen", mit Link in die Produktion). Ihre Bedarfspositionen erscheinen bewusst noch nicht.
function auftraege_ohne_festlegung(): array {
    if (!table_exists('produktionsauftrag')) return [];
    return all("SELECT pa.id AS pa_id, pa.auftrag_id, pa.produkt_id, pa.kunde_id, a.nummer AS auftrag_nr,
                       COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name, rz.name) AS produkt, k.firma AS kunde
                FROM produktionsauftrag pa
                LEFT JOIN auftrag a   ON a.id=pa.auftrag_id
                LEFT JOIN produkt p   ON p.id=pa.produkt_id
                LEFT JOIN rezeptur rz ON rz.id=pa.rezeptur_id
                LEFT JOIN kunden k    ON k.id=pa.kunde_id
                WHERE pa.status='vorbereitung' AND pa.auftrag_id IS NOT NULL
                  AND (a.status IS NULL OR a.status <> 'storniert')
                ORDER BY pa.id DESC");
}
// Wie wurde dasselbe Produkt zuletzt festgelegt (eigen/fremd)? Fuer die Einkauf-Festlegung als Hinweis.
// Bevorzugt denselben Kunden, sonst irgendein frueherer, bereits festgelegter Produktionsauftrag des Produkts.
// Rueckgabe: ['art','am','auftrag_nr','kunde','kunde_id'] oder null.
function produktionsart_letzte(int $produkt_id, ?int $kunde_id = null, int $exclude_pa_id = 0): ?array {
    if ($produkt_id <= 0 || !table_exists('produktionsauftrag')) return null;
    $row = one("SELECT pa.produktionsart AS art, pa.art_festgelegt_am AS am, a.nummer AS auftrag_nr,
                       k.firma AS kunde, pa.kunde_id
                FROM produktionsauftrag pa
                LEFT JOIN auftrag a ON a.id=pa.auftrag_id
                LEFT JOIN kunden k  ON k.id=pa.kunde_id
                WHERE pa.produkt_id=? AND pa.art_festgelegt_am IS NOT NULL AND pa.id<>?
                ORDER BY (pa.kunde_id <=> ?) DESC, pa.art_festgelegt_am DESC
                LIMIT 1", [$produkt_id, $exclude_pa_id, (int)($kunde_id ?: 0)]);
    return $row ?: null;
}
function bedarf_bulk(bool $nur_gemeldet = false): array {
    $wo = "pa.status IN ('offen','laufend') AND pa.auftrag_id IS NOT NULL AND pa.produktionsart='fremd' AND pa.art_festgelegt_am IS NOT NULL"
        . ($nur_gemeldet ? " AND pa.bedarf_gemeldet IS NOT NULL" : "");
    // Gleiches Produkt (= gleiche Kapsel/Bulk) über mehrere Aufträge zusammenfassen.
    $grp = [];
    foreach (all("SELECT pa.id, pa.auftrag_id, pa.menge, pa.produkt_id, a.nummer AS auftrag_nr,
                         p.name AS produkt, COALESCE(NULLIF(p.einheiten_pro_packung,0), a.stueck, 0) AS ep
                  FROM produktionsauftrag pa LEFT JOIN auftrag a ON a.id=pa.auftrag_id
                  LEFT JOIN produkt p ON p.id=pa.produkt_id
                  WHERE $wo ORDER BY pa.prio, pa.angelegt") as $pa) {
        $need = (int)$pa['menge'] * (int)$pa['ep'];   // ep mit Fallback auf auftrag.stueck (v3-Import)
        if ($need <= 0) continue;
        $pid = (int)$pa['produkt_id'];
        if (!isset($grp[$pid])) $grp[$pid] = ['produkt_id'=>$pid, 'produkt'=>$pa['produkt'], 'need'=>0.0, 'orders'=>[], 'pa_ids'=>[], 'auftrag_ids'=>[]];
        $grp[$pid]['need'] += $need;
        $grp[$pid]['orders'][] = ['pa_id'=>(int)$pa['id'], 'auftrag_id'=>(int)$pa['auftrag_id'], 'auftrag_nr'=>$pa['auftrag_nr'], 'need'=>$need];
        $grp[$pid]['pa_ids'][] = (int)$pa['id'];
        $grp[$pid]['auftrag_ids'][(int)$pa['auftrag_id']] = true;
    }
    $out = [];
    foreach ($grp as $g) {
        $aids = array_keys($g['auftrag_ids']);
        $in = implode(',', array_map('intval', $aids ?: [0]));
        $bestellt = (float) scalar("SELECT COALESCE(SUM(bp.menge),0) FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id
                                    WHERE bp.item_id IS NULL AND bp.auftrag_id IN ($in) AND b.status<>'geliefert'");
        // Freier, nicht kundengebundener Fertigware-Bestand dieses Produkts (ohne Item neu anzulegen).
        $lagerItem = (int) scalar("SELECT id FROM item WHERE produkt_id=? AND kategorie='verkaufsfertig' LIMIT 1", [$g['produkt_id']]);
        $stock = $lagerItem > 0 ? item_bestand($lagerItem, true) : 0.0;
        $g['bestellt'] = $bestellt;
        $g['stock'] = $stock;
        $g['zu_bestellen'] = max(0.0, $g['need'] - $stock - $bestellt);
        unset($g['auftrag_ids']);
        $out[] = $g;
    }
    return $out;
}
// Ausgewählte Bulk-Zukäufe (Produkt-IDs) als Bestellung anlegen – je Auftrag eine getrackte Freitext-Position, mit Lieferant+Datum.
function bestellung_bulk_anlegen(array $produkt_ids, ?int $lieferant, ?string $datum): int {
    $produkt_ids = array_values(array_filter(array_map('intval', $produkt_ids)));
    if (!$produkt_ids) return 0;
    $gruppen = array_values(array_filter(bedarf_bulk(true), fn($g) => in_array($g['produkt_id'], $produkt_ids, true) && $g['zu_bestellen'] > 1e-6));
    if (!$gruppen) return 0;
    $status = $datum ? 'bestellt' : 'offen';
    q("INSERT INTO bestellung (nummer,lieferant_id,status,notiz,bestelldatum) VALUES (?,?,?,?,?)",
      [naechste_nummer('BE'), $lieferant ?: null, $status, 'Bulk-Zukauf (Fremdproduktion)', $datum ?: null]);
    $bid = (int) insert_id();
    $nummer = (string) scalar("SELECT nummer FROM bestellung WHERE id=?", [$bid]);
    $wann = $datum ? ' (bestellt am ' . date('d.m.Y', strtotime($datum)) . ')' : '';
    $i = 0;
    foreach ($gruppen as $g) {
        $stockPool = (float)($g['stock'] ?? 0);   // freier Fertigware-Bestand deckt den Bedarf zuerst (FIFO über die Aufträge)
        foreach ($g['orders'] as $o) {
            $offen = (float) scalar("SELECT COALESCE(SUM(bp.menge),0) FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id
                                     WHERE bp.item_id IS NULL AND bp.auftrag_id=? AND b.status<>'geliefert'", [(int)$o['auftrag_id']]);
            $noch = (float)$o['need'] - $offen;
            if ($stockPool > 1e-6 && $noch > 1e-6) {   // vorhandenen Bestand anrechnen
                $ab = min($stockPool, $noch);
                $noch -= $ab; $stockPool -= $ab;
            }
            if ($noch <= 1e-6) continue;
            $ek = produkt_zukauf_preis((int)$g['produkt_id'], $lieferant ?: null, $noch) ?? 0.0;
            q("INSERT INTO bestellung_position (bestellung_id,item_id,bezeichnung,menge,ek_preis,einheit,auftrag_id,sort) VALUES (?,?,?,?,?,?,?,?)",
              [$bid, null, 'Bulk: ' . $g['produkt'] . ' (' . $o['auftrag_nr'] . ')', $noch, $ek, 'Stück', (int)$o['auftrag_id'], $i++]);
            if ((int)$o['auftrag_id'] > 0)
                log_aktivitaet('auftrag', (int)$o['auftrag_id'], 'team', 'Bulk (Fremdproduktion) per Bestellung ' . $nummer . $wann . ' bestellt.', 'bestellung', 'bestellung', $bid);
        }
    }
    return $bid;
}

// Sammelbestellung: aus dem aggregierten Bedarf je Hauptlieferant EINE Bestellung mit Gesamtmengen (Lager/allgemein). Gibt bestellung-IDs zurück.
function bestellung_sammel_anlegen(?string $typ = null): array {
    $gruppen = [];
    foreach (bedarf_aggregiert(true) as $a) {   // nur gemeldete Bedarfe
        if ($a['zu_bestellen'] <= 1e-6) continue;
        if ($typ && $a['typ'] !== $typ) continue;
        $lief = (int) (scalar("SELECT haupt_lieferant_id FROM item WHERE id=?", [$a['item_id']]) ?: 0);
        $gruppen[$lief][] = $a;
    }
    $label = $typ ? bedarf_typ_label($typ) : 'Material';
    $erstellt = [];
    foreach ($gruppen as $lief => $pos) {
        q("INSERT INTO bestellung (nummer,lieferant_id,status,notiz) VALUES (?,?,?,?)",
          [naechste_nummer('BE'), $lief ?: null, 'offen', 'Sammelbestellung ' . $label . ' (über alle Aufträge)']);
        $bid = (int) insert_id();
        $nummer = (string) scalar("SELECT nummer FROM bestellung WHERE id=?", [$bid]);
        $betroffen = [];
        foreach ($pos as $i => $a) {
            $ek = (float) scalar("SELECT ek_preis FROM item WHERE id=?", [$a['item_id']]);
            q("INSERT INTO bestellung_position (bestellung_id,item_id,menge,ek_preis,einheit,auftrag_id,sort) VALUES (?,?,?,?,?,NULL,?)",
              [$bid, $a['item_id'], $a['zu_bestellen'], $ek, $a['einheit'], $i]);
            foreach ($a['orders'] as $o) if ($o['need'] > 1e-6 && (int)$o['auftrag_id'] > 0) $betroffen[(int)$o['auftrag_id']] = true;
        }
        // In jeden betroffenen Auftrag schreiben, dass dieser Typ per Sammelbestellung bestellt wurde
        foreach (array_keys($betroffen) as $aid)
            log_aktivitaet('auftrag', $aid, 'team', $label . ' per Sammelbestellung ' . $nummer . ' bestellt.', 'bestellung', 'bestellung', $bid);
        $erstellt[] = $bid;
    }
    return $erstellt;
}

// Bestellung aus ausgewählten Bedarfspositionen: EINE Bestellung mit gewähltem Lieferant + Bestelldatum (bündeln).
// $mengen = [item_id => menge]. Bei gesetztem Datum Status 'bestellt' (getätigt), sonst 'offen' (Entwurf). Vermerkt in betroffenen Aufträgen.
function bestellung_aus_positionen(array $mengen, ?int $lieferant, ?string $datum): int {
    $mengen = array_filter($mengen, fn($m) => (float)$m > 0);
    if (!$mengen) return 0;
    $status = $datum ? 'bestellt' : 'offen';
    q("INSERT INTO bestellung (nummer,lieferant_id,status,notiz,bestelldatum) VALUES (?,?,?,?,?)",
      [naechste_nummer('BE'), $lieferant ?: null, $status, 'Aus Einkaufsliste', $datum ?: null]);
    $bid = (int) insert_id();
    $nummer = (string) scalar("SELECT nummer FROM bestellung WHERE id=?", [$bid]);
    // Map item -> betroffene Aufträge (für den Vermerk)
    $ordersByItem = [];
    foreach (bedarf_aggregiert(true) as $a) $ordersByItem[$a['item_id']] = array_values(array_filter(array_map(fn($o)=> (int)$o['auftrag_id'], array_filter($a['orders'], fn($o)=> $o['need'] > 1e-6))));
    $i = 0; $betroffen = [];
    foreach ($mengen as $iid => $menge) {
        $iid = (int)$iid;
        $ek = (float) scalar("SELECT ek_preis FROM item WHERE id=?", [$iid]);
        $einh = (string) scalar("SELECT einheit FROM item WHERE id=?", [$iid]);
        q("INSERT INTO bestellung_position (bestellung_id,item_id,menge,ek_preis,einheit,auftrag_id,sort) VALUES (?,?,?,?,?,NULL,?)",
          [$bid, $iid, (float)$menge, $ek, $einh, $i++]);
        foreach ($ordersByItem[$iid] ?? [] as $aid) $betroffen[$aid] = true;
    }
    $wann = $datum ? ' (bestellt am ' . date('d.m.Y', strtotime($datum)) . ')' : '';
    foreach (array_keys($betroffen) as $aid) if ($aid > 0)
        log_aktivitaet('auftrag', $aid, 'team', 'Material per Bestellung ' . $nummer . $wann . ' bestellt.', 'bestellung', 'bestellung', $bid);
    bedarf_bump();   // bestellt -> offener Bedarf aendert sich
    return $bid;
}

// Hat dieser Produktionsauftrag noch offenen (nicht bestellten) Bedarf? (Komponenten/Etikett oder Fremd-Bulk)
function auftrag_offener_bedarf(int $pa_id): bool {
    if (auftrag_fehlbedarf($pa_id)) return true;
    $pa = pa_row_cached($pa_id);
    if ($pa && ($pa['produktionsart'] ?? 'eigen') === 'fremd') {
        $einh = produktion_stueck_je_packung($pa);   // Fallback auf auftrag.stueck (v3-Import, produkt.einheiten_pro_packung=0)
        $need = (int)$pa['menge'] * $einh;
        $ordered = (float) scalar("SELECT COALESCE(SUM(bp.menge),0) FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id
                                   WHERE bp.item_id IS NULL AND bp.auftrag_id=? AND b.status<>'geliefert'", [(int)$pa['auftrag_id']]);
        if ($need - $ordered > 1e-6) return true;
    }
    return false;
}

// 1-Klick: aus dem Fehlbedarf Bestellungs-Entwürfe je Hauptlieferant anlegen (mit Auftragsbezug). Gibt bestellung-IDs zurück.
function bestellung_aus_bedarf(int $pa_id): array {
    $pa = one("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $aid = $pa ? (int)$pa['auftrag_id'] : 0;
    $gruppen = [];
    foreach (auftrag_fehlbedarf($pa_id) as $r) {
        $lief = (int) (scalar("SELECT haupt_lieferant_id FROM item WHERE id=?", [(int)$r['item_id']]) ?: 0);
        $gruppen[$lief][] = $r;
    }
    $erstellt = [];
    foreach ($gruppen as $lief => $pos) {
        q("INSERT INTO bestellung (nummer,lieferant_id,status,notiz) VALUES (?,?,?,?)",
          [naechste_nummer('BE'), $lief ?: null, 'offen', 'Bedarf aus Auftragsbestätigung']);
        $bid = (int) insert_id();
        foreach ($pos as $i => $p) {
            $einh = scalar("SELECT einheit FROM item WHERE id=?", [(int)$p['item_id']]);
            $ek   = (float) scalar("SELECT ek_preis FROM item WHERE id=?", [(int)$p['item_id']]);
            q("INSERT INTO bestellung_position (bestellung_id,item_id,menge,ek_preis,einheit,auftrag_id,sort) VALUES (?,?,?,?,?,?,?)",
              [$bid, (int)$p['item_id'], $p['zu_bestellen'], $ek, $einh, $aid, $i]);
        }
        $erstellt[] = $bid;
    }
    return $erstellt;
}

// Geführte Produktion: Klartext-Anweisung + Scan-Anforderung je Station.
// Rückgabe: ['text'=>Anweisung, 'scan'=>bool, 'kat'=>rohstoff|verpackung|fertig|kapselhuelle|null]
function station_anleitung(string $station): array {
    $a = match ($station) {
        'Rohstoffe bereitstellen'  => ['Hol die benötigten Rohstoffe aus dem Lager (FEFO) und scanne die verwendete Charge.', true, 'rohstoff'],
        'Fertigware bereitstellen' => ['Hol die zugekaufte fertige Bulkware und scanne die Charge.', true, 'fertig'],
        'Mischen'                  => ['Mische die Rohstoffe gemäß Rezeptur gründlich und homogen.', false, null],
        'Verkapselung'             => ['Befülle die Kapseln. Scanne die verwendete Leerkapsel-Charge.', true, 'kapselhuelle'],
        'Tablettierung'            => ['Presse die Tabletten gemäß Vorgabe.', false, null],
        'Softgel-Herstellung'      => ['Stelle die Softgels her.', false, null],
        'Stick-Abfüllung'          => ['Fülle die Sticks ab.', false, null],
        'Pulver-Abfüllung'         => ['Fülle das Pulver ab.', false, null],
        'Abfüllung'                => ['Fülle das Produkt ab.', false, null],
        'Verpacken'                => ['Fülle das Produkt in die Verpackung. Scanne die verwendete Verpackungs-Charge.', true, 'verpackung'],
        'Etikettieren'             => ['Etikettiere alle Gebinde korrekt (Charge, MHD, Kennzeichnung).', false, null],
        'Beipackzettel beilegen'   => ['Beipackzettel/Booklet beilegen.', false, null],
        'Umkarton'                 => ['Produkt in den Karton/die Umverpackung legen.', false, null],
        'Qualitätsprüfung'         => ['Prüfe Aussehen, Füllmenge, Dichtigkeit und Kennzeichnung.', false, null],
        'Produktions-Freigabe'     => ['Kontrolliere und gib die Produktion frei.', false, null],
        'Versand-Freigabe'         => ['Gib den Auftrag zum Versand frei.', false, null],
        default                    => [$station, false, null],
    };
    return ['text' => $a[0], 'scan' => $a[1], 'kat' => $a[2]];
}
// Gescannte Charge gegen die erwartete Kategorie/Form prüfen. Rückgabe ['ok'=>bool,'msg'=>string,'charge'=>?array]
function produktion_scan_pruefen(string $scan, ?string $kat): array {
    $scan = trim($scan);
    if ($scan === '') return ['ok'=>false, 'msg'=>'Keine Charge gescannt.', 'charge'=>null];
    $c = one("SELECT c.*, i.name AS item_name, i.kategorie AS kategorie, i.form AS form
              FROM charge c JOIN item i ON i.id=c.item_id WHERE c.charge_nr=? ORDER BY c.id DESC LIMIT 1", [$scan]);
    if (!$c) return ['ok'=>false, 'msg'=>'Charge „' . $scan . '" nicht gefunden.', 'charge'=>null];
    if ($c['status'] !== 'frei') return ['ok'=>false, 'msg'=>'Charge „' . $scan . '" ist nicht frei (Status: ' . $c['status'] . ').', 'charge'=>$c];
    if ($kat) {
        $passt = $kat === 'kapselhuelle' ? ($c['form'] === 'kapselhuelle') : ($c['kategorie'] === $kat);
        if (!$passt) return ['ok'=>false, 'msg'=>'Charge „' . $scan . '" passt nicht zu diesem Schritt (' . $c['item_name'] . ').', 'charge'=>$c];
    }
    return ['ok'=>true, 'msg'=>'', 'charge'=>$c];
}

// Eine Produktionsstation abschließen – ZENTRALE Logik des geführten Ablaufs.
// Genutzt von der Admin-Detailseite UND der geführten Produktionsseite (später App/API).
// Erzwingt die Reihenfolge (nur die erste offene Station), prüft Scan (bzw. Master-Scan),
// bucht Material FEFO ab, markiert erledigt, aktualisiert den Status; beim letzten Schritt
// wird die Fertigware eingebucht und der Auftrag auf 'erledigt' gesetzt.
// Rückgabe: ['ok'=>bool, 'fehler'=>?('reihenfolge'|'scan'|'mangel'), 'msg'=>string, 'fertig'=>bool, 'station'=>string]
function produktion_schritt_erledigen(int $pa_id, int $schritt_id, string $scan = '', bool $ohneScan = false, bool $godmode = false): array {
    // God-Mode (nur Admin): klickt alles durch, OHNE jede Prüfung/Buchung – keine Vorbereitungs-Weiche, kein
    // Scan, kein Bestandscheck, keine Material-Abbuchung, keine Lager-Übergabe. Für Admin-Tests/Sonderfälle.
    $god = $godmode && function_exists('has_role') && has_role('admin');
    // PreProduktionsauftrag: solange der Auftrag in Vorbereitung ist (noch nicht vom Admin freigegeben),
    // kann in der Produktion kein Schritt gestartet/abgeschlossen werden. Sichtbar ja – startbar nein. (God-Mode überspringt das.)
    if (!$god && (string) scalar("SELECT status FROM produktionsauftrag WHERE id=?", [$pa_id]) === 'vorbereitung')
        return ['ok'=>false, 'fehler'=>'vorbereitung', 'msg'=>'Dieser Auftrag ist noch in Vorbereitung und nicht zur Produktion freigegeben.', 'fertig'=>false, 'station'=>''];
    $firstOpen = one("SELECT id, station FROM produktion_schritt WHERE pa_id=? AND erledigt=0 ORDER BY sort LIMIT 1", [$pa_id]);
    if (!$firstOpen || (int)$firstOpen['id'] !== $schritt_id)
        return ['ok'=>false, 'fehler'=>'reihenfolge', 'msg'=>'Dieser Schritt ist gerade nicht an der Reihe.', 'fertig'=>false, 'station'=>''];
    $station = (string)$firstOpen['station'];
    $anl  = station_anleitung($station);
    $scan = trim($scan);
    $master = ist_master_scan($scan) || $god;   // 8er-Folge ODER God-Mode: überspringt Scan-Prüfung + Bestandsabbuchung
    // $ohneScan = einfacher Abhak-Modus (Detailseite): Schritt ohne Charge-Scan abschließen.
    // Material wird trotzdem nach FEFO abgebucht; nur die Scan-PRÜFUNG entfällt (es gibt noch keine Etiketten zum Scannen).
    if ($anl['scan'] && !$ohneScan && !$god) {
        if (!$master) {
            $chk = produktion_scan_pruefen($scan, $anl['kat']);
            if (!$chk['ok']) return ['ok'=>false, 'fehler'=>'scan', 'msg'=>$chk['msg'], 'fertig'=>false, 'station'=>$station];
        }
        q("UPDATE produktion_schritt SET scan_charge=? WHERE id=?", [$master ? 'Master-Freigabe' : $scan, $schritt_id]);
    }
    if (!$master) {
        $entnahme = match ($station) {
            'Rohstoffe bereitstellen'  => produktion_rohstoffe_entnehmen($pa_id),
            'Verkapselung'             => produktion_kapseln_entnehmen($pa_id),
            'Fertigware bereitstellen' => produktion_fertigware_entnehmen($pa_id),
            'Verpacken'                => produktion_verpackung_entnehmen($pa_id),
            default                    => ['ok'=>true],
        };
        if (!($entnahme['ok'] ?? true)) return ['ok'=>false, 'fehler'=>'mangel', 'msg'=>'Nicht genug Bestand für diesen Schritt.', 'fertig'=>false, 'station'=>$station];
    }
    $wer = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? '') : '';
    q("UPDATE produktion_schritt SET erledigt=1, erledigt_at=?, erledigt_von=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $wer !== '' ? $wer : null, $schritt_id]);
    reservierung_abgleichen($pa_id);   // entnommene Items: Reservierung schließen
    $total = (int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=?", [$pa_id]);
    $done  = (int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=? AND erledigt=1", [$pa_id]);
    $status = $done === 0 ? 'offen' : ($done >= $total ? 'erledigt' : 'laufend');
    q("UPDATE produktionsauftrag SET status=? WHERE id=?", [$status, $pa_id]);
    $fertig = ($status === 'erledigt');
    if ($fertig) {
        auftrag_reservierung_freigeben($pa_id);     // Rest-Reservierungen freigeben
        // ÜBERGABE ANS LAGER statt Selbst-Buchung: ist die Produktion durch, bekommt das Lager eine Aufgabe,
        // die Fertigware in Lager 1 oder 2 zu buchen (Fulfillment -> Lager 2). Das Lager bucht via einlager_buchen().
        // Teilmengen, die während der Produktion direkt gebucht wurden, zählen; einlager_buchen bucht nur den Rest.
        // God-Mode: KEINE Lager-Übergabe (es soll nichts gebucht werden – reiner Durchlauf).
        if (!$god) produktion_an_lager_uebergeben($pa_id);
        $pa = one("SELECT auftrag_id, kunde_id, nummer FROM produktionsauftrag WHERE id=?", [$pa_id]);
        if ($pa && $pa['auftrag_id']) {
            q("UPDATE auftrag SET status='erledigt' WHERE id=?", [(int)$pa['auftrag_id']]);
            if ($pa['kunde_id']) log_aktivitaet('kunde', (int)$pa['kunde_id'], 'team', 'Produktion ' . $pa['nummer'] . ($god ? ' per God-Mode (Admin) ohne Bestandsbuchung durchlaufen.' : ' abgeschlossen – an Lager zum Einlagern übergeben.'), 'auftrag', 'auftrag', (int)$pa['auftrag_id']);
        }
    }
    return ['ok'=>true, 'fehler'=>null, 'msg'=>'', 'fertig'=>$fertig, 'station'=>$station];
}
// God-Mode (nur Admin): die Produktion manuell auf eine STUFE setzen – einen Gate/„Zaun" überspringen
// (z. B. „kein Glas da"), OHNE Bestand zu prüfen/abzubuchen, OHNE Lager-Übergabe und OHNE den Auftrag/die
// Fertigware anzufassen. Setzt alle Schritte bis einschließlich $schritt_id auf erledigt, alle danach auf
// offen, und leitet daraus den PA-Status ab (offen/laufend/erledigt). $schritt_id=0 = alles wieder offen.
// Zweck: eine Produktion auf den richtigen Stand bringen, wenn man den echten Bestand (Gläser/Kapseln)
// nicht verbrauchen/buchen will. Rückgabe ['ok','msg','status','erledigt','total','stufe'].
function produktion_godmode_stufe_setzen(int $pa_id, int $schritt_id): array {
    if (!(function_exists('has_role') && has_role('admin'))) return ['ok'=>false, 'msg'=>'Nur Admin.'];
    if ((string) scalar("SELECT status FROM produktionsauftrag WHERE id=?", [$pa_id]) === 'vorbereitung')
        q("UPDATE produktionsauftrag SET status='offen' WHERE id=?", [$pa_id]);   // Freigabe-Weiche überspringen
    $schritte = all("SELECT id, sort, station FROM produktion_schritt WHERE pa_id=? ORDER BY sort, id", [$pa_id]);
    if (!$schritte) return ['ok'=>false, 'msg'=>'Keine Schritte am Auftrag.'];
    $zielIdx = -1;   // -1 = alles offen
    if ($schritt_id > 0) {
        foreach ($schritte as $i => $s) if ((int)$s['id'] === $schritt_id) $zielIdx = $i;
        if ($zielIdx < 0) return ['ok'=>false, 'msg'=>'Schritt gehört nicht zu diesem Auftrag.'];
    }
    $wer = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? '') : '';
    $now = gmdate('Y-m-d H:i:s');
    foreach ($schritte as $i => $s) {
        if ($i <= $zielIdx)
            q("UPDATE produktion_schritt SET erledigt=1, erledigt_at=COALESCE(erledigt_at,?), erledigt_von=COALESCE(NULLIF(erledigt_von,''),?) WHERE id=? AND erledigt=0",
              [$now, $wer !== '' ? $wer : null, (int)$s['id']]);
        else
            q("UPDATE produktion_schritt SET erledigt=0, erledigt_at=NULL WHERE id=? AND erledigt=1", [(int)$s['id']]);
    }
    $total = count($schritte); $erledigt = $zielIdx + 1;
    $status = $erledigt <= 0 ? 'offen' : ($erledigt >= $total ? 'erledigt' : 'laufend');
    q("UPDATE produktionsauftrag SET status=? WHERE id=?", [$status, $pa_id]);   // KEINE Lager-Übergabe, KEIN auftrag.status
    $stufeName = $zielIdx >= 0 ? (string)$schritte[$zielIdx]['station'] : 'Anfang (alles offen)';
    $pa = one("SELECT nummer, kunde_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if ($pa && !empty($pa['kunde_id']))
        log_aktivitaet('kunde', (int)$pa['kunde_id'], 'team', 'Produktion ' . $pa['nummer'] . ' per God-Mode (Admin) auf Stufe „' . $stufeName . '" gesetzt (Gate übersprungen, ohne Bestandsbuchung).', 'auftrag', 'auftrag', (int)($pa['auftrag_id'] ?? 0));
    return ['ok'=>true, 'msg'=>'', 'status'=>$status, 'erledigt'=>$erledigt, 'total'=>$total, 'stufe'=>$stufeName];
}

// Materialbedarf eines Produktionsauftrags: je Rohstoff benötigte vs. verfügbare Menge.
function produktion_materialbedarf(int $pa_id): array {
    $pa = pa_row_cached($pa_id);
    if (!$pa) return [];
    if (pa_ist_bulk($pa)) {
        // Bulk: Menge = Stück (Kapseln) direkt; kein Produkt/Packung dazwischen.
        $rid = (int)$pa['rezeptur_id'];
        $einheiten_total = (int)$pa['menge'];
    } else {
        $prod = produkt_row_cached((int)$pa['produkt_id']);
        if (!$prod || !$prod['rezeptur_id']) return [];
        // Stück je Packung MIT Fallback auf auftrag.stueck (v3-Importe tragen die Zahl oft nur am Auftrag,
        // produkt.einheiten_pro_packung=0). Sonst käme einheiten_total=0 -> Rohstoffbedarf 0 -> nichts bestellbar,
        // obwohl die Rezeptur eine Dosis hat (deckt sich mit der Anzeige „Kapseln je Packung" auf der PA-Seite).
        $einheiten_total = (int)$pa['menge'] * produktion_stueck_je_packung($pa);
        $rid = (int)$prod['rezeptur_id'];
    }
    // Zutaten der Rezeptur – in der Liste request-lokal gecacht (Rezepturen mehrerer Aufträge nur einmal holen).
    if (isset($GLOBALS['bx_stock_cache'])) {
        $zk = 'rz:' . $rid;
        if (!array_key_exists($zk, $GLOBALS['bx_stock_cache']))
            $GLOBALS['bx_stock_cache'][$zk] = all("SELECT z.item_id, z.menge_mg, i.name, i.einheit
                  FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=?", [$rid]);
        $zutaten = $GLOBALS['bx_stock_cache'][$zk];
    } else {
        $zutaten = all("SELECT z.item_id, z.menge_mg, i.name, i.einheit
                  FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=?", [$rid]);
    }
    $out = [];
    foreach ($zutaten as $z) {
        $mg = (float)$z['menge_mg'] * $einheiten_total;
        $faktor = $z['einheit'] === 'g' ? 1e3 : 1e6;          // mg -> Basiseinheit (kg-Standard)
        $benoetigt = $mg / $faktor;
        $verf = item_bestand((int)$z['item_id'], true);
        $out[] = ['item_id'=>(int)$z['item_id'], 'name'=>$z['name'], 'einheit'=>$z['einheit'],
                  'benoetigt'=>$benoetigt, 'verfuegbar'=>$verf, 'fehlt'=>max(0.0, $benoetigt - $verf)];
    }
    return $out;
}

// Express-Bestellung direkt aus einem Auftrag – überspringt Einkaufsbedarf/-liste.
// Legt EINE Bestellung an einen Lieferanten an, mit allen fehlenden Rohstoffen dieses Auftrags,
// die der Lieferant anbietet, in der jeweils passenden Staffel. Menge = was fehlt (Bedarf minus Bestand).
// Rückgabe: neue bestellung-id, oder null (kein Produktionsauftrag / Lieferant bietet nichts Fehlendes).
function auftrag_express_bestellung(int $auftrag_id, int $lieferant_id): ?int {
    if ($auftrag_id <= 0 || $lieferant_id <= 0) return null;
    $pa = one("SELECT id FROM produktionsauftrag WHERE auftrag_id=? ORDER BY id DESC LIMIT 1", [$auftrag_id]);
    if (!$pa) return null;
    $pos = [];
    foreach (produktion_materialbedarf((int)$pa['id']) as $b) {
        $menge = (float)($b['fehlt'] ?? 0);
        if ($menge <= 0.0001) continue;   // genug auf Lager
        // Passenden Staffelpreis dieses Lieferanten holen (größte Staffel <= Menge, sonst kleinste).
        $preis = null;
        foreach (all("SELECT preis, menge_ab FROM lieferant_preis WHERE item_id=? AND lieferant_id=? AND (waehrung IS NULL OR waehrung='EUR') ORDER BY menge_ab", [(int)$b['item_id'], $lieferant_id]) as $s) {
            if ($preis === null) $preis = (float)$s['preis'];              // kleinste als Rückfall (unter MOQ)
            if ((float)$s['menge_ab'] <= $menge) $preis = (float)$s['preis'];
        }
        if ($preis === null) continue;     // Lieferant bietet diesen Rohstoff nicht
        $pos[] = ['item_id' => (int)$b['item_id'], 'menge' => $menge, 'ek' => $preis, 'einheit' => (string)$b['einheit']];
    }
    if (!$pos) return null;
    q("INSERT INTO bestellung (nummer,lieferant_id,status,notiz,bestelldatum) VALUES (?,?,?,?,CURDATE())",
      [naechste_nummer('BE'), $lieferant_id, 'offen', 'Express-Bestellung aus Auftrag ' . (string) scalar("SELECT nummer FROM auftrag WHERE id=?", [$auftrag_id])]);
    $bid = insert_id();
    $i = 0;
    foreach ($pos as $p) {
        q("INSERT INTO bestellung_position (bestellung_id,item_id,menge,ek_preis,einheit,auftrag_id,sort) VALUES (?,?,?,?,?,?,?)",
          [$bid, $p['item_id'], $p['menge'], $p['ek'], $p['einheit'], $auftrag_id, $i++]);
    }
    return $bid;
}

// Express-Bestellung eines FERTIGPRODUKTS (Bulk-Zukauf) direkt aus einem Auftrag.
// Legt eine Bestellung mit EINER Bulk-Position an (item_id NULL, Freitext = Produktname), Menge =
// Packungen × Einheiten je Packung, zum passenden Staffelpreis des Lieferanten (produkt_lieferant_preis).
// Verknüpft über bestellung_position.auftrag_id. Rückgabe: bestellung-id oder null (kein Zukaufpreis).
function auftrag_express_bulk_bestellung(int $auftrag_id, int $lieferant_id): ?int {
    if ($auftrag_id <= 0 || $lieferant_id <= 0) return null;
    $a = one("SELECT a.menge, a.produkt_id, p.name, p.einheiten_pro_packung
              FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$auftrag_id]);
    if (!$a || !$a['produkt_id']) return null;
    $stk = (int)$a['menge'] * (int)($a['einheiten_pro_packung'] ?? 0);
    if ($stk <= 0) $stk = (int)$a['menge'];
    $preis = null; $einheit = 'Stück'; $groesse = '';
    foreach (all("SELECT preis, menge_ab, einheit, groesse FROM produkt_lieferant_preis
                  WHERE produkt_id=? AND lieferant_id=? AND (waehrung IS NULL OR waehrung='EUR') ORDER BY menge_ab", [(int)$a['produkt_id'], $lieferant_id]) as $s) {
        if ($preis === null) { $preis = (float)$s['preis']; $einheit = $s['einheit'] ?: 'Stück'; $groesse = (string)$s['groesse']; }
        if ((float)$s['menge_ab'] <= $stk) { $preis = (float)$s['preis']; $einheit = $s['einheit'] ?: 'Stück'; $groesse = (string)$s['groesse']; }
    }
    if ($preis === null) return null;
    q("INSERT INTO bestellung (nummer,lieferant_id,status,notiz,bestelldatum) VALUES (?,?,?,?,CURDATE())",
      [naechste_nummer('BE'), $lieferant_id, 'offen', 'Express Fertigprodukt-Zukauf aus Auftrag ' . (string) scalar("SELECT nummer FROM auftrag WHERE id=?", [$auftrag_id])]);
    $bid = insert_id();
    $bez = trim((string)$a['name'] . ($groesse !== '' ? ' · ' . $groesse : '')) ?: 'Fertigprodukt';
    q("INSERT INTO bestellung_position (bestellung_id,item_id,bezeichnung,menge,ek_preis,einheit,auftrag_id,sort) VALUES (?,NULL,?,?,?,?,?,0)",
      [$bid, $bez, $stk, $preis, $einheit, $auftrag_id]);
    return $bid;
}

// Rohstoffe für einen Produktionsauftrag nach FEFO entnehmen. Idempotent; blockiert bei zu wenig Bestand.
function produktion_rohstoffe_entnehmen(int $pa_id): array {
    if ((int) scalar("SELECT COUNT(*) FROM produktion_verbrauch WHERE pa_id=?", [$pa_id]) > 0) return ['ok'=>true, 'fehlt'=>[]];
    $bedarf = produktion_materialbedarf($pa_id);
    $fehlt = array_values(array_filter($bedarf, fn($b) => $b['fehlt'] > 0.0001));
    if ($fehlt) return ['ok'=>false, 'fehlt'=>$fehlt];
    foreach ($bedarf as $b) {
        $rest = $b['benoetigt'];
        foreach (all("SELECT * FROM charge WHERE item_id=? AND status='frei' AND menge_verfuegbar>0 AND fremd_kunde_id IS NULL
                      ORDER BY (mhd IS NULL), mhd ASC, id ASC", [$b['item_id']]) as $c) {
            if ($rest <= 0.0001) break;
            $nimm = min($rest, (float)$c['menge_verfuegbar']);
            $neu = (float)$c['menge_verfuegbar'] - $nimm;
            q("UPDATE charge SET menge_verfuegbar=?, status=? WHERE id=?", [$neu, $neu <= 0.0001 ? 'leer' : 'frei', $c['id']]);
            q("INSERT INTO produktion_verbrauch (pa_id,item_id,charge_id,menge,einheit,angelegt) VALUES (?,?,?,?,?,?)",
              [$pa_id, $b['item_id'], $c['id'], $nimm, $b['einheit'], gmdate('Y-m-d H:i:s')]);
            $rest -= $nimm;
        }
    }
    return ['ok'=>true, 'fehlt'=>[]];
}

// Verpackung eines Produktionsauftrags entnehmen (1 je Packung), FEFO. Idempotent; blockiert bei zu wenig.
function produktion_verpackung_entnehmen(int $pa_id): array {
    $pa = one("SELECT menge, produkt_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return ['ok'=>true, 'fehlt'=>[]];
    $vid = (int) (scalar("SELECT verpackung_id FROM produkt WHERE id=?", [$pa['produkt_id']]) ?: 0);
    if (!$vid) return ['ok'=>true, 'fehlt'=>[]];                       // kein Gebinde definiert -> nichts zu tun
    if ((int) scalar("SELECT COUNT(*) FROM produktion_verbrauch WHERE pa_id=? AND item_id=?", [$pa_id, $vid]) > 0) return ['ok'=>true, 'fehlt'=>[]];
    $benoetigt = (float)$pa['menge'];
    $verf = item_bestand($vid, true);
    if ($verf + 0.0001 < $benoetigt) {
        return ['ok'=>false, 'fehlt'=>[['name'=> scalar("SELECT name FROM item WHERE id=?", [$vid]), 'benoetigt'=>$benoetigt, 'verfuegbar'=>$verf, 'fehlt'=>$benoetigt-$verf, 'einheit'=>'Stück']]];
    }
    $rest = $benoetigt;
    foreach (all("SELECT * FROM charge WHERE item_id=? AND status='frei' AND menge_verfuegbar>0 AND fremd_kunde_id IS NULL ORDER BY (mhd IS NULL), mhd ASC, id ASC", [$vid]) as $c) {
        if ($rest <= 0.0001) break;
        $nimm = min($rest, (float)$c['menge_verfuegbar']);
        $neu = (float)$c['menge_verfuegbar'] - $nimm;
        q("UPDATE charge SET menge_verfuegbar=?, status=? WHERE id=?", [$neu, $neu <= 0.0001 ? 'leer' : 'frei', $c['id']]);
        q("INSERT INTO produktion_verbrauch (pa_id,item_id,charge_id,menge,einheit,angelegt) VALUES (?,?,?,?,?,?)",
          [$pa_id, $vid, $c['id'], $nimm, 'Stück', gmdate('Y-m-d H:i:s')]);
        $rest -= $nimm;
    }
    return ['ok'=>true, 'fehlt'=>[]];
}

// Auftrag versenden: Fertigware (FEFO) ausbuchen + Lieferschein (LS) + Status 'versendet'.
// Fulfillment-Kunde: Der Auftrag geht nicht raus, die Fertigware wandert ins Fremdlager (Lager 2)
// und bleibt dort, bis der Endkunde bestellt – dann bucht die Fulfillment-Kopplung sie ab
// (lager2_verbrauch). Deshalb wird hier NICHTS ausgebucht und kein Lieferschein geschrieben.
function auftrag_ins_fremdlager(int $auftrag_id): array {
    $a = one("SELECT * FROM auftrag WHERE id=? AND status='erledigt'", [$auftrag_id]);
    if (!$a) return ['ok'=>false, 'msg'=>'Auftrag ist nicht fertig produziert.'];
    $vfitem = (int) (scalar("SELECT id FROM item WHERE produkt_id=? AND kategorie='verkaufsfertig' LIMIT 1", [$a['produkt_id']]) ?: 0);
    if (!$vfitem) return ['ok'=>false, 'msg'=>'Zu diesem Produkt gibt es keinen Fertigwaren-Artikel.'];
    $verf = item_bestand($vfitem, true);
    if ($verf + 0.0001 < (float)$a['menge']) return ['ok'=>false, 'msg'=>'Nicht genug Fertigware im Lager (' . (int)$verf . ' von ' . (int)$a['menge'] . ').'];
    $bsku = bsku_ensure($vfitem);   // Brücke zum Shop – ohne sie findet die Kopplung den Artikel nicht
    q("UPDATE auftrag SET status='versendet' WHERE id=?", [$auftrag_id]);
    if ($a['kunde_id']) log_aktivitaet('kunde', (int)$a['kunde_id'], 'team',
        'Auftrag ' . $a['nummer'] . ' ins Fremdlager übernommen (BSKU ' . $bsku . '). Der Bestand bleibt dort, bis der Endkunde bestellt.',
        'auftrag', 'auftrag', $auftrag_id);
    return ['ok'=>true, 'msg'=>'Ins Fremdlager übernommen – der Bestand steht jetzt in Lager 2.', 'fremdlager'=>true];
}

function auftrag_versenden(int $auftrag_id): array {
    // „An den Kunden senden" – echte Auslieferung (FEFO-Ausbuchen + Lieferschein). Die Entscheidung
    // Kunde vs. Lager 2 trifft das Lager jetzt explizit beim Versand; es gibt daher KEINE automatische
    // Weiterleitung ins Fremdlager mehr (dafür ruft die Versandseite auftrag_ins_fremdlager() auf).
    $a = one("SELECT * FROM auftrag WHERE id=? AND status='erledigt'", [$auftrag_id]);
    if (!$a) return ['ok'=>false, 'msg'=>'Auftrag ist nicht versandbereit (Produktion muss abgeschlossen sein).'];
    $vfitem = (int) (scalar("SELECT id FROM item WHERE produkt_id=? AND kategorie='verkaufsfertig' LIMIT 1", [$a['produkt_id']]) ?: 0);
    $verf = $vfitem ? item_bestand($vfitem, true) : 0;
    if ($verf + 0.0001 < (float)$a['menge']) return ['ok'=>false, 'msg'=>'Nicht genug Fertigware im Lager (' . (int)$verf . ' von ' . (int)$a['menge'] . ').'];
    // Fertigware FEFO ausbuchen
    $rest = (float)$a['menge'];
    foreach (all("SELECT * FROM charge WHERE item_id=? AND status='frei' AND menge_verfuegbar>0 AND fremd_kunde_id IS NULL ORDER BY (mhd IS NULL), mhd ASC, id ASC", [$vfitem]) as $c) {
        if ($rest <= 0.0001) break;
        $nimm = min($rest, (float)$c['menge_verfuegbar']);
        $neu = (float)$c['menge_verfuegbar'] - $nimm;
        q("UPDATE charge SET menge_verfuegbar=?, status=? WHERE id=?", [$neu, $neu <= 0.0001 ? 'leer' : 'frei', $c['id']]);
        $rest -= $nimm;
    }
    // Lieferschein
    q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum)
       VALUES (?,'lieferschein',?,?,?,0,0,?,'offen',CURDATE())",
      [naechste_nummer('LS'), $auftrag_id, $a['kunde_id'], $a['gesamt_netto'], $a['gesamt_netto']]);
    q("UPDATE auftrag SET status='versendet' WHERE id=?", [$auftrag_id]);
    if ($a['kunde_id']) log_aktivitaet('kunde', (int)$a['kunde_id'], 'team', 'Auftrag ' . $a['nummer'] . ' versendet.', 'auftrag', 'auftrag', $auftrag_id);
    bedarf_bump();   // Fertigware ausgebucht/Auftrag raus
    return ['ok'=>true, 'msg'=>'Versendet.'];
}

// Auto-Kette: bestätigtes Angebot -> Auftragsbestätigung (AB) + Rechnung (RE) + Produktionsauftrag (PR). Idempotent.
// Namens-Snapshot bei Auftrags-Erstellung: friert den aktuellen internen Produktnamen am Auftrag ein,
// damit eine spaetere Produkt-Umbenennung laufende/abgeschlossene Auftraege NICHT umbenennt.
function auftrag_name_snapshot(int $auftrag_id): void {
    if ($auftrag_id <= 0) return;
    q("UPDATE auftrag a JOIN produkt p ON p.id=a.produkt_id SET a.produkt_bezeichnung=p.name
       WHERE a.id=? AND a.produkt_id IS NOT NULL AND (a.produkt_bezeichnung IS NULL OR a.produkt_bezeichnung='')", [$auftrag_id]);
}

function auftrag_aus_angebot(int $angebot_id): ?int {
    $a = one("SELECT * FROM angebot WHERE id=? AND status='bestaetigt'", [$angebot_id]);
    if (!$a) return null;
    $vorhanden = scalar("SELECT id FROM auftrag WHERE angebot_id=?", [$angebot_id]);
    if ($vorhanden) return (int)$vorhanden;                       // schon erzeugt -> nicht doppelt
    $s = one("SELECT * FROM angebot_staffel WHERE angebot_id=? AND bestaetigt=1 ORDER BY sort LIMIT 1", [$angebot_id]);
    if (!$s) return null;
    $menge = (int)$s['menge']; $vk = (float)$s['vk_stueck']; $netto = round($menge * $vk, 2);
    // Stück je Packung + Behälter MIT in den Auftrag übernehmen, damit er vollständig ist (sonst muss das
    // Team Menge/Verpackung nachtragen). Quellen-Priorität: bestätigte Staffel → Produkt → Anfrage.
    $pidA = (int)($a['produkt_id'] ?? 0);
    $prodA = $pidA ? one("SELECT verpackung_id, einheiten_pro_packung FROM produkt WHERE id=?", [$pidA]) : null;
    $stueck = (int)($s['stueck'] ?? 0);
    if ($stueck <= 0 && $prodA) $stueck = (int)($prodA['einheiten_pro_packung'] ?? 0);
    $verpId = $prodA ? (int)($prodA['verpackung_id'] ?? 0) : 0;
    if (($stueck <= 0 || $verpId <= 0) && !empty($a['anfrage_id'])) {
        $an = one("SELECT stueck, verpackung_id FROM portal_anfrage WHERE id=?", [(int)$a['anfrage_id']]);
        if ($an) { if ($stueck <= 0) $stueck = (int)($an['stueck'] ?? 0); if ($verpId <= 0) $verpId = (int)($an['verpackung_id'] ?? 0); }
    }
    q("INSERT INTO auftrag (nummer,angebot_id,kunde_id,produkt_id,menge,stueck,verpackung_id,vk_stueck,gesamt_netto,status)
       VALUES (?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('AB'), $angebot_id, $a['kunde_id'], $pidA, $menge, $stueck ?: null, $verpId ?: null, $vk, $netto, 'offen']);
    $aid = insert_id();
    auftrag_name_snapshot((int)$aid);
    // Kundenetikett-Artikel (v1) für dieses Produkt sicherstellen und den Auftrag daran binden – so kann das
    // Lager die gelieferten, kundenspezifischen Etiketten gezielt einbuchen (kein generischer Glas-Topf).
    $etId = kundenetikett_sicherstellen((int)$a['produkt_id']);
    if ($etId) q("UPDATE auftrag SET etikett_item_id=? WHERE id=?", [$etId, $aid]);
    // Rechnung: korrekte USt (Produktsatz, i.d.R. 19 %; Reverse-Charge/Kleinunternehmer 0 %). Ohne Rechnungs-
    // adresse wird die Rechnung NICHT für den Kunden freigegeben (Entwurf mit Hinweis) – Adresse ergänzen, dann neu berechnen.
    $ustP = produkt_ust_satz((int)($a['produkt_id'] ?? 0), (int)$a['kunde_id']);
    $ust = round($netto * $ustP / 100, 2); $brutto = $netto + $ust;
    $sicht = kunde_hat_rechnungsadresse((int)$a['kunde_id']) ? 1 : 0;
    $hinw  = $sicht ? null : 'Rechnungsadresse fehlt – bitte Kundenadresse ergänzen, dann Rechnung neu berechnen.';
    q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum,text,kunde_sichtbar)
       VALUES (?,?,?,?,?,?,?,?,?,CURDATE(),?,?)",
      [naechste_nummer('RE'), 'rechnung', $aid, $a['kunde_id'], $netto, $ustP, $ust, $brutto, 'offen', $hinw, $sicht]);
    // Detaillierte Positionen (Produkt + Glas + Etikett) materialisieren -> Team sieht sie, PDF funktioniert.
    beleg_positionen_materialisieren((int) insert_id(), $ustP);
    // Produktionsauftrag (PR) + Stationen automatisch anlegen
    $form = scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$a['produkt_id']]) ?: 'kapsel';
    // Standard = Fremdproduktion (verkürzter Weg); auf Eigenproduktion umstellbar im Produktions-Detail.
    q("INSERT INTO produktionsauftrag (nummer,auftrag_id,kunde_id,produkt_id,menge,stueck,verpackung_id,produktionsart,status) VALUES (?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('PR'), $aid, $a['kunde_id'], $a['produkt_id'], $menge, $stueck ?: null, $verpId ?: null, 'fremd', 'vorbereitung']);
    $paid = insert_id();
    foreach (produktionsschritte_fuer($form, true, false, produktion_wege_aufloesen((int)$a['produkt_id'], (int)($a['kunde_id'] ?? 0))) as $i => $station) {
        q("INSERT INTO produktion_schritt (pa_id,station,sort,erledigt) VALUES (?,?,?,0)", [$paid, $station, $i]);
    }
    if ($a['kunde_id']) log_aktivitaet('kunde', (int)$a['kunde_id'], 'team', 'Auftragsbestätigung, Rechnung & Produktionsauftrag automatisch erzeugt.', 'auftrag', 'auftrag', $aid);
    return $aid;
}

// Rechnung (Beleg) aus einem BESTEHENDEN Auftrag erzeugen – fuer Auftraege ohne (automatische)
// Rechnung, z. B. v3-Importe oder von Hand angelegte. Idempotent: gibt es schon eine nicht stornierte
// Rechnung zum Auftrag, wird deren ID zurueckgegeben. USt wie bei auftrag_aus_angebot (Kleinunternehmer
// 0 %, EU-Ausland 0 %, sonst Inlands-USt). Rueckgabe: beleg.id oder null (kein Preis am Auftrag).
// $opt (optional): datum (Y-m-d), leistung_datum (Y-m-d), zahlungsziel_tage (int),
//   ust_prozent (float; sonst automatisch), text (Rechnungshinweis).
function rechnung_aus_auftrag(int $auftrag_id, array $opt = []): ?int {
    $a = one("SELECT * FROM auftrag WHERE id=?", [$auftrag_id]);
    if (!$a) return null;
    $ex = scalar("SELECT id FROM beleg WHERE auftrag_id=? AND typ='rechnung' AND status<>'storniert' ORDER BY id LIMIT 1", [$auftrag_id]);
    if ($ex) return (int)$ex;                                   // schon da -> nicht doppelt
    $menge = (int)($a['menge'] ?? 0);
    $vk    = (float)($a['vk_stueck'] ?? 0);
    $netto = round((float)($a['gesamt_netto'] ?? 0), 2);
    if ($netto <= 0) $netto = round($menge * $vk, 2);
    if ($netto <= 0) return null;                               // ohne Preis keine Rechnung
    // USt: explizit vorgegeben? sonst zentrale, korrekte Logik (keine ungerechtfertigte 0 %).
    if (isset($opt['ust_prozent']) && $opt['ust_prozent'] !== '' && $opt['ust_prozent'] !== null) {
        $ustP = max(0.0, (float)$opt['ust_prozent']);
    } else {
        $ustP = produkt_ust_satz((int)($a['produkt_id'] ?? 0), (int)$a['kunde_id']);
    }
    $ust = round($netto * $ustP / 100, 2); $brutto = $netto + $ust;
    // Datum / Fälligkeit / Leistungsdatum.
    $gilt = fn($d) => (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
    $datum = $gilt($opt['datum'] ?? null) ?? gmdate('Y-m-d');
    $ziel  = (isset($opt['zahlungsziel_tage']) && $opt['zahlungsziel_tage'] !== '') ? max(0, (int)$opt['zahlungsziel_tage']) : null;
    $faellig = ($ziel !== null) ? date('Y-m-d', strtotime($datum . ' +' . $ziel . ' days')) : null;
    $leist = $gilt($opt['leistung_datum'] ?? null);
    $text  = trim((string)($opt['text'] ?? '')) ?: null;
    $sicht = !empty($opt['freigeben']) ? 1 : 0;                  // standardmaessig NICHT fuer den Kunden freigegeben
    // Keine Rechnung an den Kunden freigeben, solange keine Rechnungsadresse vorliegt. Der Beleg entsteht
    // als Entwurf (nicht sichtbar) mit Hinweis; sobald die Adresse da ist, kann er neu berechnet/freigegeben werden.
    $adresseFehlt = !kunde_hat_rechnungsadresse((int)$a['kunde_id']);
    if ($adresseFehlt) { $sicht = 0; $text = trim(($text ? $text . "\n" : '') . 'Rechnungsadresse fehlt – bitte Kundenadresse ergänzen, dann Rechnung neu berechnen.'); }
    $bearb = (int)($opt['bearbeiter_id'] ?? 0) ?: null;
    q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum,zahlungsziel_tage,faellig,leistung_datum,text,bearbeiter_id,kunde_sichtbar)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('RE'), 'rechnung', $auftrag_id, ($a['kunde_id'] ?: null), $netto, $ustP, $ust, $brutto, 'offen',
       $datum, $ziel, $faellig, $leist, $text, $bearb, $sicht]);
    $bid = (int) insert_id();
    // Ersteller als Bearbeiter im Verlauf festhalten.
    $ersteller = trim((string)($opt['ersteller'] ?? '')) ?: 'team';
    if (function_exists('beleg_status_log_add')) beleg_status_log_add($bid, 'offen', 'Rechnung aus Auftrag ' . (string)$a['nummer'] . ' erstellt' . ($sicht ? ', für Kunde freigegeben' : ''), $ersteller);
    // Detaillierte Positionen (Produkt + Glas + Deckel + Etikett) aus dem Auftrag materialisieren – so sieht
    // das Team dieselbe Aufschlüsselung wie der Kunde. Fallback (keine exakte Aufschlüsselung): eine Sammelzeile.
    beleg_positionen_materialisieren($bid, $ustP);
    if (!empty($a['kunde_id'])) {
        $re = (string) scalar("SELECT nummer FROM beleg WHERE id=?", [$bid]);
        log_aktivitaet('kunde', (int)$a['kunde_id'], 'team', 'Rechnung ' . $re . ' aus Auftrag ' . (string)$a['nummer'] . ' erstellt.', 'beleg', 'auftrag', $auftrag_id);
    }
    return $bid;
}

// Freie Rechnung (ohne Auftrag) anlegen – Kopf + eigene Positionen. Für die KI-gestützte und die
// manuelle Rechnungserstellung im Rechnungen-Menü. Positionen: [{artikelnr,bezeichnung,beschreibung,
// menge,einheit,preis (€, positiv),mwst_satz}]. $opt: kunde_id, datum, zahlungsziel_tage,
// leistung_datum, text, freigeben (bool → im Kundenportal sichtbar), ersteller. Gibt Beleg-ID oder null.
function rechnung_frei_erstellen(array $positionen, array $opt = []): ?int {
    $pos = [];
    foreach ($positionen as $p) {
        if (trim((string)($p['bezeichnung'] ?? '')) === '') continue;
        $menge = (float) str_replace(',', '.', (string)($p['menge'] ?? 1)); if ($menge <= 0) $menge = 1;
        $pos[] = [
            'artikelnr'   => trim((string)($p['artikelnr'] ?? '')),
            'bezeichnung' => (string)$p['bezeichnung'],
            'beschreibung'=> trim((string)($p['beschreibung'] ?? '')),
            'menge'       => $menge,
            'einheit'     => trim((string)($p['einheit'] ?? '')),
            'preis_cent'  => abs((int) round((float) str_replace(',', '.', (string)($p['preis'] ?? 0)) * 100)),
            'mwst_satz'   => (float) str_replace(',', '.', (string)($p['mwst_satz'] ?? $p['ust'] ?? 0)),
        ];
    }
    if (!$pos) return null;
    $s = beleg_summen_aus_positionen($pos);
    if ($s['netto'] <= 0) return null;                           // ohne Betrag keine Rechnung
    $ustP = 0.0;
    foreach ($pos as $p) if ((float)$p['mwst_satz'] > 0) { $ustP = (float)$p['mwst_satz']; break; }
    $gilt  = fn($d) => (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
    $datum = $gilt($opt['datum'] ?? null) ?? gmdate('Y-m-d');
    $ziel  = (isset($opt['zahlungsziel_tage']) && $opt['zahlungsziel_tage'] !== '') ? max(0, (int)$opt['zahlungsziel_tage']) : null;
    $faellig = ($ziel !== null) ? date('Y-m-d', strtotime($datum . ' +' . $ziel . ' days')) : null;
    $leist = $gilt($opt['leistung_datum'] ?? null);
    $text  = trim((string)($opt['text'] ?? '')) ?: null;
    $kid   = (int)($opt['kunde_id'] ?? 0) ?: null;
    $bearb = (int)($opt['bearbeiter_id'] ?? 0) ?: null;
    $sicht = !empty($opt['freigeben']) ? 1 : 0;                  // standardmäßig NICHT für den Kunden freigegeben
    q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum,zahlungsziel_tage,faellig,leistung_datum,text,bearbeiter_id,kunde_sichtbar)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('RE'), 'rechnung', null, $kid, $s['netto'], $ustP, $s['ust'], $s['brutto'], 'offen',
       $datum, $ziel, $faellig, $leist, $text, $bearb, $sicht]);
    $bid = (int) insert_id();
    $sort = 0;
    foreach ($pos as $p)
        q("INSERT INTO beleg_position (beleg_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,mwst_satz) VALUES (?,?,?,?,?,?,?,?,?)",
          [$bid, $sort++, $p['artikelnr'] ?: null, $p['bezeichnung'], $p['beschreibung'] ?: null,
           $p['menge'], $p['einheit'] ?: null, $p['preis_cent'], $p['mwst_satz']]);
    $ersteller = trim((string)($opt['ersteller'] ?? '')) ?: 'team';
    if (function_exists('beleg_status_log_add')) beleg_status_log_add($bid, 'offen', 'Rechnung manuell erstellt' . ($sicht ? ', für Kunde freigegeben' : ''), $ersteller);
    if ($kid) log_aktivitaet('kunde', $kid, 'team', 'Rechnung ' . scalar("SELECT nummer FROM beleg WHERE id=?", [$bid]) . ' erstellt.', 'beleg', 'beleg', $bid);
    return $bid;
}

// Eine hochgeladene (ältere) Original-Rechnung per KI auslesen. Rückgabe (immer, wirft nie):
//   ['ok'=>true,'nummer','datum'(Y-m-d|null),'netto','ust_prozent','brutto','bezahlt'(bool),'bezahlt_am']
//   ['ok'=>false,'fehler'=>'…']
function rechnung_import_ki(string $pfad): array {
    require_once __DIR__ . '/ki.php';
    if (!ki_bereit()) return ['ok' => false, 'fehler' => 'KI ist nicht eingerichtet (Einstellungen → KI).'];
    $prompt = "Dies ist eine (ältere) Ausgangsrechnung. Lies die Kopfdaten aus und gib NUR JSON zurück:\n"
        . '{"nummer":"","datum":"","netto":0,"ust_prozent":19,"brutto":0,"bezahlt":false,"bezahlt_am":""}' . "\n"
        . "datum und bezahlt_am im Format YYYY-MM-DD. brutto = Gesamt-/Rechnungsbetrag inkl. USt (Endsumme). "
        . "netto = Nettosumme, ust_prozent = USt-Satz in Prozent (0 wenn keiner ausgewiesen). "
        . "bezahlt = true NUR wenn die Rechnung klar als bezahlt gekennzeichnet ist (z. B. 'bezahlt', "
        . "'Betrag erhalten', 'Zahlungseingang', Quittung), sonst false. Zahlen mit Punkt als Dezimaltrennzeichen, "
        . "keine Tausenderpunkte. Nichts erfinden – unbekannte Felder leer/0.";
    $r = ki_datei_frage($pfad, $prompt, ['json' => true, 'max_tokens' => 1500, 'zweck' => 'rechnung-import']);
    if (empty($r['ok'])) return ['ok' => false, 'fehler' => (string)($r['fehler'] ?? 'Die Rechnung konnte nicht gelesen werden.')];
    $d = is_array($r['daten'] ?? null) ? $r['daten'] : [];
    $num  = fn($x) => (float) str_replace(',', '.', (string)$x);
    $gilt = fn($s) => (is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) ? $s : null;
    $brutto = $num($d['brutto'] ?? 0); $netto = $num($d['netto'] ?? 0); $ustP = $num($d['ust_prozent'] ?? 0);
    if ($brutto <= 0 && $netto > 0) $brutto = round($netto * (1 + $ustP / 100), 2);
    if ($netto <= 0 && $brutto > 0) $netto = $ustP > 0 ? round($brutto / (1 + $ustP / 100), 2) : $brutto;
    return ['ok' => true,
        'nummer'      => trim((string)($d['nummer'] ?? '')),
        'datum'       => $gilt($d['datum'] ?? null),
        'netto'       => round($netto, 2), 'ust_prozent' => $ustP, 'brutto' => round($brutto, 2),
        'bezahlt'     => !empty($d['bezahlt']),
        'bezahlt_am'  => $gilt($d['bezahlt_am'] ?? null)];
}

// Eine Alt-Rechnung als Beleg anlegen (ohne Auftrag), mit hochgeladenem Original-PDF. Für den
// Rechnungs-Import: erscheint danach im Kundenportal (Liste + Original-Download) mit Betrag + Status.
// $f: nummer, datum, netto, ust_prozent, brutto, bezahlt(bool). Gibt die Beleg-ID zurück.
function rechnung_alt_anlegen(int $kunde_id, array $f, string $datei, string $orig, ?int $auftrag_id = null): ?int {
    if ($kunde_id <= 0) return null;
    $brutto = round((float)($f['brutto'] ?? 0), 2);
    $netto  = round((float)($f['netto'] ?? 0), 2);
    $ustP   = (float)($f['ust_prozent'] ?? 0);
    if ($netto <= 0 && $brutto > 0) $netto = $ustP > 0 ? round($brutto / (1 + $ustP / 100), 2) : $brutto;
    $ust = round($netto * $ustP / 100, 2);
    if ($brutto <= 0) $brutto = round($netto + $ust, 2);
    if ($brutto <= 0) return null;   // ohne Betrag keine sinnvolle Rechnung
    $nummer = trim((string)($f['nummer'] ?? '')) ?: naechste_nummer('RE');
    $datum  = (is_string($f['datum'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['datum'])) ? $f['datum'] : gmdate('Y-m-d');
    $status = !empty($f['bezahlt']) ? 'bezahlt' : 'offen';
    q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum,original_datei,original_orig,kunde_sichtbar)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)",
      [$nummer, 'rechnung', ($auftrag_id ?: null), $kunde_id, $netto, $ustP, $ust, $brutto, $status, $datum, $datei, mb_substr($orig, 0, 255)]);
    $bid = (int) insert_id();
    if (function_exists('beleg_status_log_add')) beleg_status_log_add($bid, $status, 'Alt-Rechnung importiert (Original hochgeladen)' . ($status === 'bezahlt' ? ', als bezahlt übernommen' : ''), 'team');
    log_aktivitaet('kunde', $kunde_id, 'team', 'Alt-Rechnung ' . $nummer . ' importiert (' . number_format($brutto, 2, ',', '.') . ' €).', 'beleg', 'beleg', $bid);
    return $bid;
}

// Eine hochgeladene Rechnung/AB per KI in EINZELNE POSITIONEN auslesen (Herstellung/Kapseln, Glas/Dose,
// Etiketten …) – für die aufgeschlüsselte Preisübernahme am Auftrag. Rückgabe (wirft nie):
//   ['ok'=>true,'nummer','datum','bezahlt'(bool),'positionen'=>[{bezeichnung,menge,einheit,einzelpreis,ust}]]
function rechnung_import_positionen_ki(string $pfad): array {
    require_once __DIR__ . '/ki.php';
    if (!ki_bereit()) return ['ok' => false, 'fehler' => 'KI ist nicht eingerichtet (Einstellungen → KI).'];
    $prompt = "Dies ist eine Rechnung oder Auftragsbestätigung eines Lohnherstellers für Nahrungsergänzung. "
        . "Lies den Kopf und ALLE Positionszeilen aus und gib NUR JSON zurück:\n"
        . '{"nummer":"","datum":"","bezahlt":false,"positionen":[{"bezeichnung":"","menge":0,"einheit":"","einzelpreis":0,"ust":19}]}' . "\n"
        . "einzelpreis = NETTO-Einzelpreis je Einheit (NICHT die Zeilensumme). Typische Zeilen: die Herstellung "
        . "(Kapseln/Tabletten je Packung), die Verpackung (Dose/Glas), die Etiketten – jede als eigene Position "
        . "mit ihrem Preis. bezeichnung kurz (z. B. 'Herstellung 120 Kapseln', 'Weithalsglas 150 ml', 'Etiketten'). "
        . "datum im Format YYYY-MM-DD. bezahlt nur true, wenn klar als bezahlt gekennzeichnet. "
        . "Zahlen mit Punkt als Dezimaltrennzeichen, keine Tausenderpunkte. Nichts erfinden.";
    $r = ki_datei_frage($pfad, $prompt, ['json' => true, 'denken' => true, 'max_tokens' => 4000, 'timeout' => 240, 'zweck' => 'rechnung-positionen']);
    if (empty($r['ok'])) return ['ok' => false, 'fehler' => (string)($r['fehler'] ?? 'Die Rechnung konnte nicht gelesen werden.')];
    $d = is_array($r['daten'] ?? null) ? $r['daten'] : [];
    $num = fn($x) => (float) str_replace(',', '.', (string)$x);
    $list = (isset($d['positionen']) && is_array($d['positionen'])) ? $d['positionen'] : [];
    $pos = [];
    foreach ($list as $p) {
        if (!is_array($p)) continue;
        $bez = trim((string)($p['bezeichnung'] ?? '')); if ($bez === '') continue;
        $menge = $num($p['menge'] ?? 1); if ($menge <= 0) $menge = 1;
        $pos[] = ['bezeichnung' => mb_substr($bez, 0, 255), 'menge' => $menge,
                  'einheit' => mb_substr(trim((string)($p['einheit'] ?? '')), 0, 20),
                  'einzelpreis' => $num($p['einzelpreis'] ?? $p['preis'] ?? 0),
                  'ust' => $num($p['ust'] ?? 0)];
    }
    return ['ok' => true,
        'nummer'  => trim((string)($d['nummer'] ?? '')),
        'datum'   => (is_string($d['datum'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['datum'])) ? $d['datum'] : null,
        'bezahlt' => !empty($d['bezahlt']),
        'positionen' => $pos];
}

// Rezeptur löschen (nur Admin). Blockiert, wenn die Rezeptur noch aktiv VERWENDET wird
// (Produkt, Angebotsposition, Produktionsauftrag, Bulk-/Fertig-Lagerartikel) – dann erst dort lösen.
// Sonst: eigene Nebendaten löschen (Zutaten, Kundenpreise, Lieferanten-Angebote) und Verweise aus
// Anfragen/Scans lösen (rezeptur_id = NULL, die Anfrage bleibt bestehen). Rückgabe ['ok'=>bool,'fehler'?].
function rezeptur_loeschen(int $id): array {
    $id = (int)$id;
    if ($id <= 0) return ['ok' => false, 'fehler' => 'Ungültige Rezeptur.'];
    if (!scalar("SELECT id FROM rezeptur WHERE id=?", [$id])) return ['ok' => false, 'fehler' => 'Rezeptur nicht gefunden.'];

    $blocker = [];
    $nP  = (int) scalar("SELECT COUNT(*) FROM produkt WHERE rezeptur_id=?", [$id]);              if ($nP)  $blocker[] = $nP . ' Produkt(e)';
    $nAp = (int) scalar("SELECT COUNT(*) FROM angebot_position WHERE rezeptur_id=?", [$id]);     if ($nAp) $blocker[] = $nAp . ' Angebotsposition(en)';
    $nPa = (int) scalar("SELECT COUNT(*) FROM produktionsauftrag WHERE rezeptur_id=?", [$id]);   if ($nPa) $blocker[] = $nPa . ' Produktionsauftrag/-aufträge';
    // Lagerartikel (Bulk/Fertigware) der Rezeptur: nur blockieren, wenn Bestand (Chargen) dran hängt.
    // LEERE Bulk-Artikel sind nur automatisch angelegte Nebenprodukte und werden unten mitgelöscht.
    $nItBestand = (int) scalar("SELECT COUNT(*) FROM item i WHERE i.rezeptur_id=? AND EXISTS(SELECT 1 FROM charge c WHERE c.item_id=i.id)", [$id]);
    if ($nItBestand) $blocker[] = $nItBestand . ' Lagerartikel mit Bestand (Bulk/Fertigware)';
    if ($blocker) return ['ok' => false, 'fehler' => 'Rezeptur wird noch verwendet: ' . implode(', ', $blocker) . '. Bitte dort zuerst entfernen/ersetzen.'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Leere Bulk-/Fertigware-Lagerartikel der Rezeptur mitlöschen (kein Bestand -> reines Nebenprodukt).
        q("DELETE FROM item WHERE rezeptur_id=? AND NOT EXISTS(SELECT 1 FROM charge c WHERE c.item_id=item.id)", [$id]);
        // Eigene Nebendaten der Rezeptur
        q("DELETE FROM rezeptur_zutat WHERE rezeptur_id=?", [$id]);
        foreach (['rezeptur_kundenpreis', 'rezeptur_lief_angebot'] as $t)
            if (table_exists($t)) q("DELETE FROM $t WHERE rezeptur_id=?", [$id]);
        // Verweise aus Anfragen/Scans lösen – die Datensätze selbst bleiben erhalten.
        foreach (['rezeptur_anfrage', 'angebot_scan', 'portal_anfrage', 'portal_anfrage_pos',
                  'lieferant_anfrage', 'fastaction_item', 'fastaction_notiz'] as $t)
            if (table_exists($t)) q("UPDATE $t SET rezeptur_id=NULL WHERE rezeptur_id=?", [$id]);
        q("DELETE FROM rezeptur WHERE id=?", [$id]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'fehler' => 'Löschen abgebrochen: ' . $e->getMessage()];
    }
    return ['ok' => true];
}

// Wo wird eine Rezeptur überall verwendet (klickbar auflösen, damit man die Lösch-Blocker gezielt findet)?
// Deckt die Lösch-Blocker aus rezeptur_loeschen() ab PLUS abgeleitete Rezepturen und die direkten
// Verknüpfungen (auftrag/angebot/beleg.rezeptur_id). Rückgabe: Liste ['typ','label','url'(?),'blocker'(bool)].
function rezeptur_verwendung(int $id): array {
    $id = (int)$id; if ($id <= 0) return [];
    $out = [];
    foreach (all("SELECT id, COALESCE(NULLIF(name,''), NULLIF(kundenname,''), nummer, CONCAT('#',id)) AS name FROM produkt WHERE rezeptur_id=? ORDER BY id", [$id]) as $p)
        $out[] = ['typ'=>'Produkt', 'label'=>(string)$p['name'], 'url'=>'?p=produkt&id='.(int)$p['id'], 'blocker'=>true];
    foreach (all("SELECT id, name, kategorie FROM item WHERE rezeptur_id=? ORDER BY id", [$id]) as $it) {
        $hatBestand = (int) scalar("SELECT COUNT(*) FROM charge WHERE item_id=?", [(int)$it['id']]) > 0;
        $out[] = ['typ'=>'Lagerartikel (Bulk/Fertigware)',
                  'label'=>(string)$it['name'].' · '.(string)$it['kategorie'] . ($hatBestand ? ' · hat Bestand' : ' · leer, wird beim Löschen automatisch entfernt'),
                  'url'=>null, 'blocker'=>$hatBestand];
    }
    foreach (all("SELECT id, nummer FROM produktionsauftrag WHERE rezeptur_id=? ORDER BY id DESC", [$id]) as $pa)
        $out[] = ['typ'=>'Produktionsauftrag', 'label'=>(string)$pa['nummer'], 'url'=>'?p=produktionsauftrag&id='.(int)$pa['id'], 'blocker'=>true];
    foreach (all("SELECT DISTINCT ap.angebot_id, ag.nummer FROM angebot_position ap JOIN angebot ag ON ag.id=ap.angebot_id WHERE ap.rezeptur_id=? ORDER BY ap.angebot_id DESC", [$id]) as $ap)
        $out[] = ['typ'=>'Angebot (Position)', 'label'=>(string)$ap['nummer'], 'url'=>'?p=angebot&id='.(int)$ap['angebot_id'], 'blocker'=>true];
    foreach (all("SELECT id, nummer, name FROM rezeptur WHERE basis_rezeptur_id=? ORDER BY id", [$id]) as $c)
        $out[] = ['typ'=>'Abgeleitete Rezeptur (Basis)', 'label'=>(string)$c['nummer'].' · '.(string)$c['name'], 'url'=>'?p=rezeptur_detail&id='.(int)$c['id'], 'blocker'=>false];
    foreach (all("SELECT id, nummer FROM auftrag WHERE rezeptur_id=? ORDER BY id DESC", [$id]) as $a)
        $out[] = ['typ'=>'Auftrag (verknüpft)', 'label'=>(string)$a['nummer'], 'url'=>'?p=auftrag&id='.(int)$a['id'], 'blocker'=>false];
    foreach (all("SELECT id, nummer FROM angebot WHERE rezeptur_id=? ORDER BY id DESC", [$id]) as $a)
        $out[] = ['typ'=>'Angebot (verknüpft)', 'label'=>(string)$a['nummer'], 'url'=>'?p=angebot&id='.(int)$a['id'], 'blocker'=>false];
    foreach (all("SELECT id, nummer FROM beleg WHERE rezeptur_id=? ORDER BY id DESC", [$id]) as $b)
        $out[] = ['typ'=>'Rechnung/Beleg (verknüpft)', 'label'=>(string)$b['nummer'], 'url'=>'/buchhaltung/?p=rechnung&id='.(int)$b['id'], 'blocker'=>false];
    return $out;
}

// Produkt löschen – nur wenn es nirgends mehr hängt. Blocker: Aufträge, Produktionsaufträge, Kontingente,
// Angebote (Kopf-Produkt ODER Angebots-Produkt), Fertigware-Bestand (Chargen am verkaufsfertigen Lagerartikel).
// Eigene Nebendaten (Preise, Dokumente, leerer Lagerartikel) werden mitgelöscht; lose Verweise (Anfragen/
// Fastaction/Import) nur entkoppelt (NULL), die Datensätze selbst bleiben.
function produkt_loeschen(int $id): array {
    $id = (int)$id;
    if ($id <= 0 || !scalar("SELECT id FROM produkt WHERE id=?", [$id])) return ['ok'=>false, 'fehler'=>'Produkt nicht gefunden.'];
    $blocker = [];
    $nA  = (int) scalar("SELECT COUNT(*) FROM auftrag WHERE produkt_id=?", [$id]);            if ($nA)  $blocker[] = $nA . ' Auftrag/Aufträge';
    $nPa = (int) scalar("SELECT COUNT(*) FROM produktionsauftrag WHERE produkt_id=?", [$id]); if ($nPa) $blocker[] = $nPa . ' Produktionsauftrag/-aufträge';
    $nK  = (int) scalar("SELECT COUNT(*) FROM kontingent WHERE produkt_id=?", [$id]);         if ($nK)  $blocker[] = $nK . ' Kontingent(e)';
    // Angebote: nur ECHTE (existierende) zählen – verwaiste angebot_produkt-Links (Angebot längst gelöscht)
    // blockieren NICHT und werden unten aufgeräumt.
    $nAg = (int) scalar("SELECT COUNT(*) FROM angebot WHERE produkt_id=?", [$id])
         + (int) scalar("SELECT COUNT(*) FROM angebot_produkt ap JOIN angebot ag ON ag.id=ap.angebot_id WHERE ap.produkt_id=?", [$id]);
    if ($nAg) $blocker[] = $nAg . ' Angebot(e)';
    $lit = (int) scalar("SELECT id FROM item WHERE produkt_id=? AND kategorie='verkaufsfertig' LIMIT 1", [$id]);
    $bestand = $lit ? (int) scalar("SELECT COUNT(*) FROM charge WHERE item_id=?", [$lit]) : 0;
    if ($bestand) $blocker[] = $bestand . ' Charge(n) Fertigware-Bestand';
    if ($blocker) return ['ok'=>false, 'fehler'=>'Produkt wird noch verwendet: ' . implode(', ', $blocker) . '. Bitte dort zuerst entfernen/ersetzen.'];

    $pdo = db(); $pdo->beginTransaction();
    try {
        foreach (['produkt_preis', 'produkt_kundenpreis', 'produkt_lieferant_preis'] as $t)
            if (table_exists($t)) q("DELETE FROM $t WHERE produkt_id=?", [$id]);
        // Verwaiste Angebots-Produkt-Links aufräumen (echte Angebote hätten oben blockiert).
        if (table_exists('angebot_produkt')) q("DELETE FROM angebot_produkt WHERE produkt_id=?", [$id]);
        if (table_exists('dokument')) q("DELETE FROM dokument WHERE objekt_typ='produkt' AND objekt_id=?", [$id]);
        if ($lit) q("DELETE FROM item WHERE id=? AND kategorie='verkaufsfertig'", [$lit]);   // leerer Lagerartikel
        foreach (['fastaction_item', 'fastaction_notiz', 'portal_anfrage', 'portal_anfrage_pos', 'ek_import'] as $t)
            if (table_exists($t)) q("UPDATE $t SET produkt_id=NULL WHERE produkt_id=?", [$id]);
        q("DELETE FROM produkt WHERE id=?", [$id]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['ok'=>false, 'fehler'=>'Löschen abgebrochen: ' . $e->getMessage()];
    }
    return ['ok'=>true];
}

// Kontingent (Jahresvertrag) löschen – nur wenn keine aktiven Abrufe/Aufträge mehr daran hängen.
// Blocker: nicht stornierte Aufträge mit kontingent_id. Stornierte Abrufe werden entkoppelt, die
// zugehörigen Dokumente (signierter Vertrag) mitgelöscht.
function kontingent_loeschen(int $id): array {
    $id = (int)$id;
    if ($id <= 0 || !scalar("SELECT id FROM kontingent WHERE id=?", [$id])) return ['ok'=>false, 'fehler'=>'Kontingent nicht gefunden.'];
    $aktiv = (int) scalar("SELECT COUNT(*) FROM auftrag WHERE kontingent_id=? AND status<>'storniert'", [$id]);
    if ($aktiv) return ['ok'=>false, 'fehler'=>'Es gibt noch ' . $aktiv . ' aktive(n) Abruf/Auftrag aus diesem Kontingent. Bitte dort zuerst stornieren/entfernen.'];
    $pdo = db(); $pdo->beginTransaction();
    try {
        q("UPDATE auftrag SET kontingent_id=NULL WHERE kontingent_id=?", [$id]);   // stornierte Abrufe entkoppeln
        if (table_exists('dokument')) q("DELETE FROM dokument WHERE objekt_typ='kontingent' AND objekt_id=?", [$id]);
        q("DELETE FROM kontingent WHERE id=?", [$id]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['ok'=>false, 'fehler'=>'Löschen abgebrochen: ' . $e->getMessage()];
    }
    return ['ok'=>true];
}

// Wo wird ein Produkt überall verwendet (klickbar) – deckt die Lösch-Blocker aus produkt_loeschen() ab.
function produkt_verwendung(int $id): array {
    $id = (int)$id; if ($id <= 0) return [];
    $out = [];
    foreach (all("SELECT id, nummer FROM auftrag WHERE produkt_id=? ORDER BY id DESC", [$id]) as $a)
        $out[] = ['typ'=>'Auftrag', 'label'=>(string)$a['nummer'], 'url'=>'?p=auftrag&id='.(int)$a['id'], 'blocker'=>true];
    foreach (all("SELECT id, nummer FROM produktionsauftrag WHERE produkt_id=? ORDER BY id DESC", [$id]) as $pa)
        $out[] = ['typ'=>'Produktionsauftrag', 'label'=>(string)$pa['nummer'], 'url'=>'?p=produktionsauftrag&id='.(int)$pa['id'], 'blocker'=>true];
    foreach (all("SELECT id, nummer FROM angebot WHERE produkt_id=? ORDER BY id DESC", [$id]) as $ag)
        $out[] = ['typ'=>'Angebot (Kopf-Produkt)', 'label'=>(string)$ag['nummer'], 'url'=>'?p=angebot&id='.(int)$ag['id'], 'blocker'=>true];
    foreach (all("SELECT DISTINCT ap.angebot_id, ag.nummer FROM angebot_produkt ap JOIN angebot ag ON ag.id=ap.angebot_id WHERE ap.produkt_id=? ORDER BY ap.angebot_id DESC", [$id]) as $ag)
        $out[] = ['typ'=>'Angebot (Produkt)', 'label'=>(string)$ag['nummer'], 'url'=>'?p=angebot&id='.(int)$ag['angebot_id'], 'blocker'=>true];
    foreach (all("SELECT id FROM kontingent WHERE produkt_id=? ORDER BY id DESC", [$id]) as $k)
        $out[] = ['typ'=>'Kontingent', 'label'=>'Kontingent #'.(int)$k['id'], 'url'=>'?p=kontingente', 'blocker'=>true];
    $lit = (int) scalar("SELECT id FROM item WHERE produkt_id=? AND kategorie='verkaufsfertig' LIMIT 1", [$id]);
    if ($lit) { $ch = (int) scalar("SELECT COUNT(*) FROM charge WHERE item_id=?", [$lit]); if ($ch) $out[] = ['typ'=>'Fertigware-Bestand', 'label'=>$ch.' Charge(n)', 'url'=>null, 'blocker'=>true]; }
    return $out;
}

// Aus ausgelesenen Positionen eine Rechnung (Beleg) ANLEGEN und mit einem bestehenden Auftrag
// verknüpfen; Original-PDF anhängen; den (fehlenden) Auftragspreis aus der Positions-Summe füllen.
// So sind die Preise aufgeschlüsselt (Etikett/Glas/Kapsel …) beim Kunden hinterlegt. Rückgabe:
//   ['ok'=>true,'beleg_id','netto'] oder ['ok'=>false,'fehler'=>…].
function auftrag_rechnung_aus_positionen(int $auftrag_id, array $positionen, array $opt, string $datei, string $orig): array {
    $a = one("SELECT id, kunde_id, menge, gesamt_netto FROM auftrag WHERE id=?", [$auftrag_id]);
    if (!$a) return ['ok' => false, 'fehler' => 'Auftrag nicht gefunden.'];
    $kid = (int)($a['kunde_id'] ?? 0);
    // Positionen in das beleg_position-Format (preis_cent) bringen.
    $pp = [];
    foreach ($positionen as $p) {
        $bez = trim((string)($p['bezeichnung'] ?? '')); if ($bez === '') continue;
        $menge = (float) str_replace(',', '.', (string)($p['menge'] ?? 1)); if ($menge <= 0) $menge = 1;
        $pp[] = ['bezeichnung' => $bez, 'menge' => $menge,
                 'einheit' => trim((string)($p['einheit'] ?? '')),
                 'preis_cent' => (int) round((float) str_replace(',', '.', (string)($p['einzelpreis'] ?? 0)) * 100),
                 'mwst_satz' => (float) str_replace(',', '.', (string)($p['ust'] ?? 0))];
    }
    if (!$pp) return ['ok' => false, 'fehler' => 'Keine Positionen erkannt.'];
    $s = beleg_summen_aus_positionen($pp);
    if ($s['netto'] <= 0) return ['ok' => false, 'fehler' => 'Kein Betrag in den Positionen erkannt.'];
    $ustP = 0.0; foreach ($pp as $p) if ((float)$p['mwst_satz'] > 0) { $ustP = (float)$p['mwst_satz']; break; }
    $datum = (is_string($opt['datum'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $opt['datum'])) ? $opt['datum'] : gmdate('Y-m-d');
    $nummer = trim((string)($opt['nummer'] ?? '')) ?: naechste_nummer('RE');
    $status = !empty($opt['bezahlt']) ? 'bezahlt' : 'offen';
    q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum,original_datei,original_orig,kunde_sichtbar)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)",
      [$nummer, 'rechnung', $auftrag_id, ($kid ?: null), $s['netto'], $ustP, $s['ust'], $s['brutto'], $status, $datum,
       ($datei !== '' ? $datei : null), ($orig !== '' ? mb_substr($orig, 0, 255) : null)]);
    $bid = (int) insert_id();
    $sort = 0;
    foreach ($pp as $p)
        q("INSERT INTO beleg_position (beleg_id,sort,bezeichnung,menge,einheit,preis_cent,mwst_satz) VALUES (?,?,?,?,?,?,?)",
          [$bid, $sort++, $p['bezeichnung'], $p['menge'], $p['einheit'] ?: null, $p['preis_cent'], $p['mwst_satz']]);
    if (function_exists('beleg_status_log_add')) beleg_status_log_add($bid, $status, 'Rechnung aus Positionen importiert (aufgeschlüsselt)' . ($status === 'bezahlt' ? ', als bezahlt' : ''), 'team');
    // Auftragspreis füllen, wenn noch keiner da ist (nicht überschreiben).
    if ((float)($a['gesamt_netto'] ?? 0) <= 0) {
        $menge = (int)($a['menge'] ?? 0);
        $vk = $menge > 0 ? round($s['netto'] / $menge, 4) : 0;
        q("UPDATE auftrag SET gesamt_netto=?, vk_stueck=? WHERE id=?", [round($s['netto'], 2), $vk, $auftrag_id]);
    }
    if ($kid) log_aktivitaet('kunde', $kid, 'team', 'Aufgeschlüsselte Rechnung ' . $nummer . ' zu Auftrag importiert (' . number_format($s['netto'], 2, ',', '.') . ' € netto).', 'beleg', 'auftrag', $auftrag_id);
    return ['ok' => true, 'beleg_id' => $bid, 'netto' => round($s['netto'], 2)];
}

// Ein hochgeladenes Angebot / eine Auftragsbestätigung (auch v3, jede Sprache) per KI auslesen –
// für den Auftrag-Import. Liest Kopf + die EINE Hauptposition (Produkt, Rezeptur, Verpackung, Menge,
// Preis). Rückgabe ['ok'=>true, 'daten'=>[...]] oder ['ok'=>false,'fehler'=>…]. Wirft nie.
function auftrag_import_ki(string $pfad): array {
    require_once __DIR__ . '/ki.php';
    if (!ki_bereit()) return ['ok' => false, 'fehler' => 'KI ist nicht eingerichtet (Einstellungen → KI).'];
    $prompt = "Dies ist ein Angebot oder eine Auftragsbestätigung eines Lohnherstellers für Nahrungsergänzung. "
        . "Lies die Daten aus und gib NUR JSON zurück:\n"
        . '{"kunde_nr":"","kunde_name":"","ab_nummer":"","datum":"","produkt_name":"","darreichungsform":"kapsel",'
        . '"verpackung":"","stueck_je_packung":0,"menge":0,"vk_stueck":0,"gesamt_netto":0,"ust_prozent":19,'
        . '"zutaten":[{"name":"","menge_mg":0}]}' . "\n"
        . "Regeln: produkt_name = Name der Hauptposition (ohne die Rezeptur-Aufzählung). "
        . "zutaten = die in der Positionsbezeichnung aufgeführten Wirkstoffe mit mg je Einheit (z. B. 'NAC 300 mg'). "
        . "darreichungsform eines von kapsel|tablette|softgel|stick|pulver|fluessig. "
        . "verpackung = Behälter-Text, falls genannt (z. B. '150 ml Weithalsglas'), sonst ''. "
        . "stueck_je_packung = Kapseln/Stück je Packung (z. B. 120). menge = Anzahl Packungen (Bestellmenge). "
        . "vk_stueck = Preis je Packung (netto), gesamt_netto = Positionen-Netto gesamt. "
        . "Zahlen mit Punkt als Dezimaltrennzeichen, keine Tausenderpunkte. Nichts erfinden – Unbekanntes leer/0.";
    $r = ki_datei_frage($pfad, $prompt, ['json' => true, 'denken' => true, 'max_tokens' => 4000, 'timeout' => 240, 'zweck' => 'auftrag-import']);
    if (empty($r['ok'])) return ['ok' => false, 'fehler' => (string)($r['fehler'] ?? 'Das Dokument konnte nicht gelesen werden.')];
    $d = is_array($r['daten'] ?? null) ? $r['daten'] : [];
    $num = fn($x) => (float) str_replace(',', '.', (string)$x);
    $zut = [];
    foreach ((array)($d['zutaten'] ?? []) as $z) {
        if (!is_array($z)) continue;
        $n = trim((string)($z['name'] ?? '')); if ($n === '') continue;
        $zut[] = ['name' => mb_substr($n, 0, 190), 'menge_mg' => $num($z['menge_mg'] ?? 0)];
    }
    $formen = ['kapsel','tablette','softgel','stick','pulver','fluessig'];
    $form = strtolower(trim((string)($d['darreichungsform'] ?? 'kapsel')));
    return ['ok' => true, 'daten' => [
        'kunde_nr'   => trim((string)($d['kunde_nr'] ?? '')),
        'kunde_name' => trim((string)($d['kunde_name'] ?? '')),
        'ab_nummer'  => trim((string)($d['ab_nummer'] ?? '')),
        'datum'      => (is_string($d['datum'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['datum'])) ? $d['datum'] : null,
        'produkt_name' => mb_substr(trim((string)($d['produkt_name'] ?? '')), 0, 190),
        'darreichungsform' => in_array($form, $formen, true) ? $form : 'kapsel',
        'verpackung' => trim((string)($d['verpackung'] ?? '')),
        'stueck_je_packung' => (int) round($num($d['stueck_je_packung'] ?? 0)),
        'menge'      => (int) round($num($d['menge'] ?? 0)),
        'vk_stueck'  => round($num($d['vk_stueck'] ?? 0), 4),
        'gesamt_netto' => round($num($d['gesamt_netto'] ?? 0), 2),
        'ust_prozent' => $num($d['ust_prozent'] ?? 0),
        'zutaten'    => $zut,
    ]];
}

// Eine Verpackung (Behälter) anhand eines Freitexts finden (z. B. "150 ml Weithalsglas"). Grobmatch
// über Volumen (ml) + Typwort. Rückgabe item.id oder null.
function verpackung_finden(string $text): ?int {
    $text = trim($text); if ($text === '') return null;
    $mlV = null; if (preg_match('/(\d+(?:[.,]\d+)?)\s*ml/i', $text, $m)) $mlV = (float) str_replace(',', '.', $m[1]);
    foreach (all("SELECT id, name, volumen_ml FROM item WHERE kategorie='verpackung'") as $it) {
        $nameTreffer = mb_stripos($text, (string)$it['name']) !== false || mb_stripos((string)$it['name'], $text) !== false;
        $volTreffer  = $mlV !== null && abs((float)($it['volumen_ml'] ?? 0) - $mlV) < 0.5;
        if ($nameTreffer || ($volTreffer && (mb_stripos($text, 'glas') !== false) === (mb_stripos((string)$it['name'], 'glas') !== false))) return (int)$it['id'];
    }
    return null;
}

// Rezeptur zu Name (+ Zutaten) finden oder neu anlegen. Rückgabe ['id'=>…, 'neu'=>bool].
// Rezeptur-Name normalisieren (Groß/klein, Mehrfach-Leerzeichen, Satzzeichen) – für tolerante Dedup.
function rez_name_norm(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);   // nur Buchstaben/Zahlen behalten, Rest -> Leerzeichen
    return trim((string)preg_replace('/\s+/', ' ', (string)$s));
}
// Rezeptur tolerant finden: exakt -> normalisiert gleich (fängt „A / B" vs „A/B", Groß/klein, Satzzeichen).
// Bevorzugt die des Kunden, sonst kundenneutral. Rückgabe: Zeile (id,nummer,name) oder null.
function rezeptur_finden_fuzzy(string $name, ?int $kunde_id): ?array {
    $name = trim($name); if ($name === '') return null;
    $t = one("SELECT id, nummer, name FROM rezeptur WHERE name=? ORDER BY (kunde_id<=>?) DESC, id LIMIT 1", [$name, $kunde_id]);
    if ($t) return $t;
    $norm = rez_name_norm($name); if ($norm === '') return null;
    foreach (all("SELECT id, nummer, name FROM rezeptur ORDER BY (kunde_id<=>?) DESC, id", [$kunde_id]) as $r)
        if (rez_name_norm((string)$r['name']) === $norm) return $r;
    return null;
}
function rezeptur_finden_oder_anlegen(string $name, string $form, array $zutaten, ?int $kunde_id): array {
    $name = trim($name) !== '' ? mb_substr(trim($name), 0, 190) : 'Rezeptur-Import';
    $treffer = rezeptur_finden_fuzzy($name, $kunde_id);
    if ($treffer) return ['id' => (int)$treffer['id'], 'neu' => false];
    q("INSERT INTO rezeptur (nummer,name,kunde_id,darreichungsform,status,notiz) VALUES (?,?,?,?,?,?)",
      [naechste_nummer('RZ'), $name, $kunde_id ?: null, $form ?: 'kapsel', 'eingefroren', 'Aus Angebot/AB importiert (KI).']);
    $rid = (int) insert_id();
    $sort = 0;
    foreach ($zutaten as $z) {
        $bez = trim((string)($z['name'] ?? '')); if ($bez === '') continue;
        $iid = scalar("SELECT id FROM item WHERE name=? AND kategorie='rohstoff' LIMIT 1", [$bez]);
        q("INSERT INTO rezeptur_zutat (rezeptur_id,item_id,bezeichnung,menge_mg,sort) VALUES (?,?,?,?,?)",
          [$rid, $iid ?: null, mb_substr($bez, 0, 190), (float)($z['menge_mg'] ?? 0), $sort++]);
    }
    return ['id' => $rid, 'neu' => true];
}

// Kunde per Name (und optional Kundennummer) finden oder neu anlegen. Rückgabe ['id'=>…, 'neu'=>bool].
// Für den Angebotsscan: ist der Kunde im Angebot noch nicht im System, wird er angelegt.
// Kunde tolerant finden (für Importe/Scans). Reihenfolge: exakte Nummer → exakte Firma → Kunde-Firma
// ENTHÄLT den gelesenen Namen (gelesen „Pure Health" → Kunde „Pure Health Alliance") → gelesener Name
// enthält eine Kunde-Firma (gelesen „Pure Health Alliance GmbH" → Kunde „Pure Health"). Nur ein VORSCHLAG –
// der Import-Prüfschritt lässt den Kunden ändern. LIKE mit ESCAPE '=' (Backslash crasht die Live-DB).
function kunde_finden_fuzzy(string $name, string $nr = ''): ?array {
    $name = trim($name); $nr = trim($nr);
    if ($nr !== '') { $k = one("SELECT id, firma, kundennummer FROM kunden WHERE kundennummer=? LIMIT 1", [$nr]); if ($k) return $k; }
    if ($name === '') return null;
    $k = one("SELECT id, firma, kundennummer FROM kunden WHERE LOWER(TRIM(firma))=LOWER(TRIM(?)) LIMIT 1", [$name]); if ($k) return $k;
    $esc = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $name);
    $k = one("SELECT id, firma, kundennummer FROM kunden WHERE LOWER(firma) LIKE LOWER(?) ESCAPE '=' ORDER BY CHAR_LENGTH(firma) ASC LIMIT 1", ['%' . $esc . '%']); if ($k) return $k;
    $k = one("SELECT id, firma, kundennummer FROM kunden WHERE CHAR_LENGTH(firma) >= 4 AND LOWER(?) LIKE CONCAT('%', LOWER(firma), '%') ORDER BY CHAR_LENGTH(firma) DESC LIMIT 1", [$name]); if ($k) return $k;
    return null;
}
function kunde_finden_oder_anlegen(string $firma, string $nr = ''): array {
    $firma = trim($firma); $nr = trim($nr);
    if ($nr !== '') {
        $k = scalar("SELECT id FROM kunden WHERE kundennummer=? LIMIT 1", [$nr]);
        if ($k) return ['id' => (int)$k, 'neu' => false];
    }
    if ($firma !== '') {
        $k = scalar("SELECT id FROM kunden WHERE LOWER(firma)=LOWER(?) LIMIT 1", [$firma]);
        if ($k) return ['id' => (int)$k, 'neu' => false];
    }
    if ($firma === '') return ['id' => 0, 'neu' => false];   // ohne Firmennamen keinen Kunden anlegen
    q("INSERT INTO kunden (kundennummer,firma,land,zahlungsart,notiz) VALUES (?,?,?,?,?)",
      [$nr !== '' ? mb_substr($nr, 0, 40) : naechste_nummer('K'), mb_substr($firma, 0, 190), 'DE', 'rechnung', 'Aus Angebotsscan angelegt.']);
    return ['id' => (int) insert_id(), 'neu' => true];
}

// Führendes "AP"-Kürzel (Annapurna-Zuordnung) aus einem Produktnamen entfernen – soll ignoriert werden.
function angebotsscan_name_bereinigen(string $name): string {
    $name = trim($name);
    // "AP ", "AP-", "AP_" oder "AP" direkt vor dem Namen am Anfang entfernen (nur als eigenständiges Präfix).
    $name = preg_replace('/^AP[\s\-_]+/u', '', $name);
    return trim($name);
}

// --- Angebotsscan (System) -------------------------------------------------
// Liest ein (fremdes/altes) Angebot per KI: Produkt/Rezeptur, Preis-Aufschlüsselung UND den Kunden
// (Firma/Kundennummer, Datum, VK je Packung für diesen Kunden). Das führende "AP"-Kürzel im
// Produktnamen (Annapurna-Zuordnung) wird ignoriert/entfernt.
// Rückgabe ['ok'=>true,'daten'=>[produkt_name,darreichungsform,stueck_je_packung,kunde_name,kunde_nr,datum,vk_stueck,menge,zutaten[],preise[]]] oder ['ok'=>false,'fehler'=>…].
function angebotsscan_ki(string $pfad): array {
    require_once __DIR__ . '/ki.php';
    if (!ki_bereit()) return ['ok' => false, 'fehler' => 'KI ist nicht eingerichtet (Einstellungen → KI).'];
    $prompt = "Dies ist ein Angebot für Nahrungsergänzungsmittel (eigenes oder fremdes). Ein Angebot kann MEHRERE "
        . "Produkte/Rezepturen enthalten – erfasse ALLE. Gib NUR JSON zurück:\n"
        . '{"kunde_name":"","kunde_nr":"","datum":"",'
        . '"produkte":[{"produkt_name":"","darreichungsform":"kapsel","stueck_je_packung":0,"verpackung":"",'
        . '"kapselgroesse":"","einheit":"","beschreibung":"",'
        . '"staffeln":[{"menge":0,"vk_stueck":0}],"zutaten":[{"name":"","menge_mg":0}],'
        . '"preise":[{"bezeichnung":"","typ":"herstellung","einzelpreis":0,"menge":0,"einheit":""}]}]}' . "\n"
        . "Regeln: produkte = JEDES im Angebot aufgeführte Produkt als eigener Eintrag (z. B. zwei Rezepturen = zwei Einträge). "
        . "produkt_name = Name des Produkts OHNE die Wirkstoff-Aufzählung. "
        . "Ein führendes 'AP' vor dem Produktnamen ist eine interne Kürzel-Zuordnung und soll WEGGELASSEN werden. "
        . "darreichungsform eines von kapsel|tablette|softgel|stick|pulver|fluessig. "
        . "stueck_je_packung = Kapseln/Stück je Packung (z. B. 120), sonst 0. "
        . "kapselgroesse = die Kapselgröße, falls genannt (z. B. '#2', '#0', '0', '00'); sonst leer. "
        . "einheit = die Einheit aus der Mengenspalte (z. B. 'Stk.', 'Packung', 'kg', 'g', 'L'); bei loser Ware / Bulk oft 'Stk.' oder 'kg'. "
        . "beschreibung = die kompletten Zusatzzeilen UNTER der Bezeichnung WORTGETREU (Wirkstoff-Aufschlüsselung mit mg, Kapselgröße, Füllgewicht, Stückzahl – z. B. 'Bacopa-Extrakt 10:1 150mg; MCC 50mg; #2, ~217mg, 100.000 Kapseln'). "
        . "verpackung = Verpackung/Behälter mit Größe als Freitext, z. B. '150 ml Weithalsglas', 'PET-Dose 120 ml' oder 'Standbodenbeutel 500 g'; leer wenn nicht genannt. "
        . "kunde_name = Firmenname des Angebotsempfängers, kunde_nr = dessen Kundennummer falls genannt (gilt fürs ganze Angebot). "
        . "datum = Angebotsdatum als YYYY-MM-DD. "
        . "staffeln = ALLE Mengen-Staffeln DIESES Produkts: je Staffel menge = Anzahl Packungen und vk_stueck = Preis je Packung (netto). "
        . "Gibt es nur einen Preis, genau eine Staffel. "
        . "zutaten = alle aufgeführten Wirkstoffe dieses Produkts mit mg je Einheit (z. B. 'NAC 300 mg'). "
        . "preise = JEDE Preiszeile EINER Staffel einzeln (Aufschlüsselung): typ eines von "
        . "herstellung|kapsel|verpackung|etikett|zusatz|gesamt. bezeichnung = Originaltext der Zeile. "
        . "einzelpreis = Preis je Einheit (netto), menge = Stück/Packungen, einheit = Text (z. B. 'Packung','Stück'). "
        . "Zahlen mit Punkt als Dezimaltrennzeichen, keine Tausenderpunkte. Nichts erfinden – Unbekanntes leer/0.";
    $r = ki_datei_frage($pfad, $prompt, ['json' => true, 'denken' => true, 'max_tokens' => 6000, 'timeout' => 240, 'zweck' => 'angebotsscan']);
    if (empty($r['ok'])) return ['ok' => false, 'fehler' => (string)($r['fehler'] ?? 'Das Dokument konnte nicht gelesen werden.')];
    $d = is_array($r['daten'] ?? null) ? $r['daten'] : [];
    $num = fn($x) => (float) str_replace(',', '.', (string)$x);
    $formen = ['kapsel','tablette','softgel','stick','pulver','fluessig'];
    $typen  = ['herstellung','kapsel','verpackung','etikett','zusatz','gesamt'];
    // EIN Produkt-Block normalisieren (wird je Produkt aufgerufen).
    $parseProd = function(array $pd) use ($num, $formen, $typen): array {
        $zut = [];
        foreach ((array)($pd['zutaten'] ?? []) as $z) {
            if (!is_array($z)) continue;
            $n = trim((string)($z['name'] ?? '')); if ($n === '') continue;
            $zut[] = ['name' => mb_substr($n, 0, 190), 'menge_mg' => $num($z['menge_mg'] ?? 0)];
        }
        $preise = [];
        foreach ((array)($pd['preise'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $bez = trim((string)($p['bezeichnung'] ?? '')); $ep = $num($p['einzelpreis'] ?? 0);
            if ($bez === '' && $ep <= 0) continue;
            $typ = strtolower(trim((string)($p['typ'] ?? '')));
            $preise[] = ['bezeichnung' => mb_substr($bez, 0, 190), 'typ' => in_array($typ, $typen, true) ? $typ : 'zusatz',
                'einzelpreis' => round($ep, 4), 'menge' => $num($p['menge'] ?? 0), 'einheit' => mb_substr(trim((string)($p['einheit'] ?? '')), 0, 20)];
        }
        $staffeln = [];
        foreach ((array)($pd['staffeln'] ?? []) as $st) {
            if (!is_array($st)) continue;
            $m = (int) round($num($st['menge'] ?? 0)); $v = round($num($st['vk_stueck'] ?? 0), 4);
            if ($m <= 0 && $v <= 0) continue;
            $staffeln[] = ['menge' => $m, 'vk_stueck' => $v];
        }
        $vkEin = round($num($pd['vk_stueck'] ?? 0), 4); $mEin = (int) round($num($pd['menge'] ?? 0));
        if (!$staffeln && ($vkEin > 0 || $mEin > 0)) $staffeln[] = ['menge' => $mEin, 'vk_stueck' => $vkEin];
        usort($staffeln, fn($a, $b) => $a['menge'] <=> $b['menge']);
        if ($vkEin <= 0 && $staffeln) { $vkEin = (float)$staffeln[0]['vk_stueck']; $mEin = (int)$staffeln[0]['menge']; }
        $form = strtolower(trim((string)($pd['darreichungsform'] ?? 'kapsel')));
        return [
            'produkt_name'      => mb_substr(angebotsscan_name_bereinigen((string)($pd['produkt_name'] ?? '')), 0, 190),
            'darreichungsform'  => in_array($form, $formen, true) ? $form : 'kapsel',
            'stueck_je_packung' => (int) round($num($pd['stueck_je_packung'] ?? 0)),
            'verpackung'        => mb_substr(trim((string)($pd['verpackung'] ?? '')), 0, 120),
            'kapselgroesse'     => mb_substr(trim((string)($pd['kapselgroesse'] ?? '')), 0, 20),
            'einheit'           => mb_substr(trim((string)($pd['einheit'] ?? '')), 0, 20),
            'beschreibung'      => mb_substr(trim((string)($pd['beschreibung'] ?? '')), 0, 500),
            'vk_stueck'         => $vkEin,
            'menge'             => $mEin,
            'staffeln'          => $staffeln,
            'zutaten'           => $zut,
            'preise'            => $preise,
        ];
    };
    // Produkte sammeln: neues Format (produkte[]) oder Altformat (Produktfelder direkt in $d).
    $produkteRaw = (isset($d['produkte']) && is_array($d['produkte'])) ? $d['produkte'] : [$d];
    $produkte = [];
    foreach ($produkteRaw as $pd) {
        if (!is_array($pd)) continue;
        $pp = $parseProd($pd);
        if ($pp['produkt_name'] === '' && !$pp['staffeln'] && !$pp['zutaten']) continue;   // leere Blöcke überspringen
        $produkte[] = $pp;
    }
    if (!$produkte) $produkte[] = $parseProd($d);   // nichts erkannt -> wenigstens ein (leerer) Block
    // Top-Level = erstes Produkt (Rückwärtskompatibilität für angebotsscan.php) + Angebotsebene (Kunde/Datum) + produkte[].
    $erst = $produkte[0];
    return ['ok' => true, 'daten' => array_merge($erst, [
        'kunde_name' => mb_substr(trim((string)($d['kunde_name'] ?? '')), 0, 190),
        'kunde_nr'   => mb_substr(trim((string)($d['kunde_nr'] ?? '')), 0, 40),
        'datum'      => (is_string($d['datum'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['datum'])) ? $d['datum'] : null,
        'produkte'   => $produkte,
    ])];
}

// Angebotsscan SPEICHERN (nach dem Match/Vorschau-Schritt). Erwartet die – ggf. vom Nutzer korrigierten –
// ausgelesenen Daten $d (inkl. 'staffeln') und $opt mit dem AUFGELÖSTEN Kunden:
//   kunde_id (int, 0 = kein Kunde), kunde_neu (bool), datei, orig, benutzer_id, bemerkung.
// Legt die Rezeptur an/findet sie, schreibt JE STAFFEL eine Zeile in rezeptur_kundenpreis (idempotent
// je Rezeptur×Kunde×Datum) und die Scan-Zeile. Rückgabe ['ok','scan_id','rezeptur_id','rezeptur_neu','kunde_id','daten'].
function angebotsscan_speichern(array $d, array $opt = []): array {
    $kid     = (int)($opt['kunde_id'] ?? 0);
    $kneu    = !empty($opt['kunde_neu']);
    $datum   = (is_string($d['datum'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['datum'])) ? $d['datum'] : null;
    $stkPck  = (int)($d['stueck_je_packung'] ?? 0);
    $preise  = (array)($d['preise'] ?? []);

    $rez = rezeptur_finden_oder_anlegen((string)($d['produkt_name'] ?? ''), (string)($d['darreichungsform'] ?? 'kapsel'), (array)($d['zutaten'] ?? []), null);

    // Staffeln normalisieren (mind. eine, aus vk_stueck/menge falls leer).
    $staffeln = [];
    foreach ((array)($d['staffeln'] ?? []) as $st) {
        $m = (int)($st['menge'] ?? 0); $v = round((float)($st['vk_stueck'] ?? 0), 4);
        if ($m <= 0 && $v <= 0) continue;
        $staffeln[] = ['menge' => $m, 'vk_stueck' => $v];
    }
    if (!$staffeln) $staffeln[] = ['menge' => (int)($d['menge'] ?? 0), 'vk_stueck' => round((float)($d['vk_stueck'] ?? 0), 4)];
    usort($staffeln, fn($a, $b) => $a['menge'] <=> $b['menge']);
    // Repräsentativer Wert für die Übersicht ("ab VK/Packung") = günstigster Staffelpreis.
    $vkRep = 0.0; foreach ($staffeln as $st) { $v = (float)$st['vk_stueck']; if ($v > 0 && ($vkRep <= 0 || $v < $vkRep)) $vkRep = $v; }

    // Kundenpreis je Staffel schreiben (nur wenn Kunde zugeordnet). Dubletten je (Rezeptur,Kunde,Datum)
    // zuerst entfernen, damit ein erneuter Scan desselben Angebots nicht doppelt anlegt.
    if ($kid > 0) {
        q("DELETE FROM rezeptur_kundenpreis WHERE rezeptur_id=? AND kunde_id=? AND (datum<=>?)", [$rez['id'], $kid, $datum]);
        foreach ($staffeln as $st) {
            q("INSERT INTO rezeptur_kundenpreis (rezeptur_id,kunde_id,datum,vk,stueck_je_packung,menge,preise_json,quelle,scan_id)
               VALUES (?,?,?,?,?,?,?,'angebotsscan',NULL)",
              [$rez['id'], $kid, $datum, $st['vk_stueck'] > 0 ? $st['vk_stueck'] : null,
               $stkPck ?: null, $st['menge'] ?: null, json_encode($preise, JSON_UNESCAPED_UNICODE)]);
        }
        log_aktivitaet('kunde', $kid, 'team', 'Preis aus Angebotsscan erfasst (Rezeptur ' . (int)$rez['id'] . ', ' . count($staffeln) . ' Staffel(n)).', 'rezeptur', 'rezeptur', (int)$rez['id']);
    }

    q("INSERT INTO angebot_scan (produkt_name,darreichungsform,stueck_je_packung,rezeptur_id,rezeptur_neu,kunde_id,kunde_neu,angebot_datum,vk,staffeln_json,preise_json,zutaten_json,datei,original_orig,bemerkung,angelegt_von)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [(string)($d['produkt_name'] ?? ''), (string)($d['darreichungsform'] ?? 'kapsel'), $stkPck ?: null, $rez['id'], $rez['neu'] ? 1 : 0,
       $kid ?: null, $kneu ? 1 : 0, $datum, $vkRep > 0 ? $vkRep : null,
       json_encode($staffeln, JSON_UNESCAPED_UNICODE), json_encode($preise, JSON_UNESCAPED_UNICODE), json_encode((array)($d['zutaten'] ?? []), JSON_UNESCAPED_UNICODE),
       mb_substr((string)($opt['datei'] ?? ''), 0, 255) ?: null, mb_substr((string)($opt['orig'] ?? ''), 0, 255) ?: null,
       mb_substr((string)($opt['bemerkung'] ?? ''), 0, 500) ?: null, (int)($opt['benutzer_id'] ?? 0) ?: null]);
    $sid = (int) insert_id();
    if ($kid > 0) q("UPDATE rezeptur_kundenpreis SET scan_id=? WHERE rezeptur_id=? AND kunde_id=? AND (datum<=>?) AND scan_id IS NULL",
                    [$sid, $rez['id'], $kid, $datum]);
    return ['ok' => true, 'scan_id' => $sid, 'rezeptur_id' => $rez['id'], 'rezeptur_neu' => $rez['neu'], 'kunde_id' => $kid, 'daten' => $d];
}

// Aus den ausgelesenen Import-Daten einen Auftrag anlegen (inkl. Rezeptur/Produkt, falls neu).
// $status: offen|in_produktion|erledigt (erledigt = abgeschlossen/versendet-Sicht). Idempotent über
// import_ref (AB-Nummer + Kunde). Rückgabe ['ok','auftrag_id','produkt_id','rezeptur_id','rezeptur_neu','schon_da'].
function auftrag_aus_import(int $kunde_id, array $d, string $status): array {
    if ($kunde_id <= 0) return ['ok' => false, 'fehler' => 'Kein Kunde gewählt.'];
    $status = in_array($status, ['offen','in_produktion','erledigt'], true) ? $status : 'erledigt';
    $ref = trim((string)($d['ab_nummer'] ?? ''));
    if ($ref !== '') {
        $ex = scalar("SELECT id FROM auftrag WHERE kunde_id=? AND import_ref=? LIMIT 1", [$kunde_id, $ref]);
        if ($ex) return ['ok' => true, 'auftrag_id' => (int)$ex, 'schon_da' => true];
    }
    $rez = rezeptur_finden_oder_anlegen((string)($d['produkt_name'] ?? ''), (string)($d['darreichungsform'] ?? 'kapsel'), (array)($d['zutaten'] ?? []), $kunde_id);
    $stueck = max(0, (int)($d['stueck_je_packung'] ?? 0));
    $verpId = !empty($d['verpackung']) ? verpackung_finden((string)$d['verpackung']) : null;
    $pid = produkt_aus_rezeptur($rez['id'], $stueck > 0 ? $stueck : 1, $verpId, null);
    $menge = max(0, (int)($d['menge'] ?? 0));
    $vk    = round((float)($d['vk_stueck'] ?? 0), 4);
    $netto = round((float)($d['gesamt_netto'] ?? 0), 2);
    if ($netto <= 0 && $vk > 0 && $menge > 0) $netto = round($vk * $menge, 2);
    $datum = (is_string($d['datum'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['datum'])) ? $d['datum'] : gmdate('Y-m-d');
    q("INSERT INTO auftrag (nummer,kunde_id,produkt_id,menge,stueck,verpackung_id,vk_stueck,gesamt_netto,status,status_datum,produkt_bezeichnung,produkt_form,import_ref)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('AB'), $kunde_id, $pid ?: null, $menge, $stueck ?: null, $verpId ?: null, $vk, $netto, $status, $datum,
       mb_substr((string)($d['produkt_name'] ?? ''), 0, 190) ?: null, (string)($d['darreichungsform'] ?? 'kapsel'), ($ref !== '' ? mb_substr($ref, 0, 60) : null)]);
    $aid = (int) insert_id();
    log_aktivitaet('kunde', $kunde_id, 'team', 'Auftrag aus Angebot/AB importiert' . ($ref !== '' ? ' (' . $ref . ')' : '') . '.', 'auftrag', 'auftrag', $aid);
    return ['ok' => true, 'auftrag_id' => $aid, 'produkt_id' => (int)$pid, 'rezeptur_id' => $rez['id'], 'rezeptur_neu' => $rez['neu'], 'schon_da' => false];
}

// Jahresvertrag aus einem Angebot: erzeugt (einmalig) das Kontingent im Status 'wartet_vertrag'.
// Aktiv (abrufbar) wird es erst, wenn der unterschriebene Vertrag hochgeladen UND vom Team
// freigegeben ist. Rueckgabe: ['ok'=>true,'kontingent_id'=>…] oder ['ok'=>false,'fehler'=>…].
// Nur-Lese-Ableitung der Jahresvertrags-Konditionen eines Angebots für die ANZEIGE (persistiert nichts).
// Produktname + Festpreis je Packung aus der Herstellungsposition, wenn der Angebotskopf sie nicht trägt.
function jahresvertrag_konditionen(array $a): array {
    $pid   = (int)($a['produkt_id'] ?? 0);
    $menge = (int)($a['jahresmenge'] ?? 0);
    $vk    = (float)($a['jahres_vk'] ?? 0);
    $name  = ''; $mehrfach = false;
    if (!$pid || $vk <= 0) {
        $herst = null; $gruppen = [];
        foreach (all("SELECT ap.*, r.name AS rez_name FROM angebot_position ap LEFT JOIN rezeptur r ON r.id=ap.rezeptur_id WHERE ap.angebot_id=? ORDER BY ap.sort, ap.id", [(int)($a['id'] ?? 0)]) as $p) {
            if (empty($p['rezeptur_id']) || (int)$p['stueck'] <= 0) continue;
            if (!$herst) $herst = $p;
            $g = trim((string)($p['gruppe'] ?? '')); if ($g !== '') $gruppen[$g] = true;
        }
        $mehrfach = count($gruppen) > 1;
        if ($herst) {
            if ($vk <= 0) $vk = round((int)$herst['preis_cent'] / 100, 4);
            $name = trim((string)(($herst['bezeichnung'] ?? '') ?: ($herst['rez_name'] ?? '')));
        }
    }
    return ['produkt_id' => $pid, 'menge' => $menge, 'vk' => $vk, 'name' => $name, 'mehrfach' => $mehrfach];
}

// Wählbare Optionen eines Jahresvertrags-Angebots (je Konfigurations-Gruppe A/B … eine Zeile) – für die
// Options-Auswahl im Portal. Jede Option: gruppe, name, stueck, vk (Festpreis je Packung), form.
function jahresvertrag_optionen(array $a): array {
    $opts = [];
    foreach (all("SELECT ap.*, r.name AS rez_name, r.darreichungsform AS form FROM angebot_position ap LEFT JOIN rezeptur r ON r.id=ap.rezeptur_id WHERE ap.angebot_id=? ORDER BY ap.sort, ap.id", [(int)($a['id'] ?? 0)]) as $p) {
        if (empty($p['rezeptur_id']) || (int)$p['stueck'] <= 0) continue;
        $g = trim((string)($p['gruppe'] ?? ''));
        if (isset($opts[$g])) continue;   // nur die erste Herstellungsposition je Gruppe
        $opts[$g] = [
            'gruppe' => $g,
            'name'   => trim((string)(($p['bezeichnung'] ?? '') ?: ($p['rez_name'] ?? '') ?: 'Option')),
            'stueck' => (int)$p['stueck'],
            'vk'     => round((int)$p['preis_cent'] / 100, 4),
            'form'   => (string)($p['form'] ?? ''),
        ];
    }
    return array_values($opts);
}

function kontingent_aus_angebot(int $angebot_id, string $unterzeichner = '', ?string $gruppe = null): array {
    $a = one("SELECT * FROM angebot WHERE id=?", [$angebot_id]);
    if (!$a) return ['ok' => false, 'fehler' => 'Angebot nicht gefunden.'];
    if ((int)($a['jahresvertrag'] ?? 0) !== 1) return ['ok' => false, 'fehler' => 'Dieses Angebot ist kein Jahresvertrag.'];
    if (empty($a['kunde_id'])) return ['ok' => false, 'fehler' => 'Jahresvertrag braucht einen Kunden.'];
    $menge = (int)($a['jahresmenge'] ?? 0);
    $vk    = (float)($a['jahres_vk'] ?? 0);
    // Produkt + Festpreis bestimmen: bei einem positionsbasierten Angebot (kein Produkt im Kopf) werden sie
    // – wie beim normalen Auftrag – ERST HIER aus der Herstellungsposition (Rezeptur x Menge je Packung +
    // Verpackung) abgeleitet/angelegt. Bei mehreren Optionen (Gruppen A/B …) bestimmt die vom Kunden
    // gewählte $gruppe, welche Konfiguration der Vertrag wird (Produkt + Festpreis).
    $gruppe = ($gruppe !== null && trim($gruppe) !== '') ? trim($gruppe) : null;
    $produktId = (int)($a['produkt_id'] ?? 0);
    if (!$produktId || $vk <= 0 || $gruppe !== null) {
        angebot_positionen_konfig_nachtragen($angebot_id);
        $hposs = array_values(array_filter(
            all("SELECT * FROM angebot_position WHERE angebot_id=? ORDER BY sort, id", [$angebot_id]),
            fn($p) => !empty($p['rezeptur_id']) && (int)$p['stueck'] > 0));
        $gruppen = array_values(array_unique(array_filter(array_map(fn($p) => trim((string)$p['gruppe']), $hposs), fn($g) => $g !== '')));
        if ($gruppe !== null) {
            // Nur die Positionen der gewählten Option (Gruppe) – wie bei auftrag_aus_positionen.
            $gpos = array_values(array_filter($hposs, fn($p) => trim((string)$p['gruppe']) === $gruppe));
            if (!$gpos) return ['ok' => false, 'fehler' => 'Die gewählte Option wurde nicht gefunden. Bitte erneut wählen.'];
            $herst = $gpos[0];
            $produktId = 0;   // für die gewählte Option immer das passende Produkt bestimmen
        } elseif (count($hposs) > 1 && count($gruppen) > 1) {
            // Mehrere Optionen, aber keine gewählt -> der Kunde muss eine auswählen.
            return ['ok' => false, 'fehler' => 'Dieses Jahresvertrags-Angebot enthält mehrere Optionen – bitte wählen Sie eine Option aus.', 'optionen' => true];
        } else {
            $herst = $hposs[0] ?? null;
        }
        if (isset($herst) && $herst) {
            if (!$produktId) {
                $produktId = (int) produkt_aus_rezeptur((int)$herst['rezeptur_id'], (int)$herst['stueck'],
                                $herst['verpackung_id'] ? (int)$herst['verpackung_id'] : null, null);
                if ($produktId) q("UPDATE angebot SET produkt_id=? WHERE id=?", [$produktId, $angebot_id]);
            }
            // Festpreis je Packung aus der (gewählten) Position – überschreibt bei Options-Wahl den Kopf.
            if ($vk <= 0 || $gruppe !== null) { $vk = round((int)$herst['preis_cent'] / 100, 4); if ($vk > 0) q("UPDATE angebot SET jahres_vk=? WHERE id=?", [$vk, $angebot_id]); }
        }
    }
    if (!$produktId) return ['ok' => false, 'fehler' => 'Jahresvertrag braucht ein Produkt – dem Angebot fehlt eine Rezeptur/Konfiguration. Bitte beim Team melden.'];
    if ($menge < 1 || $vk <= 0) return ['ok' => false, 'fehler' => 'Jahresmenge und Festpreis müssen gesetzt sein.'];
    // Schon vorhanden? (idempotent je Angebot)
    $ex = one("SELECT id FROM kontingent WHERE angebot_id=?", [$angebot_id]);
    if ($ex) return ['ok' => true, 'kontingent_id' => (int)$ex['id'], 'schon_da' => true];
    $mon = (int)($a['jahres_laufzeit_monate'] ?? 12) ?: 12;
    q("INSERT INTO kontingent (kunde_id,produkt_id,angebot_id,gesamt_menge,abgerufen,vk_stueck,gueltig_von,gueltig_bis,status,freigabe_name,freigabe_am,notiz)
       VALUES (?,?,?,?,0,?,CURDATE(),DATE_ADD(CURDATE(), INTERVAL ? MONTH),'wartet_vertrag',?,UTC_TIMESTAMP(),?)",
      [(int)$a['kunde_id'], $produktId, $angebot_id, $menge, $vk, $mon, ($unterzeichner ?: null),
       'Aus Angebot ' . (string)$a['nummer'] . ' (Jahresvertrag).']);
    $kid = insert_id();
    q("UPDATE angebot SET status='bestaetigt' WHERE id=?", [$angebot_id]);
    log_aktivitaet('kunde', (int)$a['kunde_id'], 'kunde', 'Jahresvertrag aus Angebot ' . (string)$a['nummer'] . ' abgeschlossen – wartet auf unterschriebenen Vertrag.', 'kontingent', 'angebot', $angebot_id);
    return ['ok' => true, 'kontingent_id' => $kid];
}

// Kontingent aus einem angenommenen Auftrag: die vereinbarte Menge wird zum abrufbaren Kontingent
// (der Kunde ruft in Teilmengen zum Festpreis ab, statt einer einzigen Riesen-Produktion).
// Der Ursprungsauftrag wird storniert (die Produktion laeuft dann ueber die Abrufe). Idempotent.
// Rueckgabe: ['ok'=>true,'kontingent_id'=>…] oder ['ok'=>false,'fehler'=>…].
function kontingent_aus_auftrag(int $auftrag_id, int $monate = 12): array {
    $a = one("SELECT * FROM auftrag WHERE id=?", [$auftrag_id]);
    if (!$a) return ['ok' => false, 'fehler' => 'Auftrag nicht gefunden.'];
    if (!empty($a['kontingent_id'])) return ['ok' => false, 'fehler' => 'Dieser Auftrag stammt bereits aus einem Kontingent.'];
    if (empty($a['kunde_id']) || empty($a['produkt_id'])) return ['ok' => false, 'fehler' => 'Kontingent braucht Kunde und Produkt.'];
    $menge = (int)($a['menge'] ?? 0); $vk = (float)($a['vk_stueck'] ?? 0);
    if ($menge < 1 || $vk <= 0) return ['ok' => false, 'fehler' => 'Menge und Stückpreis müssen gesetzt sein.'];
    if ((string)($a['status'] ?? '') === 'storniert') return ['ok' => false, 'fehler' => 'Auftrag ist bereits storniert.'];
    // Bereits eine bezahlte Rechnung? Dann nicht umwandeln (Buchhaltung).
    $bezahlt = (int) scalar("SELECT COUNT(*) FROM beleg WHERE auftrag_id=? AND typ='rechnung' AND status='bezahlt'", [$auftrag_id]);
    if ($bezahlt > 0) return ['ok' => false, 'fehler' => 'Zu diesem Auftrag gibt es bereits eine bezahlte Rechnung – nicht umwandelbar.'];
    // Schon einmal umgewandelt? (idempotent über die Notiz-Referenz auf den Auftrag)
    $ref = 'Aus Auftrag ' . (string)$a['nummer'] . ' als Kontingent';
    $ex = one("SELECT id FROM kontingent WHERE kunde_id=? AND produkt_id=? AND notiz LIKE ?", [(int)$a['kunde_id'], (int)$a['produkt_id'], $ref . '%']);
    if ($ex) return ['ok' => true, 'kontingent_id' => (int)$ex['id'], 'schon_da' => true];
    $mon = $monate > 0 ? $monate : 12;
    q("INSERT INTO kontingent (kunde_id,produkt_id,angebot_id,gesamt_menge,abgerufen,vk_stueck,gueltig_von,gueltig_bis,status,notiz)
       VALUES (?,?,?,?,0,?,CURDATE(),DATE_ADD(CURDATE(), INTERVAL ? MONTH),'aktiv',?)",
      [(int)$a['kunde_id'], (int)$a['produkt_id'], ($a['angebot_id'] ?? null) ?: null, $menge, $vk, $mon, $ref . ' angelegt.']);
    $kid = insert_id();
    // Ursprungsauftrag stornieren – produziert wird ueber die Abrufe.
    q("UPDATE auftrag SET status='storniert' WHERE id=?", [$auftrag_id]);
    // Offene/vorbereitete Produktionsauftraege dieses Auftrags abbrechen: produziert wird kuenftig je Abruf
    // (Teilmenge), nicht die volle Jahresmenge. Nur solange noch kein Schritt erledigt ist. Reservierungen frei.
    foreach (all("SELECT id FROM produktionsauftrag WHERE auftrag_id=? AND status IN ('vorbereitung','offen','laufend')
                  AND NOT EXISTS (SELECT 1 FROM produktion_schritt s WHERE s.pa_id=produktionsauftrag.id AND s.erledigt=1)", [$auftrag_id]) as $__pa) {
        auftrag_reservierung_freigeben((int)$__pa['id']);
        q("UPDATE produktionsauftrag SET status='storniert' WHERE id=?", [(int)$__pa['id']]);
    }
    // Automatisch erzeugte, noch nicht bezahlte Rechnung stornieren – abgerechnet wird je Abruf.
    q("UPDATE beleg SET status='storniert' WHERE auftrag_id=? AND typ='rechnung' AND status<>'bezahlt'", [$auftrag_id]);
    bedarf_bump();
    log_aktivitaet('kunde', (int)$a['kunde_id'], 'team', 'Auftrag ' . (string)$a['nummer'] . ' in ein Kontingent (' . $menge . ' Stück, Abruf) umgewandelt.', 'kontingent', 'auftrag', $auftrag_id);
    return ['ok' => true, 'kontingent_id' => $kid];
}

// Status eines Jahresvertrags-Kontingents weiterschalten (Upload/Freigabe/Ablehnung).
function kontingent_status(int $kontingent_id, string $status): bool {
    if (!in_array($status, ['wartet_vertrag', 'wartet_freigabe', 'aktiv', 'beendet'], true)) return false;
    q("UPDATE kontingent SET status=? WHERE id=?", [$status, $kontingent_id]);
    return true;
}

// Abruf aus einem Kontingent (Rahmenvertrag/Jahresvertrag): erzeugt einen Auftrag zum vereinbarten
// Festpreis (+ Rechnung + Produktionsauftrag + Stationen) und schreibt die abgerufene Menge fort.
// Rueckgabe: ['ok'=>true,'auftrag_id'=>…,'rest'=>…] oder ['ok'=>false,'fehler'=>…].
function kontingent_abruf(int $kontingent_id, int $menge): array {
    $k = one("SELECT * FROM kontingent WHERE id=?", [$kontingent_id]);
    if (!$k) return ['ok' => false, 'fehler' => 'Kontingent nicht gefunden.'];
    if (($k['status'] ?? '') !== 'aktiv') return ['ok' => false, 'fehler' => 'Dieses Kontingent ist nicht aktiv.'];
    if (!empty($k['gueltig_bis']) && (string)$k['gueltig_bis'] < gmdate('Y-m-d')) return ['ok' => false, 'fehler' => 'Dieses Kontingent ist abgelaufen.'];
    $rest = (int)$k['gesamt_menge'] - (int)$k['abgerufen'];
    if ($menge < 1) return ['ok' => false, 'fehler' => 'Bitte eine Menge größer 0 abrufen.'];
    if ($menge > $rest) return ['ok' => false, 'fehler' => 'Nur noch ' . $rest . ' verfügbar.'];
    // Mindest-Abrufmenge je Abruf. Ausnahme: die letzte Restmenge (< Mindestmenge) darf voll abgerufen werden.
    $min = (int)($k['min_abruf'] ?? 0);
    if ($min > 0 && $menge < $min && $menge < $rest)
        return ['ok' => false, 'fehler' => 'Mindest-Abrufmenge sind ' . $min . ' je Abruf' . ($rest < $min ? ' (oder die Restmenge ' . $rest . ')' : '') . '.'];

    $kid = (int)$k['kunde_id']; $pid = (int)$k['produkt_id']; $vk = (float)$k['vk_stueck'];
    $netto = round($menge * $vk, 2);
    // Stück je Packung + Behälter aus dem Produkt übernehmen, damit der Abruf-Auftrag vollständig ist.
    $prodK = $pid ? one("SELECT verpackung_id, einheiten_pro_packung FROM produkt WHERE id=?", [$pid]) : null;
    $kStueck = $prodK ? (int)($prodK['einheiten_pro_packung'] ?? 0) : 0;
    $kVerp   = $prodK ? (int)($prodK['verpackung_id'] ?? 0) : 0;
    q("INSERT INTO auftrag (nummer,kunde_id,produkt_id,menge,stueck,verpackung_id,vk_stueck,gesamt_netto,status,kontingent_id) VALUES (?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('AB'), $kid, $pid, $menge, $kStueck ?: null, $kVerp ?: null, $vk, $netto, 'offen', $kontingent_id]);
    $aid = insert_id();
    auftrag_name_snapshot((int)$aid);
    $land = scalar("SELECT land FROM kunden WHERE id=?", [$kid]) ?: 'DE';
    $ustP = (meta_get('kleinunternehmer', '0') === '1' || $land !== 'DE') ? 0.0 : (float) meta_get('ust_inland', 19);
    $ust = round($netto * $ustP / 100, 2); $brutto = $netto + $ust;
    // Rechnung wie im normalen Auto-Weg (auftrag_aus_angebot): MIT Positionen + Sichtbarkeit, sonst hat die
    // Rechnung nur einen Nettobetrag ohne Positionen -> PDF fuer den Kunden nicht baubar (Bug bei Abruf-Rechnungen).
    $sicht = kunde_hat_rechnungsadresse($kid) ? 1 : 0;
    $hinw  = $sicht ? null : 'Rechnungsadresse fehlt – bitte Kundenadresse ergänzen, dann Rechnung neu berechnen.';
    q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum,text,kunde_sichtbar) VALUES (?,?,?,?,?,?,?,?,?,CURDATE(),?,?)",
      [naechste_nummer('RE'), 'rechnung', $aid, $kid, $netto, $ustP, $ust, $brutto, 'offen', $hinw, $sicht]);
    beleg_positionen_materialisieren((int) insert_id(), $ustP);   // Produkt + Glas + Etikett als echte Positionen
    $form = scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$pid]) ?: 'kapsel';
    q("INSERT INTO produktionsauftrag (nummer,auftrag_id,kunde_id,produkt_id,menge,stueck,verpackung_id,produktionsart,status) VALUES (?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('PR'), $aid, $kid, $pid, $menge, $kStueck ?: null, $kVerp ?: null, 'fremd', 'vorbereitung']);
    $paid = insert_id();
    foreach (produktionsschritte_fuer($form, true, false, produktion_wege_aufloesen((int)$pid, (int)$kid)) as $i => $station)
        q("INSERT INTO produktion_schritt (pa_id,station,sort,erledigt) VALUES (?,?,?,0)", [$paid, $station, $i]);
    q("UPDATE kontingent SET abgerufen = abgerufen + ? WHERE id=?", [$menge, $kontingent_id]);
    log_aktivitaet('kunde', $kid, 'kunde', 'Abruf ' . $menge . ' aus Jahresvertrag/Kontingent – Auftrag, Rechnung & Produktionsauftrag erzeugt.', 'auftrag', 'auftrag', $aid);
    return ['ok' => true, 'auftrag_id' => $aid, 'rest' => $rest - $menge];
}

// Ältere Angebote wurden gebaut, bevor die Positionen ihre Konfiguration mitspeicherten.
// Damit auch sie annehmbar sind, tragen wir sie aus der zugehörigen Anfrage nach:
// Rezeptur + Menge je Packung (+ Behälter, falls die Anfrage einen nennt) an die erste Position.
function angebot_positionen_konfig_nachtragen(int $angebot_id): void {
    if (scalar("SELECT COUNT(*) FROM angebot_position WHERE angebot_id=? AND rezeptur_id IS NOT NULL", [$angebot_id])) return;
    $a = one("SELECT anfrage_id FROM angebot WHERE id=?", [$angebot_id]);
    if (!$a || !$a['anfrage_id']) return;
    $an = one("SELECT rezeptur_id, stueck, fuellmenge_g, verpackung_id FROM portal_anfrage WHERE id=?", [(int)$a['anfrage_id']]);
    if (!$an || !$an['rezeptur_id']) return;
    $anForm = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [(int)$an['rezeptur_id']]) ?: 'kapsel';
    $stueck = anfrage_groesse($an['stueck'] ?? null, $an['fuellmenge_g'] ?? null, $anForm);
    if ($stueck <= 0) return;
    $erste = one("SELECT id, gruppe FROM angebot_position WHERE angebot_id=? AND quelle='herstellung' ORDER BY sort, id LIMIT 1", [$angebot_id])
          ?: one("SELECT id, gruppe FROM angebot_position WHERE angebot_id=? ORDER BY sort, id LIMIT 1", [$angebot_id]);
    if (!$erste) return;
    // Behälter: was die Anfrage nennt – sonst der Artikel aus der Verpackungsposition derselben Gruppe.
    $verp = $an['verpackung_id'] ? (int)$an['verpackung_id'] : null;
    if (!$verp) {
        $vp = one("SELECT artikelnr FROM angebot_position WHERE angebot_id=? AND quelle='verpackung'
                   AND (gruppe <=> ?) AND artikelnr <> '' ORDER BY sort, id LIMIT 1", [$angebot_id, $erste['gruppe']]);
        if ($vp) $verp = (int) scalar("SELECT id FROM item WHERE artikelnummer=? AND COALESCE(verpackung_rolle,'primaer')='primaer' LIMIT 1", [$vp['artikelnr']]) ?: null;
    }
    q("UPDATE angebot_position SET rezeptur_id=?, stueck=?, verpackung_id=? WHERE id=?",
      [(int)$an['rezeptur_id'], $stueck, $verp, (int)$erste['id']]);
}

// Aus den wählbaren Optionen die Staffel „Preis je fertiges Produkt" bauen – dieselbe Struktur,
// die das Angebots-PDF bei Matrix-Angeboten zeigt: Name, Stück je Packung, Zeilen je Bestellmenge.
// Gleiche Konfiguration mit verschiedenen Bestellmengen wird zu einem Block zusammengefasst.
function angebot_staffel_aus_optionen(array $optionen): array {
    $bloecke = [];
    foreach ($optionen as $o) {
        $istFuell = !empty($o['ist_fuell']);
        $name = trim($o['titel'] . ($o['groesse'] !== '' ? ' · ' . $o['groesse'] : '') . ($o['verpackung'] !== '' ? ' · ' . $o['verpackung'] : ''));
        $packCent = (int) round($o['pro_pkg'] * 100);
        if (!isset($bloecke[$name]))
            $bloecke[$name] = ['name' => $name, 'mpp' => ($istFuell || $o['stueck'] <= 0) ? 0 : (int)$o['stueck'], 'rows' => []];
        $bloecke[$name]['rows'][] = [
            'ab'          => (int) round($o['pakete']),
            'stueck_cent' => (!$istFuell && $o['stueck'] > 0) ? (int) round($packCent / $o['stueck']) : null,
            'pack_cent'   => $packCent,
        ];
    }
    foreach ($bloecke as $n => $b) usort($bloecke[$n]['rows'], fn($x, $y) => $x['ab'] <=> $y['ab']);
    return array_values($bloecke);
}
// Ein Positions-Angebot in WÄHLBARE OPTIONEN zerlegen: je Gruppe (A, B, C …) eine Konfiguration
// (Rezeptur x Menge je Packung + Verpackung) mit Anzahl Packungen und Preis je Packung.
// Das ist die Ansicht, aus der der Kunde auswählt – eine Zeile je Größe, wie bei der Preismatrix.
// Positionen ohne Gruppe sind Zuschläge und gelten unabhängig von der Wahl.
function angebot_optionen(int $angebot_id): array {
    angebot_positionen_konfig_nachtragen($angebot_id);   // ältere Angebote nachrüsten, sonst fehlt die Größe
    $opt = []; $extra = [];
    foreach (all("SELECT * FROM angebot_position WHERE angebot_id=? ORDER BY sort, id", [$angebot_id]) as $p) {
        $g = trim((string)($p['gruppe'] ?? ''));
        if ($g === '') { $extra[] = $p; continue; }
        if (!isset($opt[$g])) $opt[$g] = ['gruppe'=>$g, 'titel'=>'', 'beschreibung'=>'', 'groesse'=>'', 'verpackung'=>'',
                                          'pakete'=>0.0, 'netto'=>0.0, 'pro_pkg'=>0.0, 'einheit'=>'',
                                          'rezeptur_id'=>null, 'stueck'=>0, 'verpackung_id'=>null];
        $opt[$g]['netto'] += (float)$p['menge'] * (int)$p['preis_cent'] / 100;
        if ($p['quelle'] === 'herstellung' || ($opt[$g]['titel'] === '' && $p['quelle'] !== 'verpackung')) {
            $opt[$g]['titel']        = preg_replace('/^[A-Z]\)\s*/', '', (string)$p['bezeichnung']);
            $opt[$g]['beschreibung'] = (string)$p['beschreibung'];
            $opt[$g]['pakete']       = (float)$p['menge'];
            $opt[$g]['einheit']      = (string)$p['einheit'];
            $opt[$g]['rezeptur_id']  = $p['rezeptur_id'] ? (int)$p['rezeptur_id'] : null;
            $opt[$g]['stueck']       = (int)$p['stueck'];
            $opt[$g]['verpackung_id']= $p['verpackung_id'] ? (int)$p['verpackung_id'] : null;
        }
        // Alle Verpackungsteile nennen (Behälter, Deckel, Etikett) – sie stecken im Preis,
        // also soll auch dranstehen, was der Kunde dafür bekommt. Rollen-Präfix weg, nur der Name.
        if ($p['quelle'] === 'verpackung') {
            $teil = trim(preg_replace('/^([A-Z]\)\s*)?(Verpackung|Deckel|Etikett|Karton|Beipack):?\s*/u', '', (string)$p['bezeichnung']));
            if ($teil !== '' && strpos($opt[$g]['verpackung'], $teil) === false)
                $opt[$g]['verpackung'] = $opt[$g]['verpackung'] === '' ? $teil : $opt[$g]['verpackung'] . ' · ' . $teil;
        }
    }
    foreach ($opt as $g => $o) {
        $form = $o['rezeptur_id'] ? (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$o['rezeptur_id']]) : '';
        $opt[$g]['groesse'] = ($o['stueck'] > 0 && $form !== '') ? form_groessen_label($form, (float)$o['stueck']) : '';
        $opt[$g]['pro_pkg'] = $o['pakete'] > 0 ? $o['netto'] / $o['pakete'] : 0.0;
        $opt[$g]['form']      = $form;
        $opt[$g]['ist_fuell'] = $form !== '' && form_ist_fuellmenge($form);   // Größe ist eine Füllmenge (g/ml) -> kein Stückpreis
        $opt[$g]['waehlbar'] = $o['rezeptur_id'] && $o['stueck'] > 0;   // ohne Konfiguration kein Knopf
    }
    return ['optionen' => array_values($opt), 'extra' => $extra];
}
// Auftrag aus einem Angebot, das aus POSITIONEN besteht (kein Produkt im Kopf, keine Preismatrix) –
// der Weg für „Kunde hat eine Rezeptur, daraus soll ein Produkt werden".
// ERST HIER entsteht das Produkt: Rezeptur x Menge je Packung + Verpackung, genau wie angeboten.
// Die Konfiguration steht an der Herstellungsposition (rezeptur_id/stueck/verpackung_id).
function auftrag_aus_positionen(int $angebot_id, ?string $gruppe = null): ?int {
    $a = one("SELECT * FROM angebot WHERE id=? AND status='gesendet'", [$angebot_id]);
    if (!$a) return null;
    if (($v = scalar("SELECT id FROM auftrag WHERE angebot_id=?", [$angebot_id]))) return (int)$v;   // idempotent
    $pos = all("SELECT * FROM angebot_position WHERE angebot_id=? ORDER BY sort, id", [$angebot_id]);
    if (!$pos) return null;
    angebot_positionen_konfig_nachtragen($angebot_id);
    $pos = all("SELECT * FROM angebot_position WHERE angebot_id=? ORDER BY sort, id", [$angebot_id]);
    // Der Kunde hat EINE Option gewählt: nur deren Gruppe plus die gruppenlosen Zuschläge zählen.
    if ($gruppe !== null && $gruppe !== '')
        $pos = array_values(array_filter($pos, fn($p) => trim((string)$p['gruppe']) === $gruppe || trim((string)$p['gruppe']) === ''));
    if (!$pos) return null;
    $herst = null;
    foreach ($pos as $p) if (!empty($p['rezeptur_id']) && (int)$p['stueck'] > 0) { $herst = $p; break; }
    if (!$herst) return null;   // ohne bekannte Konfiguration kein Produkt und kein Auftrag

    $pid = produkt_aus_rezeptur((int)$herst['rezeptur_id'], (int)$herst['stueck'],
                                $herst['verpackung_id'] ? (int)$herst['verpackung_id'] : null,
                                $a['produkt_id'] ? (int)$a['produkt_id'] : null);
    if (!$pid) return null;

    $menge = (int) round((float)$herst['menge']);                       // Anzahl Packungen
    $netto = 0.0; foreach ($pos as $p) $netto += (float)$p['menge'] * (int)$p['preis_cent'] / 100;
    $vkStk = round((int)$herst['preis_cent'] / 100, 4);                 // Herstellung je Packung

    q("INSERT INTO auftrag (nummer,angebot_id,kunde_id,produkt_id,menge,stueck,verpackung_id,vk_stueck,gesamt_netto,status)
       VALUES (?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('AB'), $angebot_id, $a['kunde_id'], $pid, $menge, (int)$herst['stueck'],
       $herst['verpackung_id'] ? (int)$herst['verpackung_id'] : null, $vkStk, round($netto, 2), 'offen']);
    $aid = insert_id();
    auftrag_name_snapshot((int)$aid);
    q("UPDATE angebot SET status='bestaetigt' WHERE id=?", [$angebot_id]);
    q("INSERT IGNORE INTO angebot_produkt (angebot_id,produkt_id,stueck,verpackung_id) VALUES (?,?,?,?)",
      [$angebot_id, $pid, (int)$herst['stueck'], $herst['verpackung_id'] ? (int)$herst['verpackung_id'] : null]);

    $land = scalar("SELECT land FROM kunden WHERE id=?", [$a['kunde_id']]) ?: 'DE';
    $ustP = (meta_get('kleinunternehmer', '0') === '1' || $land !== 'DE') ? 0.0 : (float) meta_get('ust_inland', 19);
    $ust = round($netto * $ustP / 100, 2);
    q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum)
       VALUES (?,?,?,?,?,?,?,?,?,CURDATE())",
      [naechste_nummer('RE'), 'rechnung', $aid, $a['kunde_id'], round($netto, 2), $ustP, $ust, round($netto + $ust, 2), 'offen']);

    $form = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [(int)$herst['rezeptur_id']]) ?: 'kapsel';
    q("INSERT INTO produktionsauftrag (nummer,auftrag_id,kunde_id,produkt_id,menge,stueck,verpackung_id,produktionsart,status) VALUES (?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('PR'), $aid, $a['kunde_id'], $pid, $menge, (int)$herst['stueck'],
       $herst['verpackung_id'] ? (int)$herst['verpackung_id'] : null, 'fremd', 'vorbereitung']);
    $paid = insert_id();
    foreach (produktionsschritte_fuer($form, true, false, produktion_wege_aufloesen((int)$pid, (int)($a['kunde_id'] ?? 0))) as $i => $station)
        q("INSERT INTO produktion_schritt (pa_id,station,sort,erledigt) VALUES (?,?,?,0)", [$paid, $station, $i]);

    if ($a['kunde_id']) log_aktivitaet('kunde', (int)$a['kunde_id'], 'kunde',
        'Angebot ' . $a['nummer'] . ' angenommen – Produkt ' . scalar("SELECT nummer FROM produkt WHERE id=?", [$pid])
        . ' angelegt, Auftrag + Rechnung + Produktion erzeugt.', 'auftrag', 'auftrag', $aid);
    return $aid;
}

// Auftrag aus einer gewählten Matrix-Zelle (Stückzahl je Packung × Bestellmenge × Verpackung). VK wird serverseitig aus der Preismatrix geprüft.
function auftrag_aus_zelle(int $angebot_id, int $stueck, int $verp_id, int $bestellmenge): ?int {
    $a = one("SELECT * FROM angebot WHERE id=?", [$angebot_id]);
    if (!$a || !$a['produkt_id']) return null;
    if (($v = scalar("SELECT id FROM auftrag WHERE angebot_id=?", [$angebot_id]))) return (int)$v;   // idempotent
    // VK aus der Matrix holen (nicht dem Client trauen), Kundenrabatt drauf
    $vkBasis = scalar("SELECT vk_preis FROM produkt_preis WHERE produkt_id=? AND stueck=? AND verpackung_id=? AND bestellmenge=? LIMIT 1",
                      [(int)$a['produkt_id'], $stueck, $verp_id, $bestellmenge]);
    if ($vkBasis === false || $vkBasis === null) return null;   // Zelle nicht machbar
    $vk = round(vk_fuer_kunde((float)$vkBasis, (int)$a['kunde_id']), 4);   // Herstellung je Packung (für vk_stueck)
    $menge = $bestellmenge;
    // Rezeptur x gewählte Menge + gewählter Behälter = das Produkt, das hier bestellt wird.
    // Ohne diesen Schritt zeigt der Auftrag auf das Vorlage-Produkt, und Produktion/Einkauf rechnen
    // mit dessen Packungsgröße und Verpackung statt mit der bestellten.
    $bestellt = produkt_variante_id((int)$a['produkt_id'], $stueck, $verp_id) ?: (int)$a['produkt_id'];
    $netto = angebot_zelle_netto_cent((int)$a['produkt_id'], $stueck, $bestellmenge, (int)$a['kunde_id'], $verp_id) / 100;   // Herstellung + Verpackung der Zelle, belegkonform gerundet
    q("INSERT INTO auftrag (nummer,angebot_id,kunde_id,produkt_id,menge,stueck,verpackung_id,vk_stueck,gesamt_netto,status)
       VALUES (?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('AB'), $angebot_id, $a['kunde_id'], $bestellt, $menge, $stueck, $verp_id, $vk, $netto, 'offen']);
    $aid = insert_id();
    auftrag_name_snapshot((int)$aid);
    q("INSERT IGNORE INTO angebot_produkt (angebot_id,produkt_id,stueck,verpackung_id) VALUES (?,?,?,?)",
      [$angebot_id, $bestellt, $stueck, $verp_id]);   // Preis dieses Produkts ist für diesen Kunden freigegeben
    $land = scalar("SELECT land FROM kunden WHERE id=?", [$a['kunde_id']]) ?: 'DE';
    $ustP = (meta_get('kleinunternehmer', '0') === '1' || $land !== 'DE') ? 0.0 : (float) meta_get('ust_inland', 19);
    $ust = round($netto * $ustP / 100, 2); $brutto = $netto + $ust;
    q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum)
       VALUES (?,?,?,?,?,?,?,?,?,CURDATE())",
      [naechste_nummer('RE'), 'rechnung', $aid, $a['kunde_id'], $netto, $ustP, $ust, $brutto, 'offen']);
    $form = scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$bestellt]) ?: 'kapsel';
    // Standard = Fremdproduktion (verkürzter Weg); auf Eigenproduktion umstellbar im Produktions-Detail.
    q("INSERT INTO produktionsauftrag (nummer,auftrag_id,kunde_id,produkt_id,menge,stueck,verpackung_id,produktionsart,status) VALUES (?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('PR'), $aid, $a['kunde_id'], $bestellt, $menge, $stueck, $verp_id, 'fremd', 'vorbereitung']);
    $paid = insert_id();
    foreach (produktionsschritte_fuer($form, true, false, produktion_wege_aufloesen((int)$bestellt, (int)($a['kunde_id'] ?? 0))) as $i => $station) q("INSERT INTO produktion_schritt (pa_id,station,sort,erledigt) VALUES (?,?,?,0)", [$paid, $station, $i]);
    if ($a['kunde_id']) log_aktivitaet('kunde', (int)$a['kunde_id'], 'team', 'Angebot bestätigt (' . $stueck . ' Stück/Pkg × ' . $menge . '), Auftrag + Rechnung + Produktion erzeugt.', 'auftrag', 'auftrag', $aid);
    return $aid;
}

// Produktionsauftrag zu einem bestehenden Auftrag nachtraeglich anlegen (Reparatur/Recovery): manche
// Auftraege haben – aus aelteren Import-/Fehlerlaeufen – keinen Produktionsauftrag und sind dadurch
// blockiert (kein Materialbedarf, keine Produktion). Idempotent: existiert schon einer, wird er zurueck-
// gegeben. $art = 'eigen' (voller Weg mit Rohstoffen) oder 'fremd' (Zukauf). Rueckgabe: pa_id oder null.
function produktionsauftrag_aus_auftrag(int $auftrag_id, string $art = 'eigen'): ?int {
    $a = one("SELECT * FROM auftrag WHERE id=?", [$auftrag_id]);
    if (!$a) return null;
    $ex = (int) scalar("SELECT id FROM produktionsauftrag WHERE auftrag_id=?", [$auftrag_id]);
    if ($ex) return $ex;
    $pid = (int)$a['produkt_id'];
    if ($pid <= 0) return null;
    $art  = $art === 'fremd' ? 'fremd' : 'eigen';
    $form = scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$pid]) ?: 'kapsel';
    q("INSERT INTO produktionsauftrag (nummer,auftrag_id,kunde_id,produkt_id,menge,stueck,verpackung_id,produktionsart,status) VALUES (?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('PR'), $auftrag_id, $a['kunde_id'], $pid, (int)$a['menge'], (int)$a['stueck'], $a['verpackung_id'] ?: null, $art, 'vorbereitung']);
    $paid = (int) insert_id();
    foreach (produktionsschritte_fuer($form, $art === 'fremd', false, produktion_wege_aufloesen((int)$pid, (int)($a['kunde_id'] ?? 0))) as $i => $station)
        q("INSERT INTO produktion_schritt (pa_id,station,sort,erledigt) VALUES (?,?,?,0)", [$paid, $station, $i]);
    if ($a['kunde_id']) log_aktivitaet('kunde', (int)$a['kunde_id'], 'team',
        'Produktionsauftrag ' . (string) scalar("SELECT nummer FROM produktionsauftrag WHERE id=?", [$paid]) . ' nachträglich angelegt (' . $art . ').', 'auftrag', 'auftrag', $auftrag_id);
    return $paid;
}

// Zentrale Protokoll-Funktion – von jedem Modul aufrufbar, für jedes Objekt. Zeit als UTC.
function log_aktivitaet(string $objekt_typ, int $objekt_id, string $akteur, string $text,
                        string $typ = '', string $ref_typ = '', int $ref_id = 0): void {
    q("INSERT INTO aktivitaet (objekt_typ,objekt_id,akteur,typ,text,ref_typ,ref_id,erstellt) VALUES (?,?,?,?,?,?,?,?)",
      [$objekt_typ, $objekt_id, $akteur, $typ ?: null, $text, $ref_typ ?: null, $ref_id ?: null, gmdate('Y-m-d H:i:s')]);
}

function verlauf_fuer(string $objekt_typ, int $objekt_id): array {
    return all("SELECT * FROM aktivitaet WHERE objekt_typ=? AND objekt_id=? ORDER BY erstellt ASC, id ASC",
               [$objekt_typ, $objekt_id]);
}

// --- Aufgaben (Werk) ---
function prio_liste(): array { return [1 => 'Hoch', 2 => 'Normal', 3 => 'Niedrig']; }

// Aufgabe anlegen. $zugewiesen_an NULL = Team.
function aufgabe_neu(string $titel, string $beschreibung, int $prio, ?int $zugewiesen_an, ?string $faellig, ?int $erstellt_von, string $ref_typ = '', int $ref_id = 0): int {
    q("INSERT INTO aufgabe (titel,beschreibung,prio,zugewiesen_an,erstellt_von,faellig,ref_typ,ref_id,angelegt)
       VALUES (?,?,?,?,?,?,?,?,?)",
      [$titel, $beschreibung ?: null, max(1, min(3, $prio)), $zugewiesen_an, $erstellt_von, $faellig ?: null,
       $ref_typ ?: null, $ref_id ?: null, gmdate('Y-m-d H:i:s')]);
    return (int) insert_id();
}
// Offene Aufgaben für einen Benutzer: ihm zugewiesen ODER Team (NULL). Sortiert nach Prio, dann Fälligkeit.
function aufgaben_fuer_benutzer(int $uid, string $status = 'offen'): array {
    return all("SELECT a.*, u.name AS zuw_name, e.name AS ersteller_name
                FROM aufgabe a LEFT JOIN benutzer u ON u.id=a.zugewiesen_an LEFT JOIN benutzer e ON e.id=a.erstellt_von
                WHERE a.status=? AND (a.zugewiesen_an=? OR a.zugewiesen_an IS NULL)
                ORDER BY a.prio ASC, (a.faellig IS NULL), a.faellig ASC, a.angelegt ASC", [$status, $uid]);
}
function aufgabe_offen_zahl(int $uid): int {
    return (int) scalar("SELECT COUNT(*) FROM aufgabe WHERE status='offen' AND (zugewiesen_an=? OR zugewiesen_an IS NULL)", [$uid]);
}
function aufgabe_erledigen(int $id, ?int $uid): void {
    q("UPDATE aufgabe SET status='erledigt', erledigt_am=?, erledigt_von=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $uid, $id]);
}
function aufgabe_wieder_offen(int $id): void {
    q("UPDATE aufgabe SET status='offen', erledigt_am=NULL, erledigt_von=NULL WHERE id=?", [$id]);
}
// Aufgabe aus dem Team-Pool übernehmen (sich selbst zuweisen)
function aufgabe_uebernehmen(int $id, int $uid): void {
    q("UPDATE aufgabe SET zugewiesen_an=? WHERE id=? AND status='offen'", [$uid, $id]);
}

// Statusverlauf eines Belegs protokollieren (Zeit als UTC). akteur = Anzeigename oder 'System'/'team'.
function beleg_status_log_add(int $beleg_id, string $status, string $notiz = '', string $akteur = 'System'): void {
    q("INSERT INTO beleg_status_log (beleg_id,status,notiz,akteur,angelegt) VALUES (?,?,?,?,?)",
      [$beleg_id, $status, $notiz ?: null, $akteur ?: null, gmdate('Y-m-d H:i:s')]);
}

// Hinterlegte Bankkonten (aus Einstellungen). Liefert nur befüllte Konten: [['key'=>'de','label'=>'…'], …].
// Zusätzliche, frei anlegbare Bankkonten (app_meta 'bank_konten_extra', JSON-Liste [{id,name,iban,bic}, …]).
function bank_konten_extra(): array {
    $d = json_decode((string) meta_get('bank_konten_extra', ''), true);
    return is_array($d) ? array_values(array_filter($d, fn($b) => is_array($b) && !empty($b['id']))) : [];
}
function bank_konten(): array {
    $out = [];
    foreach ([['de','bank_de_name','bank_de_iban'], ['int','bank_int_name','bank_int_iban']] as $kf) {
        [$key, $nk, $ik] = $kf;
        $name = trim((string) meta_get($nk, '')); $iban = trim((string) meta_get($ik, ''));
        if ($name === '' && $iban === '') continue;
        $tail = $iban !== '' ? ' · …' . substr(preg_replace('/\s+/', '', $iban), -4) : '';
        $out[] = ['key' => $key, 'label' => ($name ?: strtoupper($key)) . $tail];
    }
    // Weitere, frei angelegte Konten (beliebig viele) – wichtig für die Buchhaltung (wohin hat der Kunde überwiesen).
    foreach (bank_konten_extra() as $b) {
        $name = trim((string)($b['name'] ?? '')); $iban = trim((string)($b['iban'] ?? ''));
        if ($name === '' && $iban === '') continue;
        $tail = $iban !== '' ? ' · …' . substr(preg_replace('/\s+/', '', $iban), -4) : '';
        $out[] = ['key' => (string)$b['id'], 'label' => ($name ?: 'Bank') . $tail];
    }
    return $out;
}
// Anzeigename eines gespeicherten Kontos anhand des key/Werts (Fallback: der gespeicherte Wert selbst).
function bank_konto_label(?string $konto): string {
    if (!$konto) return '';
    foreach (bank_konten() as $bk) if ($bk['key'] === $konto) return $bk['label'];
    return $konto;
}
// Volle Details eines Kontos (name/iban/bic) zu einem key – deckt 'de', 'int' UND die frei angelegten ab.
// Für PDF/Buchhaltung: welches Konto steht auf der Rechnung bzw. wohin wurde überwiesen.
function bank_konto_details(?string $key): ?array {
    if (!$key) return null;
    if ($key === 'de')  return ['name'=>trim((string)meta_get('bank_de_name','')),  'iban'=>trim((string)meta_get('bank_de_iban','')),  'bic'=>trim((string)meta_get('bank_de_bic',''))];
    if ($key === 'int') return ['name'=>trim((string)meta_get('bank_int_name','')), 'iban'=>trim((string)meta_get('bank_int_iban','')), 'bic'=>trim((string)meta_get('bank_int_bic',''))];
    foreach (bank_konten_extra() as $b) if ((string)($b['id'] ?? '') === $key)
        return ['name'=>trim((string)($b['name'] ?? '')), 'iban'=>trim((string)($b['iban'] ?? '')), 'bic'=>trim((string)($b['bic'] ?? ''))];
    return null;
}

function zahlungen_fuer(int $beleg_id): array {
    return all("SELECT * FROM zahlung WHERE beleg_id=? ORDER BY datum ASC, id ASC", [$beleg_id]);
}
function zahlung_summe(int $beleg_id): float {
    return (float) scalar("SELECT COALESCE(SUM(betrag),0) FROM zahlung WHERE beleg_id=?", [$beleg_id]);
}
// Abgeleiteter Zahlstatus aus Summe der Eingänge vs. Brutto. 'storniert' bleibt erhalten.
// Rückgabe: ['status'=>offen|teilbezahlt|bezahlt|storniert, 'bezahlt'=>float, 'rest'=>float, 'brutto'=>float]
function beleg_zahlstatus(array $beleg): array {
    $brutto = (float) $beleg['brutto'];
    $bezahlt = zahlung_summe((int) $beleg['id']);
    $rest = round($brutto - $bezahlt, 2);
    if (($beleg['status'] ?? '') === 'storniert') $status = 'storniert';
    elseif ($bezahlt <= 0.005)                    $status = 'offen';
    elseif ($rest > 0.005)                        $status = 'teilbezahlt';
    else                                          $status = 'bezahlt';
    return ['status' => $status, 'bezahlt' => $bezahlt, 'rest' => max(0, $rest), 'brutto' => $brutto];
}
// Zahlungseingang erfassen + Status automatisch nachziehen + Statusverlauf schreiben.
function zahlung_erfassen(int $beleg_id, float $betrag, ?string $datum, ?string $konto, ?string $art, string $notiz = '', string $akteur = 'System'): void {
    q("INSERT INTO zahlung (beleg_id,betrag,datum,konto,art,notiz,akteur,angelegt) VALUES (?,?,?,?,?,?,?,?)",
      [$beleg_id, $betrag, $datum ?: null, $konto ?: null, $art ?: null, $notiz ?: null, $akteur ?: null, gmdate('Y-m-d H:i:s')]);
    $b = one("SELECT * FROM beleg WHERE id=?", [$beleg_id]);
    if (!$b) return;
    $zs = beleg_zahlstatus($b);
    beleg_status_verlauf($beleg_id);   // Backfill „erstellt" sicherstellen
    $euro = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
    $wann = $datum ? date('d.m.Y', strtotime($datum)) : 'ohne Datum';
    $kt = bank_konto_label($konto);
    $txt = 'Zahlung ' . $euro($betrag) . ' (Valuta ' . $wann . ($kt ? ', ' . $kt : '') . ')'
         . ($zs['status'] === 'teilbezahlt' ? ' – Rest ' . $euro($zs['rest']) : '');
    if ($zs['status'] !== ($b['status'] ?? '')) q("UPDATE beleg SET status=? WHERE id=?", [$zs['status'], $beleg_id]);
    beleg_status_log_add($beleg_id, $zs['status'], $txt, $akteur);
    if ($zs['status'] === 'bezahlt' && $b['kunde_id']) log_aktivitaet('kunde', (int)$b['kunde_id'], 'team', 'Rechnung ' . $b['nummer'] . ' vollständig bezahlt.', 'beleg', 'beleg', $beleg_id);
}

// Statusverlauf lesen; legt bei fehlendem Verlauf einmalig einen „erstellt"-Eintrag aus beleg.angelegt an (Backfill für Altbelege).
function beleg_status_verlauf(int $beleg_id): array {
    $rows = all("SELECT * FROM beleg_status_log WHERE beleg_id=? ORDER BY angelegt ASC, id ASC", [$beleg_id]);
    if (!$rows) {
        $b = one("SELECT angelegt FROM beleg WHERE id=?", [$beleg_id]);
        if ($b) {
            q("INSERT INTO beleg_status_log (beleg_id,status,notiz,akteur,angelegt) VALUES (?,?,?,?,?)",
              [$beleg_id, 'erstellt', 'Beleg erstellt', 'System', $b['angelegt']]);
            $rows = all("SELECT * FROM beleg_status_log WHERE beleg_id=? ORDER BY angelegt ASC, id ASC", [$beleg_id]);
        }
    }
    return $rows;
}

// ===== Gutschrift / Storno-Rechnung =====
// Positionen eines Belegs im build_beleg_pdf-Format.
function beleg_positionen(int $beleg_id): array {
    $out = [];
    foreach (all("SELECT * FROM beleg_position WHERE beleg_id=? ORDER BY sort, id", [$beleg_id]) as $p) {
        $out[] = [
            'artikelnr'   => (string)($p['artikelnr'] ?? ''),
            'bezeichnung' => (string)$p['bezeichnung'],
            'beschreibung'=> (string)($p['beschreibung'] ?? ''),
            'menge'       => (float)$p['menge'],
            'einheit'     => (string)($p['einheit'] ?? ''),
            'preis_cent'  => (int)$p['preis_cent'],
            'ek_cent'     => 0,
            'mwst_satz'   => (float)$p['mwst_satz'],
        ];
    }
    return $out;
}
// Summen (netto/ust/brutto in EUR) aus Positionen – je Position belegkonform auf Cent gerundet.
function beleg_summen_aus_positionen(array $positionen): array {
    $nettoCent = 0; $ustCent = 0;
    foreach ($positionen as $p) {
        $zeileCent = (int) round((float)$p['menge'] * (int)$p['preis_cent']);
        $nettoCent += $zeileCent;
        $ustCent   += (int) round($zeileCent * (float)($p['mwst_satz'] ?? 0) / 100);
    }
    return ['netto'=>$nettoCent/100, 'ust'=>$ustCent/100, 'brutto'=>($nettoCent+$ustCent)/100];
}
// Generische Gutschrift/Storno-Rechnung anlegen. Positionen wie beim Angebot (preis_cent bei Gutschrift negativ). Gibt Beleg-ID.
function gutschrift_erstellen(int $kunde_id, ?string $datum, array $positionen, string $grund = '', ?int $storno_von = null, ?int $auftrag_id = null): int {
    $s = beleg_summen_aus_positionen($positionen);
    $ustP = 0.0;
    foreach ($positionen as $p) if ((float)($p['mwst_satz'] ?? 0) > 0) { $ustP = (float)$p['mwst_satz']; break; }
    q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum,storno_von_id,grund)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('GS'), 'gutschrift', $auftrag_id ?: null, $kunde_id ?: null,
       $s['netto'], $ustP, $s['ust'], $s['brutto'], 'erstellt', $datum ?: date('Y-m-d'), $storno_von ?: null, ($grund !== '' ? $grund : null)]);
    $bid = insert_id();
    $sort = 0;
    foreach ($positionen as $p) {
        if (trim((string)($p['bezeichnung'] ?? '')) === '') continue;
        q("INSERT INTO beleg_position (beleg_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,mwst_satz) VALUES (?,?,?,?,?,?,?,?,?)",
          [$bid, $sort++, trim((string)($p['artikelnr'] ?? '')) ?: null, (string)$p['bezeichnung'], trim((string)($p['beschreibung'] ?? '')) ?: null,
           (float)$p['menge'], trim((string)($p['einheit'] ?? '')) ?: null, (int)$p['preis_cent'], (float)($p['mwst_satz'] ?? 0)]);
    }
    beleg_status_log_add($bid, 'erstellt', $grund !== '' ? $grund : 'Gutschrift erstellt', 'team');
    if ($kunde_id) log_aktivitaet('kunde', $kunde_id, 'team', 'Gutschrift ' . scalar("SELECT nummer FROM beleg WHERE id=?", [$bid]) . ' erstellt.', 'beleg', 'beleg', $bid);
    return $bid;
}
// Bestehende Rechnung stornieren: Gutschrift (negativ) erzeugen + Original auf 'storniert'. Idempotent.
function gutschrift_aus_rechnung(int $rechnung_id, string $grund = '', string $akteur = 'team'): ?int {
    $b = one("SELECT * FROM beleg WHERE id=? AND typ='rechnung'", [$rechnung_id]);
    if (!$b) return null;
    if (($v = scalar("SELECT id FROM beleg WHERE storno_von_id=? AND typ='gutschrift'", [$rechnung_id]))) return (int)$v;   // schon storniert
    $orig = beleg_positionen($rechnung_id);
    $pos = [];
    if ($orig) {
        foreach ($orig as $p) { $p['preis_cent'] = -abs((int)$p['preis_cent']); $pos[] = $p; }
    } else {
        $nettoCent = (int) round((float)$b['netto'] * 100);
        $pos[] = ['artikelnr'=>'', 'bezeichnung'=>'Storno der Rechnung ' . $b['nummer'], 'beschreibung'=>$grund,
                  'menge'=>1, 'einheit'=>'', 'preis_cent'=>-abs($nettoCent), 'mwst_satz'=>(float)$b['ust_prozent']];
    }
    $gid = gutschrift_erstellen((int)$b['kunde_id'], date('Y-m-d'), $pos, $grund !== '' ? $grund : ('Storno zu ' . $b['nummer']), $rechnung_id, $b['auftrag_id'] ? (int)$b['auftrag_id'] : null);
    q("UPDATE beleg SET status='storniert' WHERE id=?", [$rechnung_id]);
    beleg_status_log_add($rechnung_id, 'storniert', 'Storniert per Gutschrift ' . scalar("SELECT nummer FROM beleg WHERE id=?", [$gid]) . ($grund !== '' ? ' – ' . $grund : ''), $akteur);
    return $gid;
}
// Alle offenen Rechnungen eines Auftrags stornieren (bei Auftrags-Storno). Gibt Anzahl erzeugter Gutschriften.
function auftrag_rechnungen_stornieren(int $auftrag_id, string $grund = 'Auftrag storniert', string $akteur = 'team'): int {
    $n = 0;
    foreach (all("SELECT id FROM beleg WHERE auftrag_id=? AND typ='rechnung' AND status<>'storniert'", [$auftrag_id]) as $r)
        if (gutschrift_aus_rechnung((int)$r['id'], $grund, $akteur)) $n++;
    return $n;
}

// Auftrags-Status schnell setzen (Fast-Track / v3-Style): Status + Datum (leer = heute), Kundensicht + Protokoll.
// Bei 'storniert' werden offene Rechnungen automatisch per Gutschrift storniert.
function auftrag_status_setzen(int $auftrag_id, string $status, ?string $datum = null, string $notiz = '', string $akteur = 'team'): bool {
    $labels = ['offen'=>'offen','in_produktion'=>'in Produktion','erledigt'=>'versandbereit','versendet'=>'versendet','storniert'=>'storniert'];
    if (!isset($labels[$status])) return false;
    $a = one("SELECT nummer, kunde_id, status FROM auftrag WHERE id=?", [$auftrag_id]);
    if (!$a) return false;
    $d = ($datum && preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) ? $datum : date('Y-m-d');
    $alt = (string)($a['status'] ?? '');
    q("UPDATE auftrag SET status=?, status_datum=? WHERE id=?", [$status, $d, $auftrag_id]);
    if ($status === 'storniert' && $alt !== 'storniert') auftrag_rechnungen_stornieren($auftrag_id, 'Auftrag storniert', $akteur);
    if (!empty($a['kunde_id']))
        log_aktivitaet('kunde', (int)$a['kunde_id'], $akteur,
            'Auftrag ' . $a['nummer'] . ': Status → ' . $labels[$status] . ' (' . date('d.m.Y', strtotime($d)) . ')' . ($notiz !== '' ? ' – ' . $notiz : ''),
            'auftrag', 'auftrag', $auftrag_id);
    return true;
}

// ===== Guthaben (aus Gutschriften) =====
// Verfügbares Guthaben eines Kunden = Summe der Gutschrift-Beträge − bereits verbrauchtes (angerechnet/ausgezahlt).
function kunde_guthaben(int $kunde_id): float {
    if (!$kunde_id) return 0.0;
    $gut = (float) scalar("SELECT COALESCE(SUM(ABS(brutto)),0) FROM beleg WHERE kunde_id=? AND typ='gutschrift'", [$kunde_id]);
    $ver = (float) scalar("SELECT COALESCE(SUM(betrag),0) FROM guthaben_bewegung WHERE kunde_id=?", [$kunde_id]);
    return round($gut - $ver, 2);
}
function guthaben_bewegung_add(int $kunde_id, float $betrag, string $typ, ?int $ref_beleg_id = null, string $notiz = '', ?int $gutschrift_id = null): void {
    q("INSERT INTO guthaben_bewegung (kunde_id,gutschrift_id,betrag,typ,ref_beleg_id,notiz,datum) VALUES (?,?,?,?,?,?,CURDATE())",
      [$kunde_id, $gutschrift_id ?: null, round($betrag, 2), $typ, $ref_beleg_id ?: null, $notiz ?: null]);
}
// Guthaben auf eine Rechnung anrechnen: als Zahlung (art=guthaben) verbuchen + Verbrauch protokollieren. Gibt den angerechneten Betrag.
function guthaben_anrechnen(int $rechnung_id, float $wunsch, string $akteur = 'team'): float {
    $b = one("SELECT * FROM beleg WHERE id=? AND typ='rechnung' AND status<>'storniert'", [$rechnung_id]);
    if (!$b || !$b['kunde_id']) return 0.0;
    $rest = (float) beleg_zahlstatus($b)['rest'];
    $frei = kunde_guthaben((int)$b['kunde_id']);
    $betrag = round(min($wunsch > 0 ? $wunsch : $frei, $frei, $rest), 2);
    if ($betrag <= 0.005) return 0.0;
    zahlung_erfassen($rechnung_id, $betrag, date('Y-m-d'), 'guthaben', 'guthaben', 'Guthaben angerechnet', $akteur);
    guthaben_bewegung_add((int)$b['kunde_id'], $betrag, 'anrechnung', $rechnung_id, 'Angerechnet auf ' . $b['nummer']);
    return $betrag;
}
// Guthaben auszahlen (Erstattung): protokolliert den Verbrauch. Gibt den ausgezahlten Betrag.
function guthaben_auszahlen(int $kunde_id, float $wunsch, string $notiz = '', string $akteur = 'team'): float {
    $frei = kunde_guthaben($kunde_id);
    $betrag = round(min($wunsch > 0 ? $wunsch : $frei, $frei), 2);
    if ($betrag <= 0.005) return 0.0;
    guthaben_bewegung_add($kunde_id, $betrag, 'auszahlung', null, $notiz ?: 'Guthaben ausgezahlt');
    return $betrag;
}

// Demo-Verlauf (nur lokal, damit die Chat-Ansicht etwas zeigt)
function seed_aktivitaet_if_empty(): void {
    if (meta_get('seed_demo_off','') === '1') return;   // Demo-Seeding nach Reset deaktiviert
    if ((int) scalar("SELECT COUNT(*) FROM aktivitaet") > 0) return;
    $t = time();
    // [objekt_typ, objekt_id, offset_sek, akteur, typ, text, ref_typ, ref_id]
    $ev = [
        ['kunde',1, 3*86400, 'team',   'notiz',    'Kunde angelegt und Portalzugang eingerichtet.', '', 0],
        ['kunde',1, 3*86400-1800, 'kunde', 'login', 'Hat sich zum ersten Mal eingeloggt.', '', 0],
        ['kunde',1, 2*86400, 'kunde',  'rezeptur', 'Rezeptur „Immun-Komplex" eingereicht.', 'rezeptur', 7],
        ['kunde',1, 2*86400-3600, 'team', 'rezeptur', 'Rezeptur geprüft und Vorschlag zurückgesendet.', 'rezeptur', 7],
        ['kunde',1, 1*86400, 'kunde',  'rezeptur', 'Rezeptur-Vorschlag angenommen (eingefroren).', 'rezeptur', 7],
        ['kunde',1, 1*86400-1800, 'team', 'angebot', 'Angebot A-1001 erstellt (3 Staffeln).', 'angebot', 1001],
        ['kunde',1, 20*3600, 'kunde',  'angebot',  'Angebot A-1001 bestätigt – Staffel 1.000 Stück.', 'angebot', 1001],
        ['kunde',1, 19*3600, 'team',   'auftrag',  'Auftragsbestätigung + Rechnung R-2041 erzeugt.', 'auftrag', 2041],
        ['kunde',1, 3*3600,  'kunde',  'login',    'War online und hat den Bestellstatus angesehen.', '', 0],
        // Lieferant 1
        ['lieferant',1, 5*86400, 'team',      'notiz',      'Lieferant angelegt.', '', 0],
        ['lieferant',1, 4*86400, 'team',      'anfrage',    'Preisanfrage für Ashwagandha-Extrakt gesendet.', 'anfrage', 55],
        ['lieferant',1, 4*86400-7200, 'lieferant', 'angebot','Preisangebot abgegeben: 42,00 €/kg.', 'angebot', 88],
        ['lieferant',1, 2*86400, 'team',      'bestellung', 'Bestellung B-3007 ausgelöst (50 kg).', 'bestellung', 3007],
        ['lieferant',1, 1*86400, 'lieferant', 'dokument',   'CoA zur Charge hochgeladen.', 'dokument', 120],
        // Partner 1 (Hybrid)
        ['partner',1, 6*86400, 'team',    'notiz',      'Partner angelegt (Hybrid: Kunde + Lieferant).', '', 0],
        ['partner',1, 4*86400, 'partner', 'anfrage',    'Als Kunde: Produktanfrage für seinen SubKunden „VitalShop" gestellt.', 'anfrage', 61],
        ['partner',1, 3*86400, 'partner', 'angebot',    'Als Lieferant: Preisangebot für unsere Softgel-Anfrage abgegeben.', 'angebot', 92],
        ['partner',1, 1*86400, 'team',    'bestellung', 'Als Lieferant: Bestellung B-3011 an den Partner ausgelöst.', 'bestellung', 3011],
    ];
    foreach ($ev as $e) {
        // $e = [objekt_typ, objekt_id, offset_sek, akteur, typ, text, ref_typ, ref_id]
        q("INSERT INTO aktivitaet (objekt_typ,objekt_id,akteur,typ,text,ref_typ,ref_id,erstellt) VALUES (?,?,?,?,?,?,?,?)",
          [$e[0], $e[1], $e[3], $e[4], $e[5], $e[6] ?: null, $e[7] ?: null, gmdate('Y-m-d H:i:s', $t - $e[2])]);
    }
}

// Demo-Lieferanten
function seed_lieferanten_if_empty(): void {
    if (meta_get('seed_demo_off','') === '1') return;   // Demo-Seeding nach Reset aus: geloeschte Datensaetze bleiben geloescht
    if ((int) scalar("SELECT COUNT(*) FROM lieferanten") > 0) return;
    // [lief.-nr, firma, ap, email, tel, gesperrt, sprache, kategorien, fertig_formen, ort, land, waehrung]
    $demo = [
        ['L-2001','Herbal Extracts Co.','Li Wei','li@herbalextracts.cn','+86 21 5566',0,'zh','rohstoff','','Shanghai','CN','USD'],
        ['L-2002','PharmaCaps GmbH','Petra Sommer','p.sommer@pharmacaps.de','0761 22334',0,'de','verpackung','','Freiburg','DE','EUR'],
        ['L-2003','NutriRaw B.V.','Jan de Vries','jan@nutriraw.nl','+31 20 998877',0,'en','rohstoff','','Amsterdam','NL','EUR'],
        ['L-2004','LaborCheck AG','Dr. Klaus Rehm','rehm@laborcheck.de','089 776655',1,'de','labor','','München','DE','EUR'],
        ['L-2005','SoftGel Pro Ltd.','Maria Rossi','m.rossi@softgelpro.it','+39 02 4455',0,'en','fertigprodukt','softgel,kapsel','Milano','IT','EUR'],
    ];
    foreach ($demo as $d) {
        $d[0] = naechste_nummer('L');
        q("INSERT INTO lieferanten (lieferantennummer,firma,ansprechpartner,email,telefon,gesperrt,sprache,kategorien,fertig_formen,ort,land,waehrung)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)", $d);
    }
}

// Demo-Partner (Hybrid) inkl. SubKunden
function seed_partner_if_empty(): void {
    if (meta_get('seed_demo_off','') === '1') return;   // Demo-Seeding nach Reset aus: geloeschte Datensaetze bleiben geloescht
    if ((int) scalar("SELECT COUNT(*) FROM partner") > 0) return;
    $demo = [
        ['P-3001','LohnPartner Nord GmbH','Katrin Vogel','k.vogel@lohnpartner-nord.de','0431 556677',0,'de','fertigprodukt','kapsel,tablette','Kiel','DE',
         [['VitalShop', 'VS'], ['DrenGesund', 'DG'], ['NordSupps', 'NS']]],
        ['P-3002','FillPro Contract mfg.','Alan Ford','alan@fillpro.co.uk','+44 20 7788',0,'en','fertigprodukt','softgel','London','GB',
         [['PureBrand', 'PB'], ['IsleNutrition', 'IN']]],
    ];
    foreach ($demo as $d) {
        $subs = array_pop($d);
        $d[0] = naechste_nummer('PA');
        q("INSERT INTO partner (partnernummer,firma,ansprechpartner,email,telefon,gesperrt,sprache,kategorien,fertig_formen,ort,land)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)", $d);
        $pid = insert_id();
        foreach ($subs as $i => $s) {
            q("INSERT INTO partner_subkunde (partner_id,name,kennung,sort) VALUES (?,?,?,?)", [$pid, $s[0], $s[1], $i]);
        }
    }
}

// Nährstoff-Referenz vorbefüllen (offizielle EU-NRV-Liste + eigene ohne NRV)
function seed_naehrstoff_if_empty(): void {
    if ((int) scalar("SELECT COUNT(*) FROM naehrstoff") > 0) return;
    // [name, kategorie, nrv_wert, einheit, ist_nrv]
    $liste = [
        ['Vitamin A','vitamin',800,'µg',1], ['Vitamin D','vitamin',5,'µg',1], ['Vitamin E','vitamin',12,'mg',1],
        ['Vitamin K','vitamin',75,'µg',1], ['Vitamin C','vitamin',80,'mg',1], ['Thiamin (B1)','vitamin',1.1,'mg',1],
        ['Riboflavin (B2)','vitamin',1.4,'mg',1], ['Niacin (B3)','vitamin',16,'mg',1], ['Vitamin B6','vitamin',1.4,'mg',1],
        ['Folsäure','vitamin',200,'µg',1], ['Vitamin B12','vitamin',2.5,'µg',1], ['Biotin','vitamin',50,'µg',1],
        ['Pantothensäure','vitamin',6,'mg',1],
        ['Kalium','mineral',2000,'mg',1], ['Chlorid','mineral',800,'mg',1], ['Calcium','mineral',800,'mg',1],
        ['Phosphor','mineral',700,'mg',1], ['Magnesium','mineral',375,'mg',1], ['Eisen','mineral',14,'mg',1],
        ['Zink','mineral',10,'mg',1], ['Kupfer','mineral',1,'mg',1], ['Mangan','mineral',2,'mg',1],
        ['Fluorid','mineral',3.5,'mg',1], ['Selen','mineral',55,'µg',1], ['Chrom','mineral',40,'µg',1],
        ['Molybdän','mineral',50,'µg',1], ['Jod','mineral',150,'µg',1],
        // eigene ohne NRV
        ['Curcumin','sonstige',null,'mg',0], ['Withanolide','sonstige',null,'mg',0],
    ];
    $i = 0;
    foreach ($liste as $n) {
        q("INSERT INTO naehrstoff (name,kategorie,nrv_wert,einheit,ist_nrv,sort) VALUES (?,?,?,?,?,?)",
          [$n[0],$n[1],$n[2],$n[3],$n[4],$i++]);
    }
    seed_naehrstoff_ie_faktoren();
}

// Standard-Einheitenformen stampfen: I.E.->Masse (mg je 1 I.E.) + Anzeige-/Etiketteinheit (RE/α-TE/NE …).
// Idempotent, nur wo noch nichts hinterlegt ist -> Team-Overrides im Nährstoff-Editor bleiben erhalten.
function seed_naehrstoff_ie_faktoren(): void {
    if (!column_exists('naehrstoff', 'ie_mg')) return;   // vor der Migration nichts tun
    // name-Präfix => [ie_mg (mg je I.E.) | null, einheit_anzeige | null]
    $f = [
        'Vitamin A' => [0.0003,   'µg RE'],    // 1 I.E. = 0,3 µg Retinol-Äquivalent
        'Vitamin D' => [0.000025, null],       // 1 I.E. = 0,025 µg (Anzeige bleibt µg, I.E. zusätzlich)
        'Vitamin E' => [0.67,     'mg α-TE'],  // 1 I.E. = 0,67 mg (natürl. RRR-α-Tocopherol); synth. abweichend -> ggf. am Nährstoff überschreiben
        'Niacin'    => [null,     'mg NE'],    // Niacin-Äquivalent
    ];
    foreach ($f as $praefix => $vals) {
        [$ie, $anz] = $vals;
        if ($ie  !== null) q("UPDATE naehrstoff SET ie_mg=? WHERE name LIKE ? AND ie_mg IS NULL", [$ie, $praefix . '%']);
        if ($anz !== null) q("UPDATE naehrstoff SET einheit_anzeige=? WHERE name LIKE ? AND (einheit_anzeige IS NULL OR einheit_anzeige='')", [$anz, $praefix . '%']);
    }
}

// Zentrale Umrechnung: mg Nährstoff je 1 mg Rohstoff – aus Gehalt-Wert + dessen Einheit (+ I.E.-Faktor des Nährstoffs).
// EINZIGE Wahrheit für die Deklaration; überall (PIB, Portal, Rezeptur-/Produkt-Live-Rechnung) genutzt.
//   prozent = % (m/m) · mg_g = mg je g · ug_g = µg je g · ie_g = I.E. je g · ie_kg = I.E. je kg
function wirkstoff_mg_je_mg($wert, ?string $einheit, $ie_mg): float {
    if ($wert === null || $wert === '') return 0.0;
    $w  = (float) $wert;
    $ie = ($ie_mg === null || $ie_mg === '') ? null : (float) $ie_mg;
    switch ($einheit ?: 'prozent') {
        case 'prozent': return $w / 100;                              // 95 % -> 0,95 mg/mg
        case 'mg_g':    return $w / 1000;                             // mg je g -> mg/mg
        case 'ug_g':    return $w / 1e6;                              // µg je g -> mg/mg
        case 'ie_g':    return $ie !== null ? $w * $ie / 1000 : 0.0;  // I.E. je g -> mg/mg (über I.E.-Faktor)
        case 'ie_kg':   return $ie !== null ? $w * $ie / 1e6  : 0.0;  // I.E. je kg
    }
    return 0.0;
}

// ---------------------------------------------------------------------------
// Nährwerte je Rezeptur (je Einheit): Live-Ableitung aus den Rohstoffen vs. fester Snapshot/Override.
// Eine Rezeptur „kennt" damit ihre Nährwerte – standardmäßig abgeleitet, beim Einfrieren festgeschrieben,
// jederzeit manuell korrigierbar. Rückgabe-Zeilen: name, mg (je Einheit), nrv, einheit, anzeige, ie_mg,
// naehrstoff_id. So reihen sie sich nahtlos in die bestehende Nährwert-Anzeige ein.
// ---------------------------------------------------------------------------

// Live aus rezeptur_zutat → item_wirkstoff → naehrstoff ableiten (identisch zur Etikett-Deklaration).
function rezeptur_naehrwerte_ableiten(int $rid): array {
    if ($rid <= 0) return [];
    $zutaten = all("SELECT z.menge_mg, z.item_id FROM rezeptur_zutat z WHERE z.rezeptur_id=? ORDER BY z.sort, z.id", [$rid]);
    $ids = array_values(array_unique(array_filter(array_map(fn($z) => (int)$z['item_id'], $zutaten))));
    $wmap = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (all("SELECT iw.item_id, n.id AS naehrstoff_id, n.name, n.nrv_wert, n.einheit, n.ie_mg, n.einheit_anzeige,
                             COALESCE(iw.gehalt_wert, iw.gehalt_prozent) AS gehalt_wert, COALESCE(iw.gehalt_einheit,'prozent') AS gehalt_einheit
                      FROM item_wirkstoff iw JOIN naehrstoff n ON n.id=iw.naehrstoff_id WHERE iw.item_id IN ($in)", $ids) as $w) {
            $wmap[(int)$w['item_id']][] = [
                'naehrstoff_id' => (int)$w['naehrstoff_id'], 'name' => $w['name'], 'nrv' => $w['nrv_wert'],
                'einheit' => $w['einheit'], 'anzeige' => $w['einheit_anzeige'],
                'ie_mg' => $w['ie_mg'] !== null ? (float)$w['ie_mg'] : null,
                'basePerMg' => wirkstoff_mg_je_mg($w['gehalt_wert'], $w['gehalt_einheit'], $w['ie_mg']),
            ];
        }
    }
    $nutr = []; $order = [];
    foreach ($zutaten as $z) {
        $mg = (float)$z['menge_mg']; if ($mg <= 0) continue;
        foreach ($wmap[(int)$z['item_id']] ?? [] as $w) {
            if (!$w['basePerMg']) continue;
            $mgN = $mg * (float)$w['basePerMg'];
            if (!isset($nutr[$w['name']])) {
                $nutr[$w['name']] = ['name' => $w['name'], 'mg' => 0.0, 'nrv' => $w['nrv'], 'einheit' => $w['einheit'],
                                     'anzeige' => $w['anzeige'], 'ie_mg' => $w['ie_mg'], 'naehrstoff_id' => $w['naehrstoff_id']];
                $order[] = $w['name'];
            }
            $nutr[$w['name']]['mg'] += $mgN;
        }
    }
    $out = [];
    foreach ($order as $nm) $out[] = $nutr[$nm];
    return $out;
}

// Ist die Deklaration festgeschrieben (Snapshot/Override gilt statt Live-Ableitung)?
function rezeptur_naehrwerte_fixiert(int $rid): bool {
    return $rid > 0 && (bool) scalar("SELECT naehrwerte_fixiert FROM rezeptur WHERE id=?", [$rid]);
}

// Effektive Nährwerte: festgeschrieben → gespeicherte Zeilen, sonst Live-Ableitung.
function rezeptur_naehrwerte(int $rid): array {
    if ($rid <= 0) return [];
    if (rezeptur_naehrwerte_fixiert($rid)) {
        $rows = all("SELECT naehrstoff_id, name, menge_mg, nrv_wert, einheit, ie_mg, einheit_anzeige, quelle
                     FROM rezeptur_naehrwert WHERE rezeptur_id=? ORDER BY sort, id", [$rid]);
        return array_map(fn($r) => [
            'name' => $r['name'], 'mg' => (float)$r['menge_mg'], 'nrv' => $r['nrv_wert'],
            'einheit' => $r['einheit'], 'anzeige' => $r['einheit_anzeige'],
            'ie_mg' => $r['ie_mg'] !== null ? (float)$r['ie_mg'] : null,
            'naehrstoff_id' => $r['naehrstoff_id'] !== null ? (int)$r['naehrstoff_id'] : null,
            'quelle' => $r['quelle'],
        ], $rows);
    }
    return rezeptur_naehrwerte_ableiten($rid);
}

// Beim Einfrieren/Freigeben: aktuelle abgeleitete Werte festschreiben – nur wenn noch NICHT fixiert
// (eine bereits manuell gepflegte Deklaration wird nicht überschrieben). Best-effort.
function rezeptur_naehrwerte_snapshot(int $rid): void {
    if ($rid <= 0 || rezeptur_naehrwerte_fixiert($rid)) return;
    $werte = rezeptur_naehrwerte_ableiten($rid);
    if (!$werte) return;  // nichts Ableitbares → nicht fixieren (bleibt live, Hinweis in der UI)
    q("DELETE FROM rezeptur_naehrwert WHERE rezeptur_id=?", [$rid]);
    $sort = 0;
    foreach ($werte as $w) {
        q("INSERT INTO rezeptur_naehrwert (rezeptur_id,naehrstoff_id,name,menge_mg,nrv_wert,einheit,ie_mg,einheit_anzeige,quelle,sort)
           VALUES (?,?,?,?,?,?,?,?, 'auto', ?)",
          [$rid, $w['naehrstoff_id'] ?: null, $w['name'], (float)$w['mg'], $w['nrv'] !== null && $w['nrv'] !== '' ? (float)$w['nrv'] : null,
           $w['einheit'] ?: null, $w['ie_mg'] !== null ? (float)$w['ie_mg'] : null, $w['anzeige'] ?: null, $sort++]);
    }
    q("UPDATE rezeptur SET naehrwerte_fixiert=1 WHERE id=?", [$rid]);
}

// Manuelle Deklaration speichern (aus dem Rezeptur-Editor). $rows: [['name','mg','einheit','nrv'], …].
// Setzt die Deklaration fest (quelle='manuell'). Leere Namen werden ignoriert.
function rezeptur_naehrwerte_speichern(int $rid, array $rows): void {
    if ($rid <= 0) return;
    q("DELETE FROM rezeptur_naehrwert WHERE rezeptur_id=?", [$rid]);
    $sort = 0;
    foreach ($rows as $r) {
        $name = trim((string)($r['name'] ?? ''));
        if ($name === '') continue;
        $einheit = ($r['einheit'] ?? 'mg') === 'µg' ? 'µg' : 'mg';
        // Eingabe erfolgt in der gewählten Einheit; intern immer in mg je Einheit speichern (wie die Ableitung).
        $eingabe = (float)str_replace(',', '.', (string)($r['mg'] ?? 0));
        $mg = $einheit === 'µg' ? $eingabe / 1000 : $eingabe;
        $nrv = isset($r['nrv']) && $r['nrv'] !== '' ? (float)str_replace(',', '.', (string)$r['nrv']) : null;
        $nid = naehrstoff_id_by_name($name, false);
        q("INSERT INTO rezeptur_naehrwert (rezeptur_id,naehrstoff_id,name,menge_mg,nrv_wert,einheit,ie_mg,einheit_anzeige,quelle,sort)
           VALUES (?,?,?,?,?,?,NULL,NULL,'manuell',?)",
          [$rid, $nid ?: null, $name, $mg, $nrv, $einheit, $sort++]);
    }
    q("UPDATE rezeptur SET naehrwerte_fixiert=1 WHERE id=?", [$rid]);
}

// Zurück auf automatisch: Snapshot/Override verwerfen, wieder live aus den Rohstoffen ableiten.
function rezeptur_naehrwerte_zuruecksetzen(int $rid): void {
    if ($rid <= 0) return;
    q("DELETE FROM rezeptur_naehrwert WHERE rezeptur_id=?", [$rid]);
    q("UPDATE rezeptur SET naehrwerte_fixiert=0 WHERE id=?", [$rid]);
}

// Worklist „Rezepturen überarbeiten": Rezepturen, deren Zutaten NICHT sauber auf Lager-Rohstoffe gematcht
// sind – Freitext (item_id NULL), Verweis ins Leere/kein Rohstoff, oder Rohstoff ohne Wirkstoffdaten.
// Genau diese liefern leere Nährwerte und keine automatische CoA/Spec-Verknüpfung.
function rezepturen_zu_ueberarbeiten(): array {
    return all("SELECT r.id, r.nummer, r.name, r.status, r.kunde_id, r.darreichungsform, r.naehrwerte_fixiert,
                       k.firma AS kunde,
                       COUNT(z.id) AS zutaten,
                       SUM(CASE WHEN z.item_id IS NULL THEN 1 ELSE 0 END) AS frei,
                       SUM(CASE WHEN z.item_id IS NOT NULL AND i.id IS NULL THEN 1 ELSE 0 END) AS tot,
                       SUM(CASE WHEN i.id IS NOT NULL AND iw.c IS NULL THEN 1 ELSE 0 END) AS ohne_wirkstoff
                FROM rezeptur r
                JOIN rezeptur_zutat z ON z.rezeptur_id=r.id
                LEFT JOIN item i ON i.id=z.item_id AND i.kategorie='rohstoff'
                LEFT JOIN (SELECT item_id, COUNT(*) c FROM item_wirkstoff GROUP BY item_id) iw ON iw.item_id=i.id
                LEFT JOIN kunden k ON k.id=r.kunde_id
                GROUP BY r.id, r.nummer, r.name, r.status, r.kunde_id, r.darreichungsform, r.naehrwerte_fixiert, k.firma
                HAVING SUM(CASE WHEN z.item_id IS NULL THEN 1 ELSE 0 END) > 0
                    OR SUM(CASE WHEN z.item_id IS NOT NULL AND i.id IS NULL THEN 1 ELSE 0 END) > 0
                    OR SUM(CASE WHEN i.id IS NOT NULL AND iw.c IS NULL THEN 1 ELSE 0 END) > 0
                ORDER BY (SUM(CASE WHEN z.item_id IS NULL THEN 1 ELSE 0 END)
                        + SUM(CASE WHEN z.item_id IS NOT NULL AND i.id IS NULL THEN 1 ELSE 0 END)) DESC, r.name");
}

// Für diesen Auftrag eine eigene, überarbeitbare Rezeptur-KOPIE anlegen (frisches Rohstoff-Matching +
// damit aktuelle Spec/CoA). Das eingefrorene Kunden-Original bleibt unangetastet; der Auftrag nutzt die
// Kopie über auftrag.rezeptur_id (Override). Idempotent: hat der Auftrag bereits eine eigene Rezeptur
// (Override ≠ Produkt-Rezeptur), wird genau die zurückgegeben (kein zweiter Klon). Rückgabe: Rezeptur-Id.
function rezeptur_fuer_auftrag_kopieren(int $auftragId): ?int {
    if ($auftragId <= 0) return null;
    $a = one("SELECT id, nummer, kunde_id, produkt_id, rezeptur_id FROM auftrag WHERE id=?", [$auftragId]);
    if (!$a) return null;
    $prodRez = !empty($a['produkt_id']) ? (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$a['produkt_id']]) : 0;
    $ovr = (int)($a['rezeptur_id'] ?? 0);
    // Bereits eine eigene (abweichende) Rezeptur am Auftrag? Dann die weiterverwenden.
    if ($ovr && $ovr !== $prodRez) return $ovr;
    $quelleId = $ovr ?: $prodRez;
    if (!$quelleId) return null;
    $src = one("SELECT * FROM rezeptur WHERE id=?", [$quelleId]);
    if (!$src) return null;
    $kunde = $a['kunde_id'] ?: $src['kunde_id'];
    $nr = naechste_nummer('RZ');
    q("INSERT INTO rezeptur (nummer,name,synonyme,kunde_id,darreichungsform,kapselgroesse_id,exklusiv,status,notiz,basis_rezeptur_id)
       VALUES (?,?,?,?,?,?,?, 'entwurf', ?, ?)",
      [$nr, $src['name'] . ' (AB ' . $a['nummer'] . ')', $src['synonyme'] ?? null, $kunde ?: null,
       $src['darreichungsform'] ?? 'kapsel', $src['kapselgroesse_id'] ?? null, $kunde ? 1 : 0,
       'Auftrags-Kopie zur Überarbeitung (Rohstoff-Matching/Spec/CoA) für ' . $a['nummer'] . '.', $quelleId]);
    $neu = insert_id();
    foreach (all("SELECT item_id, bezeichnung, menge_mg, sort FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [$quelleId]) as $z)
        q("INSERT INTO rezeptur_zutat (rezeptur_id,item_id,bezeichnung,menge_mg,sort) VALUES (?,?,?,?,?)",
          [$neu, $z['item_id'], $z['bezeichnung'], $z['menge_mg'], $z['sort']]);
    q("UPDATE auftrag SET rezeptur_id=? WHERE id=?", [$neu, $auftragId]);
    rezeptur_bulkitem((int)$neu);
    return (int)$neu;
}

// Ist ein Analyse-Parameter ein echter Sicherheits-/Analysewert (Schwermetalle, Mikrobiologie, Mykotoxine,
// Pestizide, Lösungsmittel)? Nur mit solchen Werten ist ein Dokument eine echte COA – sonst nur eine Spec.
function coa_parameter_ist_analyse(string $parameter): bool {
    $p = mb_strtolower(trim($parameter));
    if ($p === '') return false;
    return (bool) preg_match('/(blei|lead|cadmium|arsen|arsenic|quecksilber|mercury|schwermetall|heavy ?metal|'
        . 'nickel|chrom|aluminium|mikrobiolog|keimzahl|gesamtkeim|koloniezahl|e\.? ?coli|escherichia|salmonell|'
        . 'staphyl|pseudomonas|hefe|schimmel|yeast|mould|mold|enterobacter|aerob|anaerob|mykotox|aflatox|'
        . 'ochratox|pestizid|pesticide|lösungsmittel|solvent|residual)/u', $p);
}
// Hat die Charge echte Analyse-/Sicherheitswerte? -> dann ist ein bulkify-COA zulässig, sonst nur Spec.
function charge_coa_hat_analysewerte(int $charge_id): bool {
    if ($charge_id <= 0) return false;
    foreach (all("SELECT parameter FROM charge_analyse WHERE charge_id=?", [$charge_id]) as $r)
        if (coa_parameter_ist_analyse((string)$r['parameter'])) return true;
    return false;
}

// Status einer EINZELNEN Zutat fürs Matching-UI: 'ok' | 'frei' (Freitext) | 'tot' (Item fehlt/kein Rohstoff) | 'ohne_wirkstoff'.
function rezeptur_zutat_match(?int $item_id): string {
    if (!$item_id) return 'frei';
    $i = one("SELECT id FROM item WHERE id=? AND kategorie='rohstoff'", [$item_id]);
    if (!$i) return 'tot';
    return (int) scalar("SELECT COUNT(*) FROM item_wirkstoff WHERE item_id=?", [$item_id]) > 0 ? 'ok' : 'ohne_wirkstoff';
}

// Nährstoff per Name finden – oder neu anlegen (für „neuen Wirkstoff eintippen")
function naehrstoff_id_by_name(string $name, bool $create = true): ?int {
    $name = trim($name);
    if ($name === '') return null;
    $id = scalar("SELECT id FROM naehrstoff WHERE name = ?", [$name]);
    if ($id) return (int)$id;
    if (!$create) return null;
    q("INSERT INTO naehrstoff (name,kategorie,ist_nrv) VALUES (?, 'sonstige', 0)", [$name]);
    return insert_id();
}

// Demo-Rohstoffe (Items) inkl. ihrer Wirkstoffe
function seed_item_if_empty(): void {
    if ((int) scalar("SELECT COUNT(*) FROM item") > 0) return;
    seed_naehrstoff_if_empty();
    // [art.-nr, name, name_en, name_lat, kategorie, form, dichte, einheit, ek_preis, preis_bezug, [[wirkstoff,gehalt%],...]]
    $demo = [
        ['R-0001','Vitamin C (Ascorbinsäure)','Vitamin C','Acidum ascorbicum','rohstoff','pulver',0.800,'kg',8.5000,'kg', [['Vitamin C',100]]],
        ['R-0002','Magnesiumcitrat','Magnesium citrate','Magnesii citras','rohstoff','pulver',0.700,'kg',12.9000,'kg', [['Magnesium',16]]],
        ['R-0002b','Magnesiumbisglycinat','Magnesium bisglycinate','Magnesii bisglycinas','rohstoff','pulver',0.600,'kg',22.0000,'kg', [['Magnesium',14]]],
        ['R-0003','Ashwagandha-Extrakt','Ashwagandha extract','Withania somnifera','rohstoff','pulver',0.550,'kg',42.0000,'kg', [['Withanolide',2.5]]],
        ['R-0004','Zink-Bisglycinat','Zinc bisglycinate','Zinci bisglycinas','rohstoff','pulver',0.650,'kg',28.5000,'kg', [['Zink',20]]],
        ['R-0005','Kurkuma-Extrakt','Curcuma extract','Curcuma longa','rohstoff','pulver',0.500,'kg',36.0000,'kg', [['Curcumin',95]]],
        ['R-0006','Vitamin D3 100.000 IE/g (Öl)','Vitamin D3 oil','Cholecalciferolum','rohstoff','oel',0.950,'kg',95.0000,'kg', [['Vitamin D',null]]],
        ['R-0007','Mikrokristalline Cellulose','MCC','Cellulosum','rohstoff','pulver',0.450,'kg',4.2000,'kg', []],
        ['R-0008','Vitamin D3+K2 Tropfen','Vitamin D3+K2 drops','Cholecalciferolum + Menaquinonum','rohstoff','fluessig',0.920,'L',60.0000,'L', [['Vitamin D',null],['Vitamin K',null]]],
    ];
    foreach ($demo as $d) {
        $wirk = array_pop($d);
        $d[0] = naechste_nummer(item_prefix($d[4]));
        q("INSERT INTO item (artikelnummer,name,name_en,name_lat,kategorie,form,dichte,einheit,ek_preis,preis_bezug)
           VALUES (?,?,?,?,?,?,?,?,?,?)", $d);
        $iid = insert_id();
        foreach ($wirk as $i => $w) {
            $nid = naehrstoff_id_by_name($w[0]);
            if ($nid) q("INSERT INTO item_wirkstoff (item_id,naehrstoff_id,gehalt_prozent,sort) VALUES (?,?,?,?)", [$iid,$nid,$w[1],$i]);
        }
    }
    // CAS-Nummern nachtragen
    $cas = ['Vitamin C (Ascorbinsäure)'=>'50-81-7','Magnesiumcitrat'=>'3344-18-1','Magnesiumbisglycinat'=>'14783-68-7',
            'Zink-Bisglycinat'=>'14281-83-5','Kurkuma-Extrakt'=>'458-37-7','Vitamin D3 100.000 IE/g (Öl)'=>'67-97-0',
            'Mikrokristalline Cellulose'=>'9004-34-6'];
    foreach ($cas as $name => $nr) q("UPDATE item SET cas=? WHERE name=?", [$nr, $name]);
}

// Demo-Verpackungen (Items mit kategorie=verpackung)
function seed_verpackung_if_empty(): void {
    if ((int) scalar("SELECT COUNT(*) FROM item WHERE kategorie='verpackung'") > 0) return;
    // [name, verpackungsart, material, volumen_ml, farbe, ek_preis]
    $demo = [
        ['Braunglas 60 ml','flasche','Braunglas',60,'braun',0.8500],
        ['Dose 150 ml weiß','dose','HDPE',150,'weiß',0.4200],
        ['Dose 250 ml weiß','dose','HDPE',250,'weiß',0.5500],
        ['Doypack-Beutel 250 g','beutel','Alu-Verbund',null,'silber',0.3000],
        ['Blister 10er','blister','PVC/Alu',null,'transparent',0.1200],
    ];
    foreach ($demo as $d) {
        q("INSERT INTO item (artikelnummer,name,kategorie,verpackungsart,material,volumen_ml,farbe,einheit,ek_preis,preis_bezug)
           VALUES (?,?,?,?,?,?,?,?,?,?)",
          [naechste_nummer('VP'), $d[0], 'verpackung', $d[1], $d[2], $d[3], $d[4], 'Stück', $d[5], 'Stück']);
    }
}

// Wunschname -> passender Rohstoff (über Nährstoff-Name oder Item-Name).
function anfrage_auto_item(string $bez): ?int {
    $bez = trim(str_replace(['%','_'], '', $bez));
    if ($bez === '') return null;
    $id = scalar("SELECT iw.item_id FROM item_wirkstoff iw JOIN naehrstoff n ON n.id=iw.naehrstoff_id
                  JOIN item i ON i.id=iw.item_id WHERE i.kategorie='rohstoff' AND i.gesperrt=0 AND n.name LIKE ? LIMIT 1", ['%'.$bez.'%']);
    if ($id) return (int)$id;
    $id = scalar("SELECT id FROM item WHERE kategorie='rohstoff' AND gesperrt=0 AND name LIKE ? LIMIT 1", ['%'.$bez.'%']);
    return $id ? (int)$id : null;
}

// Aus einer Rezepturanfrage angelegter Rohstoff-Entwurf: mit der Lieferantenantwort (Preis/CoA/Spec) in den
// Katalog heben – entsperren + Entwurfs-Marker entfernen. Auf normale Rohstoffe wirkt es nicht.
function rohstoff_entwurf_aktivieren(int $item_id): void {
    if ($item_id <= 0) return;
    if ((int) scalar("SELECT anfrage_entwurf FROM item WHERE id=?", [$item_id]) !== 1) return;
    q("UPDATE item SET gesperrt=0, anfrage_entwurf=0 WHERE id=?", [$item_id]);
}

// ===== Rohstoff-Stoffklasse ("Art") + physische Form: Optionen + Heuristik =======================
// Stoffklasse (item.art) – für Filter (intern + Kundenportal). Reihenfolge = Anzeigereihenfolge.
function rohstoff_art_optionen(): array {
    return ['vitamin'=>'Vitamine', 'mineralstoff'=>'Mineralstoffe', 'pflanzenstoff'=>'Pflanzenstoffe',
            'aminosaeure'=>'Aminosäuren', 'fettsaeure'=>'Fettsäuren/Öle', 'ballaststoff'=>'Ballaststoffe',
            'probiotikum'=>'Probiotika', 'enzym'=>'Enzyme', 'sonstiges'=>'Sonstiges'];
}
function rohstoff_art_label(?string $art): string { return rohstoff_art_optionen()[(string)$art] ?? ''; }
// Physische Form (item.form) – gleiche Werte wie in der Liste; Extrakt bleibt bewusst eine FORM-Option.
function rohstoff_form_optionen(): array {
    return ['pulver'=>'Pulver', 'granulat'=>'Granulat', 'extrakt'=>'Extrakt', 'fluessig'=>'Flüssig',
            'oel'=>'Öl', 'paste'=>'Paste', 'kristallin'=>'Kristallin'];
}
function rohstoff_form_label(?string $form): string {
    return (rohstoff_form_optionen()[(string)$form] ?? '') ?: ((string)$form === 'kapselhuelle' ? 'Kapselhülle' : '');
}
// Beschaffenheit: WAS der Rohstoff ist. Getrennt von der physischen Form (ein Extrakt ist meist ein Pulver)
// und von der Spezifikation (Standardisierung wie "95% Curcumin").
function rohstoff_beschaffenheit_optionen(): array {
    return ['extrakt'=>'Extrakt', 'pulver_rein'=>'Reines/natives Pulver', 'isolat'=>'Isolat / reiner Stoff',
            'konzentrat'=>'Konzentrat', 'fluessigextrakt'=>'Flüssigextrakt', 'oel'=>'Öl', 'sonstiges'=>'Sonstiges'];
}
function rohstoff_beschaffenheit_label(?string $b): string { return rohstoff_beschaffenheit_optionen()[(string)$b] ?? ''; }
// Kurzwort fuer den Anzeigenamen (nur Extrakt-Arten tragen ein Wort in den Namen; Verhaeltnis separat).
function rohstoff_beschaffenheit_namenswort(?string $b): string {
    return ['extrakt'=>'Extrakt', 'fluessigextrakt'=>'Flüssigextrakt', 'konzentrat'=>'Konzentrat'][(string)$b] ?? '';
}
// Anzeigename: Basisname + (Extrakt-Wort, falls noch nicht im Namen) + Verhaeltnis (DEV).
// Beispiel: "Ashwagandha" + beschaffenheit=extrakt + dev=10:1 -> "Ashwagandha Extrakt 10:1".
// Alt-Namen, die "Extrakt" schon enthalten, werden NICHT gedoppelt. $it braucht name, beschaffenheit, dev.
function rohstoff_anzeige_name(array $it): string {
    $name = trim((string)($it['name'] ?? ''));
    if ($name === '') return '';
    $wort = rohstoff_beschaffenheit_namenswort($it['beschaffenheit'] ?? '');
    if ($wort !== '' && mb_stripos($name, $wort) === false && mb_stripos($name, 'extract') === false) {
        $name .= ' ' . $wort;
    }
    $dev = trim((string)($it['dev'] ?? ''));
    if ($dev !== '' && mb_strpos($name, $dev) === false) $name .= ' ' . $dev;
    return $name;
}
// Heuristik: Stoffklasse aus dem Namen raten. KONSERVATIV – nur klare Treffer, sonst '' (unbestimmt).
// Dient nur zur Vorbelegung leerer Felder; die Pflege bleibt am Rohstoff-Detail möglich.
function rohstoff_art_raten(string $name): string {
    $n = ' ' . mb_strtolower($name) . ' ';
    $hat = fn(string $re): bool => (bool) preg_match('/' . $re . '/u', $n);
    // Vitamine (inkl. chemische Namen)
    if ($hat('vitamin|cholecalciferol|ergocalciferol| ascorbin|ascorbinsäure|tocopherol|tocotrienol|retinol|retinyl|\bbiotin|folsäure|folat|methylfolat|niacin|nicotinamid|riboflavin|thiamin|cobalamin|methylcobalamin|pyridoxin|panthenol|pantothen|menachinon|menaquinon|phyllochinon|cholin'))
        return 'vitamin';
    // Mineralstoffe / Spurenelemente – VOR den Aminosäuren, weil Mineral-Chelate oft nach einer
    // Aminosäure benannt sind (z. B. "Magnesium Bisglycinat", "Zink Aspartat") – der Mineralstoff ist aktiv.
    if ($hat('magnesium|calcium|kalzium|\bzink|\beisen|kalium|natrium|\bselen|kupfer|\bmangan|\bchrom|molybd|\bjod\b|\biod|phosphor|\bbor\b|silicium|silizium|kiesel|spurenelement|mineral'))
        return 'mineralstoff';
    // Aminosäuren (optionales D-/DL-/L- Präfix, auch bare Namen + Beta-Alanin, Creatin, BCAA)
    if ($hat('\b(?:d-|dl-|l-)?(arginin|lysin|glutamin|glutaminsäure|glutamat|carnitin|carnosin|citrullin|ornithin|theanin|tryptophan|tyrosin|cystein|cystin|glycin|methionin|leucin|isoleucin|valin|threonin|phenylalanin|histidin|prolin|serin|taurin|alanin|asparagin|asparaginsäure|aspartat)|beta.?alanin|\bbcaa\b|\bcreatin|\bkreatin|aminosäure'))
        return 'aminosaeure';
    // Fettsäuren / Öle
    if ($hat('omega|fischöl|lein(öl|samen)|\bdha\b|\bepa\b|fettsäure|mct|krill'))
        return 'fettsaeure';
    // Probiotika
    if ($hat('lactobacillus|bifidobacterium|probiotik|\bkulturen|\bcfu'))
        return 'probiotikum';
    // Enzyme
    if ($hat('enzym|bromelain|papain|protease|lipase|amylase|laktase|lactase'))
        return 'enzym';
    // Ballaststoffe
    if ($hat('ballaststoff|inulin|flohsamen|psyllium|glucomannan|\bpektin|akazienfaser|cellulose'))
        return 'ballaststoff';
    // Pflanzenstoffe (Extrakte/Botanicals) – breiter Fang zum Schluss
    if ($hat('extrakt|extract|wurzel|blatt|blätter|kraut|frucht|samen|rinde|blüte|pflanz|botanical|kakao|cacao|acerola|curcumin|kurkuma|ginseng|ashwagandha|ginkgo|mariendistel|brennnessel|ingwer|grüntee|grüner tee|traubenkern|olivenblatt|weihrauch|bockshornklee|moringa|spirulina|chlorella|aroniabeere|holunder|hagebutte|resveratrol|quercetin|rutin|oleuropein|polyphenol|flavonoid|beere|pilz|reishi|cordyceps'))
        return 'pflanzenstoff';
    return '';
}
// Leere item.art konservativ vorbelegen (nur Rohstoffe, nur klare Namens-Treffer). Rückgabe: Anzahl gesetzt.
function rohstoff_art_autofuellen(): int {
    $n = 0;
    foreach (all("SELECT id, name FROM item WHERE kategorie='rohstoff' AND (art IS NULL OR art='')") as $r) {
        $a = rohstoff_art_raten((string)$r['name']);
        if ($a !== '') { q("UPDATE item SET art=? WHERE id=?", [$a, (int)$r['id']]); $n++; }
    }
    return $n;
}

/**
 * Aufgabe 4 – Ähnliche Rezepturen finden (Stufe A: intern, sieht ALLES).
 *
 * Vergleicht eine Zutatenliste (item_id => menge_mg) mit bestehenden Rezepturen
 * derselben Darreichungsform. Ähnlichkeit = Mengen-Anteils-Überlappung
 * (Summe der min(AnteilA, AnteilB) über alle Zutaten, 0..1) – so zählt sowohl,
 * WELCHE Rohstoffe vorkommen als auch in welchem Verhältnis.
 *
 * $zutaten       : [item_id => menge_mg]  (item_id>0, menge_mg>0)
 * $form          : Darreichungsform (nur gleiche Form wird verglichen)
 * $exclRezeptId  : optionale eigene Rezeptur-ID ausschließen
 * $nurKundeId    : Stufe B (Kundensicht): nur Hausrezepturen (kunde_id NULL) + eigene
 *                  dieses Kunden. NIEMALS Rezepturen anderer Kunden. null = intern (alle).
 * Rückgabe: bis $limit Treffer, je [rezeptur, prozent, gleiche, abweichungen[], nur_dort[], fehlt[]]
 */
function rezeptur_aehnliche(array $zutaten, string $form, ?int $exclRezeptId = null, ?int $nurKundeId = null, int $limit = 3): array {
    // Eingabe säubern + Anteile bilden
    $a = [];
    foreach ($zutaten as $iid => $mg) { $iid = (int)$iid; $mg = (float)$mg; if ($iid > 0 && $mg > 0) $a[$iid] = ($a[$iid] ?? 0) + $mg; }
    $sumA = array_sum($a);
    if (!$a || $sumA <= 0) return [];
    $pa = []; foreach ($a as $iid => $mg) $pa[$iid] = $mg / $sumA;

    // Kandidaten: gleiche Darreichungsform, echte Rezepturen (kein Entwurf/abgelehnt).
    $sql = "SELECT id, nummer, name, kunde_id, status FROM rezeptur
            WHERE darreichungsform=? AND status IN ('vorschlag','eingefroren','freigegeben')";
    $par = [$form];
    if ($exclRezeptId) { $sql .= " AND id<>?"; $par[] = $exclRezeptId; }
    if ($nurKundeId !== null) { $sql .= " AND (kunde_id IS NULL OR kunde_id=?)"; $par[] = $nurKundeId; }   // Stufe B: nie fremde Kunden
    $kandidaten = all($sql, $par);
    if (!$kandidaten) return [];

    $treffer = [];
    foreach ($kandidaten as $c) {
        $zt = all("SELECT item_id, bezeichnung, menge_mg FROM rezeptur_zutat WHERE rezeptur_id=? AND item_id>0", [(int)$c['id']]);
        if (!$zt) continue;
        $b = []; $namen = [];
        foreach ($zt as $z) { $iid=(int)$z['item_id']; $mg=(float)$z['menge_mg']; if ($iid>0 && $mg>0){ $b[$iid]=($b[$iid]??0)+$mg; $namen[$iid]=$z['bezeichnung']; } }
        $sumB = array_sum($b); if ($sumB <= 0) continue;
        $pb = []; foreach ($b as $iid=>$mg) $pb[$iid] = $mg / $sumB;

        $union = array_unique(array_merge(array_keys($pa), array_keys($pb)));
        $overlap = 0.0; $gleiche = 0;
        foreach ($union as $iid) { $va=$pa[$iid]??0; $vb=$pb[$iid]??0; $overlap += min($va,$vb); if($va>0 && $vb>0) $gleiche++; }
        $prozent = (int) round($overlap * 100);
        if ($prozent < 15) continue;   // zu unähnlich -> weglassen

        // Klartext-Abweichungen (nur geteilte Zutaten mit spürbarem Mengenunterschied).
        $abw = []; $nurDort = []; $fehlt = [];
        foreach ($union as $iid) {
            $ma = $a[$iid] ?? 0; $mb = $b[$iid] ?? 0; $nm = $namen[$iid] ?? ('#'.$iid);
            if ($ma>0 && $mb>0) {
                $d = $ma>0 ? ($mb-$ma)/$ma*100 : 0;
                if (abs($d) >= 10) $abw[] = $nm.' '.($d>0?'+':'').(int)round($d).' %';
            } elseif ($mb>0) { $nurDort[] = $nm; }
            else { $fehlt[] = $nm; }
        }
        $treffer[] = ['rezeptur'=>$c, 'prozent'=>$prozent, 'gleiche'=>$gleiche, 'zutaten_gesamt'=>count($pb),
                      'abweichungen'=>$abw, 'nur_dort'=>$nurDort, 'fehlt'=>$fehlt];
    }
    usort($treffer, fn($x,$y)=> $y['prozent'] <=> $x['prozent']);
    return array_slice($treffer, 0, $limit);
}

// Demo-Anfrage (Kundenwunsch in Laiensprache)
function seed_anfrage_if_empty(): void {
    if (meta_get('seed_demo_off','') === '1') return;   // Demo-Seeding nach Reset deaktiviert
    if ((int) scalar("SELECT COUNT(*) FROM rezeptur_anfrage") > 0) return;
    seed_kunden_if_empty();
    $kid = scalar("SELECT id FROM kunden WHERE firma='NordVital UG'");
    q("INSERT INTO rezeptur_anfrage (nummer,kunde_id,darreichungsform,notiz,status) VALUES (?,?,?,?,?)",
      [naechste_nummer('RZA'), $kid ?: null, 'kapsel', 'Immun-Komplex für den Winter, gut verträglich.', 'neu']);
    $aid = insert_id();
    $wunsch = [['Vitamin C','500','mg','hochdosiert'], ['Zink','15','mg',''], ['Kurkuma','200','mg','für Entzündungen']];
    foreach ($wunsch as $i => $w) {
        q("INSERT INTO rezeptur_anfrage_wunsch (anfrage_id,bezeichnung,wunsch_menge,einheit,notiz,sort) VALUES (?,?,?,?,?,?)",
          [$aid, $w[0], $w[1], $w[2], $w[3], $i]);
    }
}

// Demo-Rezeptur (zeigt Aggregation gleicher Nährstoffe + Deklaration)
function seed_rezeptur_if_empty(): void {
    if (meta_get('seed_demo_off','') === '1') return;   // Demo-Seeding nach Reset deaktiviert
    if ((int) scalar("SELECT COUNT(*) FROM rezeptur") > 0) return;
    seed_item_if_empty();
    q("INSERT INTO rezeptur (nummer,name,darreichungsform,status,notiz) VALUES (?,?,?,?,?)",
      [naechste_nummer('RZ'), 'Magnesium Komplex', 'kapsel', 'entwurf', 'Zwei Magnesium-Quellen + Vitamin C – zeigt die Aggregation.']);
    $rid = insert_id();
    $zut = [['Magnesiumcitrat',400], ['Magnesiumbisglycinat',400], ['Vitamin C (Ascorbinsäure)',80]];
    foreach ($zut as $i => $z) {
        $iid = scalar("SELECT id FROM item WHERE name=?", [$z[0]]);
        q("INSERT INTO rezeptur_zutat (rezeptur_id,item_id,bezeichnung,menge_mg,sort) VALUES (?,?,?,?,?)",
          [$rid, $iid ?: null, $z[0], $z[1], $i]);
    }
}

// Demo-Produkt (verbindet Rezeptur + Verpackung + Kunde)
function seed_produkt_if_empty(): void {
    if (meta_get('seed_demo_off','') === '1') return;   // Demo-Seeding nach Reset deaktiviert
    if ((int) scalar("SELECT COUNT(*) FROM produkt") > 0) return;
    seed_kunden_if_empty(); seed_rezeptur_if_empty(); seed_verpackung_if_empty();
    $kid = scalar("SELECT id FROM kunden WHERE firma='Alpenkraft GmbH'");
    $rid = scalar("SELECT id FROM rezeptur WHERE name='Magnesium Komplex'");
    $vid = scalar("SELECT id FROM item WHERE name='Dose 150 ml weiß' AND kategorie='verpackung'");
    q("INSERT INTO produkt (nummer,name,kunde_id,rezeptur_id,verpackung_id,einheiten_pro_packung,einnahme_pro_tag,status)
       VALUES (?,?,?,?,?,?,?,?)",
      [naechste_nummer('P'), 'Magnesium Komplex · 120 Kapseln', $kid ?: null, $rid ?: null, $vid ?: null, 120, 2, 'aktiv']);
}

// Demo-Angebot mit Staffeln (fürs Demo-Produkt)
function seed_angebot_if_empty(): void {
    if (meta_get('seed_demo_off','') === '1') return;   // Demo-Seeding nach Reset deaktiviert
    if ((int) scalar("SELECT COUNT(*) FROM angebot") > 0) return;
    seed_produkt_if_empty();
    $prod = one("SELECT id, kunde_id FROM produkt LIMIT 1");
    if (!$prod) return;
    q("INSERT INTO angebot (nummer,kunde_id,produkt_id,status,notiz) VALUES (?,?,?,?,?)",
      [naechste_nummer('AN'), $prod['kunde_id'], $prod['id'], 'offen', 'Erstangebot – Staffeln zur Auswahl.']);
    $aid = insert_id();
    $staffeln = [[500,4.5000],[1000,3.9000],[2500,3.4000]];
    foreach ($staffeln as $i => $s) {
        q("INSERT INTO angebot_staffel (angebot_id,menge,vk_stueck,sort) VALUES (?,?,?,?)", [$aid, $s[0], $s[1], $i]);
    }
}

// Testdaten einspielen, wenn eine Tabelle leer ist (nur lokal zum Ansehen)
function seed_kunden_if_empty(): void {
    if (meta_get('seed_demo_off','') === '1') return;   // Demo-Seeding nach Reset aus: geloeschte Datensaetze bleiben geloescht
    if ((int) scalar("SELECT COUNT(*) FROM kunden") > 0) return;
    $demo = [
        ['K-1001','Alpenkraft GmbH','Lena Berger','lena@alpenkraft.de','089 1234567',0,'München','DE','vorkasse'],
        ['K-1002','NordVital UG','Jonas Hansen','j.hansen@nordvital.de','040 987654',0,'Hamburg','DE','rechnung'],
        ['K-1003','PureLife Cosmetics','Sara Klein','sara@purelife.eu','030 5551212',0,'Berlin','DE','vorkasse'],
        ['K-1004','BioSana AG','Marco Frei','m.frei@biosana.ch','+41 44 1112233',0,'Zürich','CH','vorkasse'],
        ['K-1005','GreenPeak Nutrition','Tom Wolf','tom@greenpeak.de','0221 445566',1,'Köln','DE','vorkasse'],
    ];
    foreach ($demo as $d) {
        $d[0] = naechste_nummer('K');
        q("INSERT INTO kunden (kundennummer,firma,ansprechpartner,email,telefon,gesperrt,ort,land,zahlungsart)
           VALUES (?,?,?,?,?,?,?,?,?)", $d);
    }
}

// ---- Daten zurücksetzen (Werkzeug in den Einstellungen) ----
// Räumt die VORGÄNGE ab und lässt die Stammdaten stehen. Der Umfang ist bewusst gestuft, damit
// niemand versehentlich die gepflegten Behälter-/Preisdaten mitreißt:
//   $mitRezepturen = zusätzlich Rezepturen, Produkte und deren Preismatrix
//   $mitKunden     = zusätzlich Kunden, Marken und Partner-Subkunden
// NIE angefasst: item (Rohstoffe/Verpackungen mit EK-Staffeln, Kapsel-Fassung, Etikettenpreise),
// naehrstoff, kapselgroesse, benutzer, nummernkreis, app_meta.
// Gibt je Tabelle die Anzahl gelöschter Zeilen zurück.
function daten_zuruecksetzen(bool $mitRezepturen = false, bool $mitKunden = false): array {
    // Reihenfolge = von den abhängigen zu den führenden Tabellen (keine verwaisten Verweise)
    $vorgaenge = [
        'produktion_verbrauch', 'produktion_schritt', 'produktionsauftrag',
        'reservierung', 'lager2_bewegung', 'charge',
        'zahlung', 'beleg_status_log', 'beleg', 'auftrag',
        'angebot_position', 'angebot_staffel', 'angebot_produkt', 'angebot',
        'bestellung_position', 'bestellung', 'freibedarf',
        'portal_anfrage_pos', 'portal_anfrage',
        'rezeptur_anfrage_wunsch', 'rezeptur_anfrage',
        'aufgabe', 'aktivitaet',
    ];
    $rezepturen = ['produkt_preis', 'produkt', 'rezeptur_zutat', 'rezeptur'];
    $kunden     = ['kunde_marke', 'partner_subkunde', 'kunden'];

    $tabellen = $vorgaenge;
    if ($mitRezepturen) $tabellen = array_merge($tabellen, $rezepturen);
    if ($mitKunden)     $tabellen = array_merge($tabellen, $kunden);

    $report = [];
    foreach ($tabellen as $t) {
        if (!table_exists($t)) continue;
        $vorher = (int) scalar("SELECT COUNT(*) FROM `$t`");
        if ($vorher > 0) q("DELETE FROM `$t`");
        $report[$t] = $vorher;
    }
    // Dokumente/Chargen verweisen auf gelöschte Objekte -> Verweise am Produkt lösen, damit nichts ins Leere zeigt
    if ($mitRezepturen && table_exists('item')) q("UPDATE item SET produkt_id=NULL WHERE produkt_id IS NOT NULL");
    // Demo-Seeding aus bleibt aus: sonst legen die Seeds beim nächsten Seitenaufruf alles wieder an
    meta_set('seed_demo_off', '1');
    log_aktivitaet('system', 0, 'team', 'Daten zurückgesetzt (' . array_sum($report) . ' Zeilen gelöscht'
        . ($mitRezepturen ? ', inkl. Rezepturen/Produkte' : '') . ($mitKunden ? ', inkl. Kunden' : '') . ').');
    return $report;
}

// ---- Live-Schutz ----
// Solange das System noch nicht online ist, darf frei aufgeräumt werden. Sobald „System ist live"
// gesetzt ist (Einstellungen -> Werkzeuge), verlangen ALLE löschenden Werkzeuge zusätzlich, dass das
// Wort LÖSCHEN eingetippt wird. Reines Anhaken reicht dann nicht mehr.
function system_ist_live(): bool { return (string) meta_get('system_live', '0') === '1'; }
// Darf eine löschende Aktion laufen? $wort = was der Benutzer eingetippt hat.
// „LOESCHEN" gilt genauso wie „LÖSCHEN" – an fremden Tastaturen ist der Umlaut sonst eine Hürde.
function loeschen_erlaubt(?string $wort): bool {
    if (!system_ist_live()) return true;
    if (!is_string($wort)) return false;
    return in_array(mb_strtoupper(trim($wort)), ['LÖSCHEN', 'LOESCHEN'], true);
}

// ---- Demo-Testdaten gezielt wieder entfernen ----
// Löscht NUR, was `demo_testset_einspielen()` angelegt hat – erkennbar an der Notiz 'DEMO-TESTSET'
// (ältere Demo-Rezepturen zusätzlich an 'Demo-Rezeptur'). Echte Daten bleiben unangetastet.
// Produkte, Rezepturen und Kunden werden nur gelöscht, wenn NICHTS Echtes mehr daran hängt –
// ein Demo-Produkt, das inzwischen in einem echten Angebot steckt, bleibt also stehen.
function demo_testset_entfernen(): array {
    $r = ['angebote'=>0, 'auftraege'=>0, 'belege'=>0, 'produktionen'=>0, 'chargen'=>0,
          'produkte'=>0, 'rezepturen'=>0, 'kunden'=>0, 'behalten'=>[]];

    // 1) Vorgangskette je Demo-Angebot: Auftrag -> Produktion/Chargen/Belege
    foreach (all("SELECT id FROM angebot WHERE notiz='DEMO-TESTSET'") as $a) {
        $angId = (int)$a['id'];
        foreach (all("SELECT id FROM auftrag WHERE angebot_id=?", [$angId]) as $auf) {
            $aufId = (int)$auf['id'];
            foreach (all("SELECT id FROM produktionsauftrag WHERE auftrag_id=?", [$aufId]) as $pa) {
                $paId = (int)$pa['id'];
                q("DELETE FROM produktion_verbrauch WHERE pa_id=?", [$paId]);
                $r['chargen'] += (int) scalar("SELECT COUNT(*) FROM charge WHERE pa_id=?", [$paId]);
                q("DELETE FROM charge WHERE pa_id=?", [$paId]);
                q("DELETE FROM produktion_schritt WHERE pa_id=?", [$paId]);
                q("DELETE FROM produktionsauftrag WHERE id=?", [$paId]);
                $r['produktionen']++;
            }
            $r['chargen'] += (int) scalar("SELECT COUNT(*) FROM charge WHERE auftrag_id=?", [$aufId]);
            q("DELETE FROM charge WHERE auftrag_id=?", [$aufId]);
            q("DELETE FROM reservierung WHERE auftrag_id=?", [$aufId]);
            if (table_exists('zahlung'))          q("DELETE FROM zahlung WHERE beleg_id IN (SELECT id FROM beleg WHERE auftrag_id=?)", [$aufId]);
            if (table_exists('beleg_status_log')) q("DELETE FROM beleg_status_log WHERE beleg_id IN (SELECT id FROM beleg WHERE auftrag_id=?)", [$aufId]);
            $r['belege'] += (int) scalar("SELECT COUNT(*) FROM beleg WHERE auftrag_id=?", [$aufId]);
            q("DELETE FROM beleg WHERE auftrag_id=?", [$aufId]);
            q("DELETE FROM auftrag WHERE id=?", [$aufId]);
            $r['auftraege']++;
        }
        q("DELETE FROM angebot_position WHERE angebot_id=?", [$angId]);
        q("DELETE FROM angebot_staffel WHERE angebot_id=?", [$angId]);
        q("DELETE FROM angebot_produkt WHERE angebot_id=?", [$angId]);
        q("DELETE FROM angebot WHERE id=?", [$angId]);
        $r['angebote']++;
    }

    // 2) Demo-Produkte – nur, wenn kein echter Vorgang mehr daran hängt
    foreach (all("SELECT id, name FROM produkt WHERE notiz='DEMO-TESTSET'") as $p) {
        $pid = (int)$p['id'];
        $haengt = (int) scalar("SELECT COUNT(*) FROM angebot WHERE produkt_id=?", [$pid])
                + (int) scalar("SELECT COUNT(*) FROM auftrag WHERE produkt_id=?", [$pid])
                + (int) scalar("SELECT COUNT(*) FROM produktionsauftrag WHERE produkt_id=?", [$pid]);
        if ($haengt > 0) { $r['behalten'][] = 'Produkt ' . $p['name'] . ' (noch in Verwendung)'; continue; }
        q("DELETE FROM produkt_preis WHERE produkt_id=?", [$pid]);
        q("UPDATE item SET produkt_id=NULL WHERE produkt_id=?", [$pid]);
        q("DELETE FROM produkt WHERE id=?", [$pid]);
        $r['produkte']++;
    }

    // 3) Demo-Rezepturen – nur, wenn kein Produkt mehr darauf zeigt
    foreach (all("SELECT id, name FROM rezeptur WHERE notiz IN ('DEMO-TESTSET','Demo-Rezeptur')") as $rz) {
        $rid = (int)$rz['id'];
        if ((int) scalar("SELECT COUNT(*) FROM produkt WHERE rezeptur_id=?", [$rid]) > 0) {
            $r['behalten'][] = 'Rezeptur ' . $rz['name'] . ' (noch von einem Produkt genutzt)'; continue;
        }
        q("UPDATE rezeptur_anfrage SET rezeptur_id=NULL WHERE rezeptur_id=?", [$rid]);
        q("DELETE FROM rezeptur_zutat WHERE rezeptur_id=?", [$rid]);
        q("DELETE FROM rezeptur WHERE id=?", [$rid]);
        $r['rezepturen']++;
    }

    // 4) Demo-Kunden – nur, wenn nichts mehr auf sie verweist
    foreach (all("SELECT id, firma FROM kunden WHERE notiz='DEMO-TESTSET'") as $k) {
        $kid = (int)$k['id'];
        $haengt = (int) scalar("SELECT COUNT(*) FROM angebot WHERE kunde_id=?", [$kid])
                + (int) scalar("SELECT COUNT(*) FROM auftrag WHERE kunde_id=?", [$kid])
                + (int) scalar("SELECT COUNT(*) FROM beleg WHERE kunde_id=?", [$kid])
                + (int) scalar("SELECT COUNT(*) FROM produkt WHERE kunde_id=?", [$kid])
                + (int) scalar("SELECT COUNT(*) FROM portal_anfrage WHERE kunde_id=?", [$kid])
                + (int) scalar("SELECT COUNT(*) FROM rezeptur_anfrage WHERE kunde_id=?", [$kid]);
        if ($haengt > 0) { $r['behalten'][] = 'Kunde ' . $k['firma'] . ' (noch in Verwendung)'; continue; }
        q("DELETE FROM kunde_marke WHERE kunde_id=?", [$kid]);
        q("DELETE FROM kunden WHERE id=?", [$kid]);
        $r['kunden']++;
    }

    log_aktivitaet('system', 0, 'team', 'Demo-Testdaten entfernt: ' . $r['angebote'] . ' Angebote, '
        . $r['auftraege'] . ' Aufträge, ' . $r['produkte'] . ' Produkte, ' . $r['rezepturen'] . ' Rezepturen, '
        . $r['kunden'] . ' Kunden.');
    return $r;
}

// ---- Startset: saubere Rezepturen + Produkte nach dem Modell ----
// Rezeptur = Rohstoff x Menge + Form. Produkt = Rezeptur x Menge + Verpackung.
// Legt je Rezeptur ein Basisprodukt an und leitet weitere Packungsgrößen über produkt_variante_id() ab –
// also über genau den Weg, den auch ein echtes Angebot geht. Nicht-löschend und idempotent:
// eine Rezeptur, die es namentlich schon gibt, wird übersprungen.
function seed_startset(): array {
    // Passenden Pressure-Seal-Deckel zum Gewinde des Behälters finden (Gewinde steht in der Notiz des Behälters).
    $deckelFuer = function (?int $verp_id): ?int {
        if (!$verp_id) return null;
        $notiz = (string) scalar("SELECT notiz FROM item WHERE id=?", [$verp_id]);
        if (!preg_match('~(\d{2}/400)~', $notiz, $m)) return null;
        $d = scalar("SELECT id FROM item WHERE kategorie='verpackung' AND verpackung_rolle='verschluss'
                     AND name LIKE ? ORDER BY id LIMIT 1", ['%' . $m[1] . ' weiß%']);
        return $d ? (int)$d : null;
    };
    // [Rezepturname, Form, [[Rohstoff-Artikelnr, mg je Einheit/Portion], ...], [Größe1, Größe2]]
    $data = [
        ['Magnesiumbisglycinat 500 mg', 'kapsel',   [['R-2692', 500]],                    [60, 120]],
        ['Vitamin C 500 mg',            'kapsel',   [['R-2690', 500]],                    [60, 120]],
        ['Zink-Bisglycinat 25 mg',      'tablette', [['R-2694', 25], ['R-2697', 200]],    [90, 180]],
        ['Magnesiumcitrat Pulver',      'pulver',   [['R-2691', 2000]],                   [150, 300]],
        ['Vitamin D3+K2 Tropfen',       'fluessig', [['R-2698', 25]],                     [50, 100]],
    ];
    $log = []; $rez = 0; $prod = 0;
    foreach ($data as [$name, $form, $zutaten, $groessen]) {
        if (scalar("SELECT id FROM rezeptur WHERE name=?", [$name])) { $log[] = "$name – schon vorhanden"; continue; }
        q("INSERT INTO rezeptur (nummer,name,darreichungsform,status,notiz) VALUES (?,?,?,?,?)",
          [naechste_nummer('RZ'), $name, $form, 'freigegeben', 'Startset – Rezeptur = Rohstoff x Menge + Form.']);
        $rid = insert_id(); $rez++;
        $sort = 0;
        foreach ($zutaten as [$artnr, $mg]) {
            $iid = (int) scalar("SELECT id FROM item WHERE artikelnummer=? AND kategorie='rohstoff'", [$artnr]);
            if (!$iid) continue;
            q("INSERT INTO rezeptur_zutat (rezeptur_id,item_id,bezeichnung,menge_mg,sort) VALUES (?,?,?,?,?)",
              [$rid, $iid, scalar("SELECT name FROM item WHERE id=?", [$iid]), $mg, $sort++]);
        }
        // Basisprodukt: erste Größe + der vom System bestimmte passende Behälter
        $g1   = (int)$groessen[0];
        $beh  = passende_behaelter_fuer($rid, $form, $g1);
        $verp = $beh ? (int)$beh[0] : null;
        if (!$verp) { $log[] = "$name – kein passender Behälter, kein Produkt angelegt"; continue; }
        q("INSERT INTO produkt (nummer,name,kunde_id,rezeptur_id,verpackung_id,verschluss_id,exklusiv,einheiten_pro_packung,einnahme_pro_tag,status,notiz)
           VALUES (?,?,NULL,?,?,?,0,?,?,?,?)",
          [naechste_nummer('P'), $name . ' · ' . form_groessen_label($form, (float)$g1) . ' · ' . scalar("SELECT name FROM item WHERE id=?", [$verp]),
           $rid, $verp, $deckelFuer($verp), $g1, 1, 'aktiv', 'Startset – Produkt = Rezeptur x Menge + Verpackung.']);
        $pid = insert_id(); $prod++;
        produkt_matrix_generieren($pid);
        // Weitere Größen über denselben Weg wie im Angebot ableiten
        foreach (array_slice($groessen, 1) as $g2) {
            $v2 = behaelter_fuer_groesse($pid, (int)$g2);
            if (!$v2) continue;
            $neu = produkt_variante_id($pid, (int)$g2, $v2);
            if ($neu && $neu !== $pid) { q("UPDATE produkt SET verschluss_id=? WHERE id=? AND verschluss_id IS NULL", [$deckelFuer($v2), $neu]); $prod++; }
        }
        $log[] = "$name ($form) angelegt";
    }
    return ['rezepturen' => $rez, 'produkte' => $prod, 'log' => $log];
}

// Zusammenhängendes Demo-Testset: Kunden + Rezepturen + Produkte + Angebote + Aufträge (offen/in Produktion/erledigt).
// NICHT-LÖSCHEND und idempotent: legt je Produkt genau ein Demo-Angebot (notiz 'DEMO-TESTSET') samt Auftrag an;
// erneuter Aufruf erzeugt keine Dubletten. Gibt eine Zusammenfassung zurück.
function demo_testset_einspielen(): array {
    $log = []; $neu = 0;
    // 1) Kunden sicherstellen
    seed_kunden_if_empty();
    $kunde = function(string $firma) use (&$log, &$neu): int {
        $kid = (int) scalar("SELECT id FROM kunden WHERE firma=?", [$firma]);
        if (!$kid) {
            q("INSERT INTO kunden (kundennummer,firma,ort,land,zahlungsart,notiz) VALUES (?,?,?,?,?,?)",
              [naechste_nummer('K'), $firma, 'Musterstadt', 'DE', 'rechnung', 'DEMO-TESTSET']);
            $kid = insert_id(); $log[] = "Kunde $firma"; $neu++;
        }
        return $kid;
    };
    // 2) Rezeptur (mit Zutaten) idempotent per Name
    $rezeptur = function(string $name, string $form, array $zutaten) use (&$log, &$neu): int {
        $rid = (int) scalar("SELECT id FROM rezeptur WHERE name=?", [$name]);
        if ($rid) return $rid;
        q("INSERT INTO rezeptur (nummer,name,darreichungsform,status,notiz) VALUES (?,?,?,?,?)",
          [naechste_nummer('RZ'), $name, $form, 'freigegeben', 'DEMO-TESTSET']);
        $rid = insert_id();
        foreach ($zutaten as $i => $z) {
            $iid = scalar("SELECT id FROM item WHERE name=?", [$z[0]]);
            q("INSERT INTO rezeptur_zutat (rezeptur_id,item_id,bezeichnung,menge_mg,sort) VALUES (?,?,?,?,?)",
              [$rid, $iid ?: null, $z[0], $z[1], $i]);
        }
        $log[] = "Rezeptur $name"; $neu++;
        return $rid;
    };
    // 3) Produkt idempotent per Name
    $produkt = function(string $name, int $kid, int $rid, ?int $vid, int $einh, int $tag) use (&$log, &$neu): int {
        $pid = (int) scalar("SELECT id FROM produkt WHERE name=?", [$name]);
        if ($pid) return $pid;
        // kunde_id bleibt LEER: ein Produkt ist kundenneutral, solange es nicht exklusiv ist.
        // (Der Kunde hängt am Angebot, nicht am Produkt.) $kid wird nur noch für das Demo-Angebot gebraucht.
        q("INSERT INTO produkt (nummer,name,kunde_id,rezeptur_id,verpackung_id,einheiten_pro_packung,einnahme_pro_tag,status,notiz)
           VALUES (?,?,NULL,?,?,?,?,?,?)",
          [naechste_nummer('P'), $name, $rid, $vid, $einh, $tag, 'aktiv', 'DEMO-TESTSET']);
        $pid = insert_id(); $log[] = "Produkt $name"; $neu++;
        return $pid;
    };
    $vid = fn(?string $vname): ?int => ($vname && ($x = scalar("SELECT id FROM item WHERE name=? AND kategorie='verpackung' LIMIT 1", [$vname])) ? (int)$x : null);

    // Definition der Demo-Produkte: [Produktname, Kunde, Rezeptname, Form, Zutaten, Verpackung, Stk/Pkg, Einnahme/Tag, Staffeln[[menge,vk]], Ziel-Auftragsstatus]
    $set = [
        ['Magnesium Komplex · 120 Kapseln', 'Alpenkraft GmbH', 'Magnesium Komplex', 'kapsel',
            [['Magnesiumcitrat',400],['Magnesiumbisglycinat',400],['Vitamin C (Ascorbinsäure)',80]],
            '250 ml Weithalsglas', 120, 2, [[500,4.9000],[1000,4.2000],[2500,3.7000]], 'offen'],
        ['Immun Booster · 90 Kapseln', 'NordVital UG', 'Immun Booster', 'kapsel',
            [['Vitamin C (Ascorbinsäure)',500],['Zink-Bisglycinat',15],['Kurkuma-Extrakt',200]],
            '150 ml PET Packer', 90, 3, [[500,3.9000],[1000,3.3000],[2500,2.9000]], 'in_produktion'],
        ['Ashwagandha Ruhe · 60 Kapseln', 'BioSana AG', 'Ashwagandha Ruhe', 'kapsel',
            [['Ashwagandha-Extrakt',300],['Magnesiumbisglycinat',100]],
            '100 ml Weithalsglas', 60, 2, [[500,3.4000],[1000,2.9000],[2500,2.5000]], 'erledigt'],
        ['Vitamin D3 + K2 · 90 Kapseln', 'Alpenkraft GmbH', 'Vitamin D3 + K2', 'kapsel',
            [['Vitamin D3 100.000 IE/g (Öl)',5],['Mikrokristalline Cellulose',150]],
            '100 ml Weithalsglas', 90, 1, [[500,2.9000],[1000,2.5000],[2500,2.2000]], 'angebot_offen'],
        // Weitere OFFENE Aufträge (können produziert werden) – verschiedene Darreichungsformen
        ['Omega-3 · 90 Softgel', 'Alpenkraft GmbH', 'Omega-3 Fischöl', 'softgel',
            [['Fischöl (EPA/DHA)',1000],['Vitamin E',15]],
            '250 ml Weithalsglas', 90, 2, [[500,5.4000],[1000,4.7000],[2500,4.1000]], 'offen'],
        ['Protein Vanille · 900 g', 'NordVital UG', 'Protein Vanille', 'pulver',
            [['Whey Protein Konzentrat',25000],['Aroma Vanille',300]],
            'Doypack-Beutel 250 g', 30, 1, [[250,12.9000],[500,11.5000],[1000,9.9000]], 'offen'],
        ['Magnesium Sticks · 20 Sticks', 'GreenPeak Nutrition', 'Magnesium Direkt', 'stick',
            [['Magnesiumcitrat',300]],
            null, 20, 1, [[500,6.9000],[1000,5.9000],[2500,4.9000]], 'offen'],
        ['Vitamin C Depot · 100 Tabletten', 'PureLife Cosmetics', 'Vitamin C Depot', 'tablette',
            [['Vitamin C (Ascorbinsäure)',1000]],
            null, 100, 1, [[500,3.9000],[1000,3.3000],[2500,2.8000]], 'offen'],
        ['Vitamin D3 Tropfen · 50 ml', 'BioSana AG', 'Vitamin D3 Tropfen', 'fluessig',
            [['Vitamin D3+K2 Tropfen',50]],
            'Braunglas 60 ml', 1, 1, [[500,4.5000],[1000,3.9000],[2500,3.4000]], 'offen'],
        // ZUKAUF: fertige Kapseln vom Lieferanten (verkürzter Weg – ohne Mischen/Verkapseln)
        ['Kurkuma Kapseln · 90 (Zukauf)', 'Alpenkraft GmbH', 'Kurkuma Kapseln', 'kapsel',
            [['Kurkuma-Extrakt',400]],
            '150 ml PET Packer', 90, 2, [[500,3.9000],[1000,3.3000],[2500,2.9000]], 'zukauf'],
        ['Zink Kapseln · 120 (Zukauf)', 'NordVital UG', 'Zink Kapseln', 'kapsel',
            [['Zink-Bisglycinat',25]],
            '100 ml Weithalsglas', 120, 1, [[500,3.4000],[1000,2.9000],[2500,2.5000]], 'zukauf'],
    ];

    foreach ($set as $d) {
        [$pname,$firma,$rname,$form,$zutaten,$vname,$einh,$tag,$staffeln,$ziel] = $d;
        $kid = $kunde($firma);
        $rid = $rezeptur($rname, $form, $zutaten);
        $pid = $produkt($pname, $kid, $rid, $vid($vname), $einh, $tag);
        // schon ein Demo-Angebot für dieses Produkt? -> dann nichts weiter (idempotent)
        if (scalar("SELECT id FROM angebot WHERE produkt_id=? AND notiz='DEMO-TESTSET' LIMIT 1", [$pid])) continue;
        // Angebot + Staffeln
        q("INSERT INTO angebot (nummer,kunde_id,produkt_id,status,notiz) VALUES (?,?,?,?,?)",
          [naechste_nummer('AN'), $kid, $pid, ($ziel==='angebot_offen'?'offen':'bestaetigt'), 'DEMO-TESTSET']);
        $anid = insert_id(); $log[] = "Angebot $pname"; $neu++;
        foreach ($staffeln as $i => $s) {
            // mittlere Staffel als bestätigt markieren (für die Auftragserzeugung)
            $best = ($i === 1 && $ziel !== 'angebot_offen') ? 1 : 0;
            q("INSERT INTO angebot_staffel (angebot_id,menge,vk_stueck,bestaetigt,sort) VALUES (?,?,?,?,?)",
              [$anid, $s[0], $s[1], $best, $i]);
        }
        if ($ziel === 'angebot_offen') continue;   // bleibt offenes Angebot, kein Auftrag

        // Auftrag + Rechnung + Produktionsauftrag über die reguläre Auto-Kette
        $aid = auftrag_aus_angebot($anid);
        if (!$aid) continue;
        $log[] = "Auftrag zu $pname"; $neu++;
        $paid = (int) scalar("SELECT id FROM produktionsauftrag WHERE auftrag_id=?", [$aid]);
        if ($ziel === 'in_produktion') {
            q("UPDATE auftrag SET status='in_produktion' WHERE id=?", [$aid]);
            if ($paid) {
                q("UPDATE produktionsauftrag SET status='laufend' WHERE id=?", [$paid]);
                // erste Hälfte der Schritte erledigen
                $steps = all("SELECT id FROM produktion_schritt WHERE pa_id=? ORDER BY sort", [$paid]);
                $bis = (int) floor(count($steps) / 2);
                for ($k = 0; $k < $bis; $k++) q("UPDATE produktion_schritt SET erledigt=1 WHERE id=?", [(int)$steps[$k]['id']]);
            }
        } elseif ($ziel === 'erledigt') {
            q("UPDATE auftrag SET status='erledigt' WHERE id=?", [$aid]);
            if ($paid) {
                q("UPDATE produktionsauftrag SET status='erledigt' WHERE id=?", [$paid]);
                q("UPDATE produktion_schritt SET erledigt=1 WHERE pa_id=?", [$paid]);
            }
        } elseif ($ziel === 'zukauf') {
            // Fertige Bulkware (Kapseln vom Lieferanten) als 'fertig'-Item + freie Charge am Auftrag -> verkürzter Weg
            $fname = 'Fertigkapseln: ' . $rname . ' (Zukauf)';
            $fid = (int) scalar("SELECT id FROM item WHERE name=? AND kategorie='fertig'", [$fname]);
            if (!$fid) {
                q("INSERT INTO item (artikelnummer,name,kategorie,einheit,preis_bezug) VALUES (?,?,?,?,?)",
                  [naechste_nummer('FP'), $fname, 'fertig', 'Stück', 'Stück']);
                $fid = insert_id();
            }
            $lief = (int) scalar("SELECT id FROM lieferanten ORDER BY id LIMIT 1");
            $amenge = (int) scalar("SELECT menge FROM auftrag WHERE id=?", [$aid]);
            if (!scalar("SELECT id FROM charge WHERE auftrag_id=? AND item_id=?", [$aid, $fid])) {
                $chid = wareneingang_buchen($fid, (float)$amenge, 'ZK-' . $aid, null, $lief ?: null, 'Zugekaufte Fertigkapseln (Demo)', $aid);
                if ($chid) q("UPDATE charge SET status='frei' WHERE id=?", [$chid]);   // QC-Freigabe simulieren
            }
            if ($paid) produktion_schritte_regenerieren($paid, true);   // verkürzter Zukauf-Weg (ohne Mischen/Verkapseln)
            // Auftrag bleibt 'offen' – kann jetzt auf dem verkürzten Weg produziert werden
        }
    }
    return ['ok'=>true, 'neu'=>$neu, 'log'=>$log];
}

// Request-lokaler Cache für app_meta: dieselben Schlüssel werden pro Seitenaufruf
// sehr oft gelesen (z. B. Margen/Aufschläge in Preisrechnungen). &-Referenz, damit
// meta_set denselben Cache aktualisieren kann.
function &meta_cache(): array { static $c = []; return $c; }
function meta_get(string $k, $default = null) {
    $c = &meta_cache();
    if (!array_key_exists($k, $c)) {
        $v = scalar("SELECT v FROM app_meta WHERE k = ?", [$k]);
        $c[$k] = $v === false ? null : $v;
    }
    return $c[$k] === null ? $default : $c[$k];
}
function meta_set(string $k, $v): void {
    q("INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$k, $v]);
    $c = &meta_cache();
    $c[$k] = (string)$v;   // Cache mitziehen, damit späterer meta_get im selben Request stimmt
}

// Novel-Food-Abgleich: Zutaten der Produkt-Rezeptur gegen den EU-Katalog (novelfood_katalog).
// Liefert ['status'=>konform|novel_food|pruefung|unklar, 'treffer'=>[...], 'grund'=>...].
function produkt_novelfood_pruefen(int $pid): array {
    $rid = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [$pid]);
    if (!$rid) return ['status' => 'unklar', 'treffer' => [], 'grund' => 'Produkt hat keine Rezeptur'];
    $zutaten = all("SELECT z.bezeichnung, i.name AS item_name, i.name_lat, i.synonym
                    FROM rezeptur_zutat z LEFT JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=?", [$rid]);
    if (!$zutaten) return ['status' => 'unklar', 'treffer' => [], 'grund' => 'Rezeptur hat keine Zutaten'];
    $kat = all("SELECT name, trivial, syn, status, status_code FROM novelfood_katalog");
    if (!$kat) return ['status' => 'unklar', 'treffer' => [], 'grund' => 'Novel-Food-Katalog ist leer – bitte importieren'];
    $norm = function ($s) {
        $s = mb_strtolower(trim((string)$s));
        $s = preg_replace('/\([^)]*\)/u', ' ', $s);          // Klammerzusätze weg
        $s = preg_replace('/[^a-z0-9äöüß ]+/u', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    };
    // Katalog-Suchbegriffe (Name + Trivial + Synonyme), nur ab 4 Zeichen gegen Falschtreffer.
    $begriffe = [];
    // Zu generische Einzelwörter (Mineralien/Vitamine/Allerweltsbegriffe) NICHT als Novel-Food-Treffer werten.
    $stop = ['magnesium'=>1,'calcium'=>1,'kalzium'=>1,'natrium'=>1,'sodium'=>1,'kalium'=>1,'potassium'=>1,'zink'=>1,'zinc'=>1,'eisen'=>1,'iron'=>1,'kupfer'=>1,'copper'=>1,'mangan'=>1,'selen'=>1,'selenium'=>1,'jod'=>1,'iodine'=>1,'chrom'=>1,'chromium'=>1,'vitamin'=>1,'wasser'=>1,'water'=>1,'salz'=>1,'salts'=>1,'salt'=>1,'extrakt'=>1,'extract'=>1,'pulver'=>1,'powder'=>1,'saeure'=>1,'acid'=>1];
    foreach ($kat as $c) {
        // Chemische Namen NICHT am "/" zerlegen (sonst wird aus "sodium/magnesium/calcium salts" das Wort "magnesium").
        $terms = [$c['name']];
        foreach (['trivial', 'syn'] as $f) foreach (preg_split('/[,;]/', (string)$c[$f]) as $t) { $t = preg_replace('/\([^)]*\)/u', '', (string)$t); if (trim($t) !== '') $terms[] = $t; }
        foreach ($terms as $t) { $nt = $norm($t); if (mb_strlen($nt) >= 5 && !isset($stop[$nt])) $begriffe[$nt] = $c; }
    }
    $treffer = []; $problem = false; $pruef = false; $gesehen = [];
    foreach ($zutaten as $z) {
        $zlabel = $z['bezeichnung'] ?: ($z['item_name'] ?: '');
        foreach ([$z['bezeichnung'], $z['item_name'], $z['name_lat'], $z['synonym']] as $cand) {
            $nz = $norm($cand); if ($nz === '') continue;
            foreach ($begriffe as $bt => $c) {
                if ($nz === $bt || preg_match('/\b' . preg_quote($bt, '/') . '\b/u', $nz)) {
                    $key = $zlabel . '|' . $c['name']; if (isset($gesehen[$key])) continue;
                    $gesehen[$key] = 1;
                    $treffer[] = ['zutat' => $zlabel, 'stoff' => $c['name'], 'status' => $c['status'], 'code' => (string)$c['status_code']];
                    if ($c['status_code'] === 'NOT_YET_AUTHORISED_NOVEL_FOOD') $problem = true;
                    elseif (in_array((string)$c['status_code'], ['AUTHORISED_NOVEL_FOOD', 'SUBJECT_TO_A_CONSULTATION_REQUEST', ''], true)) $pruef = true;
                }
            }
        }
    }
    $status = $problem ? 'novel_food' : ($pruef ? 'pruefung' : 'konform');
    return ['status' => $status, 'treffer' => $treffer, 'grund' => ''];
}

// ===== Novel-Food je Rohstoff (Item) =============================================================
// Normalisierung + Katalog-Suchbegriffe (name+trivial+syn, >=5 Zeichen, ohne generische Stopwörter).
// Gleiche Semantik wie produkt_novelfood_pruefen(); hier zentral + request-gecacht, damit die Katalog-
// Begriffe nicht je Rohstoff neu gebaut werden (bei Batch-Prüfung über 1400 Rohstoffe entscheidend).
function novelfood_norm(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/\([^)]*\)/u', ' ', $s);
    $s = preg_replace('/[^a-z0-9äöüß ]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}
function novelfood_begriffe(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    if (!table_exists('novelfood_katalog')) return $cache = [];
    $stop = ['magnesium'=>1,'calcium'=>1,'kalzium'=>1,'natrium'=>1,'sodium'=>1,'kalium'=>1,'potassium'=>1,'zink'=>1,'zinc'=>1,'eisen'=>1,'iron'=>1,'kupfer'=>1,'copper'=>1,'mangan'=>1,'selen'=>1,'selenium'=>1,'jod'=>1,'iodine'=>1,'chrom'=>1,'chromium'=>1,'vitamin'=>1,'wasser'=>1,'water'=>1,'salz'=>1,'salts'=>1,'salt'=>1,'extrakt'=>1,'extract'=>1,'pulver'=>1,'powder'=>1,'saeure'=>1,'acid'=>1];
    $begriffe = [];
    foreach (all("SELECT name, trivial, syn, status, status_code FROM novelfood_katalog") as $c) {
        $terms = [$c['name']];
        foreach (['trivial', 'syn'] as $f) foreach (preg_split('/[,;]/', (string)$c[$f]) as $t) { $t = preg_replace('/\([^)]*\)/u', '', (string)$t); if (trim($t) !== '') $terms[] = $t; }
        foreach ($terms as $t) { $nt = novelfood_norm($t); if (mb_strlen($nt) >= 5 && !isset($stop[$nt])) $begriffe[$nt] = $c; }
    }
    return $cache = $begriffe;
}
// Beliebige Kandidaten-Texte (z. B. Name, lat. Name, Synonym, botanische Quelle) gegen den Katalog prüfen.
// Rückgabe: ['status'=>konform|pruefung|novel_food|unklar, 'treffer'=>[['stoff','status','code'], …], 'grund'=>?].
function novelfood_text_pruefen(array $kandidaten): array {
    $begriffe = novelfood_begriffe();
    if (!$begriffe) return ['status' => 'unklar', 'treffer' => [], 'grund' => 'Novel-Food-Katalog ist leer – bitte importieren'];
    $treffer = []; $problem = false; $pruef = false; $gesehen = [];
    foreach ($kandidaten as $cand) {
        $nz = novelfood_norm((string)$cand); if ($nz === '') continue;
        foreach ($begriffe as $bt => $c) {
            if ($nz === $bt || preg_match('/\b' . preg_quote($bt, '/') . '\b/u', $nz)) {
                if (isset($gesehen[$c['name']])) continue;
                $gesehen[$c['name']] = 1;
                $treffer[] = ['stoff' => $c['name'], 'status' => (string)$c['status'], 'code' => (string)$c['status_code']];
                if ($c['status_code'] === 'NOT_YET_AUTHORISED_NOVEL_FOOD') $problem = true;
                elseif (in_array((string)$c['status_code'], ['AUTHORISED_NOVEL_FOOD', 'SUBJECT_TO_A_CONSULTATION_REQUEST', ''], true)) $pruef = true;
            }
        }
    }
    if (!$treffer) return ['status' => 'konform', 'treffer' => [], 'grund' => 'kein Treffer im Novel-Food-Katalog'];
    return ['status' => $problem ? 'novel_food' : ($pruef ? 'pruefung' : 'konform'), 'treffer' => $treffer, 'grund' => ''];
}
// Live-Prüfung EINES Rohstoffs (ohne Speichern).
function item_novelfood_pruefen(int $item_id): array {
    $it = one("SELECT name, name_lat, synonym, bot_quelle FROM item WHERE id=? AND kategorie='rohstoff'", [$item_id]);
    if (!$it) return ['status' => 'unklar', 'treffer' => [], 'grund' => 'Rohstoff nicht gefunden'];
    return novelfood_text_pruefen([$it['name'], $it['name_lat'] ?? '', $it['synonym'] ?? '', $it['bot_quelle'] ?? '']);
}
// Prüfen UND den Status am Rohstoff festschreiben (mit Datum = Snapshot fürs PIB). Rückgabe: der Status-String.
function item_novelfood_aktualisieren(int $item_id): string {
    $r = item_novelfood_pruefen($item_id);
    q("UPDATE item SET novelfood_status=?, novelfood_geprueft_am=?, novelfood_treffer=? WHERE id=?",
      [$r['status'], gmdate('Y-m-d H:i:s'), json_encode($r['treffer'], JSON_UNESCAPED_UNICODE), $item_id]);
    return $r['status'];
}
// Alle Rohstoffe gegen den aktuellen Katalog prüfen + Status festschreiben. Rückgabe: ['geprueft'=>n, 'novel_food'=>n, 'pruefung'=>n].
function rohstoffe_novelfood_pruefen_alle(): array {
    $begriffe = novelfood_begriffe();
    if (!$begriffe) return ['geprueft' => 0, 'novel_food' => 0, 'pruefung' => 0, 'leer' => true];
    $n = 0; $nf = 0; $pr = 0; $now = gmdate('Y-m-d H:i:s');
    foreach (all("SELECT id, name, name_lat, synonym, bot_quelle FROM item WHERE kategorie='rohstoff' AND gesperrt=0") as $it) {
        $r = novelfood_text_pruefen([$it['name'], $it['name_lat'] ?? '', $it['synonym'] ?? '', $it['bot_quelle'] ?? '']);
        q("UPDATE item SET novelfood_status=?, novelfood_geprueft_am=?, novelfood_treffer=? WHERE id=?",
          [$r['status'], $now, json_encode($r['treffer'], JSON_UNESCAPED_UNICODE), (int)$it['id']]);
        $n++; if ($r['status'] === 'novel_food') $nf++; elseif ($r['status'] === 'pruefung') $pr++;
    }
    return ['geprueft' => $n, 'novel_food' => $nf, 'pruefung' => $pr];
}
// Anzeige-Meta je Status: [ampel, label, farbe-css].
function novelfood_status_meta(?string $status): array {
    switch ((string)$status) {
        case 'konform':    return ['ampel' => 'gruen', 'label' => 'Novel-Food-konform',               'farbe' => 'var(--gruen)'];
        case 'pruefung':   return ['ampel' => 'gelb',  'label' => 'Status prüfen (zugelassen/Konsultation)', 'farbe' => 'var(--warn)'];
        case 'novel_food': return ['ampel' => 'rot',   'label' => 'Novel Food – Zulassung nötig',     'farbe' => 'var(--err)'];
        default:           return ['ampel' => 'grau',  'label' => 'noch nicht geprüft',               'farbe' => 'var(--muted)'];
    }
}
// Novel-Food-Snapshot eines PRODUKTS aus den GESPEICHERTEN Zutat-Status (+ Prüfdatum). Für das PIB:
// so ist nachvollziehbar, was zum Prüfzeitpunkt galt. Rückgabe: ['status','auffaellig'=>[[name,status]…],
// 'geprueft_am'=>frühestes Zutat-Prüfdatum,'ungeprueft'=>Anzahl Zutaten ohne Status].
function produkt_novelfood_snapshot(int $pid): array {
    $rid = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [$pid]);
    if (!$rid) return ['status' => 'unklar', 'auffaellig' => [], 'geprueft_am' => null, 'ungeprueft' => 0];
    $items = all("SELECT DISTINCT i.name, i.novelfood_status AS st, i.novelfood_geprueft_am AS am
                  FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id
                  WHERE z.rezeptur_id=? AND i.kategorie='rohstoff'", [$rid]);
    if (!$items) return ['status' => 'unklar', 'auffaellig' => [], 'geprueft_am' => null, 'ungeprueft' => 0];
    $problem = false; $pruef = false; $auff = []; $daten = []; $ungeprueft = 0;
    foreach ($items as $it) {
        $s = (string)($it['st'] ?? '');
        if ($s === '') { $ungeprueft++; continue; }
        if (!empty($it['am'])) $daten[] = (string)$it['am'];
        if ($s === 'novel_food') { $problem = true; $auff[] = ['name' => (string)$it['name'], 'status' => 'novel_food']; }
        elseif ($s === 'pruefung') { $pruef = true; $auff[] = ['name' => (string)$it['name'], 'status' => 'pruefung']; }
    }
    sort($daten);
    // Keine einzige Zutat geprüft -> 'unklar' (NICHT fälschlich 'konform' behaupten).
    if ($ungeprueft >= count($items)) return ['status' => 'unklar', 'auffaellig' => [], 'geprueft_am' => null, 'ungeprueft' => $ungeprueft];
    return ['status' => $problem ? 'novel_food' : ($pruef ? 'pruefung' : 'konform'),
            'auffaellig' => $auff, 'geprueft_am' => $daten[0] ?? null, 'ungeprueft' => $ungeprueft];
}

// Einmaliger Backfill: verpackung_id der Alt-Importe aus der v3-Wahrheit setzen (siehe Kommentar am Aufruf
// in init_schema). Zuordnung v3-Auftrag (auftrag.v3_id) -> "Material|Volumen(ml)" -> v4-Behaelter. Idempotent:
// wirkt nur auf Auftraege mit leerem verpackung_id; der Aufruf ist zusaetzlich per app_meta-Marker gegatet.
function fix_auftrag_verpackung_backfill(): void {
    // Nur relevant fuer v3-Alt-Importe: braucht die Spalte auftrag.v3_id (wird erst vom v3-Import angelegt).
    // Ohne diese Spalte (DB ohne v3-Import / frische Installation) nichts zu tun – verhindert init_schema-Crash.
    if (!scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='auftrag' AND column_name='v3_id'")) return;
    // v3 auftraege.id => "Material|ml" (aus dem v3-Dump auftraege.verpackung geparst)
    $map = [13=>'Glas|150',14=>'Glas|100',16=>'Glas|150',17=>'Glas|200',20=>'Glas|200',21=>'Glas|200',25=>'PET|150',
            29=>'Glas|150',31=>'PET|100',33=>'Glas|200',34=>'Glas|150',35=>'Glas|150',38=>'Glas|200',39=>'Glas|150',
            40=>'Glas|250',41=>'Glas|100',43=>'Glas|150',44=>'Glas|100',46=>'Glas|100',47=>'Glas|150',48=>'Glas|100',
            49=>'Glas|150',50=>'Glas|150',51=>'Glas|150',52=>'Glas|250',53=>'Glas|150',54=>'Glas|150',55=>'PET|200',
            56=>'PET|200',73=>'Glas|150',78=>'Glas|100',79=>'Glas|150',80=>'Glas|200',81=>'Glas|150',82=>'Glas|200',
            86=>'Glas|100',87=>'Glas|100',88=>'Glas|100',89=>'Glas|100',90=>'Glas|100',92=>'Glas|150'];
    $resolve = [];  // "Material|ml" -> v4-Behaelter-item-id (gecacht)
    foreach ($map as $v3id => $mv) {
        $a = one("SELECT id FROM auftrag WHERE v3_id=? AND (verpackung_id IS NULL OR verpackung_id=0)", [(int)$v3id]);
        if (!$a) continue;
        if (!isset($resolve[$mv])) {
            [$mat, $ml] = explode('|', $mv);
            $resolve[$mv] = (int) scalar(
                "SELECT id FROM item WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer'
                   AND gesperrt=0 AND material=? AND volumen_ml=?
                 ORDER BY (name LIKE '%Weithals%') DESC, id ASC LIMIT 1", [$mat, (int)$ml]);
        }
        $vid = $resolve[$mv];
        if ($vid <= 0) continue;
        q("UPDATE auftrag SET verpackung_id=? WHERE id=? AND (verpackung_id IS NULL OR verpackung_id=0)", [$vid, (int)$a['id']]);
        q("UPDATE produktionsauftrag SET verpackung_id=? WHERE auftrag_id=? AND (verpackung_id IS NULL OR verpackung_id=0)", [$vid, (int)$a['id']]);
    }
}

// Ergaenzung zum v3-Backfill: verpackung_id der noch offenen Alt-Auftraege ueber die BERECHNUNG setzen
// (Rezeptur + Stueck -> passender Behaelter je Material). Material eindeutig aus dem Verpackungstext
// (produkt_kundenpreis.verpackung); ist es nicht eindeutig, bleibt der Auftrag leer (manuelle Auswahl in der
// Produktion). Nur aktive Auftraege, nur wo verpackung_id leer ist; Cascade auf produktionsauftrag.
function fix_auftrag_verpackung_v2_berechnet(): void {
    // Braucht die v3-Import-Tabelle produkt_kundenpreis. Fehlt sie (Nicht-v3-DB), nichts zu tun – verhindert init_schema-Crash.
    if (!scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='produkt_kundenpreis'")) return;
    $rows = all("SELECT a.id, p.rezeptur_id, r.darreichungsform AS form, a.stueck,
                        (SELECT kp.verpackung FROM produkt_kundenpreis kp
                          WHERE kp.produkt_id=a.produkt_id AND kp.kunde_id=a.kunde_id AND COALESCE(kp.verpackung,'')<>'' LIMIT 1) AS kp_verp
                   FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
                  WHERE (a.verpackung_id IS NULL OR a.verpackung_id=0) AND a.status NOT IN ('versendet','storniert')");
    foreach ($rows as $a) {
        $hint = trim((string)($a['kp_verp'] ?? ''));
        $t = mb_strtolower($hint);
        // Nur bei eindeutigem Material setzen – sonst nicht raten.
        if (!str_contains($t, 'glas') && !str_contains($t, 'pet') && !str_contains($t, 'pla')) continue;
        $vid = behaelter_aus_rezeptur_stueck((int)($a['rezeptur_id'] ?? 0), (string)($a['form'] ?? ''), (int)($a['stueck'] ?? 0), $hint);
        if (!$vid) continue;
        q("UPDATE auftrag SET verpackung_id=? WHERE id=? AND (verpackung_id IS NULL OR verpackung_id=0)", [(int)$vid, (int)$a['id']]);
        q("UPDATE produktionsauftrag SET verpackung_id=? WHERE auftrag_id=? AND (verpackung_id IS NULL OR verpackung_id=0)", [(int)$vid, (int)$a['id']]);
    }
}
