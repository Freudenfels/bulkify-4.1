<?php
// Wareneingang (Warenlager-Manager): Artikel + Menge buchen -> Charge anlegen -> direkt einlagern.
// Schreibt ueber erp_wareneingang_buchen() (die Naht) und protokolliert in lg_bewegung.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'buchen') {
    $item_id = (int)($_POST['item_id'] ?? 0);
    $menge   = (float) str_replace(',', '.', trim((string)($_POST['menge'] ?? '0')));
    $lief    = ($_POST['lieferant_id'] ?? '') !== '' ? (int)$_POST['lieferant_id'] : null;
    $pakete  = max(1, (int)($_POST['pakete'] ?? 1));
    $cid = erp_wareneingang_buchen($item_id, $menge, trim((string)($_POST['charge_nr'] ?? '')),
        trim((string)($_POST['mhd'] ?? '')) ?: null, $lief, trim((string)($_POST['notiz'] ?? '')));
    if (!$cid) { flash('Bitte Artikel und eine Menge größer 0 angeben.', 'warn'); weiter('?p=eingang'); }
    lg_pakete_set((int)$cid, $pakete);
    $c = erp_charge((int)$cid);
    lg_bewegung_log((int)$cid, 'ein', $menge, $c['einheit'] ?? null, (string)($c['item_name'] ?? ''), 'Wareneingang');
    // Direkt zum Einlagern: die Charge-Detailseite hat die Blinker-/Kisten-Zuweisung.
    flash('Wareneingang gebucht. Jetzt einen Blinker anhängen oder in eine Kiste legen.');
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

kopf('Wareneingang', 'eingang');
seitenkopf('Wareneingang', 'Was kommt rein? Artikel und Menge buchen – danach gleich einlagern.',
    '<a class="btn btn-ghost" href="?p=bestand">Zum Bestand</a>');
flash_zeigen();

if (!$items) { hinweis('Noch keine buchbaren Artikel im Dashboard (Rohstoffe/Verpackung/Fertigware).', 'warn'); fuss(); return; }
?>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="buchen">
  <div class="bx-panel">
    <style>
      .lg-combo{position:relative}
      .lg-combo-list{position:absolute;left:0;right:0;top:100%;z-index:30;max-height:300px;overflow:auto;
        background:var(--panel,#fff);border:1px solid var(--line,#ddd);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.14);margin-top:3px}
      .lg-combo-list .opt{padding:9px 12px;cursor:pointer;font-size:15px}
      .lg-combo-list .opt:hover,.lg-combo-list .opt.hl{background:var(--panel-2,#f2f2f0)}
      .lg-combo-list .opt .muted{font-size:12px}
      .lg-combo-empty{padding:9px 12px;color:var(--muted);font-size:13px}
    </style>
    <div class="bx-grid">
      <div class="bx-field lg-combo" id="weArtWrap"><label>Artikel</label>
        <input type="text" id="weArtSuche" autocomplete="off" placeholder="Artikel suchen oder wählen…" required aria-expanded="false" value="<?= h((string)($vorBasis['name'] ?? '')) ?>">
        <input type="hidden" name="item_id" id="weArtId" value="<?= $vorItem ?: '' ?>">
        <div id="weArtList" class="lg-combo-list" hidden></div>
      </div>
      <div class="bx-field"><label>Menge</label>
        <div class="bx-row" style="gap:8px;align-items:center;margin:0">
          <input type="text" inputmode="decimal" name="menge" required style="flex:1;min-width:0" placeholder="z. B. 25" value="<?= h($vorMenge) ?>">
          <span id="weEinheit" style="min-width:44px;font-weight:600;color:var(--muted)"><?= h((string)($vorBasis['einheit'] ?? '–')) ?></span>
        </div>
        <div class="muted" id="weEinheitHint" style="font-size:12px;margin-top:4px"><?= $vorBasis ? 'Menge in ' . h((string)$vorBasis['einheit']) . ' eingeben.' : 'Erst Artikel wählen – die Einheit erscheint hier.' ?></div>
      </div>
      <div class="bx-field"><label>Charge-Nr. (Lieferant)</label>
        <input type="text" name="charge_nr" class="lg-code" placeholder="laut Lieferant / CoA (optional)" value="<?= h($vorCharge) ?>"></div>
      <div class="bx-field"><label>MHD</label><input type="date" name="mhd"></div>
      <div class="bx-field"><label>Anzahl Pakete / Kartons</label>
        <input type="number" name="pakete" min="1" step="1" value="1">
        <div class="muted" style="font-size:12px;margin-top:4px">Je Karton wird ein Etikett gedruckt („Karton 1 / N").</div></div>
      <div class="bx-field"><label>Lieferant</label>
        <select name="lieferant_id">
          <option value="">– keiner –</option>
          <?php foreach ($lieferanten as $lf): ?><option value="<?= (int)$lf['id'] ?>" <?= $vorLief === (int)$lf['id'] ? 'selected' : '' ?>><?= h((string)$lf['firma']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Notiz (optional)</label><input type="text" name="notiz" placeholder="z. B. Teillieferung"></div>
    </div>
    <div class="muted" style="margin:4px 0 12px">Rohstoffe und Fertigware gehen zunächst in <strong>Quarantäne</strong> (auf der Charge-Seite freigeben). Verpackung/Verbrauch sind sofort frei.</div>
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
  var einhEl=document.getElementById('weEinheit'), einhHint=document.getElementById('weEinheitHint');
  if(!box||!hid||!list) return;
  var katLbl={rohstoff:'Rohstoff',verpackung:'Verpackung',verbrauch:'Verbrauch',fertig:'Fertigware',verkaufsfertig:'Fertigware'};
  function lbl(it){ return it.f==='kapselhuelle' ? 'Kapseln' : (katLbl[it.k]||it.k); }
  var hl=-1, shown=[];
  function esc(s){ return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function setEinheit(e){ einhEl.textContent=e||'–'; einhHint.textContent=e?('Menge in '+e+' eingeben.'):'Erst Artikel wählen – die Einheit erscheint hier.'; }
  function render(q){
    q=(q||'').trim().toLowerCase();
    shown=items.filter(function(it){ return !q || it.n.toLowerCase().indexOf(q)>=0; }).slice(0,60);
    if(!shown.length){ list.innerHTML='<div class="lg-combo-empty">Kein Artikel gefunden.</div>'; }
    else list.innerHTML=shown.map(function(it,i){ return '<div class="opt" data-i="'+i+'">'+esc(it.n)+' <span class="muted">('+esc(it.e||'')+' · '+esc(lbl(it))+')</span></div>'; }).join('');
    hl=-1; list.hidden=false; box.setAttribute('aria-expanded','true');
  }
  function paint(){ Array.prototype.forEach.call(list.querySelectorAll('.opt'),function(o){o.classList.toggle('hl',+o.dataset.i===hl);}); var el=list.querySelector('.opt.hl'); if(el) el.scrollIntoView({block:'nearest'}); }
  function choose(i){ var it=shown[i]; if(!it) return; hid.value=it.id; box.value=it.n; box.setCustomValidity(''); setEinheit(it.e); close(); }
  function close(){ list.hidden=true; box.setAttribute('aria-expanded','false'); }
  box.addEventListener('input', function(){ hid.value=''; setEinheit(''); render(box.value); });
  box.addEventListener('focus', function(){ render(box.value); });
  box.addEventListener('keydown', function(e){
    if(list.hidden){ if(e.key==='ArrowDown') render(box.value); return; }
    if(e.key==='ArrowDown'){ e.preventDefault(); hl=Math.min(hl+1,shown.length-1); paint(); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); hl=Math.max(hl-1,0); paint(); }
    else if(e.key==='Enter'){ if(hl>=0){ e.preventDefault(); choose(hl); } }
    else if(e.key==='Escape'){ close(); }
  });
  list.addEventListener('mousedown', function(e){ var o=e.target.closest('.opt'); if(o){ e.preventDefault(); choose(+o.dataset.i); } });
  document.addEventListener('click', function(e){ if(!e.target.closest('#weArtWrap')) close(); });
  box.form.addEventListener('submit', function(e){ if((box.value||'').trim()==='') return; if(!hid.value){ e.preventDefault(); box.setCustomValidity('Bitte einen Artikel aus der Liste wählen.'); box.reportValidity(); } });
})();
</script>
<?php
fuss();
