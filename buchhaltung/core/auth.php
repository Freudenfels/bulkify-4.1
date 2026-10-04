<?php
// Anmeldung mit DENSELBEN Mitarbeiter-Logins wie im Dashboard (Tabelle `benutzer`, über erp.php),
// aber eigene Sitzung (BXBUCH). Zugang nur für Finanz/Buchhaltung (Rolle finance oder admin).
require_once __DIR__ . '/erp.php';

function bu_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(BX_SESSION);
    session_start();
}
function bu_login(string $email, string $pass): bool {
    $u = erp_benutzer_per_mail($email);
    if (!$u || !password_verify($pass, (string)$u['pass_hash'])) return false;
    if (!bu_darf_rein($u)) return false;
    $_SESSION['uid'] = (int)$u['id'];
    return true;
}
function bu_logout(): void { unset($_SESSION['uid']); }
function bu_angemeldet(): bool { return !empty($_SESSION['uid']); }

// Von den Seiten genutzt: current_user() liefert den angemeldeten Benutzer (wie im Dashboard).
function current_user(): ?array {
    static $cache = null;
    if (empty($_SESSION['uid'])) return null;
    if ($cache === null) $cache = erp_benutzer((int)$_SESSION['uid']);
    return $cache;
}
function bu_uid(): int { return (int)($_SESSION['uid'] ?? 0); }

function user_rollen(): array {
    $u = current_user();
    return $u ? array_values(array_filter(array_map('trim', explode(',', (string)($u['rollen'] ?? ''))))) : [];
}
function has_role(string $rolle): bool { return in_array($rolle, user_rollen(), true); }
function rollen_liste(): array {
    return ['admin'=>'Admin','finance'=>'Finance / Buchhaltung','sales'=>'Vertrieb','einkauf'=>'Einkauf',
            'production'=>'Produktion','fulfillment'=>'Fulfillment','labor'=>'Labor'];
}

// Wer darf in die Buchhaltung? Nur finance oder admin.
function bu_darf_rein(array $u): bool {
    $r = array_values(array_filter(array_map('trim', explode(',', (string)($u['rollen'] ?? '')))));
    return in_array('finance', $r, true) || in_array('admin', $r, true);
}
