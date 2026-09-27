<?php
// Anmeldung mit DENSELBEN Mitarbeiter-Logins wie im Dashboard (Tabelle `benutzer`, ueber erp.php),
// aber mit eigener Sitzung (BXLAGER). Beim Anmelden wird nichts ins Dashboard geschrieben.
require_once __DIR__ . '/erp.php';

function lg_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(BX_SESSION);
    session_start();
}

function lg_login(string $email, string $pass): bool {
    $u = erp_benutzer_per_mail($email);
    if (!$u || !password_verify($pass, (string)$u['pass_hash'])) return false;
    if (!lg_darf_rein($u)) return false;
    $_SESSION['uid'] = (int)$u['id'];
    return true;
}
function lg_logout(): void { unset($_SESSION['uid']); }

function lg_angemeldet(): bool { return !empty($_SESSION['uid']); }

function lg_benutzer(): ?array {
    static $cache = null;
    if (empty($_SESSION['uid'])) return null;
    if ($cache === null) $cache = erp_benutzer((int)$_SESSION['uid']);
    return $cache;
}
function lg_uid(): int { return (int)($_SESSION['uid'] ?? 0); }

function lg_rollen_von(array $u): array {
    return array_values(array_filter(array_map('trim', explode(',', (string)($u['rollen'] ?? '')))));
}
function lg_rollen(): array { $u = lg_benutzer(); return $u ? lg_rollen_von($u) : []; }
function lg_ist_admin(): bool { return in_array('admin', lg_rollen(), true); }

// Wer darf ins Lager? Jeder Mitarbeiter - nur Lieferanten-Zugaenge nicht.
function lg_darf_rein(array $u): bool {
    $r = lg_rollen_von($u);
    return $r !== [] && !in_array('lieferant', $r, true);
}
