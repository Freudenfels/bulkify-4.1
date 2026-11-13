/* Durchsuchbare Auswahl (Regel Nico: jede Auswahl mit mehr als 10 Einträgen hat ein Suchfeld). Eingebunden über
   bx_rscombo_script() (core/layout.php) und public/assets/bx-ui.js (Portale, Sub-Apps). Seit 13.11.2026 als eigene Datei. */
if(!window.bxRsComboInit)(function(){
// Regel (Nico): jedes Dropdown mit mehr als 10 Einträgen ist ein Suchfeld mit Texteingabe; lange Einträge laufen beim Darüberfahren durch.
// Automatisch für alle <select> (auch nachträglich eingefügte, z. B. „+ Position“), ausdrücklich per class="rscombo" auch bei weniger.
// Ausnahme: data-norscombo am Select oder an einem Eltern-Element, Mehrfachauswahl, size>1.
var MIN = 10;
function norm(s){return String(s).toLowerCase().replace(/ä/g,'ae').replace(/ö/g,'oe').replace(/ü/g,'ue').replace(/ß/g,'ss');}
function esc(s){return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
function passt(sel){
  if(!sel||sel.tagName!=='SELECT'||sel.multiple||(sel.size&&sel.size>1)||sel.dataset.rscombo)return false;
  if(sel.closest('[data-norscombo]'))return false;
  return sel.classList.contains('rscombo')||sel.options.length>MIN;
}
var alle=[];
function combo(sel){
  if(!passt(sel))return;sel.dataset.rscombo='1';
  // Optionen bei jedem Öffnen frisch aus dem Select lesen (Listen, die per JS neu befüllt werden, bleiben aktuell)
  function opts(){return Array.prototype.map.call(sel.options,function(o){return {v:o.value,t:o.textContent.trim(),d:o.disabled,s:o.getAttribute('data-such')||''};});}
  var wrap=document.createElement('div');wrap.className='rs-combo';
  var cs=sel.style;if(cs.minWidth)wrap.style.minWidth=cs.minWidth;if(cs.maxWidth)wrap.style.maxWidth=cs.maxWidth;if(cs.width)wrap.style.width=cs.width;
  if(sel.disabled)wrap.classList.add('rs-disabled');
  sel.style.display='none';sel.parentNode.insertBefore(wrap,sel);wrap.appendChild(sel);
  var box=document.createElement('input');box.type='text';box.className='rs-input';box.autocomplete='off';box.disabled=sel.disabled;
  var list=document.createElement('div');list.className='rs-list';list.hidden=true;wrap.appendChild(box);wrap.appendChild(list);
  var hl=-1,shown=[],last=null;
  function sync(){ // sichtbaren Text an den Select-Wert angleichen (auch wenn anderes Skript den Wert setzt)
    var c=sel.options[sel.selectedIndex];var t=c?c.textContent.trim():'';
    if(sel.value!==''){box.value=t;box.placeholder=sel.getAttribute('data-ph')||(window.bxTx?bxTx('suchen'):'Suchen…');}
    else{box.value='';box.placeholder=t&&!/^[–—-]/.test(t)?t:(sel.getAttribute('data-ph')||(window.bxTx?bxTx('suchen'):'Suchen…'));}
    box.title=t;last=sel.value;box.disabled=sel.disabled;
  }
  sync();
  function mq(o){var t=o.querySelector('.rs-opt-t');if(!t)return;
    o.addEventListener('mouseenter',function(){var ov=t.scrollWidth-(o.clientWidth-22);if(ov>2){var d=Math.min(6,Math.max(1.2,ov/70));t.style.transition='transform '+d+'s linear';t.style.transform='translateX(-'+ov+'px)';}});
    o.addEventListener('mouseleave',function(){t.style.transition='transform .25s ease';t.style.transform='';});}
  function render(q){
    q=norm((q||'').trim());var all=opts();
    shown=all.filter(function(o){if(o.d||o.t==='')return false;if(o.v==='')return !q;return !q||norm(o.t+' '+o.s).indexOf(q)>=0;}).slice(0,100);
    if(!shown.length){list.innerHTML='<div class="rs-empty">Nichts gefunden.</div>';}
    else{list.innerHTML=shown.map(function(o,i){return '<div class="rs-opt'+(o.v===''?' rs-leer':'')+(o.v===sel.value?' rs-akt':'')+'" data-i="'+i+'" title="'+esc(o.t)+'"><span class="rs-opt-t">'+esc(o.t)+'</span></div>';}).join('');
      Array.prototype.forEach.call(list.querySelectorAll('.rs-opt'),mq);}
    hl=-1;list.hidden=false;
  }
  function paint(){Array.prototype.forEach.call(list.querySelectorAll('.rs-opt'),function(o){o.classList.toggle('hl',+o.dataset.i===hl);});var e=list.querySelector('.rs-opt.hl');if(e)e.scrollIntoView({block:'nearest'});}
  function ok(){box.setCustomValidity('');box.classList.remove('rs-invalid');}
  function pick(i){var o=shown[i];if(!o)return;sel.value=o.v;sync();list.hidden=true;ok();sel.dispatchEvent(new Event('change',{bubbles:true}));}
  sel.addEventListener('invalid',function(){box.classList.add('rs-invalid');if(window.__rsInv)return;window.__rsInv=1;setTimeout(function(){window.__rsInv=0;},80);
    box.setCustomValidity(window.bxTx?bxTx('liste'):'Bitte aus der Liste auswählen.');box.focus();box.reportValidity();});
  sel.addEventListener('change',function(){if(document.activeElement!==box)sync();});
  box.addEventListener('input',function(){box.setCustomValidity('');render(box.value);});
  box.addEventListener('focus',function(){box.select();render('');});
  box.addEventListener('keydown',function(e){if(list.hidden){if(e.key==='ArrowDown')render(box.value);return;}
    if(e.key==='ArrowDown'){e.preventDefault();hl=Math.min(hl+1,shown.length-1);paint();}
    else if(e.key==='ArrowUp'){e.preventDefault();hl=Math.max(hl-1,0);paint();}
    else if(e.key==='Enter'){if(hl>=0||shown.length===1){e.preventDefault();pick(hl>=0?hl:0);}}
    else if(e.key==='Escape'){list.hidden=true;sync();}});
  // Getippt, aber nichts angeklickt (Nico 12.11.2026: „Wellgreen“ getippt, dann gleich „Bestellen“ -> leerer Lieferant):
  // beim Verlassen des Felds einen eindeutigen Treffer selbst übernehmen – erst exakter Text, sonst genau ein Teiltreffer.
  // Läuft synchron im blur, also VOR dem Klick auf einen Absenden-Knopf.
  function autoPick(){var q=norm(box.value.trim());if(q==='')return;
    var c=sel.options[sel.selectedIndex];if(sel.value!==''&&c&&norm(c.textContent.trim())===q)return;
    var all=opts().filter(function(o){return !o.d&&o.v!=='';});
    var ex=all.filter(function(o){return norm(o.t)===q;});
    var hit=ex.length===1?ex:all.filter(function(o){return norm(o.t+' '+o.s).indexOf(q)>=0;});
    if(hit.length===1){sel.value=hit[0].v;last=null;ok();sel.dispatchEvent(new Event('change',{bubbles:true}));}}
  box.addEventListener('blur',function(){autoPick();setTimeout(function(){list.hidden=true;sync();},160);});
  list.addEventListener('mousedown',function(e){var o=e.target.closest('.rs-opt');if(o){e.preventDefault();pick(+o.dataset.i);}});
  document.addEventListener('click',function(e){if(!wrap.contains(e.target))list.hidden=true;});
  alle.push({sel:sel,box:box,sync:sync,last:function(){return last;}});
}
function init(root){root=root||document;
  if(root.tagName==='SELECT'){combo(root);return;}
  if(root.querySelectorAll)Array.prototype.forEach.call(root.querySelectorAll('select'),combo);}
// Werte, die andere Skripte setzen (sel.value=… ohne change), nachziehen
setInterval(function(){alle.forEach(function(a){if(document.activeElement!==a.box&&(a.sel.value!==a.last()||a.box.disabled!==a.sel.disabled))a.sync();});},700);
// Nachträglich eingefügte Selects (neue Zeilen, Pop-ups) automatisch aufwerten
function beobachten(){if(!window.MutationObserver||!document.body)return;var t=null;
  new MutationObserver(function(ms){var neu=false;ms.forEach(function(m){Array.prototype.forEach.call(m.addedNodes,function(n){if(n.nodeType===1&&(n.tagName==='SELECT'||(n.querySelector&&n.querySelector('select'))))neu=true;});});
    if(neu){clearTimeout(t);t=setTimeout(function(){init(document);},30);}}).observe(document.body,{childList:true,subtree:true});}
function start(){init();beobachten();}
if(document.readyState!=='loading')start();else document.addEventListener('DOMContentLoaded',start);
window.bxRsComboInit=init;})();
