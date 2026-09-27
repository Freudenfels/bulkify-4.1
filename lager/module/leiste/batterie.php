<?php
// Batterie-Runde: Blinker, die (geschaetzt) eine neue Batterie brauchen, einzeln blinken lassen
// (grün mit Ton, damit man sie leicht findet) und nach dem Wechsel den Zaehler zuruecksetzen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'neu') {
    leiste_batterie_neu((int)($_POST['leiste_id'] ?? 0));
    flash('Batterie als neu vermerkt, Zähler zurückgesetzt.');
    weiter('?p=batterie');
}

$liste = leiste_batterie_liste();

kopf('Batterie prüfen', 'leisten');
seitenkopf('Batterie prüfen', count($liste) . ' Blinker sollten (geschätzt) eine neue Batterie bekommen');
flash_zeigen();

if (!$liste) { hinweis('Kein Blinker über der Schätzgrenze. Alles gut.'); fuss(); return; }
?>
<p class="muted" style="margin-top:-6px">
  Reihenfolge nach Nutzung. Auf <strong>Blinken</strong> tippen, der Blinker leuchtet grün und piept –
  hingehen, Batterie tauschen, dann <strong>Batterie neu</strong>. Das ist eine Schätzung anhand der
  Leuchtzeit, kein echter Akkustand.
</p>
<div class="bx-tablewrap" style="margin-bottom:var(--sp-6)">
  <table class="bx-table">
    <thead><tr><th>Blinker</th><th>Hängt an</th><th>Auslösungen</th><th>Leuchtzeit</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($liste as $l): $c = $l['charge_id'] ? erp_charge((int)$l['charge_id']) : null; ?>
      <tr>
        <td class="lg-code"><strong><?= h((string)$l['code']) ?></strong></td>
        <td><?= $c ? h(charge_text($c)) : '<span class="muted">frei</span>' ?></td>
        <td><?= (int)$l['ausloesungen'] ?>×</td>
        <td class="muted"><?= (int)round((int)$l['verbrauch_sek'] / 60) ?> min</td>
        <td style="text-align:right;white-space:nowrap">
          <button type="button" class="btn btn-primary btn-sm" data-klingeln="<?= (int)$l['id'] ?>" data-farbe="gruen" data-sek="60">Blinken</button>
          <button type="button" class="btn btn-ghost btn-sm" data-klingeln="<?= (int)$l['id'] ?>" data-aktion="aus">Aus</button>
          <form method="post" style="display:inline">
            <input type="hidden" name="aktion" value="neu"><input type="hidden" name="leiste_id" value="<?= (int)$l['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit">Batterie neu</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
fuss();
