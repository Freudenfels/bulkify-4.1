<?php
// DIE NAHT ZUM DASHBOARD. Die einzige Datei im Lager-Programm, die Tabellen des Dashboards kennt.
//
// Warum an einer Stelle: Das Dashboard wird weiterentwickelt (und irgendwann von v5 abgeloest).
// Aendert sich dort eine Spalte, darf genau diese Datei kaputtgehen - ueberall sonst im Lager
// stehen nur `lg_`-Tabellen.
//
// Stand jetzt: nur gelesen (Logins). Geschrieben wird ins Dashboard noch nirgends. Kommen spaeter
// Buchungen dazu (Entnahme fuer einen Produktionsauftrag), stehen sie als benannte Funktion hier.
require_once __DIR__ . '/db.php';

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
