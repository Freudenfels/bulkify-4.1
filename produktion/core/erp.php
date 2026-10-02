<?php
// DIE NAHT ZUM DASHBOARD. Einzige Datei im Produktions-Programm, die Dashboard-Tabellen kennt
// (produktionsauftrag, produktion_schritt, charge, auftrag, produkt, kunden, benutzer).
//
// Warum an EINER Stelle: Ändert sich im Dashboard eine Spalte, darf genau diese Datei kaputtgehen –
// überall sonst im Produktions-Programm stehen nur eigene `pr_`-Tabellen (falls welche dazukommen).
//
// WICHTIG (Abgrenzung): Die eigentliche Produktionslogik (Schritt abschließen inkl. FEFO/Chargen-
// Entnahme, Mangel-Guard, Bereitschaft) lebt bislang im Dashboard in core/schema.php. Dieses Programm
// LIEST hier nur. Sobald es selbst Schritte abschließen soll, kommt die Schreib-Logik als benannte
// Funktion HIER rein – entweder als eigene Umsetzung oder, sauberer, nachdem die Dashboard-Funktionen
// (produktion_schritt_erledigen() etc.) in eine von beiden Programmen nutzbare Bibliothek ausgelagert
// wurden. NICHT core/schema.php des Dashboards hier einbinden (zieht das ganze Dashboard herein).
require_once __DIR__ . '/db.php';

// --- Benutzer (gemeinsame Logins mit dem Dashboard) ------------------------------------------
function erp_benutzer_per_mail(string $email): ?array {
    if (!tabelle_da('benutzer')) return null;
    return one("SELECT id, name, email, pass_hash, rollen, aktiv FROM benutzer WHERE email=? AND aktiv=1",
               [trim(mb_strtolower($email))]);
}
function erp_benutzer_per_token(string $token): ?array {
    if (!tabelle_da('benutzer') || trim($token) === '') return null;
    return one("SELECT id, name, email, rollen FROM benutzer WHERE login_token=? AND aktiv=1", [trim($token)]);
}
function erp_benutzer(int $id): ?array {
    if (!tabelle_da('benutzer')) return null;
    return one("SELECT id, name, email, rollen FROM benutzer WHERE id=? AND aktiv=1", [$id]);
}
// Das Dashboard liegt auf derselben Domain unter "/".
function erp_dashboard_url(): string { return '/'; }

// --- Produktionsaufträge (nur lesen) ---------------------------------------------------------
// Liste der Produktionsaufträge mit Produkt/Kunde/Fortschritt. $status: '' = alle offenen/aktiven.
function erp_produktionsauftraege(string $status = ''): array {
    if (!tabelle_da('produktionsauftrag')) return [];
    $sql = "SELECT pa.*, a.nummer AS auftrag_nr,
                   COALESCE(NULLIF(p.kundenname,''), p.name, r.name) AS produkt_name,
                   r.darreichungsform AS form, k.firma AS kunde,
                   (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id) AS schritte_gesamt,
                   (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=1) AS schritte_fertig
            FROM produktionsauftrag pa
            LEFT JOIN auftrag a   ON a.id=pa.auftrag_id
            LEFT JOIN produkt p   ON p.id=pa.produkt_id
            LEFT JOIN rezeptur r  ON r.id=COALESCE(pa.rezeptur_id, p.rezeptur_id)
            LEFT JOIN kunden k    ON k.id=pa.kunde_id";
    $params = [];
    if ($status !== '') { $sql .= " WHERE pa.status=?"; $params[] = $status; }
    else                { $sql .= " WHERE pa.status IN ('offen','in_arbeit')"; }
    $sql .= " ORDER BY COALESCE(pa.prio,2), (pa.geplant_am IS NULL), pa.geplant_am, pa.id DESC";
    return all($sql, $params);
}
// Ein Produktionsauftrag.
function erp_pa(int $id): ?array {
    if ($id <= 0 || !tabelle_da('produktionsauftrag')) return null;
    return one("SELECT pa.*, a.nummer AS auftrag_nr,
                       COALESCE(NULLIF(p.kundenname,''), p.name, r.name) AS produkt_name,
                       r.darreichungsform AS form, k.firma AS kunde
                FROM produktionsauftrag pa
                LEFT JOIN auftrag a  ON a.id=pa.auftrag_id
                LEFT JOIN produkt p  ON p.id=pa.produkt_id
                LEFT JOIN rezeptur r ON r.id=COALESCE(pa.rezeptur_id, p.rezeptur_id)
                LEFT JOIN kunden k   ON k.id=pa.kunde_id
                WHERE pa.id=?", [$id]);
}
// Schritte eines Produktionsauftrags (Stationen in Reihenfolge).
function erp_pa_schritte(int $pa_id): array {
    if ($pa_id <= 0 || !tabelle_da('produktion_schritt')) return [];
    return all("SELECT * FROM produktion_schritt WHERE pa_id=? ORDER BY sort, id", [$pa_id]);
}

// --- Schreiben ins Dashboard: NOCH NICHT. ----------------------------------------------------
// Beispiel-Signaturen für den dedizierten Produktions-Chat (hier umsetzen, nirgends sonst):
//   function erp_schritt_abschliessen(int $schritt_id, string $akteur): array { ... }
//   function erp_pa_status(int $pa_id, string $status): bool { ... }
// Bis dahin bleibt das Programm rein lesend.
