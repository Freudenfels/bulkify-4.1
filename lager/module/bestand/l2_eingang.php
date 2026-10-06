<?php
// Lager 2 (Fremdlager) – Kundenware einbuchen: Kunde (Pflicht) + Typ (Verkaufsprodukt/Rohstoff/Etikett/
// Beipackzettel/Karton/Sonstiges) + Artikel (bestehend ODER neu) + Menge + MHD + Blinker (optional)
// -> Charge, die dem Kunden gehoert (fremd_kunde_id). Der Typ filtert die Artikel und ordnet neue richtig ein.
// Regel Nico: Verkaufsfertige Produkte haben KEINE Pakete/Kartons; der Blinker ist immer optional.
$defs = erp_l2_typ_defs();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'buchen') {
    $kunde_id   = (int)($_POST['kunde_id'] ?? 0);
    $typ        = (string)($_POST['typ'] ?? '');
    $blinkerRaw = trim((string)($_POST['blinker'] ?? ''));
    $blinker    = $blinkerRaw === '' ? null : led_leiste_normalisieren($blinkerRaw);
    $menge      = (float) str_replace(',', '.', trim((string)($_POST['menge'] ?? '0')));
    if ($kunde_id <= 0) { flash('Bitte den Kunden wählen, dem die Ware gehört.', 'warn'); weiter('?p=l2_eingang'); }
    if (!isset($defs[$typ])) { flash('Bitte einen Typ wählen (Verkaufsprodukt, Rohstoff, Etikett …).', 'warn'); weiter('?p=l2_eingang'); }
    if ($blinkerRaw !== '' && $blinker === null) { flash('Blinker-Code ungültig (6 Zeichen) – oder das Feld leer lassen.', 'warn'); weiter('?p=l2_eingang'); }
    if ($menge <= 0) { flash('Bitte eine Menge größer 0 angeben.', 'warn'); weiter('?p=l2_eingang'); }
    $def = $defs[$typ];

    $item_id = (int)($_POST['item_id'] ?? 0);
    if (!$item_id) {
        $neuName = trim((string)($_POST['art_text'] ?? ''));
        if ($neuName !== '' && !$def['neu']) { flash('Verkaufsprodukte können hier nicht neu angelegt werden – bitte ein bestehendes Produkt wählen.', 'warn'); weiter('?p=l2_eingang'); }
        if ($neuName !== '') $item_id = (int) erp_item_anlegen($neuName, $def['kategorie'], (string)($_POST['neu_einheit'] ?? ''), $def['rolle']);
    }
    if (!$item_id) { flash('Bitte einen Artikel wählen – oder (außer Verkaufsprodukt) einen Namen für einen neuen Artikel eingeben.', 'warn'); weiter('?p=l2_eingang'); }

    // Verkaufsfertige Produkte haben keine Pakete/Kartons (Regel Nico) -> fest 1.
    $pakete = ($typ === 'verkaufsprodukt') ? 1 : max(1, (int)($_POST['pakete'] ?? 1));
    $cid = erp_wareneingang_buchen_fremd($item_id, $menge, trim((string)($_POST['charge_nr'] ?? '')),
        trim((string)($_POST['mhd'] ?? '')) ?: null, $kunde_id, trim((string)($_POST['notiz'] ?? '')));
    if (!$cid) { flash('Einbuchen fehlgeschlagen. Bitte Angaben prüfen.', 'warn'); weiter('?p=l2_eingang'); }
    lg_pakete_set((int)$cid, $pakete);
    $c = erp_charge((int)$cid);
    lg_bewegung_log((int)$cid, 'ein', $menge, $c['einheit'] ?? null, (string)($c['item_name'] ?? ''), 'Fremdlager-Wareneingang (' . $def['label'] . ')');

    if ($blinker !== null) {
        $bfehler = leiste_binden($blinker, (int)$cid);
        if ($bfehler === '') {
            $lr = leiste_per_code($blinker);
            if ($lr) leiste_finden((int)$lr['id'], 'gruen', 3, false);
            flash('Kundenware eingebucht (' . $def['label'] . '). Blinker ' . $blinker . ' hängt dran und leuchtet kurz grün.');
        } else {
            flash('Eingebucht – aber der Blinker konnte nicht angehängt werden: ' . $bfehler, 'warn');
        }
    } else {
        flash('Kundenware eingebucht (' . $def['label'] . '). Kein Blinker angehängt.');
    }
    weiter('?p=charge&id=' . (int)$cid . '&neu=1');
}

$items   = function_exists('erp_items_l2') ? erp_items_l2() : erp_items_eingang();
$kunden  = erp_fulfillment_kunden();

kopf('Lager 2 – Kundenware einbuchen', 'l2_eingang');
seitenkopf('Lager 2 (Fremdlager) – Kundenware einbuchen', 'Kunde + Typ + Artikel + Menge + MHD. Blinker optional; Verkaufsprodukte ohne Pakete/Kartons.',
    '<a class="btn btn-ghost" href="?p=l2_bestand">Zum Fremdlager-Bestand</a>');
flash_zeigen();

if (!$kunden) { hinweis('Noch keine Fulfillment-Kunden hinterlegt. Setze bei einem Kunden im Dashboard den Haken „nutzt Fulfillment".', 'warn'); fuss(); return; }
?>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="buchen">
  <div class="bx-panel">
    <style>
      .lg-acombo{position:relative}
      .lg-acombo-list{position:absolute;left:0;right:0;top:100%;z-index:30;max-height:300px;overflow:auto;
        background:var(--panel,#fff);border:1px solid var(--line,#ddd);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.14);margin-top:3px}
      .lg-acombo-list .opt{padding:9px 12px;cursor:pointer;font-size:15px}
      .lg-acombo-list .opt:hover,.lg-acombo-list .opt.hl{background:var(--panel-2,#f2f2f0)}
      .lg-acombo-list .opt.neu{color:var(--gruen,#1D9E75);font-weight:600}
      .lg-acombo-empty{padding:9px 12px;color:var(--muted);font-size:13px}
      #l2Neu{border:1px dashed var(--gruen,#1D9E75);border-radius:10px;padding:12px;margin:6px 0 2px}
    </style>
    <div class="bx-grid">
      <div class="bx-field"><label>Kunde <span class="muted">(wem gehört die Ware)</span></label>
        <select name="kunde_id" required>
          <option value="">– Kunde wählen –</option>
          <?php foreach ($kunden as $k): ?><option value="<?= (int)$k['id'] ?>"><?= h((string)$k['firma']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Typ <span class="muted">(was wird eingebucht)</span></label>
        <select name="typ" id="l2Typ" required>
          <option value="">– Typ wählen –</option>
          <?php foreach ($defs as $tk => $td): ?><option value="<?= h($tk) ?>"><?= h($td['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field lg-acombo" id="l2ArtWrap"><label>Artikel / Produkt</label>
        <input type="text" id="l2ArtSuche" name="art_text" autocomplete="off" placeholder="Erst Typ wählen, dann suchen…" required aria-expanded="false">
        <input type="hidden" name="item_id" id="l2ArtId">
        <div id="l2ArtList" class="lg-acombo-list" hidden></div>
      </div>
      <div class="bx-field"><label>Menge</label>
        <div class="bx-row" style="gap:8px;align-items:center;margin:0">
          <input type="text" inputmode="decimal" name="menge" required style="flex:1;min-width:0" placeholder="z. B. 500">
          <span id="l2Einheit" style="min-width:44px;font-weight:600;color:var(--muted)">–</span>
        </div>
      </div>
      <div class="bx-field"><label>Blinker <span class="muted">(optional)</span></label>
        <input type="text" name="blinker" class="lg-code" placeholder="Blinker-Code scannen oder eingeben – oder leer lassen">
      </div>
      <div class="bx-field"><label>MHD</label><input type="date" name="mhd"></div>
      <div class="bx-field"><label>Charge-Nr.</label><input type="text" name="charge_nr" class="lg-code" placeholder="optional"></div>
      <div class="bx-field" id="l2PaketeWrap"><label>Anzahl Pakete / Kartons</label><input type="number" name="pakete" id="l2Pakete" min="1" step="1" value="1"></div>
      <div class="bx-field"><label>Notiz (optional)</label><input type="text" name="notiz" placeholder="z. B. Anlieferung Kunde"></div>
    </div>

    <div id="l2Neu" hidden>
      <div class="muted" style="margin-bottom:8px">Neuer Artikel „<span id="l2NeuName"></span>" wird als <strong id="l2NeuTyp">–</strong> angelegt. Einheit festlegen:</div>
      <div class="bx-field" style="margin:0"><label>Einheit</label>
        <input type="text" name="neu_einheit" id="l2NeuEinheit" placeholder="z. B. Stück, kg" style="max-width:160px">
      </div>
    </div>

    <div class="muted" style="margin:10px 0 12px">Kundenware (Lager 2) ist sofort <strong>frei</strong> – keine Quarantäne.</div>
    <button class="btn btn-primary" type="submit">Einbuchen &amp; Blinker anhängen</button>
  </div>
</form>

<script>
(function(){
  var items = <?= json_encode(array_map(fn($it)=>['id'=>(int)$it['id'],'n'=>(string)$it['name'],'e'=>(string)$it['einheit'],'k'=>(string)$it['kategorie'],'r'=>(string)($it['rolle'] ?? '')], $items), JSON_UNESCAPED_UNICODE) ?>;
  var TYPDEFS = <?= json_encode(array_map(fn($d)=>['kategorie'=>$d['kategorie'],'rolle'=>$d['rolle'],'neu'=>$d['neu'],'label'=>$d['label']], $defs), JSON_UNESCAPED_UNICODE) ?>;
  var typEl=document.getElementById('l2Typ');
  var box=document.getElementById('l2ArtSuche'), hid=document.getElementById('l2ArtId'), list=document.getElementById('l2ArtList');
  var einhEl=document.getElementById('l2Einheit');
  var neuBox=document.getElementById('l2Neu'), neuName=document.getElementById('l2NeuName'), neuEinheit=document.getElementById('l2NeuEinheit'), neuTyp=document.getElementById('l2NeuTyp');
  var paketeWrap=document.getElementById('l2PaketeWrap'), paketeInp=document.getElementById('l2Pakete');
  if(!box||!hid||!list) return;
  var hl=-1, shown=[];
  function esc(s){ return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function typ(){ return typEl ? typEl.value : ''; }
  function matchTyp(it){ var t=typ(), d=TYPDEFS[t]; if(!t||!d) return true;
    if(t==='karton') return it.k==='karton' || (it.k==='verpackung' && it.r==='karton');
    if(t==='sonstiges') return it.k==='sonstiges' || it.k==='verbrauch';
    if(d.kategorie==='verpackung') return it.k==='verpackung' && (d.rolle==='' || it.r===d.rolle);
    return it.k===d.kategorie;
  }
  function pool(){ return items.filter(matchTyp); }
  function exakt(q){ q=(q||'').trim().toLowerCase(); return pool().some(function(it){ return it.n.toLowerCase()===q; }); }
  function neuErlaubt(){ var d=TYPDEFS[typ()]; return d ? !!d.neu : false; }
  function paketeToggle(){ if(!paketeWrap) return; var aus = typ()==='verkaufsprodukt';
    paketeWrap.hidden = aus; if(aus && paketeInp) paketeInp.value='1'; }
  function neuToggle(){ var q=(box.value||'').trim();
    var neu=q!=='' && !hid.value && !exakt(q) && neuErlaubt();
    neuBox.hidden=!neu; neuEinheit.required=neu;
    if(neu){ neuName.textContent=q; var d=TYPDEFS[typ()]; neuTyp.textContent=d?d.label:'–'; einhEl.textContent=neuEinheit.value||'–'; } }
  function render(q){
    q=(q||'').trim().toLowerCase();
    if(!typ()){ list.innerHTML='<div class="lg-acombo-empty">Bitte zuerst oben den Typ wählen.</div>'; list.hidden=false; return; }
    shown=pool().filter(function(it){ return !q || it.n.toLowerCase().indexOf(q)>=0; }).slice(0,60);
    var html = shown.map(function(it,i){ return '<div class="opt" data-i="'+i+'">'+esc(it.n)+' <span class="muted">('+esc(it.e||'')+')</span></div>'; }).join('');
    if(q!=='' && !exakt(q) && neuErlaubt()) html += '<div class="opt neu" data-neu="1">+ Neuer Artikel „'+esc(box.value.trim())+'" anlegen</div>';
    list.innerHTML = html || '<div class="lg-acombo-empty">Nichts gefunden'+(neuErlaubt()?' – Namen tippen, um neu anzulegen':'')+'.</div>';
    hl=-1; list.hidden=false;
  }
  function paint(){ Array.prototype.forEach.call(list.querySelectorAll('.opt'),function(o){o.classList.toggle('hl',+o.dataset.i===hl);}); }
  function choose(i){ var it=shown[i]; if(!it) return; hid.value=it.id; box.value=it.n; einhEl.textContent=it.e||'–'; neuToggle(); close(); }
  function close(){ list.hidden=true; }
  if(typEl) typEl.addEventListener('change', function(){ hid.value=''; box.value=''; einhEl.textContent='–'; box.placeholder = typ() ? 'Produkt suchen – oder neuen Namen eingeben…' : 'Erst Typ wählen, dann suchen…'; neuToggle(); paketeToggle(); });
  box.addEventListener('input', function(){ hid.value=''; einhEl.textContent='–'; render(box.value); neuToggle(); });
  box.addEventListener('focus', function(){ render(box.value); });
  box.addEventListener('keydown', function(e){
    if(list.hidden){ if(e.key==='ArrowDown') render(box.value); return; }
    if(e.key==='ArrowDown'){ e.preventDefault(); hl=Math.min(hl+1,shown.length-1); paint(); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); hl=Math.max(hl-1,0); paint(); }
    else if(e.key==='Enter'){ if(hl>=0){ e.preventDefault(); choose(hl); } }
    else if(e.key==='Escape'){ close(); }
  });
  list.addEventListener('mousedown', function(e){ var o=e.target.closest('.opt'); if(!o) return; e.preventDefault(); if(o.dataset.neu){ hid.value=''; close(); neuToggle(); neuEinheit.focus(); } else { choose(+o.dataset.i); } });
  if(neuEinheit) neuEinheit.addEventListener('input', function(){ einhEl.textContent=neuEinheit.value||'–'; });
  document.addEventListener('click', function(e){ if(!e.target.closest('#l2ArtWrap')) close(); });
  neuToggle(); paketeToggle();
})();
</script>
<?php
fuss();
