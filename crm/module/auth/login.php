<?php
// Anmeldung mit denselben Zugangsdaten wie im Dashboard (Tabelle `benutzer`).
// Bewusst schlicht: eine Karte, zwei Felder, ein Knopf - das hier wird meistens am Handy geoeffnet.
$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['pass'] ?? '');
    if ($email === '' || $pass === '') {
        $fehler = 'Bitte E-Mail und Passwort eingeben.';
    } elseif (crm_login($email, $pass)) {
        header('Location: ?p=wartet'); exit;
    } else {
        // Keine Auskunft darueber, welcher Teil falsch war.
        $fehler = 'E-Mail oder Passwort stimmt nicht.';
    }
}

kopf('Anmeldung');
?>
<div class="karte" style="max-width:380px;margin:32px auto 0">
  <div class="rumpf">
    <h1 style="margin-bottom:4px">Anmelden</h1>
    <p class="leise" style="margin-bottom:18px">Mit deinem Zugang aus dem bulkify Dashboard.</p>

    <?php if ($fehler !== ''): ?><div class="hinweis warn"><?= h($fehler) ?></div><?php endif; ?>

    <form method="post">
      <div class="feld">
        <label for="email">E-Mail</label>
        <input type="email" id="email" name="email" autocomplete="username"
               value="<?= h((string)($_POST['email'] ?? '')) ?>" required autofocus>
      </div>
      <div class="feld">
        <label for="pass">Passwort</label>
        <input type="password" id="pass" name="pass" autocomplete="current-password" required>
      </div>
      <button class="btn stark" type="submit" style="width:100%;justify-content:center">Anmelden</button>
    </form>
  </div>
</div>
<?php
fuss();
