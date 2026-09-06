<?php
// "Mehr": alles, was selten gebraucht wird. Route: ?p=mehr
require_once BX_ROOT . '/core/wartet.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tun'] ?? '') === 'dashboard') {
    $url = trim((string)($_POST['dashboard_url'] ?? ''));
    if ($url !== '' && !preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
    crm_meta_schreiben('dashboard_url', rtrim($url, '/'));
    header('Location: ?p=mehr&ok=1'); exit;
}

$u = crm_benutzer();
kopf('Mehr', 'mehr');
seitenkopf('Mehr', h((string)($u['name'] ?? '')) . ' · ' . h((string)($u['email'] ?? '')));
if (isset($_GET['ok'])) hinweis('Gespeichert.');
?>
<div class="karte">
  <a class="zeile" href="?p=termine" style="text-decoration:none">
    <div class="mitte"><span class="titel">Termine</span><span class="unter">Rückruf, Messe, Besuch</span></div></a>
  <a class="zeile" href="?p=kunden" style="text-decoration:none">
    <div class="mitte"><span class="titel">Kunden</span><span class="unter">Verlauf an bestehenden Kunden des Dashboards</span></div></a>
</div>

<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Adresse des Dashboards</h2>
  <p class="leise" style="margin-bottom:12px">Damit jede Zeile in der Liste direkt zum Vorgang im Dashboard führt.</p>
  <form method="post">
    <input type="hidden" name="tun" value="dashboard">
    <div class="feld">
      <input type="text" name="dashboard_url" placeholder="beta.bulkify.pro"
             value="<?= h(crm_meta_lesen('dashboard_url', '')) ?>">
    </div>
    <button class="btn stark" type="submit">Speichern</button>
  </form>
</div></div>

<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Darstellung</h2>
  <button class="btn" type="button" id="thema">Dunkler Modus</button>
</div></div>

<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Aufs Handy legen</h2>
  <p class="leise" style="margin:0">Android/Chrome: Menü (drei Punkte) &rarr; App installieren.
     iPhone/Safari: Teilen &rarr; Zum Home-Bildschirm. Danach eigenes Icon, keine Browserleiste.</p>
</div></div>

<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Woher die Zugangsdaten kommen</h2>
  <div class="leise">
    <div>Datenbank: <?= h(DB_NAME) ?> auf <?= h(DB_HOST) ?></div>
    <div>Zugangsdatei: <?= h(crm_secrets_quelle() ?: 'keine gefunden - es gelten die lokalen Vorgaben') ?></div>
    <?php require_once BX_ROOT . '/core/ki.php'; $ks = ki_status(); ?>
    <div>KI: <?= $ks['bereit'] ? 'einsatzbereit, Schl&uuml;ssel endet auf ' . h($ks['endet']) : 'nicht eingerichtet' ?></div>
  </div>
  <p class="leise" style="margin:10px 0 0;font-size:13px">Das CRM nutzt dieselbe Datenbank und denselben
     KI-Schl&uuml;ssel wie das Dashboard. Zwei Kopien w&uuml;rden fr&uuml;her oder sp&auml;ter auseinanderlaufen.</p>
</div></div>

<a class="btn" href="?p=logout" style="margin-bottom:24px">Abmelden</a>

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
