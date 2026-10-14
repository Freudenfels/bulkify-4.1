<?php
// Lieferschein-Generator – einfache Erfassung wie beim Angebot: Kunde + Lieferadresse wählen, Produkte aus
// dem Katalog mit Kartons × Menge/Karton, Paket oder Palette. Versicherungswert (EK, sonst VK/2) und Gewicht
// rechnen sich automatisch. Beim Speichern entsteht eine Sendung (lg_versand) -> Lieferschein-PDF/Druck und
// später DHL/Cargoboard im Versand-Detail. Route: ?p=lieferschein_neu

// AJAX: Adressen + Katalog eines Kunden nachladen (wenn der Kunde gewählt wird).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'laden') {
    $kid = (int)($_POST['kunde_id'] ?? 0);
    json_antwort(['ok' => true, 'adressen' => erp_kunde_adressen($kid), 'katalog' => erp_versandkatalog($kid)]);
}

// Speichern: Sendung + Positionen anlegen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'erstellen') {
    $prod = $_POST['p_produkt'] ?? []; $item = $_POST['p_item'] ?? []; $bez = $_POST['p_bez'] ?? [];
    $kart = $_POST['p_kartons'] ?? []; $mk = $_POST['p_mengekarton'] ?? [];
    $einz = $_POST['p_einzelwert'] ?? []; $gew = $_POST['p_gewicht'] ?? [];
    $positionen = [];
    foreach ($bez as $i => $b) {
        $b = trim((string)$b); if ($b === '') continue;
        $positionen[] = [
            'produkt_id'      => (int)($prod[$i] ?? 0) ?: null,
            'item_id'         => (int)($item[$i] ?? 0) ?: null,
            'bezeichnung'     => $b,
            'kartons'         => (int)($kart[$i] ?? 0),
            'menge_karton'    => (string)($mk[$i] ?? ''),
            'einzelwert_cent' => (int)round((float)str_replace(',', '.', (string)($einz[$i] ?? 0)) * 100),
            'gewicht_g'       => (int)($gew[$i] ?? 0),
            'einheit'         => 'Stk',
        ];
    }
    if (!$positionen) { flash('Bitte mindestens eine Position erfassen.', 'warn'); weiter('?p=lieferschein_neu'); }
    $vicCent = trim((string)($_POST['versicherung'] ?? '')) !== '' ? (int)round((float)str_replace(',', '.', (string)$_POST['versicherung']) * 100) : '';
    $vid = lg_lieferschein_erstellen([
        'kunde_id' => (int)($_POST['kunde_id'] ?? 0) ?: null,
        'empf_firma' => trim($_POST['empf_firma'] ?? ''), 'empf_name' => trim($_POST['empf_name'] ?? ''),
        'empf_strasse' => trim($_POST['empf_strasse'] ?? ''), 'empf_hausnummer' => trim($_POST['empf_hausnummer'] ?? ''),
        'empf_plz' => trim($_POST['empf_plz'] ?? ''), 'empf_ort' => trim($_POST['empf_ort'] ?? ''),
        'empf_land' => trim($_POST['empf_land'] ?? 'DE'), 'empf_email' => trim($_POST['empf_email'] ?? ''),
        'empf_telefon' => trim($_POST['empf_telefon'] ?? ''), 'adress_quelle' => trim($_POST['adress_quelle'] ?? 'frei'),
        'typ' => (($_POST['typ'] ?? 'paket') === 'palette' ? 'palette' : 'paket'),
        'pakete' => (int)($_POST['pakete'] ?? 0),
        'gewicht_kg' => trim((string)($_POST['gewicht_kg'] ?? '')),
        'versicherung_cent' => $vicCent,
        'notiz' => trim($_POST['notiz'] ?? ''),
        'positionen' => $positionen,
    ]);
    flash('Lieferschein ' . (string)(lg_versand($vid)['nummer'] ?? '') . ' erstellt.', 'ok');
    weiter('?p=versand_detail&id=' . (int)$vid . '&erstellt=1');
}

$kunden = erp_kunden_liste();

kopf('Lieferschein erstellen', 'versand');
seitenkopf('Lieferschein erstellen', 'Kunde und Produkte wählen – Versicherungswert und Gewicht rechnen sich automatisch.',
    '<a class="btn btn-ghost" href="?p=versand">Zur Sendungsliste</a>');
flash_zeigen();
?>
<form method="post" id="lsForm">
  <input type="hidden" name="aktion" value="erstellen">
  <input type="hidden" name="adress_quelle" id="adress_quelle" value="frei">

  <div class="bx-panel">
    <h2 style="margin-top:0">1. Kunde &amp; Lieferadresse</h2>
    <div class="lg-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
      <label>Kunde
        <select name="kunde_id" id="kunde_id" style="width:100%">
          <option value="">– Kunde wählen –</option>
          <?php foreach ($kunden as $k): ?><option value="<?= (int)$k['id'] ?>"><?= h($k['firma']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <div id="adressWahl" style="grid-column:1/-1"><div class="muted" style="font-size:13px">Adresse wird nach Kundenwahl vorgeschlagen – Felder sind frei änderbar.</div></div>
    </div>
    <div class="lg-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-top:10px">
      <label>Firma<input type="text" name="empf_firma" id="empf_firma" style="width:100%"></label>
      <label>Name / zu Händen<input type="text" name="empf_name" id="empf_name" style="width:100%"></label>
      <label>Straße<input type="text" name="empf_strasse" id="empf_strasse" style="width:100%"></label>
      <label>Hausnr.<input type="text" name="empf_hausnummer" id="empf_hausnummer" style="width:100%"></label>
      <label>PLZ<input type="text" name="empf_plz" id="empf_plz" style="width:100%"></label>
      <label>Ort<input type="text" name="empf_ort" id="empf_ort" style="width:100%"></label>
      <label>Land<input type="text" name="empf_land" id="empf_land" value="DE" maxlength="2" style="width:100%;text-transform:uppercase"></label>
      <label>E-Mail<input type="text" name="empf_email" id="empf_email" style="width:100%"></label>
      <label>Telefon<input type="text" name="empf_telefon" id="empf_telefon" style="width:100%"></label>
    </div>
  </div>

  <div class="bx-panel">
    <h2 style="margin-top:0">2. Produkte</h2>
    <div class="bx-tablewrap"><table class="bx-table" id="posTab">
      <thead><tr>
        <th style="min-width:220px">Produkt</th><th>Kartons</th><th>Menge / Karton</th>
        <th class="bx-num">Menge</th><th class="bx-num">Stückwert €</th><th class="bx-num">Gewicht</th><th></th>
      </tr></thead>
      <tbody id="posRows"></tbody>
    </table></div>
    <button type="button" class="btn btn-ghost btn-sm" id="addRow" style="margin-top:8px">+ Zeile</button>
    <div class="muted" style="font-size:12px;margin-top:6px">Stückwert = Einkaufspreis; fehlt er, wird der halbe Verkaufspreis (VK/2) genommen. Beides je Zeile änderbar.</div>
  </div>

  <div class="bx-panel">
    <h2 style="margin-top:0">3. Versand</h2>
    <div class="lg-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
      <label>Versandart
        <select name="typ" id="typ" style="width:100%"><option value="paket">Paket</option><option value="palette">Palette / Fracht</option></select>
      </label>
      <label>Pakete / Packstücke<input type="number" name="pakete" id="pakete" min="1" value="1" style="width:100%"></label>
      <label>Gewicht gesamt (kg)<input type="text" name="gewicht_kg" id="gewicht_kg" inputmode="decimal" style="width:100%"></label>
      <label>Versicherungswert (€)<input type="text" name="versicherung" id="versicherung" inputmode="decimal" style="width:100%"></label>
    </div>
    <div class="muted" style="font-size:12px;margin-top:6px">Pakete = Summe der Kartons (änderbar). Gewicht/Versicherung rechnen sich aus den Positionen – du kannst sie überschreiben. <span id="gewProPaket"></span></div>
    <label style="display:block;margin-top:10px">Hinweis / Notiz<textarea name="notiz" rows="2" style="width:100%"></textarea></label>
  </div>

  <div class="bx-row" style="gap:10px;margin:14px 0 40px">
    <button class="btn btn-primary" type="submit">Lieferschein erstellen</button>
    <a class="btn btn-ghost" href="?p=versand">Abbrechen</a>
  </div>
</form>

<template id="rowTpl">
  <tr class="posRow">
    <td>
      <select name="p_produkt[]" class="p_prod" style="width:100%"></select>
      <input type="hidden" name="p_item[]" class="p_item">
      <input type="hidden" name="p_bez[]" class="p_bez">
      <input type="hidden" name="p_gewicht[]" class="p_gewg">
    </td>
    <td><input type="number" name="p_kartons[]" class="p_kartons" min="0" step="1" style="width:72px"></td>
    <td><input type="text" name="p_mengekarton[]" class="p_mk" inputmode="decimal" style="width:90px"></td>
    <td class="bx-num c_menge">–</td>
    <td class="bx-num"><input type="text" name="p_einzelwert[]" class="p_einz" inputmode="decimal" style="width:80px;text-align:right"></td>
    <td class="bx-num c_gew">–</td>
    <td><button type="button" class="btn btn-ghost btn-sm c_del">×</button></td>
  </tr>
</template>

<script>
(function(){
  var KAT = [];                 // Katalog des gewaehlten Kunden
  var rowsEl = document.getElementById('posRows');
  var tpl = document.getElementById('rowTpl');
  var nf = function(n,d){ d=d||2; return (Math.round(n*Math.pow(10,d))/Math.pow(10,d)).toLocaleString('de-DE',{minimumFractionDigits:0,maximumFractionDigits:d}); };

  function fillSelect(sel){
    var cur = sel.value;
    sel.innerHTML = '<option value="">– Produkt wählen –</option>';
    KAT.forEach(function(p){
      var o = document.createElement('option');
      o.value = p.produkt_id; o.textContent = p.name + (p.nummer ? ' ('+p.nummer+')' : '');
      o.dataset.item = p.item_id||''; o.dataset.name = p.name;
      o.dataset.ek = p.ek_cent||0; o.dataset.vk = p.vk_cent||0; o.dataset.gew = p.gewicht_g||0;
      sel.appendChild(o);
    });
    if (cur) sel.value = cur;
  }
  function addRow(){
    var fr = tpl.content.cloneNode(true);
    rowsEl.appendChild(fr);
    var tr = rowsEl.lastElementChild;
    fillSelect(tr.querySelector('.p_prod'));
    tr.querySelector('.c_del').addEventListener('click', function(){ tr.remove(); recalc(); });
    ['.p_prod','.p_kartons','.p_mk','.p_einz'].forEach(function(s){
      tr.querySelector(s).addEventListener('input', function(){ rowChanged(tr); });
      tr.querySelector(s).addEventListener('change', function(){ rowChanged(tr); });
    });
    return tr;
  }
  function rowChanged(tr){
    var sel = tr.querySelector('.p_prod');
    var opt = sel.options[sel.selectedIndex];
    if (opt && opt.value){
      tr.querySelector('.p_item').value = opt.dataset.item||'';
      tr.querySelector('.p_bez').value = opt.dataset.name||'';
      tr.querySelector('.p_gewg').value = opt.dataset.gew||0;
      // Stückwert vorbelegen, wenn das Feld leer ist: EK, sonst VK/2.
      var einz = tr.querySelector('.p_einz');
      if (!einz.dataset.touched){
        var ek = parseInt(opt.dataset.ek||0,10), vk = parseInt(opt.dataset.vk||0,10);
        var cent = ek>0 ? ek : Math.round(vk/2);
        einz.value = cent>0 ? (cent/100).toString().replace('.',',') : '';
      }
    }
    recalc();
  }
  function recalc(){
    var vicSum=0, gewSum=0, kartSum=0;
    rowsEl.querySelectorAll('.posRow').forEach(function(tr){
      var kart = parseInt(tr.querySelector('.p_kartons').value||0,10)||0;
      var mk = parseFloat((tr.querySelector('.p_mk').value||'0').replace(',','.'))||0;
      var menge = kart>0 && mk>0 ? kart*mk : 0;
      var einz = parseFloat((tr.querySelector('.p_einz').value||'0').replace(',','.'))||0;
      var gewg = parseInt(tr.querySelector('.p_gewg').value||0,10)||0;
      tr.querySelector('.c_menge').textContent = menge>0 ? nf(menge,3)+' Stk' : '–';
      tr.querySelector('.c_gew').textContent = (gewg*menge)>0 ? nf(gewg*menge/1000,3)+' kg' : '–';
      vicSum += einz*menge; gewSum += gewg*menge; kartSum += kart;
    });
    var vicEl=document.getElementById('versicherung'), gewEl=document.getElementById('gewicht_kg'), pakEl=document.getElementById('pakete');
    if(!vicEl.dataset.touched) vicEl.value = vicSum>0 ? nf(vicSum,2) : '';
    if(!gewEl.dataset.touched) gewEl.value = gewSum>0 ? nf(gewSum/1000,3) : '';
    if(!pakEl.dataset.touched && kartSum>0) pakEl.value = kartSum;
    var pk = parseInt(pakEl.value||0,10)||0;
    document.getElementById('gewProPaket').textContent = (gewSum>0 && pk>0) ? ('≈ '+nf(gewSum/1000/pk,3)+' kg je Paket') : '';
  }
  ['versicherung','gewicht_kg','pakete'].forEach(function(id){ var el=document.getElementById(id); el.addEventListener('input',function(){el.dataset.touched='1';recalc();}); });
  document.getElementById('addRow').addEventListener('click', function(){ addRow(); });

  // Kunde gewaehlt -> Adressen + Katalog laden
  document.getElementById('kunde_id').addEventListener('change', function(){
    var kid = this.value;
    var fd = new FormData(); fd.append('aktion','laden'); fd.append('kunde_id', kid||0);
    fetch('?p=lieferschein_neu', {method:'POST', body:fd}).then(function(r){return r.json();}).then(function(j){
      if(!j || !j.ok) return;
      KAT = j.katalog||[];
      rowsEl.querySelectorAll('.p_prod').forEach(fillSelect);
      // Adressen als Auswahl
      var box = document.getElementById('adressWahl'); box.innerHTML='';
      (j.adressen||[]).forEach(function(a,idx){
        var id='adr'+idx;
        var lbl=document.createElement('label'); lbl.style.cssText='display:inline-flex;gap:6px;align-items:center;margin:0 14px 6px 0;font-size:13px';
        lbl.innerHTML='<input type="radio" name="adrwahl" value="'+idx+'"'+(a.bevorzugt?' checked':'')+'> '+(a.label||'Adresse')+': '+[a.strasse,a.hausnummer].filter(Boolean).join(' ')+', '+[a.plz,a.ort].filter(Boolean).join(' ');
        lbl.querySelector('input').addEventListener('change', function(){ setAdr(a); });
        box.appendChild(lbl);
        if(a.bevorzugt) setAdr(a);
      });
      if(!(j.adressen||[]).length) box.innerHTML='<div class="muted" style="font-size:13px">Keine Adresse hinterlegt – bitte unten eintragen.</div>';
    });
  });
  function setAdr(a){
    document.getElementById('empf_firma').value=a.firma||''; document.getElementById('empf_name').value=a.name||'';
    document.getElementById('empf_strasse').value=a.strasse||''; document.getElementById('empf_hausnummer').value=a.hausnummer||'';
    document.getElementById('empf_plz').value=a.plz||''; document.getElementById('empf_ort').value=a.ort||'';
    document.getElementById('empf_land').value=a.land||'DE'; document.getElementById('empf_email').value=a.email||'';
    document.getElementById('empf_telefon').value=a.telefon||''; document.getElementById('adress_quelle').value=a.quelle||'frei';
  }
  // Markiere Stückwert als „von Hand" sobald getippt, damit die Automatik ihn nicht ueberschreibt.
  rowsEl.addEventListener('input', function(e){ if(e.target.classList.contains('p_einz')) e.target.dataset.touched='1'; });
  addRow(); addRow();
})();
</script>
<?php fuss();
