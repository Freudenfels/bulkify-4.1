<?php
// Aufgaben – „Das musst du machen". Admin/Vorarbeiter legen an, Werk arbeitet ab. Zuweisung an Person ODER Team.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$u   = function_exists('current_user') ? current_user() : null;
$uid = $u ? (int)$u['id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    if ($aktion === 'neu' && trim($_POST['titel'] ?? '') !== '') {
        $zuw = ($_POST['zugewiesen_an'] ?? '') !== '' ? (int)$_POST['zugewiesen_an'] : null;
        aufgabe_neu(trim($_POST['titel']), trim($_POST['beschreibung'] ?? ''), (int)($_POST['prio'] ?? 2),
                    $zuw, trim($_POST['faellig'] ?? ''), $uid);
    } elseif ($aktion === 'erledigt') {
        aufgabe_erledigen((int)$_POST['id'], $uid);
    } elseif ($aktion === 'offen') {
        aufgabe_wieder_offen((int)$_POST['id']);
    } elseif ($aktion === 'uebernehmen' && $uid) {
        aufgabe_uebernehmen((int)$_POST['id'], $uid);
    }
    header('Location: ?p=aufgaben' . (($_GET['zeige'] ?? '') === 'erledigt' ? '&zeige=erledigt' : '')); exit;
}

$zeigeErledigt = ($_GET['zeige'] ?? '') === 'erledigt';
$status = $zeigeErledigt ? 'erledigt' : 'offen';
$aufgaben = all("SELECT a.*, u.name AS zuw_name, e.name AS ersteller_name, x.name AS erledigt_name
                 FROM aufgabe a
                 LEFT JOIN benutzer u ON u.id=a.zugewiesen_an
                 LEFT JOIN benutzer e ON e.id=a.erstellt_von
                 LEFT JOIN benutzer x ON x.id=a.erledigt_von
                 WHERE a.status=?
                 ORDER BY a.prio ASC, (a.faellig IS NULL), a.faellig ASC, a.angelegt DESC", [$status]);
$offenGesamt = (int) scalar("SELECT COUNT(*) FROM aufgabe WHERE status='offen'");
$mitarbeiter = all("SELECT id, name FROM benutzer WHERE aktiv=1 ORDER BY name");

render_header('aufgaben', 'Aufgaben');
bx_head('Aufgaben', $zeigeErledigt ? 'Erledigte Aufgaben' : $offenGesamt . ' offene Aufgaben',
        $zeigeErledigt ? bx_btn('Offene anzeigen', '?p=aufgaben', 'ghost') : bx_btn('Erledigte anzeigen', '?p=aufgaben&zeige=erledigt', 'ghost'));
?>
<?php if (!$zeigeErledigt): ?>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="neu">
  <div class="bx-panel">
    <h2 style="margin-top:0">Neue Aufgabe</h2>
    <div class="bx-grid">
      <div class="bx-field" style="grid-column:1/-1"><label>Aufgabe</label><input type="text" name="titel" required placeholder="Was ist zu tun?"></div>
      <div class="bx-field" style="grid-column:1/-1"><label>Details (optional)</label><textarea name="beschreibung" placeholder="Genauere Beschreibung, Hinweise …"></textarea></div>
      <div class="bx-field"><label>Priorität</label>
        <select name="prio"><?php foreach (prio_liste() as $k=>$lbl): ?><option value="<?= $k ?>" <?= $k===2?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?></select>
      </div>
      <div class="bx-field"><label>Zuweisen an</label>
        <select name="zugewiesen_an">
          <option value="">Team (alle)</option>
          <?php foreach ($mitarbeiter as $m): ?><option value="<?= (int)$m['id'] ?>"><?= h($m['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Fällig bis (optional)</label><input type="date" name="faellig"></div>
    </div>
  </div>
  <button class="btn btn-primary" type="submit">Aufgabe erstellen</button>
</form>
<?php endif; ?>

<div class="bx-panel">
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Prio</th><th>Aufgabe</th><th>Zugewiesen</th><th>Fällig</th><th><?= $zeigeErledigt ? 'Erledigt' : 'Erstellt von' ?></th><th></th></tr></thead>
    <tbody>
      <?php if (!$aufgaben): ?><tr><td colspan="6" class="muted"><?= $zeigeErledigt ? 'Keine erledigten Aufgaben.' : 'Keine offenen Aufgaben.' ?></td></tr><?php endif; ?>
      <?php foreach ($aufgaben as $a):
          $ueberfaellig = !$zeigeErledigt && $a['faellig'] && $a['faellig'] < gmdate('Y-m-d'); ?>
        <tr>
          <td><?= prio_badge((int)$a['prio']) ?></td>
          <td>
            <div><strong><?= h($a['titel']) ?></strong></div>
            <?php if ($a['beschreibung']): ?><div class="muted" style="font-size:12px;white-space:pre-line"><?= h($a['beschreibung']) ?></div><?php endif; ?>
          </td>
          <td><?= $a['zuw_name'] ? h($a['zuw_name']) : bx_badge('Team','info') ?></td>
          <td><?= $a['faellig'] ? '<span'.($ueberfaellig?' class="bx-err"':'').'>'.h(date('d.m.Y', strtotime($a['faellig']))).'</span>' : '<span class="muted">–</span>' ?></td>
          <td class="muted">
            <?php if ($zeigeErledigt): ?><?= h($a['erledigt_name'] ?: '–') ?><?= $a['erledigt_am'] ? ' · '.h(fmt_zeit($a['erledigt_am'],'d.m.Y')) : '' ?>
            <?php else: ?><?= h($a['ersteller_name'] ?: 'System') ?><?php endif; ?>
          </td>
          <td class="bx-num" style="white-space:nowrap">
            <?php if ($zeigeErledigt): ?>
              <form method="post" style="display:inline"><input type="hidden" name="aktion" value="offen"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Wieder öffnen</button></form>
            <?php else: ?>
              <?php if ($a['zugewiesen_an'] === null && $uid): ?>
                <form method="post" style="display:inline"><input type="hidden" name="aktion" value="uebernehmen"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Übernehmen</button></form>
              <?php endif; ?>
              <form method="post" style="display:inline"><input type="hidden" name="aktion" value="erledigt"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-primary btn-sm" type="submit">Erledigt</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer(); ?>
