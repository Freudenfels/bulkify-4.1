<?php
// Anmeldung mit DENSELBEN Mitarbeiter-Logins wie im Dashboard (Tabelle `benutzer`, über erp.php),
// aber eigene Sitzung (BXPROD). Beim Anmelden wird nichts ins Dashboard geschrieben.
require_once __DIR__ . '/erp.php';

function pr_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(BX_SESSION);
    session_start();
}
function pr_login(string $email, string $pass): bool {
    $u = erp_benutzer_per_mail($email);
    if (!$u || !password_verify($pass, (string)$u['pass_hash'])) return false;
    if (!pr_darf_rein($u)) return false;
    $_SESSION['uid'] = (int)$u['id'];
    return true;
}
function pr_logout(): void { unset($_SESSION['uid']); }
function pr_angemeldet(): bool { return !empty($_SESSION['uid']); }
function pr_benutzer(): ?array {
    static $cache = null;
    if (empty($_SESSION['uid'])) return null;
    if ($cache === null) $cache = erp_benutzer((int)$_SESSION['uid']);
    return $cache;
}
function pr_uid(): int { return (int)($_SESSION['uid'] ?? 0); }
function pr_rollen_von(array $u): array {
    return array_values(array_filter(array_map('trim', explode(',', (string)($u['rollen'] ?? '')))));
}
function pr_rollen(): array { $u = pr_benutzer(); return $u ? pr_rollen_von($u) : []; }
function pr_ist_admin(): bool { return in_array('admin', pr_rollen(), true); }

// Wer darf in die Produktion? Mitarbeiter mit Produktions-/Admin-Rolle (keine Lieferanten/Kunden).
// Großzügig gehalten wie das Lager – zur Not später verschärfen.
function pr_darf_rein(array $u): bool {
    $r = pr_rollen_von($u);
    if ($r === [] || in_array('lieferant', $r, true)) return false;
    return true;
}
