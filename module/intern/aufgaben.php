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
        // 13.11.2026: nach „Erledigt“ zurück zur Liste der offenen Aufgaben mit kurzer Meldung.
        aufgabe_erledigen((int)$_POST['id'], $uid);
        header('Location: ?p=aufgaben&erledigt=1'); exit;
    } elseif ($aktion === 'uebernehmen' && $uid) {
        aufgabe_uebernehmen((int)$_POST['id'], $uid);
    }
    // „Wieder öffnen“ gibt es seit 13.11.2026 nicht mehr – die Aktion „offen“ bucht nichts.
    header('Location: ?p=aufgaben'); exit;
}

// Archiv (13.11.2026) statt „Erledigte anzeigen“: nur lesen, schlichte Tabelle, neueste oben, Suchfeld. Alter Link zeige=erledigt landet auch hier.
$zeigeArchiv = in_array($_GET['zeige'] ?? '', ['archiv', 'erledigt'], true);
if ($zeigeArchiv) {
    $such = trim((string)($_GET['q'] ?? ''));
    $w = "a.status='erledigt'"; $args = [];
    if ($such !== '') {
        $like = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $such) . '%';
        $w .= " AND (a.titel LIKE ? ESCAPE '=' OR a.beschreibung LIKE ? ESCAPE '=' OR x.name LIKE ? ESCAPE '=')"; array_push($args, $like, $like, $like);
    }
    $archiv = all("SELECT a.id, a.titel, a.ref_typ, a.ref_id, a.angelegt, a.erledigt_am, x.name AS erledigt_name
                   FROM aufgabe a LEFT JOIN benutzer x ON x.id=a.erledigt_von
                   WHERE $w ORDER BY COALESCE(a.erledigt_am, a.angelegt) DESC, a.id DESC LIMIT 500", $args);
    render_header('aufgaben', 'Aufgaben – Archiv');
    bx_head('Aufgaben – Archiv', 'Erledigte Aufgaben, nur zum Nachlesen', bx_btn('Offene Aufgaben', '?p=aufgaben', 'ghost'));
    ?>
<form method="get" class="bx-listbar" style="margin-bottom:12px">
  <input type="hidden" name="p" value="aufgaben"><input type="hidden" name="zeige" value="archiv">
  <input type="search" name="q" value="<?= h($such) ?>" placeholder="Suchen (Titel, Text, erledigt von)" style="flex:1;min-width:220px;max-width:420px">
  <button class="btn btn-ghost" type="submit">Suchen</button>
</form>
<div class="bx-panel">
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Titel</th><th>Bezug</th><th>Erstellt am</th><th>Erledigt am</th><th>Erledigt von</th></tr></thead>
    <tbody>
    <?php if (!$archiv): ?><tr><td colspan="5" class="muted"><?= $such !== '' ? 'Nichts gefunden.' : 'Noch keine erledigten Aufgaben.' ?></td></tr><?php endif; ?>
    <?php foreach ($archiv as $a): $bz = aufgabe_bezug((string)$a['ref_typ'], (int)$a['ref_id']); ?>
      <tr>
        <td><a href="?p=aufgabe&amp;id=<?= (int)$a['id'] ?>&amp;von=archiv"><?= h((string)$a['titel']) ?></a></td>
        <td><?= $bz ? '<a href="' . h($bz['href']) . '">' . h($bz['text']) . '</a>' : '<span class="muted">–</span>' ?></td>
        <td style="white-space:nowrap"><?= $a['angelegt'] ? h(fmt_zeit($a['angelegt'], 'd.m.Y')) : '–' ?></td>
        <td style="white-space:nowrap"><?= $a['erledigt_am'] ? h(fmt_zeit($a['erledigt_am'], 'd.m.Y H:i')) : '–' ?></td>
        <td><?= $a['erledigt_name'] ? h((string)$a['erledigt_name']) : '<span class="muted">–</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($archiv) >= 500): ?><div class="muted" style="font-size:12px;margin-top:8px">Es werden die neuesten 500 gezeigt – für ältere bitte suchen.</div><?php endif; ?>
</div>
<?php
    render_footer(); exit;
}

// Normale Liste: nur offene Aufgaben.
$aufgaben = all("SELECT a.*, u.name AS zuw_name, e.name AS ersteller_name
                 FROM aufgabe a
                 LEFT JOIN benutzer u ON u.id=a.zugewiesen_an
                 LEFT JOIN benutzer e ON e.id=a.erstellt_von
                 WHERE a.status='offen'
                 ORDER BY a.prio ASC, a.angelegt ASC, a.id ASC");   // 13.11.2026: dringend, normal, später – darin nach Alter (älteste zuerst)
$offenGesamt = count($aufgaben);
$mitarbeiter = all("SELECT id, name FROM benutzer WHERE aktiv=1 AND lieferant_id IS NULL ORDER BY name");

render_header('aufgaben', 'Aufgaben');
bx_head('Aufgaben', $offenGesamt . ' offene Aufgaben', bx_btn('Archiv', '?p=aufgaben&zeige=archiv', 'ghost'));
if (isset($_GET['erledigt'])) echo '<div id="bxToast" class="badge-ok" style="position:fixed;top:16px;right:16px;z-index:9999;padding:10px 16px;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.18);font-weight:600">Aufgabe erledigt.</div>'
                                . '<script>setTimeout(function(){var t=document.getElementById("bxToast");if(t)t.remove();},2500);</script>';
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

<div class="bx-row" style="margin-bottom:12px">
  <button type="button" class="btn" style="background:var(--gruen,#2f6f4f);border-color:transparent;color:#fff" onclick="document.getElementById('newAufDlg').showModal()">+ Neue Aufgabe</button>
</div>
<dialog id="newAufDlg" class="bx-dialog" style="max-width:560px">
  <form method="post" style="margin:0">
    <input type="hidden" name="aktion" value="neu">
    <div class="bx-row" style="justify-content:space-between;align-items:center;margin-bottom:6px">
      <h2 style="margin:0;font-size:17px">Neue Aufgabe</h2>
      <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('newAufDlg').close()">Schließen</button>
    </div>
    <div class="bx-grid">
      <div class="bx-field" style="grid-column:1/-1"><label>Aufgabe</label><input type="text" name="titel" required placeholder="Was ist zu tun?" autofocus></div>
      <div class="bx-field" style="grid-column:1/-1"><label>Details (optional)</label><textarea name="beschreibung" placeholder="Genauere Beschreibung, Hinweise …"></textarea></div>
      <div class="bx-field"><label>Dringlichkeit</label>
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

<?php if (!$aufgaben): ?>
  <div class="bx-panel"><div class="muted">Keine offenen Aufgaben.</div></div>
<?php else: ?>
<div class="auf-list">
  <?php foreach ($aufgaben as $a):
      $ueberfaellig = $a['faellig'] && $a['faellig'] < gmdate('Y-m-d');
      $besch = trim(preg_replace('/\s+/', ' ', (string)$a['beschreibung'])); ?>
    <?php // Fastaction-Aufgaben oeffnen direkt die volle Notiz (alle Infos + Aktionen); sonst die Aufgaben-Detailseite.
      $ziel = (($a['ref_typ'] ?? '') === 'fastaction' && (int)($a['ref_id'] ?? 0) > 0)
            ? '?p=fastaction&notiz=' . (int)$a['ref_id'] : '?p=aufgabe&id=' . (int)$a['id'];
      $zielErl = aufgabe_ziel($a);   // 13.11.2026: direkt dorthin, wo man die Aufgabe erledigt ?>
    <div class="auf-item prio<?= (int)$a['prio'] ?>">
      <a class="auf-main" href="<?= $ziel ?>">
        <div class="auf-t"><?= h($a['titel']) ?></div>
        <?php if ($besch !== ''): ?><div class="auf-d"><?= h($besch) ?></div><?php endif; ?>
        <div class="auf-m">
          <?= prio_badge((int)$a['prio']) ?>
          <span>· <?= $a['zuw_name'] ? h($a['zuw_name']) : 'Team' ?></span>
          <?php if ($a['faellig']): ?><span>· fällig <?= $ueberfaellig ? '<span class="bx-err">'.h(date('d.m.Y', strtotime($a['faellig']))).'</span>' : h(date('d.m.Y', strtotime($a['faellig']))) ?></span><?php endif; ?>
        </div>
      </a>
      <div class="auf-act">
        <?php if ($zielErl !== $ziel && strpos($zielErl, '?p=aufgabe&') !== 0): ?><a class="btn btn-ghost btn-sm" href="<?= h($zielErl) ?>">Öffnen</a><?php endif; ?>
        <?php if ($a['zugewiesen_an'] === null && $uid): ?><form method="post" style="margin:0"><input type="hidden" name="aktion" value="uebernehmen"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Übernehmen</button></form><?php endif; ?>
        <form method="post" style="margin:0"><input type="hidden" name="aktion" value="erledigt"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-primary btn-sm" type="submit">Erledigt</button></form>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php render_footer(); ?>
