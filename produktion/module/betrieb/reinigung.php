<?php
// Reinigungspläne (Werk): wiederkehrende Reinigungen je Bereich/Maschine anlegen, als gereinigt
// markieren (mit Datum + Bediener), löschen. Eigene pr_-Tabelle (pr_reinigung).
$akteur = (string)(pr_benutzer()['name'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'neu' && trim((string)($_POST['titel'] ?? '')) !== '') {
        pr_reinigung_neu((string)$_POST['titel'], (string)($_POST['bereich'] ?? ''), (string)($_POST['intervall'] ?? ''), (string)($_POST['notiz'] ?? ''));
        flash('Reinigungsplan angelegt.');
    } elseif ($aktion === 'gereinigt') {
        pr_reinigung_gereinigt((int)($_POST['id'] ?? 0), $akteur);
        flash('Als gereinigt vermerkt.');
    } elseif ($aktion === 'loeschen') {
        pr_reinigung_loeschen((int)($_POST['id'] ?? 0));
        flash('Reinigungsplan entfernt.', 'warn');
    }
    weiter('?p=reinigung');
}
$plaene = pr_reinigung_alle();

kopf('Reinigungspläne', 'reinigung');
seitenkopf('Reinigungspläne', count($plaene) . ' ' . (count($plaene) === 1 ? 'Plan' : 'Pläne'));
?>
<div class="bx-panel">
  <h2 style="margin-top:0">Neuer Reinigungsplan</h2>
  <form method="post" class="bx-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="neu">
    <div class="bx-field" style="margin:0;min-width:220px;flex:1"><label>Was wird gereinigt</label><input type="text" name="titel" required placeholder="z. B. Kapselmaschine KM-3"></div>
    <div class="bx-field" style="margin:0;max-width:180px"><label>Bereich</label><input type="text" name="bereich" placeholder="z. B. Produktion A"></div>
    <div class="bx-field" style="margin:0;max-width:170px"><label>Intervall</label><input type="text" name="intervall" placeholder="z. B. je Charge"></div>
    <div class="bx-field" style="margin:0;min-width:200px;flex:1"><label>Hinweis (optional)</label><input type="text" name="notiz" placeholder="z. B. mit Alkohol wischen"></div>
    <button type="submit" class="btn btn-primary">Anlegen</button>
  </form>
</div>

<?php if (!$plaene): ?>
  <div class="bx-panel"><div class="muted">Noch keine Reinigungspläne angelegt.</div></div>
<?php else: ?>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr><th>Was</th><th>Bereich</th><th>Intervall</th><th>Zuletzt gereinigt</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($plaene as $p): ?>
    <tr>
      <td><strong><?= h((string)$p['titel']) ?></strong><?php if (!empty($p['notiz'])): ?><br><span class="muted" style="font-size:12px"><?= h((string)$p['notiz']) ?></span><?php endif; ?></td>
      <td><?= h((string)($p['bereich'] ?: '–')) ?></td>
      <td><?= h((string)($p['intervall'] ?: '–')) ?></td>
      <td><?= $p['letzte_reinigung'] ? h(date('d.m.Y', strtotime((string)$p['letzte_reinigung']))) . (!empty($p['letzte_von']) ? ' · ' . h((string)$p['letzte_von']) : '') : '<span class="badge badge-warn">noch nie</span>' ?></td>
      <td class="bx-num">
        <form method="post" style="margin:0;display:inline"><input type="hidden" name="aktion" value="gereinigt"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button type="submit" class="btn btn-primary btn-sm">Gereinigt</button></form>
        <form method="post" style="margin:0;display:inline" onsubmit="return confirm('Diesen Reinigungsplan entfernen?');"><input type="hidden" name="aktion" value="loeschen"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button type="submit" class="btn btn-ghost btn-sm">Entfernen</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php fuss();
