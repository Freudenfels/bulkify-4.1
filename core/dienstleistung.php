<?php
// Dienstleistungen – Modul-Logik + Schema (eigene Datei, damit die geteilten Dateien ruhig bleiben).
// Phase 1: Service-Katalog (Stammdaten) zum Anlegen/Pflegen. Die Einbindung ins Angebot
// (Dienstleistung als Angebotsposition) kommt in Phase 1b, sobald die Felder bestätigt sind.
//
// Grundidee: Eine Dienstleistung ist alles Verkaufbare, das nicht "Produkt/Rezeptur herstellen+ausliefern"
// ist – eigenständig ODER als Zusatz zum Produkt. Katalog ist die EINZIGE Preisquelle (wie beim Produkt).
require_once __DIR__ . '/db.php';

// --- Auswahl-Listen (eine Quelle fuer Dropdowns + Anzeige) ---

// Kategorien – orientiert an den Dienstleistungsanfrage-Typen aus dem Portal, plus Rezepturbewertung.
function dienstleistung_kategorien(): array {
    return [
        'labortest'         => 'Labortest / Analyse',
        'abfuellung'        => 'Abfüllung',
        'konfektionierung'  => 'Konfektionierung',
        'sourcing'          => 'Sourcing / Beschaffung',
        'lagerung'          => 'Lagerung / Fulfillment',
        'beratung'          => 'Beratung',
        'rezepturbewertung' => 'Rezepturbewertung',
        'sonstiges'         => 'Sonstiges',
    ];
}

// Preismodelle – Phase 1 nutzt vor allem Pauschale / pro Einheit / auf Anfrage.
// pro_stunde + monatlich sind bereits vorgesehen (monatlich kommt mit der wiederkehrenden Abrechnung, Phase 3).
function dienstleistung_preismodelle(): array {
    return [
        'pauschale'   => 'Pauschale',
        'pro_einheit' => 'pro Einheit',
        'pro_stunde'  => 'pro Stunde',
        'monatlich'   => 'monatlich (wiederkehrend)',
        'auf_anfrage' => 'auf Anfrage',
    ];
}

// Eigenständig verkaufbar vs. nur Zusatz zum Produkt.
function dienstleistung_arten(): array {
    return [
        'addon'      => 'Nur als Zusatz (Add-on)',
        'standalone' => 'Nur eigenständig',
        'beides'     => 'Beides',
    ];
}

// Einmalig vs. wiederkehrend (Abrechnung). Monatlich ist angelegt, der Abrechnungslauf kommt in Phase 3.
function dienstleistung_wiederkehr(): array {
    return [
        'einmalig'  => 'Einmalig',
        'monatlich' => 'Monatlich wiederkehrend',
    ];
}

// Verweis auf einen vorhandenen Service-Baustein, der schon im System existiert – damit wir
// die Logik dort NICHT duplizieren, sondern der Katalog-Eintrag nur darauf zeigt.
function dienstleistung_bausteine(): array {
    return [
        ''                  => '– eigenständig (kein Baustein) –',
        'rezepturbewertung' => 'Rezepturbewertung (Auto-Rechnung)',
        'labortest'         => 'Labortest / Laboranalyse',
        'energetisierung'   => 'Energetisierung',
        'fulfillment'       => 'Fulfillment / Fremdlager',
        'etikettcheck'      => 'Etikett-Check',
    ];
}

// Lesbares Label mit sicherem Fallback (unbekannter Wert wird nur aufgehuebscht, nie verschluckt).
function dienstleistung_label(array $liste, ?string $key): string {
    $key = (string)$key;
    if (isset($liste[$key]) && $key !== '') return $liste[$key];
    if ($key === '') return $liste[''] ?? '–';
    return ucfirst(str_replace('_', ' ', $key));
}

// --- Schema (idempotent, nur additiv) ---
// Wird aus init_schema() per EINER Zeile aufgerufen (guarded via function_exists).
function dienstleistung_schema(): void {
    $pdo = db();

    // Service-Katalog: Stammdaten einer verkaufbaren Dienstleistung. Preise in Cent (netto), wie ueberall.
    $pdo->exec("CREATE TABLE IF NOT EXISTS dienstleistung (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nummer VARCHAR(20) NULL,
        name VARCHAR(190) NOT NULL,
        kategorie VARCHAR(30) NOT NULL DEFAULT 'sonstiges',
        beschreibung TEXT NULL,
        preismodell VARCHAR(20) NOT NULL DEFAULT 'pauschale',   -- pauschale|pro_einheit|pro_stunde|monatlich|auf_anfrage
        einheit VARCHAR(30) NULL,                               -- nur bei pro_einheit/pro_stunde/monatlich: Stück, Probe, Stunde, Monat …
        ek_cent INT NOT NULL DEFAULT 0,                         -- interner EK je Einheit/Pauschale (nur Marge)
        vk_cent INT NOT NULL DEFAULT 0,                         -- VK netto je Einheit/Pauschale
        mwst_satz DECIMAL(5,2) NOT NULL DEFAULT 19,
        art VARCHAR(12) NOT NULL DEFAULT 'beides',              -- addon|standalone|beides
        wiederkehrend VARCHAR(12) NOT NULL DEFAULT 'einmalig',  -- einmalig|monatlich
        baustein VARCHAR(20) NULL,                              -- Verweis auf vorhandenen Service-Baustein (keine Duplizierung)
        aktiv TINYINT(1) NOT NULL DEFAULT 1,
        sort INT NOT NULL DEFAULT 0,
        notiz TEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        aktualisiert DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_aktiv (aktiv), KEY idx_kat (kategorie)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Vorbereitung fuer Phase 1b: Dienstleistung als Angebotsposition (quelle='dienstleistung').
    // Nur additive Spalte – der Angebots-Editor nutzt sie erst, wenn der Picker gebaut ist.
    ensure_column('angebot_position', 'dienstleistung_id', "INT NULL");
}

// --- Katalog lesen ---
function dienstleistungen_alle(bool $nur_aktiv = false): array {
    $w = $nur_aktiv ? "WHERE aktiv=1" : "";
    return all("SELECT * FROM dienstleistung $w ORDER BY aktiv DESC, sort ASC, name ASC");
}
function dienstleistung_laden(int $id): ?array {
    return one("SELECT * FROM dienstleistung WHERE id=?", [$id]);
}

// Euro-Eingabe ("12,50" / "12.5") -> Cent. Leer -> 0.
function dienstleistung_cent(string $eingabe): int {
    $eingabe = trim($eingabe);
    if ($eingabe === '') return 0;
    return (int) round(((float) str_replace(',', '.', $eingabe)) * 100);
}
// Cent -> Euro-Anzeige (ohne Währungszeichen), deutsche Schreibweise.
function dienstleistung_eur(int $cent): string {
    return number_format($cent / 100, 2, ',', '.');
}

// Preis einer Dienstleistung als lesbarer Text (fuer Listen).
function dienstleistung_preis_text(array $d): string {
    $pm = (string)($d['preismodell'] ?? 'pauschale');
    if ($pm === 'auf_anfrage') return 'auf Anfrage';
    $vk = dienstleistung_eur((int)($d['vk_cent'] ?? 0)) . ' €';
    $einheit = trim((string)($d['einheit'] ?? ''));
    return match ($pm) {
        'pro_einheit' => $vk . ($einheit !== '' ? ' / ' . $einheit : ' / Einheit'),
        'pro_stunde'  => $vk . ' / Stunde',
        'monatlich'   => $vk . ' / Monat',
        default       => $vk,   // pauschale
    };
}

// Start-Dienstleistungen (Phase-1-Katalog). Idempotent: legt nur an, was per Name noch fehlt.
// Bewusst KEIN Auto-Seed beim Seitenaufruf (Regel seed_demo_off) – wird per Knopf ausgeloest.
function dienstleistung_startseed(): int {
    $start = [
        ['name'=>'Laboranalyse (Standard)',   'kategorie'=>'labortest',         'preismodell'=>'pauschale',   'art'=>'beides',      'wiederkehrend'=>'einmalig', 'baustein'=>'labortest',
         'beschreibung'=>'Externe Laboranalyse einer Charge (Schwermetalle, Mikrobiologie, Identität). Pauschale je Analyseauftrag.'],
        ['name'=>'Abfüllung (je Einheit)',    'kategorie'=>'abfuellung',        'preismodell'=>'pro_einheit', 'einheit'=>'Stück', 'art'=>'beides',      'wiederkehrend'=>'einmalig', 'baustein'=>'',
         'beschreibung'=>'Abfüllen vorhandener Bulkware in das Zielgebinde. Preis je abgefüllter Einheit.'],
        ['name'=>'Beratung (je Stunde)',      'kategorie'=>'beratung',          'preismodell'=>'pro_stunde',  'einheit'=>'Stunde','art'=>'standalone',  'wiederkehrend'=>'einmalig', 'baustein'=>'',
         'beschreibung'=>'Fachberatung (Regulatorik, Rezeptur, Markt). Abrechnung nach Aufwand je Stunde.'],
        ['name'=>'Rezepturbewertung',         'kategorie'=>'rezepturbewertung', 'preismodell'=>'pauschale',   'art'=>'beides',      'wiederkehrend'=>'einmalig', 'baustein'=>'rezepturbewertung',
         'beschreibung'=>'Kostenpflichtige Bewertung einer Kundenrezeptur. Nutzt den vorhandenen Baustein (Auto-Rechnung), später verrechenbar.'],
    ];
    $n = 0;
    foreach ($start as $s) {
        if (scalar("SELECT COUNT(*) FROM dienstleistung WHERE name=?", [$s['name']]) > 0) continue;
        q("INSERT INTO dienstleistung (nummer,name,kategorie,beschreibung,preismodell,einheit,ek_cent,vk_cent,mwst_satz,art,wiederkehrend,baustein,aktiv,sort)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?)",
          [naechste_nummer('DL'), $s['name'], $s['kategorie'], $s['beschreibung'] ?? null,
           $s['preismodell'], $s['einheit'] ?? null, 0, 0, 19,
           $s['art'] ?? 'beides', $s['wiederkehrend'] ?? 'einmalig', $s['baustein'] ?? null, $n]);
        $n++;
    }
    return $n;
}
