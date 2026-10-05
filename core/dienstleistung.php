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

    // Dienstleistung als Angebotsposition (quelle='dienstleistung').
    ensure_column('angebot_position', 'dienstleistung_id', "INT NULL");

    // Eigener Dienstleistungs-Strang: Marker in Angebot/Auftrag/Beleg, damit die DL-Vorgaenge
    // getrennt gefuehrt werden (eigene Listen, eigener Nummernkreis DA-/DB-/DR-), aber dieselben
    // Tabellen + PDF/E-Rechnung/DATEV/Buchhaltung nutzen. Default 'produkt' = unveraendertes Verhalten.
    ensure_column('angebot', 'kategorie', "VARCHAR(20) NOT NULL DEFAULT 'produkt'");
    ensure_column('auftrag', 'kategorie', "VARCHAR(20) NOT NULL DEFAULT 'produkt'");
    ensure_column('beleg',   'kategorie', "VARCHAR(20) NOT NULL DEFAULT 'produkt'");
}

// ===================== DL-Vorgangskette: Angebot (DA) -> Auftrag (DB) -> Rechnung (DR) =====================
// Spiegelt bewusst den Produkt-Weg (core/schema.php: auftrag_aus_angebot / rechnung_aus_auftrag),
// nur mit eigenen Nummernkreisen und dem Marker kategorie='dienstleistung'. KEINE Duplizierung der
// Buchhaltung: die DL-Rechnung ist ein normaler beleg und taucht im zentralen Kassenbuch auf.

// Untermenue (Reiter) des Dienstleistungs-Moduls.
function dl_subtabs(string $aktiv): void {
    $tabs = ['dienstleistungen'=>'Katalog', 'dl_angebote'=>'Angebote', 'dl_auftraege'=>'Aufträge', 'dl_rechnungen'=>'Rechnungen'];
    echo '<div class="settabs">';
    foreach ($tabs as $route => $label) {
        $on = $route === $aktiv ? ' class="on"' : '';
        echo '<a' . $on . ' href="?p=' . h($route) . '">' . h($label) . '</a>';
    }
    echo '</div>';
}

// --- Lesen ---
function dl_angebote_alle(): array {
    return all("SELECT a.*, k.firma AS kunde_firma,
                   (SELECT COUNT(*) FROM angebot_position p WHERE p.angebot_id=a.id) AS pos_anzahl,
                   (SELECT id FROM auftrag au WHERE au.angebot_id=a.id LIMIT 1) AS auftrag_id
                FROM angebot a LEFT JOIN kunden k ON k.id=a.kunde_id
                WHERE a.kategorie='dienstleistung'
                ORDER BY a.aktualisiert DESC, a.id DESC");
}
function dl_angebot_laden(int $id): ?array {
    return one("SELECT a.*, k.firma AS kunde_firma FROM angebot a LEFT JOIN kunden k ON k.id=a.kunde_id WHERE a.id=? AND a.kategorie='dienstleistung'", [$id]);
}
function dl_positionen(int $angebot_id): array {
    return all("SELECT * FROM angebot_position WHERE angebot_id=? ORDER BY sort, id", [$angebot_id]);
}
// Netto/USt/Brutto eines DL-Angebots aus seinen Positionen.
function dl_angebot_summe(int $angebot_id): array {
    $pos = array_map(fn($p) => ['menge'=>$p['menge'], 'preis_cent'=>$p['preis_cent'], 'mwst_satz'=>$p['mwst_satz']], dl_positionen($angebot_id));
    return beleg_summen_aus_positionen($pos);
}

// --- Schreiben ---
function dl_angebot_neu(?int $kunde_id): int {
    q("INSERT INTO angebot (nummer,kunde_id,kategorie,status) VALUES (?,?,?,?)",
      [naechste_nummer('DA'), $kunde_id ?: null, 'dienstleistung', 'offen']);
    return (int) insert_id();
}
// Dienstleistung als Position an ein DL-Angebot haengen. Preis/Einheit/MwSt kommen aus dem Katalog,
// koennen aber je Angebot ueberschrieben werden.
function dl_position_add(int $angebot_id, int $dienstleistung_id, float $menge = 1, ?int $preis_cent = null): bool {
    $d = dienstleistung_laden($dienstleistung_id);
    if (!$d) return false;
    $sort = (int) scalar("SELECT COALESCE(MAX(sort),-1)+1 FROM angebot_position WHERE angebot_id=?", [$angebot_id]);
    q("INSERT INTO angebot_position (angebot_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,ek_cent,mwst_satz,quelle,dienstleistung_id)
       VALUES (?,?,?,?,?,?,?,?,?,?, 'dienstleistung', ?)",
      [$angebot_id, $sort, $d['nummer'] ?: null, $d['name'], $d['beschreibung'] ?: null,
       $menge > 0 ? $menge : 1, $d['einheit'] ?: null,
       $preis_cent !== null ? $preis_cent : (int)$d['vk_cent'], (int)$d['ek_cent'], (float)$d['mwst_satz'], $dienstleistung_id]);
    q("UPDATE angebot SET aktualisiert=CURRENT_TIMESTAMP WHERE id=?", [$angebot_id]);
    return true;
}
function dl_position_update(int $pos_id, float $menge, int $preis_cent): void {
    q("UPDATE angebot_position SET menge=?, preis_cent=? WHERE id=?", [$menge > 0 ? $menge : 1, $preis_cent, $pos_id]);
}
function dl_position_del(int $pos_id): void {
    q("DELETE FROM angebot_position WHERE id=?", [$pos_id]);
}
function dl_angebot_status(int $angebot_id, string $status): void {
    $erlaubt = ['offen','gesendet','bestaetigt','abgelehnt'];
    if (!in_array($status, $erlaubt, true)) return;
    q("UPDATE angebot SET status=? WHERE id=? AND kategorie='dienstleistung'", [$status, $angebot_id]);
}

// DL-Auftrag (DB-) aus einem bestaetigten DL-Angebot. Idempotent.
function dl_auftrag_aus_angebot(int $angebot_id): ?int {
    $a = dl_angebot_laden($angebot_id);
    if (!$a || $a['status'] !== 'bestaetigt') return null;
    $ex = scalar("SELECT id FROM auftrag WHERE angebot_id=?", [$angebot_id]);
    if ($ex) return (int)$ex;
    $sum = dl_angebot_summe($angebot_id);
    q("INSERT INTO auftrag (nummer,angebot_id,kunde_id,produkt_id,menge,vk_stueck,gesamt_netto,status,kategorie)
       VALUES (?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('DB'), $angebot_id, $a['kunde_id'] ?: null, null, 0, 0, round((float)$sum['netto'], 2), 'offen', 'dienstleistung']);
    $aid = (int) insert_id();
    if (!empty($a['kunde_id'])) log_aktivitaet('kunde', (int)$a['kunde_id'], 'team', 'DL-Auftrag aus Angebot ' . (string)$a['nummer'] . ' erzeugt.', 'auftrag', 'auftrag', $aid);
    return $aid;
}

// DL-Rechnung (DR-) aus einem DL-Auftrag. Kopiert die Positionen des zugehoerigen DL-Angebots.
// Idempotent: existiert schon eine nicht stornierte DR-Rechnung, wird deren ID zurueckgegeben.
function dl_rechnung_aus_auftrag(int $auftrag_id, array $opt = []): ?int {
    $a = one("SELECT * FROM auftrag WHERE id=? AND kategorie='dienstleistung'", [$auftrag_id]);
    if (!$a) return null;
    $ex = scalar("SELECT id FROM beleg WHERE auftrag_id=? AND typ='rechnung' AND status<>'storniert' ORDER BY id LIMIT 1", [$auftrag_id]);
    if ($ex) return (int)$ex;
    $quellPos = dl_positionen((int)$a['angebot_id']);
    $pos = [];
    foreach ($quellPos as $p) {
        $pos[] = [
            'artikelnr'   => $p['artikelnr'] ?? null,
            'bezeichnung' => (string)$p['bezeichnung'],
            'beschreibung'=> $p['beschreibung'] ?? null,
            'menge'       => (float)$p['menge'],
            'einheit'     => $p['einheit'] ?? null,
            'preis_cent'  => (int)$p['preis_cent'],
            'mwst_satz'   => (float)$p['mwst_satz'],
        ];
    }
    if (!$pos) return null;
    $s = beleg_summen_aus_positionen($pos);
    if ($s['netto'] <= 0) return null;
    $ustP = 0.0;
    foreach ($pos as $p) if ((float)$p['mwst_satz'] > 0) { $ustP = (float)$p['mwst_satz']; break; }
    $gilt  = fn($d) => (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
    $datum = $gilt($opt['datum'] ?? null) ?? gmdate('Y-m-d');
    $ziel  = (isset($opt['zahlungsziel_tage']) && $opt['zahlungsziel_tage'] !== '') ? max(0, (int)$opt['zahlungsziel_tage']) : null;
    $faellig = ($ziel !== null) ? date('Y-m-d', strtotime($datum . ' +' . $ziel . ' days')) : null;
    $text  = trim((string)($opt['text'] ?? '')) ?: null;
    $sicht = !empty($opt['freigeben']) ? 1 : 0;
    q("INSERT INTO beleg (nummer,typ,kategorie,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum,zahlungsziel_tage,faellig,text,kunde_sichtbar)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('DR'), 'rechnung', 'dienstleistung', $auftrag_id, ($a['kunde_id'] ?: null),
       $s['netto'], $ustP, $s['ust'], $s['brutto'], 'offen', $datum, $ziel, $faellig, $text, $sicht]);
    $bid = (int) insert_id();
    $sort = 0;
    foreach ($pos as $p)
        q("INSERT INTO beleg_position (beleg_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,mwst_satz) VALUES (?,?,?,?,?,?,?,?,?)",
          [$bid, $sort++, $p['artikelnr'] ?: null, $p['bezeichnung'], $p['beschreibung'] ?: null,
           $p['menge'], $p['einheit'] ?: null, $p['preis_cent'], $p['mwst_satz']]);
    if (function_exists('beleg_status_log_add')) beleg_status_log_add($bid, 'offen', 'DL-Rechnung aus Auftrag ' . (string)$a['nummer'] . ' erstellt' . ($sicht ? ', für Kunde freigegeben' : ''), trim((string)($opt['ersteller'] ?? '')) ?: 'team');
    if (!empty($a['kunde_id'])) log_aktivitaet('kunde', (int)$a['kunde_id'], 'team', 'DL-Rechnung ' . (string) scalar("SELECT nummer FROM beleg WHERE id=?", [$bid]) . ' erstellt.', 'beleg', 'auftrag', $auftrag_id);
    return $bid;
}

// DL-Auftraege (Liste) + ein Auftrag mit Rechnungsinfo.
function dl_auftraege_alle(): array {
    return all("SELECT a.*, k.firma AS kunde_firma, ang.nummer AS angebot_nummer,
                   (SELECT id FROM beleg b WHERE b.auftrag_id=a.id AND b.typ='rechnung' AND b.status<>'storniert' LIMIT 1) AS rechnung_id,
                   (SELECT nummer FROM beleg b WHERE b.auftrag_id=a.id AND b.typ='rechnung' AND b.status<>'storniert' LIMIT 1) AS rechnung_nummer
                FROM auftrag a LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN angebot ang ON ang.id=a.angebot_id
                WHERE a.kategorie='dienstleistung'
                ORDER BY a.angelegt DESC, a.id DESC");
}
function dl_auftrag_laden(int $id): ?array {
    return one("SELECT a.*, k.firma AS kunde_firma, ang.nummer AS angebot_nummer FROM auftrag a LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN angebot ang ON ang.id=a.angebot_id WHERE a.id=? AND a.kategorie='dienstleistung'", [$id]);
}
function dl_rechnungen_alle(): array {
    return all("SELECT b.*, k.firma AS kunde_firma, au.nummer AS auftrag_nummer
                FROM beleg b LEFT JOIN kunden k ON k.id=b.kunde_id LEFT JOIN auftrag au ON au.id=b.auftrag_id
                WHERE b.kategorie='dienstleistung' AND b.typ='rechnung'
                ORDER BY b.datum DESC, b.id DESC");
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
