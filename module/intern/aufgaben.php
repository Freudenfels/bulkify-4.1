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
<style>
  /* Kompakte Liste – bricht immer um, nie seitliches Scrollen. Klick auf die Karte öffnet die Detailseite. */
  .auf-list{ display:flex; flex-direction:column; gap:8px }
  .auf-item{ display:flex; gap:12px; align-items:flex-start; background:var(--panel); border:1px solid var(--line); border-left-width:4px; border-radius:10px; padding:10px 12px }
  .auf-item.prio1{ border-left-color:#c0392b }
  .auf-item.prio2{ border-left-color:#3b82f6 }
  .auf-item.prio3{ border-left-color:#9aa3ad }
  .auf-main{ flex:1; min-width:0; text-decoration:none; color:inherit; display:block }
  .auf-main:hover .auf-t{ text-decoration:underline }
  .auf-t{ font-weight:600; word-break:break-word; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden }
  .auf-d{ color:var(--muted); font-size:12px; margin-top:2px; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; overflow:hidden; word-break:break-word }
  .auf-m{ color:var(--muted); font-size:12px; margin-top:6px; display:flex; gap:8px; flex-wrap:wrap; align-items:center }
  .auf-act{ display:flex; gap:6px; flex:none; flex-wrap:wrap }
  #newAufDlg{ width:min(560px,calc(100% - 32px)); border:1px solid var(--line); border-radius:14px; padding:0 }
  #newAufDlg::backdrop{ background:rgba(0,0,0,.45) }
  @media (max-width:560px){ .auf-item{ flex-wrap:wrap } .auf-act{ width:100% } .auf-act form,.auf-act .btn{ flex:1 } }
</style>

<?php if (!$zeigeErledigt): ?>
<div class="bx-row" style="margin-bottom:12px">
  <button type="button" class="btn" style="background:var(--gruen,#2f6f4f);border-color:transparent;color:#fff" onclick="document.getElementById('newAufDlg').showModal()">+ Neue Aufgabe</button>
</div>
<dialog id="newAufDlg">
  <form method="post" style="margin:0;padding:18px 20px">
    <input type="hidden" name="aktion" value="neu">
    <div class="bx-row" style="justify-content:space-between;align-items:center;margin-bottom:6px">
      <h2 style="margin:0;font-size:17px">Neue Aufgabe</h2>
      <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('newAufDlg').close()">Schließen</button>
    </div>
    <div class="bx-grid">
      <div class="bx-field" style="grid-column:1/-1"><label>Aufgabe</label><input type="text" name="titel" required placeholder="Was ist zu tun?" autofocus></div>
      <div class="bx-field" style="grid-column:1/-1"><label>Details (optional)</label><textarea name="beschreibung" placeholder="Genauere Beschreibung, Hinweise …"></textarea></div>
      <div class="bx-field"><label>Priorität</label>
        <select name="prio"><?php foreach (prio_liste() as $k=>$lbl): ?><option value="<?= $k ?>" <?= $k===2?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?></select>
      </div>
      <div class="bx-field"><label>Zuweisen an</label>
        <select name="zugewiesen_an"><option value="">Team (alle)</option>
          <?php foreach ($mitarbeiter as $m): ?><option value="<?= (int)$m['id'] ?>"><?= h($m['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Fällig bis (optional)</label><input type="date" name="faellig"></div>
    </div>
    <div class="bx-row" style="margin-top:var(--sp-3)"><button class="btn" style="background:var(--gruen,#2f6f4f);border-color:transparent;color:#fff" type="submit">Aufgabe erstellen</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if (!$aufgaben): ?>
  <div class="bx-panel"><div class="muted"><?= $zeigeErledigt ? 'Keine erledigten Aufgaben.' : 'Keine offenen Aufgaben.' ?></div></div>
<?php else: ?>
<div class="auf-list">
  <?php foreach ($aufgaben as $a):
      $ueberfaellig = !$zeigeErledigt && $a['faellig'] && $a['faellig'] < gmdate('Y-m-d');
      $besch = trim(preg_replace('/\s+/', ' ', (string)$a['beschreibung'])); ?>
    <div class="auf-item prio<?= (int)$a['prio'] ?>">
      <a class="auf-main" href="?p=aufgabe&id=<?= (int)$a['id'] ?>">
        <div class="auf-t"><?= h($a['titel']) ?></div>
        <?php if ($besch !== ''): ?><div class="auf-d"><?= h($besch) ?></div><?php endif; ?>
        <div class="auf-m">
          <?= prio_badge((int)$a['prio']) ?>
          <span>· <?= $a['zuw_name'] ? h($a['zuw_name']) : 'Team' ?></span>
          <?php if ($a['faellig']): ?><span>· fällig <?= $ueberfaellig ? '<span class="bx-err">'.h(date('d.m.Y', strtotime($a['faellig']))).'</span>' : h(date('d.m.Y', strtotime($a['faellig']))) ?></span><?php endif; ?>
          <?php if ($zeigeErledigt && $a['erledigt_am']): ?><span>· erledigt <?= h(fmt_zeit($a['erledigt_am'],'d.m.Y')) ?></span><?php endif; ?>
        </div>
      </a>
      <div class="auf-act">
        <?php if ($zeigeErledigt): ?>
          <form method="post" style="margin:0"><input type="hidden" name="aktion" value="offen"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Wieder öffnen</button></form>
        <?php else: ?>
          <?php if ($a['zugewiesen_an'] === null && $uid): ?><form method="post" style="margin:0"><input type="hidden" name="aktion" value="uebernehmen"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Übernehmen</button></form><?php endif; ?>
          <form method="post" style="margin:0"><input type="hidden" name="aktion" value="erledigt"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-primary btn-sm" type="submit">Erledigt</button></form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php render_footer(); ?>
