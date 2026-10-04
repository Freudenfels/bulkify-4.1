<?php
// Massen-Freigabe für die öffentliche Rohstoff-Datenbank (bulkify.pro). Route: ?p=rohstoff_website
// Hier entscheidet das Team, welche Rohstoffe öffentlich sichtbar sind (item.website_sichtbar).
// Es geht nichts automatisch online; der öffentliche Endpoint (public/rohstoffe_public.php) liefert nur
// die hier Freigegebenen – und nur sichere Felder. Siehe core/rohstoff_public.php.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/rohstoff_public.php';

$ret = '?p=rohstoff_website';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $akt = (string)($_POST['aktion'] ?? '');
    $f   = (string)($_POST['f'] ?? 'bereit');
    $suffix = '&f=' . urlencode($f) . (isset($_POST['q']) && $_POST['q'] !== '' ? '&q=' . urlencode((string)$_POST['q']) : '');

    // Ausgewählte IDs freigeben/sperren
    if ($akt === 'freigeben' || $akt === 'sperren') {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
        $n = 0;
        foreach ($ids as $iid) {
            if (!$iid) continue;
            if ($akt === 'freigeben') {
                q("UPDATE item SET website_sichtbar=1 WHERE id=? AND kategorie='rohstoff'", [$iid]);
                rohstoff_web_slug_sicherstellen($iid);
            } else {
                q("UPDATE item SET website_sichtbar=0 WHERE id=? AND kategorie='rohstoff'", [$iid]);
            }
            $n++;
        }
        $_SESSION['rw_flash'] = $n . ($akt === 'freigeben' ? ' Rohstoff(e) für die Website freigegeben.' : ' Rohstoff(e) von der Website entfernt.');
        header('Location: ' . $ret . $suffix); exit;
    }

    // Alle "bereit" (Name + Kennwert/Wirkstoff) auf einmal freigeben
    if ($akt === 'freigeben_bereit') {
        $rows = all("SELECT i.id FROM item i WHERE i.kategorie='rohstoff' AND COALESCE(i.gesperrt,0)=0
                     AND COALESCE(i.website_sichtbar,0)=0 AND TRIM(COALESCE(i.name,''))<>''
                     AND (EXISTS(SELECT 1 FROM item_kennwert k WHERE k.item_id=i.id)
                       OR EXISTS(SELECT 1 FROM item_wirkstoff w WHERE w.item_id=i.id))");
        $n = 0;
        foreach ($rows as $r) { $iid = (int)$r['id']; q("UPDATE item SET website_sichtbar=1 WHERE id=?", [$iid]); rohstoff_web_slug_sicherstellen($iid); $n++; }
        $_SESSION['rw_flash'] = $n . ' „bereite" Rohstoff(e) freigegeben.';
        header('Location: ' . $ret . $suffix); exit;
    }
}

$flash = $_SESSION['rw_flash'] ?? null; unset($_SESSION['rw_flash']);
$f = in_array($_GET['f'] ?? 'bereit', ['alle','frei','offen','bereit'], true) ? $_GET['f'] : 'bereit';
$q = trim((string)($_GET['q'] ?? ''));

// Basis + Filter
$where = "i.kategorie='rohstoff' AND COALESCE(i.gesperrt,0)=0";
$args  = [];
$bereitExpr = "(EXISTS(SELECT 1 FROM item_kennwert k WHERE k.item_id=i.id) OR EXISTS(SELECT 1 FROM item_wirkstoff w WHERE w.item_id=i.id))";
if     ($f === 'frei')   $where .= " AND COALESCE(i.website_sichtbar,0)=1";
elseif ($f === 'offen')  $where .= " AND COALESCE(i.website_sichtbar,0)=0";
elseif ($f === 'bereit') $where .= " AND COALESCE(i.website_sichtbar,0)=0 AND TRIM(COALESCE(i.name,''))<>'' AND $bereitExpr";
if ($q !== '') { $like = '%' . str_replace('\\', '', $q) . '%'; $where .= " AND (i.name LIKE ? OR i.artikelnummer LIKE ? OR i.name_lat LIKE ?)"; array_push($args, $like, $like, $like); }

$rows = all("SELECT i.id, i.artikelnummer, i.name, i.form, i.website_sichtbar,
                    (SELECT COUNT(*) FROM item_kennwert k WHERE k.item_id=i.id) AS n_kw,
                    (SELECT COUNT(*) FROM item_wirkstoff w WHERE w.item_id=i.id) AS n_ws
             FROM item i WHERE $where ORDER BY i.name LIMIT 1000", $args);

// Zähler für die Reiter
$cAlle   = (int) scalar("SELECT COUNT(*) FROM item i WHERE i.kategorie='rohstoff' AND COALESCE(i.gesperrt,0)=0");
$cFrei   = (int) scalar("SELECT COUNT(*) FROM item i WHERE i.kategorie='rohstoff' AND COALESCE(i.gesperrt,0)=0 AND COALESCE(i.website_sichtbar,0)=1");
$cBereit = (int) scalar("SELECT COUNT(*) FROM item i WHERE i.kategorie='rohstoff' AND COALESCE(i.gesperrt,0)=0 AND COALESCE(i.website_sichtbar,0)=0 AND TRIM(COALESCE(i.name,''))<>'' AND $bereitExpr");
$FORMLBL = ['pulver'=>'Pulver','granulat'=>'Granulat','fluessig'=>'Flüssig','oel'=>'Öl','paste'=>'Paste','kristallin'=>'Kristallin','kapselhuelle'=>'Kapselhülle'];

render_header('rohstoffe', 'Rohstoffe – Website-Freigabe');
bx_head('Rohstoffe für die Website freigeben', 'Öffentliche Rohstoff-Datenbank (bulkify.pro) – nur Freigegebene gehen online',
        bx_btn('Zu den Rohstoffen', '?p=rohstoffe', 'ghost'));
if ($flash) echo '<div class="bx-panel badge-ok" style="padding:10px 14px">' . h($flash) . '</div>';
?>
<div class="bx-panel" style="padding:10px 14px;color:var(--muted);font-size:13px">
  Freigegeben = sichtbar auf bulkify.pro (öffentlich, für Google). Es gehen nur sichere Felder online
  (Name, CAS, botanische Quelle, charakteristische Kennwerte, Wirkstoffe, Beschreibung) – nie Preise,
  Lieferanten, Bestand oder Originaldokumente. „Bereit" = hat Name und mindestens einen Kennwert/Wirkstoff.
</div>

<div class="bx-listbar" style="gap:12px;flex-wrap:wrap;align-items:center">
  <?php $tab = function($key,$lbl) use ($f,$q) { $on = $f===$key ? ' class="on"' : ''; $href='?p=rohstoff_website&f='.$key.($q!==''?'&q='.urlencode($q):''); return '<a'.$on.' href="'.$href.'" style="margin-right:12px">'.$lbl.'</a>'; }; ?>
  <div>
    <?= $tab('bereit','Bereit ('.$cBereit.')') ?>
    <?= $tab('frei','Freigegeben ('.$cFrei.')') ?>
    <?= $tab('offen','Nicht freigegeben') ?>
    <?= $tab('alle','Alle ('.$cAlle.')') ?>
  </div>
  <form method="get" style="margin-left:auto;display:flex;gap:6px">
    <input type="hidden" name="p" value="rohstoff_website"><input type="hidden" name="f" value="<?= h($f) ?>">
    <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Name / R-Nr. / lat.">
    <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
    <?php if ($q!==''): ?><a class="btn btn-ghost btn-sm" href="?p=rohstoff_website&f=<?= h($f) ?>">zurücksetzen</a><?php endif; ?>
  </form>
</div>

<?php if ($f !== 'frei' && $cBereit > 0): ?>
<div class="bx-panel" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
  <div class="muted" style="font-size:13px"><strong style="font-weight:600"><?= $cBereit ?></strong> Rohstoff(e) sind „bereit" (Name + Kennwerte/Wirkstoffe) und noch nicht online.</div>
  <form method="post" style="margin:0" onsubmit="return confirm('<?= $cBereit ?> Rohstoff(e) öffentlich auf bulkify.pro freigeben?');">
    <input type="hidden" name="aktion" value="freigeben_bereit"><input type="hidden" name="f" value="<?= h($f) ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
    <button class="btn btn-primary" type="submit" data-busy="gebe frei…">Alle <?= $cBereit ?> „bereiten" freigeben</button>
  </form>
</div>
<?php endif; ?>

<form method="post" id="rwform">
  <input type="hidden" name="f" value="<?= h($f) ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
  <div class="bx-panel">
    <?php if (!$rows): ?>
      <div class="muted">Keine Rohstoffe in dieser Ansicht.</div>
    <?php else: ?>
      <div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">
        <button class="btn btn-primary btn-sm" type="submit" name="aktion" value="freigeben" onclick="return rwHas()">Ausgewählte freigeben</button>
        <button class="btn btn-ghost btn-sm" type="submit" name="aktion" value="sperren" onclick="return rwHas()">Ausgewählte entfernen</button>
        <label class="muted" style="font-size:12px;display:flex;align-items:center;gap:6px;margin-left:6px"><input type="checkbox" id="rwall"> alle auf dieser Seite</label>
      </div>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th style="width:34px"></th><th>Nr.</th><th>Rohstoff</th><th>Form</th><th class="bx-num">Kennw.</th><th class="bx-num">Wirkst.</th><th>Website</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" class="rwcb"></td>
            <td style="white-space:nowrap"><?= h((string)($r['artikelnummer'] ?: ('R-'.$r['id']))) ?></td>
            <td style="max-width:360px;overflow-wrap:anywhere"><a href="?p=rohstoff&id=<?= (int)$r['id'] ?>" target="_blank"><?= h((string)$r['name']) ?></a></td>
            <td class="muted"><?= h($FORMLBL[(string)$r['form']] ?? (string)$r['form']) ?></td>
            <td class="bx-num"><?= (int)$r['n_kw'] ?></td>
            <td class="bx-num"><?= (int)$r['n_ws'] ?></td>
            <td><?= (int)$r['website_sichtbar'] === 1 ? bx_badge('online','ok') : '<span class="muted">–</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php if (count($rows) >= 1000): ?><p class="muted" style="font-size:12px;margin-top:8px">Nur die ersten 1000 – mit der Suche eingrenzen.</p><?php endif; ?>
    <?php endif; ?>
  </div>
</form>
<script>
  (function(){
    var all=document.getElementById('rwall');
    if(all) all.addEventListener('change',function(){ document.querySelectorAll('.rwcb').forEach(function(c){ c.checked=all.checked; }); });
  })();
  function rwHas(){ if(!document.querySelector('.rwcb:checked')){ alert('Bitte zuerst Rohstoffe auswählen.'); return false; } return true; }
</script>
<?php render_footer();
