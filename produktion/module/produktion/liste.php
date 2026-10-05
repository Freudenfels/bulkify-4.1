<?php
// Produktionsaufträge – nur AKTIVE (offen + laufend). Abgeschlossene stehen im Archiv (?p=archiv).
// Sortiert nach geplantem Datum (Planung kommt aus dem Dashboard), dann Prio. Nur lesend über erp.php.
$pas = erp_produktionsauftraege('');   // offen + laufend
usort($pas, function ($a, $b) {
    $ga = (string)($a['geplant_am'] ?? ''); $gb = (string)($b['geplant_am'] ?? '');
    if (($ga === '') !== ($gb === '')) return $ga === '' ? 1 : -1;
    if ($ga !== $gb) return strcmp($ga, $gb);
    return ((int)($a['prio'] ?? 2)) <=> ((int)($b['prio'] ?? 2));
});

kopf('Produktionsaufträge', 'liste');
seitenkopf('Produktionsaufträge', count($pas) . ' aktive ' . (count($pas) === 1 ? 'Auftrag' : 'Aufträge'),
    '<a class="btn btn-ghost btn-sm" href="?p=archiv">Archiv</a>');
?>
<?php if (!$pas): ?>
  <div class="bx-panel"><div class="muted">Keine aktiven Aufträge. Abgeschlossene stehen im <a href="?p=archiv">Archiv</a>.</div></div>
<?php else: ?>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr>
    <th>Nr.</th><th>Produkt</th><th>Kunde</th><th class="bx-num">Menge</th>
    <th>Wann dran</th><th>Auftragseingang</th><th>Produzierbar?</th><th>Fortschritt</th><th>Status</th>
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
      <td><?= !empty($pa['geplant_am']) ? h(date('d.m.Y', strtotime((string)$pa['geplant_am']))) : '<span class="muted">—</span>' ?></td>
      <td class="muted"><?= $eingang ? h(fmt_zeit($eingang, 'd.m.Y')) : '–' ?></td>
      <td><?= bereit_badge($ber['status']) ?></td>
      <td style="min-width:120px"><?= $g > 0 ? ('Schritt ' . $f . '/' . $g . ' · ' . $proz . '%') : '<span class="muted">–</span>' ?></td>
      <td><?= pa_badge((string)$pa['status']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php fuss();
