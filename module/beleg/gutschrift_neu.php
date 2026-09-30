<?php
// Storno-Rechnung / Gutschrift manuell erstellen: Kunde + Datum + Positionen (wie beim Angebot).
// Für alte Bestellungen, aus denen etwas herausstorniert werden soll.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'gutschrift_save') {
    $kid   = (int)($_POST['kunde_id'] ?? 0);
    $datum = trim($_POST['datum'] ?? '') !== '' ? $_POST['datum'] : date('Y-m-d');
    $grund = trim($_POST['grund'] ?? '');
    // Nummer der Ursprungsrechnung: verknuepft die Storno-Rechnung (storno_von_id) und befuellt Kunde/Bezug.
    $stornoNr  = trim($_POST['storno_nr'] ?? '');
    $stornoVon = null;
    if ($stornoNr !== '') {
        $rb = one("SELECT id, kunde_id, nummer FROM beleg WHERE nummer=? AND typ='rechnung' LIMIT 1", [$stornoNr]);
        if ($rb) { $stornoVon = (int)$rb['id']; if (!$kid) $kid = (int)$rb['kunde_id']; if ($grund === '') $grund = 'Storno zu Rechnung ' . $rb['nummer']; }
        elseif ($grund === '') { $grund = 'Storno zu Rechnung ' . $stornoNr; }   // Nummer trotzdem als Bezug uebernehmen
    }
    $positionen = [];
    foreach (($_POST['p_bez'] ?? []) as $i => $bez) {
        $bez = trim((string)$bez);
        if ($bez === '') continue;
        $menge = (float) str_replace(',', '.', $_POST['p_menge'][$i] ?? '0');
        $preis = (float) str_replace(',', '.', $_POST['p_preis'][$i] ?? '0');
        $mwst  = (float) str_replace(',', '.', $_POST['p_mwst'][$i] ?? '0');
        if ($menge <= 0) $menge = 1;
        $positionen[] = [
            'artikelnr'   => trim((string)($_POST['p_artikelnr'][$i] ?? '')),
            'bezeichnung' => $bez,
            'beschreibung'=> trim((string)($_POST['p_besch'][$i] ?? '')),
            'menge'       => $menge,
            'einheit'     => trim((string)($_POST['p_einheit'][$i] ?? '')) ?: 'Stk.',
            'preis_cent'  => -abs((int) round($preis * 100)),   // Gutschrift = negativer Betrag
            'mwst_satz'   => $mwst,
        ];
    }
    if (!$kid)            $fehler = 'Bitte einen Kunden wählen.';
    elseif (!$positionen) $fehler = 'Bitte mindestens eine Position mit Bezeichnung angeben.';
    else {
        $bid = gutschrift_erstellen($kid, $datum, $positionen, $grund, $stornoVon);
        header('Location: ?p=rechnung&id=' . $bid . '&gespeichert=1'); exit;
    }
}

$kunden  = all("SELECT id, firma, kundennummer FROM kunden ORDER BY firma");
$ustStd  = rtrim(rtrim(number_format((float) meta_get('ust_inland', 19), 2, '.', ''), '0'), '.');
$vorKid  = (int)($_GET['kunde_id'] ?? ($_POST['kunde_id'] ?? 0));

render_header('rechnungen', 'Storno-Rechnung');
bx_head('Storno-Rechnung / Gutschrift', 'Kunde, Datum und Positionen angeben – wie beim Angebot', bx_btn('Zurück zu Rechnungen', '?p=rechnungen', 'ghost'));
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($fehler) . '</div>';
?>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="gutschrift_save">
  <div class="bx-panel">
    <div class="bx-grid">
      <div class="bx-field"><label>Kunde</label>
        <select name="kunde_id" required>
          <option value="">– Kunde wählen –</option>
          <?php foreach ($kunden as $k): ?>
            <option value="<?= (int)$k['id'] ?>" <?= $vorKid===(int)$k['id']?'selected':'' ?>><?= h($k['firma']) ?><?= $k['kundennummer'] ? ' · '.h($k['kundennummer']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Datum</label><input type="date" name="datum" value="<?= h(date('Y-m-d')) ?>"></div>
      <div class="bx-field"><label>Storno zu Rechnung (Nummer) <?= bx_hint('Nummer der Ursprungsrechnung, z. B. RE-2026-0123. Wird verknüpft und auf dem Beleg als „Storno zu Rechnung …" ausgewiesen; Kunde wird übernommen, falls leer.') ?></label><input type="text" name="storno_nr" value="<?= h((string)($_POST['storno_nr'] ?? '')) ?>" placeholder="z. B. RE-2026-0123"></div>
      <div class="bx-field"><label>Grund / Bezug (optional) <?= bx_hint('Erscheint zusätzlich auf dem Beleg. Leer = automatisch „Storno zu Rechnung <Nummer>".') ?></label><input type="text" name="grund" placeholder="z. B. Teilstorno / falsche Menge"></div>
    </div>
  </div>

  <div class="bx-panel">
    <h2 style="margin-top:0">Positionen</h2>
    <p class="muted" style="margin-top:0">Preise als normale (positive) Beträge eintragen – der Beleg weist sie als Gutschrift (negativ) aus.</p>

    <details style="margin-bottom:12px">
      <summary style="cursor:pointer;color:var(--gruen)">Positionen aus Text einfügen (aus altem Angebot / alter Rechnung)</summary>
      <p class="muted" style="font-size:12px;margin:8px 0 4px">Text aus dem alten Angebot / der alten Rechnung kopieren und einfügen. „Positionen übernehmen" erkennt das Spaltenformat (Pos · Artikel-Nr. · Bezeichnung · Menge · Einheit · Einzelpreis · Gesamt). Klappt das nicht, nimmt <strong>„Roh übernehmen"</strong> einfach <strong>jede Zeile als eigene Position</strong> (Betrag am Zeilenende wird als Preis übernommen) – dann nur noch prüfen.</p>
      <textarea id="gsPaste" rows="6" style="width:100%;font-family:monospace;font-size:12px" placeholder="14 VCB 1.32.8 V Collagen Booster 1.000,00 Stk. 7,54 7.540,00&#10;V-COL® – 6 928 mg&#10;8g pro Tag, 30 Portionen&#10;15 STB 500g Standbodenbeutel 1.000,00 Stk. 0,75 750,00"></textarea>
      <div class="bx-row" style="margin-top:6px;gap:8px;align-items:center;flex-wrap:wrap">
        <button type="button" class="btn btn-primary btn-sm" id="gsPasteBtn">Positionen übernehmen</button>
        <button type="button" class="btn btn-ghost btn-sm" id="gsRawBtn">Roh übernehmen (1 Zeile = 1 Position)</button>
        <span class="muted" id="gsPasteInfo" style="font-size:12px"></span>
      </div>
    </details>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr>
        <th>Artikel-Nr.</th><th>Bezeichnung / Beschreibung</th>
        <th class="bx-num">Menge</th><th>Einheit</th><th class="bx-num">Einzelpreis (€)</th><th class="bx-num">USt %</th>
      </tr></thead>
      <tbody id="gsrows">
        <?php for ($i = 0; $i < 3; $i++): ?>
        <tr>
          <td><input type="text" name="p_artikelnr[]" style="max-width:110px"></td>
          <td>
            <input type="text" name="p_bez[]" placeholder="Bezeichnung" style="width:100%">
            <textarea name="p_besch[]" rows="2" placeholder="Beschreibung (optional, mehrzeilig)" style="width:100%;margin-top:4px"></textarea>
          </td>
          <td class="bx-num"><input type="text" inputmode="decimal" name="p_menge[]" value="1" style="max-width:90px;text-align:right"></td>
          <td><input type="text" name="p_einheit[]" value="Stk." style="max-width:80px"></td>
          <td class="bx-num"><input type="text" inputmode="decimal" name="p_preis[]" placeholder="0,00" style="max-width:120px;text-align:right"></td>
          <td class="bx-num"><input type="text" inputmode="decimal" name="p_mwst[]" value="<?= h($ustStd) ?>" style="max-width:70px;text-align:right"></td>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table></div>
    <div class="bx-row" style="margin-top:var(--sp-4)">
      <button type="button" class="btn btn-ghost btn-sm" id="gsAdd">+ Position</button>
    </div>
  </div>

  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit">Storno-Rechnung erstellen</button>
    <a class="btn btn-ghost" href="?p=rechnungen">Abbrechen</a>
  </div>
</form>

<script>
(function(){
  var UST = <?= json_encode($ustStd) ?>;
  var tbody = document.getElementById('gsrows');
  function rowHTML(){
    return '<td><input type="text" name="p_artikelnr[]" style="max-width:110px"></td>'
      + '<td><input type="text" name="p_bez[]" placeholder="Bezeichnung" style="width:100%">'
      + '<textarea name="p_besch[]" rows="2" placeholder="Beschreibung (optional, mehrzeilig)" style="width:100%;margin-top:4px"></textarea></td>'
      + '<td class="bx-num"><input type="text" inputmode="decimal" name="p_menge[]" value="1" style="max-width:90px;text-align:right"></td>'
      + '<td><input type="text" name="p_einheit[]" value="Stk." style="max-width:80px"></td>'
      + '<td class="bx-num"><input type="text" inputmode="decimal" name="p_preis[]" placeholder="0,00" style="max-width:120px;text-align:right"></td>'
      + '<td class="bx-num"><input type="text" inputmode="decimal" name="p_mwst[]" value="'+UST+'" style="max-width:70px;text-align:right"></td>';
  }
  function addRow(d){
    var tr=document.createElement('tr'); tr.innerHTML=rowHTML(); tbody.appendChild(tr);
    if(d){
      tr.querySelector('[name="p_artikelnr[]"]').value = d.artikelnr || '';
      tr.querySelector('[name="p_bez[]"]').value = d.bez || '';
      tr.querySelector('[name="p_besch[]"]').value = d.besch || '';
      if(d.menge!=='' && d.menge!=null)  tr.querySelector('[name="p_menge[]"]').value = d.menge;
      tr.querySelector('[name="p_einheit[]"]').value = d.einheit || 'Stk.';
      if(d.preis!=='' && d.preis!=null)  tr.querySelector('[name="p_preis[]"]').value = d.preis;
      if(d.mwst!=='' && d.mwst!=null)    tr.querySelector('[name="p_mwst[]"]').value = d.mwst;
    }
    return tr;
  }
  document.getElementById('gsAdd').addEventListener('click', function(){ addRow(); });

  function deNum(s){ if(s==null) return ''; s=String(s).trim().replace(/\s/g,'').replace(/\./g,'').replace(',','.'); var n=parseFloat(s); return isNaN(n)?'':n; }
  function fmtDe(n){ return n===''?'':String(n).replace('.',','); }
  // STRICT: Kopfzeile "Pos Artikelnr Bezeichnung Menge Einheit Einzelpreis Gesamt" + Beschreibungszeilen darunter.
  function parseStrict(text){
    var lines=text.split(/\r?\n/), out=[], cur=null;
    var hdr=/^\s*\d+\s+(.+?)\s+([\d.]+,\d{2})\s+(\S+)\s+([\d.]+,\d{2})\s+([\d.]+,\d{2})\s*$/;
    lines.forEach(function(ln){
      var m=ln.match(hdr);
      if(m){
        if(cur) out.push(cur);
        var mid=m[1].trim(), art='', bez=mid;
        var am=mid.match(/^([A-Za-zÄÖÜäöü]{2,6}\s+[\w.\-]+)\s+(.+)$/);
        if(am){ art=am[1]; bez=am[2]; }
        cur={artikelnr:art, bez:bez, besch:[], menge:m[2], einheit:m[3], preis:m[4]};
      } else if(cur && ln.trim()!==''){ cur.besch.push(ln.trim()); }
    });
    if(cur) out.push(cur);
    return out;
  }
  // ROH: jede nicht-leere Zeile = eine Position. Betrag am Zeilenende (z. B. 7.540,00 oder 12,00 €) wird als
  // Preis uebernommen (Menge 1 -> Positionssumme = dieser Betrag), der Rest der Zeile wird die Bezeichnung.
  function parseRaw(text){
    return text.split(/\r?\n/).map(function(l){return l.replace(/\t/g,' ').trim();}).filter(Boolean).map(function(l){
      var m=l.match(/(\d{1,3}(?:[.\s]\d{3})*,\d{2}|\d+[.,]\d{2})\s*(?:€|EUR)?\s*$/);
      var preis='', bez=l;
      if(m){ preis=m[1]; bez=l.slice(0,m.index).trim() || l; }
      return {artikelnr:'', bez:bez, besch:[], menge:'1', einheit:'Stk.', preis:preis};
    });
  }
  function clearEmptyRows(){
    Array.prototype.slice.call(tbody.querySelectorAll('tr')).forEach(function(tr){
      var b=tr.querySelector('[name="p_bez[]"]'); if(b && b.value.trim()==='') tr.remove();
    });
  }
  function fill(items){
    clearEmptyRows();
    items.forEach(function(it){
      addRow({artikelnr:it.artikelnr||'', bez:it.bez, besch:(it.besch||[]).join('\n'),
              menge:fmtDe(deNum(it.menge)), einheit:it.einheit||'Stk.', preis:fmtDe(deNum(it.preis)), mwst:UST});
    });
  }
  document.getElementById('gsPasteBtn').addEventListener('click', function(){
    var txt=document.getElementById('gsPaste').value||'', info=document.getElementById('gsPasteInfo');
    var items=parseStrict(txt);
    if(items.length){ fill(items); info.textContent=items.length+' Position(en) übernommen – bitte prüfen/anpassen.'; return; }
    // Format nicht erkannt -> automatisch roh uebernehmen (nichts geht verloren).
    items=parseRaw(txt);
    if(!items.length){ info.textContent='Kein Text zum Übernehmen.'; return; }
    fill(items); info.textContent='Spaltenformat nicht erkannt – '+items.length+' Zeile(n) roh übernommen. Bitte Beträge/Mengen prüfen.';
  });
  document.getElementById('gsRawBtn').addEventListener('click', function(){
    var items=parseRaw(document.getElementById('gsPaste').value||''), info=document.getElementById('gsPasteInfo');
    if(!items.length){ info.textContent='Kein Text zum Übernehmen.'; return; }
    fill(items); info.textContent=items.length+' Zeile(n) roh übernommen – bitte Beträge/Mengen prüfen.';
  });
})();
</script>
<?php render_footer(); ?>
