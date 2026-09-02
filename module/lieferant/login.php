<?php
// Lieferanten-Login: eigene Seite, damit ein Lieferant nie auf dem Team-Login landet.
// Route: ?p=lieferant_login (oeffentlich). Sprache ueber den Umschalter waehlbar –
// vor dem Login gibt es keine Stammdaten, aus denen sie sich ableiten liesse.
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
    $fehler = lp_t('login_fehler');
}

lp_head('bulkify – ' . lp_t('portal'));
?>
<div class="bx-shell"><aside class="bx-side">
  <div class="bx-brand"><img src="assets/bulkify-logo-white.png" alt="bulkify" class="bx-logo"><span class="bx-ver"><?= h(lp_t('portal')) ?></span></div>
</aside>
<main class="bx-main">
  <div class="bx-row" style="justify-content:space-between;align-items:center;max-width:420px">
    <h1 style="margin:0 0 4px"><?= h(lp_t('anmelden')) ?></h1>
    <?= lp_sprachwahl() ?>
  </div>
  <p class="bx-sub"><?= h(lp_t('login_sub')) ?></p>
  <?php if ($fehler): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px;max-width:420px"><?= h($fehler) ?></div><?php endif; ?>
  <div class="bx-panel" style="max-width:420px">
    <form method="post">
      <div class="bx-field"><label><?= h(lp_t('email')) ?></label><input type="email" name="email" required autocomplete="email"></div>
      <div class="bx-field"><label><?= h(lp_t('passwort')) ?></label><input type="password" name="passwort" required autocomplete="current-password"></div>
      <button class="btn btn-primary" type="submit"><?= h(lp_t('anmelden')) ?></button>
    </form>
    <p class="muted" style="font-size:13px;margin-bottom:0"><?= h(lp_t('kein_zugang')) ?></p>
  </div>
</main></div>
<?php lp_foot();
