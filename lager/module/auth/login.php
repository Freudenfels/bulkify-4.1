<?php
// Anmeldung mit denselben Zugangsdaten wie im Dashboard (Tabelle `benutzer`).
$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['pass'] ?? '');
    if ($email === '' || $pass === '') {
        $fehler = 'Bitte E-Mail und Passwort eingeben.';
    } elseif (lg_login($email, $pass)) {
        weiter('?p=uebersicht');
    } else {
        $fehler = 'E-Mail oder Passwort stimmt nicht.';
    }
}

kopf('Anmeldung');
?>
<div class="bx-panel" style="max-width:380px;margin:32px auto 0">
  <?php /* Fable-5 T4.12 (D29): Logo und Programmname auf der Karte; hell = dunkles Logo, dunkel = weißes Logo */ ?>
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
    <img src="/assets/bulkify-logo-dark.png" alt="bulkify" class="lg-logo-hell" style="height:26px;width:auto">
    <img src="/assets/bulkify-logo-white.png" alt="bulkify" class="lg-logo-dunkel" style="height:26px;width:auto">
    <span style="font-size:20px;font-weight:600">Lager</span>
  </div>
  <style>.lg-logo-dunkel{display:none}:root[data-theme="dark"] .lg-logo-hell{display:none}:root[data-theme="dark"] .lg-logo-dunkel{display:inline}</style>
  <h1 style="margin-bottom:4px">Anmelden</h1>
  <p class="muted" style="margin-bottom:18px">Mit deinem Zugang aus dem bulkify Dashboard.</p>

  <?php if ($fehler !== '') hinweis($fehler, 'warn'); ?>

  <form method="post">
    <div class="bx-field">
      <label for="email">E-Mail</label>
      <input type="email" id="email" name="email" autocomplete="username"
             value="<?= h((string)($_POST['email'] ?? '')) ?>" required autofocus>
    </div>
    <div class="bx-field">
      <label for="pass">Passwort</label>
      <input type="password" id="pass" name="pass" autocomplete="current-password" required>
    </div>
    <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center">Anmelden</button>
  </form>
</div>
<?php
fuss();
