<?php
// Partnerkonto (Cockpit) & Bearbeiten – HYBRID: Kunde + Lieferant. Statt Marken: SubKunden.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

$KATS   = ['rohstoff'=>'Rohstoff','verpackung'=>'Verpackung','verbrauch'=>'Verbrauch','maschine'=>'Maschine','labor'=>'Labor','fertigprodukt'=>'Fertige Produkte'];
$FORMEN = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig'];

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = fn($k) => trim($_POST[$k] ?? '');
    if ($f('firma') === '') {
        $fehler = 'Firma ist ein Pflichtfeld.';
    } else {
        $felder = ['partnernummer','firma','ansprechpartner','email','telefon','gesperrt','sprache','webseite',
                   'strasse','hausnummer','plz','ort','land','ust_id',
                   'zahlungsart_kunde','zahlungsziel_kunde','rabatt_marge','aufschlag_marge',
                   'kategorien','fertig_formen','waehrung','zahlungsart_lief','zahlungsziel_lief','lieferzeit_tage','mindestbestellwert','notiz'];
        $vals = array_map($f, $felder);
        $vals[array_search('gesperrt', $felder)] = isset($_POST['gesperrt']) ? 1 : 0;
        foreach (['zahlungsziel_kunde','rabatt_marge','aufschlag_marge','zahlungsziel_lief','lieferzeit_tage','mindestbestellwert'] as $nf) { $ix = array_search($nf, $felder); if (trim((string)$vals[$ix]) === '') $vals[$ix] = 0; }
        $katsSel = array_keys(array_intersect_key($KATS, (array)($_POST['kat'] ?? [])));
        $vals[array_search('kategorien', $felder)] = implode(',', $katsSel);
        $formenSel = in_array('fertigprodukt', $katsSel, true)
            ? array_keys(array_intersect_key($FORMEN, (array)($_POST['form'] ?? [])))
            : [];
        $vals[array_search('fertig_formen', $felder)] = implode(',', $formenSel);
        if ($neu) {
            if (trim($vals[array_search('partnernummer', $felder)]) === '') $vals[array_search('partnernummer', $felder)] = naechste_nummer('PA');
            $ph = implode(',', array_fill(0, count($felder), '?'));
            q("INSERT INTO partner (" . implode(',', $felder) . ") VALUES ($ph)", $vals);
            $id = insert_id();
            log_aktivitaet('partner', (int)$id, 'team', 'Partner angelegt.', 'notiz');
        } else {
            $set = implode(',', array_map(fn($c) => "$c=?", $felder));
            $vals[] = (int)$id;
            q("UPDATE partner SET $set WHERE id=?", $vals);
        }
        // SubKunden synchronisieren
        q("DELETE FROM partner_subkunde WHERE partner_id=?", [(int)$id]);
        $snamen = $_POST['sub_name'] ?? [];
        $skenn  = $_POST['sub_kennung'] ?? [];
        foreach ($snamen as $i => $nm) {
            $nm = trim($nm); $kn = trim($skenn[$i] ?? '');
            if ($nm === '' && $kn === '') continue;
            q("INSERT INTO partner_subkunde (partner_id,name,kennung,sort) VALUES (?,?,?,?)", [(int)$id, $nm, $kn, $i]);
        }
        header('Location: ?p=partner_detail&id=' . $id . '&gespeichert=1'); exit;
    }
}

$p = $neu
    ? ['gesperrt'=>0,'land'=>'DE','sprache'=>'de','waehrung'=>'EUR','zahlungsart_kunde'=>'vorkasse','zahlungsart_lief'=>'rechnung']
    : one("SELECT * FROM partner WHERE id=?", [(int)$id]);
if (!$p) { $neu = true; $p = ['gesperrt'=>0,'land'=>'DE','sprache'=>'de','waehrung'=>'EUR','zahlungsart_kunde'=>'vorkasse','zahlungsart_lief'=>'rechnung']; }
$v = fn($key) => h((string)($p[$key] ?? ''));
$gesperrt = (int)($p['gesperrt'] ?? 0) === 1;
$aktKats = array_filter(explode(',', (string)($p['kategorien'] ?? '')));
$aktFormen = array_filter(explode(',', (string)($p['fertig_formen'] ?? '')));
$hatFertig = in_array('fertigprodukt', $aktKats, true);
$subkunden = $neu ? [] : all("SELECT * FROM partner_subkunde WHERE partner_id=? ORDER BY sort,id", [(int)$id]);
if (!$neu) { seed_aktivitaet_if_empty(); $verlauf = verlauf_fuer('partner', (int)$id); } else { $verlauf = []; }

function bx_bald(string $modul): void {
    echo '<div class="bx-tablewrap"><table class="bx-table"><tbody><tr><td class="muted">'
       . 'Sobald das Modul <strong>' . h($modul) . '</strong> steht, erscheinen hier automatisch die Vorgänge dieses Partners.'
       . '</td></tr></tbody></table></div>';
}

render_header('partner', $neu ? 'Neuer Partner' : $p['firma']);
bx_head($neu ? 'Neuer Partner' : $v('firma'),
        $neu ? 'Hybrid: Kunde + Lieferant' : trim(($v('partnernummer') ? $v('partnernummer') . ' · ' : '') . $v('ort') . ' · ' . $v('land') . ' · Hybrid'),
        bx_btn('Zurück zur Liste', '?p=partner', 'ghost'));

if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($fehler) . '</div>';

if (!$neu) {
    echo '<div class="bx-cards">';
    echo '<div class="bx-card"><div class="k">Status</div><div class="v">' . ($gesperrt ? bx_badge('gesperrt','err') : bx_badge('aktiv','ok')) . '</div></div>';
    echo '<div class="bx-card"><div class="k">SubKunden</div><div class="v">' . count($subkunden) . '</div></div>';
    echo '<div class="bx-card"><div class="k">Umsatz (als Kunde)</div><div class="v muted">–</div></div>';
    echo '<div class="bx-card"><div class="k">Einkauf (als Lieferant)</div><div class="v muted">–</div></div>';
    echo '<div class="bx-card"><div class="k">Offene Rechnungen</div><div class="v muted">–</div></div>';
    echo '</div>';
}
?>
<form method="post" class="bx-form">
  <div class="settabs" id="ptabs">
    <?php if (!$neu): ?>
    <a href="#" class="on" data-tab="ueber">Übersicht</a>
    <a href="#" data-tab="sub">SubKunden</a>
    <a href="#" data-tab="alskunde">Als Kunde</a>
    <a href="#" data-tab="alslief">Als Lieferant</a>
    <a href="#" data-tab="verlauf">Verlauf</a>
    <a href="#" data-tab="stamm">Stammdaten</a>
    <?php else: ?>
    <a href="#" class="on" data-tab="stamm">Stammdaten</a>
    <a href="#" data-tab="sub">SubKunden</a>
    <?php endif; ?>
    <a href="#" data-tab="adr">Adresse</a>
    <a href="#" data-tab="kond">Konditionen</a>
  </div>

  <?php if (!$neu): ?>
  <section data-panel="ueber">
    <div class="bx-panel">
      <h2>Kontakt</h2>
      <div class="bx-grid">
        <div><div class="k muted">Ansprechpartner</div><div><?= $v('ansprechpartner') ?: '–' ?></div></div>
        <div><div class="k muted">E-Mail</div><div><?= $v('email') ?: '–' ?></div></div>
        <div><div class="k muted">Telefon</div><div><?= $v('telefon') ?: '–' ?></div></div>
        <div><div class="k muted">Sprache</div><div><?= h(strtoupper($v('sprache'))) ?></div></div>
      </div>
      <?php if ($aktKats): ?>
      <hr class="bx"><div class="k muted">Kann liefern / fertigen</div>
      <div class="bx-row" style="margin-top:6px"><?php foreach ($aktKats as $kk) echo bx_badge($KATS[$kk] ?? $kk, $kk==='fertigprodukt'?'info':''); foreach ($aktFormen as $ff) echo bx_badge($FORMEN[$ff] ?? $ff, 'ok'); ?></div>
      <?php endif; ?>
    </div>
    <div class="bx-panel">
      <h2>SubKunden <?= bx_hint('die Kunden des Partners – wichtig zur Unterscheidung in der Produktion') ?></h2>
      <?php if ($subkunden): ?>
        <div class="bx-row"><?php foreach ($subkunden as $s) echo bx_badge(trim(($s['kennung']?$s['kennung'].' – ':'').$s['name'])); ?></div>
      <?php else: ?><div class="muted">Noch keine SubKunden eingetragen.</div><?php endif; ?>
    </div>
  </section>

  <section data-panel="sub" hidden>
    <div class="bx-panel">
      <h2>SubKunden <?= bx_hint('Kunden des Partners. Kennung = kurzes Kürzel für Produktion/Etikett') ?></h2>
      <div id="subrows">
        <?php $rows = $subkunden ?: [['name'=>'','kennung'=>'']]; foreach ($rows as $s): ?>
        <div class="bx-row subrow" style="flex-wrap:nowrap;margin-bottom:8px">
          <input type="text" name="sub_name[]" value="<?= h($s['name']) ?>" placeholder="Name / Firma" style="flex:1">
          <input type="text" name="sub_kennung[]" value="<?= h($s['kennung']) ?>" placeholder="Kürzel" style="width:120px">
          <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.subrow').remove()">entfernen</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" id="addSub">+ SubKunde</button>
    </div>
  </section>

  <section data-panel="alskunde" hidden>
    <div class="bx-panel"><h2>Als Kunde – Anfragen, Angebote &amp; Bestellungen bei uns</h2><?php bx_bald('Angebote / Bestellungen'); ?></div>
  </section>
  <section data-panel="alslief" hidden>
    <div class="bx-panel"><h2>Als Lieferant – unsere Anfragen, Bestellungen &amp; Rechnungen</h2><?php bx_bald('Bestellungen / Rechnungen'); ?></div>
  </section>
  <section data-panel="verlauf" hidden>
    <div class="bx-panel">
      <h2>Aktivitätsverlauf <?= bx_hint('links = wir (bulkify), rechts = Partner. Kunden- und Lieferanten-Aktionen in einem Faden') ?></h2>
      <?php bx_chat($verlauf, $v('firma')); ?>
    </div>
  </section>
  <?php endif; ?>

  <section data-panel="stamm" <?= $neu ? '' : 'hidden' ?>>
    <div class="bx-panel"><div class="bx-grid">
      <div class="bx-field"><label>Partnernummer <?= bx_hint('leer lassen = wird automatisch vergeben (PA-…)') ?></label><input type="text" name="partnernummer" value="<?= $v('partnernummer') ?>" placeholder="<?= $neu ? 'automatisch (PA-…)' : '' ?>"></div>
      <div class="bx-field"><label>Firma</label><input type="text" name="firma" value="<?= $v('firma') ?>" required></div>
      <div class="bx-field"><label>Ansprechpartner</label><input type="text" name="ansprechpartner" value="<?= $v('ansprechpartner') ?>"></div>
      <div class="bx-field"><label>E-Mail</label><input type="email" name="email" value="<?= $v('email') ?>"></div>
      <div class="bx-field"><label>Telefon</label><input type="text" name="telefon" value="<?= $v('telefon') ?>"></div>
      <div class="bx-field"><label>Sprache</label>
        <select name="sprache">
          <?php foreach (['de'=>'Deutsch','en'=>'English','zh'=>'中文'] as $s=>$lbl): ?>
            <option value="<?= $s ?>" <?= ($p['sprache']??'')===$s?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Webseite</label><input type="text" name="webseite" value="<?= $v('webseite') ?>" placeholder="https://…"></div>
      <div class="bx-field"><label>Partner sperren <?= bx_hint('Schutzfunktion: gesperrte Partner können weder bestellen noch beliefern') ?></label>
        <div class="bx-check" style="padding-top:8px">
          <input type="checkbox" name="gesperrt" id="f_gesperrt" value="1" <?= $gesperrt?'checked':'' ?>>
          <label for="f_gesperrt" style="margin:0">Partner ist gesperrt</label>
        </div>
      </div>
    </div>
    <div class="bx-field"><label>Notiz (intern)</label><textarea name="notiz"><?= $v('notiz') ?></textarea></div>
    </div>
  </section>

  <section data-panel="adr" hidden>
    <div class="bx-panel">
      <h2>Adresse</h2>
      <div class="bx-grid">
        <div class="bx-field"><label>Straße / Hausnummer</label>
          <div class="bx-row" style="flex-wrap:nowrap">
            <input type="text" name="strasse" value="<?= $v('strasse') ?>" style="flex:1" placeholder="Straße">
            <input type="text" name="hausnummer" value="<?= $v('hausnummer') ?>" style="width:100px" placeholder="Nr.">
          </div>
        </div>
        <div class="bx-field"><label>PLZ / Ort</label>
          <div class="bx-row" style="flex-wrap:nowrap">
            <input type="text" name="plz" value="<?= $v('plz') ?>" style="width:110px" placeholder="PLZ">
            <input type="text" name="ort" value="<?= $v('ort') ?>" style="flex:1" placeholder="Ort">
          </div>
        </div>
        <div class="bx-field"><label>Land</label><input type="text" name="land" value="<?= $v('land') ?>" maxlength="2"></div>
        <div class="bx-field"><label>USt-ID</label><input type="text" name="ust_id" value="<?= $v('ust_id') ?>"></div>
      </div>
    </div>
  </section>

  <section data-panel="kond" hidden>
    <div class="bx-panel">
      <h2>Als Kunde <?= bx_hint('Konditionen, wenn der Partner bei uns bestellt') ?></h2>
      <div class="bx-grid">
        <div class="bx-field"><label>Zahlungsart</label>
          <select name="zahlungsart_kunde">
            <?php foreach (['vorkasse'=>'Vorkasse','rechnung'=>'Rechnung','lastschrift'=>'Lastschrift'] as $s=>$lbl): ?>
              <option value="<?= $s ?>" <?= ($p['zahlungsart_kunde']??'')===$s?'selected':'' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="bx-field"><label>Zahlungsziel (Tage)</label><input type="number" name="zahlungsziel_kunde" value="<?= $v('zahlungsziel_kunde') ?>"></div>
        <div class="bx-field"><label>Rabatt auf Marge (%)</label><input type="number" step="0.01" name="rabatt_marge" value="<?= $v('rabatt_marge') ?>"></div>
        <div class="bx-field"><label>Aufschlag auf Marge (%)</label><input type="number" step="0.01" name="aufschlag_marge" value="<?= $v('aufschlag_marge') ?>"></div>
      </div>
    </div>

    <div class="bx-panel">
      <h2>Als Lieferant <?= bx_hint('Konditionen, wenn der Partner für uns fertigt/liefert') ?></h2>
      <div class="bx-field"><label>Kann liefern / fertigen</label>
        <div class="bx-row">
          <?php foreach ($KATS as $key=>$lbl): ?>
            <label class="bx-check"><input type="checkbox" name="kat[<?= $key ?>]" value="1" <?= in_array($key,$aktKats,true)?'checked':'' ?> <?= $key==='fertigprodukt'?'id="kat_fertig"':'' ?>> <?= $lbl ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="bx-field" id="formenBlock" <?= $hatFertig ? '' : 'hidden' ?>>
        <label>Fertige Produkte – welche Formen?</label>
        <div class="bx-row">
          <?php foreach ($FORMEN as $key=>$lbl): ?>
            <label class="bx-check"><input type="checkbox" name="form[<?= $key ?>]" value="1" <?= in_array($key,$aktFormen,true)?'checked':'' ?>> <?= $lbl ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="bx-grid">
        <div class="bx-field"><label>Währung</label>
          <select name="waehrung">
            <?php foreach (['EUR'=>'EUR €','USD'=>'USD $','CNY'=>'CNY ¥','GBP'=>'GBP £'] as $s=>$lbl): ?>
              <option value="<?= $s ?>" <?= ($p['waehrung']??'')===$s?'selected':'' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="bx-field"><label>Zahlungsart</label>
          <select name="zahlungsart_lief">
            <?php foreach (['rechnung'=>'Rechnung','vorkasse'=>'Vorkasse'] as $s=>$lbl): ?>
              <option value="<?= $s ?>" <?= ($p['zahlungsart_lief']??'')===$s?'selected':'' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="bx-field"><label>Zahlungsziel (Tage)</label><input type="number" name="zahlungsziel_lief" value="<?= $v('zahlungsziel_lief') ?>"></div>
        <div class="bx-field"><label>Standard-Lieferzeit (Tage)</label><input type="number" name="lieferzeit_tage" value="<?= $v('lieferzeit_tage') ?>"></div>
        <div class="bx-field"><label>Mindestbestellwert</label><input type="number" step="0.01" name="mindestbestellwert" value="<?= $v('mindestbestellwert') ?>"></div>
      </div>
    </div>
  </section>

  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit"><?= $neu ? 'Partner anlegen' : 'Speichern' ?></button>
    <a class="btn btn-ghost" href="?p=partner">Abbrechen</a>
  </div>
</form>

<script>
(function(){
  var tabs = document.querySelectorAll('#ptabs a');
  tabs.forEach(function(t){
    t.addEventListener('click', function(e){
      e.preventDefault();
      tabs.forEach(function(x){ x.classList.remove('on'); });
      t.classList.add('on');
      document.querySelectorAll('[data-panel]').forEach(function(pp){
        pp.hidden = (pp.getAttribute('data-panel') !== t.getAttribute('data-tab'));
      });
    });
  });
  var add = document.getElementById('addSub');
  if (add) add.addEventListener('click', function(){
    var row = document.createElement('div');
    row.className = 'bx-row subrow';
    row.style.cssText = 'flex-wrap:nowrap;margin-bottom:8px';
    row.innerHTML = '<input type="text" name="sub_name[]" placeholder="Name / Firma" style="flex:1">'
      + '<input type="text" name="sub_kennung[]" placeholder="Kürzel" style="width:120px">'
      + '<button type="button" class="btn btn-ghost btn-sm">entfernen</button>';
    row.querySelector('button').addEventListener('click', function(){ row.remove(); });
    document.getElementById('subrows').appendChild(row);
  });
  var katFertig = document.getElementById('kat_fertig');
  var formenBlock = document.getElementById('formenBlock');
  if (katFertig && formenBlock) katFertig.addEventListener('change', function(){ formenBlock.hidden = !katFertig.checked; });
})();
</script>
<?php
render_footer();
