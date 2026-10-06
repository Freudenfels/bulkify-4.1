<?php
// Offene Posten Debitoren (Zeilenebene je Rechnung). Filter: alle / nur überfällig, optional je Kunde.
// Druckbar + CSV (?p=beleg_export&art=op). Von hier aus direkt in den Mahnlauf. Route: op_debitoren.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/buchhaltung.php';

$filter  = ($_GET['filter'] ?? '') === 'ueberfaellig' ? 'ueberfaellig' : '';
$kundeId = (int)($_GET['kunde'] ?? 0);
$rows = bh_op_rechnungen($filter, $kundeId);

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$sumOffen = 0.0; $sumUe = 0.0; $anzUe = 0;
foreach ($rows as $r) { $sumOffen += (float)$r['rest']; if ((int)$r['tage_ueberfaellig'] > 0) { $sumUe += (float)$r['rest']; $anzUe++; } }
$mahnLbl = [0=>'–', 1=>'Zahlungserinnerung', 2=>'1. Mahnung', 3=>'2. Mahnung'];
$kundeName = $kundeId ? (string) scalar("SELECT firma FROM kunden WHERE id=?", [$kundeId]) : '';

render_header('op_debitoren', 'Offene Posten (Debitoren)');
?>
<style>@media print{.bx-side,.bx-sidegriff,.bx-sideauf,.bx-mobilbar,.bx-head .bx-row,.bx-listbar,.op-nowrap-print{display:none!important}.bx-main{margin:0!important}}</style>
<?php
bx_head('Offene Posten – Debitoren' . ($kundeName ? ' · ' . h($kundeName) : ''),
        count($rows) . ' offene Rechnung(en) · offen gesamt: ' . $eur($sumOffen) . ' · überfällig: ' . $eur($sumUe),
        bx_btn('Drucken', 'javascript:window.print()', 'ghost') . ' '
        . bx_btn('OP-Liste als CSV', '?p=beleg_export&art=op', 'ghost') . ' '
        . bx_btn('Mahnlauf', '?p=mahnlauf', 'primary'));
?>
<div class="bx-cards">
  <div class="bx-card"><div class="k">Offen gesamt</div><div class="v" style="<?= $sumOffen>0?'color:var(--warn)':'' ?>"><?= $eur($sumOffen) ?></div></div>
  <div class="bx-card"><div class="k">Davon überfällig</div><div class="v" style="<?= $sumUe>0?'color:var(--err)':'' ?>"><?= $eur($sumUe) ?></div></div>
  <div class="bx-card"><div class="k">Überfällige Rechnungen</div><div class="v"><?= $anzUe ?: '<span class="muted">0</span>' ?></div></div>
</div>
<form class="bx-listbar op-nowrap-print" method="get">
  <input type="hidden" name="p" value="op_debitoren">
  <?php if ($kundeId): ?><input type="hidden" name="kunde" value="<?= $kundeId ?>"><?php endif; ?>
  <a class="btn btn-sm <?= $filter===''?'btn-primary':'btn-ghost' ?>" href="?p=op_debitoren<?= $kundeId?'&kunde='.$kundeId:'' ?>">Alle offenen</a>
  <a class="btn btn-sm <?= $filter==='ueberfaellig'?'btn-primary':'btn-ghost' ?>" href="?p=op_debitoren&filter=ueberfaellig<?= $kundeId?'&kunde='.$kundeId:'' ?>">Nur überfällig</a>
  <?php if ($kundeId): ?><a class="btn btn-ghost btn-sm" href="?p=op_debitoren<?= $filter?'&filter='.$filter:'' ?>">Kundenfilter entfernen</a><?php endif; ?>
</form>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr>
    <th>Nummer</th><th>Kunde</th><th>Datum</th><th>Fällig</th><th class="bx-num">Tage überf.</th>
    <th class="bx-num">Brutto</th><th class="bx-num">Bezahlt</th><th class="bx-num">Offen</th><th>Mahnstufe</th>
  </tr></thead>
  <tbody>
    <?php if (!$rows): ?><tr><td colspan="9" class="muted">Keine offenen Posten.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $tue = (int)$r['tage_ueberfaellig']; ?>
      <tr style="cursor:pointer" onclick="location.href='?p=rechnung&id=<?= (int)$r['id'] ?>'">
        <td><strong><?= h((string)$r['nummer']) ?></strong><?= ($r['kategorie'] ?? '')==='dienstleistung' ? ' <span class="muted">· DL</span>' : '' ?></td>
        <td><?= kunde_link($r['kunde_id'] ?? null, $r['kunde_firma'] ?: '(ohne Kunde)') ?></td>
        <td><?= $r['datum'] ? h(date('d.m.Y', strtotime((string)$r['datum']))) : '<span class="muted">–</span>' ?></td>
        <td><?= $r['faellig'] ? ('<span' . ($tue>0?' style="color:var(--err)"':'') . '>' . h(date('d.m.Y', strtotime((string)$r['faellig']))) . '</span>') : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= $tue>0 ? '<span style="color:var(--err)">'.$tue.'</span>' : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= $eur($r['brutto']) ?></td>
        <td class="bx-num"><?= $eur($r['bezahlt']) ?></td>
        <td class="bx-num"><strong><?= $eur($r['rest']) ?></strong></td>
        <td><?= (int)$r['mahnstufe']>0 ? bx_badge($mahnLbl[(int)$r['mahnstufe']] ?? (string)$r['mahnstufe'], (int)$r['mahnstufe']>=2?'err':'warn') : '<span class="muted">–</span>' ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
  <?php if ($rows): ?>
  <tfoot><tr><td colspan="7" class="bx-num">Offen gesamt</td><td class="bx-num"><strong><?= $eur($sumOffen) ?></strong></td><td></td></tr></tfoot>
  <?php endif; ?>
</table></div>
<?php
render_footer();
