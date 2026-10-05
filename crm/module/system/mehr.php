<?php
// "Mehr": alles, was selten gebraucht wird. Route: ?p=mehr
require_once BX_ROOT . '/core/wartet.php';
require_once BX_ROOT . '/core/mail_abruf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tun'] ?? '') === 'dashboard') {
    $url = trim((string)($_POST['dashboard_url'] ?? ''));
    if ($url !== '' && !preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
    crm_meta_schreiben('dashboard_url', rtrim($url, '/'));
    header('Location: ?p=mehr&ok=1'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tun'] ?? '') === 'intake_token_neu') {
    crm_meta_schreiben('lead_intake_token', bin2hex(random_bytes(24)));
    header('Location: ?p=mehr&ok=1'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tun'] ?? '') === 'imap') {
    mail_imap_speichern($_POST);
    header('Location: ?p=mehr&ok=1#eingang'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tun'] ?? '') === 'mail_cron_token_neu') {
    crm_meta_schreiben('mail_cron_token', bin2hex(random_bytes(24)));
    header('Location: ?p=mehr&ok=1#eingang'); exit;
}

$u = crm_benutzer();
kopf('Mehr', 'mehr');
seitenkopf('Mehr', h((string)($u['name'] ?? '')) . ' · ' . h((string)($u['email'] ?? '')));
if (isset($_GET['ok'])) hinweis('Gespeichert.');
?>
<div class="karte">
  <a class="crm-zeile" href="?p=termine" style="text-decoration:none">
    <div class="crm-mitte"><span class="titel">Termine</span><span class="unter">Rückruf, Messe, Besuch</span></div></a>
  <a class="crm-zeile" href="?p=kunden" style="text-decoration:none">
    <div class="crm-mitte"><span class="titel">Kunden</span><span class="unter">Verlauf an bestehenden Kunden des Dashboards</span></div></a>
</div>

<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Adresse des Dashboards</h2>
  <p class="muted" style="margin-bottom:12px">Damit jede Zeile in der Liste direkt zum Vorgang im Dashboard führt.</p>
  <form method="post">
    <input type="hidden" name="tun" value="dashboard">
    <div class="bx-field">
      <input type="text" name="dashboard_url" placeholder="beta.bulkify.pro"
             value="<?= h(crm_meta_lesen('dashboard_url', '')) ?>">
    </div>
    <button class="btn btn-primary" type="submit">Speichern</button>
  </form>
</div></div>

<div class="karte"><div class="rumpf">
  <?php $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $intakeUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/crm/lead_intake.php'; ?>
  <h2 style="margin-top:0">Website-Eingang</h2>
  <p class="muted" style="margin-top:0">Anfragen von der Webseite (bulkify.pro) landen automatisch als Kontakt hier.
     Die Webseite postet die Formularfelder an die URL unten und schickt den Token mit (Feld <code>token</code>
     oder Header <code>X-Intake-Token</code>).</p>
  <div class="bx-field"><label>Endpunkt-URL</label>
    <input type="text" readonly onclick="this.select()" value="<?= h($intakeUrl) ?>"></div>
  <div class="bx-field"><label>Token</label>
    <input type="text" readonly onclick="this.select()" value="<?= h(lead_intake_token()) ?>"></div>
  <form method="post" onsubmit="return confirm('Neuen Token erzeugen? Der alte gilt danach nicht mehr - die Webseite muss den neuen bekommen.');">
    <input type="hidden" name="tun" value="intake_token_neu">
    <button class="btn btn-ghost" type="submit">Neuen Token erzeugen</button>
  </form>
  <p class="muted" style="font-size:13px;margin:10px 0 0">Formularfelder (alle optional außer einem von Name/E-Mail/Telefon):
     <code>name, firma, email, telefon, whatsapp</code> sowie <code>anliegen, ziel, produktform, menge,
     wirkstoffe, rezeptur, nachricht</code>. Antwort: JSON <code>{ok, kontakt_id}</code>.</p>
</div></div>

<div class="karte" id="eingang"><div class="rumpf">
  <?php $ik = mail_imap_konfig();
        $cronUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/crm/mail_cron.php?token=' . mail_cron_token(); ?>
  <h2 style="margin-top:0">E-Mail-Eingang (IMAP)</h2>
  <p class="muted" style="margin-top:0">Zugang zum Postfach (z. B. <code>crm@bulkify.pro</code>). Neue Mails landen
     unter „E-Mail-Eingang“ mit KI-Vorschau; angelegt wird erst per Klick. Der Zugang wird in der Datenbank
     gespeichert, nicht an das Dashboard weitergegeben.</p>
  <?php if (!mail_abruf_moeglich()): ?>
    <div class="bx-panel" style="padding:10px 14px;border-color:#e6c4c0;color:#8f231b;margin-bottom:12px">
      Hinweis: Die PHP-IMAP-Erweiterung ist auf diesem Server nicht aktiv. Zum Abrufen muss sie aktiviert werden
      (sonst bauen wir einen alternativen Abruf).</div>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="tun" value="imap">
    <div class="bx-grid">
      <div class="bx-field"><label for="imap_host">IMAP-Server (Host)</label>
        <input type="text" id="imap_host" name="imap_host" value="<?= h($ik['host']) ?>" placeholder="z. B. imap.ionos.de"></div>
      <div class="bx-field"><label for="imap_port">Port</label>
        <input type="number" id="imap_port" name="imap_port" value="<?= (int)$ik['port'] ?>" placeholder="993"></div>
    </div>
    <div class="bx-grid">
      <div class="bx-field"><label for="imap_user">Benutzername</label>
        <input type="text" id="imap_user" name="imap_user" value="<?= h($ik['user']) ?>" placeholder="meist die volle Adresse"></div>
      <div class="bx-field"><label for="imap_pass">Passwort</label>
        <input type="password" id="imap_pass" name="imap_pass" autocomplete="new-password"
               placeholder="<?= $ik['pass'] !== '' ? '• gesetzt – leer lassen zum Behalten' : 'Postfach-Passwort' ?>"></div>
    </div>
    <div class="bx-grid">
      <div class="bx-field"><label for="imap_ordner">Ordner</label>
        <input type="text" id="imap_ordner" name="imap_ordner" value="<?= h($ik['ordner']) ?>" placeholder="INBOX"></div>
      <div class="bx-field"><label for="imap_ssl">Verschlüsselung</label>
        <select id="imap_ssl" name="imap_ssl">
          <option value="1" <?= $ik['ssl'] ? 'selected' : '' ?>>SSL/TLS (Port 993)</option>
          <option value="0" <?= $ik['ssl'] ? '' : 'selected' ?>>ohne (nicht empfohlen)</option>
        </select></div>
    </div>
    <button class="btn btn-primary" type="submit">Speichern</button>
  </form>

  <div class="bx-field" style="margin-top:16px"><label>Automatischer Abruf (Cronjob)</label>
    <input type="text" readonly onclick="this.select()" value="<?= h($cronUrl) ?>"></div>
  <p class="muted" style="font-size:13px;margin:0">Diese URL alle paar Minuten per Cron aufrufen, dann füllt sich der
     Eingang von selbst (holt nur ab, legt nichts automatisch an). Ohne Cron geht es jederzeit über „Postfach abrufen“.</p>
  <form method="post" onsubmit="return confirm('Neuen Cron-Token erzeugen? Die alte URL funktioniert danach nicht mehr.');" style="margin-top:8px">
    <input type="hidden" name="tun" value="mail_cron_token_neu">
    <button class="btn btn-ghost btn-sm" type="submit">Neuen Cron-Token erzeugen</button>
  </form>
</div></div>

<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Darstellung</h2>
  <button class="btn btn-ghost" type="button" id="thema">Dunkler Modus</button>
</div></div>

<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Aufs Handy legen</h2>
  <p class="muted" style="margin:0">Android/Chrome: Menü (drei Punkte) &rarr; App installieren.
     iPhone/Safari: Teilen &rarr; Zum Home-Bildschirm. Danach eigenes Icon, keine Browserleiste.</p>
</div></div>

<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Woher die Zugangsdaten kommen</h2>
  <div class="muted">
    <div>Datenbank: <?= h(DB_NAME) ?> auf <?= h(DB_HOST) ?></div>
    <div>Zugangsdatei: <?= h(crm_secrets_quelle() ?: 'keine gefunden - es gelten die lokalen Vorgaben') ?></div>
    <?php require_once BX_ROOT . '/core/ki.php'; $ks = ki_status(); ?>
    <div>KI: <?= $ks['bereit'] ? 'einsatzbereit, Schl&uuml;ssel endet auf ' . h($ks['endet']) : 'nicht eingerichtet' ?></div>
  </div>
  <p class="muted" style="margin:10px 0 0;font-size:13px">Das CRM nutzt dieselbe Datenbank und denselben
     KI-Schl&uuml;ssel wie das Dashboard. Zwei Kopien w&uuml;rden fr&uuml;her oder sp&auml;ter auseinanderlaufen.</p>
</div></div>

<a class="btn btn-ghost" href="?p=logout" style="margin-bottom:24px">Abmelden</a>

<script>
(function () {
  var k = document.getElementById('thema'), r = document.documentElement;
  function stand(){ return r.getAttribute('data-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); }
  function zeigen(){ k.textContent = stand() === 'dark' ? 'Heller Modus' : 'Dunkler Modus'; }
  k.addEventListener('click', function () {
    var neu = stand() === 'dark' ? 'light' : 'dark';
    r.setAttribute('data-theme', neu);
    try { localStorage.setItem('crm-theme', neu); } catch (e) {}
    zeigen();
  });
  zeigen();
})();
</script>
<?php fuss('mehr');
