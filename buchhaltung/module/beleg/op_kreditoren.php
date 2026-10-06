<?php
// Offene Posten Kreditoren (Zeilenebene je Eingangsrechnung). Filter alle/überfällig. Druckbar + CSV
// (?p=beleg_export&art=vop). Datenquelle: kreditor.php (lieferant_rechnung). Route: op_kreditoren.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/kreditor.php';
kreditor_init();

$filter = ($_GET['filter'] ?? '') === 'ueberfaellig' ? 'ueberfaellig' : '';
$rows = kr_liste('offen');
$heute = strtotime(date('Y-m-d'));
if ($filter === 'ueberfaellig') $rows = array_values(array_filter($rows, fn($r) => $r['faellig'] && strtotime((string)$r['faellig']) < $heute));

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$sumOffen = 0.0; $sumUe = 0.0; $anzUe = 0;
foreach ($rows as $r) {
    $sumOffen += (float)$r['rest'];
    if ($r['faellig'] && strtotime((string)$r['faellig']) < $heute) { $sumUe += (float)$r['rest']; $anzUe++; }
}

render_header('op_kreditoren', 'Offene Posten (Kreditoren)');
?>
<style>@media print{.bx-side,.bx-sidegriff,.bx-sideauf,.bx-mobilbar,.bx-head .bx-row,.bx-listbar,.op-nowrap-print{display:none!important}.bx-main{margin:0!important}}</style>
<?php
bx_head('Offene Posten – Kreditoren',
        count($rows) . ' offene Eingangsrechnung(en) · offen gesamt: ' . $eur($sumOffen) . ' · überfällig: ' . $eur($sumUe),
        bx_btn('Drucken', 'javascript:window.print()', 'ghost') . ' '
        . bx_btn('Verbindlichkeiten als CSV', '?p=beleg_export&art=vop', 'ghost') . ' '
        . bx_btn('+ Eingangsrechnung', '?p=lief_rechnung_neu', 'primary'));
?>
<div class="bx-cards">
  <div class="bx-card"><div class="k">Offen gesamt</div><div class="v" style="<?= $sumOffen>0?'color:var(--warn)':'' ?>"><?= $eur($sumOffen) ?></div></div>
  <div class="bx-card"><div class="k">Davon überfällig</div><div class="v" style="<?= $sumUe>0?'color:var(--err)':'' ?>"><?= $eur($sumUe) ?></div></div>
  <div class="bx-card"><div class="k">Überfällige Rechnungen</div><div class="v"><?= $anzUe ?: '<span class="muted">0</span>' ?></div></div>
</div>
<form class="bx-listbar op-nowrap-print" method="get">
  <input type="hidden" name="p" value="op_kreditoren">
  <a class="btn btn-sm <?= $filter===''?'btn-primary':'btn-ghost' ?>" href="?p=op_kreditoren">Alle offenen</a>
  <a class="btn btn-sm <?= $filter==='ueberfaellig'?'btn-primary':'btn-ghost' ?>" href="?p=op_kreditoren&filter=ueberfaellig">Nur überfällig</a>
</form>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr>
    <th>Erfassungsnr.</th><th>Lieferant</th><th>Lief.-Rechnungsnr.</th><th>Datum</th><th>Fällig</th>
    <th class="bx-num">Tage überf.</th><th class="bx-num">Brutto</th><th class="bx-num">Bezahlt</th><th class="bx-num">Offen</th>
  </tr></thead>
  <tbody>
    <?php if (!$rows): ?><tr><td colspan="9" class="muted">Keine offenen Verbindlichkeiten.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r):
      $ue = $r['faellig'] && strtotime((string)$r['faellig']) < $heute;
      $tue = $ue ? (int) round(($heute - strtotime((string)$r['faellig'])) / 86400) : 0;
      $cur = strtoupper((string)($r['waehrung'] ?: 'EUR'));
    ?>
      <tr style="cursor:pointer" onclick="location.href='?p=lief_rechnung&id=<?= (int)$r['id'] ?>'">
        <td><strong><?= h((string)$r['nummer']) ?></strong><?= $cur!=='EUR' ? ' <span class="muted">· '.h(number_format((float)($r['fw_netto']??0),2,',','.').' '.$cur).'</span>' : '' ?></td>
        <td><?= h((string)($r['firma'] ?: '(ohne Lieferant)')) ?></td>
        <td><?= h((string)($r['lief_nummer'] ?: '')) ?: '<span class="muted">–</span>' ?></td>
        <td><?= $r['datum'] ? h(date('d.m.Y', strtotime((string)$r['datum']))) : '<span class="muted">–</span>' ?></td>
        <td><?= $r['faellig'] ? ('<span' . ($ue?' style="color:var(--err)"':'') . '>' . h(date('d.m.Y', strtotime((string)$r['faellig']))) . '</span>') : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= $tue>0 ? '<span style="color:var(--err)">'.$tue.'</span>' : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= $eur($r['brutto']) ?></td>
        <td class="bx-num"><?= $eur($r['bezahlt']) ?></td>
        <td class="bx-num"><strong><?= $eur($r['rest']) ?></strong></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
  <?php if ($rows): ?>
  <tfoot><tr><td colspan="8" class="bx-num">Offen gesamt</td><td class="bx-num"><strong><?= $eur($sumOffen) ?></strong></td></tr></tfoot>
  <?php endif; ?>
</table></div>
<?php
render_footer();
