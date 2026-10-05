<?php
// Produktionsmodus – Auswahl: nur Aufträge, die JETZT gemacht werden können (produzierbar) oder
// bereits laufen. Klick öffnet den geführten Produktionsmodus (?p=run). Sortiert nach geplantem Datum.
$rows = [];
foreach (erp_produktionsauftraege('') as $pa) {   // aktive (offen/laufend)
    $f = (int)$pa['schritte_fertig'];
    $ber = erp_pa_bereitschaft((int)$pa['id'], (string)$pa['status'], $f);
    if ((string)$pa['status'] === 'laufend' || $ber['status'] === 'bereit') { $pa['_ber'] = $ber['status']; $rows[] = $pa; }
}
// nach geplantem Datum (ohne Termin ans Ende), dann Prio
usort($rows, function ($a, $b) {
    $ga = (string)($a['geplant_am'] ?? ''); $gb = (string)($b['geplant_am'] ?? '');
    if (($ga === '') !== ($gb === '')) return $ga === '' ? 1 : -1;
    if ($ga !== $gb) return strcmp($ga, $gb);
    return ((int)($a['prio'] ?? 2)) <=> ((int)($b['prio'] ?? 2));
});

kopf('Produktionsmodus', 'modus');
seitenkopf('Produktionsmodus', count($rows) . ' ' . (count($rows) === 1 ? 'Auftrag' : 'Aufträge') . ' startbar');
?>
<?php if (!$rows): ?>
  <div class="bx-panel"><div class="muted">Aktuell ist kein Auftrag produzierbar oder in Produktion. Sobald Material da und freigegeben ist, erscheint er hier.</div></div>
<?php else: ?>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr><th>Nr.</th><th>Produkt</th><th>Kunde</th><th class="bx-num">Menge</th><th>Wann dran</th><th>Status</th><th>Fortschritt</th></tr></thead>
  <tbody>
    <?php foreach ($rows as $pa):
        $g = (int)$pa['schritte_gesamt']; $f = (int)$pa['schritte_fertig']; ?>
    <tr onclick="location.href='?p=run&id=<?= (int)$pa['id'] ?>'" style="cursor:pointer">
      <td><strong><?= h((string)$pa['nummer']) ?></strong></td>
      <td><?= h((string)($pa['produkt_name'] ?: '–')) ?></td>
      <td><?= h((string)($pa['kunde'] ?: '–')) ?></td>
      <td class="bx-num"><?= menge_txt($pa['menge']) ?></td>
      <td><?= !empty($pa['geplant_am']) ? h(date('d.m.Y', strtotime((string)$pa['geplant_am']))) : '<span class="muted">ohne Termin</span>' ?></td>
      <td><?= (string)$pa['status'] === 'laufend' ? '<span class="badge badge-info">in Produktion</span>' : '<span class="badge badge-ok">produzierbar</span>' ?></td>
      <td><?= $g > 0 ? ('Schritt ' . $f . '/' . $g) : '<span class="muted">–</span>' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<p class="muted" style="font-size:12px;margin:10px 0 0">Hier stehen nur startbare Aufträge (Material da / in Produktion). „Wann dran" kommt aus der Planung (geplantes Datum, wird im Dashboard gesetzt).</p>
<?php endif; ?>
<?php fuss();
