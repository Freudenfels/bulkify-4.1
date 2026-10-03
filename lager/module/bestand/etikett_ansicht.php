<?php
// Etikett-Ansicht als normale In-App-Seite (mit Zurück-Button!), damit man im PDF nicht gefangen ist.
// Zeigt das Etikett als Vorschau + Knöpfe: Zurück, Größe, Öffnen (neuer Tab), Direkt drucken.
$ids = '';
if (isset($_GET['ids'])) {
    $ids = implode(',', array_filter(array_map('intval', explode(',', (string)$_GET['ids'])), fn($x) => $x > 0));
} elseif (isset($_GET['id'])) {
    $ids = (string)(int)$_GET['id'];
}
if ($ids === '') { flash('Kein Etikett angegeben.', 'warn'); weiter('?p=bestand'); }
$zurueck = (string)($_GET['zurueck'] ?? '?p=bestand');
if (!preg_match('/^\?p=[a-z0-9_&=\-,]+$/i', $zurueck)) $zurueck = '?p=bestand';

kopf('Etikett', 'bestand');
seitenkopf('Etikett', 'Vorschau · drucken', '<a class="btn btn-ghost" href="' . h($zurueck) . '">← Zurück</a>');
flash_zeigen();
?>
<div class="bx-panel">
  <div class="bx-row" style="gap:var(--sp-2);flex-wrap:wrap;align-items:center;margin-bottom:var(--sp-3)">
    <button type="button" class="btn btn-ghost btn-sm" data-etk-fmt="klein">100×70</button>
    <button type="button" class="btn btn-ghost btn-sm" data-etk-fmt="gross">100×150</button>
    <button type="button" class="btn btn-primary btn-sm" id="etkDruck">Direkt drucken</button>
    <a class="btn btn-ghost btn-sm" id="etkOeffnen" href="?p=etikett&ids=<?= h($ids) ?>" target="_blank" rel="noopener">Öffnen (neuer Tab)</a>
    <span id="etkDruckInfo" class="muted"></span>
  </div>
  <embed id="etkEmbed" src="?p=etikett&ids=<?= h($ids) ?>" type="application/pdf" style="width:100%;height:60vh;min-height:360px;border:1px solid var(--line);border-radius:8px;background:#fff">
</div>
<script>
(function(){
  var emb=document.getElementById('etkEmbed'), auf=document.getElementById('etkOeffnen'),
      druck=document.getElementById('etkDruck'), dinfo=document.getElementById('etkDruckInfo'),
      base='?p=etikett&ids=<?= h($ids) ?>', ids='<?= h($ids) ?>', fmt='';
  document.querySelectorAll('[data-etk-fmt]').forEach(function(b){ b.addEventListener('click',function(){
    fmt=b.getAttribute('data-etk-fmt');
    emb.src = base+'&format='+fmt+'&merken=1'; auf.href = base+'&format='+fmt;
    document.querySelectorAll('[data-etk-fmt]').forEach(function(x){ x.classList.toggle('btn-primary', x===b); x.classList.toggle('btn-ghost', x!==b); });
  }); });
  druck.addEventListener('click',function(){
    dinfo.textContent='…';
    var fd=new FormData(); fd.append('ids',ids); if(fmt) fd.append('format',fmt);
    fetch('?p=druck_job',{method:'POST',body:fd}).then(function(r){return r.json();})
      .then(function(j){ dinfo.textContent=j.ok?(j.meldung||'An den Drucker geschickt.'):('Fehler: '+(j.fehler||'')); })
      .catch(function(){ dinfo.textContent='Serverfehler.'; });
  });
})();
</script>
<?php
fuss();
