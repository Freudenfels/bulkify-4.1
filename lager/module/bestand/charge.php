<?php
// Detailansicht einer Charge: Produkt, Charge, MHD, Menge, Lieferant, Wareneingang, Tracking, Blinker.
$id = (int)($_GET['id'] ?? 0);
$c = erp_charge_voll($id);
if (!$c) { flash('Diese Charge gibt es nicht.', 'warn'); weiter('?p=bestand'); }

// Blinker binden/lösen direkt hier.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'binden') {
        $scan = trim((string)($_POST['code'] ?? ''));
        $code = led_leiste_normalisieren($scan);
        if ($code === null) flash('"' . $scan . '" ist kein Blinker-Code (z. B. CF64B6XD).', 'warn');
        else {
            $fehler = leiste_binden($code, $id);
            if ($fehler !== '') flash($fehler, 'warn');
            else { leiste_finden((int)leiste_per_code($code)['id'], 'blau', 3, false); flash('Blinker ' . $code . ' gebunden, leuchtet kurz blau.'); }
        }
        weiter('?p=charge&id=' . $id);
    }
    if ($aktion === 'loesen') {
        $lid = (int)($_POST['leiste_id'] ?? 0);
        leiste_finden($lid, 'rot', 3, false); leiste_loesen($lid);
        flash('Blinker gelöst.');
        weiter('?p=charge&id=' . $id);
    }
    if ($aktion === 'in_kiste') {
        $fehler = kiste_charge_zuordnen((int)($_POST['kiste_id'] ?? 0), $id, trim((string)($_POST['fach'] ?? '')));
        flash($fehler ?: 'In die Kiste gelegt.', $fehler ? 'warn' : 'ok');
        weiter('?p=charge&id=' . $id);
    }
    if ($aktion === 'aus_kiste') {
        kiste_charge_entfernen($id);
        flash('Aus der Kiste genommen.');
        weiter('?p=charge&id=' . $id);
    }
    if ($aktion === 'umbuchen') {
        $ziel  = (int)($_POST['kunde_id'] ?? 0);
        $menge = ($_POST['menge'] ?? '') !== '' ? (float) str_replace(',', '.', (string)$_POST['menge']) : null;
        $r = erp_charge_umbuchen($id, $ziel > 0 ? $ziel : null, $menge);
        flash($r['meldung'], $r['ok'] ? 'ok' : 'warn');
        weiter('?p=charge&id=' . $id);
    }
    if ($aktion === 'menge_korr') {
        $neu   = (float) str_replace(',', '.', (string)($_POST['menge'] ?? ''));
        $grund = trim((string)($_POST['grund'] ?? ''));
        $r = erp_charge_menge_setzen($id, $neu, $grund);
        if ($r['ok']) {
            $delta = (float)$r['delta'];
            if (abs($delta) > 1e-9) lg_bewegung_log($id, $delta > 0 ? 'ein' : 'aus', abs($delta), (string)$r['einheit'], (string)$c['item_name'], 'Korrektur' . ($grund ? ': ' . $grund : ''));
        }
        flash($r['meldung'], $r['ok'] ? 'ok' : 'warn');
        weiter('?p=charge&id=' . $id);
    }
    if ($aktion === 'loeschen') {
        $grund = trim((string)($_POST['grund'] ?? ''));
        lg_papierkorb_rein($id, (string)$c['item_name'], (string)$c['charge_nr'],
            menge_txt($c['menge_verfuegbar']) . ' ' . (string)$c['einheit'], $grund, (int)(lg_benutzer()['id'] ?? 0) ?: null);
        flash('In den Mülleimer gelegt – 30 Tage wiederherstellbar.', 'ok');
        weiter('?p=bestand');
    }
}

$bl = leiste_fuer_charge($id);
$in_kiste = kiste_fuer_charge($id);
$item = erp_item_voll((int)$c['item_id']);
$produkt_id = $item['produkt_id'] ?? null;
$produkt = $produkt_id ? erp_produkt((int)$produkt_id) : null;
$wirkstoffe = erp_item_wirkstoffe((int)$c['item_id']);
$dokumente = erp_item_dokumente((int)$c['item_id'], $produkt_id ? (int)$produkt_id : null);
$andere = erp_item_chargen((int)$c['item_id'], $id);

kopf('Charge ' . (string)$c['charge_nr'], 'bestand');
$fremd_kunde  = (int)($c['fremd_kunde_id'] ?? 0);
$umb_vorschlag = $fremd_kunde ?: (int)(erp_charge_kunde_vorschlag($id) ?? 0);
$umb_kunden   = erp_fulfillment_kunden();
// Vorgeschlagenen Kunden sicher in die Auswahl aufnehmen (falls nicht als Fulfillment-Kunde markiert).
if ($umb_vorschlag && !array_filter($umb_kunden, fn($k) => (int)$k['id'] === $umb_vorschlag)) {
    $nm = erp_kunde_name($umb_vorschlag);
    if ($nm !== '') array_unshift($umb_kunden, ['id' => $umb_vorschlag, 'firma' => $nm]);
}

$kopfAktion = '<a class="btn btn-' . (isset($_GET['neu']) ? 'primary' : 'ghost') . '" href="?p=etikett&id=' . $id . '" target="_blank">Etikett drucken</a> '
    . (isset($_GET['neu']) ? '<a class="btn btn-ghost" href="?p=eingang">Nächstes Einbuchen</a> ' : '')
    . '<button type="button" class="btn btn-ghost" data-umb-open>Umbuchen</button> '
    . '<a class="btn btn-ghost" href="?p=bestand">Zum Bestand</a>';
seitenkopf((string)$c['item_name'], erp_kategorie_label($c) . ($c['artikelnummer'] ? ' · ' . $c['artikelnummer'] : ''), $kopfAktion);
flash_zeigen();
?>
<?php if (isset($_GET['neu'])): ?>
<div class="bx-panel" style="border:1px solid var(--gruen);margin-bottom:var(--sp-5)">
  <div class="bx-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--sp-2)">
    <h2 style="margin:0">Eingebucht – Etikett für die Kartons</h2>
    <span class="bx-row" style="gap:var(--sp-2);flex-wrap:wrap;align-items:center">
      <button type="button" class="btn btn-primary btn-sm" id="etkDruck">Direkt drucken</button>
      <a class="btn btn-ghost btn-sm" href="?p=etikett&id=<?= $id ?>" target="_blank">Öffnen</a>
      <span id="etkDruckInfo" class="muted"></span>
    </span>
  </div>
  <embed id="etkEmbed" src="?p=etikett&id=<?= $id ?>" type="application/pdf" style="width:100%;height:360px;border:1px solid var(--line);border-radius:8px;background:#fff;margin-top:var(--sp-3)">
</div>
<script>
(function(){
  var druck=document.getElementById('etkDruck'), dinfo=document.getElementById('etkDruckInfo');
  druck.addEventListener('click',function(){
    dinfo.textContent='…';
    var fd=new FormData(); fd.append('ids','<?= $id ?>');
    fetch('?p=druck_job',{method:'POST',body:fd}).then(function(r){return r.json();})
      .then(function(j){ dinfo.textContent=j.ok?(j.meldung||'An den Drucker geschickt.'):('Fehler: '+(j.fehler||'')); })
      .catch(function(){ dinfo.textContent='Serverfehler.'; });
  });
})();
</script>
<?php endif; ?>
<div class="bx-grid" style="margin-bottom:var(--sp-5)">
  <div class="bx-card"><div class="k">Bestand</div><div class="v"><?= h(menge_txt($c['menge_verfuegbar'])) ?> <?= h((string)$c['einheit']) ?></div></div>
  <div class="bx-card"><div class="k">MHD</div><div class="v" style="font-size:var(--fs-lg)"><?= mhd_html($c['mhd']) ?></div></div>
  <div class="bx-card"><div class="k">Status</div><div class="v" style="font-size:var(--fs-lg)"><?= status_badge($c['status']) ?></div></div>
  <div class="bx-card"><div class="k">Charge</div><div class="v lg-code" style="font-size:var(--fs-lg)"><?= h((string)$c['charge_nr']) ?: '–' ?></div></div>
  <div class="bx-card"><div class="k">Lager</div><div class="v" style="font-size:var(--fs-lg)"><?= $fremd_kunde ? 'Lager 2 · ' . h(erp_kunde_name($fremd_kunde)) : 'Lager 1 · eigener Bestand' ?></div></div>
  <?php $trk = function_exists('lg_tracking') ? lg_tracking($id) : ''; if ($trk !== ''): ?>
  <div class="bx-card"><div class="k">Sendung / Paket</div><div class="v lg-code" style="font-size:var(--fs-lg)"><?= h($trk) ?></div></div>
  <?php endif; ?>
</div>

<div class="umb-overlay" id="umbModal" hidden>
  <div class="umb-box bx-panel">
    <h2 style="margin-top:0">Umbuchen</h2>
    <?php if ($fremd_kunde): ?>
      <p>Aktuell: <strong>Lager 2</strong> (<?= h(erp_kunde_name($fremd_kunde)) ?>). Menge zurück in den eigenen Bestand (Lager 1):</p>
      <form method="post">
        <input type="hidden" name="aktion" value="umbuchen"><input type="hidden" name="kunde_id" value="0">
        <div class="bx-field"><label>Menge <span class="muted">(von <?= h(menge_txt($c['menge_verfuegbar'])) ?> <?= h((string)$c['einheit']) ?>)</span></label>
          <input type="text" inputmode="decimal" name="menge" value="<?= h(menge_txt($c['menge_verfuegbar'])) ?>" autofocus>
          <div class="muted" style="font-size:12px;margin-top:4px">Weniger = Teilmenge; der Rest bleibt in Lager 2.</div></div>
        <div class="bx-row" style="justify-content:flex-end;gap:var(--sp-2)">
          <button type="button" class="btn btn-ghost" data-umb-close>Abbrechen</button>
          <button type="submit" class="btn btn-primary">Zurück nach Lager 1</button>
        </div>
      </form>
    <?php else: ?>
      <p>Fertige Ware ins <strong>Fremdlager (Lager 2)</strong> des Kunden buchen. Teilmenge möglich – der Rest bleibt in Lager 1.</p>
      <?php if (!$umb_kunden): ?>
        <p class="muted">Noch keine Fulfillment-Kunden hinterlegt (Haken „nutzt Fulfillment" im Dashboard).</p>
        <div class="bx-row" style="justify-content:flex-end"><button type="button" class="btn btn-ghost" data-umb-close>Schließen</button></div>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="aktion" value="umbuchen">
        <div class="bx-field"><label>Kunde (Fremdlager)</label>
          <select name="kunde_id" required>
            <option value="">– Kunde wählen –</option>
            <?php foreach ($umb_kunden as $k): ?><option value="<?= (int)$k['id'] ?>" <?= $umb_vorschlag === (int)$k['id'] ? 'selected' : '' ?>><?= h((string)$k['firma']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="bx-field"><label>Menge <span class="muted">(von <?= h(menge_txt($c['menge_verfuegbar'])) ?> <?= h((string)$c['einheit']) ?>)</span></label>
          <input type="text" inputmode="decimal" name="menge" value="<?= h(menge_txt($c['menge_verfuegbar'])) ?>">
          <div class="muted" style="font-size:12px;margin-top:4px">Weniger als verfügbar = Teilmenge; der Rest bleibt in Lager 1.</div></div>
        <div class="bx-row" style="justify-content:flex-end;gap:var(--sp-2)">
          <button type="button" class="btn btn-ghost" data-umb-close>Abbrechen</button>
          <button type="submit" class="btn btn-primary">Umbuchen</button>
        </div>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<style>
  .umb-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;z-index:200;padding:16px}
  .umb-overlay[hidden]{display:none}
  .umb-box{max-width:460px;width:100%;margin:0}
</style>
<script>
(function(){var m=document.getElementById('umbModal'); if(!m)return;
  function zu(){m.hidden=true;} function auf(){m.hidden=false; var f=m.querySelector('input[name=menge],select'); if(f)f.focus();}
  document.querySelectorAll('[data-umb-open]').forEach(function(b){b.addEventListener('click',auf);});
  document.querySelectorAll('[data-umb-close]').forEach(function(b){b.addEventListener('click',zu);});
  m.addEventListener('click',function(e){if(e.target===m)zu();});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')zu();});
})();
</script>

<div class="bx-panel">
  <h2>Blinker</h2>
  <?php if ($in_kiste): ?>
    <p>Diese Charge liegt in <a class="lg-namelink" href="?p=kiste&id=<?= (int)$in_kiste['kiste_id'] ?>">Kiste <?= h((string)$in_kiste['kiste_name']) ?></a><?= $in_kiste['fach'] ? ', Fach ' . h((string)$in_kiste['fach']) : '' ?>. Beim Suchen blinkt die Kiste.</p>
    <?php $kb = kiste_blinker((int)$in_kiste['kiste_id']); if ($kb): ?>
      <div class="bx-row" style="gap:var(--sp-3)">
        <button type="button" class="btn btn-primary" data-klingeln="<?= (int)$kb['id'] ?>">Kiste finden</button>
        <button type="button" class="btn btn-ghost" data-klingeln="<?= (int)$kb['id'] ?>" data-aktion="aus">Aus</button>
      </div>
    <?php else: ?><p class="muted">An der Kiste hängt noch kein Blinker.</p><?php endif; ?>
    <form method="post" style="margin-top:var(--sp-3)" onsubmit="return confirm('Charge aus der Kiste nehmen?')">
      <input type="hidden" name="aktion" value="aus_kiste">
      <button class="btn btn-ghost btn-sm" type="submit">Aus der Kiste nehmen</button>
    </form>
  <?php elseif ($bl): ?>
    <p>Am Blinker <span class="lg-code"><strong><?= h((string)$bl['code']) ?></strong></span> seit <?= h(fmt_zeit((string)$bl['gebunden_am'])) ?>.</p>
    <div class="bx-row" style="gap:var(--sp-3)">
      <button type="button" class="btn btn-primary" data-klingeln="<?= (int)$bl['id'] ?>">Finden</button>
      <button type="button" class="btn btn-ghost" data-klingeln="<?= (int)$bl['id'] ?>" data-aktion="aus">Aus</button>
      <form method="post" style="display:inline" onsubmit="return confirm('Blinker <?= h((string)$bl['code']) ?> lösen?')">
        <input type="hidden" name="aktion" value="loesen"><input type="hidden" name="leiste_id" value="<?= (int)$bl['id'] ?>">
        <button class="btn btn-ghost lg-x" type="submit" title="Blinker lösen">×</button>
      </form>
    </div>
  <?php else: ?>
    <p class="muted">Kein Blinker an dieser Charge. Entweder einen eigenen Blinker anhängen – oder die Charge in eine Kiste legen, die schon einen hat.</p>
    <form method="post" class="bx-row" style="gap:6px;margin-bottom:var(--sp-3)" data-no-busy>
      <input type="hidden" name="aktion" value="binden">
      <input name="code" class="lg-code" style="max-width:200px" placeholder="Blinker scannen (CF64B6XD)" autocomplete="off">
      <button class="btn btn-primary" type="submit">Eigener Blinker</button>
    </form>
    <?php $kisten = kiste_alle(); if ($kisten): ?>
    <form method="post" class="bx-row" style="gap:6px;flex-wrap:wrap;align-items:flex-end" data-no-busy>
      <input type="hidden" name="aktion" value="in_kiste">
      <div class="bx-field" style="margin:0"><label>In eine Kiste</label>
        <select name="kiste_id">
          <?php foreach ($kisten as $k): ?><option value="<?= (int)$k['id'] ?>"><?= h((string)$k['name']) ?><?= $k['blinker'] ? '' : ' (ohne Blinker)' ?></option><?php endforeach; ?>
        </select></div>
      <div class="bx-field" style="margin:0"><label>Fach (optional)</label><input name="fach" placeholder="z. B. vorne links" style="max-width:160px"></div>
      <button class="btn btn-ghost" type="submit">In die Kiste</button>
    </form>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="bx-panel">
  <h2>Charge und Lieferung</h2>
  <table class="bx-table"><tbody>
    <tr><td class="muted" style="width:200px">Chargennummer</td><td class="lg-code"><?= h((string)$c['charge_nr']) ?: '–' ?></td></tr>
    <tr><td class="muted">Eingegangene Menge</td><td><?= h(menge_txt($c['menge'] ?? null)) ?> <?= h((string)$c['einheit']) ?></td></tr>
    <tr><td class="muted">Verfügbar</td><td><?= h(menge_txt($c['menge_verfuegbar'])) ?> <?= h((string)$c['einheit']) ?></td></tr>
    <tr><td class="muted">MHD</td><td><?= mhd_html($c['mhd']) ?></td></tr>
    <tr><td class="muted">Status</td><td><?= status_badge($c['status']) ?></td></tr>
    <tr><td class="muted">Lieferant</td><td><?= h((string)($c['lieferant'] ?? '')) ?: '–' ?></td></tr>
    <tr><td class="muted">Wareneingang</td><td><?= $c['wareneingang'] ? h(date('d.m.Y', strtotime((string)$c['wareneingang']))) : '–' ?></td></tr>
    <?php if (!empty($c['tracking'])): ?><tr><td class="muted">Sendungsnummer(n)</td><td><?= nl2br(h((string)$c['tracking'])) ?></td></tr><?php endif; ?>
    <?php if (!empty($c['notiz'])): ?><tr><td class="muted">Notiz</td><td><?= nl2br(h((string)$c['notiz'])) ?></td></tr><?php endif; ?>
  </tbody></table>

  <?php if ($dokumente): ?>
  <h3 style="margin:var(--sp-4) 0 var(--sp-2)">Dokumente</h3>
  <div class="bx-row" style="gap:8px;flex-wrap:wrap">
    <?php foreach ($dokumente as $d): ?>
      <a class="btn btn-ghost btn-sm" href="?p=dok&id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener">
        <?= h(ucfirst((string)$d['typ'])) ?><?= $d['charge_nr'] ? ' · ' . h((string)$d['charge_nr']) : '' ?>
        <?= $d['datei_orig'] ? ' <span class="muted">' . h((string)$d['datei_orig']) . '</span>' : '' ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="bx-panel">
  <h2>Produkt</h2>
  <table class="bx-table"><tbody>
    <tr><td class="muted" style="width:200px">Name</td><td><?= h((string)$c['item_name']) ?></td></tr>
    <?php if (!empty($item['name_en'])): ?><tr><td class="muted">Englisch</td><td><?= h((string)$item['name_en']) ?></td></tr><?php endif; ?>
    <?php if (!empty($item['name_lat'])): ?><tr><td class="muted">Botanisch/Latein</td><td><?= h((string)$item['name_lat']) ?></td></tr><?php endif; ?>
    <?php if (!empty($item['cas'])): ?><tr><td class="muted">CAS</td><td><?= h((string)$item['cas']) ?></td></tr><?php endif; ?>
    <tr><td class="muted">Kategorie</td><td><?= h(erp_kategorie_label($c)) ?></td></tr>
    <tr><td class="muted">Artikelnummer</td><td><?= h((string)$c['artikelnummer']) ?: '–' ?></td></tr>
    <?php if (!empty($item['form'])): ?><tr><td class="muted">Form</td><td><?= h((string)$item['form']) ?></td></tr><?php endif; ?>
    <?php if (!empty($item['dichte'])): ?><tr><td class="muted">Dichte</td><td><?= h(menge_txt($item['dichte'])) ?> g/ml</td></tr><?php endif; ?>
    <?php if (!empty($item['allergene'])): ?><tr><td class="muted">Allergene</td><td><?= h((string)$item['allergene']) ?></td></tr><?php endif; ?>
    <?php if (!empty($item['herkunft'])): ?><tr><td class="muted">Herkunft</td><td><?= h((string)$item['herkunft']) ?></td></tr><?php endif; ?>
    <?php if ($produkt && !empty($produkt['kundenname'])): ?><tr><td class="muted">Kunde</td><td><?= h((string)$produkt['kundenname']) ?></td></tr><?php endif; ?>
    <?php if (!empty($item['notiz'])): ?><tr><td class="muted">Notiz</td><td><?= nl2br(h((string)$item['notiz'])) ?></td></tr><?php endif; ?>
  </tbody></table>

  <?php if ($wirkstoffe): ?>
  <h3 style="margin:var(--sp-4) 0 var(--sp-2)">Wirkstoffe</h3>
  <table class="bx-table"><tbody>
    <?php foreach ($wirkstoffe as $w): ?>
      <tr><td><?= h((string)$w['name']) ?></td>
        <td style="text-align:right"><?= $w['gehalt_wert'] !== null && $w['gehalt_wert'] !== '' ? h(menge_txt($w['gehalt_wert']) . ' ' . (string)$w['gehalt_einheit']) : ($w['gehalt_prozent'] !== null ? h(menge_txt($w['gehalt_prozent']) . ' %') : '') ?></td></tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>

<?php if ($andere): ?>
<div class="bx-panel">
  <h2>Weitere Chargen dieses Produkts</h2>
  <div class="bx-tablewrap">
    <table class="bx-table">
      <thead><tr><th>Charge</th><th>MHD</th><th>Bestand</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($andere as $a): ?>
        <tr onclick="location.href='?p=charge&id=<?= (int)$a['id'] ?>'" style="cursor:pointer">
          <td class="lg-code"><?= h((string)$a['charge_nr']) ?: '–' ?></td>
          <td><?= mhd_html($a['mhd']) ?></td>
          <td><?= h(menge_txt($a['menge_verfuegbar'])) ?> <?= h((string)$a['einheit']) ?></td>
          <td><?= status_badge($a['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="bx-panel">
  <h2>Bestand korrigieren / löschen</h2>
  <form method="post" class="bx-row" style="gap:var(--sp-3);align-items:flex-end;flex-wrap:wrap;margin-bottom:var(--sp-3)">
    <input type="hidden" name="aktion" value="menge_korr">
    <div class="bx-field" style="margin:0;max-width:170px"><label>Neue Menge (<?= h((string)$c['einheit']) ?>)</label>
      <input type="text" inputmode="decimal" name="menge" value="<?= h(menge_txt($c['menge_verfuegbar'])) ?>"></div>
    <div class="bx-field" style="margin:0;min-width:200px;flex:1"><label>Grund (optional)</label>
      <input type="text" name="grund" placeholder="z. B. Zählkorrektur, Bruch"></div>
    <button type="submit" class="btn btn-primary">Bestand setzen</button>
  </form>
  <form method="post" class="bx-row" style="gap:var(--sp-3);align-items:flex-end;flex-wrap:wrap" onsubmit="return confirm('Diese Charge in den Mülleimer legen? 30 Tage wiederherstellbar.')">
    <input type="hidden" name="aktion" value="loeschen">
    <div class="bx-field" style="margin:0;min-width:200px;flex:1"><label>Grund (optional)</label>
      <input type="text" name="grund" placeholder="z. B. Fehlbuchung"></div>
    <button type="submit" class="btn btn-ghost" style="color:#8f231b;border-color:#e6c4c0">In den Mülleimer</button>
  </form>
  <div class="muted" style="font-size:12px;margin-top:var(--sp-2)">Löschen blendet die Charge nur aus (das Dashboard behält sie). Wiederherstellen im <a href="?p=papierkorb">Mülleimer</a>.</div>
</div>
<?php
fuss();
