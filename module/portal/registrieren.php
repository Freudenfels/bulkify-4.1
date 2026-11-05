<?php
// Kunden-Registrierung (öffentlich, Nico 05.11.2026). Route ?p=portal_registrieren. Neue Kunden legen ihr Konto selbst an
// (Firma, Ansprechpartner, Anschrift, USt-IdNr., E-Mail + Passwort). Daraus entsteht ein GESPERRTER Kunde – anmelden kann er
// sich erst nach der Freigabe durch das Team (Kundenseite, Knopf „Registrierung freigeben“). Schutz gegen Spam: verstecktes
// Feld (Honeypot), Mindest-Ausfüllzeit, höchstens 3 Registrierungen je Sitzung und Stunde.
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/mail.php';

$fehler = ''; $ok = false;
$v = fn($f, $std = '') => h((string)($_POST[$f] ?? $std));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start = (int)($_SESSION['reg_formular_ab'] ?? 0);
    $_SESSION['reg_log'] = array_values(array_filter((array)($_SESSION['reg_log'] ?? []), fn($t) => time() - (int)$t < 3600));
    if (trim((string)($_POST['webseite_url'] ?? '')) !== '' || ($start > 0 && time() - $start < 3)) {
        $ok = true;   // Bot: so tun, als sei alles gut – nichts anlegen
    } elseif (count($_SESSION['reg_log']) >= 3) {
        $fehler = 'Zu viele Registrierungen in kurzer Zeit. Bitte versuchen Sie es später noch einmal oder kontaktieren Sie uns.';
    } elseif ((string)($_POST['passwort'] ?? '') !== (string)($_POST['passwort2'] ?? '')) {
        $fehler = 'Die beiden Passwörter stimmen nicht überein.';
    } elseif (empty($_POST['agb'])) {
        $fehler = 'Bitte bestätigen Sie, dass Ihre Angaben stimmen und Sie gewerblich bestellen.';
    } else {
        $r = kunde_registrierung_anlegen($_POST);
        $ok = !empty($r['ok']); $fehler = (string)($r['fehler'] ?? '');
        if ($ok) {
            $_SESSION['reg_log'][] = time();
            $kidR = (int)$r['id']; $dubR = $r['dublette'] ?? null;
            nach_antwort(fn() => mail_kunde_registrierung_eingang($kidR, $dubR));   // Team (immer Benachrichtigung) + Bestätigung an den Kunden
        }
    }
}
if (!$ok) $_SESSION['reg_formular_ab'] = time();

$cssV = (int) @filemtime(BX_ROOT . '/public/assets/app.css');
$laender = ['DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz', 'NL' => 'Niederlande', 'BE' => 'Belgien', 'LU' => 'Luxemburg', 'FR' => 'Frankreich',
            'IT' => 'Italien', 'ES' => 'Spanien', 'PL' => 'Polen', 'DK' => 'Dänemark', 'CZ' => 'Tschechien', 'SE' => 'Schweden', 'GB' => 'Vereinigtes Königreich'];
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Registrieren · bulkify</title><link rel="stylesheet" href="assets/app.css?v=<?= $cssV ?>">
<script>(function(){try{var t=localStorage.getItem('bx-theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
</head><body>
<div style="max-width:620px;margin:6vh auto;padding:0 16px">
  <div style="text-align:center;margin-bottom:18px"><style>.bx-logo-hell{display:inline-block}.bx-logo-dunkel{display:none}:root[data-theme="dark"] .bx-logo-hell{display:none}:root[data-theme="dark"] .bx-logo-dunkel{display:inline-block}</style><img src="assets/bulkify-logo-dark.png" alt="bulkify" class="bx-logo-hell" style="height:40px"><img src="assets/bulkify-logo-white.png" alt="bulkify" class="bx-logo-dunkel" style="height:40px"></div>
  <div class="bx-panel">
  <?php if ($ok): ?>
    <h1 style="margin:0 0 8px;font-size:22px">Vielen Dank für Ihre Registrierung</h1>
    <p style="margin:0 0 10px">Wir prüfen Ihre Angaben und schalten Ihr Konto frei. Sie erhalten dann eine E-Mail und können sich mit Ihrer E-Mail-Adresse und Ihrem Passwort anmelden.</p>
    <a class="btn btn-ghost" href="?p=portal_login">Zur Anmeldung</a>
  <?php else: ?>
    <h1 style="margin:0 0 4px;font-size:22px">Kundenkonto anlegen</h1>
    <p class="bx-sub" style="margin-top:0">Für Unternehmen. Nach der Prüfung durch bulkify können Sie sich anmelden, Produkte ansehen und Anfragen stellen.</p>
    <?php if ($fehler !== ''): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:10px 14px;margin-bottom:12px"><?= h($fehler) ?></div><?php endif; ?>
    <form method="post" autocomplete="on">
      <div style="position:absolute;left:-5000px" aria-hidden="true"><label>Webseite</label><input type="text" name="webseite_url" tabindex="-1" autocomplete="off"></div>
      <div class="bx-grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">
        <div class="bx-field"><label>Firma</label><input type="text" name="firma" value="<?= $v('firma') ?>" required autocomplete="organization"></div>
        <div class="bx-field"><label>Ansprechpartner</label><input type="text" name="ansprechpartner" value="<?= $v('ansprechpartner') ?>" required autocomplete="name"></div>
        <div class="bx-field"><label>E-Mail</label><input type="email" name="email" value="<?= $v('email') ?>" required autocomplete="email" placeholder="name@firma.de"></div>
        <div class="bx-field"><label>Telefon <span class="muted">(optional)</span></label><input type="tel" name="telefon" value="<?= $v('telefon') ?>" autocomplete="tel"></div>
      </div>
      <div class="bx-grid" style="grid-template-columns:3fr 1fr">
        <div class="bx-field"><label>Straße</label><input type="text" name="strasse" value="<?= $v('strasse') ?>" required autocomplete="address-line1"></div>
        <div class="bx-field"><label>Nr.</label><input type="text" name="hausnummer" value="<?= $v('hausnummer') ?>"></div>
      </div>
      <div class="bx-grid" style="grid-template-columns:1fr 2fr 2fr">
        <div class="bx-field"><label>PLZ</label><input type="text" name="plz" value="<?= $v('plz') ?>" required autocomplete="postal-code"></div>
        <div class="bx-field"><label>Ort</label><input type="text" name="ort" value="<?= $v('ort') ?>" required autocomplete="address-level2"></div>
        <div class="bx-field"><label>Land</label><select name="land"><?php foreach ($laender as $lk => $ln): ?><option value="<?= $lk ?>" <?= (string)($_POST['land'] ?? 'DE') === $lk ? 'selected' : '' ?>><?= h($ln) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="bx-field"><label>USt-IdNr. <span class="muted">(für Rechnungen innerhalb der EU)</span></label><input type="text" name="ust_id" value="<?= $v('ust_id') ?>" placeholder="z. B. DE123456789"></div>
      <div class="bx-grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">
        <div class="bx-field"><label>Passwort <span class="muted">(mind. 8 Zeichen)</span></label><input type="password" name="passwort" required minlength="8" autocomplete="new-password"></div>
        <div class="bx-field"><label>Passwort wiederholen</label><input type="password" name="passwort2" required minlength="8" autocomplete="new-password"></div>
      </div>
      <div class="bx-field"><label>Was haben Sie vor? <span class="muted">(optional)</span></label><textarea name="nachricht" rows="3" style="min-height:0" placeholder="z. B. Kapseln mit eigener Rezeptur, ca. 5.000 Dosen"><?= $v('nachricht') ?></textarea></div>
      <label class="bx-check" style="white-space:normal;align-items:flex-start;margin:4px 0 12px"><input type="checkbox" name="agb" value="1" required <?= !empty($_POST['agb']) ? 'checked' : '' ?> style="margin-top:3px">
        <span>Ich bestelle als Unternehmen und meine Angaben stimmen.</span></label>
      <button class="btn btn-primary" type="submit" style="width:100%">Konto anlegen</button>
    </form>
    <p class="muted" style="font-size:12px;margin-top:14px">Schon ein Konto? <a href="?p=portal_login">Zur Anmeldung</a></p>
  <?php endif; ?>
  </div>
</div>
</body></html>
