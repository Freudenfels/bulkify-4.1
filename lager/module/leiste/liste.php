<?php
// Übersicht aller Blinker im grossen Lager: welche haengt an welcher Charge, welche sind frei.
$leisten = leiste_alle();
$frei = count(array_filter($leisten, fn($l) => !$l['charge_id']));

kopf('Blinker', 'leisten');
seitenkopf('Blinker', count($leisten) . ' im Umlauf, davon ' . $frei . ' frei');
flash_zeigen();

if (!$leisten) {
    hinweis('Noch kein Blinker aufgenommen. Ein Blinker wird beim ersten Binden unter „Finden" automatisch aufgenommen.');
    fuss(); return;
}
?>
<div class="bx-listbar">
  <input type="search" class="bx-search" placeholder="Suchen: Blinker, Rohstoff, Charge" data-filter="lg-leisten" autofocus>
</div>
<div class="bx-tablewrap" style="margin-bottom:var(--sp-6)">
  <table class="bx-table" id="lg-leisten">
    <thead><tr><th>Blinker</th><th>Status</th><th>Hängt an</th><th>Seit</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($leisten as $l): ?>
      <tr>
        <td class="lg-code"><?= h((string)$l['code']) ?></td>
        <td><?= $l['charge_id'] ? '<span class="badge badge-ok">belegt</span>' : '<span class="badge">frei</span>' ?></td>
        <td><?= $l['charge'] ? h(charge_text($l['charge'])) : '<span class="muted">–</span>' ?></td>
        <td class="muted"><?= $l['gebunden_am'] ? h(fmt_zeit((string)$l['gebunden_am'])) : '' ?></td>
        <td style="text-align:right;white-space:nowrap">
          <?php if ($l['charge_id']): ?>
            <button type="button" class="btn btn-ghost btn-sm" data-klingeln="<?= (int)$l['id'] ?>">Finden</button>
            <button type="button" class="btn btn-ghost btn-sm" data-klingeln="<?= (int)$l['id'] ?>" data-aktion="aus">Aus</button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
fuss();
