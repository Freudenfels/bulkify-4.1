<?php
// Apps (Nico 13.11.2026): alle bulkify-Programme an einer Stelle – öffnen, aufs Tablet/Handy holen (QR), als App installieren.
// Sichtbar je nach Rolle (gleiche Regeln wie die Unterseiten im Menü). Route ?p=apps, für alle angemeldeten Team-Nutzer.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/qr.php';

$rollen = function_exists('user_rollen') ? user_rollen() : [];
$admin  = in_array('admin', $rollen, true);
$hat    = fn(array $r) => $admin || (bool) array_intersect($rollen, $r);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$basis  = $scheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'app.bulkify.pro');

// Jede App: Name, wofür, Adresse, installierbar (eigenes Web-Manifest) ja/nein, wer sie sieht.
$apps = [
    ['name' => 'bulkify Dashboard', 'wofuer' => 'Alles am Rechner: Anfragen, Angebote, Aufträge, Einkauf, Produktion, Lager.', 'url' => '/', 'pwa' => true, 'zeigen' => true, 'hier' => true],
    ['name' => 'Werk-App (Produktion am Tablet)', 'wofuer' => 'Für die Mitarbeiter an der Linie: Tagesplan, Start mit Bulk-Scan, Abfüllen, Teilmengen, Kartons. Anmeldung per PIN.', 'url' => '/produktion/?p=werk', 'pwa' => true, 'zeigen' => $hat(['production'])],
    ['name' => 'Lager-App', 'wofuer' => 'Tablet/Handy am Regal: Suchen, Einbuchen, Umbuchen, Versand, Inventur, Etiketten.', 'url' => '/lager/', 'pwa' => true, 'zeigen' => $hat(['production', 'fulfillment', 'einkauf', 'labor'])],
    ['name' => 'Produktion (Team)', 'wofuer' => 'Produktionsaufträge im Büro: Schritte, Qualitätsprüfung, Laufzettel.', 'url' => '/produktion/', 'pwa' => false, 'zeigen' => $hat(['production'])],
    ['name' => 'CRM', 'wofuer' => 'Fürs Handy: Kontakte, Wiedervorlagen, wer wartet auf mich.', 'url' => '/crm/', 'pwa' => true, 'zeigen' => $admin],
    ['name' => 'Buchhaltung', 'wofuer' => 'Belege, Zahlungen, offene Posten, Mahnungen.', 'url' => '/buchhaltung/', 'pwa' => false, 'zeigen' => $hat(['finance'])],
];

render_header('apps', 'Apps');
bx_head('Apps', 'bulkify-Programme öffnen und als App auf Rechner, Tablet oder Handy installieren');
?>
<style>
  .ap-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:var(--sp-4)}
  .ap-karte{display:flex;flex-direction:column;gap:10px}
  .ap-kopf{display:flex;gap:14px;align-items:flex-start;justify-content:space-between}
  .ap-qr{flex:0 0 auto;background:#fff;padding:4px;border-radius:8px;line-height:0}
  .ap-hilfe{font-size:13px}
  .ap-hilfe summary{cursor:pointer}
  .ap-hilfe ul{margin:6px 0 0 18px;padding:0}
</style>
<div class="ap-grid">
<?php foreach ($apps as $a): if (!$a['zeigen']) continue; $voll = $basis . $a['url']; ?>
  <div class="bx-panel ap-karte">
    <div class="ap-kopf">
      <div>
        <h2 style="margin:0 0 4px"><?= h($a['name']) ?></h2>
        <div class="muted" style="font-size:13px"><?= h($a['wofuer']) ?></div>
        <div class="muted" style="font-size:12px;margin-top:6px"><?= h($voll) ?></div>
      </div>
      <div class="ap-qr" title="Mit dem Tablet oder Handy scannen"><?= qr_svg($voll, 112) ?></div>
    </div>
    <div class="bx-row" style="gap:8px;flex-wrap:wrap">
      <a class="btn btn-primary btn-sm" href="<?= h($a['url']) ?>" target="_blank" rel="noopener">Öffnen</a>
      <?php if (!empty($a['hier'])): ?><button type="button" class="btn btn-ghost btn-sm" id="apInstall" hidden>Auf diesem Gerät installieren</button><?php endif; ?>
    </div>
    <details class="ap-hilfe">
      <summary><?= $a['pwa'] ? 'Als App installieren – so geht es' : 'Als Verknüpfung ablegen – so geht es' ?></summary>
      <ul>
        <li>Tablet/Handy: QR-Code scannen, die Seite öffnet sich im Browser.</li>
        <li>Android (Chrome): Menü ⋮ → „App installieren“ bzw. „Zum Startbildschirm hinzufügen“.</li>
        <li>iPhone/iPad (Safari): Teilen-Symbol → „Zum Home-Bildschirm“.</li>
        <li>Rechner (Chrome/Edge): in der Adresszeile das Symbol „Installieren“ bzw. Menü → „App installieren“.</li>
        <?php if (!$a['pwa']): ?><li>Diese Seite hat (noch) keine eigene App-Datei – sie wird als Verknüpfung abgelegt und öffnet im Browser.</li><?php endif; ?>
      </ul>
    </details>
  </div>
<?php endforeach; ?>
<?php if ($admin): $br = $basis . '/lager/?p=bruecke_skript&art=hintergrund'; ?>
  <div class="bx-panel ap-karte">
    <div class="ap-kopf">
      <div>
        <h2 style="margin:0 0 4px">Lager-Brücke (Windows-PC)</h2>
        <div class="muted" style="font-size:13px">Programm für den Lager-PC: druckt Etiketten und steuert die Regal-Leuchten (Pick-to-Light). Startet nach der Einrichtung automatisch mit Windows, kein Admin-Recht nötig.</div>
      </div>
    </div>
    <div class="bx-row" style="gap:8px;flex-wrap:wrap">
      <a class="btn btn-primary btn-sm" href="<?= h($br) ?>">Herunterladen (Hintergrund, Autostart)</a>
      <a class="btn btn-ghost btn-sm" href="<?= h($basis . '/lager/?p=bruecke_skript') ?>">Testversion mit Fenster</a>
    </div>
    <div class="muted" style="font-size:12px">Nur am Lager-PC ausführen. Download nur mit Admin-Anmeldung in der Lager-App.</div>
  </div>
<?php endif; ?>
</div>
<script>
(function(){
  var b = document.getElementById('apInstall'), ev = null;
  if (!b) return;
  window.addEventListener('beforeinstallprompt', function(e){ e.preventDefault(); ev = e; b.hidden = false; });
  b.addEventListener('click', function(){ if (!ev) return; ev.prompt(); ev.userChoice.then(function(){ ev = null; b.hidden = true; }); });
  window.addEventListener('appinstalled', function(){ b.hidden = true; });
})();
</script>
<?php render_footer();
