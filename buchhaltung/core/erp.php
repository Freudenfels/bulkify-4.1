<?php
// DIE NAHT des Buchhaltungs-Programms. Zugriffe auf GETEILTE Dashboard-Tabellen laufen hier gebündelt:
// - benutzer (Login/Sitzung)  - app_meta (Einstellungen: ust_inland, Firma, Wechselkurse …)
// - nummernkreis (fortlaufende Belegnummern, EINE Quelle für Dashboard + Buchhaltung)
// - aktivitaet (Kunden-Verlauf)
// Finanz-EIGENE Tabellen (beleg*, zahlung, lieferant_rechnung/_zahlung) stehen NICHT hier, sondern in
// schema.php/finanz.php. Wer eine geteilte Spalte umbenennt, prüft genau diese Datei.
require_once __DIR__ . '/db.php';

// ---- benutzer (Mitarbeiter-Logins, geteilt mit dem Dashboard; nur Lesen) --------------------------
function erp_benutzer_per_mail(string $email): ?array {
    return one("SELECT * FROM benutzer WHERE email=? AND aktiv=1", [trim(mb_strtolower($email))]);
}
function erp_benutzer_per_token(string $token): ?array {
    if ($token === '') return null;
    return one("SELECT * FROM benutzer WHERE login_token=? AND aktiv=1", [$token]);
}
function erp_benutzer(int $id): ?array {
    return $id ? one("SELECT * FROM benutzer WHERE id=? AND aktiv=1", [$id]) : null;
}
function erp_dashboard_url(): string { return '/'; }

// ---- app_meta (Einstellungen, geteilt) – verbatim aus dem Dashboard -------------------------------
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
    $c[$k] = (string)$v;
}

// GoBD scharfgeschaltet? Default AUS (Aufbau-/Migrationsphase: Belege/Beträge/Positionen frei korrigierbar).
// Scharf = festgeschriebene/freigegebene/bezahlte Belege sind unveränderbar (nur Storno/Gutschrift).
function gobd_scharf(): bool { return (string) meta_get('gobd_scharf', '0') === '1'; }

// ---- nummernkreis (fortlaufende Nummern, EINE geteilte Quelle) – verbatim -------------------------
function naechste_nummer(string $prefix): string {
    $prefix = strtoupper(trim($prefix));
    q("INSERT IGNORE INTO nummernkreis (prefix, naechste, stellen) VALUES (?, 2690, 4)", [$prefix]);
    q("UPDATE nummernkreis SET naechste = naechste + 1 WHERE prefix = ?", [$prefix]);
    $r = one("SELECT naechste - 1 AS nr, stellen FROM nummernkreis WHERE prefix = ?", [$prefix]);
    return $prefix . '-' . str_pad((string)$r['nr'], (int)$r['stellen'], '0', STR_PAD_LEFT);
}
function nummer_zurueckgeben(string $nummer): void {
    if (!preg_match('/^([A-Z]+)-(\d+)$/', strtoupper(trim($nummer)), $m)) return;
    q("UPDATE nummernkreis SET naechste = naechste - 1 WHERE prefix = ? AND naechste = ?", [$m[1], (int)$m[2] + 1]);
}

// ---- kunden (geteilt) ------------------------------------------------------------------------------
// Lesen: alle Kunden (für Zuordnung/Abgleich im Import). Nur id + firma + ust_id + kundennummer.
function erp_kunden_alle(): array {
    if (!tabelle_da('kunden')) return [];
    return all("SELECT id, firma, kundennummer, ust_id FROM kunden ORDER BY firma");
}
function erp_kunde(int $id): ?array {
    return ($id && tabelle_da('kunden')) ? one("SELECT * FROM kunden WHERE id=?", [$id]) : null;
}
// Fuzzy-Suche über die Firma (für den KI-Namensabgleich). Gibt die beste Übereinstimmung oder null.
function erp_kunde_per_firma(string $firma): ?array {
    $firma = trim($firma);
    if ($firma === '' || !tabelle_da('kunden')) return null;
    $exact = one("SELECT id, firma FROM kunden WHERE firma=? LIMIT 1", [$firma]);
    if ($exact) return $exact;
    return one("SELECT id, firma FROM kunden WHERE firma LIKE ? ORDER BY CHAR_LENGTH(firma) LIMIT 1", ['%' . $firma . '%']);
}
// EINZIGE Schreibstelle der Buchhaltung in die Kunden: Neuanlage (nur firma Pflicht). Gibt die neue id.
// Für den Rechnungs-Import („Kunde neu anlegen", wenn kein Treffer). Vergibt eine Kundennummer (K-…).
function erp_kunde_anlegen(string $firma, array $extra = []): int {
    $firma = trim($firma);
    if ($firma === '') return 0;
    $vorhanden = one("SELECT id FROM kunden WHERE firma=? LIMIT 1", [$firma]);
    if ($vorhanden) return (int)$vorhanden['id'];
    q("INSERT INTO kunden (kundennummer, firma, email, ort, land) VALUES (?,?,?,?,?)",
      [naechste_nummer('K'), $firma, trim((string)($extra['email'] ?? '')) ?: null,
       trim((string)($extra['ort'] ?? '')) ?: null, strtoupper((string)($extra['land'] ?? 'DE')) ?: 'DE']);
    return insert_id();
}

// ---- angebot / auftrag (geteilt, NUR LESEN; für die Buchhaltungs-Ansicht + Abgleich) --------------
// Angebote mit Kundenname + Produkt + repräsentativer Summe (bestätigte, sonst erste Staffel: menge×VK).
// $kunde_id filtert auf einen Kunden; $suche filtert (Nummer/Kunde) serverseitig im Aufrufer.
function erp_angebote(?int $kunde_id = null, string $suche = ''): array {
    if (!tabelle_da('angebot')) return [];
    $where = '1=1'; $args = [];
    if ($kunde_id) { $where .= ' AND a.kunde_id=?'; $args[] = $kunde_id; }
    $rows = all(
        "SELECT a.id, a.nummer, a.status, a.angelegt, a.gueltig_bis, a.kunde_id,
                k.firma AS kunde_firma, p.name AS produkt_name,
                (SELECT s.menge * s.vk_stueck FROM angebot_staffel s WHERE s.angebot_id=a.id
                   ORDER BY s.bestaetigt DESC, s.sort ASC, s.id ASC LIMIT 1) AS summe_netto,
                (SELECT COUNT(*) FROM angebot_staffel s WHERE s.angebot_id=a.id) AS staffel_anzahl
           FROM angebot a
           LEFT JOIN kunden k ON k.id=a.kunde_id
           LEFT JOIN produkt p ON p.id=a.produkt_id
          WHERE $where
          ORDER BY a.angelegt DESC, a.id DESC", $args);
    if ($suche !== '') {
        $n = mb_strtolower($suche);
        $rows = array_values(array_filter($rows, fn($r) =>
            mb_strpos(mb_strtolower((string)$r['nummer']), $n) !== false
            || mb_strpos(mb_strtolower((string)$r['kunde_firma']), $n) !== false
            || mb_strpos(mb_strtolower((string)$r['produkt_name']), $n) !== false));
    }
    return $rows;
}

// Einzelner Auftrag (voll) + Produktname. Für die Positionsübernahme in die Rechnung. Nur Lesen.
function erp_auftrag(int $id): ?array {
    if (!$id || !tabelle_da('auftrag')) return null;
    return one("SELECT a.*, p.name AS produkt_name FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$id]);
}

// Echte (hinterlegte) Angebotspositionen – Rohzeilen aus angebot_position (leere v3-Null-Zeilen raus).
// Für die aufgeschlüsselte Rechnungsposition. Nur Lesen. (Staffel-/Auto-Ableitung liegt im Dashboard.)
function erp_angebot_positionen(int $angebot_id): array {
    if (!$angebot_id || !tabelle_da('angebot_position')) return [];
    $rows = all("SELECT * FROM angebot_position WHERE angebot_id=? ORDER BY sort, id", [$angebot_id]);
    return array_values(array_filter($rows, fn($r) => (float)$r['menge'] > 1e-9 || (int)$r['preis_cent'] > 0));
}
// ALLE Angebotspositionen (1:1, ohne Filter) – für DL-Rechnungen (Positionen unverändert übernehmen). Nur Lesen.
function erp_dl_positionen(int $angebot_id): array {
    if (!$angebot_id || !tabelle_da('angebot_position')) return [];
    return all("SELECT * FROM angebot_position WHERE angebot_id=? ORDER BY sort, id", [$angebot_id]);
}

// Aufträge mit Kundenname (für den Abgleich Angebot→Auftrag→Rechnung). Nur Lesen.
function erp_auftraege(?int $kunde_id = null): array {
    if (!tabelle_da('auftrag')) return [];
    $where = '1=1'; $args = [];
    if ($kunde_id) { $where .= ' AND a.kunde_id=?'; $args[] = $kunde_id; }
    return all(
        "SELECT a.id, a.nummer, a.status, a.angebot_id, a.kunde_id, a.gesamt_netto, a.status_datum,
                k.firma AS kunde_firma, p.name AS produkt_name
           FROM auftrag a
           LEFT JOIN kunden k ON k.id=a.kunde_id
           LEFT JOIN produkt p ON p.id=a.produkt_id
          WHERE $where
          ORDER BY a.id DESC", $args);
}

// ---- rezeptur (geteilt, NUR LESEN; + optionaler Produkt-Write) ------------------------------------
// Für „Rezeptur verknüpfen" in der Rechnung (nachträglich Rezepturen an Belege hängen, v3-Import/Freitext).
// Liste für den Picker (nummer/name). Nummer absteigend = neueste zuerst.
function erp_rezepturen(int $limit = 1000): array {
    if (!tabelle_da('rezeptur')) return [];
    return all("SELECT id, nummer, name FROM rezeptur ORDER BY nummer DESC LIMIT " . max(1, $limit));
}
function erp_rezeptur(int $id): ?array {
    return ($id && tabelle_da('rezeptur')) ? one("SELECT id, nummer, name, kunde_id FROM rezeptur WHERE id=?", [$id]) : null;
}
// Rezeptur, die am Produkt des Auftrags hängt (zur Auflösung, wenn beleg.rezeptur_id leer ist). Nur Lesen.
function erp_auftrag_rezeptur_id(int $auftrag_id): int {
    if (!$auftrag_id || !tabelle_da('auftrag')) return 0;
    return (int) scalar("SELECT p.rezeptur_id FROM auftrag a JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$auftrag_id]);
}
// Optional: Rezeptur am Produkt des Auftrags NACHTRAGEN – nur wenn dort noch keine hinterlegt ist
// (damit Produktion/Specs sie kennen; so macht es auch das Dashboard). Gibt true, wenn gesetzt wurde.
function erp_auftrag_produkt_rezeptur_setzen(int $auftrag_id, int $rezeptur_id): bool {
    if (!$auftrag_id || !$rezeptur_id || !tabelle_da('auftrag')) return false;
    $pid = (int) scalar("SELECT produkt_id FROM auftrag WHERE id=?", [$auftrag_id]);
    if (!$pid) return false;
    if ((int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [$pid])) return false;  // schon belegt → nicht überschreiben
    q("UPDATE produkt SET rezeptur_id=? WHERE id=? AND (rezeptur_id IS NULL OR rezeptur_id=0)", [$rezeptur_id, $pid]);
    return true;
}

// ---- aktivitaet (Kunden-Verlauf, geteilt) – verbatim ----------------------------------------------
function log_aktivitaet(string $objekt_typ, int $objekt_id, string $akteur, string $text,
                        string $typ = '', string $ref_typ = '', int $ref_id = 0): void {
    if (!tabelle_da('aktivitaet')) return;
    q("INSERT INTO aktivitaet (objekt_typ,objekt_id,akteur,typ,text,ref_typ,ref_id,erstellt) VALUES (?,?,?,?,?,?,?,?)",
      [$objekt_typ, $objekt_id, $akteur, $typ ?: null, $text, $ref_typ ?: null, $ref_id ?: null, gmdate('Y-m-d H:i:s')]);
}
