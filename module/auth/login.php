<?php
// Login-Seite (öffentlich, ohne Sidebar). Setzt Session bei Erfolg.
require_once BX_ROOT . '/core/auth.php';

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (auth_login($_POST['email'] ?? '', $_POST['pass'] ?? '')) {
        // Produktionsmitarbeiter landen im eigenen Werk-Bereich
        header('Location: ?p=' . (ist_produktionsbereich() ? 'werk' : 'dashboard')); exit;
    }
    $fehler = 'E-Mail oder Passwort falsch.';
}
$marke = BX_MARKE; $ver = BX_VERSION;
?><!doctype html><html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h("Login – $marke $ver") ?></title>
<link rel="stylesheet" href="assets/app.css">
<script>(function(){try{var t=localStorage.getItem('bx-theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
</head><body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px">
  <div class="bx-panel" style="width:100%;max-width:380px">
    <div class="bx-brand" style="font-size:22px;margin-bottom:4px"><?= h($marke) ?> <span class="bx-ver"><?= h($ver) ?></span></div>
    <div class="muted" style="margin-bottom:18px">Anmeldung für Mitarbeiter</div>
    <?php if ($fehler): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:10px 12px;margin-bottom:14px"><?= h($fehler) ?></div><?php endif; ?>
    <form method="post" class="bx-form">
      <div class="bx-field"><label>E-Mail</label><input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required autofocus></div>
      <div class="bx-field"><label>Passwort</label><input type="password" name="pass" required></div>
      <div class="bx-row" style="margin-top:16px"><button class="btn btn-primary" type="submit" style="width:100%">Anmelden</button></div>
    </form>
  </div>
</div>
</body></html>
