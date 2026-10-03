<?php
// Bewegungen (Warenlager-Manager): die Historie – was kam rein, was ging raus.
$filter = (string)($_GET['typ'] ?? '');   // '' | 'ein' | 'aus'
$alle   = lg_bewegungen(300);
if ($filter === 'ein' || $filter === 'aus') $alle = array_values(array_filter($alle, fn($b) => $b['typ'] === $filter));

$ein = 0; $aus = 0;
foreach (lg_bewegungen(300) as $b) { if ($b['typ'] === 'ein') $ein++; else $aus++; }

kopf('Bewegungen', 'bewegungen');
seitenkopf('Bewegungen', 'Was kam rein, was ging raus.',
    '<a class="btn btn-ghost" href="?p=we">Einbuchen</a> <a class="btn btn-ghost" href="?p=ausgang">Warenausgang</a>');
flash_zeigen();
?>
<div class="lg-reiter">
  <a href="?p=bewegungen" class="<?= $filter === '' ? 'an' : '' ?>">Alle <span class="lg-reiter-z"><?= $ein + $aus ?></span></a>
  <a href="?p=bewegungen&typ=ein" class="<?= $filter === 'ein' ? 'an' : '' ?>">Eingang <span class="lg-reiter-z"><?= $ein ?></span></a>
  <a href="?p=bewegungen&typ=aus" class="<?= $filter === 'aus' ? 'an' : '' ?>">Ausgang <span class="lg-reiter-z"><?= $aus ?></span></a>
</div>

<?php if (!$alle): ?>
  <div class="bx-panel muted">Noch keine Bewegungen erfasst. Buche eine <a href="?p=we">Einbuchung</a> oder <a href="?p=ausgang">Warenausgang</a>.</div>
<?php else: ?>
<div class="bx-tablewrap" style="margin-bottom:var(--sp-6)">
  <table class="bx-table">
    <thead><tr><th>Zeit</th><th>Richtung</th><th>Artikel</th><th>Menge</th><th>Grund</th></tr></thead>
    <tbody>
    <?php foreach ($alle as $b): ?>
      <tr>
        <td class="muted"><?= h(fmt_zeit((string)$b['angelegt'], 'd.m.Y H:i')) ?></td>
        <td><?= $b['typ'] === 'ein' ? '<span class="badge badge-ok">Eingang</span>' : '<span class="badge badge-warn">Ausgang</span>' ?></td>
        <td><?php if (!empty($b['charge_id'])): ?><a class="lg-namelink" href="?p=charge&id=<?= (int)$b['charge_id'] ?>"><?= h((string)$b['item_name']) ?></a><?php else: ?><?= h((string)$b['item_name']) ?><?php endif; ?></td>
        <td><?= h(menge_txt($b['menge'])) ?> <?= h((string)$b['einheit']) ?></td>
        <td class="muted"><?= h((string)$b['grund']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
fuss();
