<?php
// „bulkify aufs Handy" – Anleitung zum Installieren. Route: ?p=app
// Es gibt keinen Play-Store-Download: bulkify ist eine Web-App, die Android und iOS auf den
// Startbildschirm legen koennen. Danach hat sie ein eigenes Icon und startet ohne Browserleiste.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/pwa.php';

$basis = rtrim((string)(function_exists('mail_basis_url') ? mail_basis_url() : ''), '/');
if ($basis === '') {
    $schema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $basis  = $schema . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
}
$sicher = str_starts_with($basis, 'https://');

render_header('app', 'bulkify aufs Handy');
bx_head('bulkify aufs Handy', 'Als App auf den Startbildschirm – ohne Play Store, ohne Download');
?>

<div class="bx-panel" data-nur-browser hidden style="border-color:var(--gruen)">
  <strong>Direkt hier installieren</strong>
  <p class="muted" style="margin:6px 0 10px">Dein Browser bietet die Installation gerade an.</p>
  <button class="btn btn-primary" type="button" id="bx-install">Auf dem Startbildschirm ablegen</button>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Android (Chrome)</h2>
  <ol style="margin:0;padding-left:20px;line-height:2">
    <li><?= h($basis) ?> im Chrome oeffnen und anmelden.</li>
    <li>Oben rechts auf die drei Punkte tippen.</li>
    <li><strong>App installieren</strong> waehlen – manchmal heisst es <em>Zum Startbildschirm hinzufuegen</em>.</li>
    <li>Bestaetigen. Das bulkify-Icon liegt jetzt neben den anderen Apps.</li>
  </ol>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">iPhone (Safari)</h2>
  <ol style="margin:0;padding-left:20px;line-height:2">
    <li><?= h($basis) ?> in <strong>Safari</strong> oeffnen (in Chrome geht es auf dem iPhone nicht).</li>
    <li>Unten auf das Teilen-Symbol tippen (Quadrat mit Pfeil nach oben).</li>
    <li><strong>Zum Home-Bildschirm</strong> waehlen und mit <strong>Hinzufuegen</strong> bestaetigen.</li>
  </ol>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Was du danach hast</h2>
  <ul class="muted" style="margin:0;padding-left:20px;line-height:2">
    <li>Eigenes Icon, eigener Eintrag im App-Umschalter, keine Browserleiste.</li>
    <li>Dieselben Daten wie am Rechner – es ist dasselbe System, kein zweiter Stand.</li>
    <li>Ohne Internet geht es nicht: bulkify arbeitet immer mit den echten, aktuellen Daten.</li>
    <li>Abmelden wie gewohnt ueber das Menue. Auf einem fremden Geraet danach die App wieder loeschen.</li>
  </ul>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Fuer Lieferanten</h2>
  <p class="muted" style="margin:6px 0 0">Genau derselbe Weg, dieselbe Adresse: <?= h($basis) ?>.
    Der Lieferant meldet sich mit seinem Zugang an und landet in seinem Portal. Die Anleitung dazu
    findet er dort unter <strong>Anleitung</strong>; du kannst sie ihm auch als Link schicken:
    <?= h($basis) ?>/?p=lieferant_hilfe</p>
</div>

<?php if (!$sicher): ?>
<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">
  Diese Adresse laeuft ohne HTTPS (<?= h($basis) ?>). Installieren geht nur ueber eine verschluesselte
  Verbindung – auf <strong>beta.bulkify.pro</strong> klappt es. Lokal ist das normal und kein Fehler.
</div>
<?php endif; ?>

<script>
// Chrome fragt selbst, wann es die Installation anbietet. Bis dahin bleibt der Kasten oben versteckt.
(function () {
  var merker = null, kasten = document.querySelector('[data-nur-browser]'), knopf = document.getElementById('bx-install');
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault(); merker = e; if (kasten) kasten.hidden = false;
  });
  if (knopf) knopf.addEventListener('click', function () {
    if (!merker) return;
    merker.prompt(); merker = null; if (kasten) kasten.hidden = true;
  });
})();
</script>
<?php
render_footer();
