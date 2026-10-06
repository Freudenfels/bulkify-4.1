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

    // Workflow je Service: frei definierbare Schritte (Fortschritt) + Endergebnis-Upload.
    ensure_column('dienstleistung', 'ergebnis_upload',     "TINYINT(1) NOT NULL DEFAULT 0"); // Upload eines Endergebnis-Dokuments erlaubt
    ensure_column('dienstleistung', 'upload_schliesst_ab', "TINYINT(1) NOT NULL DEFAULT 0"); // Upload setzt den Auftrag auf erledigt
    ensure_column('dienstleistung', 'ohne_fortschritt',    "TINYINT(1) NOT NULL DEFAULT 0"); // kein Workflow – nur Abrechnung (z. B. Fulfillment/Lagerung)
    // Kundenspezifische Preise je Service (optional) MIT Mengenstaffel: Standard = dienstleistung.vk_cent,
    // Ausnahmen je Kunde + ab-Menge hier (menge_ab = ab wie vielen Einheiten dieser Preis gilt).
    $pdo->exec("CREATE TABLE IF NOT EXISTS dienstleistung_kundenpreis (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dienstleistung_id INT NOT NULL,
        kunde_id INT NOT NULL,
        menge_ab INT NOT NULL DEFAULT 1,
        vk_cent INT NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_dl_kunde_menge (dienstleistung_id, kunde_id, menge_ab)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_column('dienstleistung_kundenpreis', 'menge_ab', "INT NOT NULL DEFAULT 1");
    // Alten 2-Spalten-Unique (uniq_dl_kunde) auf den 3-Spalten-Unique migrieren (best-effort, idempotent).
    try {
        if ((int) scalar("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='dienstleistung_kundenpreis' AND index_name='uniq_dl_kunde'"))
            $pdo->exec("ALTER TABLE dienstleistung_kundenpreis DROP INDEX uniq_dl_kunde");
        if (!(int) scalar("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='dienstleistung_kundenpreis' AND index_name='uniq_dl_kunde_menge'"))
            $pdo->exec("ALTER TABLE dienstleistung_kundenpreis ADD UNIQUE KEY uniq_dl_kunde_menge (dienstleistung_id, kunde_id, menge_ab)");
    } catch (Throwable $e) { /* best-effort */ }
    $pdo->exec("CREATE TABLE IF NOT EXISTS dienstleistung_schritt (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dienstleistung_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        sort INT NOT NULL DEFAULT 0,
        KEY idx_dl (dienstleistung_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // DL-Auftrag: welcher Service (ein Service pro Auftrag) + aus welcher Angebotsposition er stammt,
    // damit die DL-Rechnung genau diese Position abrechnet (nicht das ganze Angebot).
    ensure_column('auftrag', 'dienstleistung_id',   "INT NULL");
    ensure_column('auftrag', 'angebot_position_id', "INT NULL");
    // Materialisierte Schritte des DL-Auftrags (Fortschritt), aus dem Service kopiert.
    $pdo->exec("CREATE TABLE IF NOT EXISTS dl_auftrag_schritt (
        id INT AUTO_INCREMENT PRIMARY KEY,
        auftrag_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        sort INT NOT NULL DEFAULT 0,
        erledigt TINYINT(1) NOT NULL DEFAULT 0,
        erledigt_at DATETIME NULL,
        KEY idx_auf (auftrag_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Standard-Schritte je Baustein (Vorlage beim Anlegen, frei anpassbar). Unbekannt = generisch.
function dl_schritte_vorlage(?string $baustein): array {
    return match ((string)$baustein) {
        'labortest'         => ['Bestätigung', 'Probe versendet', 'Ergebnis'],
        'energetisierung'   => ['Bestätigung', 'In Bearbeitung', 'Abschluss'],
        'fulfillment'       => ['Bestätigung', 'Einlagerung', 'Aktiv'],
        'etikettcheck'      => ['Bestätigung', 'Prüfung', 'Ergebnis'],
        'rezepturbewertung' => ['Bestätigung', 'In Prüfung', 'Ergebnis'],
        default             => ['Bestätigung', 'In Bearbeitung', 'Abschluss'],
    };
}
// Katalog-Schritte eines Service lesen/setzen (frei editierbar, Reihenfolge = sort).
function dl_katalog_schritte(int $dl_id): array {
    return all("SELECT id, name, sort FROM dienstleistung_schritt WHERE dienstleistung_id=? ORDER BY sort, id", [$dl_id]);
}
function dl_katalog_schritte_setzen(int $dl_id, array $namen): void {
    q("DELETE FROM dienstleistung_schritt WHERE dienstleistung_id=?", [$dl_id]);
    $sort = 0;
    foreach ($namen as $n) {
        $n = trim((string)$n);
        if ($n === '') continue;
        q("INSERT INTO dienstleistung_schritt (dienstleistung_id,name,sort) VALUES (?,?,?)", [$dl_id, mb_substr($n, 0, 120), $sort++]);
    }
}
// Kundenspezifische Preis-Staffeln eines Service (für die Katalog-UI). Je Kunde + ab-Menge eine Zeile.
function dl_kundenpreise(int $dl_id): array {
    return all("SELECT kp.id, kp.kunde_id, kp.menge_ab, kp.vk_cent, k.firma
                FROM dienstleistung_kundenpreis kp LEFT JOIN kunden k ON k.id=kp.kunde_id
                WHERE kp.dienstleistung_id=? ORDER BY k.firma, kp.menge_ab", [$dl_id]);
}
// VK (Cent) für einen Kunden bei gegebener Menge: passende Staffel (menge_ab<=menge, höchste), sonst die
// kleinste Staffel des Kunden, sonst Standard-VK des Service. null = Service unbekannt.
function dl_kundenpreis(int $dl_id, ?int $kunde_id, int $menge = 1): ?int {
    if ($dl_id <= 0) return null;
    if ($kunde_id) {
        $m = max(1, $menge);
        $kp = scalar("SELECT vk_cent FROM dienstleistung_kundenpreis WHERE dienstleistung_id=? AND kunde_id=? AND menge_ab<=? ORDER BY menge_ab DESC LIMIT 1", [$dl_id, $kunde_id, $m]);
        if ($kp !== null && $kp !== false) return (int)$kp;
        $kp2 = scalar("SELECT vk_cent FROM dienstleistung_kundenpreis WHERE dienstleistung_id=? AND kunde_id=? ORDER BY menge_ab ASC LIMIT 1", [$dl_id, $kunde_id]);
        if ($kp2 !== null && $kp2 !== false) return (int)$kp2;
    }
    $std = scalar("SELECT vk_cent FROM dienstleistung WHERE id=?", [$dl_id]);
    return $std === null || $std === false ? null : (int)$std;
}
// Kundenpreis-Staffeln ersetzen: $zeilen = [['kunde_id'=>int,'menge_ab'=>int,'vk_cent'=>int], …].
// Doppelte (kunde+menge_ab) und ungültige übersprungen.
function dl_kundenpreise_setzen(int $dl_id, array $zeilen): void {
    q("DELETE FROM dienstleistung_kundenpreis WHERE dienstleistung_id=?", [$dl_id]);
    $seen = [];
    foreach ($zeilen as $z) {
        $kid = (int)($z['kunde_id'] ?? 0); $vk = (int)($z['vk_cent'] ?? -1); $mab = max(1, (int)($z['menge_ab'] ?? 1));
        if ($kid <= 0 || $vk < 0) continue;
        $key = $kid . ':' . $mab; if (isset($seen[$key])) continue; $seen[$key] = true;
        q("INSERT INTO dienstleistung_kundenpreis (dienstleistung_id,kunde_id,menge_ab,vk_cent) VALUES (?,?,?,?)", [$dl_id, $kid, $mab, $vk]);
    }
}
// Schritte eines DL-Auftrags einmalig aus dem Service materialisieren (idempotent). Service „ohne_fortschritt"
// (z. B. Fulfillment/Lagerung) bekommt KEINE Schritte – der Auftrag ist dann reine Abrechnung ohne Fortschritt.
// Sonst eigene Katalog-Schritte, hilfsweise die Baustein-Vorlage.
function dl_auftrag_schritte_anlegen(int $auftrag_id, int $dl_id): void {
    if ((int) scalar("SELECT COALESCE(ohne_fortschritt,0) FROM dienstleistung WHERE id=?", [$dl_id]) === 1) return;
    if ((int) scalar("SELECT COUNT(*) FROM dl_auftrag_schritt WHERE auftrag_id=?", [$auftrag_id]) > 0) return;
    $schritte = array_map(fn($s) => (string)$s['name'], dl_katalog_schritte($dl_id));
    if (!$schritte) { $d = dienstleistung_laden($dl_id); $schritte = dl_schritte_vorlage($d['baustein'] ?? ''); }
    $sort = 0;
    foreach ($schritte as $n) { $n = trim((string)$n); if ($n === '') continue; q("INSERT INTO dl_auftrag_schritt (auftrag_id,name,sort) VALUES (?,?,?)", [$auftrag_id, mb_substr($n, 0, 120), $sort++]); }
}
// Fortschritt eines DL-Auftrags (für Dashboard + Portal).
function dl_auftrag_track(int $auftrag_id): array {
    return all("SELECT id, name, sort, erledigt, erledigt_at FROM dl_auftrag_schritt WHERE auftrag_id=? ORDER BY sort, id", [$auftrag_id]);
}
// Auftrag-Status aus den Schritten ableiten: nichts erledigt = offen, alle = erledigt, sonst in_arbeit.
function dl_auftrag_status_ableiten(int $auftrag_id): void {
    $tot = (int) scalar("SELECT COUNT(*) FROM dl_auftrag_schritt WHERE auftrag_id=?", [$auftrag_id]);
    if ($tot === 0) return;
    $don = (int) scalar("SELECT COUNT(*) FROM dl_auftrag_schritt WHERE auftrag_id=? AND erledigt=1", [$auftrag_id]);
    $st = $don === 0 ? 'offen' : ($don >= $tot ? 'erledigt' : 'in_arbeit');
    q("UPDATE auftrag SET status=? WHERE id=? AND kategorie='dienstleistung'", [$st, $auftrag_id]);
}
// Aktuellen Schritt setzen: alle bis einschließlich $schritt_id = erledigt, danach offen. Status ableiten.
function dl_auftrag_schritt_setzen(int $auftrag_id, int $schritt_id): void {
    $schritte = dl_auftrag_track($auftrag_id);
    if (!$schritte) return;
    $ziel = null;
    foreach ($schritte as $i => $s) if ((int)$s['id'] === $schritt_id) $ziel = $i;
    if ($ziel === null) return;
    foreach ($schritte as $i => $s) {
        $erl = $i <= $ziel ? 1 : 0;
        q("UPDATE dl_auftrag_schritt SET erledigt=?, erledigt_at=? WHERE id=?",
          [$erl, $erl ? ($s['erledigt_at'] ?: gmdate('Y-m-d H:i:s')) : null, (int)$s['id']]);
    }
    dl_auftrag_status_ableiten($auftrag_id);
}
// Endergebnis-Dokument hochladen (Feld „ergebnis"). Legt dokument typ='dl_ergebnis' an (kundensichtbar).
// Ist am Service „Upload schließt ab" gesetzt: alle Schritte auf erledigt + Kunde benachrichtigen.
function dl_ergebnis_upload(int $auftrag_id, string $feld = 'ergebnis'): array {
    $a = one("SELECT id, kunde_id, dienstleistung_id, nummer FROM auftrag WHERE id=? AND kategorie='dienstleistung'", [$auftrag_id]);
    if (!$a) return ['ok'=>false, 'msg'=>'Auftrag nicht gefunden.'];
    if (empty($_FILES[$feld]['name']) || ($_FILES[$feld]['error'] ?? 1) !== UPLOAD_ERR_OK) return ['ok'=>false, 'msg'=>'Keine Datei empfangen.'];
    if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
    $orig = (string)$_FILES[$feld]['name'];
    $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
    $fn   = 'dl_ergebnis_' . $auftrag_id . '_' . bin2hex(random_bytes(6)) . ($ext ? '.' . $ext : '');
    if (!move_uploaded_file($_FILES[$feld]['tmp_name'], BX_UPLOADS . '/' . $fn)) return ['ok'=>false, 'msg'=>'Upload fehlgeschlagen.'];
    q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,kunde_sichtbar) VALUES ('auftrag',?, 'dl_ergebnis', ?,?,?,1)",
      [$auftrag_id, 'Endergebnis', $fn, $orig]);
    $dlid = (int)($a['dienstleistung_id'] ?? 0);
    $schliesst = $dlid ? (int) scalar("SELECT upload_schliesst_ab FROM dienstleistung WHERE id=?", [$dlid]) : 0;
    if (!empty($a['kunde_id'])) log_aktivitaet('kunde', (int)$a['kunde_id'], 'team', 'Endergebnis zu ' . (string)$a['nummer'] . ' hochgeladen.', 'auftrag', 'auftrag', $auftrag_id);
    if ($schliesst) {
        q("UPDATE dl_auftrag_schritt SET erledigt=1, erledigt_at=? WHERE auftrag_id=? AND erledigt=0", [gmdate('Y-m-d H:i:s'), $auftrag_id]);
        dl_auftrag_status_ableiten($auftrag_id);
        if (!empty($a['kunde_id']) && function_exists('mail_bereit') && mail_bereit())
            nach_antwort(fn() => mail_kunde_dl_ergebnis($auftrag_id));
    }
    return ['ok'=>true, 'msg'=>'', 'abgeschlossen'=>(bool)$schliesst];
}
// Endergebnis-Dokument(e) eines DL-Auftrags.
function dl_ergebnis_dateien(int $auftrag_id): array {
    return all("SELECT id, titel, datei, datei_orig, angelegt FROM dokument WHERE objekt_typ='auftrag' AND objekt_id=? AND typ='dl_ergebnis' ORDER BY id DESC", [$auftrag_id]);
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
    // Preis: explizit vorgegeben – sonst kundenspezifischer Preis (falls hinterlegt), sonst Standard-VK.
    if ($preis_cent === null) {
        $kid = (int) scalar("SELECT COALESCE(kunde_id,0) FROM angebot WHERE id=?", [$angebot_id]);
        $kp  = dl_kundenpreis($dienstleistung_id, $kid ?: null, (int) round($menge > 0 ? $menge : 1));
        $preis_cent = $kp !== null ? $kp : (int)$d['vk_cent'];
    }
    $sort = (int) scalar("SELECT COALESCE(MAX(sort),-1)+1 FROM angebot_position WHERE angebot_id=?", [$angebot_id]);
    q("INSERT INTO angebot_position (angebot_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,ek_cent,mwst_satz,quelle,dienstleistung_id)
       VALUES (?,?,?,?,?,?,?,?,?,?, 'dienstleistung', ?)",
      [$angebot_id, $sort, $d['nummer'] ?: null, $d['name'], $d['beschreibung'] ?: null,
       $menge > 0 ? $menge : 1, $d['einheit'] ?: null,
       (int)$preis_cent, (int)$d['ek_cent'], (float)$d['mwst_satz'], $dienstleistung_id]);
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

// DL-Auftrag (DB-) aus einem bestaetigten DL-Angebot. Ein Service pro Auftrag: je Angebotsposition ein
// eigener DB-Auftrag (mit dienstleistung_id + angebot_position_id + materialisierten Schritten). Idempotent.
// Rueckgabe: id des ERSTEN Auftrags (Aufrufer leitet dorthin; weitere stehen in der DL-Auftragsliste).
function dl_auftrag_aus_angebot(int $angebot_id): ?int {
    $a = dl_angebot_laden($angebot_id);
    if (!$a || $a['status'] !== 'bestaetigt') return null;
    // Rückwärtskompatibel: wurde das Angebot früher (alte Logik) schon in EINEN Sammel-Auftrag gewandelt,
    // nicht nochmal aufsplitten – diesen zurückgeben.
    $legacy = (int) scalar("SELECT id FROM auftrag WHERE angebot_id=? AND angebot_position_id IS NULL LIMIT 1", [$angebot_id]);
    if ($legacy) return $legacy;
    $positionen = dl_positionen($angebot_id);
    if (!$positionen) return null;
    $ersterAid = null;
    foreach ($positionen as $p) {
        $ex = (int) scalar("SELECT id FROM auftrag WHERE angebot_position_id=?", [(int)$p['id']]);
        if ($ex) { $ersterAid = $ersterAid ?? $ex; continue; }
        $dlid  = (int)($p['dienstleistung_id'] ?? 0);
        $vk    = (int)$p['preis_cent'] / 100;
        $netto = round(((float)$p['menge']) * $vk, 2);
        q("INSERT INTO auftrag (nummer,angebot_id,angebot_position_id,kunde_id,produkt_id,dienstleistung_id,menge,vk_stueck,gesamt_netto,status,kategorie)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)",
          [naechste_nummer('DB'), $angebot_id, (int)$p['id'], $a['kunde_id'] ?: null, null, $dlid ?: null,
           (float)$p['menge'], $vk, $netto, 'offen', 'dienstleistung']);
        $aid = (int) insert_id();
        if ($dlid) dl_auftrag_schritte_anlegen($aid, $dlid);
        if (!empty($a['kunde_id'])) log_aktivitaet('kunde', (int)$a['kunde_id'], 'team', 'DL-Auftrag ' . (string) scalar("SELECT nummer FROM auftrag WHERE id=?", [$aid]) . ' aus Angebot ' . (string)$a['nummer'] . ' erzeugt.', 'auftrag', 'auftrag', $aid);
        $ersterAid = $ersterAid ?? $aid;
    }
    return $ersterAid;
}

// DL-Rechnung (DR-) aus einem DL-Auftrag. Kopiert die Positionen des zugehoerigen DL-Angebots.
// Idempotent: existiert schon eine nicht stornierte DR-Rechnung, wird deren ID zurueckgegeben.
function dl_rechnung_aus_auftrag(int $auftrag_id, array $opt = []): ?int {
    $a = one("SELECT * FROM auftrag WHERE id=? AND kategorie='dienstleistung'", [$auftrag_id]);
    if (!$a) return null;
    $ex = scalar("SELECT id FROM beleg WHERE auftrag_id=? AND typ='rechnung' AND status<>'storniert' ORDER BY id LIMIT 1", [$auftrag_id]);
    if ($ex) return (int)$ex;
    $quellPos = dl_positionen((int)$a['angebot_id']);
    // Ein Service pro Auftrag: nur die EINE Position dieses Auftrags abrechnen (sonst würde jeder der
    // aufgesplitteten Aufträge das ganze Angebot berechnen). Legacy-Aufträge (ohne Position) = alles.
    if (!empty($a['angebot_position_id']))
        $quellPos = array_values(array_filter($quellPos, fn($p) => (int)$p['id'] === (int)$a['angebot_position_id']));
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
