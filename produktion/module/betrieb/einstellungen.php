<?php
// Einstellungen: Maschinen und Räume – NUR ANSICHT. Gepflegt werden sie jetzt zentral im DASHBOARD
// (Maschinenfuhrpark, /?p=maschinen). Die Produktion liest sie weiter (QR-Scan je Step, Reinigungspläne).
// Eigene pr_-Tabellen (pr_raum, pr_maschine) – gleiche DB wie das Dashboard.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Bearbeiten ist ins Dashboard umgezogen – hier keine Änderungen mehr annehmen.
    flash('Maschinen/Räume werden jetzt im Dashboard verwaltet (Maschinenfuhrpark).', 'warn');
    weiter('?p=einstellungen&tab=' . (preg_replace('/[^a-z]/', '', (string)($_POST['tab'] ?? 'maschinen'))));
}
$tab = (($_GET['tab'] ?? '') === 'raeume') ? 'raeume' : 'maschinen';
$raeume    = pr_raum_alle();
$maschinen = pr_maschine_alle();

kopf('Einstellungen', 'einstellungen');
seitenkopf('Einstellungen', 'Maschinen & Räume (Verwaltung im Dashboard)');
?>
<div class="bx-panel" style="border-color:var(--gruen,#1D9E75)">
  Maschinen und Räume werden jetzt zentral im <strong>Dashboard</strong> gepflegt (Maschinenfuhrpark).
  Hier siehst du nur den aktuellen Stand – die Produktion scannt die Maschinen-QR-Codes weiter, die Reinigungspläne bleiben hier.
  <div style="margin-top:10px"><a class="btn btn-primary btn-sm" href="/?p=maschinen" target="_blank" rel="noopener">Im Dashboard verwalten</a></div>
</div>

<div class="settabs">
  <a href="?p=einstellungen&tab=maschinen" class="<?= $tab === 'maschinen' ? 'on' : '' ?>">Maschinen</a>
  <a href="?p=einstellungen&tab=raeume" class="<?= $tab === 'raeume' ? 'on' : '' ?>">Räume</a>
</div>

<?php if ($tab === 'maschinen'): ?>
<?php if (!$maschinen): ?><div class="bx-panel"><div class="muted">Noch keine Maschinen – im Dashboard anlegen.</div></div><?php else: ?>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr><th>Maschine</th><th>Typ</th><th>QR-Code</th><th>Raum</th><th>Reinigung</th></tr></thead>
  <tbody>
    <?php foreach ($maschinen as $m): ?>
    <tr>
      <td><strong><?= h((string)$m['name']) ?></strong><?php if (!empty($m['notiz'])): ?><br><span class="muted" style="font-size:12px"><?= h((string)$m['notiz']) ?></span><?php endif; ?></td>
      <td><?= h(pr_maschinentyp_label((string)($m['typ'] ?? ''))) ?></td>
      <td><?= h((string)($m['qr_code'] ?: '–')) ?></td>
      <td><?= h((string)($m['raum_name'] ?: '–')) ?></td>
      <td><?= h(pr_intervall_label((string)($m['reinigung_intervall'] ?? ''))) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<?php else: ?>
<?php if (!$raeume): ?><div class="bx-panel"><div class="muted">Noch keine Räume – im Dashboard anlegen.</div></div><?php else: ?>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr><th>Raum</th><th>Reinigung</th></tr></thead>
  <tbody>
    <?php foreach ($raeume as $r): ?>
    <tr>
      <td><strong><?= h((string)$r['name']) ?></strong><?php if (!empty($r['notiz'])): ?><br><span class="muted" style="font-size:12px"><?= h((string)$r['notiz']) ?></span><?php endif; ?></td>
      <td><?= h(pr_intervall_label((string)($r['reinigung_intervall'] ?? ''))) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php endif; ?>
<?php fuss();
