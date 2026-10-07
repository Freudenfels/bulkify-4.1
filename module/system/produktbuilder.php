<?php
// KI-Produktbuilder: Freitext-Wunsch -> Rezepturvorschlag. Die KI schlägt Formulierung/Rohstoffe vor,
// der deterministische Rechner (core/produktbuilder.php) macht die exakte Dosis-Mathe (IE/mg/µg, Gehalt).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/produktbuilder.php';

$FORMEN = pb_formen();
$EINHEITEN = ['mg'=>'mg', 'µg'=>'µg', 'IE'=>'IE', 'g'=>'g'];
$GEH = ['prozent'=>'%','mg_g'=>'mg/g','ug_g'=>'µg/g','ie_g'=>'IE/g','ie_kg'=>'IE/kg'];

$wunsch = trim((string)($_POST['wunsch'] ?? ''));
$form   = (string)($_POST['form'] ?? 'fluessig');
$bezug  = trim((string)($_POST['bezug'] ?? '')) ?: ($form === 'fluessig' ? '1 Tropfen' : '1 ' . ($FORMEN[$form] ?? 'Einheit'));
$kundeId= (int)($_POST['kunde_id'] ?? 0) ?: null;
$fehler = ''; $vorschlag = null; $charge = null;

// Flüssig-Parameter (für Tropfen/Flasche + Trägerauffüllung) und Chargen-/Ansatzgröße.
$lml    = (float) str_replace(',', '.', (string)($_POST['l_ml'] ?? '')) ?: 30.0;
$ltpml  = (float) str_replace(',', '.', (string)($_POST['l_tpml'] ?? '')) ?: 25.0;
$ldichte= (float) str_replace(',', '.', (string)($_POST['l_dichte'] ?? '')) ?: 0.95;
$cFlaschen  = (float) str_replace(',', '.', (string)($_POST['c_flaschen'] ?? '')) ?: 100.0;
$cEinheiten = (float) str_replace(',', '.', (string)($_POST['c_einheiten'] ?? '')) ?: 10000.0;

// Zutaten aus dem POST (Editier-/Anlegen-Schritt) in die Vorschlags-Struktur zurücklesen.
$zutatenAusPost = function(): array {
    $out = [];
    $n  = $_POST['z_name'] ?? []; $zw = $_POST['z_wirk'] ?? []; $zd = $_POST['z_dosis'] ?? []; $ze = $_POST['z_einheit'] ?? [];
    $gw = $_POST['z_gw'] ?? []; $ge = $_POST['z_ge'] ?? []; $ii = $_POST['z_item'] ?? []; $rl = $_POST['z_rolle'] ?? [];
    foreach ($n as $i => $nm) {
        if (trim((string)$nm) === '') continue;
        $out[] = ['name'=>$nm, 'ziel_wirkstoff'=>$zw[$i] ?? '', 'ziel_dosis'=>$zd[$i] ?? '', 'ziel_einheit'=>$ze[$i] ?? 'mg',
                  'gehalt_wert'=>$gw[$i] ?? '', 'gehalt_einheit'=>$ge[$i] ?? 'prozent', 'item_id'=>(int)($ii[$i] ?? 0) ?: null, 'rolle'=>$rl[$i] ?? 'wirkstoff'];
    }
    return $out;
};
// Rechnet je nach Form: Flüssig mit Trägerauffüllung, sonst normal.
$rechne = fn(array $data) => $form === 'fluessig' ? pb_liquid_rechnen($data, $lml, $ltpml, $ldichte) : pb_vorschlag_rechnen($data);

$aktion = $_POST['aktion'] ?? '';
if ($aktion === 'ki') {
    if ($wunsch === '') $fehler = 'Bitte einen Wunsch/Beschreibung eingeben.';
    else {
        $r = pb_ki_vorschlag($wunsch, $form, $bezug);
        if (!$r['ok']) $fehler = $r['fehler'];
        else { $d = (array)$r['daten']; $form = array_key_exists((string)($d['darreichungsform'] ?? ''), $FORMEN) ? (string)$d['darreichungsform'] : $form;
               $rechne = fn(array $x) => $form === 'fluessig' ? pb_liquid_rechnen($x, $lml, $ltpml, $ldichte) : pb_vorschlag_rechnen($x);
               $vorschlag = $rechne($d); }
    }
} elseif (in_array($aktion, ['recalc','anlegen'], true)) {
    $data = ['name' => $_POST['p_name'] ?? '', 'darreichungsform' => $form, 'bezug' => $bezug,
        'kapselgroesse' => $_POST['p_kaps'] ?? '', 'verzehrempfehlung' => $_POST['p_verzehr'] ?? '',
        'verpackung_typ' => $_POST['p_verp'] ?? '', 'zutaten' => $zutatenAusPost(),
        'hinweise' => array_filter(array_map('trim', explode("\n", (string)($_POST['p_hinweise'] ?? ''))))];
    if ($aktion === 'anlegen') {
        $v = $rechne($data);   // Träger auffüllen, bevor angelegt wird
        $r = pb_anlegen((string)$data['name'], $form, $kundeId, (array)$v['zutaten'], (string)$data['kapselgroesse'], (string)$data['verzehrempfehlung']);
        if (!empty($r['ok'])) { header('Location: ?p=rezeptur_detail&id=' . (int)$r['rezeptur_id'] . '&gespeichert=1#zutaten'); exit; }
        $fehler = $r['fehler'] ?? 'Anlegen fehlgeschlagen.'; $vorschlag = $v;
    } else {
        $vorschlag = $rechne($data);
    }
}
// Ansatz/Charge berechnen: Einheiten = Flaschen × Tropfen/Flasche (flüssig) bzw. direkt Anzahl Einheiten.
if ($vorschlag) {
    $einh = ($form === 'fluessig' && !empty($vorschlag['liquid']['tropfen_gesamt']))
          ? $cFlaschen * (float)$vorschlag['liquid']['tropfen_gesamt']
          : $cEinheiten;
    $charge = pb_charge($vorschlag, $einh);
}

$kunden = all("SELECT id, firma FROM kunden ORDER BY firma");
render_header('rezeptur', 'Produktbuilder (KI)');
bx_head('Produktbuilder', 'Aus einem Wunsch eine Rezeptur – KI schlägt vor, der Rechner macht die Dosis exakt', bx_btn('Alle Rezepturen', '?p=rezeptur'));

if (!ki_bereit()) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Die KI ist nicht aktiv (kein Schlüssel hinterlegt) – der Vorschlag funktioniert erst, wenn die KI eingerichtet ist. Der Dosis-Rechner unten funktioniert trotzdem.</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($fehler) . '</div>';
?>
<div class="bx-panel">
  <form method="post">
    <input type="hidden" name="aktion" value="ki">
    <div class="bx-field"><label>Was soll gebaut werden? <?= bx_hint('Frei beschreiben: Produktidee, Zieldosen, Besonderheiten. Je konkreter, desto besser. Beispiel: „D3/K2-Tropfen, 1000 IE D3 + 20 µg K2 MK-7 pro Tropfen, MCT-Öl, 30-ml-Tropfflasche".') ?></label>
      <textarea name="wunsch" rows="3" placeholder="z. B. D3 K2 Tropfen, 1000 IE D3 + 20 µg K2 (MK-7) pro Tropfen, in MCT-Öl, 30 ml Flasche"><?= h($wunsch) ?></textarea></div>
    <div class="bx-grid">
      <div class="bx-field"><label>Darreichungsform</label><select name="form"><?php foreach ($FORMEN as $fk=>$fl): ?><option value="<?= $fk ?>"<?= $form===$fk?' selected':'' ?>><?= h($fl) ?></option><?php endforeach; ?></select></div>
      <div class="bx-field"><label>Dosis bezieht sich auf <?= bx_hint('Worauf sich die Zieldosen beziehen, z. B. „1 Tropfen", „1 Kapsel", „1 ml", „tägliche Portion".') ?></label><input type="text" name="bezug" value="<?= h($bezug) ?>"></div>
      <div class="bx-field"><label>Für Kunde (optional)</label><select name="kunde_id"><option value="">– Hausrezeptur –</option><?php foreach ($kunden as $kk): ?><option value="<?= (int)$kk['id'] ?>"<?= $kundeId===(int)$kk['id']?' selected':'' ?>><?= h($kk['firma']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="bx-grid" style="margin-top:4px">
      <div class="bx-field"><label>Flaschenvolumen (ml) <?= bx_hint('nur Flüssig/Tropfen – Größe der Tropfflasche') ?></label><input type="text" name="l_ml" value="<?= h((string)$lml) ?>" placeholder="30"></div>
      <div class="bx-field"><label>Tropfen je ml <?= bx_hint('dropperabhängig, Öl ca. 20–30. Für die Garantie je Tropfen am besten durch Auswiegen bestätigen.') ?></label><input type="text" name="l_tpml" value="<?= h((string)$ltpml) ?>" placeholder="25"></div>
      <div class="bx-field"><label>Dichte des Öls (g/ml) <?= bx_hint('MCT ≈ 0,95. Für mg↔ml-Umrechnung.') ?></label><input type="text" name="l_dichte" value="<?= h((string)$ldichte) ?>" placeholder="0,95"></div>
    </div>
    <div class="bx-row" style="margin-top:12px"><button class="btn btn-primary" type="submit"<?= ki_bereit()?'':' disabled' ?>>KI-Vorschlag erstellen</button></div>
  </form>
</div>

<?php if ($vorschlag): $zut = (array)($vorschlag['zutaten'] ?? []); ?>
<form method="post">
  <input type="hidden" name="form" value="<?= h((string)($vorschlag['darreichungsform'] ?? $form)) ?>">
  <input type="hidden" name="bezug" value="<?= h($bezug) ?>">
  <input type="hidden" name="kunde_id" value="<?= $kundeId ? (int)$kundeId : '' ?>">
  <div class="bx-panel">
    <h2 style="margin-top:0">Vorschlag</h2>
    <div class="bx-grid">
      <div class="bx-field"><label>Produktname</label><input type="text" name="p_name" value="<?= h((string)($vorschlag['name'] ?? '')) ?>"></div>
      <div class="bx-field"><label>Kapselgröße <?= bx_hint('nur bei Kapsel/Softgel') ?></label><input type="text" name="p_kaps" value="<?= h((string)($vorschlag['kapselgroesse'] ?? '')) ?>" placeholder="z. B. 00"></div>
      <div class="bx-field"><label>Verzehrempfehlung</label><input type="text" name="p_verzehr" value="<?= h((string)($vorschlag['verzehrempfehlung'] ?? '')) ?>"></div>
      <div class="bx-field"><label>Verpackung (Vorschlag)</label><input type="text" name="p_verp" value="<?= h((string)($vorschlag['verpackung_typ'] ?? '')) ?>"></div>
    </div>
    <p class="muted" style="margin:6px 0 0">Bezug: <strong><?= h($bezug) ?></strong> · Gesamtgewicht je Bezug: <strong><?= h(rtrim(rtrim(number_format((float)($vorschlag['gesamt_mg'] ?? 0),3,',','.'),'0'),',')) ?> mg</strong><?= $form!=='fluessig' ? ' <span class="muted">(ohne Trägerauffüllung)</span>' : '' ?></p>
  </div>

  <?php if ($form === 'fluessig'): $lq = (array)($vorschlag['liquid'] ?? []); ?>
  <div class="bx-panel">
    <h2 style="margin-top:0">Flüssig / Tropfen</h2>
    <div class="bx-grid">
      <div class="bx-field"><label>Flaschenvolumen (ml)</label><input type="text" name="l_ml" value="<?= h((string)$lml) ?>"></div>
      <div class="bx-field"><label>Tropfen je ml</label><input type="text" name="l_tpml" value="<?= h((string)$ltpml) ?>"></div>
      <div class="bx-field"><label>Dichte (g/ml)</label><input type="text" name="l_dichte" value="<?= h((string)$ldichte) ?>"></div>
    </div>
    <p class="muted" style="margin:8px 0 0">
      Je Tropfen ≈ <strong><?= h(rtrim(rtrim(number_format((float)($lq['masse_je_tropfen_mg'] ?? 0),2,',','.'),'0'),',')) ?> mg</strong> ·
      Tropfen je Flasche: <strong><?= (int)($lq['tropfen_gesamt'] ?? 0) ?></strong> ·
      Füllgewicht je Flasche: <strong><?= h(rtrim(rtrim(number_format((float)($lq['fuellgewicht_flasche_g'] ?? 0),2,',','.'),'0'),',')) ?> g</strong>.
      Das <strong>Trägeröl (MCT)</strong> füllt jeden Tropfen automatisch auf – siehe Zutatentabelle.
    </p>
  </div>
  <?php else: ?>
    <input type="hidden" name="l_ml" value="<?= h((string)$lml) ?>"><input type="hidden" name="l_tpml" value="<?= h((string)$ltpml) ?>"><input type="hidden" name="l_dichte" value="<?= h((string)$ldichte) ?>">
  <?php endif; ?>

  <div class="bx-panel">
    <h2 style="margin-top:0">Zutaten &amp; Dosis <span class="muted" style="font-weight:400;font-size:13px">(mg je Bezug wird exakt gerechnet)</span></h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Rohstoff</th><th>Leitwirkstoff</th><th class="bx-num">Zieldosis</th><th>Einheit</th><th class="bx-num">Gehalt</th><th>Gehalt-Einh.</th><th class="bx-num">mg je Bezug</th><th>Rolle</th><th></th></tr></thead>
      <tbody id="pbrows">
      <?php foreach ($zut as $z): ?>
        <tr>
          <td><input type="text" name="z_name[]" value="<?= h((string)($z['name'] ?? '')) ?>" style="width:100%">
              <input type="hidden" name="z_item[]" value="<?= (int)($z['item_id'] ?? 0) ?: '' ?>">
              <?php if (!empty($z['item_id'])): ?><div class="muted" style="font-size:11px;color:var(--gruen)">↳ Lager-Rohstoff: <?= h((string)($z['item_name'] ?? '')) ?></div><?php endif; ?></td>
          <td><input type="text" name="z_wirk[]" value="<?= h((string)($z['ziel_wirkstoff'] ?? '')) ?>" style="width:100%"></td>
          <td class="bx-num"><input type="text" name="z_dosis[]" value="<?= h((string)($z['ziel_dosis'] ?? '')) ?>" style="width:80px;text-align:right"></td>
          <td><select name="z_einheit[]"><?php foreach ($EINHEITEN as $ek=>$el): ?><option value="<?= $ek ?>"<?= (string)($z['ziel_einheit'] ?? 'mg')===$ek?' selected':'' ?>><?= $el ?></option><?php endforeach; ?></select></td>
          <td class="bx-num"><input type="text" name="z_gw[]" value="<?= h((string)($z['gehalt_wert'] ?? '')) ?>" style="width:80px;text-align:right"></td>
          <td><select name="z_ge[]"><?php foreach ($GEH as $gk=>$gl): ?><option value="<?= $gk ?>"<?= (string)($z['gehalt_einheit'] ?? 'prozent')===$gk?' selected':'' ?>><?= $gl ?></option><?php endforeach; ?></select></td>
          <td class="bx-num"><strong><?= h(rtrim(rtrim(number_format((float)($z['menge_mg'] ?? 0),4,',','.'),'0'),',')) ?></strong>
              <?php if (empty($z['calc_ok']) && !empty($z['calc_hinweis'])): ?><div class="muted" style="font-size:11px;color:var(--warn)"><?= h((string)$z['calc_hinweis']) ?></div><?php endif; ?></td>
          <td><select name="z_rolle[]"><?php foreach (['wirkstoff'=>'Wirkstoff','traeger'=>'Träger','hilfsstoff'=>'Hilfsstoff'] as $rk=>$rl): ?><option value="<?= $rk ?>"<?= (string)($z['rolle'] ?? 'wirkstoff')===$rk?' selected':'' ?>><?= $rl ?></option><?php endforeach; ?></select></td>
          <td><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('tr').remove()">×</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="bx-row" style="margin-top:10px;gap:8px">
      <button type="button" class="btn btn-ghost btn-sm" onclick="pbAdd()">+ Zutat</button>
      <button type="submit" class="btn btn-ghost" name="aktion" value="recalc">Neu berechnen</button>
      <span style="flex:1"></span>
      <button type="submit" class="btn btn-primary" name="aktion" value="anlegen">Als Rezeptur (Entwurf) anlegen</button>
    </div>
    <div class="muted" style="font-size:12px;margin-top:8px">„mg je Bezug" = benötigte Rohstoffmenge je <?= h($bezug) ?>, exakt gerechnet aus Zieldosis × Gehalt (IE/µg/mg sauber umgerechnet). Ändere Dosis/Gehalt und „Neu berechnen".</div>
  </div>

  <?php // Ansatz/Charge: wie viel von jedem Rohstoff für eine Charge – zum Bestellen und Mischen.
  if ($charge): $gfmt = fn($g) => $g >= 1000 ? rtrim(rtrim(number_format($g/1000,3,',','.'),'0'),',') . ' kg' : rtrim(rtrim(number_format($g,2,',','.'),'0'),',') . ' g'; ?>
  <div class="bx-panel">
    <h2 style="margin-top:0">Ansatz / Charge <span class="muted" style="font-weight:400;font-size:13px">– wie viel du von jedem Rohstoff mischen/bestellen musst</span></h2>
    <div class="bx-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap">
      <?php if ($form === 'fluessig'): ?>
        <div class="bx-field" style="max-width:200px"><label>Anzahl Flaschen</label><input type="text" name="c_flaschen" value="<?= h((string)$cFlaschen) ?>"></div>
        <input type="hidden" name="c_einheiten" value="<?= h((string)$cEinheiten) ?>">
        <div class="muted" style="font-size:13px;padding-bottom:8px">= <strong><?= (int)($charge['einheiten'] ?? 0) ?></strong> Tropfen gesamt</div>
      <?php else: ?>
        <div class="bx-field" style="max-width:220px"><label>Anzahl Einheiten (<?= h($bezug) ?>)</label><input type="text" name="c_einheiten" value="<?= h((string)$cEinheiten) ?>"></div>
        <input type="hidden" name="c_flaschen" value="<?= h((string)$cFlaschen) ?>">
      <?php endif; ?>
      <button type="submit" class="btn btn-ghost" name="aktion" value="recalc">Charge berechnen</button>
    </div>
    <div class="bx-tablewrap" style="margin-top:10px"><table class="bx-table">
      <thead><tr><th>Rohstoff</th><th>Rolle</th><th class="bx-num">je Bezug (mg)</th><th class="bx-num">Gesamt für die Charge</th></tr></thead>
      <tbody>
      <?php foreach ((array)($charge['zeilen'] ?? []) as $cz): ?>
        <tr>
          <td><?= h((string)$cz['name']) ?></td>
          <td><?= h((string)$cz['rolle']) ?></td>
          <td class="bx-num"><?= h(rtrim(rtrim(number_format((float)$cz['je_einheit_mg'],4,',','.'),'0'),',')) ?></td>
          <td class="bx-num"><strong><?= h($gfmt((float)$cz['gesamt_g'])) ?></strong></td>
        </tr>
      <?php endforeach; ?>
        <tr style="font-weight:600"><td colspan="3">Gesamtmenge Ansatz</td><td class="bx-num"><?= h($gfmt((float)($charge['gesamt_g'] ?? 0))) ?></td></tr>
      </tbody>
    </table></div>
    <div class="muted" style="font-size:12px;margin-top:8px">Gesamt je Rohstoff = „mg je Bezug" × Anzahl <?= $form==='fluessig' ? 'Tropfen (Flaschen × Tropfen/Flasche)' : 'Einheiten' ?>. Das ist deine Einkaufs- und Mischmenge. Ändere die Anzahl und „Charge berechnen".</div>
  </div>
  <?php endif; ?>

  <?php $hinw = (array)($vorschlag['hinweise'] ?? []); ?>
  <div class="bx-panel">
    <h2 style="margin-top:0">Hinweise (KI) <span class="muted" style="font-weight:400;font-size:13px">– bitte prüfen (Höchstmengen, Novel Food, Health Claims)</span></h2>
    <textarea name="p_hinweise" rows="3" style="width:100%"><?= h(implode("\n", array_map('strval', $hinw))) ?></textarea>
    <p class="muted" style="font-size:12px;margin:6px 0 0">Die KI kann irren – Dosierung/Recht immer gegenprüfen. Die Rezeptur wird als <strong>Entwurf</strong> angelegt.</p>
  </div>
</form>
<script>
function pbAdd(){
  var tb=document.getElementById('pbrows');
  var tr=document.createElement('tr');
  tr.innerHTML='<td><input type="text" name="z_name[]" style="width:100%"><input type="hidden" name="z_item[]"></td>'
   +'<td><input type="text" name="z_wirk[]" style="width:100%"></td>'
   +'<td class="bx-num"><input type="text" name="z_dosis[]" style="width:80px;text-align:right"></td>'
   +'<td><select name="z_einheit[]"><option>mg</option><option>µg</option><option>IE</option><option>g</option></select></td>'
   +'<td class="bx-num"><input type="text" name="z_gw[]" style="width:80px;text-align:right"></td>'
   +'<td><select name="z_ge[]"><option value="prozent">%</option><option value="mg_g">mg/g</option><option value="ug_g">µg/g</option><option value="ie_g">IE/g</option><option value="ie_kg">IE/kg</option></select></td>'
   +'<td class="bx-num muted">–</td>'
   +'<td><select name="z_rolle[]"><option value="wirkstoff">Wirkstoff</option><option value="traeger">Träger</option><option value="hilfsstoff">Hilfsstoff</option></select></td>'
   +'<td><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest(\'tr\').remove()">×</button></td>';
  tb.appendChild(tr);
}
</script>
<?php endif;
render_footer();
