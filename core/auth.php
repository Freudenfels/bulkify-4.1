<?php
// Authentifizierung & Rollen (Phase 1). Interne Mitarbeiter: E-Mail + Passwort.
// Kundenportale bleiben separat (passwortloser Magic-Link, Route 'portal').
require_once __DIR__ . '/db.php';

// Alle Rollen (Set pro Benutzer möglich). admin = darf/sieht alles.
function rollen_liste(): array {
    return [
        'admin'       => 'Admin',
        'sales'       => 'Sales / Vertrieb',
        'finance'     => 'Finance / Buchhaltung',
        'einkauf'     => 'Einkauf',
        'production'  => 'Production / Herstellung',
        'fulfillment' => 'Fulfillment / Versand',
        'labor'       => 'Labor / QA',
    ];
}

// Welche Rollen dürfen eine Route sehen. '*' = jede angemeldete Person. admin ist immer erlaubt.
function route_rollen_map(): array {
    return [
        'dashboard'          => ['*'],
        'werk'               => ['production', 'labor', 'fulfillment'],
        'aufgaben'           => ['production', 'labor', 'fulfillment'],
        'kalender'           => ['production', 'labor', 'fulfillment'],
        'bedarf'             => ['production', 'labor', 'fulfillment', 'einkauf'],
        'einkaufsliste'      => ['einkauf'],
        'chargen'            => ['production', 'labor', 'fulfillment', 'einkauf'],
        'kunden'             => ['sales', 'finance'],
        'kunde'              => ['sales', 'finance'],
        'partner'            => ['sales', 'finance'],
        'partner_detail'     => ['sales', 'finance'],
        'angebote'           => ['sales', 'finance'],
        'angebot'            => ['sales', 'finance'],
        'angebot_pdf'        => ['sales', 'finance'],
        'auftraege'          => ['sales', 'finance', 'production', 'fulfillment'],
        'auftrag'            => ['sales', 'finance', 'production', 'fulfillment'],
        'anfragen'           => ['sales', 'production'],
        'anfrage'            => ['sales', 'production'],
        'portal_anfragen'    => ['sales', 'production', 'einkauf'],
        'portal_anfrage'     => ['sales', 'production', 'einkauf'],
        'rezeptur'           => ['production', 'labor'],
        'rezeptur_detail'    => ['production', 'labor'],
        'produkte'           => ['production', 'sales'],
        'produkt'            => ['production', 'sales'],
        'produktion'         => ['production', 'labor', 'fulfillment'],
        'produktionsauftrag' => ['production', 'labor', 'fulfillment'],
        'versand'            => ['fulfillment', 'production', 'labor'],
        'lager'              => ['production', 'fulfillment', 'einkauf', 'labor'],
        'lager2'             => ['production', 'fulfillment', 'einkauf', 'labor'],
        'betriebsmittel'     => ['production', 'fulfillment', 'einkauf', 'labor'],
        'wareneingang'       => ['einkauf', 'labor', 'production'],
        'rohstoffe'          => ['production', 'einkauf', 'labor'],
        'rohstoff'           => ['production', 'einkauf', 'labor'],
        'spec_pdf'           => ['production', 'einkauf', 'labor'],
        'spec_bulkify'       => ['production', 'einkauf', 'labor'],
        'coa_bulkify'        => ['production', 'einkauf', 'labor'],
        'dokument'           => ['production', 'einkauf', 'labor', 'sales'],
        'verpackungen'       => ['production', 'einkauf'],
        'verpackung'         => ['production', 'einkauf'],
        'verpackung_dok'     => ['production', 'einkauf'],
        'naehrstoffe'        => ['production'],
        'naehrstoff'         => ['production'],
        'lieferanten'        => ['einkauf', 'finance'],
        'lieferant'          => ['einkauf', 'finance'],
        'einkauf'            => ['einkauf'],
        'bestellung'         => ['einkauf'],
        'bestellung_pdf'     => ['einkauf'],
        // Lieferantenportal: die Seiten pruefen selbst, dass ein Lieferant angemeldet ist.
        'lieferant_portal'        => ['*'],
        'lieferant_bestellung'    => ['*'],
        'lieferant_bestellung_pdf'=> ['*'],
        'lieferant_anfrage'       => ['*'],
        'lieferant_profil'        => ['*'],
        'rechnungen'         => ['finance'],
        'rechnung'           => ['finance'],
        'buchhaltung'        => ['finance'],
        'einstellungen'      => ['admin', 'finance', 'production'],
        'benutzer'           => ['admin'],
        'benutzer_detail'    => ['admin'],
    ];
}

// --- Session-Zustand ---
function current_user(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) return $cache = null;
    $u = one("SELECT * FROM benutzer WHERE id=? AND aktiv=1", [(int)$uid]);
    return $cache = ($u ?: null);
}
function is_logged_in(): bool { return current_user() !== null; }

function user_rollen(): array {
    $u = current_user();
    if (!$u) return [];
    return array_values(array_filter(array_map('trim', explode(',', (string)($u['rollen'] ?? '')))));
}
function has_role(string $r): bool {
    $roles = user_rollen();
    return in_array('admin', $roles, true) || in_array($r, $roles, true);
}

// Reiner Produktions-/Warenwirtschafts-Mitarbeiter: sieht den eigenen „Werk"-Bereich (Cockpit + Produktion + Lager + Entwicklung),
// KEINE Verkaufs-/Finanzsicht. Wahr, wenn Produktionsrolle vorhanden und KEINE Admin-/Sales-/Finance-Rolle.
// Lieferanten-Login: der Benutzer haengt an einem Lieferanten und sieht NUR dessen Portal.
// Bewusst getrennt von den Team-Rollen – ein Lieferant darf nie im internen Bereich landen.
function ist_lieferant(): bool {
    $u = current_user();
    return $u !== null && !empty($u['lieferant_id']);
}
function aktueller_lieferant_id(): int {
    $u = current_user();
    return (int)($u['lieferant_id'] ?? 0);
}
function aktueller_lieferant(): ?array {
    $id = aktueller_lieferant_id();
    return $id ? one("SELECT * FROM lieferanten WHERE id=?", [$id]) : null;
}
// Sprache des angemeldeten Lieferanten (de|en). Alles ausser 'de' laeuft auf Englisch.
function lieferant_sprache(): string {
    $l = aktueller_lieferant();
    return strtolower((string)($l['sprache'] ?? 'de')) === 'de' ? 'de' : 'en';
}
function ist_produktionsbereich(): bool {
    $r = user_rollen();
    if (array_intersect($r, ['admin', 'sales', 'finance'])) return false;
    return (bool) array_intersect($r, ['production', 'labor', 'fulfillment']);
}

// Darf der aktuelle Benutzer Verkaufsinfos sehen (VK-Preise, Aufschläge, Margen, Umsätze)?
// Produktionsmitarbeiter (Werk-Bereich) sehen diese bewusst NICHT. EK/Einkauf bleibt (Warenwirtschaft).
function darf_verkauf(): bool {
    return !ist_produktionsbereich();
}

// Darf der aktuelle Benutzer diese Route sehen?
function route_erlaubt(string $route): bool {
    $u = current_user();
    if (!$u) return false;
    if (in_array('admin', user_rollen(), true)) return true;
    $map = route_rollen_map();
    $erlaubt = $map[$route] ?? ['admin'];       // unbekannte Route: nur Admin (sicherer Default)
    if (in_array('*', $erlaubt, true)) return true;
    return (bool) array_intersect(user_rollen(), $erlaubt);
}

// --- Login / Logout ---
function auth_login(string $email, string $pass): bool {
    $u = one("SELECT * FROM benutzer WHERE email=? AND aktiv=1", [trim(mb_strtolower($email))]);
    if (!$u || !password_verify($pass, (string)$u['pass_hash'])) return false;
    $_SESSION['uid'] = (int)$u['id'];
    q("UPDATE benutzer SET letzter_login=? WHERE id=?", [gmdate('Y-m-d H:i:s'), (int)$u['id']]);
    return true;
}
function auth_logout(): void {
    unset($_SESSION['uid']);
}

// Läuft die Anfrage lokal (localhost)? Autologin-Links sind nur lokal erlaubt (Sicherheit).
function ist_lokal(): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($ip, ['127.0.0.1', '::1', '', 'localhost'], true);
}
// Autologin per Benutzer-Token (nur localhost). Für bequemes lokales Testen ohne Passwort.
function auth_login_by_token(string $token): bool {
    if (!ist_lokal()) return false;
    $token = trim($token);
    if ($token === '') return false;
    $u = one("SELECT * FROM benutzer WHERE login_token=? AND aktiv=1", [$token]);
    if (!$u) return false;
    $_SESSION['uid'] = (int)$u['id'];
    q("UPDATE benutzer SET letzter_login=? WHERE id=?", [gmdate('Y-m-d H:i:s'), (int)$u['id']]);
    return true;
}
