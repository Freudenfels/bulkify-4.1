<?php
// Finden – ganz einfach (handyfreundlich): tippen ODER sprechen, Treffer erscheinen live,
// Antippen eines Treffers lässt den Blinker an der Palette sofort klingeln.
// Suche läuft über ?p=suche (JSON), Klingeln über ?p=klingeln (assets/lager.js, data-klingeln).
$q = trim((string)($_GET['q'] ?? ''));

kopf('Finden', 'finden');
seitenkopf('Finden', 'Tippen oder sprechen – Treffer antippen, der Blinker blinkt.');
flash_zeigen();

if (!tabelle_da('charge')) { hinweis('Es sind noch keine Chargen im Dashboard vorhanden.', 'warn'); fuss(); return; }
?>
<div class="bx-panel fnd-suche">
  <input type="search" id="fndQ" class="fnd-input lg-code" autocomplete="off" autofocus
         placeholder="Was suchst du? (Rohstoff, Charge …)" value="<?= h($q) ?>">
  <button type="button" id="fndMic" class="btn btn-ghost fnd-mic" title="Per Sprache suchen" aria-label="Per Sprache suchen">
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"></rect><path d="M5 11a7 7 0 0 0 14 0"></path><line x1="12" y1="18" x2="12" y2="22"></line></svg>
    <span>Sprechen</span>
  </button>
</div>
<div id="fndStatus" class="muted" style="margin:var(--sp-3) 0"></div>
<div id="fndListe" class="fnd-liste"></div>

<style>
  .fnd-suche{display:flex;gap:var(--sp-3);align-items:center}
  .fnd-input{flex:1;min-width:0;font-size:20px;padding:16px 16px}
  .fnd-mic{min-height:56px;display:inline-flex;align-items:center;gap:8px;white-space:nowrap}
  .fnd-liste{display:flex;flex-direction:column;gap:12px}
  .fnd-item{display:block;width:100%;text-align:left;border:1px solid var(--line);border-radius:14px;
    padding:18px 20px;background:var(--panel-2);color:var(--text);cursor:pointer;line-height:1.3}
  .fnd-item:hover{border-color:var(--gruen);text-decoration:none}
  .fnd-item .n{display:block;font-size:var(--fs-lg);font-weight:600}
  .fnd-item .s{display:block;color:var(--muted);margin-top:4px}
  /* Aktiv = blinkt gerade: hellgrün. Nochmal antippen -> wieder dunkel. */
  .fnd-item.lg-an{background:#C0F24E;border-color:#1D9E75;color:#10210f;box-shadow:0 0 0 2px #C0F24E inset}
  .fnd-item.lg-an .n, .fnd-item.lg-an .s{color:#10210f}
  .fnd-item.lg-an::after{content:"● blinkt – zum Stoppen antippen";display:block;margin-top:6px;font-size:13px;font-weight:600;color:#10210f}
  .fnd-item.kein{opacity:.65;cursor:default}
  .fnd-item .lg-meldung{display:block;margin-top:6px;font-weight:600}
</style>

<script>
(function(){
  var q=document.getElementById('fndQ'), liste=document.getElementById('fndListe'),
      status=document.getElementById('fndStatus'), mic=document.getElementById('fndMic'), timer;
  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}

  function render(treffer){
    if(!treffer.length){ liste.innerHTML=''; status.textContent='Nichts gefunden.'; return; }
    status.textContent=treffer.length+' Treffer – zum Finden antippen.';
    liste.innerHTML = treffer.map(function(t){
      var teile=[]; if(t.charge_nr) teile.push('Charge '+t.charge_nr);
      if(t.menge) teile.push(t.menge+(t.einheit?(' '+t.einheit):''));
      if(t.ort) teile.push(t.ort); else if(t.leiste) teile.push('Blinker '+t.leiste);
      var sub=teile.join(' · ');
      if(t.leiste_id){
        return '<button type="button" class="fnd-item" data-klingeln="'+t.leiste_id+'" data-farbe="gruen" data-sek="40">'
             + '<span class="n">'+esc(t.name)+'</span><span class="s">'+esc(sub)+'</span></button>';
      }
      return '<div class="fnd-item kein"><span class="n">'+esc(t.name)+'</span>'
           + '<span class="s">'+esc(sub)+(sub?' · ':'')+'kein Blinker</span></div>';
    }).join('');
  }
  // Sieht die Eingabe nach einem gescannten QR-Code vom Etikett aus? (URL mit id=…) -> sofort suchen.
  function istScan(s){ return /[?&]id=\d+/.test(s) || /^https?:\/\//i.test(s); }
  function suchen(){
    var s=q.value.trim();
    if(s===''){ liste.innerHTML=''; status.textContent=''; return; }
    status.textContent='Suche …';
    fetch('?p=suche&q='+encodeURIComponent(s),{credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(j){ render(j.treffer||[]); })   // nur anzeigen – Klingeln erst beim Antippen
      .catch(function(){ status.textContent='Suche fehlgeschlagen.'; });
  }
  q.addEventListener('input',function(){ clearTimeout(timer); var sofort=istScan(q.value.trim()); timer=setTimeout(suchen, sofort?0:250); });
  // Handscanner schließt mit Enter ab -> sofort suchen (aber NICHT automatisch klingeln).
  q.addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); clearTimeout(timer); suchen(); } });
  if(q.value.trim()) suchen();

  // --- Sprache (Web Speech API, Chrome/Android) ---
  // Viele Handscanner/PDAs haben ein Mikrofon, aber KEINE Sprache-zu-Text-Maschine im System
  // (kein Google-Sprachdienst). Dann schlägt die Erkennung fehl ('network'/'service-not-allowed').
  // In dem Fall merken wir uns das und blenden den Knopf künftig aus – Tippen bleibt der Hauptweg.
  function sttFlag(set){ try{ if(set){localStorage.setItem('lg_stt_aus','1');} return localStorage.getItem('lg_stt_aus')==='1'; }catch(e){ return false; } }
  var SR=window.SpeechRecognition||window.webkitSpeechRecognition;
  if(!SR){ mic.style.display='none'; }           // Gerät kennt die Sprach-API gar nicht
  else if(sttFlag(false)){                        // früher fehlgeschlagen -> aus, aber Reaktivieren anbieten
    mic.style.display='none';
    var re=document.createElement('button');
    re.type='button'; re.className='btn btn-ghost fnd-mic';
    re.textContent='Sprachsuche aktivieren';
    re.title='Nur möglich, wenn das Gerät Google-Spracherkennung hat';
    re.addEventListener('click',function(){ try{localStorage.removeItem('lg_stt_aus');}catch(e){} location.reload(); });
    mic.parentNode.appendChild(re);
  }
  else mic.addEventListener('click',function(){
    try{
      var r=new SR(); r.lang='de-DE'; r.interimResults=false; r.maxAlternatives=1;
      status.textContent='Sprich jetzt …'; mic.disabled=true;
      r.onresult=function(e){ q.value=e.results[0][0].transcript; suchen(); };
      r.onerror=function(e){
        var weg={'service-not-allowed':1,'network':1,'language-not-supported':1}; // Maschine fehlt → dauerhaft
        if(weg[e.error]){ sttFlag(true); mic.style.display='none';
          status.textContent='Dieses Gerät hat keine Sprache-zu-Text – bitte tippen.'; return; }
        var m={'not-allowed':'Mikrofon nicht erlaubt – bitte in den App-/Browser-Rechten freigeben.',
               'no-speech':'Nichts verstanden – nochmal auf „Sprechen" tippen.',
               'audio-capture':'Kein Mikrofon gefunden.'};
        status.textContent = m[e.error] || ('Spracherkennung nicht möglich ('+(e.error||'Fehler')+'). Bitte tippen.');
      };
      r.onend=function(){ mic.disabled=false; };
      r.start();
    }catch(err){ sttFlag(true); mic.style.display='none'; status.textContent='Spracherkennung auf diesem Gerät nicht möglich – bitte tippen.'; }
  });
})();
</script>
<?php
fuss();
