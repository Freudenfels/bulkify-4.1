<?php
// Reinigungspläne (Werk): werden aus den Maschinen & Räumen (Einstellungen) erzeugt – je Betriebsmittel
// mit Reinigungsintervall eine Fälligkeit. „Gereinigt" setzt das Datum am Betriebsmittel.
$akteur = (string)(pr_benutzer()['name'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['aktion'] ?? '') === 'gereinigt') {
    pr_gereinigt_setzen((string)($_POST['typ'] ?? ''), (int)($_POST['id'] ?? 0), $akteur);
    flash('Als gereinigt vermerkt.');
    weiter('?p=reinigung');
}
$plaene = pr_reinigungsplaene();
$faellig = array_filter($plaene, fn($p) => $p['faellig']);

kopf('Reinigungspläne', 'reinigung');
seitenkopf('Reinigungspläne', count($plaene) . ' ' . (count($plaene) === 1 ? 'Plan' : 'Pläne')
    . ($faellig ? ' · ' . count($faellig) . ' fällig' : ''),
    '<a class="btn btn-ghost btn-sm" href="?p=einstellungen">Maschinen & Räume</a>');
?>
<?php if (!$plaene): ?>
  <div class="bx-panel"><div class="muted">Noch keine Reinigungspläne. Lege unter <a href="?p=einstellungen">Einstellungen</a> Maschinen/Räume mit einem Reinigungsintervall an.</div></div>
<?php else: ?>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr><th>Betriebsmittel</th><th>Bereich</th><th>Intervall</th><th>Zuletzt gereinigt</th><th>Fällig</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($plaene as $p): ?>
    <tr<?= $p['faellig'] ? ' style="background:rgba(230,196,192,.18)"' : '' ?>>
      <td><strong><?= h((string)$p['name']) ?></strong></td>
      <td class="muted"><?= h((string)$p['bereich']) ?></td>
      <td><?= h((string)$p['intervall_label']) ?></td>
      <td><?= $p['letzte_reinigung'] ? h(date('d.m.Y', strtotime($p['letzte_reinigung']))) . ($p['letzte_von'] ? ' · ' . h($p['letzte_von']) : '') : '<span class="muted">noch nie</span>' ?></td>
      <td><?php
        if ($p['intervall'] === 'je_charge') echo '<span class="badge badge-info">vor jeder Produktion</span>';
        elseif ($p['faellig']) echo '<span class="badge badge-warn">fällig' . ($p['naechste'] ? ' seit ' . h(date('d.m.Y', strtotime($p['naechste']))) : '') . '</span>';
        else echo '<span class="badge badge-ok">ok' . ($p['naechste'] ? ' bis ' . h(date('d.m.Y', strtotime($p['naechste']))) : '') . '</span>';
      ?></td>
      <td class="bx-num"><form method="post" style="margin:0"><input type="hidden" name="aktion" value="gereinigt"><input type="hidden" name="typ" value="<?= h((string)$p['typ']) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-primary btn-sm" type="submit">Gereinigt</button></form></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<p class="muted" style="font-size:12px;margin:10px 0 0">Die Pläne ergeben sich aus den Maschinen & Räumen mit Reinigungsintervall (<a href="?p=einstellungen">Einstellungen</a>). „Gereinigt" vermerkt Datum + Bediener.</p>
<?php endif; ?>
<?php fuss();
