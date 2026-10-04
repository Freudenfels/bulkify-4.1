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

// ---- aktivitaet (Kunden-Verlauf, geteilt) – verbatim ----------------------------------------------
function log_aktivitaet(string $objekt_typ, int $objekt_id, string $akteur, string $text,
                        string $typ = '', string $ref_typ = '', int $ref_id = 0): void {
    if (!tabelle_da('aktivitaet')) return;
    q("INSERT INTO aktivitaet (objekt_typ,objekt_id,akteur,typ,text,ref_typ,ref_id,erstellt) VALUES (?,?,?,?,?,?,?,?)",
      [$objekt_typ, $objekt_id, $akteur, $typ ?: null, $text, $ref_typ ?: null, $ref_id ?: null, gmdate('Y-m-d H:i:s')]);
}
