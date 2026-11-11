<?php
// Reiter „Plan" der Produktionsliste (11.11.2026, Teil 2): Reihenfolge per Ziehen, je Zeile Datum, Linie, Mitarbeiter.
// Nicht machbare Aufträge grau mit Grund (produktion_machbar_schritte). Speichern ohne Neuladen. Die Werk-App zeigt genau
// diese Reihenfolge. Erwartet aus liste.php: $rows (Plan-Zeilen), $prodMap (produziert je PA).
$planRows = produktion_plan_sortieren(array_values($rows));
$mitarbeiter = all("SELECT id, name FROM benutzer WHERE aktiv=1 AND lieferant_id IS NULL ORDER BY name");
$linien = produktion_linien();
$nf = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
?>
<style>
  .pp-tab td{vertical-align:middle}
  .pp-tab tr.pp-grau td{opacity:.55}
  .pp-tab tr.pp-ziehen{outline:2px dashed var(--gruen)}
  .pp-griff{cursor:grab;color:var(--muted);font-size:18px;user-select:none;padding:0 6px}
  .pp-tab input[type=date],.pp-tab select{font-size:13px;padding:5px 6px;min-width:0}
  .pp-grund{font-size:12px;color:var(--muted);white-space:normal;max-width:320px}
</style>
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">
    <span class="muted" style="font-size:13px">Reihenfolge per Ziehen am Griff festlegen. Die Werk-App zeigt genau diese Reihenfolge – ist der oberste blockiert, rückt der nächste machbare nach vorne.</span>
    <span id="ppMsg" class="muted" style="font-size:13px"></span>
  </div>
  <?php if (!$planRows): ?><p class="muted">Nichts im Plan.</p><?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table pp-tab" id="ppTab"><thead><tr><th></th><th>Pos.</th><th>Produkt</th><th class="bx-num">Menge</th><th>Machbar</th><th>Datum</th><th>Linie</th><th>Mitarbeiter</th></tr></thead><tbody>
  <?php foreach ($planRows as $i => $r): $m = produktion_machbar_schritte((int)$r['id']); $pid = (int)$r['id']; ?>
    <tr data-pa="<?= $pid ?>" draggable="true" class="<?= $m['machbar'] ? '' : 'pp-grau' ?>">
      <td><span class="pp-griff" title="ziehen">⋮⋮</span></td>
      <td class="pp-pos"><?= $i + 1 ?></td>
      <td style="white-space:normal;min-width:220px"><a href="?p=produktionsauftrag&id=<?= $pid ?>"><?= h((string)($r['produkt_name'] ?: '–')) ?></a>
        <div class="muted" style="font-size:12px"><?= h((string)$r['nummer']) ?><?= $r['kunde_firma'] ? ' · ' . h(firma_kurz((string)$r['kunde_firma'])) : '' ?><?= ($r['produktionsart'] ?? '') === 'eigen' ? ' · Eigen' : ' · Fremd' ?></div></td>
      <td class="bx-num"><?= h($nf($r['menge'])) ?><?php $g = (float)($prodMap[$pid] ?? 0); if ($g > 0): ?><div class="muted" style="font-size:12px">produziert <?= h($nf($g)) ?></div><?php endif; ?></td>
      <td><?= match ($m['stufe']) { 'startklar' => bx_badge('startklar', 'ok'), 'bis_etikett' => bx_badge('bis vor Etikettieren', 'warn'), 'gesperrt' => bx_badge('nicht machbar'), default => bx_badge($m['stufe']) } ?>
        <?php if ($m['grund'] !== ''): ?><div class="pp-grund"><?= h((string)$m['grund']) ?></div><?php endif; ?></td>
      <td><input type="date" class="pp-f" data-f="datum" value="<?= h((string)($r['geplant_am'] ?? '')) ?>"></td>
      <td><select class="pp-f" data-f="linie" data-norscombo><option value="">–</option><?php foreach ($linien as $l): ?><option<?= (string)($r['linie'] ?? '') === $l ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></td>
      <td><select class="pp-f" data-f="mitarbeiter"><option value="">–</option><?php foreach ($mitarbeiter as $u): ?><option value="<?= (int)$u['id'] ?>"<?= (int)($r['mitarbeiter_id'] ?? 0) === (int)$u['id'] ? ' selected' : '' ?>><?= h((string)$u['name']) ?></option><?php endforeach; ?></select></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<script>
(function(){
  var tb=document.querySelector('#ppTab tbody'); if(!tb) return; var msg=document.getElementById('ppMsg'), zieh=null;
  function speichern(felder){
    var fd=new FormData(); fd.append('aktion','plan_speichern');
    tb.querySelectorAll('tr[data-pa]').forEach(function(tr,i){ fd.append('reihenfolge[]',tr.dataset.pa); tr.querySelector('.pp-pos').textContent=i+1; });
    if(felder){ var tr=felder; tr.querySelectorAll('.pp-f').forEach(function(f){ fd.append('felder['+tr.dataset.pa+']['+f.dataset.f+']', f.value); }); }
    msg.textContent='speichert …';
    fetch('?p=produktion&tab=plan',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){ msg.textContent=j.ok?'gespeichert':(j.msg||'Fehler'); }).catch(function(){ msg.textContent='Fehler beim Speichern'; });
  }
  tb.addEventListener('dragstart',function(e){ zieh=e.target.closest('tr'); if(zieh) zieh.classList.add('pp-ziehen'); e.dataTransfer.effectAllowed='move'; });
  tb.addEventListener('dragover',function(e){ e.preventDefault(); var tr=e.target.closest('tr'); if(!tr||!zieh||tr===zieh) return; var r=tr.getBoundingClientRect(); tb.insertBefore(zieh, (e.clientY-r.top)>r.height/2 ? tr.nextSibling : tr); });
  tb.addEventListener('dragend',function(){ if(zieh){ zieh.classList.remove('pp-ziehen'); zieh=null; speichern(null); } });
  tb.addEventListener('change',function(e){ if(e.target.classList.contains('pp-f')) speichern(e.target.closest('tr')); });
})();
</script>
