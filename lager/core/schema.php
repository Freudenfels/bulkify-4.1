<?php
// Die eigenen Tabellen des Lager-Programms. ALLE heissen `lg_...`. Dashboard-Tabellen werden hier
// NIE angelegt oder geaendert - wer dort etwas lesen will, geht ueber core/erp.php.
//
// Laeuft bei jedem Aufruf, ist idempotent (CREATE IF NOT EXISTS + additive Spalten).
require_once __DIR__ . '/db.php';

function lg_spalte(string $tabelle, string $spalte, string $definition): void {
    $da = scalar("SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", [$tabelle, $spalte]);
    if (!$da) q("ALTER TABLE `$tabelle` ADD COLUMN `$spalte` $definition");
}

function lg_schema(): void {
    static $fertig = false;
    if ($fertig) return;
    $fertig = true;

    // Nur nach einer Aenderung an dieser Datei bauen, nicht bei jedem Aufruf (wie im Dashboard:
    // Marker = Aenderungszeit von schema.php). Fehlt lg_meta noch, wird einfach gebaut.
    $build = (string)filemtime(__FILE__);
    try { if (lg_meta_lesen('schema_build') === $build) return; } catch (Throwable $e) {}

    // --- Lagerplatz: ein Fach im Regal. Bereich - Regal - Ebene - Fach. ------------------------
    // An einem Platz haengt hoechstens einen Blinker (6-stelliger Code vom Barcode des Blinkers).
    q("CREATE TABLE IF NOT EXISTS lg_platz (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        bereich      VARCHAR(10)  NOT NULL DEFAULT 'A',
        regal        INT          NOT NULL,
        ebene        INT          NOT NULL,
        fach         INT          NOT NULL DEFAULT 1,
        bezeichnung  VARCHAR(190) NULL,
        leiste       CHAR(6)      NULL,
        sender_id    INT          NULL,        -- NULL = Standard-Sender
        notiz        TEXT         NULL,
        angelegt     DATETIME     NOT NULL,
        aktualisiert DATETIME     NULL,
        UNIQUE KEY ort (bereich, regal, ebene, fach),
        UNIQUE KEY leiste (leiste)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Sender (Basisstation): schickt den Funkbefehl an die Blinker. -------------------------
    // weg: bruecke = Befehl wird von der Bruecke im Lager abgeholt (Normalfall auf dem Server)
    //      direkt  = der Server ruft die IP selbst auf (nur, wenn er im selben Netz steht)
    //      cloud   = ueber die Open-API des Herstellers (Sender mit 4G)
    q("CREATE TABLE IF NOT EXISTS lg_sender (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        name      VARCHAR(120) NOT NULL,
        weg       VARCHAR(10)  NOT NULL DEFAULT 'bruecke',
        ip        VARCHAR(60)  NULL,
        sn        VARCHAR(60)  NULL,
        aktiv     TINYINT(1)   NOT NULL DEFAULT 1,
        angelegt  DATETIME     NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Leuchtbefehle: jede Anforderung eine Zeile. Warteschlange fuer die Bruecke und Protokoll.
    // status: offen | abgeholt | ok | fehler | verfallen
    q("CREATE TABLE IF NOT EXISTS lg_befehl (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        sender_id   INT          NOT NULL,
        platz_id    INT          NULL,
        leiste      CHAR(6)      NOT NULL,
        code        CHAR(16)     NOT NULL,
        farbe       VARCHAR(10)  NOT NULL,
        sekunden    INT          NOT NULL DEFAULT 0,
        piep        TINYINT(1)   NOT NULL DEFAULT 0,
        status      VARCHAR(12)  NOT NULL DEFAULT 'offen',
        antwort     VARCHAR(500) NULL,
        benutzer_id INT          NULL,
        angelegt    DATETIME     NOT NULL,
        erledigt    DATETIME     NULL,
        KEY offen (status, angelegt),
        KEY (platz_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Blinker (Chaos-Modell grosses Lager): die physische Blinker, an eine CHARGE gebunden.
    // Kein fester Platz: der Blinker haengt an der Palette und wandert mit. charge_id NULL = frei,
    // liegt vorn und wartet auf die naechste Palette. code = 6-stellig vom Barcode des Blinkers.
    q("CREATE TABLE IF NOT EXISTS lg_leiste (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        code         CHAR(6)      NOT NULL,
        sender_id    INT          NULL,        -- welcher Raum/Sender; NULL = Standard
        charge_id    INT          NULL,        -- gebundene Dashboard-Charge; NULL = frei
        gebunden_am  DATETIME     NULL,
        ausloesungen INT          NOT NULL DEFAULT 0,   -- wie oft angesteuert (Akku-Schaetzung)
        verbrauch_sek INT         NOT NULL DEFAULT 0,   -- Summe der Leuchtsekunden (genauer fuer Akku)
        batterie_seit DATETIME    NULL,                 -- seit wann die aktuelle Batterie drin ist
        notiz        VARCHAR(190) NULL,
        angelegt     DATETIME     NOT NULL,
        aktualisiert DATETIME     NULL,
        UNIQUE KEY code (code),
        KEY charge (charge_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Fuer bestehende Datenbanken nachziehen.
    lg_spalte('lg_leiste', 'ausloesungen', 'INT NOT NULL DEFAULT 0');
    lg_spalte('lg_leiste', 'verbrauch_sek', 'INT NOT NULL DEFAULT 0');
    lg_spalte('lg_leiste', 'batterie_seit', 'DATETIME NULL');
    // Ein Blinker haengt entweder an einer Charge (charge_id) ODER an einer Kiste (kiste_id).
    lg_spalte('lg_leiste', 'kiste_id', 'INT NULL');

    // --- Kiste: ein Behaelter mit EINEM Blinker, in dem viele verschiedene Chargen liegen. ------
    // So muss nicht an jedes Kleinteil ein Blinker - man sucht ein Produkt, die Kiste blinkt.
    q("CREATE TABLE IF NOT EXISTS lg_kiste (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        name         VARCHAR(120) NOT NULL,
        notiz        VARCHAR(190) NULL,
        angelegt     DATETIME     NOT NULL,
        aktualisiert DATETIME     NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Inhalt einer Kiste: welche Charge liegt drin, optional mit Fach-Hinweis ("vorne links").
    // Eine Charge liegt in hoechstens einer Kiste (UNIQUE charge_id).
    q("CREATE TABLE IF NOT EXISTS lg_kiste_inhalt (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        kiste_id  INT         NOT NULL,
        charge_id INT         NOT NULL,
        fach      VARCHAR(60) NULL,
        angelegt  DATETIME    NOT NULL,
        UNIQUE KEY charge (charge_id),
        KEY kiste (kiste_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Einstellungen (Schluessel/Wert), damit nichts im Dashboard gespeichert wird. ---------
    q("CREATE TABLE IF NOT EXISTS lg_meta (
        schluessel VARCHAR(60) PRIMARY KEY,
        wert       TEXT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    lg_meta_schreiben('schema_build', $build);
}

function lg_meta_lesen(string $schluessel, string $standard = ''): string {
    $w = scalar("SELECT wert FROM lg_meta WHERE schluessel=?", [$schluessel]);
    return $w === null ? $standard : (string)$w;
}
function lg_meta_schreiben(string $schluessel, string $wert): void {
    q("INSERT INTO lg_meta (schluessel, wert) VALUES (?,?) ON DUPLICATE KEY UPDATE wert=VALUES(wert)", [$schluessel, $wert]);
}

// Schluessel der Bruecke (public/lager/bruecke.php). Beim ersten Zugriff einmalig erzeugt,
// unter "Sender und Bruecke" neu erzeugbar.
function lg_bruecke_token(bool $neu = false): string {
    $t = $neu ? '' : lg_meta_lesen('bruecke_token', '');
    if ($t === '') { $t = bin2hex(random_bytes(24)); lg_meta_schreiben('bruecke_token', $t); }
    return $t;
}
