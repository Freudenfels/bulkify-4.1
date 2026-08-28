<?php
// Lieferantenkonto (Cockpit) & Bearbeiten – gleiches Muster wie Kundenkonto
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

$KATS = ['rohstoff'=>'Rohstoff','verpackung'=>'Verpackung','verbrauch'=>'Verbrauch','maschine'=>'Maschine','labor'=>'Labor','fertigprodukt'=>'Fertige Produkte'];
$FORMEN = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig'];

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = fn($k) => trim($_POST[$k] ?? '');
    if ($f('firma') === '') {
        $fehler = 'Firma ist ein Pflichtfeld.';
    } else {
        $felder = ['lieferantennummer','firma','ansprechpartner','email','telefon','gesperrt','sprache','kategorien','fertig_formen','webseite',
                   'strasse','hausnummer','plz','ort','land','ust_id',
                   'waehrung','zahlungsart','zahlungsziel_tage','lieferzeit_tage','mindestbestellwert','notiz'];
        $vals = array_map($f, $felder);
        $vals[array_search('gesperrt', $felder)]   = isset($_POST['gesperrt']) ? 1 : 0;
        foreach (['zahlungsziel_tage','lieferzeit_tage','mindestbestellwert'] as $nf) { $ix = array_search($nf, $felder); if (trim((string)$vals[$ix]) === '') $vals[$ix] = 0; }
        $katsSel = array_keys(array_intersect_key($KATS, (array)($_POST['kat'] ?? [])));
        $vals[array_search('kategorien', $felder)] = implode(',', $katsSel);
        // Formen nur speichern, wenn „Fertige Produkte" gewählt ist
        $formenSel = in_array('fertigprodukt', $katsSel, true)
            ? array_keys(array_intersect_key($FORMEN, (array)($_POST['form'] ?? [])))
            : [];
        $vals[array_search('fertig_formen', $felder)] = implode(',', $formenSel);
        if ($neu) {
            if (trim($vals[array_search('lieferantennummer', $felder)]) === '') $vals[array_search('lieferantennummer', $felder)] = naechste_nummer('L');
            $ph = implode(',', array_fill(0, count($felder), '?'));
            q("INSERT INTO lieferanten (" . implode(',', $felder) . ") VALUES ($ph)", $vals);
            $id = insert_id();
            log_aktivitaet('lieferant', (int)$id, 'team', 'Lieferant angelegt.', 'notiz');
        } else {
            $set = implode(',', array_map(fn($c) => "$c=?", $felder));
            $vals[] = (int)$id;
            q("UPDATE lieferanten SET $set WHERE id=?", $vals);
        }
        header('Location: ?p=lieferant&id=' . $id . '&gespeichert=1'); exit;
    }
}

$l = $neu
    ? ['gesperrt'=>0,'land'=>'DE','sprache'=>'de','waehrung'=>'EUR','zahlungsart'=>'rechnung','zahlungsziel_tage'=>0]
    : one("SELECT * FROM lieferanten WHERE id=?", [(int)$id]);
if (!$l) { $neu = true; $l = ['gesperrt'=>0,'land'=>'DE','sprache'=>'de','waehrung'=>'EUR','zahlungsart'=>'rechnung']; }
$v = fn($key) => h((string)($l[$key] ?? ''));
$gesperrt = (int)($l['gesperrt'] ?? 0) === 1;
$aktKats = array_filter(explode(',', (string)($l['kategorien'] ?? '')));
$aktFormen = array_filter(explode(',', (string)($l['fertig_formen'] ?? '')));
$hatFertig = in_array('fertigprodukt', $aktKats, true);
if (!$neu) { seed_aktivitaet_if_empty(); $verlauf = verlauf_fuer('lieferant', (int)$id); } else { $verlauf = []; }
// Echte Bestellungen dieses Lieferanten
$l_bestellungen = $neu ? [] : all("SELECT b.*, (SELECT COALESCE(SUM(menge*ek_preis),0) FROM bestellung_position p WHERE p.bestellung_id=b.id) AS summe,
                                    (SELECT COUNT(*) FROM bestellung_position p WHERE p.bestellung_id=b.id) AS pos
                                    FROM bestellung b WHERE b.lieferant_id=? ORDER BY b.angelegt DESC", [(int)$id]);
$l_einkauf = 0.0; foreach ($l_bestellungen as $lb) $l_einkauf += (float)$lb['summe'];
$l_beur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$l_bBadge = fn($s) => match ($s) { 'offen'=>bx_badge('offen','info'),'bestellt'=>bx_badge('bestellt','warn'),'geliefert'=>bx_badge('geliefert','ok'),default=>bx_badge($s) };
$l_bestTabelle = function($rows) use ($l_beur, $l_bBadge) {
    echo '<div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Nummer</th><th class="bx-num">Positionen</th><th class="bx-num">Summe</th><th>Status</th></tr></thead><tbody>';
    if (!$rows) echo '<tr><td colspan="4" class="muted">Noch keine Bestellungen bei diesem Lieferanten.</td></tr>';
    foreach ($rows as $b) echo '<tr style="cursor:pointer" onclick="location.href=\'?p=bestellung&id=' . (int)$b['id'] . '\'"><td><strong>' . h($b['nummer']) . '</strong></td><td class="bx-num">' . (int)$b['pos'] . '</td><td class="bx-num">' . $l_beur($b['summe']) . '</td><td>' . $l_bBadge($b['status']) . '</td></tr>';
    echo '</tbody></table></div>';
};

function bx_bald(string $modul): void {
    echo '<div class="bx-tablewrap"><table class="bx-table"><tbody><tr><td class="muted">'
       . 'Sobald das Modul <strong>' . h($modul) . '</strong> steht, erscheinen hier automatisch alle '
       . h($modul) . ' dieses Lieferanten.</td></tr></tbody></table></div>';
}

render_header('lieferanten', $neu ? 'Neuer Lieferant' : $l['firma']);
bx_head($neu ? 'Neuer Lieferant' : $v('firma'),
        $neu ? 'Stammdaten anlegen' : trim(($v('lieferantennummer') ? $v('lieferantennummer') . ' · ' : '') . $v('ort') . ' · ' . $v('land')),
        bx_btn('Zurück zur Liste', '?p=lieferanten', 'ghost'));

if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($fehler) . '</div>';

if (!$neu) {
    echo '<div class="bx-cards">';
    echo '<div class="bx-card"><div class="k">Status</div><div class="v">' . ($gesperrt ? bx_badge('gesperrt','err') : bx_badge('aktiv','ok')) . '</div></div>';
    echo '<div class="bx-card"><div class="k">Einkauf gesamt</div><div class="v">' . ($l_einkauf>0 ? number_format($l_einkauf,2,',','.').' €' : '<span class="muted">–</span>') . '</div></div>';
    echo '<div class="bx-card"><div class="k">Produkte hergestellt</div><div class="v muted">–</div></div>';
    echo '<div class="bx-card"><div class="k">Offene Rechnungen</div><div class="v muted">–</div></div>';
    echo '<div class="bx-card"><div class="k">Ø Lieferzeit</div><div class="v">' . ((int)($l['lieferzeit_tage']??0) ?: '–') . '<span style="font-size:14px"> Tage</span></div></div>';
    echo '</div>';
}
?>
<form method="post" class="bx-form">
  <div class="settabs" id="lieftabs">
    <?php if (!$neu): ?>
    <a href="#" class="on" data-tab="ueber">Übersicht</a>
    <a href="#" data-tab="angebote">Preise / Angebote</a>
    <a href="#" data-tab="bestell">Bestellungen</a>
    <a href="#" data-tab="rechnungen">Rechnungen</a>
    <a href="#" data-tab="dok">Dokumente</a>
    <a href="#" data-tab="verlauf">Verlauf</a>
    <a href="#" data-tab="stamm">Stammdaten</a>
    <?php else: ?>
    <a href="#" class="on" data-tab="stamm">Stammdaten</a>
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
      <hr class="bx"><div class="k muted">Liefer-Kategorien</div>
      <div class="bx-row" style="margin-top:6px"><?php foreach ($aktKats as $kk) echo bx_badge($KATS[$kk] ?? $kk, $kk==='fertigprodukt'?'info':''); ?></div>
      <?php if ($hatFertig && $aktFormen): ?>
        <div class="k muted" style="margin-top:10px">Fertige Produkte – Formen</div>
        <div class="bx-row" style="margin-top:6px"><?php foreach ($aktFormen as $ff) echo bx_badge($FORMEN[$ff] ?? $ff, 'ok'); ?></div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="bx-panel"><h2>Letzte Preise / Angebote</h2><?php bx_bald('Angebote'); ?></div>
    <div class="bx-panel"><h2>Letzte Bestellungen</h2><?php $l_bestTabelle(array_slice($l_bestellungen,0,5)); ?></div>
  </section>

  <section data-panel="angebote" hidden><div class="bx-panel"><h2>Preise / Angebote</h2><?php bx_bald('Angebote'); ?></div></section>
  <section data-panel="bestell" hidden><div class="bx-panel"><h2>Bestellungen (<?= count($l_bestellungen) ?>)</h2><?php $l_bestTabelle($l_bestellungen); ?></div></section>

  <section data-panel="rechnungen" hidden>
    <div class="bx-panel">
      <h2>Rechnungen &amp; Zahlungen <?= bx_hint('Zeigt je Auftrag, ob eine Rechnung vorliegt und ob sie bezahlt ist') ?></h2>
      <div class="bx-row" style="margin-bottom:12px">
        <?= bx_badge('bezahlt','ok') ?> <?= bx_badge('offen','warn') ?> <?= bx_badge('überfällig','err') ?> <?= bx_badge('keine Rechnung','info') ?>
        <span class="muted">Vorschau mit Beispieldaten – echte Werte erscheinen, sobald Bestellungen &amp; Buchhaltung angebunden sind.</span>
      </div>
      <?php
      $beispiel = [
          ['B-3007','12.08.2026','2.100,00 €','R-5501','26.08.2026','bezahlt','ok'],
          ['B-3012','18.08.2026','850,00 €','R-5540','01.09.2026','offen','warn'],
          ['B-3015','05.08.2026','1.400,00 €','R-5480','19.08.2026','überfällig','err'],
          ['B-3020','20.08.2026','3.200,00 €','—','—','keine Rechnung','info'],
      ];
      ?>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Auftrag</th><th>Datum</th><th class="bx-num">Betrag</th><th>Rechnung</th><th>fällig</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($beispiel as $b): ?>
          <tr>
            <td><?= h($b[0]) ?></td><td><?= h($b[1]) ?></td><td class="bx-num"><?= h($b[2]) ?></td>
            <td><?= $b[3]==='—' ? '<span class="muted">—</span>' : h($b[3]) ?></td>
            <td><?= h($b[4]) ?></td><td><?= bx_badge($b[5],$b[6]) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </section>

  <section data-panel="dok" hidden><div class="bx-panel"><h2>Dokumente (CoA / Spec / Zertifikate)</h2><?php bx_bald('Dokumente'); ?></div></section>
  <section data-panel="verlauf" hidden>
    <div class="bx-panel">
      <h2>Aktivitätsverlauf <?= bx_hint('links = wir (bulkify), rechts = Lieferant. Jede Aktion wird automatisch protokolliert') ?></h2>
      <?php bx_chat($verlauf, $v('firma')); ?>
    </div>
  </section>
  <?php endif; ?>

  <section data-panel="stamm" <?= $neu ? '' : 'hidden' ?>>
    <div class="bx-panel"><div class="bx-grid">
      <div class="bx-field"><label>Lieferantennummer <?= bx_hint('leer lassen = wird automatisch vergeben (L-…)') ?></label><input type="text" name="lieferantennummer" value="<?= $v('lieferantennummer') ?>" placeholder="<?= $neu ? 'automatisch (L-…)' : '' ?>"></div>
      <div class="bx-field"><label>Firma</label><input type="text" name="firma" value="<?= $v('firma') ?>" required></div>
      <div class="bx-field"><label>Ansprechpartner</label><input type="text" name="ansprechpartner" value="<?= $v('ansprechpartner') ?>"></div>
      <div class="bx-field"><label>E-Mail</label><input type="email" name="email" value="<?= $v('email') ?>"></div>
      <div class="bx-field"><label>Telefon</label><input type="text" name="telefon" value="<?= $v('telefon') ?>"></div>
      <div class="bx-field"><label>Sprache <?= bx_hint('Kommunikationssprache im Lieferantenportal') ?></label>
        <select name="sprache">
          <?php foreach (['de'=>'Deutsch','en'=>'English','zh'=>'中文'] as $s=>$lbl): ?>
            <option value="<?= $s ?>" <?= ($l['sprache']??'')===$s?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Webseite</label><input type="text" name="webseite" value="<?= $v('webseite') ?>" placeholder="https://…"></div>
      <div class="bx-field"><label>Lieferant sperren <?= bx_hint('gesperrte Lieferanten werden bei Preisanfragen/Bestellungen nicht mehr vorgeschlagen') ?></label>
        <div class="bx-check" style="padding-top:8px">
          <input type="checkbox" name="gesperrt" id="f_gesperrt" value="1" <?= $gesperrt?'checked':'' ?>>
          <label for="f_gesperrt" style="margin:0">Lieferant ist gesperrt</label>
        </div>
      </div>
    </div>
    <div class="bx-field"><label>Liefer-Kategorien <?= bx_hint('Was kann der Lieferant liefern? Steuert später die automatische Preisanfrage') ?></label>
      <div class="bx-row">
        <?php foreach ($KATS as $key=>$lbl): ?>
          <label class="bx-check"><input type="checkbox" name="kat[<?= $key ?>]" value="1" <?= in_array($key,$aktKats,true)?'checked':'' ?> <?= $key==='fertigprodukt'?'id="kat_fertig"':'' ?>> <?= $lbl ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="bx-field" id="formenBlock" <?= $hatFertig ? '' : 'hidden' ?>>
      <label>Fertige Produkte – welche Formen? <?= bx_hint('z. B. reiner Softgel-Hersteller. Steuert, welche fertigen Produkte hier eingekauft werden können') ?></label>
      <div class="bx-row">
        <?php foreach ($FORMEN as $key=>$lbl): ?>
          <label class="bx-check"><input type="checkbox" name="form[<?= $key ?>]" value="1" <?= in_array($key,$aktFormen,true)?'checked':'' ?>> <?= $lbl ?></label>
        <?php endforeach; ?>
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
    <div class="bx-panel"><div class="bx-grid">
      <div class="bx-field"><label>Währung <?= bx_hint('EK oft in Fremdwährung – hier die Standardwährung des Lieferanten') ?></label>
        <select name="waehrung">
          <?php foreach (['EUR'=>'EUR €','USD'=>'USD $','CNY'=>'CNY ¥'] as $s=>$lbl): ?>
            <option value="<?= $s ?>" <?= ($l['waehrung']??'')===$s?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Zahlungsart</label>
        <select name="zahlungsart">
          <?php foreach (['rechnung'=>'Rechnung','vorkasse'=>'Vorkasse'] as $s=>$lbl): ?>
            <option value="<?= $s ?>" <?= ($l['zahlungsart']??'')===$s?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Zahlungsziel (Tage)</label><input type="number" name="zahlungsziel_tage" value="<?= $v('zahlungsziel_tage') ?>"></div>
      <div class="bx-field"><label>Standard-Lieferzeit (Tage)</label><input type="number" name="lieferzeit_tage" value="<?= $v('lieferzeit_tage') ?>"></div>
      <div class="bx-field"><label>Mindestbestellwert</label><input type="number" step="0.01" name="mindestbestellwert" value="<?= $v('mindestbestellwert') ?>"></div>
    </div></div>
  </section>

  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit"><?= $neu ? 'Lieferant anlegen' : 'Speichern' ?></button>
    <a class="btn btn-ghost" href="?p=lieferanten">Abbrechen</a>
  </div>
</form>

<script>
(function(){
  var tabs = document.querySelectorAll('#lieftabs a');
  tabs.forEach(function(t){
    t.addEventListener('click', function(e){
      e.preventDefault();
      tabs.forEach(function(x){ x.classList.remove('on'); });
      t.classList.add('on');
      document.querySelectorAll('[data-panel]').forEach(function(p){
        p.hidden = (p.getAttribute('data-panel') !== t.getAttribute('data-tab'));
      });
    });
  });
  // „Fertige Produkte" -> Formen-Auswahl ein/ausblenden
  var katFertig = document.getElementById('kat_fertig');
  var formenBlock = document.getElementById('formenBlock');
  if (katFertig && formenBlock) {
    katFertig.addEventListener('change', function(){ formenBlock.hidden = !katFertig.checked; });
  }
})();
</script>
<?php
render_footer();
