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
    // Aufgeklebter Barcode je Kiste (zum Scannen beim Einbuchen -> Kiste wählen).
    lg_spalte('lg_kiste', 'barcode', 'VARCHAR(64) NULL');

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

    // --- Bewegungs-Historie (Warenlager-Manager): was kam rein, was ging raus. -----------------
    // Eigene Lager-Historie (lg_), unabhaengig vom Dashboard. item_name wird als Momentaufnahme
    // mitgespeichert, damit die Liste auch ohne Join lesbar bleibt (Charge kann spaeter leer/weg sein).
    q("CREATE TABLE IF NOT EXISTS lg_bewegung (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        charge_id   INT          NULL,
        item_name   VARCHAR(190) NULL,
        typ         VARCHAR(10)  NOT NULL,          -- 'ein' | 'aus'
        menge       DECIMAL(14,3) NOT NULL DEFAULT 0,
        einheit     VARCHAR(20)  NULL,
        grund       VARCHAR(190) NULL,
        benutzer_id INT          NULL,
        angelegt    DATETIME     NOT NULL,
        KEY zeit (angelegt),
        KEY charge (charge_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Zusatzinfo je Charge (Lager-eigen): Anzahl Pakete/Kartons der Lieferung. ---------------
    // Fuers Karton-Etikett (eine Charge kam in N Kartons) – Dashboard-Charge bleibt unberuehrt.
    q("CREATE TABLE IF NOT EXISTS lg_charge_info (
        charge_id INT PRIMARY KEY,
        pakete    INT      NOT NULL DEFAULT 1,
        angelegt  DATETIME NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Menge auf dem Etikett auf die Kartons aufteilen (statt Gesamtmenge je Karton)?
    lg_spalte('lg_charge_info', 'aufteilen', 'TINYINT NOT NULL DEFAULT 0');
    // Sendungs-/Tracking-Nummer(n) des gelieferten Pakets (welches Paket ist gekommen).
    // TEXT, weil eine Lieferung in vielen Kartons mit je eigener UPS-/Paketnummer kommen kann.
    lg_spalte('lg_charge_info', 'tracking', 'TEXT NULL');
    // Bestehende Installationen (alte VARCHAR(255)-Spalte) auf TEXT erweitern – laeuft nur einmal je Deploy.
    try { q("ALTER TABLE lg_charge_info MODIFY tracking TEXT NULL"); } catch (\Throwable $e) {}

    // Änderungs-Historie je Charge (wer hat wann was geändert) – fürs Lager-Protokoll.
    q("CREATE TABLE IF NOT EXISTS lg_charge_log (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        charge_id     INT          NOT NULL,
        feld          VARCHAR(40)  NOT NULL,
        alt           VARCHAR(190) NULL,
        neu           VARCHAR(190) NULL,
        benutzer_id   INT          NULL,
        benutzer_name VARCHAR(120) NULL,
        angelegt      DATETIME     NOT NULL,
        KEY c (charge_id, id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Papierkorb: im Lager "geloeschte" Chargen. Nur AUSGEBLENDET (Dashboard-Charge bleibt!),
    //     damit nichts kaputtgeht und man 30 Tage lang wiederherstellen kann. --------------------
    q("CREATE TABLE IF NOT EXISTS lg_papierkorb (
        charge_id   INT PRIMARY KEY,
        item_name   VARCHAR(190) NULL,     -- Momentaufnahme fuer die Liste
        charge_nr   VARCHAR(80)  NULL,
        menge       VARCHAR(40)  NULL,
        grund       VARCHAR(190) NULL,
        benutzer_id INT          NULL,
        geloescht_am DATETIME    NOT NULL,
        KEY zeit (geloescht_am)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Druckauftraege (Etiketten): von der kombinierten Bruecke auf dem Lager-PC gedruckt. -----
    q("CREATE TABLE IF NOT EXISTS lg_druckjob (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        ids       VARCHAR(255) NOT NULL,            -- Charge-IDs, Komma-getrennt
        format    VARCHAR(10)  NOT NULL DEFAULT 'klein',
        status    VARCHAR(12)  NOT NULL DEFAULT 'offen',  -- offen|abgeholt|ok|fehler|verfallen
        antwort   VARCHAR(255) NULL,
        benutzer_id INT        NULL,
        angelegt  DATETIME     NOT NULL,
        erledigt  DATETIME     NULL,
        KEY st (status, id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Warenausgang / Versand: eine geplante Sendung + ihre Positionen. --------------------------
    // Empfaenger wird als Snapshot gespeichert (bleibt stabil, auch wenn der Kunde seine Adresse aendert).
    // Weltweit: empf_land ist ein 2-Buchstaben-Laendercode (ISO), Default DE.
    q("CREATE TABLE IF NOT EXISTS lg_versand (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nummer VARCHAR(20) NULL,
        kunde_id INT NULL,
        empf_firma VARCHAR(190) NULL,
        empf_name VARCHAR(190) NULL,
        empf_strasse VARCHAR(190) NULL,
        empf_hausnummer VARCHAR(20) NULL,
        empf_plz VARCHAR(20) NULL,
        empf_ort VARCHAR(120) NULL,
        empf_land VARCHAR(2) NOT NULL DEFAULT 'DE',
        empf_email VARCHAR(190) NULL,
        empf_telefon VARCHAR(60) NULL,
        adress_quelle VARCHAR(20) NULL,                 -- liefer|haupt|rechnung|frei (nur Info)
        typ VARCHAR(12) NOT NULL DEFAULT 'paket',       -- paket|palette
        carrier VARCHAR(20) NOT NULL DEFAULT 'manuell', -- manuell|dhl|cargoboard
        status VARCHAR(12) NOT NULL DEFAULT 'geplant',  -- geplant|versendet|storniert
        tracking VARCHAR(255) NULL,
        gewicht_kg DECIMAL(10,3) NULL,
        pakete INT NOT NULL DEFAULT 1,
        notiz TEXT NULL,
        benutzer_id INT NULL,
        benutzer_name VARCHAR(120) NULL,
        angelegt DATETIME NOT NULL,
        versendet_am DATETIME NULL,
        KEY st (status, id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    q("CREATE TABLE IF NOT EXISTS lg_versand_pos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        versand_id INT NOT NULL,
        charge_id INT NULL,
        item_id INT NULL,
        bezeichnung VARCHAR(255) NULL,
        charge_nr VARCHAR(80) NULL,
        menge DECIMAL(14,3) NOT NULL DEFAULT 0,
        einheit VARCHAR(20) NULL,
        abgebucht TINYINT(1) NOT NULL DEFAULT 0,
        KEY v (versand_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Druckjob kann jetzt verschiedene Dokumente drucken (Etikett/Lieferschein/Versand-Label). Additiv.
    lg_spalte('lg_druckjob', 'typ', "VARCHAR(16) NOT NULL DEFAULT 'etikett'");

    // Maße je Packstück (cm) – fuer Fracht/Palette (Cargoboard). Additiv.
    lg_spalte('lg_versand', 'masse_l', 'DECIMAL(6,1) NULL');
    lg_spalte('lg_versand', 'masse_b', 'DECIMAL(6,1) NULL');
    lg_spalte('lg_versand', 'masse_h', 'DECIMAL(6,1) NULL');
    // DHL-Paketgröße: gross = Paket (V01PAK/V53WPAK), klein = Kleinpaket/Warenpost (V62KP/V66WPI).
    lg_spalte('lg_versand', 'dhl_groesse', "VARCHAR(8) NOT NULL DEFAULT 'gross'");

    // Zoll (CN23) je Position – nur für Nicht-EU-Sendungen. HS-Code, Ursprungsland (ISO2),
    // Warenwert je Stück (EUR), Gewicht je Stück (g, optional).
    lg_spalte('lg_versand_pos', 'zoll_hs', 'VARCHAR(20) NULL');
    lg_spalte('lg_versand_pos', 'zoll_ursprung', 'VARCHAR(2) NULL');
    lg_spalte('lg_versand_pos', 'zoll_wert', 'DECIMAL(10,2) NULL');
    lg_spalte('lg_versand_pos', 'zoll_gewicht_g', 'INT NULL');

    // Vom Carrier erzeugtes Versand-Label (PDF) je Sendung.
    q("CREATE TABLE IF NOT EXISTS lg_versand_label (
        versand_id INT PRIMARY KEY,
        carrier VARCHAR(20) NULL,
        format VARCHAR(8) NULL,
        pdf LONGBLOB NULL,
        angelegt DATETIME NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    lg_spalte('lg_versand_label', 'zoll_pdf', 'LONGBLOB NULL');   // CN23-Zollpapier (A4), Nicht-EU

    // Lager-2-Artikelkatalog je Fulfillment-Kunde (Stammdaten). Eigene Tabelle – der Kunde hat hier
    // Produkte, Kundenetiketten, Beilagen, Sonstiges; optional mit einem Dashboard-Item verknüpft (item_id).
    q("CREATE TABLE IF NOT EXISTS lg_artikel (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kunde_id INT NOT NULL,
        item_id INT NULL,                              -- optional: Verkaufsfertig-Item (Bestand) im Dashboard
        typ VARCHAR(16) NOT NULL DEFAULT 'produkt',    -- produkt|kundenetikett|beilage|sonstiges
        name VARCHAR(190) NOT NULL,
        verkaufsartikel TINYINT(1) NOT NULL DEFAULT 0,
        gewicht_g DECIMAL(10,2) NULL,
        masse_l_mm DECIMAL(8,1) NULL,
        masse_b_mm DECIMAL(8,1) NULL,
        masse_h_mm DECIMAL(8,1) NULL,
        ean VARCHAR(40) NULL,
        kunden_sku VARCHAR(80) NULL,
        mindestbestand DECIMAL(14,3) NULL,
        produktionszeit_tage INT NULL,                 -- Override; NULL = vom System bestimmt
        etikett_bild VARCHAR(255) NULL,                -- Dateiname in data/uploads
        notiz TEXT NULL,
        angelegt DATETIME NOT NULL,
        aktualisiert DATETIME NOT NULL,
        KEY k (kunde_id), KEY it (item_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    lg_meta_schreiben('schema_build', $build);
}

// Anzahl Pakete/Kartons einer Charge (Standard 1).
function lg_pakete(int $charge_id): int {
    $n = (int) scalar("SELECT pakete FROM lg_charge_info WHERE charge_id=?", [$charge_id]);
    return $n > 0 ? $n : 1;
}
function lg_pakete_set(int $charge_id, int $pakete): void {
    $pakete = max(1, $pakete);
    q("INSERT INTO lg_charge_info (charge_id,pakete,angelegt) VALUES (?,?,?)
       ON DUPLICATE KEY UPDATE pakete=VALUES(pakete)", [$charge_id, $pakete, jetzt_utc()]);
}

// Änderung an einer Charge protokollieren (nur wenn sich etwas geändert hat). Zeigt "wer/wann/was".
function lg_charge_log_add(int $charge_id, string $feld, ?string $alt, ?string $neu): void {
    if ((string)$alt === (string)$neu) return;
    $u = function_exists('lg_benutzer') ? lg_benutzer() : null;
    q("INSERT INTO lg_charge_log (charge_id,feld,alt,neu,benutzer_id,benutzer_name,angelegt) VALUES (?,?,?,?,?,?,?)",
      [$charge_id, mb_substr($feld, 0, 40),
       $alt === null ? null : mb_substr((string)$alt, 0, 190),
       $neu === null ? null : mb_substr((string)$neu, 0, 190),
       (int)($u['id'] ?? 0) ?: null, mb_substr((string)($u['name'] ?? ''), 0, 120), jetzt_utc()]);
}
function lg_charge_log_liste(int $charge_id, int $limit = 50): array {
    return all("SELECT * FROM lg_charge_log WHERE charge_id=? ORDER BY id DESC LIMIT " . max(1, $limit), [$charge_id]);
}

// --- Warenausgang / Versand (eigene lg_-Tabellen; Bestandsabbuchung laeuft ueber core/erp.php) ---
function lg_versand_nr(): string {
    $n = (int) scalar("SELECT COALESCE(MAX(id),0)+1 FROM lg_versand");
    return 'WA-' . str_pad((string)$n, 5, '0', STR_PAD_LEFT);
}
function lg_versand_anlegen(array $d): int {
    $u = function_exists('lg_benutzer') ? lg_benutzer() : null;
    q("INSERT INTO lg_versand
        (nummer,kunde_id,empf_firma,empf_name,empf_strasse,empf_hausnummer,empf_plz,empf_ort,empf_land,
         empf_email,empf_telefon,adress_quelle,typ,carrier,status,notiz,gewicht_kg,pakete,benutzer_id,benutzer_name,angelegt)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, 'manuell','geplant', ?,?,?,?,?,?)",
      [lg_versand_nr(), ($d['kunde_id'] ?? null) ?: null,
       $d['empf_firma'] ?? '', $d['empf_name'] ?? '', $d['empf_strasse'] ?? '', $d['empf_hausnummer'] ?? '',
       $d['empf_plz'] ?? '', $d['empf_ort'] ?? '', strtoupper(trim((string)($d['empf_land'] ?? 'DE'))) ?: 'DE',
       $d['empf_email'] ?? '', $d['empf_telefon'] ?? '', $d['adress_quelle'] ?? 'frei',
       in_array(($d['typ'] ?? 'paket'), ['paket', 'palette'], true) ? ($d['typ'] ?? 'paket') : 'paket',
       $d['notiz'] ?? '',
       (($d['gewicht_kg'] ?? '') !== '' ? (float)$d['gewicht_kg'] : null),
       max(1, (int)($d['pakete'] ?? 1)),
       (int)($u['id'] ?? 0) ?: null, mb_substr((string)($u['name'] ?? ''), 0, 120), jetzt_utc()]);
    return (int) insert_id();
}
function lg_versand(int $id): ?array { return one("SELECT * FROM lg_versand WHERE id=?", [$id]); }
function lg_versand_liste(string $status = '', int $limit = 100): array {
    $w = ''; $p = [];
    if ($status !== '') { $w = 'WHERE status=?'; $p[] = $status; }
    return all("SELECT * FROM lg_versand $w ORDER BY id DESC LIMIT " . max(1, $limit), $p);
}
function lg_versand_kopf_speichern(int $id, array $d): void {
    $mass = fn($v) => ($v ?? '') !== '' ? (float)str_replace(',', '.', (string)$v) : null;
    q("UPDATE lg_versand SET kunde_id=?, empf_firma=?, empf_name=?, empf_strasse=?, empf_hausnummer=?,
         empf_plz=?, empf_ort=?, empf_land=?, empf_email=?, empf_telefon=?, adress_quelle=?, typ=?,
         notiz=?, gewicht_kg=?, pakete=?, masse_l=?, masse_b=?, masse_h=?, dhl_groesse=? WHERE id=?",
      [($d['kunde_id'] ?? null) ?: null, $d['empf_firma'] ?? '', $d['empf_name'] ?? '', $d['empf_strasse'] ?? '',
       $d['empf_hausnummer'] ?? '', $d['empf_plz'] ?? '', $d['empf_ort'] ?? '',
       strtoupper(trim((string)($d['empf_land'] ?? 'DE'))) ?: 'DE', $d['empf_email'] ?? '', $d['empf_telefon'] ?? '',
       $d['adress_quelle'] ?? 'frei', in_array(($d['typ'] ?? 'paket'), ['paket', 'palette'], true) ? ($d['typ'] ?? 'paket') : 'paket',
       $d['notiz'] ?? '',
       (($d['gewicht_kg'] ?? '') !== '' ? (float)str_replace(',', '.', (string)$d['gewicht_kg']) : null),
       max(1, (int)($d['pakete'] ?? 1)), $mass($d['masse_l'] ?? ''), $mass($d['masse_b'] ?? ''), $mass($d['masse_h'] ?? ''),
       (($d['dhl_groesse'] ?? 'gross') === 'klein' ? 'klein' : 'gross'), $id]);
}
// Vom Carrier erzeugtes Label speichern/lesen.
function lg_versand_label_set(int $versand_id, string $carrier, string $format, string $pdf, string $zoll_pdf = ''): void {
    q("INSERT INTO lg_versand_label (versand_id,carrier,format,pdf,zoll_pdf,angelegt) VALUES (?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE carrier=VALUES(carrier), format=VALUES(format), pdf=VALUES(pdf), zoll_pdf=VALUES(zoll_pdf), angelegt=VALUES(angelegt)",
      [$versand_id, $carrier, $format, $pdf, $zoll_pdf, jetzt_utc()]);
}
function lg_versand_label(int $versand_id): ?array { return one("SELECT * FROM lg_versand_label WHERE versand_id=?", [$versand_id]); }
function lg_versand_hat_label(int $versand_id): bool { return (int) scalar("SELECT COUNT(*) FROM lg_versand_label WHERE versand_id=?", [$versand_id]) > 0; }

// --- Lager-2-Artikelkatalog (Stammdaten je Fulfillment-Kunde) ---------------------------------
function lg_artikel_typen(): array {
    return ['produkt' => 'Produkt', 'kundenetikett' => 'Kundenetikett', 'beilage' => 'Beilage', 'sonstiges' => 'Sonstiges'];
}
function lg_artikel_liste(int $kunde_id = 0, string $q = ''): array {
    $w = ['1=1']; $p = [];
    if ($kunde_id > 0) { $w[] = 'kunde_id=?'; $p[] = $kunde_id; }
    foreach (preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY) as $t) {
        $w[] = '(name LIKE ? OR ean LIKE ? OR kunden_sku LIKE ?)';
        $like = '%' . $t . '%'; array_push($p, $like, $like, $like);
    }
    return all("SELECT * FROM lg_artikel WHERE " . implode(' AND ', $w) . " ORDER BY name LIMIT 500", $p);
}
function lg_artikel(int $id): ?array { return one("SELECT * FROM lg_artikel WHERE id=?", [$id]); }
// Numerik: deutsche Dezimaltrennung -> float/int oder null (leer).
function lg_artikel_num($v, bool $int = false) {
    if ($v === null || trim((string)$v) === '') return null;
    $f = (float) str_replace(',', '.', (string)$v);
    return $int ? (int) round($f) : $f;
}
function lg_artikel_anlegen(array $d): int {
    $now = jetzt_utc();
    q("INSERT INTO lg_artikel
        (kunde_id,item_id,typ,name,verkaufsartikel,gewicht_g,masse_l_mm,masse_b_mm,masse_h_mm,
         ean,kunden_sku,mindestbestand,produktionszeit_tage,notiz,angelegt,aktualisiert)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [max(0, (int)($d['kunde_id'] ?? 0)), ($d['item_id'] ?? null) ?: null,
       array_key_exists($d['typ'] ?? '', lg_artikel_typen()) ? $d['typ'] : 'produkt',
       mb_substr(trim((string)($d['name'] ?? '')), 0, 190),
       !empty($d['verkaufsartikel']) ? 1 : 0,
       lg_artikel_num($d['gewicht_g'] ?? null), lg_artikel_num($d['masse_l_mm'] ?? null),
       lg_artikel_num($d['masse_b_mm'] ?? null), lg_artikel_num($d['masse_h_mm'] ?? null),
       mb_substr(trim((string)($d['ean'] ?? '')), 0, 40), mb_substr(trim((string)($d['kunden_sku'] ?? '')), 0, 80),
       lg_artikel_num($d['mindestbestand'] ?? null), lg_artikel_num($d['produktionszeit_tage'] ?? null, true),
       (string)($d['notiz'] ?? ''), $now, $now]);
    return (int) insert_id();
}
function lg_artikel_speichern(int $id, array $d): void {
    q("UPDATE lg_artikel SET kunde_id=?, item_id=?, typ=?, name=?, verkaufsartikel=?, gewicht_g=?,
         masse_l_mm=?, masse_b_mm=?, masse_h_mm=?, ean=?, kunden_sku=?, mindestbestand=?,
         produktionszeit_tage=?, notiz=?, aktualisiert=? WHERE id=?",
      [max(0, (int)($d['kunde_id'] ?? 0)), ($d['item_id'] ?? null) ?: null,
       array_key_exists($d['typ'] ?? '', lg_artikel_typen()) ? $d['typ'] : 'produkt',
       mb_substr(trim((string)($d['name'] ?? '')), 0, 190),
       !empty($d['verkaufsartikel']) ? 1 : 0,
       lg_artikel_num($d['gewicht_g'] ?? null), lg_artikel_num($d['masse_l_mm'] ?? null),
       lg_artikel_num($d['masse_b_mm'] ?? null), lg_artikel_num($d['masse_h_mm'] ?? null),
       mb_substr(trim((string)($d['ean'] ?? '')), 0, 40), mb_substr(trim((string)($d['kunden_sku'] ?? '')), 0, 80),
       lg_artikel_num($d['mindestbestand'] ?? null), lg_artikel_num($d['produktionszeit_tage'] ?? null, true),
       (string)($d['notiz'] ?? ''), jetzt_utc(), $id]);
}
function lg_artikel_bild_set(int $id, string $datei): void {
    q("UPDATE lg_artikel SET etikett_bild=?, aktualisiert=? WHERE id=?", [mb_substr($datei, 0, 255), jetzt_utc(), $id]);
}
function lg_artikel_del(int $id): void { q("DELETE FROM lg_artikel WHERE id=?", [$id]); }
function lg_versand_status_setzen(int $id, string $status): void {
    if (!in_array($status, ['geplant', 'versendet', 'storniert'], true)) return;
    if ($status === 'versendet') q("UPDATE lg_versand SET status=?, versendet_am=? WHERE id=?", [$status, jetzt_utc(), $id]);
    else q("UPDATE lg_versand SET status=? WHERE id=?", [$status, $id]);
}
function lg_versand_tracking_setzen(int $id, string $tracking, string $carrier = ''): void {
    if ($carrier !== '') q("UPDATE lg_versand SET tracking=?, carrier=? WHERE id=?", [$tracking, $carrier, $id]);
    else q("UPDATE lg_versand SET tracking=? WHERE id=?", [$tracking, $id]);
}
function lg_versand_pos_add(int $versand_id, array $p): int {
    q("INSERT INTO lg_versand_pos (versand_id,charge_id,item_id,bezeichnung,charge_nr,menge,einheit)
       VALUES (?,?,?,?,?,?,?)",
      [$versand_id, ($p['charge_id'] ?? null) ?: null, ($p['item_id'] ?? null) ?: null,
       mb_substr((string)($p['bezeichnung'] ?? ''), 0, 255), mb_substr((string)($p['charge_nr'] ?? ''), 0, 80),
       (float)($p['menge'] ?? 0), mb_substr((string)($p['einheit'] ?? ''), 0, 20)]);
    return (int) insert_id();
}
function lg_versand_pos_liste(int $versand_id): array {
    return all("SELECT * FROM lg_versand_pos WHERE versand_id=? ORDER BY id", [$versand_id]);
}
function lg_versand_pos_del(int $pos_id): void { q("DELETE FROM lg_versand_pos WHERE id=?", [$pos_id]); }
function lg_versand_pos_abgebucht(int $pos_id): void { q("UPDATE lg_versand_pos SET abgebucht=1 WHERE id=?", [$pos_id]); }
// Zolldaten (CN23) einer Position setzen.
function lg_versand_pos_zoll_set(int $pos_id, string $hs, string $ursprung, ?float $wert, ?int $gewicht_g): void {
    q("UPDATE lg_versand_pos SET zoll_hs=?, zoll_ursprung=?, zoll_wert=?, zoll_gewicht_g=? WHERE id=?",
      [mb_substr(preg_replace('/\s+/', '', $hs), 0, 20), strtoupper(mb_substr($ursprung, 0, 2)) ?: null,
       $wert, $gewicht_g, $pos_id]);
}

// Soll die Menge auf dem Etikett auf die Kartons aufgeteilt werden? (0/1)
function lg_aufteilen(int $charge_id): bool {
    return (int) scalar("SELECT aufteilen FROM lg_charge_info WHERE charge_id=?", [$charge_id]) === 1;
}
function lg_aufteilen_set(int $charge_id, bool $an): void {
    q("INSERT INTO lg_charge_info (charge_id,pakete,aufteilen,angelegt) VALUES (?,1,?,?)
       ON DUPLICATE KEY UPDATE aufteilen=VALUES(aufteilen)", [$charge_id, $an ? 1 : 0, jetzt_utc()]);
}

// Sendungs-/Tracking-Nummer(n) der Lieferung (welches Paket kam).
function lg_tracking(int $charge_id): string {
    return (string) scalar("SELECT tracking FROM lg_charge_info WHERE charge_id=?", [$charge_id]);
}
function lg_tracking_set(int $charge_id, string $tracking): void {
    // Mehrere Paket-/Sendungsnummern erlaubt (eine Lieferung = viele Kartons). Grosszuegig begrenzen.
    $tracking = mb_substr(trim($tracking), 0, 4000);
    if ($tracking === '') return;
    q("INSERT INTO lg_charge_info (charge_id,pakete,tracking,angelegt) VALUES (?,1,?,?)
       ON DUPLICATE KEY UPDATE tracking=VALUES(tracking)", [$charge_id, $tracking, jetzt_utc()]);
}
// Paket-/Sendungsnummern als Liste (fuer Anzeige + Vollstaendigkeits-Pruefung "welches Paket fehlt").
function lg_tracking_liste(int $charge_id): array {
    $s = lg_tracking($charge_id);
    if (trim($s) === '') return [];
    return array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $s)), fn($x) => $x !== ''));
}

// --- Papierkorb (im Lager ausgeblendete Chargen; Dashboard-Charge bleibt erhalten) -----------
function lg_papierkorb_ist_drin(int $charge_id): bool {
    return tabelle_da('lg_papierkorb') && (int) scalar("SELECT COUNT(*) FROM lg_papierkorb WHERE charge_id=?", [$charge_id]) > 0;
}
function lg_papierkorb_rein(int $charge_id, string $item_name, string $charge_nr, string $menge, string $grund, ?int $uid): void {
    q("INSERT INTO lg_papierkorb (charge_id,item_name,charge_nr,menge,grund,benutzer_id,geloescht_am) VALUES (?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE grund=VALUES(grund), geloescht_am=VALUES(geloescht_am)",
      [$charge_id, $item_name ?: null, $charge_nr ?: null, $menge ?: null, $grund ?: null, $uid ?: null, jetzt_utc()]);
}
function lg_papierkorb_raus(int $charge_id): void {
    q("DELETE FROM lg_papierkorb WHERE charge_id=?", [$charge_id]);
}
// Liste fuer die Papierkorb-Seite (neuste zuerst). tage_max: Wiederherstellung nur so lange moeglich.
function lg_papierkorb_liste(): array {
    if (!tabelle_da('lg_papierkorb')) return [];
    return all("SELECT * FROM lg_papierkorb ORDER BY geloescht_am DESC LIMIT 500");
}

// Eine Lagerbewegung protokollieren (Wareneingang/-ausgang aus dem Lager-Programm).
function lg_bewegung_log(?int $charge_id, string $typ, float $menge, ?string $einheit, string $item_name, string $grund = ''): void {
    q("INSERT INTO lg_bewegung (charge_id,item_name,typ,menge,einheit,grund,benutzer_id,angelegt) VALUES (?,?,?,?,?,?,?,?)",
      [$charge_id ?: null, $item_name ?: null, $typ, $menge, $einheit ?: null, $grund ?: null,
       (function_exists('lg_uid') ? (lg_uid() ?: null) : null), jetzt_utc()]);
}
function lg_bewegungen(int $limit = 40): array {
    return all("SELECT * FROM lg_bewegung ORDER BY id DESC LIMIT " . max(1, (int)$limit));
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
