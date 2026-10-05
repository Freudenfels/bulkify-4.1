<?php
// Versand-Detail: eine Sendung planen. Empfaenger (Kunde + Adresse, Lieferadresse bevorzugt, weltweit),
// Positionen aus dem Bestand, Lieferschein drucken, als versendet markieren (bucht Bestand ab).
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

// --- AJAX: Adressen eines Kunden (Lieferadresse bevorzugt) ---
if (($_POST['aktion'] ?? '') === 'adressen') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'adressen' => erp_kunde_adressen((int)($_POST['kunde_id'] ?? 0))], JSON_UNESCAPED_UNICODE);
    exit;
}

$v = $id ? lg_versand($id) : null;
if (!$v) { flash('Sendung nicht gefunden.', 'warn'); weiter('?p=versand'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'kopf_speichern') {
        lg_versand_kopf_speichern($id, [
            'kunde_id' => (int)($_POST['kunde_id'] ?? 0),
            'adress_quelle' => (string)($_POST['adress_quelle'] ?? 'frei'),
            'empf_firma' => trim((string)($_POST['empf_firma'] ?? '')),
            'empf_name' => trim((string)($_POST['empf_name'] ?? '')),
            'empf_strasse' => trim((string)($_POST['empf_strasse'] ?? '')),
            'empf_hausnummer' => trim((string)($_POST['empf_hausnummer'] ?? '')),
            'empf_plz' => trim((string)($_POST['empf_plz'] ?? '')),
            'empf_ort' => trim((string)($_POST['empf_ort'] ?? '')),
            'empf_land' => trim((string)($_POST['empf_land'] ?? 'DE')),
            'empf_email' => trim((string)($_POST['empf_email'] ?? '')),
            'empf_telefon' => trim((string)($_POST['empf_telefon'] ?? '')),
            'typ' => (string)($_POST['typ'] ?? 'paket'),
            'pakete' => (int)($_POST['pakete'] ?? 1),
            'gewicht_kg' => (string)($_POST['gewicht_kg'] ?? ''),
            'masse_l' => (string)($_POST['masse_l'] ?? ''),
            'masse_b' => (string)($_POST['masse_b'] ?? ''),
            'masse_h' => (string)($_POST['masse_h'] ?? ''),
            'notiz' => trim((string)($_POST['notiz'] ?? '')),
        ]);
        flash('Sendung gespeichert.');
        weiter('?p=versand_detail&id=' . $id);
    }
    if ($aktion === 'pos_add') {
        $cid = (int)($_POST['charge_id'] ?? 0);
        $menge = erp_menge_parse((string)($_POST['menge'] ?? '0'));
        if ($cid > 0) {
            $c = erp_charge_voll($cid);
            if ($c) lg_versand_pos_add($id, ['charge_id' => $cid, 'item_id' => (int)($c['item_id'] ?? 0),
                'bezeichnung' => (string)($c['item_name'] ?? ''), 'charge_nr' => (string)($c['charge_nr'] ?? ''),
                'menge' => $menge > 0 ? $menge : (float)($c['menge_verfuegbar'] ?? 0), 'einheit' => (string)($c['einheit'] ?? '')]);
        } else {
            $bez = trim((string)($_POST['bezeichnung'] ?? ''));
            if ($bez !== '') lg_versand_pos_add($id, ['bezeichnung' => $bez, 'menge' => $menge,
                'einheit' => trim((string)($_POST['einheit'] ?? ''))]);
        }
        flash('Position hinzugefügt.');
        weiter('?p=versand_detail&id=' . $id . (($_POST['q'] ?? '') !== '' ? '&q=' . urlencode((string)$_POST['q']) : ''));
    }
    if ($aktion === 'pos_del') {
        lg_versand_pos_del((int)($_POST['pos_id'] ?? 0));
        flash('Position entfernt.');
        weiter('?p=versand_detail&id=' . $id);
    }
    if ($aktion === 'stornieren') {
        lg_versand_status_setzen($id, 'storniert');
        flash('Sendung storniert.');
        weiter('?p=versand_detail&id=' . $id);
    }
    if ($aktion === 'label') {
        require_once __DIR__ . '/../../core/versand.php';
        $r = versand_label_erstellen($id);
        if (!empty($r['ok'])) flash('Versand-Label erstellt (' . strtoupper((string)$r['carrier']) . '). Sendungsnr.: ' . ((string)$r['tracking'] ?: '–'));
        else flash((string)($r['fehler'] ?? 'Label konnte nicht erstellt werden.'), 'warn');
        weiter('?p=versand_detail&id=' . $id);
    }
    if ($aktion === 'versenden') {
        // Bestand je Position abbuchen (nur eigener Bestand, nur noch nicht abgebucht).
        $fehler = [];
        foreach (lg_versand_pos_liste($id) as $p) {
            if ((int)$p['abgebucht'] === 1 || !$p['charge_id'] || (float)$p['menge'] <= 0) continue;
            $r = erp_charge_entnehmen((int)$p['charge_id'], (float)$p['menge']);
            if (!$r['ok']) { $fehler[] = (string)$p['bezeichnung'] . ': ' . $r['meldung']; continue; }
            lg_bewegung_log((int)$p['charge_id'], 'aus', (float)$p['menge'], (string)$r['einheit'], (string)$r['item_name'], 'Versand ' . (string)$v['nummer']);
            if (!empty($r['leer'])) {
                $bl = leiste_fuer_charge((int)$p['charge_id']);
                if ($bl) { leiste_aus((int)$bl['id']); leiste_loesen((int)$bl['id']); }
                if (kiste_fuer_charge((int)$p['charge_id'])) kiste_charge_entfernen((int)$p['charge_id']);
            }
            lg_versand_pos_abgebucht((int)$p['id']);
        }
        lg_versand_status_setzen($id, 'versendet');
        flash($fehler ? ('Als versendet markiert. Hinweise: ' . implode(' · ', $fehler)) : 'Als versendet markiert, Bestand abgebucht.', $fehler ? 'warn' : 'ok');
        weiter('?p=versand_detail&id=' . $id);
    }
}

$pos    = lg_versand_pos_liste($id);
$hatLabel = function_exists('lg_versand_hat_label') && lg_versand_hat_label($id);
$kunden = function_exists('erp_kunden_liste') ? erp_kunden_liste() : [];
$q      = trim((string)($_GET['q'] ?? ''));
$treffer = $q !== '' ? erp_bestand('', $q, false, 30) : [];
$geplant = (string)$v['status'] === 'geplant';

kopf('Sendung ' . (string)$v['nummer'], 'versand');
$kopfAktion = '<a class="btn btn-primary" href="?p=lieferschein&id=' . $id . '" target="_blank">Lieferschein drucken</a> '
    . '<a class="btn btn-ghost" href="?p=versand">Zur Übersicht</a>';
seitenkopf('Sendung ' . (string)$v['nummer'],
    ((string)$v['status'] === 'versendet' ? 'Versendet' : ((string)$v['status'] === 'storniert' ? 'Storniert' : 'In Planung')), $kopfAktion);
flash_zeigen();
?>
<form method="post">
  <input type="hidden" name="aktion" value="kopf_speichern">
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="adress_quelle" id="vsQuelle" value="<?= h((string)($v['adress_quelle'] ?? 'frei')) ?>">

  <div class="bx-panel" style="margin-bottom:var(--sp-5)">
    <h2 style="margin-top:0">Empfänger</h2>
    <div class="bx-row" style="gap:var(--sp-4);flex-wrap:wrap;align-items:flex-end">
      <div class="bx-field" style="margin:0;min-width:240px"><label>Kunde <span class="muted">(optional – füllt die Adresse)</span></label>
        <select id="vsKunde">
          <option value="">– Kunde wählen –</option>
          <?php foreach ($kunden as $k): ?><option value="<?= (int)$k['id'] ?>" <?= (int)($v['kunde_id'] ?? 0) === (int)$k['id'] ? 'selected' : '' ?>><?= h((string)$k['firma']) ?></option><?php endforeach; ?>
        </select>
        <input type="hidden" name="kunde_id" id="vsKundeH" value="<?= (int)($v['kunde_id'] ?? 0) ?>">
      </div>
      <div class="bx-field" style="margin:0;min-width:220px"><label>Adresse <span class="muted">(Lieferadresse bevorzugt)</span></label>
        <select id="vsAdresse"><option value="">– erst Kunde wählen –</option></select>
      </div>
    </div>
    <div class="bx-grid" style="margin-top:var(--sp-4)">
      <div class="bx-field"><label>Firma</label><input type="text" name="empf_firma" value="<?= h((string)$v['empf_firma']) ?>"></div>
      <div class="bx-field"><label>Name / z. H.</label><input type="text" name="empf_name" value="<?= h((string)$v['empf_name']) ?>"></div>
      <div class="bx-field"><label>Straße</label><input type="text" name="empf_strasse" value="<?= h((string)$v['empf_strasse']) ?>"></div>
      <div class="bx-field" style="max-width:120px"><label>Hausnr.</label><input type="text" name="empf_hausnummer" value="<?= h((string)$v['empf_hausnummer']) ?>"></div>
      <div class="bx-field" style="max-width:130px"><label>PLZ</label><input type="text" name="empf_plz" value="<?= h((string)$v['empf_plz']) ?>"></div>
      <div class="bx-field"><label>Ort</label><input type="text" name="empf_ort" value="<?= h((string)$v['empf_ort']) ?>"></div>
      <div class="bx-field" style="max-width:150px"><label>Land <span class="muted">(z. B. DE)</span></label><input type="text" name="empf_land" maxlength="2" style="text-transform:uppercase" value="<?= h((string)$v['empf_land']) ?>"></div>
      <div class="bx-field"><label>E-Mail</label><input type="text" name="empf_email" value="<?= h((string)($v['empf_email'] ?? '')) ?>"></div>
      <div class="bx-field"><label>Telefon</label><input type="text" name="empf_telefon" value="<?= h((string)($v['empf_telefon'] ?? '')) ?>"></div>
    </div>
  </div>

  <div class="bx-panel" style="margin-bottom:var(--sp-5)">
    <h2 style="margin-top:0">Versandart</h2>
    <div class="bx-row" style="gap:var(--sp-4);flex-wrap:wrap;align-items:flex-end">
      <div class="bx-field" style="margin:0;min-width:200px"><label>Art</label>
        <select name="typ">
          <option value="paket" <?= (string)$v['typ'] === 'paket' ? 'selected' : '' ?>>Paket (klein)</option>
          <option value="palette" <?= (string)$v['typ'] === 'palette' ? 'selected' : '' ?>>Palette / Fracht</option>
        </select>
      </div>
      <div class="bx-field" style="margin:0;max-width:120px"><label>Pakete</label><input type="number" name="pakete" min="1" step="1" value="<?= (int)($v['pakete'] ?? 1) ?>"></div>
      <div class="bx-field" style="margin:0;max-width:160px"><label>Gewicht (kg)<?= (string)$v['typ'] === 'palette' ? ' <span class="muted">(gesamt)</span>' : '' ?></label><input type="text" name="gewicht_kg" inputmode="decimal" value="<?= $v['gewicht_kg'] !== null ? h(rtrim(rtrim(number_format((float)$v['gewicht_kg'],3,',','.'),'0'),',')) : '' ?>"></div>
      <?php $mz = fn($x) => $x !== null && $x !== '' ? h(rtrim(rtrim(number_format((float)$x,1,',',''),'0'),',')) : ''; ?>
      <div class="bx-field vs-masse" style="margin:0;max-width:110px"><label>Länge (cm)</label><input type="text" name="masse_l" inputmode="decimal" value="<?= $mz($v['masse_l'] ?? null) ?>" placeholder="120"></div>
      <div class="bx-field vs-masse" style="margin:0;max-width:110px"><label>Breite (cm)</label><input type="text" name="masse_b" inputmode="decimal" value="<?= $mz($v['masse_b'] ?? null) ?>" placeholder="80"></div>
      <div class="bx-field vs-masse" style="margin:0;max-width:110px"><label>Höhe (cm)</label><input type="text" name="masse_h" inputmode="decimal" value="<?= $mz($v['masse_h'] ?? null) ?>" placeholder="100"></div>
      <div class="bx-field" style="margin:0;flex:1;min-width:240px"><label>Notiz (auf dem Lieferschein)</label><input type="text" name="notiz" value="<?= h((string)($v['notiz'] ?? '')) ?>"></div>
    </div>
    <div style="margin-top:var(--sp-4)"><button class="btn btn-primary" type="submit">Speichern</button></div>
  </div>
</form>

<div class="bx-panel" style="margin-bottom:var(--sp-5)">
  <h2 style="margin-top:0">Positionen</h2>
  <?php if (!$pos): ?>
    <div class="muted" style="margin-bottom:var(--sp-3)">Noch nichts drin. Unten aus dem Bestand hinzufügen.</div>
  <?php else: ?>
  <div class="bx-tablewrap" style="margin-bottom:var(--sp-3)">
    <table class="bx-table lg-karten">
      <thead><tr><th>Bezeichnung</th><th>Charge</th><th>Menge</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($pos as $p): ?>
        <tr>
          <td data-label=""><?= h((string)$p['bezeichnung']) ?: '–' ?></td>
          <td data-label="Charge" class="lg-code"><?= h((string)($p['charge_nr'] ?? '')) ?: '–' ?></td>
          <td data-label="Menge"><?= h(menge_txt($p['menge'])) ?> <?= h((string)($p['einheit'] ?? '')) ?><?= (int)$p['abgebucht'] === 1 ? ' <span class="muted">(abgebucht)</span>' : '' ?></td>
          <td data-label="" style="text-align:right">
            <?php if ($geplant): ?><form method="post" style="display:inline" onsubmit="return confirm('Position entfernen?')"><input type="hidden" name="aktion" value="pos_del"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="pos_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">×</button></form><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($geplant): ?>
  <form method="get" class="bx-row" style="gap:6px;margin:0 0 var(--sp-3);flex-wrap:wrap" role="search">
    <input type="hidden" name="p" value="versand_detail"><input type="hidden" name="id" value="<?= $id ?>">
    <input type="search" class="bx-search" name="q" value="<?= h($q) ?>" placeholder="Bestand suchen: Rohstoff, Charge, Artikelnummer" style="flex:1;min-width:220px">
    <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=versand_detail&id=<?= $id ?>">×</a><?php endif; ?>
  </form>
  <?php if ($q !== ''): ?>
    <?php if (!$treffer): ?><div class="muted">Nichts gefunden für „<?= h($q) ?>".</div><?php else: ?>
    <div class="bx-tablewrap">
      <table class="bx-table lg-karten">
        <thead><tr><th>Rohstoff / Produkt</th><th>Charge</th><th>Verfügbar</th><th>Menge</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($treffer as $t): ?>
          <tr>
            <td data-label=""><?= h((string)$t['item_name']) ?></td>
            <td data-label="Charge" class="lg-code"><?= h((string)$t['charge_nr']) ?></td>
            <td data-label="Verfügbar"><?= h(menge_txt($t['menge_verfuegbar'])) ?> <?= h((string)$t['einheit']) ?></td>
            <td data-label="Menge" colspan="2">
              <form method="post" class="bx-row" style="gap:6px;margin:0;justify-content:flex-end">
                <input type="hidden" name="aktion" value="pos_add"><input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="charge_id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
                <input type="text" name="menge" inputmode="decimal" value="<?= h(menge_txt($t['menge_verfuegbar'])) ?>" style="max-width:110px">
                <button class="btn btn-ghost btn-sm" type="submit">Hinzufügen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  <?php endif; ?>
  <?php endif; ?>
</div>

<div class="bx-panel" style="margin-bottom:var(--sp-5)">
  <h2 style="margin-top:0">Versand-Label &amp; Tracking</h2>
  <div class="bx-grid" style="margin-bottom:var(--sp-3)">
    <div class="bx-card"><div class="k">Carrier</div><div class="v"><?= (string)$v['carrier'] === 'dhl' ? 'DHL (Paket)' : ((string)$v['carrier'] === 'cargoboard' ? 'Cargoboard (Fracht)' : '<span class="muted">noch keiner</span>') ?></div></div>
    <div class="bx-card"><div class="k">Sendungsnummer</div><div class="v lg-code"><?= h((string)($v['tracking'] ?? '')) ?: '<span class="muted">–</span>' ?></div></div>
  </div>
  <div class="bx-row" style="gap:var(--sp-3);flex-wrap:wrap">
    <?php if ($geplant): ?>
    <form method="post" onsubmit="return confirm('Jetzt beim Carrier ein Versand-Label erzeugen? (<?= (string)$v['typ'] === 'palette' ? 'Cargoboard' : 'DHL' ?>)')">
      <input type="hidden" name="aktion" value="label"><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn-primary" type="submit"><?= $hatLabel ? 'Label neu erstellen' : 'Versand-Label erstellen' ?></button>
    </form>
    <?php endif; ?>
    <?php if ($hatLabel): ?><a class="btn btn-ghost" href="?p=versand_label&id=<?= $id ?>" target="_blank">Label öffnen (PDF)</a><?php endif; ?>
  </div>
  <div class="muted" style="font-size:12px;margin-top:var(--sp-2)">Paket → DHL, Palette → Cargoboard. Zugänge unter Einstellungen → Zugänge. Ohne Zugang kommt eine klare Meldung.</div>
</div>

<?php if ($geplant): ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Abschließen</h2>
  <div class="bx-row" style="gap:var(--sp-3);flex-wrap:wrap">
    <form method="post" onsubmit="return confirm('Sendung als versendet markieren? Der Bestand der Positionen wird abgebucht.')">
      <input type="hidden" name="aktion" value="versenden"><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn-primary" type="submit">Als versendet markieren &amp; abbuchen</button>
    </form>
    <form method="post" onsubmit="return confirm('Sendung stornieren?')">
      <input type="hidden" name="aktion" value="stornieren"><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn-ghost" type="submit" style="color:#8f231b;border-color:#e6c4c0">Stornieren</button>
    </form>
  </div>
  <div class="muted" style="font-size:12px;margin-top:var(--sp-2)">Carrier-Anbindung (DHL-Paket, Cargoboard-Palette) kommt in Phase 2/3 – dann entstehen hier Label und Tracking automatisch.</div>
</div>
<?php endif; ?>

<script>
(function(){
  var kunde=document.getElementById('vsKunde'), kundeH=document.getElementById('vsKundeH'),
      adr=document.getElementById('vsAdresse'), quelle=document.getElementById('vsQuelle');
  var felder={firma:'empf_firma',name:'empf_name',strasse:'empf_strasse',hausnummer:'empf_hausnummer',plz:'empf_plz',ort:'empf_ort',land:'empf_land',email:'empf_email',telefon:'empf_telefon'};
  var ADR=[];
  function f(n){ return document.querySelector('[name="'+n+'"]'); }
  function fuell(a){ if(!a) return; Object.keys(felder).forEach(function(k){ var el=f(felder[k]); if(el) el.value=(a[k]||''); }); if(quelle) quelle.value=a.quelle||'frei'; }
  function renderAdr(sel){
    adr.innerHTML='';
    if(!ADR.length){ adr.innerHTML='<option value="">– keine Adresse hinterlegt –</option>'; return; }
    ADR.forEach(function(a,i){ var o=document.createElement('option'); o.value=String(i);
      o.textContent=a.label+(a.bevorzugt?' (bevorzugt)':'')+' · '+[a.plz,a.ort].filter(Boolean).join(' ')+(a.land&&a.land!=='DE'?(' / '+a.land):'');
      adr.appendChild(o); });
    var idx = sel!=null ? sel : (ADR.findIndex(function(a){return a.bevorzugt;}));
    if(idx<0) idx=0; adr.value=String(idx);
  }
  function ladeAdressen(kid, autofill){
    if(!kid){ ADR=[]; renderAdr(); return; }
    var fd=new FormData(); fd.append('aktion','adressen'); fd.append('kunde_id',kid);
    fetch('?p=versand_detail&id=<?= $id ?>',{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();}).then(function(j){
        ADR=(j&&j.adressen)||[]; renderAdr();
        if(autofill && ADR.length){ var i=ADR.findIndex(function(a){return a.bevorzugt;}); fuell(ADR[i<0?0:i]); }
      }).catch(function(){ ADR=[]; renderAdr(); });
  }
  if(kunde){ kunde.addEventListener('change',function(){ kundeH.value=kunde.value; ladeAdressen(kunde.value, true); }); }
  if(adr){ adr.addEventListener('change',function(){ var i=parseInt(adr.value,10); if(!isNaN(i)&&ADR[i]) fuell(ADR[i]); }); }
  // Beim Laden: hat die Sendung schon einen Kunden, Adressliste nachladen (ohne Felder zu ueberschreiben).
  if(kunde && kunde.value){ ladeAdressen(kunde.value, false); }

  // Maße-Felder nur bei Palette/Fracht zeigen.
  var typ=document.querySelector('[name="typ"]');
  function masseToggle(){ var pal=typ&&typ.value==='palette'; document.querySelectorAll('.vs-masse').forEach(function(el){ el.style.display=pal?'':'none'; }); }
  if(typ){ typ.addEventListener('change',masseToggle); masseToggle(); }
})();
</script>
<?php fuss();
