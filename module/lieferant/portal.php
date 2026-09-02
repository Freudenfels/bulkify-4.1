<?php
// Lieferantenportal – Übersicht. Route: ?p=lieferant_portal
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$lid = aktueller_lieferant_id();
$lf  = aktueller_lieferant();

$offen = all("SELECT * FROM bestellung WHERE lieferant_id=? AND status <> 'geliefert' ORDER BY angelegt DESC", [$lid]);
$unbestaetigt = array_values(array_filter($offen, fn($b) => (int)$b['bestaetigt'] !== 1));
$anfragenOffen = (int) scalar("SELECT COUNT(*) FROM lieferant_anfrage WHERE lieferant_id=? AND status='offen'", [$lid]);

lp_head('bulkify – ' . lp_t('portal'));
lp_shell_start('lieferant_portal');
?>
<h1 style="margin-bottom:4px"><?= h(lp_t('willkommen')) ?>, <?= h($lf['ansprechpartner'] ?: $lf['firma']) ?></h1>
<p class="bx-sub"><?= h(lp_t('portal')) ?> · <?= h($lf['firma']) ?></p>

<div class="pt-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0;max-width:760px">
  <div class="bx-panel" style="margin:0"><div class="muted" style="font-size:12px"><?= h(lp_t('offene_best')) ?></div>
    <div style="font-size:22px;font-weight:600;margin-top:4px"><?= count($offen) ?></div></div>
  <div class="bx-panel" style="margin:0"><div class="muted" style="font-size:12px"><?= h(lp_t('unbestaetigt')) ?></div>
    <div style="font-size:22px;font-weight:600;margin-top:4px"><?= count($unbestaetigt) ?></div></div>
  <div class="bx-panel" style="margin:0"><div class="muted" style="font-size:12px"><?= h(lp_t('offene_anfragen')) ?></div>
    <div style="font-size:22px;font-weight:600;margin-top:4px"><?= $anfragenOffen ?></div></div>
</div>

<?php // Was jetzt dran ist: unbestätigte Bestellungen zuerst – dafür ist der Lieferant hier. ?>
<?php if ($unbestaetigt): ?>
<div class="bx-panel">
  <h2 style="margin-top:0"><?= h(lp_t('unbestaetigt')) ?></h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th><?= h(lp_t('nummer')) ?></th><th><?= h(lp_t('datum')) ?></th><th class="bx-num"><?= h(lp_t('positionen')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($unbestaetigt as $b): ?>
      <tr><td><?= h($b['nummer']) ?></td>
          <td><?= h(date('d.m.Y', strtotime((string)$b['angelegt']))) ?></td>
          <td class="bx-num"><?= (int) scalar("SELECT COUNT(*) FROM bestellung_position WHERE bestellung_id=?", [(int)$b['id']]) ?></td>
          <td class="bx-num"><a class="btn btn-primary btn-sm" href="?p=lieferant_bestellung&id=<?= (int)$b['id'] ?>"><?= h(lp_t('zur_bestellung')) ?></a></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="bx-panel">
  <h2 style="margin-top:0"><?= h(lp_t('bestellungen')) ?></h2>
  <?php if (!$offen): ?><div class="muted"><?= h(lp_t('keine_best')) ?></div><?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th><?= h(lp_t('nummer')) ?></th><th><?= h(lp_t('datum')) ?></th><th><?= h(lp_t('termin')) ?></th><th><?= h(lp_t('status')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($offen as $b):
        $st = (string)$b['station'];
        $lbl = $st === '' ? '–' : bestellung_stationen_fuer(lp_sprache())[$st]; ?>
      <tr><td><?= h($b['nummer']) ?></td>
          <td><?= h(date('d.m.Y', strtotime((string)$b['angelegt']))) ?></td>
          <td><?= $b['eta_geplant'] ? h(date('d.m.Y', strtotime((string)$b['eta_geplant']))) : '<span class="muted">–</span>' ?></td>
          <td><?= h($lbl) ?></td>
          <td class="bx-num"><a class="btn btn-ghost btn-sm" href="?p=lieferant_bestellung&id=<?= (int)$b['id'] ?>"><?= h(lp_t('zur_bestellung')) ?></a></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php lp_shell_ende(); lp_foot();
