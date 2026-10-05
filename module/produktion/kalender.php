<?php
// Produktions-Kalender – planen, wann welcher Produktionsauftrag produziert wird.
// Drag & Drop (Auftrag auf einen Tag ziehen = geplant_am setzen, auf „nicht eingeplant" = entfernen),
// Tages-Kapazität (wie viele Aufträge pro Tag) + Mitarbeiter-Zuteilung. Schreibt nur geplant_am +
// mitarbeiter_id am Produktionsauftrag; die Produktion liest das.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    $paId = (int)($_POST['pa_id'] ?? 0);
    if ($aktion === 'plan' && $paId) {
        $d = trim($_POST['datum'] ?? '');
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
        q("UPDATE produktionsauftrag SET geplant_am=? WHERE id=?", [$d, $paId]);
    } elseif ($aktion === 'unplan' && $paId) {
        q("UPDATE produktionsauftrag SET geplant_am=NULL WHERE id=?", [$paId]);
    } elseif ($aktion === 'mitarbeiter' && $paId) {
        $mid = ($_POST['mitarbeiter_id'] ?? '') !== '' ? (int)$_POST['mitarbeiter_id'] : null;
        q("UPDATE produktionsauftrag SET mitarbeiter_id=? WHERE id=?", [$mid, $paId]);
    } elseif ($aktion === 'kapazitaet') {
        meta_set('prod_kap_tag', (string) max(1, min(99, (int)($_POST['kap'] ?? 3))));
    }
    header('Location: ?p=kalender' . (($_GET['monat'] ?? '') !== '' ? '&monat=' . urlencode($_GET['monat']) : '')); exit;
}

$monat = $_GET['monat'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $monat)) $monat = date('Y-m');
$first = $monat . '-01';
$ts = strtotime($first);
$tageImMonat   = (int) date('t', $ts);
$startWochentag = (int) date('N', $ts);   // 1=Mo .. 7=So
$prev = date('Y-m', strtotime($first . ' -1 month'));
$next = date('Y-m', strtotime($first . ' +1 month'));
$heute = date('Y-m-d');
$MON =['01'=>'Januar','02'=>'Februar','03'=>'März','04'=>'April','05'=>'Mai','06'=>'Juni','07'=>'Juli','08'=>'August','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Dezember'];
$titel = $MON[substr($monat,5,2)] . ' ' . substr($monat,0,4);
$kap = max(1, (int) meta_get('prod_kap_tag', '3'));   // Aufträge pro Tag (Kapazität)

// Mitarbeiter (aktive Benutzer) für die Zuteilung.
$mitarbeiter = all("SELECT id, name FROM benutzer WHERE aktiv=1 ORDER BY name");
$mSelect = function($pid, $sel) use ($mitarbeiter) {
    $s = '<form method="post" class="k-mitform" style="margin:0"><input type="hidden" name="aktion" value="mitarbeiter"><input type="hidden" name="pa_id" value="' . (int)$pid . '">';
    $s .= '<select name="mitarbeiter_id" onchange="this.form.submit()" style="width:100%;font-size:11px;padding:1px 2px"><option value="">– Mitarbeiter –</option>';
    foreach ($mitarbeiter as $m) $s .= '<option value="' . (int)$m['id'] . '"' . ((int)$sel === (int)$m['id'] ? ' selected' : '') . '>' . h($m['name']) . '</option>';
    return $s . '</select></form>';
};

$sel = "SELECT pa.*, COALESCE(NULLIF(a.produkt_bezeichnung,''), NULLIF(p.name,''), CONCAT(rz.name,' · Bulk')) AS produkt_name,
               k.firma AS kunde, b.name AS mitarbeiter_name
        FROM produktionsauftrag pa
        LEFT JOIN produkt p   ON p.id=pa.produkt_id
        LEFT JOIN rezeptur rz ON rz.id=pa.rezeptur_id
        LEFT JOIN auftrag a   ON a.id=pa.auftrag_id
        LEFT JOIN kunden k    ON k.id=pa.kunde_id
        LEFT JOIN benutzer b  ON b.id=pa.mitarbeiter_id";
$geplant = all($sel . " WHERE pa.geplant_am BETWEEN ? AND ? ORDER BY pa.geplant_am, pa.prio", [$first, date('Y-m-t', $ts)]);
$byDay = [];
foreach ($geplant as $g) { $byDay[(int)date('j', strtotime($g['geplant_am']))][] = $g; }

$ungeplant = all($sel . " WHERE pa.status IN ('offen','laufend') AND pa.geplant_am IS NULL ORDER BY pa.prio, pa.angelegt");

render_header('kalender', 'Produktions-Kalender');
bx_head('Produktions-Kalender', 'Aufträge per Drag & Drop auf einen Tag ziehen (= Termin setzen). Tage über Kapazität sind rot.');
?>
<style>
  .bx-cal-cell{min-height:92px}
  .k-chip{display:block;border:1px solid var(--line);border-radius:6px;padding:3px 5px;margin:3px 0;background:var(--panel-2);font-size:11px;cursor:grab;line-height:1.25}
  .k-chip[draggable=true]:active{cursor:grabbing}
  .k-chip .k-top{display:flex;gap:4px;align-items:center}
  .k-chip .k-nr{font-weight:600}
  .k-cap{float:right;font-size:11px;color:var(--muted)}
  .k-over{outline:2px solid #d64545;outline-offset:-2px}
  .k-drop{background:rgba(29,158,117,.12)!important}
  .k-dz{border:1px dashed var(--line);border-radius:8px;padding:10px;margin-top:8px;color:var(--muted);font-size:13px;text-align:center}
  .k-prio{display:inline-block;width:9px;height:9px;border-radius:50%;flex:0 0 auto}
</style>
<div class="bx-row" style="justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">
  <a class="btn btn-ghost btn-sm" href="?p=kalender&monat=<?= $prev ?>">&#8592; <?= h($MON[substr($prev,5,2)]) ?></a>
  <h2 style="margin:0"><?= h($titel) ?></h2>
  <div class="bx-row" style="gap:10px;align-items:center">
    <form method="post" class="bx-row" style="gap:4px;margin:0;align-items:center">
      <input type="hidden" name="aktion" value="kapazitaet">
      <label class="muted" style="font-size:12px">Kapazität/Tag</label>
      <input type="number" name="kap" min="1" max="99" value="<?= $kap ?>" style="width:60px" onchange="this.form.submit()">
    </form>
    <a class="btn btn-ghost btn-sm" href="?p=kalender&monat=<?= $next ?>"><?= h($MON[substr($next,5,2)]) ?> &#8594;</a>
  </div>
</div>

<div class="bx-panel">
  <div class="bx-cal-head">
    <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $wd): ?><div><?= $wd ?></div><?php endforeach; ?>
  </div>
  <div class="bx-cal">
    <?php for ($i = 1; $i < $startWochentag; $i++): ?><div class="bx-cal-cell bx-cal-empty"></div><?php endfor; ?>
    <?php for ($tag = 1; $tag <= $tageImMonat; $tag++): $datum = sprintf('%s-%02d', $monat, $tag); $istHeute = $datum === $heute;
          $anz = count($byDay[$tag] ?? []); $over = $anz > $kap; ?>
      <div class="bx-cal-cell k-daycell<?= $istHeute ? ' bx-cal-today' : '' ?><?= $over ? ' k-over' : '' ?>" data-datum="<?= $datum ?>">
        <div class="bx-cal-day"><?= $tag ?><?php if ($anz): ?><span class="k-cap" title="belegt / Kapazität"><?= $anz ?>/<?= $kap ?></span><?php endif; ?></div>
        <?php foreach ($byDay[$tag] ?? [] as $g): ?>
          <div class="k-chip" draggable="true" data-pa="<?= (int)$g['id'] ?>" title="<?= h(($g['produkt_name'] ?: '') . ($g['kunde'] ? ' · ' . $g['kunde'] : '')) ?>">
            <div class="k-top">
              <span class="k-prio" style="background:<?= (int)($g['prio'] ?? 2)===1?'#d64545':((int)($g['prio'] ?? 2)===3?'#9aa0a6':'#2b6cd4') ?>"></span>
              <a class="k-nr" href="?p=produktionsauftrag&id=<?= (int)$g['id'] ?>"><?= h($g['nummer'] ?: ('#'.$g['id'])) ?></a>
              <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($g['produkt_name'] ?: '–') ?></span>
            </div>
            <?= $mSelect((int)$g['id'], (int)($g['mitarbeiter_id'] ?? 0)) ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endfor; ?>
  </div>
  <div class="muted" style="font-size:12px;margin-top:8px">Ziehen: Auftrag auf einen Tag = Termin setzen · auf „Noch nicht eingeplant" = Termin entfernen. Mehrere Aufträge pro Tag = parallel; über der Kapazität wird der Tag rot.</div>
</div>

<div class="bx-panel k-unplanzone">
  <h2>Noch nicht eingeplant (<?= count($ungeplant) ?>)</h2>
  <div class="k-dz" id="kUnplanDrop">Auftrag hierher ziehen, um den Termin zu entfernen</div>
  <?php if ($ungeplant): ?>
  <div class="bx-tablewrap" style="margin-top:10px"><table class="bx-table">
    <thead><tr><th>Prio</th><th>Bereit</th><th>Auftrag</th><th>Produkt</th><th>Kunde</th><th style="width:170px">Mitarbeiter</th><th style="width:230px">Einplanen auf</th></tr></thead>
    <tbody>
      <?php foreach ($ungeplant as $g): $ber = produktion_bereitschaft((int)$g['id']); ?>
        <tr class="k-chip" draggable="true" data-pa="<?= (int)$g['id'] ?>" style="cursor:grab">
          <td><?= prio_badge((int)($g['prio'] ?? 2)) ?></td>
          <td><?= bereitschaft_badge($ber['status']) ?></td>
          <td><a href="?p=produktionsauftrag&id=<?= (int)$g['id'] ?>"><?= h($g['nummer'] ?: ('#'.$g['id'])) ?></a></td>
          <td><?= h($g['produkt_name'] ?: '–') ?></td>
          <td><?= $g['kunde'] ? h($g['kunde']) : '<span class="muted">–</span>' ?></td>
          <td><?= $mSelect((int)$g['id'], (int)($g['mitarbeiter_id'] ?? 0)) ?></td>
          <td>
            <form method="post" class="bx-row" style="gap:6px;margin:0">
              <input type="hidden" name="aktion" value="plan"><input type="hidden" name="pa_id" value="<?= (int)$g['id'] ?>">
              <input type="date" name="datum" required style="max-width:150px">
              <button class="btn btn-primary btn-sm" type="submit">Einplanen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<!-- verstecktes Formular für Drag&Drop (voller Reload, robust) -->
<form method="post" id="kDnd" style="display:none">
  <input type="hidden" name="aktion" id="kDndAktion" value="plan">
  <input type="hidden" name="pa_id" id="kDndPa" value="">
  <input type="hidden" name="datum" id="kDndDatum" value="">
</form>
<script>(function(){
  var dragPa=null;
  document.querySelectorAll('.k-chip[draggable=true]').forEach(function(ch){
    ch.addEventListener('dragstart', function(e){ dragPa=ch.getAttribute('data-pa'); e.dataTransfer.setData('text/plain', dragPa); e.dataTransfer.effectAllowed='move'; });
    // Links/Selects im Chip nicht als Drag starten
    ch.querySelectorAll('a,select,input,button').forEach(function(el){ el.addEventListener('mousedown', function(ev){ ev.stopPropagation(); }); });
  });
  function submitDnd(aktion, pa, datum){
    document.getElementById('kDndAktion').value=aktion;
    document.getElementById('kDndPa').value=pa;
    document.getElementById('kDndDatum').value=datum||'';
    document.getElementById('kDnd').submit();
  }
  document.querySelectorAll('.k-daycell').forEach(function(cell){
    cell.addEventListener('dragover', function(e){ e.preventDefault(); cell.classList.add('k-drop'); });
    cell.addEventListener('dragleave', function(){ cell.classList.remove('k-drop'); });
    cell.addEventListener('drop', function(e){ e.preventDefault(); var pa=e.dataTransfer.getData('text/plain')||dragPa; if(pa) submitDnd('plan', pa, cell.getAttribute('data-datum')); });
  });
  var uz=document.getElementById('kUnplanDrop');
  if(uz){
    uz.addEventListener('dragover', function(e){ e.preventDefault(); uz.classList.add('k-drop'); });
    uz.addEventListener('dragleave', function(){ uz.classList.remove('k-drop'); });
    uz.addEventListener('drop', function(e){ e.preventDefault(); var pa=e.dataTransfer.getData('text/plain')||dragPa; if(pa) submitDnd('unplan', pa, ''); });
  }
})();</script>
<?php render_footer(); ?>
