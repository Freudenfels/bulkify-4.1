<?php
// Anmeldung. Es sind DIESELBEN Mitarbeiter-Logins wie im Dashboard: geprueft wird gegen die
// Tabelle `benutzer` (ueber core/erp.php). Die Sitzung ist trotzdem eine eigene - das CRM laeuft
// auf derselben Domain, und ohne eigenen Sitzungsnamen wuerden sich beide Anmeldungen ueberschreiben.
//
// Geschrieben wird beim Anmelden NICHTS ins Dashboard (auch kein letzter_login) - das wuerde die
// Anzeige dort verfaelschen.
require_once __DIR__ . '/erp.php';

function crm_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(BX_SESSION);
    session_start();
}

function crm_login(string $email, string $pass): bool {
    $u = erp_benutzer_per_mail($email);
    if (!$u || !password_verify($pass, (string)$u['pass_hash'])) return false;
    // Lieferanten-Zugaenge haben im CRM nichts verloren.
    if (in_array('lieferant', crm_rollen_von($u), true)) return false;
    $_SESSION['uid'] = (int)$u['id'];
    return true;
}
function crm_logout(): void { unset($_SESSION['uid']); }

function crm_angemeldet(): bool { return !empty($_SESSION['uid']); }

function crm_benutzer(): ?array {
    static $cache = null;
    if (empty($_SESSION['uid'])) return null;
    if ($cache === null) $cache = erp_benutzer((int)$_SESSION['uid']);
    return $cache;
}
function crm_uid(): int { return (int)($_SESSION['uid'] ?? 0); }

function crm_rollen_von(array $u): array {
    return array_values(array_filter(array_map('trim', explode(',', (string)($u['rollen'] ?? '')))));
}
function crm_rollen(): array { $u = crm_benutzer(); return $u ? crm_rollen_von($u) : []; }
function crm_ist_admin(): bool { return in_array('admin', crm_rollen(), true); }
