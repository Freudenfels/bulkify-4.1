<?php
// Mülleimer: im Lager „gelöschte" (nur ausgeblendete) Chargen. 30 Tage wiederherstellbar.
// Die Dashboard-Charge bleibt immer erhalten – „löschen" heißt hier nur: im Lager nicht mehr zeigen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'restore') {
    lg_papierkorb_raus((int)($_POST['charge_id'] ?? 0));
    flash('Wiederhergestellt – die Charge ist wieder im Bestand.');
    weiter('?p=papierkorb');
}

$liste = lg_papierkorb_liste();

kopf('Mülleimer', 'papierkorb');
seitenkopf('Mülleimer', count($liste) . ' gelöschte Charge(n) · 30 Tage wiederherstellbar');
flash_zeigen();

if (!$liste) { hinweis('Der Mülleimer ist leer.'); fuss(); return; }
?>
<div class="bx-tablewrap" style="margin-bottom:var(--sp-6)">
  <table class="bx-table">
    <thead><tr><th>Artikel</th><th>Charge</th><th>Menge</th><th>Grund</th><th>Gelöscht</th><th></th></tr></thead>
    <tbody>
    <?php $jetzt = time(); foreach ($liste as $z):
      $alterTage = (int) floor(($jetzt - strtotime((string)$z['geloescht_am'] . ' UTC')) / 86400);
      $restaurierbar = $alterTage <= 30;
    ?>
      <tr>
        <td><?= h((string)$z['item_name']) ?: '<span class="muted">–</span>' ?></td>
        <td class="lg-code"><?= h((string)$z['charge_nr']) ?: '–' ?></td>
        <td><?= h((string)$z['menge']) ?: '–' ?></td>
        <td class="muted"><?= h((string)$z['grund']) ?: '–' ?></td>
        <td class="muted"><?= h(fmt_zeit((string)$z['geloescht_am'], 'd.m.Y')) ?> <?= $restaurierbar ? '' : '<span class="badge badge-warn">abgelaufen</span>' ?></td>
        <td style="text-align:right">
          <?php if ($restaurierbar): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="aktion" value="restore"><input type="hidden" name="charge_id" value="<?= (int)$z['charge_id'] ?>">
              <button class="btn btn-ghost btn-sm" type="submit">Wiederherstellen</button>
            </form>
          <?php else: ?><span class="muted" style="font-size:12px">über 30 Tage</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
fuss();
