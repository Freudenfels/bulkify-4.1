<?php
// Produktionsaufträge – drei Sichten über Reiter:
//   alle     = alle Aufträge mit „produzierbar?"-Status und Auftragseingang
//   laufend  = aktuell laufende Produktionen
//   fertig   = abgeschlossene Produktionen
// Nur lesend über die Naht erp.php.
$tabs = ['alle'=>'Alle Aufträge', 'laufend'=>'Laufende Produktionen', 'fertig'=>'Abgeschlossen'];
$tab  = (string)($_GET['tab'] ?? 'alle');
if (!isset($tabs[$tab])) $tab = 'alle';
$statusMap = ['alle'=>'alle', 'laufend'=>'laufend', 'fertig'=>'erledigt'];
$pas = erp_produktionsauftraege($statusMap[$tab]);

kopf('Produktionsaufträge', 'liste');
seitenkopf('Produktionsaufträge', count($pas) . ' ' . (count($pas) === 1 ? 'Auftrag' : 'Aufträge') . ' · ' . $tabs[$tab]);
?>
<div class="bx-row" style="gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <?php foreach ($tabs as $key => $label): ?>
    <a class="btn btn-sm <?= $tab === $key ? 'btn-primary' : 'btn-ghost' ?>" href="?p=liste&tab=<?= h($key) ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</div>
<?php if (!$pas): ?>
  <div class="bx-panel"><div class="muted">Keine Aufträge in dieser Sicht.</div></div>
<?php else: ?>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr>
    <th>Nr.</th><th>Produkt</th><th>Kunde</th><th class="bx-num">Menge</th>
    <th>Auftragseingang</th><th>Produzierbar?</th><th>Fortschritt</th><th>Status</th>
  </tr></thead>
  <tbody>
  <?php foreach ($pas as $pa):
      $g = (int)$pa['schritte_gesamt']; $f = (int)$pa['schritte_fertig'];
      $proz = $g > 0 ? round($f * 100 / $g) : 0;
      $ber = erp_pa_bereitschaft((int)$pa['id'], (string)$pa['status'], $f);
      $eingang = $pa['auftrag_eingang'] ?? ($pa['angelegt'] ?? null); ?>
    <tr onclick="location.href='?p=pa&id=<?= (int)$pa['id'] ?>'" style="cursor:pointer">
      <td><strong><?= h((string)$pa['nummer']) ?></strong><?php if (!empty($pa['auftrag_nr'])): ?><br><span class="muted" style="font-size:12px"><?= h((string)$pa['auftrag_nr']) ?></span><?php endif; ?></td>
      <td><?= h((string)($pa['produkt_name'] ?: '–')) ?><?php if (!empty($pa['form'])): ?> <span class="muted" style="font-size:12px">· <?= h((string)$pa['form']) ?></span><?php endif; ?></td>
      <td><?= h((string)($pa['kunde'] ?: '–')) ?></td>
      <td class="bx-num"><?= menge_txt($pa['menge']) ?></td>
      <td class="muted"><?= $eingang ? h(fmt_zeit($eingang, 'd.m.Y')) : '–' ?></td>
      <td><?= bereit_badge($ber['status']) ?></td>
      <td style="min-width:140px"><?= $g > 0 ? ('Schritt ' . $f . '/' . $g . ' · ' . $proz . '%') : '<span class="muted">–</span>' ?></td>
      <td><?= pa_badge((string)$pa['status']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php fuss();
