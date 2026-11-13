<?php
// Neue Inventur anlegen (nur Admin, 11.11.2026): Name/Stichtag, Umfang (Lager 1 / Lager 2 je Kunde, einzelne Plätze/Kisten
// oder ganzes Lager, Warenarten), Zähler, Nachzählen ab x %, Toleranzen. Mehrere Teil-Inventuren nacheinander möglich.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $r = inv_anlegen($_POST);
    if ($r['ok']) { flash('Inventur angelegt. Zählen kann sofort beginnen.'); weiter('?p=inventur_lauf&id=' . (int)$r['id']); }
    flash($r['fehler'], 'warn');
    weiter('?p=inventur_neu');
}
$plaetze = inv_plaetze();
$kisten  = kiste_alle();
$kunden  = erp_fulfillment_kunden();
$zaehler = erp_inv_zaehler_auswahl();
$gesperrt = [];
foreach (all("SELECT b.schluessel, i.name FROM lg_inventur_bereich b JOIN lg_inventur i ON i.id=b.inventur_id WHERE b.sperre IS NOT NULL") as $r) $gesperrt[(string)$r['schluessel']] = (string)$r['name'];

kopf('Neue Inventur', 'inventur');
seitenkopf('Neue Inventur', 'Umfang festlegen. Ohne Auswahl einzelner Plätze wird das ganze Lager gezählt – Plätze kommen beim Scannen dazu.', '<a class="btn btn-ghost" href="?p=inventur">Zurück</a>');
flash_zeigen();
?>
<form method="post" class="bx-panel" style="display:flex;flex-direction:column;gap:18px;max-width:900px">
  <div class="bx-row" style="gap:14px;flex-wrap:wrap">
    <div class="bx-field" style="flex:2 1 280px"><label>Name</label><input type="text" name="name" required placeholder="z. B. Inventur Regal A" value="Inventur <?= h(date('d.m.Y')) ?>"></div>
    <div class="bx-field" style="flex:1 1 160px"><label>Stichtag</label><input type="date" name="stichtag" value="<?= h(gmdate('Y-m-d')) ?>"></div>
  </div>
  <div class="bx-row" style="gap:14px;flex-wrap:wrap;align-items:flex-end">
    <div class="bx-field" style="flex:1 1 200px"><label>Lager</label>
      <select name="lager" id="invLager" data-norscombo><option value="1">Lager 1 (eigener Bestand)</option><option value="2">Lager 2 (Kundenware)</option></select></div>
    <div class="bx-field" style="flex:2 1 280px" id="invKunde" hidden><label>Kunde (Lager 2)</label>
      <select name="kunde_id"><option value="0">alle Kunden</option><?php foreach ($kunden as $k): ?><option value="<?= (int)$k['id'] ?>"><?= h((string)$k['firma']) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="bx-field"><label>Warenarten (keine Auswahl = alle)</label>
    <div class="bx-row" style="gap:16px;flex-wrap:wrap">
      <?php foreach (erp_inv_warenarten() as $k => $l): ?><label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="warenarten[]" value="<?= h($k) ?>"> <?= h($l) ?></label><?php endforeach; ?>
    </div></div>
  <div class="bx-field"><label>Plätze und Kisten (keine Auswahl = ganzes Lager)</label>
    <?php if ($kisten): ?><label style="display:flex;gap:6px;align-items:center;margin:4px 0 8px"><input type="checkbox" id="invAlleKisten"> alle Kisten auswählen (<?= count($kisten) ?>)</label><?php endif; ?>
    <?php if (!$plaetze && !$kisten): ?><p class="muted">Noch keine Lagerplätze angelegt – unter „Lagerplätze“ ein Raster anlegen und die Etiketten drucken. Ohne Plätze zählt die Inventur das ganze Lager.</p><?php endif; ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px 14px;max-height:280px;overflow:auto">
      <?php foreach ($plaetze as $p): $key = 'p:' . (int)$p['id']; ?><label style="display:flex;gap:6px;align-items:center<?= isset($gesperrt[$key]) ? ';opacity:.5' : '' ?>" title="<?= isset($gesperrt[$key]) ? h('in „' . $gesperrt[$key] . '“') : '' ?>"><input type="checkbox" name="bereiche[]" value="<?= h($key) ?>"<?= isset($gesperrt[$key]) ? ' disabled' : '' ?>> <span class="lg-code"><?= h(inv_platz_name($p)) ?></span><?= (string)$p['lager'] === 'lager2' ? ' <span class="muted">L2</span>' : '' ?></label><?php endforeach; ?>
      <?php foreach ($kisten as $k): $key = 'k:' . (int)$k['id']; ?><label style="display:flex;gap:6px;align-items:center<?= isset($gesperrt[$key]) ? ';opacity:.5' : '' ?>"><input type="checkbox" name="bereiche[]" value="<?= h($key) ?>"<?= isset($gesperrt[$key]) ? ' disabled' : '' ?>> Kiste <?= h((string)$k['name']) ?></label><?php endforeach; ?>
    </div></div>
  <div class="bx-field"><label>Zähler (keine Auswahl = alle aus dem Lager)</label>
    <div class="bx-row" style="gap:16px;flex-wrap:wrap">
      <?php foreach ($zaehler as $u): ?><label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="zaehler[]" value="<?= (int)$u['id'] ?>"> <?= h((string)$u['name']) ?></label><?php endforeach; ?>
    </div></div>
  <div class="bx-row" style="gap:14px;flex-wrap:wrap">
    <div class="bx-field" style="flex:1 1 220px"><label>Nachzählen durch zweite Person ab Abweichung über (%)</label><input type="text" inputmode="decimal" name="nachzaehl_prozent" placeholder="leer = aus"></div>
    <div class="bx-field" style="flex:1 1 160px"><label>Toleranz gewogene Ware (%)</label><input type="text" inputmode="decimal" name="tol_gewogen" value="1"></div>
    <div class="bx-field" style="flex:1 1 160px"><label>Toleranz Stück-Ware (%)</label><input type="text" inputmode="decimal" name="tol_stk" value="0"></div>
  </div>
  <div class="bx-row" style="gap:14px;flex-wrap:wrap">
    <div class="bx-field" style="flex:1 1 220px"><label>„Kleine Differenzen übernehmen“ unter (€)</label><input type="text" inputmode="decimal" name="klein_eur" value="20"></div>
    <div class="bx-field" style="flex:1 1 220px"><label>… und unter (%)</label><input type="text" inputmode="decimal" name="klein_prozent" value="2"></div>
  </div>
  <div class="bx-field"><label>Notiz</label><textarea name="notiz" rows="2"></textarea></div>
  <div><button class="btn btn-primary" type="submit">Inventur anlegen</button></div>
</form>
<script>
(function(){ var l=document.getElementById('invLager'), k=document.getElementById('invKunde'); function z(){ k.hidden = l.value!=='2'; } l.addEventListener('change', z); z(); })();
// Sofortfix 13.11.2026: alle Kisten auf einmal wählen (gesperrte – schon in einer anderen offenen Inventur – bleiben aus).
(function(){ var a=document.getElementById('invAlleKisten'); if(!a) return; a.addEventListener('change', function(){
  document.querySelectorAll('input[name="bereiche[]"][value^="k:"]').forEach(function(c){ if(!c.disabled) c.checked=a.checked; }); }); })();
</script>
<?php fuss();
