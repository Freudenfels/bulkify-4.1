<?php
// Archiv – abgeschlossene Produktionsaufträge (status erledigt), zuletzt fertige zuerst. Nur lesend.
$pas = erp_produktionsauftraege('erledigt');
kopf('Archiv', 'archiv');
seitenkopf('Archiv', count($pas) . ' ' . (count($pas) === 1 ? 'abgeschlossener Auftrag' : 'abgeschlossene Aufträge'));
?>
<?php if (!$pas): ?>
  <div class="bx-panel"><div class="muted">Noch keine abgeschlossenen Aufträge.</div></div>
<?php else: ?>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr><th>Produkt</th><th class="bx-num">Menge</th><th>Auftragseingang</th></tr></thead>
  <tbody>
    <?php foreach ($pas as $pa): $eingang = $pa['auftrag_eingang'] ?? ($pa['angelegt'] ?? null); ?>
    <tr onclick="location.href='?p=pa&id=<?= (int)$pa['id'] ?>'" style="cursor:pointer">
      <td><?= pr_produkt_zelle($pa) ?></td>
      <td class="bx-num" style="white-space:nowrap"><?= menge_txt($pa['menge']) ?></td>
      <td class="muted"><?= $eingang ? h(fmt_zeit($eingang, 'd.m.Y')) : '–' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php fuss();
