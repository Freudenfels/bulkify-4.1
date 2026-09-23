<?php // Kleiner Umschalt-Button: klappt alle Angebotskarten (details.pt-ang) auf einen Schlag ein/aus.
      // Der Balken blendet sich aus, wenn keine Karten da sind. In Angebote- und Anfragen-Ansicht eingebunden. ?>
<div id="ptCollapseBar" class="bx-row" style="justify-content:flex-end;margin:0 0 10px" hidden>
  <button type="button" id="ptCollapseAll" class="btn btn-ghost btn-sm">Alle einklappen</button>
</div>
<script>
(function(){
  var bar=document.getElementById('ptCollapseBar'), btn=document.getElementById('ptCollapseAll');
  if(!bar||!btn) return;
  function cards(){ return Array.prototype.slice.call(document.querySelectorAll('details.pt-ang')); }
  function sync(){ var c=cards(); if(!c.length){ bar.hidden=true; return; } bar.hidden=false;
    btn.textContent = c.some(function(d){return d.open;}) ? 'Alle einklappen' : 'Alle ausklappen'; }
  btn.addEventListener('click', function(){
    var c=cards(), anyOpen=c.some(function(d){return d.open;});
    c.forEach(function(d){ d.open = !anyOpen; }); sync();
  });
  // Label mitziehen, wenn einzelne Karten auf-/zugeklappt werden (toggle bubbelt nicht -> Capture).
  document.addEventListener('toggle', function(e){ if(e.target && e.target.matches && e.target.matches('details.pt-ang')) sync(); }, true);
  sync();
})();
</script>
