<?php
// Wareneingang (Warenlager-Manager): Artikel (bestehend ODER neu anlegen) + Menge buchen,
// Blinker ist PFLICHT -> Charge anlegen, Blinker anhaengen, fertig. Scanner-freundlich.
// Schreibt ueber erp_wareneingang_buchen()/erp_item_anlegen() (die Naht) und protokolliert in lg_bewegung.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'buchen') {
    // Blinker (Pflicht) ZUERST pruefen – damit bei ungueltigem Code kein verwaister Artikel entsteht.
    $blinker = led_leiste_normalisieren((string)($_POST['blinker'] ?? ''));
    if ($blinker === null) { flash('Blinker ist Pflicht: bitte den Blinker-Code scannen oder eingeben (6 Zeichen, z. B. AFC709).', 'warn'); weiter('?p=eingang'); }
    $menge = (float) str_replace(',', '.', trim((string)($_POST['menge'] ?? '0')));
    if ($menge <= 0) { flash('Bitte eine Menge größer 0 angeben.', 'warn'); weiter('?p=eingang'); }

    $item_id = (int)($_POST['item_id'] ?? 0);
    // Kein bestehender Artikel gewaehlt, aber ein Name getippt -> neuen Artikel anlegen.
    if (!$item_id) {
        $neuName = trim((string)($_POST['art_text'] ?? ''));
        if ($neuName !== '') {
            $item_id = (int) erp_item_anlegen($neuName,
                (string)($_POST['neu_kategorie'] ?? 'rohstoff'),
                (string)($_POST['neu_einheit'] ?? ''));
        }
    }
    if (!$item_id) { flash('Bitte einen Artikel wählen – oder einen Namen für einen neuen Artikel eingeben.', 'warn'); weiter('?p=eingang'); }

    $lief   = ($_POST['lieferant_id'] ?? '') !== '' ? (int)$_POST['lieferant_id'] : null;
    $pakete = max(1, (int)($_POST['pakete'] ?? 1));
    $cid = erp_wareneingang_buchen($item_id, $menge, trim((string)($_POST['charge_nr'] ?? '')),
        trim((string)($_POST['mhd'] ?? '')) ?: null, $lief, trim((string)($_POST['notiz'] ?? '')));
    if (!$cid) { flash('Bitte Artikel und eine Menge größer 0 angeben.', 'warn'); weiter('?p=eingang'); }
    lg_pakete_set((int)$cid, $pakete);
    $c = erp_charge((int)$cid);
    lg_bewegung_log((int)$cid, 'ein', $menge, $c['einheit'] ?? null, (string)($c['item_name'] ?? ''), 'Wareneingang');

    // Blinker (Pflicht) an die Charge haengen und zur Bestaetigung kurz gruen blinken.
    $bfehler = leiste_binden($blinker, (int)$cid);
    if ($bfehler === '') {
        $lr = leiste_per_code($blinker);
        if ($lr) leiste_finden((int)$lr['id'], 'gruen', 3, false);
        flash('Eingebucht. Blinker ' . $blinker . ' hängt dran und leuchtet kurz grün.');
    } else {
        flash('Eingebucht – aber der Blinker konnte nicht angehängt werden: ' . $bfehler, 'warn');
    }
    weiter('?p=charge&id=' . (int)$cid . '&neu=1');
}

$items       = erp_items_eingang();
$lieferanten = erp_lieferanten();
$letzte      = lg_bewegungen(12);

// Vorbefuellung (z. B. aus "Erwartete Lieferungen" -> Einbuchen): Artikel/Menge/Lieferant/Charge.
$vorItem   = (int)($_GET['item'] ?? 0);
$vorBasis  = $vorItem ? erp_item_basis($vorItem) : null;
if (!$vorBasis) $vorItem = 0;
$vorMenge  = trim((string)($_GET['menge'] ?? ''));
$vorCharge = trim((string)($_GET['charge'] ?? ''));
$vorLief   = (int)($_GET['lieferant'] ?? 0);

kopf('Einbuchen', 'eingang');
seitenkopf('Einbuchen', 'Was kommt rein? Artikel wählen oder neu anlegen, Menge + MHD + Blinker – fertig.',
    '<a class="btn btn-ghost" href="?p=bestand">Zum Bestand</a>');
flash_zeigen();
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
      .lg-acombo-list .opt .muted{font-size:12px}
      .lg-acombo-list .opt.neu{color:var(--gruen,#1D9E75);font-weight:600}
      .lg-acombo-empty{padding:9px 12px;color:var(--muted);font-size:13px}
      #weNeu{border:1px dashed var(--gruen,#1D9E75);border-radius:10px;padding:12px;margin:6px 0 2px}
    </style>
    <div class="bx-grid">
      <div class="bx-field lg-acombo" id="weArtWrap"><label>Artikel</label>
        <input type="text" id="weArtSuche" name="art_text" autocomplete="off" placeholder="Artikel suchen – oder neuen Namen eingeben…" required aria-expanded="false" value="<?= h((string)($vorBasis['name'] ?? '')) ?>">
        <input type="hidden" name="item_id" id="weArtId" value="<?= $vorItem ?: '' ?>">
        <div id="weArtList" class="lg-acombo-list" hidden></div>
      </div>
      <div class="bx-field"><label>Menge</label>
        <div class="bx-row" style="gap:8px;align-items:center;margin:0">
          <input type="text" inputmode="decimal" name="menge" required style="flex:1;min-width:0" placeholder="z. B. 25" value="<?= h($vorMenge) ?>">
          <span id="weEinheit" style="min-width:44px;font-weight:600;color:var(--muted)"><?= h((string)($vorBasis['einheit'] ?? '–')) ?></span>
        </div>
      </div>
      <div class="bx-field"><label>Blinker <span class="muted">(Pflicht)</span></label>
        <input type="text" name="blinker" id="weBlinker" class="lg-code" required placeholder="Blinker-Code scannen oder eingeben (z. B. AFC709)">
      </div>
      <div class="bx-field"><label>MHD</label><input type="date" name="mhd"></div>
      <div class="bx-field"><label>Charge-Nr. (Lieferant)</label>
        <input type="text" name="charge_nr" class="lg-code" placeholder="laut Lieferant / CoA (optional)" value="<?= h($vorCharge) ?>"></div>
      <div class="bx-field"><label>Anzahl Pakete / Kartons</label>
        <input type="number" name="pakete" min="1" step="1" value="1"></div>
      <div class="bx-field"><label>Lieferant</label>
        <select name="lieferant_id">
          <option value="">– keiner –</option>
          <?php foreach ($lieferanten as $lf): ?><option value="<?= (int)$lf['id'] ?>" <?= $vorLief === (int)$lf['id'] ? 'selected' : '' ?>><?= h((string)$lf['firma']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Notiz (optional)</label><input type="text" name="notiz" placeholder="z. B. Teillieferung"></div>
    </div>

    <!-- Neuer Artikel: nur sichtbar, wenn ein Name getippt wird, der nicht in der Liste steht. -->
    <div id="weNeu" hidden>
      <div class="muted" style="margin-bottom:8px">Neuer Artikel „<span id="weNeuName"></span>" wird angelegt. Bitte Kategorie und Einheit festlegen:</div>
      <div class="bx-row" style="gap:12px;flex-wrap:wrap">
        <div class="bx-field" style="margin:0"><label>Kategorie</label>
          <select name="neu_kategorie" id="weNeuKat">
            <option value="rohstoff">Rohstoff</option>
            <option value="verpackung">Verpackung</option>
            <option value="verbrauch">Verbrauch</option>
            <option value="fertig">Bulk / lose</option>
            <option value="verkaufsfertig">Fertiges Produkt</option>
          </select>
        </div>
        <div class="bx-field" style="margin:0"><label>Einheit</label>
          <input type="text" name="neu_einheit" id="weNeuEinheit" placeholder="z. B. kg, Stück, L" style="max-width:160px">
        </div>
      </div>
    </div>

    <div class="muted" style="margin:10px 0 12px">Rohstoffe, Bulk und fertige Produkte gehen zunächst in <strong>Quarantäne</strong> (auf der Charge-Seite freigeben). Verpackung/Verbrauch sind sofort frei.</div>
    <button class="btn btn-primary" type="submit">Buchen &amp; einlagern</button>
  </div>
</form>

<?php if ($letzte): ?>
<div class="bx-panel">
  <h2>Zuletzt bewegt</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Zeit</th><th>Richtung</th><th>Artikel</th><th>Menge</th></tr></thead>
    <tbody>
    <?php foreach ($letzte as $b): ?>
      <tr>
        <td class="muted"><?= h(fmt_zeit((string)$b['angelegt'], 'd.m. H:i')) ?></td>
        <td><?= $b['typ'] === 'ein' ? '<span class="badge badge-ok">Eingang</span>' : '<span class="badge badge-warn">Ausgang</span>' ?></td>
        <td><?php if (!empty($b['charge_id'])): ?><a class="lg-namelink" href="?p=charge&id=<?= (int)$b['charge_id'] ?>"><?= h((string)$b['item_name']) ?></a><?php else: ?><?= h((string)$b['item_name']) ?><?php endif; ?></td>
        <td><?= h(menge_txt($b['menge'])) ?> <?= h((string)$b['einheit']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div style="margin-top:10px"><a class="btn btn-ghost btn-sm" href="?p=bewegungen">Alle Bewegungen</a></div>
</div>
<?php endif; ?>

<script>
(function(){
  var items = <?= json_encode(array_map(fn($it)=>['id'=>(int)$it['id'],'n'=>(string)$it['name'],'e'=>(string)$it['einheit'],'k'=>(string)$it['kategorie'],'f'=>(string)($it['form']??'')], $items), JSON_UNESCAPED_UNICODE) ?>;
  var box=document.getElementById('weArtSuche'), hid=document.getElementById('weArtId'), list=document.getElementById('weArtList');
  var einhEl=document.getElementById('weEinheit');
  var neuBox=document.getElementById('weNeu'), neuName=document.getElementById('weNeuName'),
      neuKat=document.getElementById('weNeuKat'), neuEinheit=document.getElementById('weNeuEinheit');
  if(!box||!hid||!list) return;
  var katLbl={rohstoff:'Rohstoff',verpackung:'Verpackung',verbrauch:'Verbrauch',fertig:'Bulk / lose',verkaufsfertig:'Fertiges Produkt'};
  function lbl(it){ return it.f==='kapselhuelle' ? 'Kapseln' : (katLbl[it.k]||it.k); }
  var hl=-1, shown=[];
  function esc(s){ return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function setEinheit(e){ einhEl.textContent=e||'–'; }
  function exakt(q){ q=(q||'').trim().toLowerCase(); return items.some(function(it){ return it.n.toLowerCase()===q; }); }
  function neuToggle(){
    var q=(box.value||'').trim();
    var neu = q!=='' && !hid.value && !exakt(q);
    neuBox.hidden=!neu;
    if(neu){ neuName.textContent=q; neuEinheit.required=true; setEinheit(neuEinheit.value||''); }
    else { neuEinheit.required=false; }
  }
  function render(q){
    q=(q||'').trim().toLowerCase();
    shown=items.filter(function(it){ return !q || it.n.toLowerCase().indexOf(q)>=0; }).slice(0,60);
    var html = shown.length ? shown.map(function(it,i){ return '<div class="opt" data-i="'+i+'">'+esc(it.n)+' <span class="muted">('+esc(it.e||'')+' · '+esc(lbl(it))+')</span></div>'; }).join('') : '';
    if(q!=='' && !exakt(q)) html += '<div class="opt neu" data-neu="1">+ Neuen Artikel „'+esc(box.value.trim())+'" anlegen</div>';
    list.innerHTML = html || '<div class="lg-acombo-empty">Tippen, um zu suchen …</div>';
    hl=-1; list.hidden=false; box.setAttribute('aria-expanded','true');
  }
  function paint(){ Array.prototype.forEach.call(list.querySelectorAll('.opt'),function(o){o.classList.toggle('hl',+o.dataset.i===hl);}); var el=list.querySelector('.opt.hl'); if(el) el.scrollIntoView({block:'nearest'}); }
  function choose(i){ var it=shown[i]; if(!it) return; hid.value=it.id; box.value=it.n; box.setCustomValidity(''); setEinheit(it.e); neuToggle(); close(); }
  function waehleNeu(){ hid.value=''; close(); neuToggle(); neuEinheit.focus(); }
  function close(){ list.hidden=true; box.setAttribute('aria-expanded','false'); }
  box.addEventListener('input', function(){ hid.value=''; setEinheit(''); render(box.value); neuToggle(); });
  box.addEventListener('focus', function(){ render(box.value); });
  box.addEventListener('keydown', function(e){
    if(list.hidden){ if(e.key==='ArrowDown') render(box.value); return; }
    if(e.key==='ArrowDown'){ e.preventDefault(); hl=Math.min(hl+1,shown.length-1); paint(); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); hl=Math.max(hl-1,0); paint(); }
    else if(e.key==='Enter'){ if(hl>=0){ e.preventDefault(); choose(hl); } }
    else if(e.key==='Escape'){ close(); }
  });
  list.addEventListener('mousedown', function(e){ var o=e.target.closest('.opt'); if(!o) return; e.preventDefault(); if(o.dataset.neu){ waehleNeu(); } else { choose(+o.dataset.i); } });
  if(neuEinheit) neuEinheit.addEventListener('input', function(){ setEinheit(neuEinheit.value); });
  document.addEventListener('click', function(e){ if(!e.target.closest('#weArtWrap')) close(); });
  // Submit: entweder bestehender Artikel (hid) ODER neuer Name (art_text) ist ok.
  box.form.addEventListener('submit', function(e){ box.setCustomValidity(''); if(!hid.value && (box.value||'').trim()===''){ e.preventDefault(); box.setCustomValidity('Bitte Artikel wählen oder Namen eingeben.'); box.reportValidity(); } });
  neuToggle();
})();
</script>
<?php
fuss();
