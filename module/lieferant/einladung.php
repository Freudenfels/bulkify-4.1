<?php
// Einladung einloesen: der Lieferant setzt seinen Zugang selbst.
// Route: ?p=lieferant_einladung&token=... (oeffentlich, Token einmalig)
require_once BX_ROOT . '/module/lieferant/portal_layout.php';

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['token'] ?? $_POST['token'] ?? ''));
$inv   = $token !== '' ? one("SELECT e.*, l.firma, l.ansprechpartner, l.sprache
                              FROM lieferant_einladung e JOIN lieferanten l ON l.id=e.lieferant_id
                              WHERE e.token=? AND e.eingeloest=0", [$token]) : null;

// Sprache: die Wahl des Besuchers zaehlt, sonst die am Lieferanten hinterlegte.
if ($inv && empty($_SESSION['lp_lang']) && ($_GET['lang'] ?? '') === '')
    $_SESSION['lp_lang'] = strtolower((string)$inv['sprache']) === 'de' ? 'de' : 'en';

$fehler = '';
if ($inv && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fehler = lieferant_zugang_anlegen($token, (string)($_POST['name'] ?? ''), (string)($_POST['email'] ?? ''), (string)($_POST['passwort'] ?? ''));
    if ($fehler === '') { header('Location: ?p=lieferant_login&neu=1'); exit; }
}

lp_head('bulkify – ' . lp_t('portal'));
?>
<div class="bx-shell"><aside class="bx-side">
  <div class="bx-brand"><img src="assets/bulkify-logo-white.png" alt="bulkify" class="bx-logo"><span class="bx-ver"><?= h(lp_t('portal')) ?></span></div>
</aside>
<main class="bx-main">
<?php if (!$inv): ?>
  <div class="bx-row" style="justify-content:space-between;align-items:center;max-width:520px">
    <h1 style="margin:0 0 4px"><?= h(lp_t('einl_ungueltig')) ?></h1>
    <?= lp_sprachwahl() ?>
  </div>
  <div class="bx-panel" style="max-width:520px">
    <p class="muted" style="margin-top:0"><?= h(lp_t('einl_abgelaufen')) ?></p>
    <a class="btn btn-ghost btn-sm" href="?p=lieferant_login"><?= h(lp_t('zum_login')) ?></a>
  </div>
<?php else: ?>
  <div class="bx-row" style="justify-content:space-between;align-items:center;max-width:460px">
    <h1 style="margin:0 0 4px"><?= h(lp_t('einl_titel')) ?></h1>
    <?= lp_sprachwahl() ?>
  </div>
  <p class="bx-sub"><?= h(lp_t('einl_fuer')) ?> <strong><?= h($inv['firma']) ?></strong></p>
  <?php if ($fehler): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px;max-width:460px"><?= h($fehler) ?></div><?php endif; ?>
  <div class="bx-panel" style="max-width:460px">
    <p class="muted" style="margin-top:0"><?= h(lp_t('einl_text')) ?></p>
    <form method="post">
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <div class="bx-field"><label><?= h(lp_t('ihr_name')) ?></label>
        <input type="text" name="name" required value="<?= h($inv['ansprechpartner'] ?? '') ?>" autocomplete="name"></div>
      <div class="bx-field"><label><?= h(lp_t('email')) ?></label>
        <input type="email" name="email" required value="<?= h($inv['email'] ?? '') ?>" autocomplete="email"></div>
      <div class="bx-field"><label><?= h(lp_t('pw_regel')) ?></label>
        <input type="password" name="passwort" required minlength="8" autocomplete="new-password"></div>
      <button class="btn btn-primary" type="submit"><?= h(lp_t('zugang_anlegen')) ?></button>
    </form>
  </div>
<?php endif; ?>
</main></div>
<?php lp_foot();
