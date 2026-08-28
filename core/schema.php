<?php
// Schema + additive Migrationen bulkify 4.1
// Regel: erst CREATE (frisch installierbar), dann additive Migrationen via ensure_column().
// Nie Spalten loeschen. Alles UTF-8 (utf8mb4).
require_once __DIR__ . '/db.php';

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

function init_schema(): void {
    $pdo = db();

    // app_meta: zentrale Schluessel/Wert-Einstellungen (eine Quelle)
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_meta (
        k VARCHAR(100) PRIMARY KEY,
        v TEXT NULL,
        aktualisiert DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
        waehrung VARCHAR(3) NOT NULL DEFAULT 'EUR',       -- EUR | USD | CNY
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
        gehalt_prozent DECIMAL(6,2) NULL,                 -- Gehalt dieses Wirkstoffs im Rohstoff (z. B. 95)
        sort INT NOT NULL DEFAULT 0,
        KEY idx_item (item_id),
        KEY idx_naehrstoff (naehrstoff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
        vk_stueck DECIMAL(12,4) NOT NULL DEFAULT 0,        -- VK je Packung
        bestaetigt TINYINT(1) NOT NULL DEFAULT 0,
        sort INT NOT NULL DEFAULT 0,
        KEY idx_angebot (angebot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
    ensure_column('produktionsauftrag', 'produktionsart', "VARCHAR(10) NOT NULL DEFAULT 'eigen'");   // eigen|fremd (Make-or-Buy)
    ensure_column('produktionsauftrag', 'bedarf_gemeldet', "DATETIME NULL");      // wann der Bedarf ans Einkauf gemeldet wurde

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
    ensure_column('kunden', 'portal_token', "VARCHAR(64) NULL");   // Magic-Link-Zugang zum Kundenportal
    ensure_column('item', 'produkt_id', "INT NULL");               // Verkaufsfertig-Item <-> Produkt (Fertigware-Bestand)
    ensure_column('item', 'cas', "VARCHAR(30) NULL");              // CAS-Nummer (z. B. Ascorbinsäure 50-81-7)
    ensure_column('item', 'max_fuellgewicht_g', "DECIMAL(10,2) NULL"); // Verpackung: max. Füllgewicht (g) – für Pulver-Match (Glas/Dose)
    // Verpackungs-Stückliste: Rolle je Verpackungs-Item + Produkt-Slots für die komplette Stückliste
    ensure_column('item', 'verpackung_rolle', "VARCHAR(20) NULL");  // primaer|verschluss|etikett|karton|beipack (nur kategorie=verpackung)
    ensure_column('item', 'etikett_format', "VARCHAR(40) NULL");    // z. B. 100x70 mm (nur Etikett)
    ensure_column('item', 'etikett_druck', "VARCHAR(40) NULL");     // Etikett-Druckdatei-Maß je Behälter (B x H mm, = Endformat + 3mm rundum)
    ensure_column('item', 'etikett_final', "VARCHAR(40) NULL");     // finales Etikettenformat je Behälter (B x H mm)
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
    // Portal-Freischaltungen je Kunde (welche Anfrage-Bereiche der Kunde sieht)
    ensure_column('kunden', 'portal_rezeptur', "TINYINT(1) NOT NULL DEFAULT 1");
    ensure_column('kunden', 'portal_produkte', "TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column('kunden', 'portal_rohstoffe', "TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column('kunden', 'portal_dienstleistung', "TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column('portal_anfrage', 'verpackung_typ', "VARCHAR(40) NULL");   // Kundenwunsch Verpackungstyp (Glas/PET/…); wir wählen den passenden Behälter
    ensure_column('portal_anfrage', 'fuellmenge_g', "DECIMAL(10,2) NULL");   // Pulver-Anfrage: Füllmenge je Packung (g) statt Stück je Packung
    ensure_column('portal_anfrage', 'wunsch_menge', "DECIMAL(12,3) NULL");   // Rohstoff-Anfrage: gewünschte Menge
    ensure_column('portal_anfrage', 'wunsch_einheit', "VARCHAR(10) NULL");   // Einheit dazu (kg/g/t/Stück/L)
    ensure_column('portal_anfrage', 'rohstoff_id', "INT NULL");              // Rohstoff-Anfrage: konkreter Rohstoff (item) für die Preisberechnung
    ensure_column('rezeptur_anfrage', 'produktname', "VARCHAR(190) NULL");    // Wunsch-Produktname des Kunden bei der Rezepturanfrage
    ensure_column('produkt', 'kundenname', "VARCHAR(190) NULL");              // vom Kunden gewünschter Produktname (intern = name)
    // Angebot als Preismatrix (Kunde wählt Zelle: Stückzahl × Bestellmenge) -> gewählte Werte fließen in Auftrag + Produktion
    ensure_column('auftrag', 'stueck', "INT NULL");
    ensure_column('auftrag', 'verpackung_id', "INT NULL");
    ensure_column('produktionsauftrag', 'stueck', "INT NULL");
    ensure_column('produktionsauftrag', 'verpackung_id', "INT NULL");
    ensure_column('angebot', 'kunde_ausgeblendet', "TINYINT(1) NOT NULL DEFAULT 0");  // Kunde hat es aus seiner Liste entfernt (Löschen)
    ensure_column('angebot', 'marge_override', "DECIMAL(6,2) NULL");          // je Angebot gesetzte Marge % (überschreibt Marge-je-Typ; VK = EK×(1+Marge))
    ensure_column('angebot', 'produktionszeit_wochen', "DECIMAL(5,1) NULL");  // je Angebot gesetzte Produktionszeit (Wochen); leer = globaler Wert
    ensure_column('angebot', 'anfrage_id', "INT NULL");                       // Herkunft: portal_anfrage (angefragte Konfiguration fürs Angebots-PDF)
    // Rohstoff-Spezifikation (nur das Unterscheidende; Reinheits-Grenzwerte bleiben im PDF)
    ensure_column('item', 'synonym', "VARCHAR(60) NULL");            // z. B. RM940
    ensure_column('item', 'ec_nr', "VARCHAR(30) NULL");
    ensure_column('item', 'bot_quelle', "VARCHAR(190) NULL");        // botanische Quelle / Pflanzenteil
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
    ensure_column('item', 'vk_aufschlag_prozent', "DECIMAL(6,2) NULL"); // Rohstoff-Verkauf: eigener Aufschlag % (leer = globaler aufschlag_rohstoff)
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

    // portal_anfrage: Kundenanfragen aus dem Portal für Produkt / Rohstoff / Dienstleistung (Rezeptur läuft separat über rezeptur_anfrage).
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

// Kapsel-Fassung einer Verpackung als [kapselgroesse_id => stueck].
function pack_kapazitaet_fuer(int $item_id): array {
    $out = [];
    foreach (all("SELECT kapselgroesse_id, stueck FROM pack_kapazitaet WHERE item_id=?", [$item_id]) as $r)
        $out[(int)$r['kapselgroesse_id']] = (int)$r['stueck'];
    return $out;
}

// Kapselgröße einer Kapsel-Rezeptur: bevorzugt die AM REZEPT gespeicherte Größe (kapselgroesse_id),
// sonst die kleinste Größe, in die das Füllgewicht je Kapsel passt. Gibt Zeile aus kapselgroesse oder null.
function rezeptur_kapselgroesse(int $rezeptur_id): ?array {
    $gid = (int) scalar("SELECT kapselgroesse_id FROM rezeptur WHERE id=?", [$rezeptur_id]);
    if ($gid > 0) { $kg = one("SELECT * FROM kapselgroesse WHERE id=?", [$gid]); if ($kg) return $kg; }
    $weight = (float) scalar("SELECT COALESCE(SUM(menge_mg),0) FROM rezeptur_zutat WHERE rezeptur_id=?", [$rezeptur_id]);
    if ($weight <= 0) return null;
    $kg = one("SELECT * FROM kapselgroesse WHERE fuellmenge_mg >= ? ORDER BY fuellmenge_mg ASC LIMIT 1", [$weight]);
    return $kg ?: null;
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

// Station Verkapselung: Leerkapseln nach FEFO abbuchen (menge × einheiten je Packung). Blockiert bei zu wenig Bestand.
function produktion_kapseln_entnehmen(int $pa_id): array {
    $pa = one("SELECT menge, produkt_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return ['ok'=>true, 'fehlt'=>[]];
    $kid = produkt_leerkapsel_id((int)$pa['produkt_id']);
    if (!$kid) return ['ok'=>true, 'fehlt'=>[]];                       // kein Kapselprodukt / nicht bestimmbar -> nichts abbuchen
    if ((int) scalar("SELECT COUNT(*) FROM produktion_verbrauch WHERE pa_id=? AND item_id=?", [$pa_id, $kid]) > 0) return ['ok'=>true, 'fehlt'=>[]];
    $einh = (int) scalar("SELECT einheiten_pro_packung FROM produkt WHERE id=?", [$pa['produkt_id']]);
    $benoetigt = (float)$pa['menge'] * $einh;                          // Gesamt-Kapseln
    $verf = item_bestand($kid, true);
    if ($verf + 0.0001 < $benoetigt) {
        return ['ok'=>false, 'fehlt'=>[['name'=> scalar("SELECT name FROM item WHERE id=?", [$kid]), 'benoetigt'=>$benoetigt, 'verfuegbar'=>$verf, 'fehlt'=>$benoetigt-$verf, 'einheit'=>'Stück']]];
    }
    $rest = $benoetigt;
    foreach (all("SELECT * FROM charge WHERE item_id=? AND status='frei' AND menge_verfuegbar>0 ORDER BY (mhd IS NULL), mhd ASC, id ASC", [$kid]) as $c) {
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
    $einh = (int) scalar("SELECT einheiten_pro_packung FROM produkt WHERE id=?", [(int)$pa['produkt_id']]);
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

// Fertigware eines abgeschlossenen Produktionsauftrags als Charge einbuchen. Idempotent (charge_nr = PR-Nummer).
function produktion_fertigware_einbuchen(int $pa_id): ?int {
    $pa = one("SELECT nummer, produkt_id, menge FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa || !$pa['produkt_id']) return null;
    if ((int) scalar("SELECT COUNT(*) FROM charge WHERE charge_nr=?", [$pa['nummer']]) > 0) return null;  // schon eingebucht
    $item_id = produkt_lageritem((int)$pa['produkt_id']);
    if (!$item_id) return null;
    // Fulfillment-Kunde? → Fertigware gehört ins Lager 2, BSKU (Brücke) sicherstellen.
    $kunde = one("SELECT k.nutzt_fulfillment FROM produkt p JOIN kunden k ON k.id=p.kunde_id WHERE p.id=?", [(int)$pa['produkt_id']]);
    if ($kunde && (int)$kunde['nutzt_fulfillment'] === 1) bsku_ensure($item_id);
    q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,wareneingang,status,notiz,angelegt)
       VALUES (?,?,?,?, 'Stück', CURDATE(), 'frei', ?, ?)",
      [$pa['nummer'], $item_id, (int)$pa['menge'], (int)$pa['menge'], 'Aus Produktion ' . $pa['nummer'], gmdate('Y-m-d H:i:s')]);
    return insert_id();
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
// Alle Lager-2-Produkte (Verkaufsfertig-Items von Fulfillment-Kunden) mit Bestand + Brücken-Feldern.
function lager2_produkte(?int $kunde_id = null): array {
    $sql = "SELECT i.id AS item_id, i.artikelnummer, i.name, i.bsku, i.shopify_inventory_item_id,
                   p.id AS produkt_id, p.nummer AS produkt_nr, COALESCE(NULLIF(p.kundenname,''),p.name) AS anzeigename,
                   k.id AS kunde_id, k.firma AS kunde
            FROM item i
            JOIN produkt p ON p.id=i.produkt_id
            JOIN kunden k ON k.id=p.kunde_id
            WHERE i.kategorie='verkaufsfertig' AND k.nutzt_fulfillment=1";
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
    return insert_id();
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

// Nächste feste Nummer für einen Präfix, z. B. naechste_nummer('K') -> "K-0001". Atomar hochgezählt.
function naechste_nummer(string $prefix): string {
    $prefix = strtoupper(trim($prefix));
    // Start bei 2690, dann frei hochzählen (Stellen wachsen mit)
    q("INSERT IGNORE INTO nummernkreis (prefix, naechste, stellen) VALUES (?, 2690, 4)", [$prefix]);
    q("UPDATE nummernkreis SET naechste = naechste + 1 WHERE prefix = ?", [$prefix]);
    $r = one("SELECT naechste - 1 AS nr, stellen FROM nummernkreis WHERE prefix = ?", [$prefix]);
    return $prefix . '-' . str_pad((string)$r['nr'], (int)$r['stellen'], '0', STR_PAD_LEFT);
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
function item_bestand(int $item_id, bool $nur_frei = true): float {
    $status = $nur_frei ? "status='frei'" : "status IN ('frei','quarantaene')";
    return (float) scalar("SELECT COALESCE(SUM(menge_verfuegbar),0) FROM charge WHERE item_id=? AND $status", [$item_id]);
}

// Wareneingang buchen -> neue Charge. Rohstoffe landen in Quarantäne, Rest direkt frei.
function wareneingang_buchen(int $item_id, float $menge, string $charge_nr, ?string $mhd, ?int $lieferant_id, string $notiz = '', ?int $auftrag_id = null, ?int $bestellung_position_id = null): ?int {
    $it = one("SELECT kategorie, einheit FROM item WHERE id=?", [$item_id]);
    if (!$it || $menge <= 0) return null;
    $status = item_braucht_quarantaene($it['kategorie']) ? 'quarantaene' : 'frei';
    q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,lieferant_id,mhd,wareneingang,status,notiz,auftrag_id,bestellung_position_id,angelegt)
       VALUES (?,?,?,?,?,?,?,CURDATE(),?,?,?,?,?)",
      [$charge_nr ?: null, $item_id, $menge, $menge, $it['einheit'], $lieferant_id ?: null, $mhd ?: null, $status, $notiz ?: null, $auftrag_id ?: null, $bestellung_position_id ?: null, gmdate('Y-m-d H:i:s')]);
    return insert_id();
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
function rezeptur_kosten_pro_einheit(?int $rid): float {
    if (!$rid) return 0.0;
    $c = 0.0;
    foreach (all("SELECT z.menge_mg, i.ek_preis, i.preis_bezug, i.dichte
                  FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=?", [$rid]) as $z) {
        $mg = (float)$z['menge_mg']; $pb = $z['preis_bezug']; $ek = (float)$z['ek_preis'];
        $perMg = $pb === 'kg' ? $ek/1e6 : ($pb === 'g' ? $ek/1e3 : ($pb === 'L' && $z['dichte'] ? ($ek/(1000*(float)$z['dichte']))/1e3 : 0));
        $c += $mg * $perMg;
    }
    return $c;
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

// Behälter-EK bei einer Bestellmenge: passende Staffel, sonst flacher item.ek_preis.
function pack_ek_bei_menge(int $verp_id, int $menge): float {
    $st = one("SELECT ek_preis FROM pack_ek_staffel WHERE item_id=? AND menge_ab<=? ORDER BY menge_ab DESC LIMIT 1", [$verp_id, $menge]);
    if ($st) return (float) $st['ek_preis'];
    return (float) scalar("SELECT ek_preis FROM item WHERE id=?", [$verp_id]);
}
// Leerkapsel-EK je Stück für ein Produkt (0 wenn nicht bestimmbar / kein Kapselprodukt).
function produkt_kapsel_ek(int $produkt_id): float {
    $kid = produkt_leerkapsel_id($produkt_id);
    return $kid ? (float) scalar("SELECT ek_preis FROM item WHERE id=?", [$kid]) : 0.0;
}
// Herstellungs-EK je Packung (nur Rezeptur-Füllung + Leerkapseln). Der BEHÄLTER kommt separat
// als eigene Angebotsposition (Dose/Deckel/Etikett kommen extra – EK-Staffel × Verpackungs-Aufschlag).
function produkt_variante_ek(int $produkt_id, int $stueck, int $verp_id = 0, int $bestellmenge = 0): float {
    $rid = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [$produkt_id]);
    $fuell = rezeptur_kosten_pro_einheit($rid ?: null) * $stueck;
    $kaps  = produkt_kapsel_ek($produkt_id) * $stueck;
    return $fuell + $kaps;
}

// ---- Verpackung als eigene Position (Dose/Deckel/Etikett kommen extra) ----
// Aufschlag % für einen Verpackungsartikel: eigener Wert am Artikel, sonst globaler aufschlag_verpackung.
function verpackung_aufschlag_prozent(int $item_id): float {
    $o = scalar("SELECT vk_aufschlag_prozent FROM item WHERE id=?", [$item_id]);
    if ($o !== null && trim((string) $o) !== '') return (float) $o;
    return (float) meta_get('aufschlag_verpackung', 30);
}
// VK je Stück eines Verpackungsartikels bei einer Bestellmenge = EK-Staffel × (1 + Aufschlag). Ohne Kundenrabatt.
function verpackung_vk_bei_menge(int $item_id, int $menge): float {
    // Direkter VK-Override je Bestellmenge hat Vorrang (von Hand im Verkauf-Reiter gesetzt).
    $vk = scalar("SELECT vk_preis FROM pack_vk_staffel WHERE item_id=? AND menge_ab<=? ORDER BY menge_ab DESC LIMIT 1", [$item_id, $menge]);
    if ($vk !== null && $vk !== false) return (float) $vk;
    return pack_ek_bei_menge($item_id, $menge) * (1 + verpackung_aufschlag_prozent($item_id) / 100);
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
function produkt_verpackung_items(int $produkt_id): array {
    $p = one("SELECT verpackung_id, verschluss_id, etikett_id FROM produkt WHERE id=?", [$produkt_id]);
    if (!$p) return [];
    $out = [];
    foreach (['verpackung_id'=>'Verpackung', 'verschluss_id'=>'Deckel', 'etikett_id'=>'Etikett'] as $f => $rolle) {
        if (!empty($p[$f])) {
            $it = one("SELECT id, name, artikelnummer FROM item WHERE id=?", [(int)$p[$f]]);
            if ($it) $out[] = ['rolle' => $rolle, 'id' => (int)$it['id'], 'name' => $it['name'], 'artikelnummer' => $it['artikelnummer']];
        }
    }
    return $out;
}
// Summe Verpackungs-VK je Packung (Dose+Deckel+Etikett) bei einer Bestellmenge, mit Kundenrabatt.
function produkt_verpackung_vk_je_pack(int $produkt_id, int $menge, ?int $kunde_id): float {
    $s = 0.0;
    foreach (produkt_verpackung_items($produkt_id) as $vp) $s += verpackung_vk_bei_menge($vp['id'], $menge);
    return vk_fuer_kunde($s, $kunde_id);
}
// Verpackungs-Summe je Packung in CENT (jede Position einzeln auf Cent gerundet – wie auf dem Beleg).
function verpackung_cent_je_pack(int $produkt_id, int $bestellmenge, ?int $kunde_id): int {
    $c = 0;
    foreach (produkt_verpackung_items($produkt_id) as $vp) $c += (int) round(vk_fuer_kunde(verpackung_vk_bei_menge($vp['id'], $bestellmenge), $kunde_id) * 100);
    return $c;
}
// Netto (Cent) für eine Angebotszelle: (Herstellung + Verpackung) je Packung, je Position auf Cent gerundet, × Bestellmenge.
function angebot_zelle_netto_cent(int $produkt_id, int $stueck, int $bestellmenge, ?int $kunde_id): int {
    $vkH = scalar("SELECT vk_preis FROM produkt_preis WHERE produkt_id=? AND stueck=? AND bestellmenge=? ORDER BY vk_preis ASC LIMIT 1", [$produkt_id, $stueck, $bestellmenge]);
    if ($vkH === null || $vkH === false) return 0;
    $hCent = (int) round(vk_fuer_kunde((float)$vkH, $kunde_id) * 100);
    return ($hCent + verpackung_cent_je_pack($produkt_id, $bestellmenge, $kunde_id)) * $bestellmenge;
}

// ---- Angebots-Positionen (Hybrid: automatisch erzeugt, überschreibbar) ----
// USt-Satz für einen Kunden: Kleinunternehmer/EU-Ausland -> 0 %, sonst Inland-Satz.
function angebot_ust_satz(?int $kunde_id): float {
    if ((string) meta_get('kleinunternehmer', '0') === '1') return 0.0;
    $land = strtoupper(trim((string) (scalar("SELECT land FROM kunden WHERE id=?", [$kunde_id]) ?? '')));
    $inland = ($land === '' || in_array($land, ['DE','D','DEUTSCHLAND','GERMANY'], true));
    return $inland ? (float) meta_get('ust_inland', 19) : 0.0;
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
// Für eine Gruppe: primäre Stückzahl + Bestellmenge aus der Matrix bestimmen (mit Fallback aufs Standardraster).
function _angebot_feat(array $matrix, bool $istPulver, array $g): array {
    $featStk = $istPulver ? (float)($g['fuellmenge_g'] ?? 0) : (int)($g['stueck'] ?? 0);
    if (!$featStk || !isset($matrix[$featStk])) { foreach (std_stueckzahlen() as $s2) if (isset($matrix[$s2])) { $featStk = $s2; break; } }
    $primaer = $g['mengen'] ? min($g['mengen']) : 0;
    if (!$primaer || !isset($matrix[$featStk][$primaer])) { $primaer = 0; foreach (std_bestellmengen() as $bm2) if (isset($matrix[$featStk][$bm2])) { $primaer = $bm2; break; } }
    return [$featStk, $primaer];
}
// Positionen EINER Konfigurationsgruppe (Herstellung mit Rezeptur + Verpackung). $letter = Gruppenbuchstabe oder null.
// Ohne Präfix in der Bezeichnung – die A)/B)-Kennzeichnung macht angebot_positionen_prefix() bzw. der PDF-/Editor-Renderer.
function angebot_gruppe_positionen(array $g, ?float $mo, ?int $kid, ?string $letter = null): array {
    $pid = (int)$g['produkt_id'];
    if ((int) scalar("SELECT COUNT(*) FROM produkt_preis WHERE produkt_id=?", [$pid]) === 0) produkt_matrix_generieren($pid);
    $matrix = angebot_matrix($pid, $mo);
    $ust = angebot_ust_satz($kid);
    $form = (string) scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$pid]) ?: 'kapsel';
    $istPulver = in_array($form, ['pulver','granulat','stick'], true);
    $formPl = ['kapsel'=>'Kapseln','tablette'=>'Tabletten','softgel'=>'Softgels','stick'=>'Sticks','pulver'=>'g','granulat'=>'g','fluessig'=>'ml'][$form] ?? 'Stück';
    [$featStk, $featMenge] = _angebot_feat($matrix, $istPulver, $g);
    $pname = (string) scalar("SELECT COALESCE(NULLIF(kundenname,''), name) FROM produkt WHERE id=?", [$pid]) ?: 'Produkt';
    $stkLabel = $istPulver ? (rtrim(rtrim(number_format((float)$featStk,1,',','.'),'0'),',') . ' g') : ((int)$featStk . ' ' . $formPl);
    $cell = ($featStk && $featMenge && isset($matrix[$featStk][$featMenge])) ? $matrix[$featStk][$featMenge] : ['vk'=>0.0,'ek'=>0.0];
    $rid = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [$pid]);
    $rezLines = []; $totalMg = 0.0;
    foreach ($rid ? all("SELECT bezeichnung, menge_mg FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [$rid]) : [] as $z) {
        $mg = (float)$z['menge_mg']; $totalMg += $mg;
        $rezLines[] = $z['bezeichnung'] . ' ' . rtrim(rtrim(number_format($mg, 2, ',', ''), '0'), ',') . 'mg';
    }
    $sumMg = (int) round($totalMg / 10) * 10;
    if ($istPulver) $summary = '~' . $sumMg . 'mg je Portion, ' . $stkLabel . ' je Packung';
    else {
        $summary = '~' . $sumMg . 'mg, ' . (int)$featStk . ' ' . $formPl;
        $kg = rezeptur_kapselgroesse($rid ?: 0);
        if ($kg && !empty($kg['name'])) $summary .= ', #' . trim(str_ireplace(['Größe', 'Gr.', 'Gr'], '', $kg['name']));
    }
    $besch = $rezLines ? (implode("\n", $rezLines) . "\n" . $summary) : $summary;
    $out = [[
        'artikelnr'=>'', 'bezeichnung'=>$pname, 'beschreibung'=>$besch,
        'menge'=>(float)($featMenge ?: 1), 'einheit'=>'Pkg.',
        'preis_cent'=>(int) round(vk_fuer_kunde($cell['vk'], $kid) * 100),
        'ek_cent'=>(int) round($cell['ek'] * 100), 'mwst_satz'=>$ust, 'quelle'=>'herstellung', 'gruppe'=>$letter,
    ]];
    foreach (produkt_verpackung_items($pid) as $vp) {
        $out[] = [
            'artikelnr'=>$vp['artikelnummer'] ?? '', 'bezeichnung'=>$vp['rolle'] . ': ' . $vp['name'], 'beschreibung'=>'',
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
        $matrix = angebot_matrix($pid, $mo);
        $form = (string) scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$pid]) ?: 'kapsel';
        $istPulver = in_array($form, ['pulver','granulat','stick'], true);
        [$featStk, $primaer] = _angebot_feat($matrix, $istPulver, $g);
        $mengen = $g['mengen'] ?: ($primaer ? [$primaer] : []);
        $rows = [];
        foreach ($mengen as $bm) {
            if (!isset($matrix[$featStk][$bm])) continue;
            $allinCent = (int) round(vk_fuer_kunde($matrix[$featStk][$bm]['vk'], $kid) * 100) + verpackung_cent_je_pack($pid, (int)$bm, $kid);
            $rows[] = ['ab'=>(int)$bm, 'stueck_cent'=>(!$istPulver && $featStk) ? (int) round($allinCent / $featStk) : null, 'pack_cent'=>$allinCent];
        }
        if ($rows) {
            $pname = (string) scalar("SELECT COALESCE(NULLIF(kundenname,''), name) FROM produkt WHERE id=?", [$pid]) ?: 'Produkt';
            $letter = $mehrere ? chr(65 + $idx) . ') ' : '';
            $formPl = ['kapsel'=>'Kapseln','tablette'=>'Tabletten','softgel'=>'Softgels','stick'=>'Sticks','pulver'=>'g','granulat'=>'g','fluessig'=>'ml'][$form] ?? 'Stück';
            $stkLabel = $istPulver ? (rtrim(rtrim(number_format((float)$featStk,1,',','.'),'0'),',') . ' g') : ((int)$featStk . ' ' . $formPl);
            $out[] = ['name'=>$letter . $pname . ' · ' . $stkLabel, 'mpp'=>$istPulver ? 0 : $featStk, 'rows'=>$rows];
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
        q("INSERT INTO angebot_position (angebot_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,ek_cent,mwst_satz,quelle,gruppe) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
          [$aid, $sort++, $p['artikelnr'] ?? '', $p['bezeichnung'], $p['beschreibung'] ?? '', (float)$p['menge'], $p['einheit'] ?? '', (int)$p['preis_cent'], (int)($p['ek_cent'] ?? 0), (float)($p['mwst_satz'] ?? 0), $p['quelle'] ?? 'manuell', $letter]);
    }
    $all = all("SELECT id,bezeichnung,gruppe FROM angebot_position WHERE angebot_id=? ORDER BY sort,id", [$aid]);
    foreach (angebot_positionen_prefix($all) as $p) q("UPDATE angebot_position SET bezeichnung=? WHERE id=?", [$p['bezeichnung'], (int)$p['id']]);
}
// Positionszeilen aus einer REZEPTUR (frei gewählte Stückzahl + Verpackungen), ohne Produkt-SKU.
function angebot_rezeptur_zeilen(int $rid, int $stueck, array $verp_ids, int $menge, ?float $mo, ?int $kid): array {
    $r = one("SELECT name, darreichungsform FROM rezeptur WHERE id=?", [$rid]); if (!$r) return [];
    $form = $r['darreichungsform'] ?: 'kapsel';
    $istPulver = in_array($form, ['pulver','granulat','stick'], true);
    $istKapsel = in_array($form, ['kapsel','softgel'], true);
    $formPl = ['kapsel'=>'Kapseln','tablette'=>'Tabletten','softgel'=>'Softgels','stick'=>'Sticks','pulver'=>'g','granulat'=>'g','fluessig'=>'ml'][$form] ?? 'Stück';
    $ust = angebot_ust_satz($kid);
    $menge = max(1, $menge);
    $ekH = rezeptur_kosten_pro_einheit($rid) * $stueck + ($istKapsel ? leerkapsel_ek_fuer_rezeptur($rid) * $stueck : 0);
    $marge = $mo !== null ? $mo : max(marge_typ_prozent($form), marge_min_prozent());
    $vkH = vk_fuer_kunde($ekH * (1 + $marge/100), $kid);
    // Rezeptur-Beschreibung (je Zutat eine Zeile + Zusammenfassung)
    $rezLines = []; $totalMg = 0.0;
    foreach (all("SELECT bezeichnung, menge_mg FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [$rid]) as $z) {
        $mg = (float)$z['menge_mg']; $totalMg += $mg;
        $rezLines[] = $z['bezeichnung'] . ' ' . rtrim(rtrim(number_format($mg, 2, ',', ''), '0'), ',') . 'mg';
    }
    $sumMg = (int) round($totalMg / 10) * 10;
    $stkLabel = $istPulver ? ((int)$stueck . ' g') : ((int)$stueck . ' ' . $formPl);
    if ($istPulver) $summary = '~' . $sumMg . 'mg je Portion, ' . $stkLabel . ' je Packung';
    else {
        $summary = '~' . $sumMg . 'mg, ' . (int)$stueck . ' ' . $formPl;
        $kg = rezeptur_kapselgroesse($rid);
        if ($kg && !empty($kg['name'])) $summary .= ', #' . trim(str_ireplace(['Größe', 'Gr.', 'Gr'], '', $kg['name']));
    }
    $besch = $rezLines ? (implode("\n", $rezLines) . "\n" . $summary) : $summary;
    $rows = [[
        'artikelnr'=>'', 'bezeichnung'=>$r['name'], 'beschreibung'=>$besch,
        'menge'=>(float)$menge, 'einheit'=>'Pkg.', 'preis_cent'=>(int) round($vkH * 100),
        'ek_cent'=>(int) round($ekH * 100), 'mwst_satz'=>$ust, 'quelle'=>'herstellung',
    ]];
    foreach ($verp_ids as $vid) {
        $vid = (int)$vid; if (!$vid) continue;
        $vp = one("SELECT name, artikelnummer, verpackung_rolle FROM item WHERE id=? AND kategorie='verpackung'", [$vid]);
        if (!$vp) continue;
        $rolleLbl = ['primaer'=>'Verpackung','verschluss'=>'Deckel','etikett'=>'Etikett','karton'=>'Karton','beipack'=>'Beipack'][$vp['verpackung_rolle'] ?? 'primaer'] ?? 'Verpackung';
        $rows[] = [
            'artikelnr'=>$vp['artikelnummer'] ?? '', 'bezeichnung'=>$rolleLbl . ': ' . $vp['name'], 'beschreibung'=>'',
            'menge'=>(float)$menge, 'einheit'=>'Stück',
            'preis_cent'=>(int) round(vk_fuer_kunde(verpackung_vk_bei_menge($vid, $menge), $kid) * 100),
            'ek_cent'=>(int) round(pack_ek_bei_menge($vid, $menge) * 100), 'mwst_satz'=>$ust, 'quelle'=>'verpackung',
        ];
    }
    return $rows;
}
// Positionszeile aus einem ROHSTOFF (Weiterverkauf): EK-Staffel × Aufschlag, Kundenrabatt.
function angebot_rohstoff_zeile(int $item_id, float $menge, string $einheit, ?int $kid): array {
    $it = one("SELECT name, artikelnummer, preis_bezug FROM item WHERE id=? AND kategorie='rohstoff'", [$item_id]); if (!$it) return [];
    $menge = $menge > 0 ? $menge : 1;
    $vk = vk_fuer_kunde(rohstoff_vk_bei_menge($item_id, $menge) ?? 0.0, $kid);
    $ek = rohstoff_ek_bei_menge($item_id, $menge) ?? 0.0;
    return [[
        'artikelnr'=>$it['artikelnummer'] ?? '', 'bezeichnung'=>$it['name'], 'beschreibung'=>'',
        'menge'=>(float)$menge, 'einheit'=>$einheit ?: ($it['preis_bezug'] ?: 'kg'),
        'preis_cent'=>(int) round($vk * 100), 'ek_cent'=>(int) round($ek * 100),
        'mwst_satz'=>angebot_ust_satz($kid), 'quelle'=>'rohstoff',
    ]];
}
// Positionen eines Angebots: gespeicherte (überschrieben) haben Vorrang, sonst automatisch.
function angebot_positionen(int $angebot_id): array {
    $rows = all("SELECT * FROM angebot_position WHERE angebot_id=? ORDER BY sort, id", [$angebot_id]);
    if ($rows) return array_map(fn($r) => [
        'artikelnr'=>$r['artikelnr'], 'bezeichnung'=>$r['bezeichnung'], 'beschreibung'=>$r['beschreibung'],
        'menge'=>(float)$r['menge'], 'einheit'=>$r['einheit'], 'preis_cent'=>(int)$r['preis_cent'],
        'ek_cent'=>(int)$r['ek_cent'], 'mwst_satz'=>(float)$r['mwst_satz'], 'quelle'=>$r['quelle'], 'gruppe'=>$r['gruppe'] ?? null,
    ], $rows);
    $a = one("SELECT * FROM angebot WHERE id=?", [$angebot_id]);
    return $a ? angebot_positionen_auto($a) : [];
}
function angebot_hat_positionen(int $angebot_id): bool {
    return (int) scalar("SELECT COUNT(*) FROM angebot_position WHERE angebot_id=?", [$angebot_id]) > 0;
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
    $rab = (float) scalar("SELECT rabatt_marge FROM kunden WHERE id=?", [$kunde_id]);
    return $vk * (1 - $rab / 100);
}

// ---- Rohstoff-Preise (Weiterverkauf an Kunden): EK-Staffel + Aufschlag ----
// Günstigster Lieferanten-EK je Bezugseinheit bei einer Menge (je Lieferant die passende
// Staffel menge_ab<=Menge, dann der günstigste Lieferant). Fallback: flacher item.ek_preis.
// Hinweis: rechnet in der Bezugseinheit des Rohstoffs; Fremdwährungen (waehrung != EUR)
// werden aktuell nicht umgerechnet (später ergänzbar).
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

// Passende Behälter je Stückzahl bestimmen – je Darreichungsform über die richtige Kennzahl.
// Kapsel: fasst >= Stück Kapseln (pack_kapazitaet). Pulver/Granulat/Stick: max. Füllgewicht (g) >= Portionen × Portionsgewicht.
// Rückgabe: je Material der kleinste passende Behälter [item_id, ...].
function passende_behaelter_fuer(int $rezeptur_id, string $form, int $stueck): array {
    if (in_array($form, ['kapsel', 'softgel'], true)) {
        $kg = rezeptur_kapselgroesse($rezeptur_id);
        if (!$kg) return [];
        $cands = all("SELECT pk.item_id, i.material FROM pack_kapazitaet pk JOIN item i ON i.id=pk.item_id
                      WHERE pk.kapselgroesse_id=? AND pk.stueck>=? AND i.gesperrt=0
                      ORDER BY (i.material IS NULL), i.material, pk.stueck ASC", [(int)$kg['id'], $stueck]);
    } elseif (in_array($form, ['pulver', 'granulat', 'stick'], true)) {
        $portionG = (float) scalar("SELECT COALESCE(SUM(menge_mg),0) FROM rezeptur_zutat WHERE rezeptur_id=?", [$rezeptur_id]) / 1000;
        if ($portionG <= 0) return [];
        $fillG = $portionG * $stueck;
        $cands = all("SELECT id AS item_id, material FROM item
                      WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND gesperrt=0
                        AND max_fuellgewicht_g IS NOT NULL AND max_fuellgewicht_g >= ?
                      ORDER BY (material IS NULL), material, max_fuellgewicht_g ASC", [$fillG]);
    } else {
        return [];   // fluessig u. a. später (nach Volumen)
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
    foreach (std_stueckzahlen() as $stueck) {
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
    }
    return $anz;
}

// Stationen/Gates einer Produktion je Darreichungsform.
function produktionsschritte_fuer(string $form, bool $zukauf = false): array {
    // Zugekaufte fertige Bulkware (fertige Kapseln/Tabletten vom Lieferanten):
    // kein Rohstoff-Bereitstellen/Mischen/Verkapseln – nur bereitstellen, verpacken, etikettieren, prüfen.
    if ($zukauf) {
        return ['Fertigware bereitstellen', 'Verpacken', 'Etikettieren',
                'Qualitätsprüfung', 'Produktions-Freigabe', 'Versand-Freigabe'];
    }
    $herstellung = match ($form) {
        'kapsel'   => 'Verkapselung',
        'tablette' => 'Tablettierung',
        'softgel'  => 'Softgel-Herstellung',
        'stick'    => 'Stick-Abfüllung',
        'pulver'   => 'Pulver-Abfüllung',
        'fluessig' => 'Abfüllung',
        default    => 'Herstellung',
    };
    return ['Rohstoffe bereitstellen', 'Mischen', $herstellung, 'Verpacken', 'Etikettieren',
            'Qualitätsprüfung', 'Produktions-Freigabe', 'Versand-Freigabe'];
}

// Produktionsbereitschaft: ist das Material komplett da, um den Auftrag zu produzieren?
// Rückgabe: ['status'=>bereit|wartet|laeuft|fertig|unbekannt, 'fehlend'=>[['name','benoetigt','verfuegbar','einheit'], ...]]
function produktion_bereitschaft(int $pa_id): array {
    $pa = one("SELECT * FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return ['status'=>'unbekannt', 'fehlend'=>[]];
    if ($pa['status'] === 'erledigt') return ['status'=>'fertig', 'fehlend'=>[]];
    if ((int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=? AND erledigt=1", [$pa_id]) > 0)
        return ['status'=>'laeuft', 'fehlend'=>[]];

    // Voller Stücklisten-Bedarf inkl. Verpackung/Etiketten, netto (Reservierungen anderer abgezogen).
    $fehlend = [];
    foreach (auftrag_bedarf($pa_id) as $r)
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
    return (int) scalar("SELECT COUNT(*) FROM charge c JOIN item i ON i.id=c.item_id
                         WHERE c.auftrag_id=? AND i.kategorie='fertig'", [$auftrag_id]) > 0;
}

// Produktionsschritte neu erzeugen (Weg umstellen) – nur solange KEIN Schritt erledigt ist.
function produktion_schritte_regenerieren(int $pa_id, bool $zukauf): bool {
    if ((int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=? AND erledigt=1", [$pa_id]) > 0) return false;
    $pid  = (int) scalar("SELECT produkt_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $form = (string) (scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$pid]) ?: 'kapsel');
    q("DELETE FROM produktion_schritt WHERE pa_id=?", [$pa_id]);
    foreach (produktionsschritte_fuer($form, $zukauf) as $i => $station)
        q("INSERT INTO produktion_schritt (pa_id,station,sort,erledigt) VALUES (?,?,?,0)", [$pa_id, $station, $i]);
    q("UPDATE produktionsauftrag SET status='offen' WHERE id=?", [$pa_id]);
    return true;
}

// --- Bestandsreservierung (manuell) ---
function item_reserviert_andere(int $item_id, int $auftrag_id): float {
    return (float) scalar("SELECT COALESCE(SUM(menge),0) FROM reservierung WHERE item_id=? AND status='aktiv' AND (auftrag_id IS NULL OR auftrag_id<>?)", [$item_id, $auftrag_id]);
}
function item_reserviert_eigen(int $item_id, int $auftrag_id): float {
    return (float) scalar("SELECT COALESCE(SUM(menge),0) FROM reservierung WHERE item_id=? AND status='aktiv' AND auftrag_id=?", [$item_id, $auftrag_id]);
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
    return $n;
}
function auftrag_reservierung_freigeben(int $pa_id): void {
    q("UPDATE reservierung SET status='storniert' WHERE pa_id=? AND status='aktiv'", [$pa_id]);
}
// Bei physischer Entnahme: aktive Reservierung des Auftrags für dieses Item schließen (sonst doppelte Sperre).
function reservierung_verbrauchen(int $auftrag_id, int $item_id): void {
    if (!$auftrag_id) return;
    q("UPDATE reservierung SET status='verbraucht' WHERE auftrag_id=? AND item_id=? AND status='aktiv'", [$auftrag_id, $item_id]);
}
// Nach Produktionsschritten: Reservierungen für bereits (teil)entnommene Items schließen.
function reservierung_abgleichen(int $pa_id): void {
    $aid = (int) scalar("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$aid) return;
    q("UPDATE reservierung SET status='verbraucht'
       WHERE auftrag_id=? AND status='aktiv' AND item_id IN (SELECT item_id FROM produktion_verbrauch WHERE pa_id=?)", [$aid, $pa_id]);
}

// Kompletter Einkaufsbedarf eines Auftrags (Stückliste × Menge vs. freier Bestand).
// Rückgabe je Komponente: ['rolle','item_id','name','benoetigt','verfuegbar','fehlt','einheit']
function auftrag_bedarf(int $pa_id): array {
    $pa = one("SELECT * FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return [];
    $aid = (int)$pa['auftrag_id'];
    $menge = (int)$pa['menge'];
    $einh  = (int) scalar("SELECT einheiten_pro_packung FROM produkt WHERE id=?", [(int)$pa['produkt_id']]);
    $einheiten = $menge * $einh;
    $rows = [];
    // Zukauf des Bulks (Kapseln/Tabletten/Pulver) – wenn so entschieden (Fremdproduktion) ODER schon zugekauft eingegangen.
    // Verpackung + Etiketten braucht es TROTZDEM (werden unten immer angehängt).
    $zukauf = produktion_ist_zukauf((int)$pa['auftrag_id']) || ($pa['produktionsart'] ?? 'eigen') === 'fremd';
    if ($zukauf) {
        $verf = (float) scalar("SELECT COALESCE(SUM(c.menge_verfuegbar),0) FROM charge c JOIN item i ON i.id=c.item_id
                                WHERE c.auftrag_id=? AND i.kategorie='fertig' AND c.status='frei'", [(int)$pa['auftrag_id']]);
        $rows[] = ['rolle'=>'Fertigware','item_id'=>0,'name'=>'Bulk (Kapseln/Tabletten/Pulver) – Zukauf','benoetigt'=>$einheiten,'verfuegbar'=>$verf,'fehlt'=>max(0.0,$einheiten-$verf),'einheit'=>'Stück'];
    } else {
        foreach (produktion_materialbedarf($pa_id) as $m)
            $rows[] = ['rolle'=>'Rohstoff','item_id'=>$m['item_id'],'name'=>$m['name'],'benoetigt'=>$m['benoetigt'],'verfuegbar'=>$m['verfuegbar'],'fehlt'=>$m['fehlt'],'einheit'=>$m['einheit']];
        $kapId = produkt_leerkapsel_id((int)$pa['produkt_id']);
        if ($kapId && $einheiten > 0) {
            $verfK = item_bestand($kapId, true);
            $rows[] = ['rolle'=>'Leerkapsel','item_id'=>$kapId,'name'=>scalar("SELECT name FROM item WHERE id=?",[$kapId]),'benoetigt'=>$einheiten,'verfuegbar'=>$verfK,'fehlt'=>max(0.0,$einheiten-$verfK),'einheit'=>'Stück'];
        }
    }
    // Verpackungs-Stückliste (alle Slots) – je Packung 1 Stück
    $slots = one("SELECT verpackung_id, verschluss_id, etikett_id, karton_id, beipack_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]);
    foreach (['verpackung_id'=>'Verpackung','verschluss_id'=>'Deckel','etikett_id'=>'Etikett','karton_id'=>'Karton','beipack_id'=>'Beipackzettel'] as $f => $rolle) {
        if (!empty($slots[$f]) && $menge > 0) {
            $iid = (int)$slots[$f]; $verf = item_bestand($iid, true);
            $rows[] = ['rolle'=>$rolle,'item_id'=>$iid,'name'=>scalar("SELECT name FROM item WHERE id=?",[$iid]),'benoetigt'=>$menge,'verfuegbar'=>$verf,'fehlt'=>max(0.0,$menge-$verf),'einheit'=>'Stück'];
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
// Kombinierte Bestellung für EINEN Lieferant. $itemPositionen = [['item_id','menge','auftrag_id'(0=Lager)], ...] + Bulk (produkt_ids).
function bestellung_erstellen(array $itemPositionen, array $bulkProduktIds, ?int $lieferant, ?string $datum, array $freiIds = []): int {
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
    return $bid;
}
// Offener freier Einkaufsbedarf (ohne Produktionsbezug), inkl. Lieferant-Firma.
function freibedarf_offen(): array {
    return all("SELECT f.*, l.firma AS lieferant_firma FROM freibedarf f
                LEFT JOIN lieferanten l ON l.id=f.lieferant_id
                WHERE f.status='offen' ORDER BY f.angelegt DESC");
}
// Offener Fehlbedarf (nur bestellbare Positionen mit item_id, abzüglich schon offen bestellter Menge für diesen Auftrag).
function auftrag_fehlbedarf(int $pa_id): array {
    $pa = one("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $aid = $pa ? (int)$pa['auftrag_id'] : 0;
    $out = [];
    foreach (auftrag_bedarf($pa_id) as $r) {
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
    return true;
}
function etikett_del(int $auftrag_id): void {
    $d = etikett_datei($auftrag_id);
    if ($d) { @unlink(BX_UPLOADS . '/' . basename((string)$d['datei'])); q("DELETE FROM dokument WHERE id=?", [(int)$d['id']]); }
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
    $wo = "pa.status IN ('offen','laufend') AND pa.auftrag_id IS NOT NULL"
        . ($nur_gemeldet ? " AND pa.bedarf_gemeldet IS NOT NULL" : "");
    foreach (all("SELECT pa.id, pa.auftrag_id, a.nummer AS auftrag_nr FROM produktionsauftrag pa
                  LEFT JOIN auftrag a ON a.id=pa.auftrag_id WHERE $wo") as $pa) {
        foreach (auftrag_bedarf((int)$pa['id']) as $r) {
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
            $a['etikett_ok'] = etikett_vorhanden($aid);
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
function bedarf_bulk(bool $nur_gemeldet = false): array {
    $wo = "pa.status IN ('offen','laufend') AND pa.auftrag_id IS NOT NULL AND pa.produktionsart='fremd'"
        . ($nur_gemeldet ? " AND pa.bedarf_gemeldet IS NOT NULL" : "");
    // Gleiches Produkt (= gleiche Kapsel/Bulk) über mehrere Aufträge zusammenfassen.
    $grp = [];
    foreach (all("SELECT pa.id, pa.auftrag_id, pa.menge, pa.produkt_id, a.nummer AS auftrag_nr,
                         p.name AS produkt, p.einheiten_pro_packung AS ep
                  FROM produktionsauftrag pa LEFT JOIN auftrag a ON a.id=pa.auftrag_id
                  LEFT JOIN produkt p ON p.id=pa.produkt_id
                  WHERE $wo ORDER BY pa.prio, pa.angelegt") as $pa) {
        $need = (int)$pa['menge'] * (int)$pa['ep'];
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
        $g['bestellt'] = $bestellt;
        $g['zu_bestellen'] = max(0.0, $g['need'] - $bestellt);
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
        foreach ($g['orders'] as $o) {
            $offen = (float) scalar("SELECT COALESCE(SUM(bp.menge),0) FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id
                                     WHERE bp.item_id IS NULL AND bp.auftrag_id=? AND b.status<>'geliefert'", [(int)$o['auftrag_id']]);
            $noch = (float)$o['need'] - $offen;
            if ($noch <= 1e-6) continue;
            q("INSERT INTO bestellung_position (bestellung_id,item_id,bezeichnung,menge,ek_preis,einheit,auftrag_id,sort) VALUES (?,?,?,?,?,?,?,?)",
              [$bid, null, 'Bulk: ' . $g['produkt'] . ' (' . $o['auftrag_nr'] . ')', $noch, 0, 'Stück', (int)$o['auftrag_id'], $i++]);
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
    return $bid;
}

// Hat dieser Produktionsauftrag noch offenen (nicht bestellten) Bedarf? (Komponenten/Etikett oder Fremd-Bulk)
function auftrag_offener_bedarf(int $pa_id): bool {
    if (auftrag_fehlbedarf($pa_id)) return true;
    $pa = one("SELECT auftrag_id, menge, produkt_id, produktionsart FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if ($pa && ($pa['produktionsart'] ?? 'eigen') === 'fremd') {
        $einh = (int) scalar("SELECT einheiten_pro_packung FROM produkt WHERE id=?", [(int)$pa['produkt_id']]);
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

// Materialbedarf eines Produktionsauftrags: je Rohstoff benötigte vs. verfügbare Menge.
function produktion_materialbedarf(int $pa_id): array {
    $pa = one("SELECT menge, produkt_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return [];
    $prod = one("SELECT rezeptur_id, einheiten_pro_packung FROM produkt WHERE id=?", [$pa['produkt_id']]);
    if (!$prod || !$prod['rezeptur_id']) return [];
    $einheiten_total = (int)$pa['menge'] * (int)$prod['einheiten_pro_packung'];
    $out = [];
    foreach (all("SELECT z.item_id, z.menge_mg, i.name, i.einheit
                  FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=?", [$prod['rezeptur_id']]) as $z) {
        $mg = (float)$z['menge_mg'] * $einheiten_total;
        $faktor = $z['einheit'] === 'g' ? 1e3 : 1e6;          // mg -> Basiseinheit (kg-Standard)
        $benoetigt = $mg / $faktor;
        $verf = item_bestand((int)$z['item_id'], true);
        $out[] = ['item_id'=>(int)$z['item_id'], 'name'=>$z['name'], 'einheit'=>$z['einheit'],
                  'benoetigt'=>$benoetigt, 'verfuegbar'=>$verf, 'fehlt'=>max(0.0, $benoetigt - $verf)];
    }
    return $out;
}

// Rohstoffe für einen Produktionsauftrag nach FEFO entnehmen. Idempotent; blockiert bei zu wenig Bestand.
function produktion_rohstoffe_entnehmen(int $pa_id): array {
    if ((int) scalar("SELECT COUNT(*) FROM produktion_verbrauch WHERE pa_id=?", [$pa_id]) > 0) return ['ok'=>true, 'fehlt'=>[]];
    $bedarf = produktion_materialbedarf($pa_id);
    $fehlt = array_values(array_filter($bedarf, fn($b) => $b['fehlt'] > 0.0001));
    if ($fehlt) return ['ok'=>false, 'fehlt'=>$fehlt];
    foreach ($bedarf as $b) {
        $rest = $b['benoetigt'];
        foreach (all("SELECT * FROM charge WHERE item_id=? AND status='frei' AND menge_verfuegbar>0
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
    foreach (all("SELECT * FROM charge WHERE item_id=? AND status='frei' AND menge_verfuegbar>0 ORDER BY (mhd IS NULL), mhd ASC, id ASC", [$vid]) as $c) {
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
function auftrag_versenden(int $auftrag_id): array {
    $a = one("SELECT * FROM auftrag WHERE id=? AND status='erledigt'", [$auftrag_id]);
    if (!$a) return ['ok'=>false, 'msg'=>'Auftrag ist nicht versandbereit (Produktion muss abgeschlossen sein).'];
    $vfitem = (int) (scalar("SELECT id FROM item WHERE produkt_id=? AND kategorie='verkaufsfertig' LIMIT 1", [$a['produkt_id']]) ?: 0);
    $verf = $vfitem ? item_bestand($vfitem, true) : 0;
    if ($verf + 0.0001 < (float)$a['menge']) return ['ok'=>false, 'msg'=>'Nicht genug Fertigware im Lager (' . (int)$verf . ' von ' . (int)$a['menge'] . ').'];
    // Fertigware FEFO ausbuchen
    $rest = (float)$a['menge'];
    foreach (all("SELECT * FROM charge WHERE item_id=? AND status='frei' AND menge_verfuegbar>0 ORDER BY (mhd IS NULL), mhd ASC, id ASC", [$vfitem]) as $c) {
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
    return ['ok'=>true, 'msg'=>'Versendet.'];
}

// Auto-Kette: bestätigtes Angebot -> Auftragsbestätigung (AB) + Rechnung (RE) + Produktionsauftrag (PR). Idempotent.
function auftrag_aus_angebot(int $angebot_id): ?int {
    $a = one("SELECT * FROM angebot WHERE id=? AND status='bestaetigt'", [$angebot_id]);
    if (!$a) return null;
    $vorhanden = scalar("SELECT id FROM auftrag WHERE angebot_id=?", [$angebot_id]);
    if ($vorhanden) return (int)$vorhanden;                       // schon erzeugt -> nicht doppelt
    $s = one("SELECT * FROM angebot_staffel WHERE angebot_id=? AND bestaetigt=1 ORDER BY sort LIMIT 1", [$angebot_id]);
    if (!$s) return null;
    $menge = (int)$s['menge']; $vk = (float)$s['vk_stueck']; $netto = round($menge * $vk, 2);
    q("INSERT INTO auftrag (nummer,angebot_id,kunde_id,produkt_id,menge,vk_stueck,gesamt_netto,status)
       VALUES (?,?,?,?,?,?,?,?)",
      [naechste_nummer('AB'), $angebot_id, $a['kunde_id'], $a['produkt_id'], $menge, $vk, $netto, 'offen']);
    $aid = insert_id();
    // Rechnung: Kleinunternehmer 0 %, EU-Ausland 0 %, sonst Inlands-USt aus den Einstellungen (Standard 19 %)
    $land = scalar("SELECT land FROM kunden WHERE id=?", [$a['kunde_id']]) ?: 'DE';
    $ustInland = (float) meta_get('ust_inland', 19);
    $ustP = (meta_get('kleinunternehmer', '0') === '1' || $land !== 'DE') ? 0.0 : $ustInland;
    $ust = round($netto * $ustP / 100, 2); $brutto = $netto + $ust;
    q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum)
       VALUES (?,?,?,?,?,?,?,?,?,CURDATE())",
      [naechste_nummer('RE'), 'rechnung', $aid, $a['kunde_id'], $netto, $ustP, $ust, $brutto, 'offen']);
    // Produktionsauftrag (PR) + Stationen automatisch anlegen
    $form = scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$a['produkt_id']]) ?: 'kapsel';
    q("INSERT INTO produktionsauftrag (nummer,auftrag_id,kunde_id,produkt_id,menge,status) VALUES (?,?,?,?,?,?)",
      [naechste_nummer('PR'), $aid, $a['kunde_id'], $a['produkt_id'], $menge, 'offen']);
    $paid = insert_id();
    foreach (produktionsschritte_fuer($form) as $i => $station) {
        q("INSERT INTO produktion_schritt (pa_id,station,sort,erledigt) VALUES (?,?,?,0)", [$paid, $station, $i]);
    }
    if ($a['kunde_id']) log_aktivitaet('kunde', (int)$a['kunde_id'], 'team', 'Auftragsbestätigung, Rechnung & Produktionsauftrag automatisch erzeugt.', 'auftrag', 'auftrag', $aid);
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
    $netto = angebot_zelle_netto_cent((int)$a['produkt_id'], $stueck, $bestellmenge, (int)$a['kunde_id']) / 100;   // Herstellung + Verpackung, belegkonform gerundet
    q("INSERT INTO auftrag (nummer,angebot_id,kunde_id,produkt_id,menge,stueck,verpackung_id,vk_stueck,gesamt_netto,status)
       VALUES (?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('AB'), $angebot_id, $a['kunde_id'], $a['produkt_id'], $menge, $stueck, $verp_id, $vk, $netto, 'offen']);
    $aid = insert_id();
    $land = scalar("SELECT land FROM kunden WHERE id=?", [$a['kunde_id']]) ?: 'DE';
    $ustP = (meta_get('kleinunternehmer', '0') === '1' || $land !== 'DE') ? 0.0 : (float) meta_get('ust_inland', 19);
    $ust = round($netto * $ustP / 100, 2); $brutto = $netto + $ust;
    q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum)
       VALUES (?,?,?,?,?,?,?,?,?,CURDATE())",
      [naechste_nummer('RE'), 'rechnung', $aid, $a['kunde_id'], $netto, $ustP, $ust, $brutto, 'offen']);
    $form = scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$a['produkt_id']]) ?: 'kapsel';
    q("INSERT INTO produktionsauftrag (nummer,auftrag_id,kunde_id,produkt_id,menge,stueck,verpackung_id,status) VALUES (?,?,?,?,?,?,?,?)",
      [naechste_nummer('PR'), $aid, $a['kunde_id'], $a['produkt_id'], $menge, $stueck, $verp_id, 'offen']);
    $paid = insert_id();
    foreach (produktionsschritte_fuer($form) as $i => $station) q("INSERT INTO produktion_schritt (pa_id,station,sort,erledigt) VALUES (?,?,?,0)", [$paid, $station, $i]);
    if ($a['kunde_id']) log_aktivitaet('kunde', (int)$a['kunde_id'], 'team', 'Angebot bestätigt (' . $stueck . ' Stück/Pkg × ' . $menge . '), Auftrag + Rechnung + Produktion erzeugt.', 'auftrag', 'auftrag', $aid);
    return $aid;
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
function bank_konten(): array {
    $out = [];
    foreach ([['de','bank_de_name','bank_de_iban'], ['int','bank_int_name','bank_int_iban']] as $kf) {
        [$key, $nk, $ik] = $kf;
        $name = trim((string) meta_get($nk, '')); $iban = trim((string) meta_get($ik, ''));
        if ($name === '' && $iban === '') continue;
        $tail = $iban !== '' ? ' · …' . substr(preg_replace('/\s+/', '', $iban), -4) : '';
        $out[] = ['key' => $key, 'label' => ($name ?: strtoupper($key)) . $tail];
    }
    return $out;
}
// Anzeigename eines gespeicherten Kontos anhand des key/Werts (Fallback: der gespeicherte Wert selbst).
function bank_konto_label(?string $konto): string {
    if (!$konto) return '';
    foreach (bank_konten() as $bk) if ($bk['key'] === $konto) return $bk['label'];
    return $konto;
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
            q("INSERT INTO kunden (kundennummer,firma,ort,land,zahlungsart) VALUES (?,?,?,?,?)",
              [naechste_nummer('K'), $firma, 'Musterstadt', 'DE', 'rechnung']);
            $kid = insert_id(); $log[] = "Kunde $firma"; $neu++;
        }
        return $kid;
    };
    // 2) Rezeptur (mit Zutaten) idempotent per Name
    $rezeptur = function(string $name, string $form, array $zutaten) use (&$log, &$neu): int {
        $rid = (int) scalar("SELECT id FROM rezeptur WHERE name=?", [$name]);
        if ($rid) return $rid;
        q("INSERT INTO rezeptur (nummer,name,darreichungsform,status,notiz) VALUES (?,?,?,?,?)",
          [naechste_nummer('RZ'), $name, $form, 'freigegeben', 'Demo-Rezeptur']);
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
        q("INSERT INTO produkt (nummer,name,kunde_id,rezeptur_id,verpackung_id,einheiten_pro_packung,einnahme_pro_tag,status)
           VALUES (?,?,?,?,?,?,?,?)",
          [naechste_nummer('P'), $name, $kid, $rid, $vid, $einh, $tag, 'aktiv']);
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

function meta_get(string $k, $default = null) {
    $v = scalar("SELECT v FROM app_meta WHERE k = ?", [$k]);
    return $v === false ? $default : $v;
}
function meta_set(string $k, $v): void {
    q("INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$k, $v]);
}
