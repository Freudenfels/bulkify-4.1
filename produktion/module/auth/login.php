<?php
// Anmeldung zum Produktions-Programm (dieselben Mitarbeiter-Logins wie das Dashboard).
$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (pr_login((string)($_POST['email'] ?? ''), (string)($_POST['pass'] ?? ''))) weiter('?p=liste');
    $fehler = 'E-Mail oder Passwort stimmt nicht – oder kein Produktions-Zugang.';
}
kopf('Anmelden');
?>
<div class="bx-panel" style="max-width:420px;margin:8vh auto 0">
  <h1 style="margin-top:0"><?= h(BX_MARKE) ?> <span class="muted"><?= h(BX_TITEL) ?></span></h1>
  <p class="bx-sub">Mit deinem bulkify-Mitarbeiter-Login anmelden.</p>
  <?php if ($fehler): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:10px 14px"><?= h($fehler) ?></div><?php endif; ?>
  <form method="post" class="bx-form" style="margin-top:12px">
    <div class="bx-field"><label>E-Mail</label><input type="email" name="email" required autofocus autocomplete="username"></div>
    <div class="bx-field"><label>Passwort</label><input type="password" name="pass" required autocomplete="current-password"></div>
    <div class="bx-row" style="margin-top:8px"><button class="btn btn-primary" type="submit">Anmelden</button>
      <a class="btn btn-ghost" href="/">Zum Dashboard</a></div>
  </form>
</div>
<?php fuss();
