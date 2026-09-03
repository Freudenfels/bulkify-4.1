<?php
// Login-Seite (öffentlich, ohne Sidebar). Eigenständiges Marken-Design. Setzt Session bei Erfolg.
require_once BX_ROOT . '/core/auth.php';

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (auth_login($_POST['email'] ?? '', $_POST['pass'] ?? '')) {
        header('Location: ?p=' . (ist_produktionsbereich() ? 'werk' : 'dashboard')); exit;
    }
    $fehler = 'E-Mail oder Passwort falsch.';
}
$marke = BX_MARKE; $ver = BX_VERSION;
?><!doctype html><html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h("Login – $marke $ver") ?></title>
<?= pwa_head() ?>
<style>
  :root{ --lime:#C0F24E; --gruen:#1D9E75; --dunkel:#10210f; }
  *{ box-sizing:border-box; }
  body{ margin:0; font-family:'Segoe UI',system-ui,-apple-system,Roboto,Helvetica,Arial,sans-serif; color:#111; }
  .lg-wrap{ min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px;
    background:radial-gradient(1200px 600px at 20% -10%, rgba(192,242,78,.18), transparent 60%),
               linear-gradient(135deg, var(--dunkel) 0%, #14351f 45%, var(--gruen) 130%); }
  .lg-card{ width:100%; max-width:400px; background:#fff; border-radius:18px; padding:38px 34px 34px;
    box-shadow:0 24px 70px rgba(0,0,0,.38); }
  .lg-logo{ display:block; width:190px; max-width:70%; height:auto; margin:0 auto 6px; }
  .lg-sub{ text-align:center; color:#6b7280; font-size:14px; margin:0 0 24px; }
  .lg-field{ margin-bottom:15px; }
  .lg-field label{ display:block; font-size:13px; font-weight:400; color:#374151; margin-bottom:6px; }
  .lg-field input{ width:100%; padding:12px 14px; font-size:15px; color:#111; background:#f7f8fa;
    border:1px solid #e2e5ea; border-radius:11px; outline:none; transition:border-color .15s, box-shadow .15s; }
  .lg-field input:focus{ border-color:var(--gruen); box-shadow:0 0 0 3px rgba(29,158,117,.18); background:#fff; }
  .lg-btn{ width:100%; margin-top:8px; padding:13px 16px; font-size:15px; font-weight:600; color:#fff;
    background:var(--gruen); border:0; border-radius:11px; cursor:pointer; transition:background .15s, transform .05s; }
  .lg-btn:hover{ background:#188a66; }
  .lg-btn:active{ transform:translateY(1px); }
  .lg-err{ background:#fdecea; border:1px solid #f3c1bb; color:#8f231b; font-size:14px;
    border-radius:10px; padding:10px 12px; margin-bottom:16px; }
  .lg-foot{ text-align:center; color:#9aa1ab; font-size:12px; margin-top:20px; }
</style>
</head><body>
<?= pwa_script() ?>
<div class="lg-wrap">
  <div class="lg-card">
    <img src="assets/bulkify-logo-dark.png" alt="<?= h($marke) ?>" class="lg-logo">
    <p class="lg-sub">Anmeldung für Mitarbeiter</p>
    <?php if ($fehler): ?><div class="lg-err"><?= h($fehler) ?></div><?php endif; ?>
    <form method="post" autocomplete="on">
      <div class="lg-field"><label>E-Mail</label><input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required autofocus></div>
      <div class="lg-field"><label>Passwort</label><input type="password" name="pass" required></div>
      <button class="lg-btn" type="submit">Anmelden</button>
    </form>
    <div class="lg-foot">bulkify Dashboard <?= h($ver) ?></div>
  </div>
</div>
</body></html>
