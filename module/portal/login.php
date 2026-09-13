<?php
// Kunden-Login (E-Mail + Passwort). Der Erstzugang (Konto einrichten) läuft über den
// Magic-Link im Kundenportal; danach meldet sich der Kunde hier an.
require_once BX_ROOT . '/core/schema.php';

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $k = kunde_login((string)($_POST['email'] ?? ''), (string)($_POST['passwort'] ?? ''));
    if ($k) {
        $_SESSION['portal_kid'] = (int)$k['id'];
        header('Location: ?p=portal' . (!empty($k['portal_token']) ? '&token=' . $k['portal_token'] : '')); exit;
    }
    $fehler = 'E-Mail oder Passwort ist nicht korrekt.';
}
// Schon eingeloggt? Direkt ins Portal.
if (empty($fehler) && !empty($_SESSION['portal_kid'])) { header('Location: ?p=portal'); exit; }

$cssV = (int) @filemtime(BX_ROOT . '/public/assets/app.css');
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anmelden · bulkify</title><link rel="stylesheet" href="assets/app.css?v=<?= $cssV ?>">
<script>(function(){try{var t=localStorage.getItem('bx-theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
</head><body>
<div style="max-width:400px;margin:9vh auto;padding:0 16px">
  <div style="text-align:center;margin-bottom:18px"><img src="assets/bulkify-logo-dark.png" alt="bulkify" style="height:40px"></div>
  <div class="bx-panel">
    <h1 style="margin:0 0 4px;font-size:22px">Anmelden</h1>
    <p class="bx-sub" style="margin-top:0">Melden Sie sich mit Ihrer E-Mail und Ihrem Passwort an.</p>
    <?php if (isset($_GET['abgemeldet'])): ?><div class="bx-panel badge-ok" style="padding:10px 14px;margin-bottom:12px">Sie wurden abgemeldet.</div><?php endif; ?>
    <?php if ($fehler !== ''): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:10px 14px;margin-bottom:12px"><?= h($fehler) ?></div><?php endif; ?>
    <form method="post">
      <div class="bx-field"><label>E-Mail</label><input type="email" name="email" required autocomplete="email" autofocus placeholder="name@firma.de"></div>
      <div class="bx-field"><label>Passwort</label><input type="password" name="passwort" required autocomplete="current-password"></div>
      <button class="btn btn-primary" type="submit" style="width:100%;margin-top:6px">Anmelden</button>
    </form>
    <p class="muted" style="font-size:12px;margin-top:14px">Noch keinen Zugang? Ihren persönlichen Erstzugang-Link erhalten Sie von bulkify – darüber richten Sie E-Mail und Passwort einmalig ein.</p>
  </div>
</div>
</body></html>
