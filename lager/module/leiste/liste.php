<?php
// Übersicht aller Blinker im grossen Lager: welche haengt an welcher Charge, welche sind frei.
// Dazu "Blinker testen": Code eingeben/scannen -> Blinker leuchtet (auch unregistrierte Codes).

// AJAX: Blinker per Code auslösen (an/aus). Nimmt den Blinker bei Bedarf auf (leiste_sicherstellen).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'testcode') {
    $code = led_leiste_normalisieren((string)($_POST['code'] ?? ''));
    if ($code === null) json_antwort(['ok' => false, 'meldung' => 'Kein gültiger Blinker-Code (6 Zeichen, z. B. AFC709).']);
    $l = leiste_sicherstellen($code);
    $r = ($_POST['modus'] ?? 'an') === 'aus'
        ? leiste_aus((int)$l['id'])
        : leiste_finden((int)$l['id'], 'gruen', 20, true);
    json_antwort($r);
}

$leisten = leiste_alle();
$frei = count(array_filter($leisten, fn($l) => !$l['charge_id']));

kopf('Blinker', 'leisten');
seitenkopf('Blinker', count($leisten) . ' im Umlauf, davon ' . $frei . ' frei');
flash_zeigen();
?>
<div class="bx-panel">
  <h2 style="margin-top:0">Blinker testen</h2>
  <p class="muted" style="margin:0 0 var(--sp-3)">Code eingeben oder scannen – der Blinker leuchtet kurz grün (auch unregistrierte Codes).</p>
  <div class="bx-row" style="gap:var(--sp-3);flex-wrap:wrap;align-items:flex-end">
    <div class="bx-field" style="margin:0;min-width:260px;flex:1"><label>Blinker-Code</label>
      <input type="text" id="tcCode" class="lg-code" autocomplete="off" placeholder="Code scannen oder eingeben (z. B. AFC709)">
    </div>
    <button type="button" class="btn btn-primary" id="tcAn">Leuchten lassen</button>
    <button type="button" class="btn btn-ghost" id="tcAus">Aus</button>
    <span id="tcInfo" class="muted" style="align-self:center"></span>
  </div>
</div>
<script>
(function(){
  var code=document.getElementById('tcCode'), info=document.getElementById('tcInfo');
  function send(modus){
    var c=(code.value||'').trim(); if(c===''){ info.textContent='Bitte einen Code eingeben.'; code.focus(); return; }
    info.textContent='…';
    var fd=new FormData(); fd.append('aktion','testcode'); fd.append('code',c); fd.append('modus',modus);
    fetch('?p=leisten',{method:'POST',body:fd}).then(function(r){return r.json();})
      .then(function(j){ info.textContent=j.meldung||(j.ok?'Leuchtet.':'Fehler'); })
      .catch(function(){ info.textContent='Serverfehler.'; });
  }
  document.getElementById('tcAn').addEventListener('click',function(){ send('an'); });
  document.getElementById('tcAus').addEventListener('click',function(){ send('aus'); });
  code.addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); send('an'); } });
})();
</script>
<?php
if (!$leisten) {
    hinweis('Noch kein Blinker aufgenommen. Ein Blinker wird beim ersten Binden unter „Finden" automatisch aufgenommen.');
    fuss(); return;
}
?>
<div class="bx-listbar">
  <input type="search" class="bx-search" placeholder="Suchen: Blinker, Rohstoff, Charge" data-filter="lg-leisten" autofocus>
</div>
<div class="bx-tablewrap" style="margin-bottom:var(--sp-6)">
  <table class="bx-table" id="lg-leisten">
    <thead><tr><th>Blinker</th><th>Status</th><th>Hängt an</th><th>Nutzung</th><th>Batterie</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($leisten as $l): $stufe = leiste_batterie_stufe($l); ?>
      <tr>
        <td class="lg-code"><?= h((string)$l['code']) ?></td>
        <td><?= $l['charge_id'] ? '<span class="badge badge-ok">belegt</span>' : '<span class="badge">frei</span>' ?></td>
        <td><?= $l['charge'] ? h(charge_text($l['charge'])) : '<span class="muted">–</span>' ?></td>
        <td class="muted"><?= (int)$l['ausloesungen'] ?>× · <?= (int)round((int)$l['verbrauch_sek'] / 60) ?> min</td>
        <td><?php
            echo match ($stufe) {
                'tausch' => '<span class="badge badge-err">Batterie tauschen</span>',
                'hoch'   => '<span class="badge badge-warn">viel genutzt</span>',
                default  => '<span class="badge badge-ok">ok</span>',
            };
        ?></td>
        <td style="text-align:right;white-space:nowrap">
          <?php if ($l['charge_id']): ?>
            <button type="button" class="btn btn-ghost btn-sm" data-klingeln="<?= (int)$l['id'] ?>">Finden</button>
            <button type="button" class="btn btn-ghost btn-sm" data-klingeln="<?= (int)$l['id'] ?>" data-aktion="aus">Aus</button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
fuss();
