<?php
// Lieferanten-Login: eigene Seite, damit ein Lieferant nie auf dem Team-Login landet.
// Route: ?p=lieferant_login (oeffentlich)
require_once BX_ROOT . '/module/lieferant/portal_layout.php';

if (is_logged_in()) { header('Location: ?p=' . (ist_lieferant() ? 'lieferant_portal' : 'dashboard')); exit; }

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mail = trim(mb_strtolower((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['passwort'] ?? '');
    $u = $mail !== '' ? one("SELECT * FROM benutzer WHERE email=? AND aktiv=1", [$mail]) : null;
    if ($u && !empty($u['lieferant_id']) && password_verify($pass, (string)$u['pass_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        q("UPDATE benutzer SET letzter_login=? WHERE id=?", [gmdate('Y-m-d H:i:s'), (int)$u['id']]);
        header('Location: ?p=lieferant_portal'); exit;
    }
    // Absichtlich unspezifisch: nicht verraten, ob es die Adresse gibt.
    $fehler = 'Login fehlgeschlagen. / Sign-in failed.';
}

lp_head('bulkify – Supplier portal');
?>
<div class="bx-shell"><aside class="bx-side"><div class="bx-brand">bulkify <span class="bx-ver">Supplier portal</span></div></aside>
<main class="bx-main">
  <h1 style="margin-bottom:4px">Anmelden <span class="muted" style="font-weight:400">/ Sign in</span></h1>
  <p class="bx-sub">Zugang für Lieferanten. <span class="muted">Supplier access.</span></p>
  <?php if ($fehler): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px"><?= h($fehler) ?></div><?php endif; ?>
  <div class="bx-panel" style="max-width:420px">
    <form method="post">
      <div class="bx-field"><label>E-Mail</label><input type="email" name="email" required autocomplete="email"></div>
      <div class="bx-field"><label>Passwort <span class="muted">/ Password</span></label><input type="password" name="passwort" required autocomplete="current-password"></div>
      <button class="btn btn-primary" type="submit">Anmelden / Sign in</button>
    </form>
    <p class="muted" style="font-size:13px;margin-bottom:0">Noch keinen Zugang? Bitte den Einladungslink nutzen, den wir Ihnen geschickt haben.<br>
      <span style="opacity:.85">No access yet? Please use the invitation link we sent you.</span></p>
  </div>
</main></div>
<?php lp_foot();
