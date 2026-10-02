<?php
// Vollwertiger Wareneingang (Warenlager-Manager). Ein Bereich fuer ALLES, was reinkommt:
//   Schritt 0  Ziel: Lager 1 (eigener Bestand) ODER Lager 2 (Kundenware -> Kunde).
//   Schritt 1  Lieferschein scannen (Webcam-Foto oder Datei/PDF) -> KI liest Lieferant + Positionen.
//   Schritt 2  Positionen pruefen/ergaenzen (Artikel, Warenart, Menge, Charge, MHD, Blinker, Pakete).
//              Pflichtfelder je Warenart (erp_warenart_regeln): Rohstoff/Fertig/Kapsel = MHD+Charge
//              Pflicht + Quarantaene; Verpackung/Verbrauch = kein MHD, sofort frei.
//   Schritt 3  Alle buchen -> je Position eine Charge + Blinker + Karton-Etiketten.
//
// KI ist im Lager self-contained (lager/core/ki.php). Tabellen-Zugriffe nur ueber erp.php (Naht).
require_once __DIR__ . '/../../core/ki.php';

// --- AJAX: Lieferschein auslesen -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'scan') {
    header('Content-Type: application/json; charset=utf-8');
    if (!lg_ki_bereit()) { echo json_encode(['ok' => false, 'fehler' => 'KI ist nicht eingerichtet (kein Anthropic-Schluessel in secrets.php).']); exit; }
    $pfade = [];
    $tmpdir = sys_get_temp_dir();
    if (!empty($_FILES['dateien']['tmp_name']) && is_array($_FILES['dateien']['tmp_name'])) {
        foreach ($_FILES['dateien']['tmp_name'] as $i => $tmp) {
            if (!is_uploaded_file($tmp)) continue;
            $name = (string)($_FILES['dateien']['name'][$i] ?? 'bild.jpg');
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'jpg';
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'], true)) $ext = 'jpg';
            $ziel = $tmpdir . '/lsin_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (@move_uploaded_file($tmp, $ziel)) $pfade[] = $ziel;
        }
    }
    if (!$pfade) { echo json_encode(['ok' => false, 'fehler' => 'Keine Datei empfangen.']); exit; }
    $r = lg_lieferschein_lesen($pfade);
    foreach ($pfade as $p) @unlink($p);
    if (!$r['ok']) { echo json_encode($r); exit; }
    $pos = [];
    foreach ($r['positionen'] as $p) $pos[] = erp_position_zuordnen($p);
    echo json_encode([
        'ok'  => true,
        'kopf' => ['lieferant' => $r['lieferant'], 'ls_nr' => $r['ls_nr'], 'datum' => $r['datum']],
        'positionen' => $pos,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Buchen: alle Positionen ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'buchen') {
    $ziel     = ($_POST['ziel'] ?? 'l1') === 'l2' ? 'l2' : 'l1';
    $kunde_id = (int)($_POST['kunde_id'] ?? 0);
    $lief     = ($_POST['lieferant_id'] ?? '') !== '' ? (int)$_POST['lieferant_id'] : null;
    if ($ziel === 'l2' && $kunde_id <= 0) { flash('Lager 2: bitte den Kunden wählen, dem die Ware gehört.', 'warn'); weiter('?p=we'); }

    $names   = (array)($_POST['p_name'] ?? []);
    $gebucht = [];
    $fehler  = [];
    foreach ($names as $i => $nm) {
        $name     = trim((string)$nm);
        $item_id  = (int)($_POST['p_item'][$i] ?? 0);
        $warenart = (string)($_POST['p_warenart'][$i] ?? 'rohstoff');
        $menge    = (float) str_replace(',', '.', trim((string)($_POST['p_menge'][$i] ?? '0')));
        $einheit  = trim((string)($_POST['p_einheit'][$i] ?? ''));
        $charge   = trim((string)($_POST['p_charge'][$i] ?? ''));
        $mhd      = trim((string)($_POST['p_mhd'][$i] ?? ''));
        $blinker  = led_leiste_normalisieren((string)($_POST['p_blinker'][$i] ?? ''));
        $pakete   = max(1, (int)($_POST['p_pakete'][$i] ?? 1));
        if ($name === '' && $menge <= 0) continue;   // leere Zeile

        // Artikel bestimmen (bestehend oder neu anlegen).
        if (!$item_id && $name !== '') {
            $kat = in_array($warenart, ['rohstoff', 'verpackung', 'verbrauch', 'fertig'], true) ? $warenart : 'rohstoff';
            $item_id = (int) erp_item_anlegen($name, $kat, $einheit);
        }
        if (!$item_id) { $fehler[] = 'Zeile ' . ($i + 1) . ': kein Artikel.'; continue; }

        // Warenart/Regeln der tatsaechlichen Ware.
        $basis = erp_item_basis($item_id);
        $regeln = erp_warenart_regeln((string)($basis['kategorie'] ?? $warenart), (string)($basis['form'] ?? ''));
        if ($menge <= 0)                           { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Menge fehlt.'; continue; }
        if ($blinker === null)                     { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Blinker fehlt.'; continue; }
        if ($regeln['mhd_pflicht'] && $mhd === '')    { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): MHD ist Pflicht.'; continue; }
        if ($regeln['charge_pflicht'] && $charge === '') { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Charge-Nr. ist Pflicht.'; continue; }

        $notiz = 'Wareneingang' . ($ziel === 'l2' ? ' (Kundenware)' : '');
        $cid = $ziel === 'l2'
            ? erp_wareneingang_buchen_fremd($item_id, $menge, $charge, $mhd ?: null, $kunde_id, $notiz)
            : erp_wareneingang_buchen($item_id, $menge, $charge, $mhd ?: null, $lief, $notiz);
        if (!$cid) { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Buchen fehlgeschlagen.'; continue; }

        lg_pakete_set((int)$cid, $pakete);
        $c = erp_charge((int)$cid);
        lg_bewegung_log((int)$cid, 'ein', $menge, $c['einheit'] ?? null, (string)($c['item_name'] ?? ''), $notiz);
        if (leiste_binden($blinker, (int)$cid) === '') {
            $lr = leiste_per_code($blinker);
            if ($lr) leiste_finden((int)$lr['id'], 'gruen', 3, false);
        }
        $gebucht[] = (int)$cid;
    }

    if ($gebucht) flash(count($gebucht) . ' Position(en) eingebucht, Blinker angehängt.' . ($fehler ? ' ' . count($fehler) . ' übersprungen.' : ''));
    if ($fehler)  flash(implode(' · ', $fehler), $gebucht ? 'warn' : 'warn');
    if (!$gebucht && !$fehler) flash('Nichts zu buchen – keine Position erfasst.', 'warn');
    weiter('?p=we' . ($gebucht ? '&gebucht=' . implode(',', $gebucht) : ''));
}

// --- Anzeige ----------------------------------------------------------------------------------
$kunden  = erp_fulfillment_kunden();
$liefers = erp_lieferanten();
$items   = erp_items_eingang();
$ki      = lg_ki_bereit();

// Nach dem Buchen: Erfolgspanel mit Sammel-Etikett.
$gebucht = [];
if (isset($_GET['gebucht'])) foreach (explode(',', (string)$_GET['gebucht']) as $x) { $x = (int)$x; if ($x > 0) $gebucht[] = $x; }

kopf('Wareneingang', 'we');
seitenkopf('Wareneingang', 'Ziel wählen · Lieferschein scannen · prüfen · buchen.',
    '<a class="btn btn-ghost" href="?p=bestand">Zum Bestand</a>');
flash_zeigen();

if ($gebucht):
    $ids = implode(',', $gebucht);
?>
<div class="bx-panel" style="border:1px solid var(--gruen);margin-bottom:var(--sp-5)">
  <div class="bx-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--sp-3)">
    <h2 style="margin:0"><?= count($gebucht) ?> Position(en) eingebucht – Etiketten für die Kartons</h2>
    <span class="bx-row" style="gap:var(--sp-2)">
      <a class="btn btn-primary btn-sm" href="?p=etikett&ids=<?= h($ids) ?>" target="_blank">Alle Etiketten drucken</a>
      <a class="btn btn-ghost btn-sm" href="?p=we">Nächster Wareneingang</a>
    </span>
  </div>
  <embed src="?p=etikett&ids=<?= h($ids) ?>" type="application/pdf" style="width:100%;height:340px;border:1px solid var(--line);border-radius:8px;background:#fff;margin-top:var(--sp-3)">
</div>
<?php endif; ?>

<form method="post" id="weForm" class="bx-form">
  <input type="hidden" name="aktion" value="buchen">

  <!-- Schritt 0: Ziel -->
  <div class="bx-panel">
    <h2 style="margin-top:0">1 · Wohin?</h2>
    <div class="bx-row" style="gap:var(--sp-4);flex-wrap:wrap;align-items:flex-end">
      <label class="we-ziel on"><input type="radio" name="ziel" value="l1" checked> <strong>Lager 1</strong><br><span class="muted">eigener Bestand</span></label>
      <label class="we-ziel"><input type="radio" name="ziel" value="l2"> <strong>Lager 2</strong><br><span class="muted">Kundenware (Fremdlager)</span></label>
      <div class="bx-field" id="weKundeWrap" style="margin:0;min-width:240px;display:none"><label>Kunde <span class="muted">(wem gehört die Ware)</span></label>
        <select name="kunde_id" id="weKunde">
          <option value="">– Kunde wählen –</option>
          <?php foreach ($kunden as $k): ?><option value="<?= (int)$k['id'] ?>"><?= h((string)$k['firma']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field" id="weLiefWrap" style="margin:0;min-width:240px"><label>Lieferant <span class="muted">(optional)</span></label>
        <select name="lieferant_id" id="weLief">
          <option value="">– keiner –</option>
          <?php foreach ($liefers as $lf): ?><option value="<?= (int)$lf['id'] ?>"><?= h((string)$lf['firma']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <!-- Schritt 1: Lieferschein scannen -->
  <div class="bx-panel">
    <h2 style="margin-top:0">2 · Lieferschein scannen <span class="muted" style="font-weight:400">(optional – geht auch von Hand)</span></h2>
    <?php if (!$ki): ?>
      <div class="bx-panel warn" style="margin:0 0 var(--sp-3)">KI-Scan ist nicht eingerichtet (kein Anthropic-Schlüssel). Du kannst Positionen von Hand erfassen.</div>
    <?php endif; ?>
    <div class="bx-row" style="gap:var(--sp-3);flex-wrap:wrap">
      <button type="button" class="btn btn-ghost" id="weCamStart" <?= $ki ? '' : 'disabled' ?>>Kamera starten</button>
      <label class="btn btn-ghost" style="margin:0">Datei/Foto/PDF wählen
        <input type="file" id="weFile" accept="image/*,application/pdf" capture="environment" multiple hidden <?= $ki ? '' : 'disabled' ?>>
      </label>
      <button type="button" class="btn btn-primary" id="weScan" disabled>Lieferschein auslesen</button>
      <span id="weScanInfo" class="muted" style="align-self:center"></span>
    </div>
    <div id="weCamBox" style="display:none;margin-top:var(--sp-3)">
      <video id="weVideo" playsinline style="width:100%;max-width:520px;border-radius:10px;background:#000"></video>
      <div class="bx-row" style="gap:var(--sp-2);margin-top:var(--sp-2)">
        <button type="button" class="btn btn-primary btn-sm" id="weShot">Foto aufnehmen</button>
        <button type="button" class="btn btn-ghost btn-sm" id="weCamStop">Kamera aus</button>
      </div>
    </div>
    <div id="weThumbs" class="bx-row" style="gap:var(--sp-2);flex-wrap:wrap;margin-top:var(--sp-3)"></div>
    <canvas id="weCanvas" hidden></canvas>
  </div>

  <!-- Schritt 2: Positionen -->
  <div class="bx-panel">
    <div class="bx-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--sp-2)">
      <h2 style="margin:0">3 · Positionen</h2>
      <button type="button" class="btn btn-ghost btn-sm" id="weAdd">+ Zeile</button>
    </div>
    <div id="weRows" style="margin-top:var(--sp-3)"></div>
    <div class="muted" style="margin-top:var(--sp-2)">Pflicht je Warenart: Rohstoff/Fertigware/Kapseln → MHD + Charge; Verpackung/Verbrauch → frei. Blinker ist immer Pflicht.</div>
    <div style="margin-top:var(--sp-4)"><button type="submit" class="btn btn-primary" id="weBuchen">Alle buchen &amp; Blinker anhängen</button></div>
  </div>
</form>

<style>
  .we-ziel{border:1px solid var(--line);border-radius:var(--r-sm);padding:10px 14px;cursor:pointer;line-height:1.3}
  .we-ziel.on{border-color:var(--gruen);box-shadow:inset 0 0 0 1px var(--gruen)}
  .we-pos{position:relative;border:1px solid var(--line);border-radius:var(--r-sm);padding:var(--sp-4);padding-top:var(--sp-5);margin-bottom:var(--sp-3);background:var(--panel-2)}
  .we-pos .bx-grid{grid-template-columns:repeat(auto-fit,minmax(185px,1fr));gap:var(--sp-3) var(--sp-4)}
  .we-pos .bx-field{margin-bottom:0}
  .we-pos .f-art{grid-column:1/-1}
  .we-pos .we-del{position:absolute;top:var(--sp-2);right:var(--sp-2)}
  .we-thumb{position:relative;width:84px;height:84px;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:var(--panel-2)}
  .we-thumb img{width:100%;height:100%;object-fit:cover}
  .we-thumb .x{position:absolute;top:2px;right:2px;background:#000a;color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;line-height:1}
  .we-thumb .pdf{display:flex;align-items:center;justify-content:center;height:100%;font-weight:600;color:var(--muted)}
</style>

<script>
(function(){
  var ITEMS = <?= json_encode(array_map(fn($it)=>['id'=>(int)$it['id'],'n'=>(string)$it['name'],'e'=>(string)$it['einheit'],'k'=>(string)$it['kategorie'],'f'=>(string)($it['form']??'')], $items), JSON_UNESCAPED_UNICODE) ?>;
  var MATRIX = {rohstoff:{mhd:1,charge:1},kapsel:{mhd:1,charge:1},fertig:{mhd:1,charge:1},verkaufsfertig:{mhd:1,charge:1},verpackung:{mhd:0,charge:0},verbrauch:{mhd:0,charge:0}};
  var ARTEN = [['rohstoff','Rohstoff'],['kapsel','Kapseln'],['fertig','Fertigware'],['verpackung','Verpackung'],['verbrauch','Verbrauch']];
  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}

  // --- Ziel L1/L2 ---
  var kundeWrap=document.getElementById('weKundeWrap'), kunde=document.getElementById('weKunde');
  document.querySelectorAll('input[name="ziel"]').forEach(function(r){
    r.addEventListener('change',function(){
      document.querySelectorAll('.we-ziel').forEach(function(l){l.classList.toggle('on', l.querySelector('input').checked);});
      var l2=document.querySelector('input[name="ziel"][value="l2"]').checked;
      kundeWrap.style.display=l2?'':'none'; kunde.required=l2;
    });
  });

  // --- Positionen-Tabelle ---
  var rows=document.getElementById('weRows');
  function artOptions(sel){ return ARTEN.map(function(a){return '<option value="'+a[0]+'"'+(a[0]===sel?' selected':'')+'>'+a[1]+'</option>';}).join(''); }
  function datalistItems(){ return ITEMS.map(function(it){return '<option value="'+esc(it.n)+'">';}).join(''); }
  var dl=document.createElement('datalist'); dl.id='weItemList'; dl.innerHTML=datalistItems(); document.body.appendChild(dl);

  function addRow(p){
    p=p||{};
    var art=p.kategorie||p.warenart||'rohstoff'; if(!MATRIX[art])art='rohstoff';
    var card=document.createElement('div'); card.className='we-pos';
    card.innerHTML=
      '<button type="button" class="btn btn-ghost btn-sm we-del" title="Zeile entfernen">×</button>'+
      '<div class="bx-grid">'+
        '<div class="bx-field f-art"><label>Artikel</label><input type="text" class="we-name" name="p_name[]" list="weItemList" autocomplete="off" value="'+esc(p.item_name||p.name||'')+'" placeholder="Artikel suchen oder neuen Namen eingeben"><input type="hidden" name="p_item[]" value="'+(p.item_id||0)+'"></div>'+
        '<div class="bx-field"><label>Warenart</label><select name="p_warenart[]" class="we-art">'+artOptions(art)+'</select></div>'+
        '<div class="bx-field"><label>Menge</label><input type="text" name="p_menge[]" inputmode="decimal" value="'+(p.menge&&p.menge>0?p.menge:'')+'" placeholder="0"></div>'+
        '<div class="bx-field"><label>Einheit</label><input type="text" name="p_einheit[]" value="'+esc(p.einheit||'')+'" placeholder="Stk"></div>'+
        '<div class="bx-field"><label class="lbl-charge">Charge-Nr.</label><input type="text" name="p_charge[]" class="we-charge" value="'+esc(p.charge_nr||'')+'"></div>'+
        '<div class="bx-field"><label class="lbl-mhd">MHD</label><input type="date" name="p_mhd[]" class="we-mhd" value="'+esc(p.mhd||'')+'"></div>'+
        '<div class="bx-field"><label>Blinker (Pflicht)</label><input type="text" name="p_blinker[]" class="we-blinker" value="" placeholder="Code scannen" required></div>'+
        '<div class="bx-field"><label>Pakete</label><input type="number" name="p_pakete[]" min="1" step="1" value="1"></div>'+
      '</div>';
    rows.appendChild(card);
    // Artikel-Name -> item_id, Einheit, Warenart aus Treffer uebernehmen.
    var name=card.querySelector('.we-name'), hid=card.querySelector('input[name="p_item[]"]'),
        art2=card.querySelector('.we-art'), einh=card.querySelector('input[name="p_einheit[]"]');
    name.addEventListener('input',function(){
      var m=ITEMS.filter(function(it){return it.n.toLowerCase()===name.value.trim().toLowerCase();})[0];
      if(m){ hid.value=m.id; if(!einh.value)einh.value=m.e||''; var a=m.f==='kapselhuelle'?'kapsel':m.k; if(MATRIX[a]){art2.value=a;} pflicht(card); }
      else { hid.value=0; }
    });
    art2.addEventListener('change',function(){pflicht(card);});
    card.querySelector('.we-del').addEventListener('click',function(){card.remove(); if(!rows.children.length)addRow();});
    pflicht(card);
    return card;
  }
  function pflicht(tr){
    var art=tr.querySelector('.we-art').value, reg=MATRIX[art]||{mhd:0,charge:0};
    var mhd=tr.querySelector('.we-mhd'), ch=tr.querySelector('.we-charge');
    mhd.required=!!reg.mhd; ch.required=!!reg.charge;
    tr.querySelector('.lbl-mhd').textContent    = reg.mhd    ? 'MHD (Pflicht)' : 'MHD';
    tr.querySelector('.lbl-charge').textContent = reg.charge ? 'Charge-Nr. (Pflicht)' : 'Charge-Nr.';
  }
  document.getElementById('weAdd').addEventListener('click',function(){addRow();});
  rows.addEventListener('input',function(e){ var tr=e.target.closest('.we-pos'); if(tr)pflicht(tr); });
  addRow(); // Startzeile

  // --- Scan: Dateien/Kamera sammeln + senden ---
  var dateien=[]; // {blob, name, isPdf}
  var thumbs=document.getElementById('weThumbs'), scanBtn=document.getElementById('weScan'), info=document.getElementById('weScanInfo');
  function renderThumbs(){
    thumbs.innerHTML='';
    dateien.forEach(function(d,i){
      var div=document.createElement('div'); div.className='we-thumb';
      if(d.isPdf){ div.innerHTML='<div class="pdf">PDF</div>'; }
      else { var img=document.createElement('img'); img.src=URL.createObjectURL(d.blob); div.appendChild(img); }
      var x=document.createElement('button'); x.className='x'; x.textContent='×'; x.type='button';
      x.onclick=function(){dateien.splice(i,1);renderThumbs();}; div.appendChild(x);
      thumbs.appendChild(div);
    });
    scanBtn.disabled = dateien.length===0;
    info.textContent = dateien.length ? dateien.length+' Seite(n) bereit' : '';
  }
  document.getElementById('weFile').addEventListener('change',function(e){
    [].forEach.call(e.target.files,function(f){ dateien.push({blob:f,name:f.name,isPdf:/pdf$/i.test(f.type)||/\.pdf$/i.test(f.name)}); });
    e.target.value=''; renderThumbs();
  });

  // Kamera
  var stream=null, camBox=document.getElementById('weCamBox'), video=document.getElementById('weVideo'), canvas=document.getElementById('weCanvas');
  document.getElementById('weCamStart').addEventListener('click',function(){
    navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}}).then(function(s){
      stream=s; video.srcObject=s; video.play(); camBox.style.display='';
    }).catch(function(){ info.textContent='Kamera nicht verfügbar – bitte Datei wählen.'; });
  });
  function camStop(){ if(stream){stream.getTracks().forEach(function(t){t.stop();});stream=null;} camBox.style.display='none'; }
  document.getElementById('weCamStop').addEventListener('click',camStop);
  document.getElementById('weShot').addEventListener('click',function(){
    canvas.width=video.videoWidth; canvas.height=video.videoHeight;
    canvas.getContext('2d').drawImage(video,0,0);
    canvas.toBlob(function(b){ dateien.push({blob:b,name:'foto'+(dateien.length+1)+'.jpg',isPdf:false}); renderThumbs(); },'image/jpeg',0.85);
  });

  // Auslesen
  scanBtn.addEventListener('click',function(){
    if(!dateien.length)return;
    scanBtn.disabled=true; info.textContent='Lese Lieferschein …';
    var fd=new FormData(); fd.append('aktion','scan');
    dateien.forEach(function(d){ fd.append('dateien[]', d.blob, d.name); });
    fetch('?p=we',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
      scanBtn.disabled=false;
      if(!j.ok){ info.textContent='Fehler: '+(j.fehler||'unbekannt'); return; }
      // Lieferant/LS vorbelegen (Text) + Positionen in die Tabelle.
      info.textContent = (j.kopf.lieferant?('Lieferant: '+j.kopf.lieferant+'  '):'') + (j.positionen.length+' Position(en) erkannt');
      rows.innerHTML='';
      if(!j.positionen.length){ addRow(); }
      else j.positionen.forEach(function(p){ addRow(p); });
    }).catch(function(){ scanBtn.disabled=false; info.textContent='Netzwerk-/Serverfehler beim Auslesen.'; });
  });
  window.addEventListener('beforeunload',camStop);
})();
</script>
<?php
fuss();
