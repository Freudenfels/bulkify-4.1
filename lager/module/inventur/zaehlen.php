<?php
// Zählen (11.11.2026) – Handy/Tablet, blind. Ablauf: Platz scannen -> jedes Gebinde scannen -> Menge erfassen (Stk, „voll,
// original verschlossen", wiegen mit Tara, Bulk-Sack -> Kapseln, „Gebinde leer") -> „Platz fertig". Ware ohne Etikett ->
// „Gefunden ohne Etikett" (Klärfall + Etikett drucken). Alles per fetch an ?p=inventur_api, nichts geht beim Unterbrechen verloren.
$inv = inv_get((int)($_GET['id'] ?? 0));
if (!$inv || !inv_darf_zaehlen($inv)) { flash('Diese Inventur ist nicht zum Zählen offen.', 'warn'); weiter('?p=inventur'); }
$id = (int)$inv['id'];
$offeneBereiche = (int)$inv['ganz'] ? [] : array_values(array_filter(inv_bereiche($id), fn($b) => $b['status'] !== 'fertig'));
kopf('Zählen', 'inventur');
seitenkopf('Zählen: ' . (string)$inv['name'], 'Blind zählen – du siehst keine Soll-Mengen. Erst den Platz scannen, dann jedes Gebinde.', '<a class="btn btn-ghost" href="?p=inventur_lauf&id=' . $id . '">Übersicht</a>');
flash_zeigen();
?>
<style>
  .iz{max-width:760px;display:flex;flex-direction:column;gap:14px}
  .iz-platz{font-size:22px;font-weight:600;padding:14px 16px;border-radius:12px;border:1px solid var(--line);background:var(--panel)}
  .iz-platz.an{border-color:var(--gruen);box-shadow:inset 0 0 0 1px var(--gruen)}
  .iz-scan{display:flex;gap:8px}
  .iz-scan input{flex:1;font-size:20px;min-height:56px;padding:10px 14px}
  .iz .btn{min-height:52px;font-size:17px;padding:0 18px}
  .iz-karte{border:1px solid var(--line);border-radius:12px;padding:16px;background:var(--panel);display:flex;flex-direction:column;gap:10px}
  .iz-art{font-size:20px;font-weight:600;line-height:1.3}
  .iz-ein{font-size:30px;font-weight:700;letter-spacing:.02em}
  .iz-zeile{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .iz-zeile input,.iz-zeile select{font-size:20px;min-height:52px;padding:8px 12px}
  .iz-zeile input{width:170px;text-align:right}
  .iz-msg{padding:12px 14px;border-radius:10px;font-size:16px}
  .iz-msg.ok{background:rgba(29,158,117,.12);color:var(--text)} .iz-msg.err{background:rgba(143,35,27,.1);color:#8f231b}
  .iz-liste div{padding:8px 0;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:10px}
  .iz-wahl button{display:block;width:100%;text-align:left;margin:6px 0}
  .iz-treffer div{padding:10px 12px;border-bottom:1px solid var(--line);cursor:pointer}
  .iz-treffer div:hover{background:var(--row-hover)}
  .iz-gross{font-size:18px} .muted.klein{font-size:13px}
</style>
<div class="iz" id="iz" data-inv="<?= $id ?>">
  <div class="iz-platz" id="izPlatz">Platz scannen</div>
  <?php if ($offeneBereiche): ?><div class="iz-zeile"><select id="izBereichWahl"><option value="">… oder Platz wählen</option><?php foreach ($offeneBereiche as $b): ?><option value="<?= h((string)$b['schluessel']) ?>"><?= h((string)$b['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="iz-scan"><input type="text" id="izCode" placeholder="Scannen oder Code eintippen" autocomplete="off" autofocus><button class="btn btn-primary" id="izOk" type="button">OK</button><button class="btn btn-ghost" id="izCam" type="button" hidden>Kamera</button></div>
  <video id="izVideo" playsinline style="width:100%;max-height:260px;display:none;border-radius:12px;background:#000"></video>
  <div id="izMsg"></div>
  <div id="izKarte"></div>
  <div class="iz-zeile"><button class="btn btn-ghost" id="izOhne" type="button" disabled>Gefunden ohne Etikett</button><button class="btn btn-primary" id="izFertig" type="button" disabled>Platz fertig</button></div>
  <div class="iz-karte"><div class="iz-zeile" style="justify-content:space-between"><b style="font-weight:600">Auf diesem Platz erfasst</b><span class="muted" id="izAnz"></span></div><div class="iz-liste" id="izListe"><span class="muted">Noch nichts.</span></div></div>
  <?= function_exists('inv_anleitung_html') ? inv_anleitung_html(true) : '' ?>
  <div class="iz-karte" id="izNz" hidden><b style="font-weight:600">Nachzählen (zweite Person)</b><div class="iz-liste" id="izNzListe"></div></div>
</div>
<script>
(function(){
  var INV=document.getElementById('iz').dataset.inv, bereich=0, platzName='', nurNz=false, ART=null, TARA=[];
  var $=function(i){return document.getElementById(i);}, code=$('izCode'), karte=$('izKarte'), msgEl=$('izMsg');
  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
  function zahl(v){ v=String(v||'').trim(); if(v.indexOf(',')>=0) v=v.replace(/\./g,'').replace(',','.'); else if(/^\d{1,3}(\.\d{3})+$/.test(v)) v=v.replace(/\./g,''); return parseFloat(v)||0; }
  function fmt(n,d){ return Number(n).toLocaleString('de-DE',{maximumFractionDigits:d==null?3:d}); }
  // Gewichte (13.11.2026): wie gewicht_lesen() – Punkt und Komma = Dezimal, beide da = das letzte; Vorbelegung ohne Tausenderpunkt.
  function gew(v){ v=String(v||'').replace(/\s/g,''); var p=v.lastIndexOf('.'), k=v.lastIndexOf(',');
    if(p>=0&&k>=0){ v= p>k ? v.replace(/,/g,'') : v.replace(/\./g,'').replace(',','.'); }
    else if(p>=0||k>=0){ var z=p>=0?'.':','; v = v.split(z).length>2 ? v.split(z).join('') : v.replace(',','.'); }
    return parseFloat(v)||0; }
  function fmtG(n,d){ return Number(n).toLocaleString('de-DE',{maximumFractionDigits:d==null?3:d,useGrouping:false}); }
  function msg(t,ok){ msgEl.innerHTML=t?'<div class="iz-msg '+(ok?'ok':'err')+'">'+esc(t)+'</div>':''; }
  function api(a,data,cb){ var fd=data instanceof FormData?data:new FormData(); if(!(data instanceof FormData)) for(var k in (data||{})) fd.append(k,data[k]);
    fetch('?p=inventur_api&id='+INV+'&a='+a,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(cb).catch(function(){ msg('Keine Verbindung – nichts verloren, bitte nochmal.',false); }); }
  function fokus(){ setTimeout(function(){ code.focus(); },50); }
  function platzSetzen(r){ if(!r.ok){ msg(r.msg,false); return; } bereich=r.bereich_id; platzName=r.name; nurNz=!!r.nachzaehlen;
    try{ localStorage.setItem('inv-'+INV+'-bereich', JSON.stringify({b:bereich,n:platzName,z:nurNz})); }catch(e){}
    $('izPlatz').textContent=r.msg; $('izPlatz').classList.add('an'); $('izOhne').disabled=nurNz; $('izFertig').disabled=nurNz; karte.innerHTML=''; msg('',true); scans(); }
  function scans(){ if(!bereich) return; api('scans',{bereich:bereich},function(j){ var l=j.scans||[]; $('izAnz').textContent=l.length?l.length+' Scan(s)':'';
      $('izListe').innerHTML=l.length?l.map(function(s){return '<div><span>'+esc(s.artikel)+(s.klaerfall?' <span class="muted klein">'+esc(s.klaerfall)+'</span>':'')+(s.nachzaehlung?' <span class="muted klein">Nachzählung</span>':'')+'</span><b style="font-weight:600;white-space:nowrap">'+esc(s.ist)+'</b></div>';}).join(''):'<span class="muted">Noch nichts.</span>'; }); }
  function nachzaehlen(){ api('nachzaehlen',{},function(j){ var l=j.liste||[]; $('izNz').hidden=!l.length;
      $('izNzListe').innerHTML=l.map(function(x){return '<div><span>'+esc(x.artikel)+' <span class="muted klein">'+esc(x.charge)+(x.gebinde?' · '+esc(x.gebinde):'')+'</span></span><span class="muted">'+esc(x.bereich)+'</span></div>';}).join(''); }); }
  function taraSelect(){ return '<select class="izTara">'+TARA.map(function(t){return '<option value="'+t.id+'" data-kg="'+t.kg+'">'+esc(t.name)+' ('+fmt(t.kg)+' kg)</option>';}).join('')+'</select>'; }
  function taraKg(t){ var o=t.options[t.selectedIndex]; return o?parseFloat(o.getAttribute('data-kg'))||0:0; }
  function ladeStamm(cb){ if(ART){ cb(); return; } api('artikel',{},function(j){ ART=j.artikel||[]; TARA=j.tara||[]; cb(); }); }
  // Mengen-Eingabe je Modus. o: {modus, einheit, voll, kapsel_mg}. Rückgabe HTML; liest() holt die Werte.
  function eingabe(o){
    var h='';
    if(o.modus==='stk'){ h+='<div class="iz-zeile"><input type="text" inputmode="decimal" class="izMenge" placeholder="Menge"> <span class="iz-gross">'+esc(o.einheit)+'</span></div>';
      if(o.voll) h+='<button type="button" class="btn btn-ghost izVoll">voll, original verschlossen ('+fmt(o.voll)+' '+esc(o.einheit)+')</button>'; }
    else { h+='<div class="iz-zeile"><input type="text" inputmode="decimal" class="izBrutto" data-gewicht="kg" placeholder="Brutto"> <span class="iz-gross">kg brutto</span></div>'
          +'<div class="iz-zeile">'+taraSelect()+' <input type="text" inputmode="decimal" class="izTaraKg" style="width:120px" placeholder="Tara"> <span>kg Tara</span></div>';
      if(o.modus==='kapsel') h+='<div class="iz-zeile"><input type="text" inputmode="decimal" class="izMg" style="width:120px" value="'+(o.kapsel_mg?fmtG(o.kapsel_mg,2):'')+'"> <span>mg je Kapsel</span></div>';
      h+='<div class="iz-gross izNetto muted">Netto: –</div>'; }
    return h; }
  function verdrahte(box,o){
    var t=box.querySelector('.izTara'), tk=box.querySelector('.izTaraKg');
    if(t&&tk){ tk.value=fmtG(taraKg(t)); t.addEventListener('change',function(){ tk.value=fmtG(taraKg(t)); rechne(); }); }
    function rechne(){ var n=box.querySelector('.izNetto'); if(!n) return; var net=Math.max(0,gew(box.querySelector('.izBrutto').value)-gew(tk.value));
      var t2='Netto: '+fmt(net)+' kg'; if(o.modus==='kapsel'){ var mg=gew(box.querySelector('.izMg').value); t2+= mg>0?' = '+fmt(Math.round(net*1e6/mg),0)+' Kapseln':' (Kapselgewicht fehlt)'; } n.textContent=t2; }
    box.addEventListener('input',rechne); }
  function werte(box,o){ var d={};
    if(o.modus==='stk'){ d.art='stk'; d.menge=box.querySelector('.izMenge').value; }
    else { d.art=o.modus==='kapsel'?'kapsel':'wiegen'; d.brutto=box.querySelector('.izBrutto').value; var tt=box.querySelector('.izTara'), tv=box.querySelector('.izTaraKg').value; d.tara_id=tt?tt.value:0; d.tara=tv; d.tara_hand=(tt && tv===fmtG(taraKg(tt)))?0:1; if(o.modus==='kapsel') d.kapsel_mg=box.querySelector('.izMg').value; }
    return d; }
  function zeigeObjekt(o){
    ladeStamm(function(){
      karte.innerHTML='<div class="iz-karte"><div class="iz-art">'+esc(o.artikel)+'</div>'
        +'<div class="muted">Charge '+esc(o.charge||'–')+' · MHD '+esc(o.mhd||'–')+' · '+esc(o.gebinde)+(o.lager2?' · Lager 2':'')+(o.quarantaene?' · Quarantäne':'')+'</div>'
        +'<div>Einheit <span class="iz-ein">'+esc(o.einheit)+'</span></div>'
        +(o.schon?'<div class="muted">'+esc(o.schon)+' – neu zählen ersetzt den Wert.</div>':'')
        +eingabe(o)
        +'<div class="iz-zeile"><button type="button" class="btn btn-primary izSpeichern">Speichern</button><button type="button" class="btn btn-ghost izLeer">Gebinde leer</button><button type="button" class="btn btn-ghost izAbbr">Abbrechen</button></div></div>';
      var box=karte.firstChild; verdrahte(box,o);
      var f=box.querySelector('.izMenge')||box.querySelector('.izBrutto'); if(f) f.focus();
      function senden(d){ d.bereich=bereich; d.obj=o.obj; d.oid=o.id; api('zaehlen',d,function(r){ msg(r.msg,r.ok); if(r.ok){ karte.innerHTML=''; scans(); nachzaehlen(); fokus(); } }); }
      box.querySelector('.izSpeichern').addEventListener('click',function(){ var los=function(){ senden(werte(box,o)); }; if(!window.bxGewichtPruefen || window.bxGewichtPruefen(box,los)) los(); });
      box.addEventListener('keydown',function(e){ if(e.key==='Enter'&&e.target.tagName==='INPUT'){ e.preventDefault(); senden(werte(box,o)); } });
      var v=box.querySelector('.izVoll'); if(v) v.addEventListener('click',function(){ senden({art:'voll'}); });
      box.querySelector('.izLeer').addEventListener('click',function(){ if(confirm('Gebinde ist leer / verbraucht?')) senden({art:'leer'}); });
      box.querySelector('.izAbbr').addEventListener('click',function(){ karte.innerHTML=''; fokus(); });
    });
  }
  function scan(c){ c=String(c||'').trim(); if(!c) return; code.value='';
    api('scan',{code:c,bereich:bereich},function(r){
      if(r.typ==='platz'||r.typ==='kiste'){ platzSetzen(r.start); fokus(); return; }
      if(!bereich){ msg('Bitte zuerst den Platz scannen.',false); fokus(); return; }
      if(r.typ==='objekt'){ msg('',true); zeigeObjekt(r); return; }
      if(r.typ==='gebinde_wahl'){ karte.innerHTML='<div class="iz-karte iz-wahl"><div class="iz-art">'+esc(r.artikel)+'</div><div class="muted">Charge '+esc(r.charge)+' – welches Gebinde?</div>'
          +r.gebinde.map(function(g){return '<button type="button" class="btn btn-ghost" data-g="'+g.id+'">'+esc(g.text)+(g.gezaehlt?' · schon gezählt':'')+'</button>';}).join('')+'</div>';
        karte.querySelectorAll('[data-g]').forEach(function(b){ b.addEventListener('click',function(){ scan('?bg='+b.dataset.g); }); }); return; }
      msg(r.msg||'Unbekannt.',r.typ==='klaerfall'); fokus(); }); }
  code.addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); scan(code.value); } });
  $('izOk').addEventListener('click',function(){ scan(code.value); });
  var bw=$('izBereichWahl'); if(bw) bw.addEventListener('change',function(){ if(bw.value) api('start',{key:bw.value},function(r){ platzSetzen(r); fokus(); }); });
  $('izFertig').addEventListener('click',function(){ if(!bereich||!confirm(platzName+' fertig gezählt? Danach kann nur der Admin den Platz wieder öffnen.')) return;
    api('fertig',{bereich:bereich},function(r){ msg(r.msg,r.ok); if(r.ok){ bereich=0; try{ localStorage.removeItem('inv-'+INV+'-bereich'); }catch(e){} $('izPlatz').textContent='Nächsten Platz scannen'; $('izPlatz').classList.remove('an'); $('izOhne').disabled=true; $('izFertig').disabled=true; $('izListe').innerHTML='<span class="muted">Noch nichts.</span>'; $('izAnz').textContent=''; if(bw){ var o=bw.querySelector('option[value="'+bw.value+'"]'); if(o) o.remove(); bw.value=''; } } fokus(); }); });
  // Gefunden ohne Etikett: Artikel suchen (Synonyme, Kapselgröße/Füllgewicht sichtbar), Charge blind abtippen, MHD, Menge, Pflichtfoto.
  $('izOhne').addEventListener('click',function(){ ladeStamm(function(){
    karte.innerHTML='<form class="iz-karte" id="izKf"><div class="iz-art">Gefunden ohne Etikett</div>'
      +'<input type="text" id="izKfSuche" placeholder="Artikel suchen (Name, Synonym)" style="font-size:18px;min-height:52px"><div class="iz-treffer" id="izKfTreffer"></div><input type="hidden" name="item_id" id="izKfItem">'
      +'<div id="izKfMenge"></div>'
      +'<div class="iz-zeile"><input type="text" name="charge_nr" placeholder="Charge vom Gebinde abtippen" style="width:100%;text-align:left"></div>'
      +'<div class="iz-zeile"><label>MHD <input type="date" name="mhd" style="width:auto;text-align:left"></label></div>'
      +'<div class="iz-zeile"><label>Foto (Pflicht) <input type="file" name="foto" accept="image/*" capture="environment" required style="width:auto;text-align:left;font-size:15px"></label></div>'
      +'<div class="iz-zeile"><input type="text" name="notiz" placeholder="Notiz (optional)" style="width:100%;text-align:left"></div>'
      +'<div class="iz-zeile"><button class="btn btn-primary" type="submit">Erfassen und Etikett drucken</button><button type="button" class="btn btn-ghost izAbbr">Abbrechen</button></div></form>';
    var f=$('izKf'), s=$('izKfSuche'), tr=$('izKfTreffer'), sel=null;
    function norm(x){ return String(x).toLowerCase().replace(/ä/g,'ae').replace(/ö/g,'oe').replace(/ü/g,'ue').replace(/ß/g,'ss'); }
    s.addEventListener('input',function(){ var q=norm(s.value.trim()); if(q.length<2){ tr.innerHTML=''; return; }
      var hit=ART.filter(function(a){ return norm(a.name+' '+a.syn).indexOf(q)>=0; }).slice(0,30);
      tr.innerHTML=hit.map(function(a){ return '<div data-id="'+a.id+'">'+esc(a.name)+' <span class="muted klein">'+esc(a.einheit)+(a.kapsel?' · '+esc(a.kapsel):'')+'</span></div>'; }).join('')||'<div class="muted">Nichts gefunden.</div>'; });
    tr.addEventListener('click',function(e){ var d=e.target.closest('[data-id]'); if(!d) return; sel=ART.filter(function(a){return String(a.id)===d.dataset.id;})[0]; if(!sel) return;
      $('izKfItem').value=sel.id; s.value=sel.name+(sel.kapsel?' ('+sel.kapsel+')':''); tr.innerHTML='';
      var o={modus:(sel.einheit==='kg'||sel.einheit==='g')?'wiegen':'stk', einheit:sel.einheit}; var m=$('izKfMenge'); m.innerHTML=eingabe(o); verdrahte(m,o); m.dataset.modus=o.modus; });
    f.querySelector('.izAbbr').addEventListener('click',function(){ karte.innerHTML=''; fokus(); });
    f.addEventListener('submit',function(e){ e.preventDefault(); if(!sel){ msg('Bitte den Artikel wählen.',false); return; }
      var fd=new FormData(f), m=$('izKfMenge'), o={modus:m.dataset.modus}; var w=werte(m,o); for(var k in w) fd.append(k,w[k]); fd.append('bereich',bereich);
      api('klaerfall',fd,function(r){ msg(r.msg,r.ok); if(r.ok){ karte.innerHTML='<div class="iz-karte"><div>Etikett <b style="font-weight:600">Inv '+esc(r.inventar_nr)+'</b> auf das Gebinde kleben.</div><a class="btn btn-ghost" target="_blank" href="'+esc(r.etikett_url)+'">Etikett anzeigen</a></div>'; scans(); } }); });
  }); });
  // Kamera-Scan (Android/Chrome: BarcodeDetector). Sonst Handscanner oder eintippen.
  if('BarcodeDetector' in window){ var cam=$('izCam'), vid=$('izVideo'), strom=null, det=null; cam.hidden=false;
    cam.addEventListener('click',function(){ if(strom){ stop(); return; }
      try{ det=new BarcodeDetector(); }catch(e){ msg('Kamera-Scan nicht verfügbar.',false); return; }
      navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}}).then(function(s){ strom=s; vid.srcObject=s; vid.style.display='block'; vid.play(); cam.textContent='Kamera aus'; suche(); }).catch(function(){ msg('Kamera nicht erlaubt.',false); }); });
    function stop(){ if(strom) strom.getTracks().forEach(function(t){t.stop();}); strom=null; vid.style.display='none'; cam.textContent='Kamera'; }
    function suche(){ if(!strom) return; det.detect(vid).then(function(c){ if(c&&c.length){ var v=c[0].rawValue; stop(); scan(v); } else setTimeout(suche,250); }).catch(function(){ setTimeout(suche,400); }); } }
  // Weitermachen nach Unterbrechung: zuletzt offener Platz.
  try{ var w=JSON.parse(localStorage.getItem('inv-'+INV+'-bereich')||'null'); if(w&&w.b){ bereich=w.b; platzName=w.n; nurNz=!!w.z; $('izPlatz').textContent=w.n+' · Zählung läuft'; $('izPlatz').classList.add('an'); $('izOhne').disabled=nurNz; $('izFertig').disabled=nurNz; scans(); } }catch(e){}
  nachzaehlen(); fokus();
})();
</script>
<?php fuss();
