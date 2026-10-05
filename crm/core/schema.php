<?php
// Die eigenen Tabellen des CRM. ALLE heissen `crm_...`, damit auf einen Blick klar ist, was uns
// gehoert und was dem Dashboard. Dashboard-Tabellen werden hier NIE angefasst - weder angelegt
// noch geaendert. Wer dort etwas lesen will, geht ueber core/erp.php.
//
// Wie im Dashboard: laeuft bei jedem Aufruf, ist idempotent (CREATE IF NOT EXISTS + additive
// Spalten). Damit muss niemand ein Migrationsskript von Hand starten.
require_once __DIR__ . '/db.php';

function crm_spalte(string $tabelle, string $spalte, string $definition): void {
    // Best-effort: eine fehlende Spalte darf NIE die ganze Seite killen. Schlaegt das ALTER fehl
    // (z. B. Rechte, Sonderfall), wird es geloggt und die Seite laeuft trotzdem - nur das eine
    // Feature, das die Spalte braucht, fehlt dann. Sonst steht das komplette CRM mit 500 still.
    try {
        $da = scalar("SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", [$tabelle, $spalte]);
        if (!$da) q("ALTER TABLE `$tabelle` ADD COLUMN `$spalte` $definition");
    } catch (Throwable $e) {
        error_log('crm_schema: Spalte ' . $tabelle . '.' . $spalte . ' konnte nicht angelegt werden: ' . $e->getMessage());
    }
}

function crm_schema(): void {
    static $fertig = false;
    if ($fertig) return;
    $fertig = true;

    // --- Kontakt: ein Mensch oder eine Firma, die noch KEIN Kundenkonto hat. -------------------
    // Wird daraus ein Kunde, bleibt der Kontakt bestehen und zeigt per kunde_id dorthin.
    q("CREATE TABLE IF NOT EXISTS crm_kontakt (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        name          VARCHAR(190) NOT NULL,
        firma         VARCHAR(190) NULL,
        email         VARCHAR(190) NULL,
        telefon       VARCHAR(60)  NULL,
        whatsapp      VARCHAR(60)  NULL,
        quelle        VARCHAR(20)  NOT NULL DEFAULT 'sonstiges',   -- whatsapp|messe|mail|telefon|empfehlung|website|sonstiges
        phase         VARCHAR(20)  NOT NULL DEFAULT 'neu',         -- neu|gespraech|angebot|gewonnen|verloren
        wert_eur      DECIMAL(12,2) NULL,
        notiz         TEXT NULL,
        besitzer_id   INT NULL,                                    -- benutzer.id, wer sich kuemmert
        kunde_id      INT NULL,                                    -- gesetzt, sobald daraus ein Kunde wurde
        archiviert    TINYINT(1) NOT NULL DEFAULT 0,
        angelegt      DATETIME NOT NULL,
        aktualisiert  DATETIME NULL,
        KEY (kunde_id), KEY (phase), KEY (besitzer_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Verlauf: jede Beruehrung eine Zeile. Haengt am Kontakt ODER direkt an einem Kunden. ----
    q("CREATE TABLE IF NOT EXISTS crm_verlauf (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        kontakt_id  INT NULL,
        kunde_id    INT NULL,
        typ         VARCHAR(20) NOT NULL DEFAULT 'notiz',   -- notiz|anruf|whatsapp|mail|treffen|angebot
        text        TEXT NOT NULL,
        benutzer_id INT NULL,
        angelegt    DATETIME NOT NULL,
        KEY (kontakt_id), KEY (kunde_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Wiedervorlage: das Herzstueck. Haengt an irgendetwas (Bezug) oder steht fuer sich. -----
    // bezug_typ/bezug_id zeigen wahlweise auf einen Kontakt oder auf einen Dashboard-Vorgang
    // (angebot, portal_anfrage, rezeptur_anfrage, kunde). Es wird NICHTS dorthin geschrieben -
    // die Wiedervorlage merkt sich nur, worum es geht.
    q("CREATE TABLE IF NOT EXISTS crm_wiedervorlage (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        titel        VARCHAR(200) NOT NULL,
        notiz        TEXT NULL,
        faellig      DATE NOT NULL,
        bezug_typ    VARCHAR(30) NULL,
        bezug_id     INT NULL,
        benutzer_id  INT NULL,
        erledigt_am  DATETIME NULL,
        erledigt_von INT NULL,
        angelegt     DATETIME NOT NULL,
        KEY (faellig), KEY (bezug_typ, bezug_id), KEY (benutzer_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Termin: Rueckruf, Messe, Besuch. Zeit statt nur Datum. --------------------------------
    q("CREATE TABLE IF NOT EXISTS crm_termin (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        titel       VARCHAR(200) NOT NULL,
        start_at    DATETIME NOT NULL,
        ende_at     DATETIME NULL,
        ort         VARCHAR(190) NULL,
        notiz       TEXT NULL,
        bezug_typ   VARCHAR(30) NULL,
        bezug_id    INT NULL,
        benutzer_id INT NULL,
        erledigt    TINYINT(1) NOT NULL DEFAULT 0,
        angelegt    DATETIME NOT NULL,
        KEY (start_at), KEY (bezug_typ, bezug_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Weggeklickt: was in der Liste nicht mehr auftauchen soll. ------------------------------
    // Ein Dashboard-Vorgang laesst sich nicht "erledigen", ohne im Dashboard etwas zu aendern -
    // und genau das soll das CRM nicht tun. Stattdessen merkt es sich hier, dass DU die Zeile
    // erledigt hast. Kommt der Vorgang spaeter neu in Bewegung, wird der Haken aufgehoben.
    q("CREATE TABLE IF NOT EXISTS crm_erledigt (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        bezug_typ   VARCHAR(30) NOT NULL,
        bezug_id    INT NOT NULL,
        stand       VARCHAR(40) NULL,        -- Zustand des Vorgangs beim Wegklicken (z. B. Zeitstempel)
        benutzer_id INT NULL,
        angelegt    DATETIME NOT NULL,
        UNIQUE KEY bezug (bezug_typ, bezug_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Fragenkatalog fuers Erstgespraech (aus v3 uebernommen). ------------------------------
    // Eigene Tabelle statt einer Spalte am Kontakt: So haengt derselbe Katalog wahlweise an einem
    // Kontakt ODER an einem Kunden des Dashboards - und an dessen Tabellen fassen wir nichts an.
    q("CREATE TABLE IF NOT EXISTS crm_briefing (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        bezug_typ VARCHAR(20) NOT NULL,      -- kontakt|kunde
        bezug_id  INT NOT NULL,
        inhalt    MEDIUMTEXT NOT NULL,       -- Markdown, sechs Bloecke
        modell    VARCHAR(60) NULL,
        stand     DATETIME NOT NULL,
        UNIQUE KEY bezug (bezug_typ, bezug_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Einstellungen des CRM (Schluessel/Wert), damit nichts im Dashboard gespeichert wird. ---
    q("CREATE TABLE IF NOT EXISTS crm_meta (
        schluessel VARCHAR(60) PRIMARY KEY,
        wert       TEXT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Dokumente am Kontakt (Angebot/Abschluss/Rechnung/Sonstiges) - Verkaeufer-Workflow. ------
    // Datei liegt in data/ (gitignored), heruntergeladen wird ueber public/crm/kontakt_doc.php.
    // Best-effort (try/catch): scheitert das CREATE, laeuft der Rest des CRM trotzdem.
    try {
        q("CREATE TABLE IF NOT EXISTS crm_kontakt_datei (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            kontakt_id  INT NOT NULL,
            kategorie   VARCHAR(20) NOT NULL DEFAULT 'sonstiges',
            original    VARCHAR(255) NOT NULL,
            stored      VARCHAR(190) NOT NULL,
            groesse     INT NOT NULL DEFAULT 0,
            benutzer_id INT NULL,
            angelegt    DATETIME NOT NULL,
            KEY (kontakt_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('crm_schema: crm_kontakt_datei konnte nicht angelegt werden: ' . $e->getMessage());
    }

    // --- E-Mail-Eingang: automatisch abgeholte Mails aus dem Postfach crm@bulkify.pro. ----------
    // Dienen als "Eingang" mit KI-Vorschau; mit einem Klick wird daraus Kontakt/Notiz/Wiedervorlage.
    // Best-effort (try/catch): scheitert das CREATE, laeuft der Rest des CRM trotzdem.
    try {
        q("CREATE TABLE IF NOT EXISTS crm_mail_eingang (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            message_id  VARCHAR(255) NULL,
            imap_uid    INT NULL,
            von_name    VARCHAR(190) NULL,
            von_email   VARCHAR(190) NULL,
            betreff     VARCHAR(255) NULL,
            datum       DATETIME NULL,
            body        MEDIUMTEXT NULL,
            art         VARCHAR(20) NULL,
            daten_json  MEDIUMTEXT NULL,
            status      VARCHAR(12) NOT NULL DEFAULT 'neu',   -- neu|angelegt|verworfen
            kontakt_id  INT NULL,
            kunde_id    INT NULL,
            angelegt    DATETIME NOT NULL,
            bearbeitet  DATETIME NULL,
            UNIQUE KEY msg (message_id(191)),
            KEY (status)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('crm_schema: crm_mail_eingang konnte nicht angelegt werden: ' . $e->getMessage());
    }

    // --- KI-Rezepturvorschlag am Kontakt/Kunden (Verkaeufer-Workflow im CRM). ------------------
    // Wie crm_briefing (Fragenkatalog): ein Vorschlag je Kontakt ODER Kunde, als JSON gespeichert.
    // Best-effort (try/catch): scheitert das CREATE, laeuft der Rest des CRM trotzdem.
    try {
        q("CREATE TABLE IF NOT EXISTS crm_rezeptur_ki (
            id        INT AUTO_INCREMENT PRIMARY KEY,
            bezug_typ VARCHAR(20) NOT NULL,      -- kontakt|kunde
            bezug_id  INT NOT NULL,
            inhalt    MEDIUMTEXT NOT NULL,       -- JSON (Vorschlag)
            modell    VARCHAR(60) NULL,
            stand     DATETIME NOT NULL,
            UNIQUE KEY bezug (bezug_typ, bezug_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('crm_schema: crm_rezeptur_ki konnte nicht angelegt werden: ' . $e->getMessage());
    }

    // --- Nachtraeglich ergaenzte Spalten (additiv, idempotent). --------------------------------
    // Wann die KI die Anfrage dieses Kontakts ausgewertet hat (core/lead_ki.php). Leer = noch nie.
    crm_spalte('crm_kontakt', 'ki_ausgewertet', 'DATETIME NULL');
    // Wann die Phase zuletzt gewechselt hat - fuer "seit X Tagen in dieser Phase" + Nachfass-Warnung.
    crm_spalte('crm_kontakt', 'phase_at', 'DATETIME NULL');
    // Qualifizierung/Segmentierung (Verkaeufer-Workflow, aus v3). Dropdown-Werte siehe crm_segfelder().
    foreach (['kontaktart', 'erfahrung', 'zielmarkt', 'nische', 'firmentyp', 'volumen', 'prioritaet'] as $sp) {
        crm_spalte('crm_kontakt', $sp, 'VARCHAR(30) NULL');
    }
    crm_spalte('crm_kontakt', 'land', 'VARCHAR(120) NULL');
    crm_spalte('crm_kontakt', 'website', 'VARCHAR(190) NULL');
    crm_spalte('crm_kontakt', 'moeglichkeiten', 'TEXT NULL');
    crm_spalte('crm_kontakt', 'besonderheiten', 'TEXT NULL');
    // Strukturierte Anfrage-Daten (von der KI gefuellt oder von Hand) - fuer den Anfrage-Block.
    crm_spalte('crm_kontakt', 'anfrage_rezeptur', 'VARCHAR(255) NULL');
    crm_spalte('crm_kontakt', 'anfrage_form', 'VARCHAR(120) NULL');
    crm_spalte('crm_kontakt', 'anfrage_inhalt', 'VARCHAR(120) NULL');
    crm_spalte('crm_kontakt', 'anfrage_vorhaben', 'VARCHAR(190) NULL');
}

function crm_meta_lesen(string $schluessel, string $standard = ''): string {
    $w = scalar("SELECT wert FROM crm_meta WHERE schluessel=?", [$schluessel]);
    return $w === null ? $standard : (string)$w;
}
function crm_meta_schreiben(string $schluessel, string $wert): void {
    q("INSERT INTO crm_meta (schluessel, wert) VALUES (?,?) ON DUPLICATE KEY UPDATE wert=VALUES(wert)", [$schluessel, $wert]);
}

// Token fuer den Website-Eingang (public/crm/lead_intake.php). Wird beim ersten Zugriff einmalig
// erzeugt und in crm_meta gemerkt; ueber die CRM-Systemseite („Mehr") neu erzeugbar.
function lead_intake_token(): string {
    $t = crm_meta_lesen('lead_intake_token', '');
    if ($t === '') { $t = bin2hex(random_bytes(24)); crm_meta_schreiben('lead_intake_token', $t); }
    return $t;
}
