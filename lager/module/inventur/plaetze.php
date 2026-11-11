<?php
// Lagerplätze (11.11.2026, mit der Inventur eingeführt): feste Plätze für Lager 1 und Lager 2 (Bereich-Regal-Ebene-Fach),
// je Platz ein Etikett mit Barcode „LP-00012" fürs Regal. Anlegen/Abschalten nur Admin; ansehen und Etiketten drucken alle aus dem Lager.
$admin = lg_ist_admin();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admin) {
    $a = (string)($_POST['aktion'] ?? '');
    if ($a === 'raster') {
        $n = inv_platz_raster((string)$_POST['bereich'], (int)$_POST['regal_von'], (int)$_POST['regal_bis'], (int)$_POST['ebenen'], (int)$_POST['faecher'], (string)$_POST['lager']);
        flash($n . ' Platz/Plätze angelegt' . ($n === 0 ? ' (gab es schon).' : '.'));
    } elseif ($a === 'aus') {
        $belegt = (int) scalar("SELECT COUNT(*) FROM lg_platz_inhalt WHERE platz_id=?", [(int)$_POST['platz']]);
        if ($belegt) flash('Auf dem Platz liegt noch Ware (' . $belegt . ' Gebinde/Chargen) – erst umlagern.', 'warn');
        else { q("UPDATE lg_platz SET aktiv=0, aktualisiert=? WHERE id=?", [jetzt_utc(), (int)$_POST['platz']]); flash('Platz abgeschaltet.'); }
    }
    weiter('?p=plaetze');
}
$plaetze = inv_plaetze();
$belegung = [];
foreach (all("SELECT platz_id, COUNT(*) n FROM lg_platz_inhalt GROUP BY platz_id") as $r) $belegung[(int)$r['platz_id']] = (int)$r['n'];

kopf('Lagerplätze', 'plaetze');
seitenkopf('Lagerplätze', 'Feste Plätze im Regal mit Barcode. Beim Zählen der Inventur bekommt jedes Gebinde seinen Platz.', '<a class="btn btn-ghost" href="?p=inventur">Inventur</a>');
flash_zeigen();
if ($admin): ?>
<form method="post" class="bx-panel">
  <input type="hidden" name="aktion" value="raster">
  <h2>Plätze anlegen (Raster)</h2>
  <div class="bx-row" style="gap:12px;flex-wrap:wrap;align-items:flex-end">
    <div class="bx-field" style="width:120px"><label>Lager</label><select name="lager" data-norscombo><option value="lager1">Lager 1</option><option value="lager2">Lager 2</option></select></div>
    <div class="bx-field" style="width:90px"><label>Bereich</label><input type="text" name="bereich" value="A" maxlength="10"></div>
    <div class="bx-field" style="width:110px"><label>Regal von</label><input type="number" name="regal_von" value="1" min="1"></div>
    <div class="bx-field" style="width:110px"><label>Regal bis</label><input type="number" name="regal_bis" value="1" min="1"></div>
    <div class="bx-field" style="width:110px"><label>Ebenen</label><input type="number" name="ebenen" value="4" min="1" max="50"></div>
    <div class="bx-field" style="width:110px"><label>Fächer je Ebene</label><input type="number" name="faecher" value="1" min="1" max="50"></div>
    <button class="btn btn-primary" type="submit">Anlegen</button>
  </div>
  <p class="muted" style="margin-bottom:0">Name = Bereich-Regal-Ebene, z. B. A-03-2 (bei mehreren Fächern A-03-2-4). Vorhandene Plätze bleiben unverändert.</p>
</form>
<?php endif; ?>
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <h2 style="margin:0"><?= count($plaetze) ?> Plätze</h2>
    <?php if ($plaetze): ?><button type="button" class="btn btn-ghost" id="plDruck">Etiketten der markierten drucken</button><?php endif; ?>
  </div>
  <span id="plMsg" class="muted"></span>
  <?php if (!$plaetze): ?><p class="muted">Noch keine Plätze.</p><?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th><input type="checkbox" id="plAlle" aria-label="alle"></th><th>Platz</th><th>Code</th><th>Lager</th><th class="bx-num">belegt</th><th></th></tr></thead><tbody>
  <?php foreach ($plaetze as $p): $pid = (int)$p['id']; ?>
    <tr><td><input type="checkbox" class="plSel" value="<?= $pid ?>"></td><td class="lg-code"><?= h(inv_platz_name($p)) ?></td><td class="lg-code muted"><?= h(inv_platz_code($pid)) ?></td>
      <td><?= (string)$p['lager'] === 'lager2' ? 'Lager 2' : 'Lager 1' ?></td><td class="bx-num"><?= (int)($belegung[$pid] ?? 0) ?></td>
      <td><?php if ($admin): ?><form method="post" style="margin:0" onsubmit="return confirm('Platz abschalten?')"><input type="hidden" name="aktion" value="aus"><input type="hidden" name="platz" value="<?= $pid ?>"><button class="btn btn-ghost btn-sm">abschalten</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<script>
(function(){
  var a=document.getElementById('plAlle'); if(a) a.addEventListener('change',function(){ document.querySelectorAll('.plSel').forEach(function(c){ c.checked=a.checked; }); });
  var b=document.getElementById('plDruck'), m=document.getElementById('plMsg'); if(!b) return;
  b.addEventListener('click',function(){
    var ids=[].map.call(document.querySelectorAll('.plSel:checked'),function(c){return c.value;});
    if(!ids.length){ m.textContent='Bitte Plätze anhaken.'; return; }
    var fd=new FormData(); fd.append('typ','platz'); fd.append('ids',ids.join(','));
    fetch('?p=druck_job',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){ m.textContent = j.ok ? (ids.length+' Etikett(en): '+j.meldung) : (j.fehler||'Fehler'); });
  });
})();
</script>
<?php fuss();
