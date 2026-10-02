<?php
// Startseite des Produktions-Programms: offene/aktive Produktionsaufträge mit Fortschritt.
// Nur lesend (über die Naht erp.php). Schritt-Abschluss baut der Produktions-Chat als Nächstes.
$status = isset($_GET['status']) ? preg_replace('/[^a-z_]/', '', (string)$_GET['status']) : '';
$pas = erp_produktionsauftraege($status);

kopf('Produktionsaufträge', 'liste');
seitenkopf('Produktionsaufträge', count($pas) . ' ' . (count($pas) === 1 ? 'Auftrag' : 'Aufträge') . ($status === '' ? ' (offen/in Arbeit)' : ''));
?>
<?php if (!$pas): ?>
  <div class="bx-panel"><div class="muted">Keine Produktionsaufträge in diesem Status.</div></div>
<?php else: ?>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr><th>Nr.</th><th>Produkt</th><th>Kunde</th><th class="bx-num">Menge</th><th>Fortschritt</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($pas as $pa):
      $g = (int)$pa['schritte_gesamt']; $f = (int)$pa['schritte_fertig'];
      $proz = $g > 0 ? round($f * 100 / $g) : 0; ?>
    <tr>
      <td><strong><?= h((string)$pa['nummer']) ?></strong><?php if (!empty($pa['auftrag_nr'])): ?><br><span class="muted" style="font-size:12px"><?= h((string)$pa['auftrag_nr']) ?></span><?php endif; ?></td>
      <td><?= h((string)($pa['produkt_name'] ?: '–')) ?><?php if (!empty($pa['form'])): ?> <span class="muted" style="font-size:12px">· <?= h((string)$pa['form']) ?></span><?php endif; ?></td>
      <td><?= h((string)($pa['kunde'] ?: '–')) ?></td>
      <td class="bx-num"><?= menge_txt($pa['menge']) ?></td>
      <td style="min-width:140px"><?= $g > 0 ? ('Schritt ' . $f . '/' . $g . ' · ' . $proz . '%') : '<span class="muted">–</span>' ?></td>
      <td><?= pa_badge((string)$pa['status']) ?></td>
      <td class="bx-num"><a class="btn btn-ghost btn-sm" href="?p=pa&id=<?= (int)$pa['id'] ?>">öffnen</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php fuss();
