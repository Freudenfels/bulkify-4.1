<?php
// Umlagern Lager 1 -> Lager 2 (Nico 04.11.2026): freie, fertig produzierte Ware aus Lager 1 mit wenigen Klicks ins Fremdlager
// des Kunden (eigenes Fulfillment). Mobil bedienbar (Karten, große Touch-Ziele). Charge/MHD nur zur Prüfung, nicht eintippbar.
// Menge reicht (Sammelbehälter, kein Einzel-Karton-Scan). Etiketten/Material laufen weiter über „Kundenware einbuchen“.
$kand = erp_umlagern_kandidaten();
$ffKunden = []; foreach (erp_fulfillment_kunden() as $k) $ffKunden[(int)$k['id']] = (string)$k['firma'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'umlagern') {
    $jeId = []; foreach ($kand as $c) $jeId[(int)$c['id']] = $c;
    $ok = []; $fehler = [];
    foreach (array_map('intval', (array)($_POST['sel'] ?? [])) as $cid) {
        if (!isset($jeId[$cid])) { $fehler[] = 'Charge #' . $cid . ' ist nicht mehr frei in Lager 1.'; continue; }
        $c = $jeId[$cid];
        // Besitzer kommt aus Auftrag/PA/Produkt. Nur wenn er fehlt, gilt die Auswahl auf der Seite (nie raten).
        $kid = (int)$c['besitzer_id'] ?: (int)($_POST['kunde'][$cid] ?? 0);
        if ($kid <= 0 || (!$c['besitzer_id'] && !isset($ffKunden[$kid]))) { $fehler[] = (string)$c['anzeige'] . ' (' . (string)$c['charge_nr'] . '): bitte den Kunden wählen.'; continue; }
        $r = erp_umlagern_lager2($cid, erp_menge_parse((string)($_POST['menge'][$cid] ?? '0')), $kid);
        if (!empty($r['ok'])) $ok[] = (string)$r['meldung']; else $fehler[] = (string)$r['meldung'];
    }
    if (!$ok && !$fehler) $fehler[] = 'Bitte mindestens eine Zeile anhaken.';
    if ($ok) flash(implode(' ', $ok), 'ok');
    if ($fehler) flash(implode(' ', $fehler), 'warn');
    weiter('?p=umlagern');
}

$nf = fn($v) => rtrim(rtrim(number_format((float)$v, 3, ',', '.'), '0'), ',');
kopf('Nach Lager 2', 'umlagern');
seitenkopf('Nach Lager 2', 'Fertige, freie Ware aus Lager 1 ins Lager 2 des Kunden umlagern. Menge eintragen, anhaken, umlagern.');
flash_zeigen();
?>
<style>
  .ul-liste{display:flex;flex-direction:column;gap:var(--sp-3)}
  .ul-karte{display:block;padding:14px 16px;border:1px solid var(--line);border-radius:12px;background:var(--card,var(--bg));cursor:pointer}
  .ul-karte.an{border-color:var(--green,#1D9E75);box-shadow:0 0 0 2px var(--green,#1D9E75) inset}
  .ul-karte.gesperrt{opacity:.65;cursor:default}
  .ul-kopf{display:flex;gap:12px;align-items:flex-start}
  .ul-kopf input[type=checkbox]{width:28px;height:28px;flex:0 0 28px;margin:2px 0 0}
  .ul-name{font-size:17px;font-weight:600;line-height:1.3}
  .ul-kunde{font-size:13px;color:var(--muted)}
  .ul-daten{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:12px 0 10px}
  .ul-daten div{min-width:0}
  .ul-daten span{display:block;font-size:12px;color:var(--muted)}
  .ul-daten b{font-weight:500;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block}
  .ul-menge{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .ul-menge input{font-size:18px;padding:10px 12px;min-height:48px;width:140px;text-align:right}
  .ul-menge select{min-height:48px;font-size:16px;flex:1 1 200px}
  .ul-hinweis{font-size:13px;color:var(--warn-text,#8a5a00);margin-top:6px}
  .ul-leiste{position:sticky;bottom:0;padding:12px 0 calc(12px + env(safe-area-inset-bottom));background:var(--bg);display:flex;gap:12px;align-items:center;justify-content:space-between;border-top:1px solid var(--line);margin-top:var(--sp-4)}
  .ul-leiste .btn{min-height:52px;font-size:17px;padding:0 24px}
</style>
<?php if (!$kand): ?>
  <div class="bx-panel muted">In Lager 1 liegt keine freie, fertig produzierte Ware.</div>
<?php else: ?>
<form method="post" id="ulForm">
  <input type="hidden" name="aktion" value="umlagern">
  <div class="ul-liste">
  <?php foreach ($kand as $c): $cid = (int)$c['id']; $offen = !$c['besitzer_id']; $nichtFf = $c['besitzer_id'] && !$c['fulfillment']; ?>
    <label class="ul-karte<?= $nichtFf ? ' gesperrt' : '' ?>" data-verf="<?= h((string)(float)$c['menge_verfuegbar']) ?>">
      <div class="ul-kopf">
        <input type="checkbox" name="sel[]" value="<?= $cid ?>" class="ul-sel"<?= $nichtFf ? ' disabled' : '' ?>>
        <div style="min-width:0">
          <div class="ul-name"><?= h((string)$c['anzeige']) ?></div>
          <div class="ul-kunde"><?= $c['besitzer'] !== '' ? h($c['besitzer']) : 'Kunde nicht eindeutig' ?><?= !empty($c['auftrag_nr']) ? ' · ' . h((string)$c['auftrag_nr']) : '' ?></div>
        </div>
      </div>
      <div class="ul-daten">
        <div><span>Charge</span><b class="lg-code"><?= h((string)($c['charge_nr'] ?: '–')) ?></b></div>
        <div><span>MHD</span><b><?= $c['mhd'] ? h(date('d.m.Y', strtotime((string)$c['mhd']))) : '–' ?></b></div>
        <div><span>In Lager 1</span><b><?= h($nf($c['menge_verfuegbar']) . ' ' . (string)$c['einheit']) ?></b></div>
      </div>
      <?php if (!$nichtFf): ?>
      <div class="ul-menge">
        <input type="text" inputmode="decimal" name="menge[<?= $cid ?>]" class="ul-m" value="<?= h(zahl_feld($c['menge_verfuegbar'])) ?>" aria-label="Menge umlagern"> <span><?= h((string)$c['einheit']) ?></span>
        <?php if ($offen): ?>
        <select name="kunde[<?= $cid ?>]" class="ul-k"><option value="">Kunde wählen …</option><?php foreach ($ffKunden as $kid => $fn): ?><option value="<?= $kid ?>"><?= h($fn) ?></option><?php endforeach; ?></select>
        <?php endif; ?>
      </div>
      <?php if ($offen): ?><div class="ul-hinweis">Kein Auftrag und kein Kunde am Produkt – bitte den Besitzer wählen.</div><?php endif; ?>
      <div class="ul-hinweis ul-fehler" hidden></div>
      <?php else: ?>
      <div class="ul-hinweis"><?= h($c['besitzer']) ?> nutzt kein Fulfillment – Lager 2 gibt es nur für Fulfillment-Kunden (am Kunden einschalten).</div>
      <?php endif; ?>
    </label>
  <?php endforeach; ?>
  </div>
  <div class="ul-leiste">
    <span class="muted" id="ulInfo">Nichts angehakt</span>
    <button type="submit" class="btn btn-primary" id="ulBtn" disabled>Umlagern</button>
  </div>
</form>
<script>
(function(){
  var f=document.getElementById('ulForm'), btn=document.getElementById('ulBtn'), info=document.getElementById('ulInfo');
  function zahl(v){ v=String(v||'').trim(); if(v.indexOf(',')>=0) v=v.replace(/\./g,'').replace(',','.'); else if(/^\d{1,3}(\.\d{3})+$/.test(v)) v=v.replace(/\./g,''); return parseFloat(v)||0; }
  function pruefe(){
    var n=0, bad=0;
    f.querySelectorAll('.ul-karte').forEach(function(k){
      var cb=k.querySelector('.ul-sel'), m=k.querySelector('.ul-m'), ks=k.querySelector('.ul-k'), fe=k.querySelector('.ul-fehler');
      if(!cb) return; k.classList.toggle('an', cb.checked);
      if(!cb.checked){ if(fe) fe.hidden=true; return; }
      n++;
      var v=zahl(m&&m.value), verf=parseFloat(k.getAttribute('data-verf'))||0, t='';
      if(v<=0) t='Menge fehlt.'; else if(v>verf+1e-9) t='Höchstens die verfügbare Menge.'; else if(ks && !ks.value) t='Bitte den Kunden wählen.';
      if(fe){ fe.textContent=t; fe.hidden=!t; } if(t) bad++;
    });
    btn.disabled = !n || bad>0;
    info.textContent = n ? (n+' ausgewählt'+(bad?' – bitte prüfen':'')) : 'Nichts angehakt';
  }
  f.addEventListener('change', pruefe); f.addEventListener('input', pruefe);
  // Tippen in Menge/Kunde hakt die Zeile an (ein Klick weniger).
  f.querySelectorAll('.ul-m, .ul-k').forEach(function(el){ el.addEventListener('input', function(){ var cb=el.closest('.ul-karte').querySelector('.ul-sel'); if(cb && !cb.checked){ cb.checked=true; pruefe(); } }); });
  f.addEventListener('submit', function(e){ var n=f.querySelectorAll('.ul-sel:checked').length; if(!n || !confirm(n+' Position(en) ins Lager 2 umlagern?')) e.preventDefault(); });
  pruefe();
})();
</script>
<?php endif; ?>
<?php fuss();
