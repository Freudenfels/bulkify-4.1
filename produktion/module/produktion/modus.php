<?php
// Produktionsmodus – Auswahl: Aufträge, die JETZT gemacht werden können (produzierbar), bereits laufen oder für die
// eine TEILMENGE machbar ist (Nico 05.11.2026: z. B. 9.000 von 450.000 Kapseln da -> 100 Pkg jetzt, Rest später).
// Klick öffnet den geführten Produktionsmodus (?p=run). Sortiert nach geplantem Datum.
$rows = [];
foreach (erp_produktionsauftraege('') as $pa) {   // aktive (offen/laufend)
    $f = (int)$pa['schritte_fertig'];
    $ber = erp_pa_bereitschaft((int)$pa['id'], (string)$pa['status'], $f);
    if ((string)$pa['status'] === 'laufend' || $ber['status'] === 'bereit') { $pa['_ber'] = $ber['status']; $rows[] = $pa; continue; }
    if ((string)$pa['status'] !== 'offen') continue;
    $tm = erp_teilmenge_machbar((int)$pa['id']);
    if ($tm['rest'] > 0 && max($tm['komplett'], $tm['vor_etikett']) > 0) { $pa['_ber'] = 'teil'; $pa['_tm'] = $tm; $rows[] = $pa; }
}
// nach geplantem Datum (ohne Termin ans Ende), dann Prio
usort($rows, function ($a, $b) {
    $ga = (string)($a['geplant_am'] ?? ''); $gb = (string)($b['geplant_am'] ?? '');
    if (($ga === '') !== ($gb === '')) return $ga === '' ? 1 : -1;
    if ($ga !== $gb) return strcmp($ga, $gb);
    return ((int)($a['prio'] ?? 2)) <=> ((int)($b['prio'] ?? 2));
});

kopf('Produktionsmodus', 'modus');
seitenkopf('Produktionsmodus', count($rows) . ' ' . (count($rows) === 1 ? 'Auftrag' : 'Aufträge') . ' startbar (auch Teilmengen)');
?>
<?php if (!$rows): ?>
  <div class="bx-panel"><div class="muted">Aktuell ist kein Auftrag produzierbar oder in Produktion. Sobald Material da und freigegeben ist, erscheint er hier.</div></div>
<?php else: ?>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr><th>Produkt</th><th class="bx-num">Menge</th><th class="pr-wann">Wann dran</th><th>Stand</th></tr></thead>
  <tbody>
    <?php foreach ($rows as $pa):
        $g = (int)$pa['schritte_gesamt']; $f = (int)$pa['schritte_fertig']; ?>
    <tr onclick="location.href='?p=run&id=<?= (int)$pa['id'] ?>'" style="cursor:pointer">
      <td><?= pr_produkt_zelle($pa) ?></td>
      <td class="bx-num" style="white-space:nowrap"><?= menge_txt($pa['menge']) ?></td>
      <td class="pr-wann" style="white-space:nowrap"><?= !empty($pa['geplant_am']) ? h(date('d.m.Y', strtotime((string)$pa['geplant_am']))) : '<span class="muted">ohne Termin</span>' ?></td>
      <td><?php if ((string)$pa['status'] === 'laufend'): ?><span class="badge badge-info">in Produktion</span>
          <?php elseif (($pa['_ber'] ?? '') === 'teil'): $tmM = $pa['_tm']; ?><span class="badge badge-warn">Teilmenge machbar: <?= menge_txt($tmM['komplett'] > 0 ? $tmM['komplett'] : $tmM['vor_etikett']) ?> Pkg<?= $tmM['komplett'] > 0 ? '' : ' bis vor Etikett' ?></span>
          <?php else: ?><span class="badge badge-ok">produzierbar</span><?php endif; ?>
          <?php if (!empty($pa['geplant_am'])): ?><div class="muted pr-sub pr-nur-handy">dran <?= h(date('d.m.', strtotime((string)$pa['geplant_am']))) ?></div><?php endif; ?>
          <?php if ($g > 0 && $f > 0): ?><div class="muted pr-sub">Schritt <?= $f ?>/<?= $g ?></div><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<p class="muted" style="font-size:12px;margin:10px 0 0">Hier stehen startbare Aufträge (Material da / in Produktion) und Aufträge, für die jetzt eine Teilmenge machbar ist – im Auftrag über „Teilmenge produzieren“, der Rest bleibt offen. „Wann dran" kommt aus der Planung (geplantes Datum, wird im Dashboard gesetzt).</p>
<?php endif; ?>
<?php fuss();
