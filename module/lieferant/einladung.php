<?php
// Einladung einloesen: der Lieferant setzt seinen Zugang selbst.
// Route: ?p=lieferant_einladung&token=... (oeffentlich, Token einmalig)
require_once BX_ROOT . '/module/lieferant/portal_layout.php';

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['token'] ?? $_POST['token'] ?? ''));
$inv   = $token !== '' ? one("SELECT e.*, l.firma, l.ansprechpartner, l.sprache
                              FROM lieferant_einladung e JOIN lieferanten l ON l.id=e.lieferant_id
                              WHERE e.token=? AND e.eingeloest=0", [$token]) : null;

$fehler = '';
if ($inv && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fehler = lieferant_zugang_anlegen($token, (string)($_POST['name'] ?? ''), (string)($_POST['email'] ?? ''), (string)($_POST['passwort'] ?? ''));
    if ($fehler === '') { header('Location: ?p=lieferant_login&neu=1'); exit; }
}

$en = $inv && strtolower((string)$inv['sprache']) !== 'de';
$t  = fn(string $de, string $enT) => $en ? $enT : $de;

lp_head('bulkify – ' . ($en ? 'Supplier access' : 'Lieferantenzugang'));
?>
<div class="bx-shell"><aside class="bx-side"><div class="bx-brand">bulkify <span class="bx-ver">Supplier portal</span></div></aside>
<main class="bx-main">
<?php if (!$inv): ?>
  <h1 style="margin-bottom:4px">Einladung ungültig / Invitation not valid</h1>
  <div class="bx-panel"><p class="muted" style="margin:0">Dieser Einladungslink ist abgelaufen oder wurde bereits verwendet. Bitte melden Sie sich bei uns.<br>
    <span style="opacity:.85">This invitation link has expired or was already used. Please get in touch with us.</span></p>
    <p style="margin-bottom:0"><a class="btn btn-ghost btn-sm" href="?p=lieferant_login">Zum Login / To sign-in</a></p></div>
<?php else: ?>
  <h1 style="margin-bottom:4px"><?= h($t('Zugang einrichten', 'Set up your access')) ?></h1>
  <p class="bx-sub"><?= h($t('für', 'for')) ?> <strong><?= h($inv['firma']) ?></strong></p>
  <?php if ($fehler): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px"><?= h($fehler) ?></div><?php endif; ?>
  <div class="bx-panel" style="max-width:460px">
    <p class="muted" style="margin-top:0"><?= h($t('Legen Sie hier Ihren Zugang an. Danach sehen Sie Ihre Bestellungen und Anfragen und können Termine, Fortschritt und Versanddaten selbst eintragen.',
        'Create your access here. Afterwards you can see your orders and requests, and enter dates, progress and shipping details yourself.')) ?></p>
    <form method="post">
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <div class="bx-field"><label><?= h($t('Ihr Name', 'Your name')) ?></label>
        <input type="text" name="name" required value="<?= h($inv['ansprechpartner'] ?? '') ?>" autocomplete="name"></div>
      <div class="bx-field"><label>E-Mail</label>
        <input type="email" name="email" required value="<?= h($inv['email'] ?? '') ?>" autocomplete="email"></div>
      <div class="bx-field"><label><?= h($t('Passwort (mindestens 8 Zeichen)', 'Password (at least 8 characters)')) ?></label>
        <input type="password" name="passwort" required minlength="8" autocomplete="new-password"></div>
      <button class="btn btn-primary" type="submit"><?= h($t('Zugang anlegen', 'Create access')) ?></button>
    </form>
  </div>
<?php endif; ?>
</main></div>
<?php lp_foot();
