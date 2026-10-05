<?php
// Dienstleistung anlegen & bearbeiten (Katalog-Stammdaten). Phase 1.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dienstleistung.php';

$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

$KAT       = dienstleistung_kategorien();
$PM        = dienstleistung_preismodelle();
$ARTEN     = dienstleistung_arten();
$WIEDER    = dienstleistung_wiederkehr();
$BAUSTEINE = dienstleistung_bausteine();
$MWST      = ['19' => '19 %', '7' => '7 %', '0' => '0 %'];

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'loeschen' && is_numeric($id)) {
    q("DELETE FROM dienstleistung WHERE id=?", [(int)$id]);
    header('Location: ?p=dienstleistungen&ok=' . urlencode('Dienstleistung gelöscht.')); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'save') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $fehler = 'Name ist ein Pflichtfeld.';
    } else {
        $kategorie   = isset($KAT[$_POST['kategorie'] ?? ''])    ? $_POST['kategorie']   : 'sonstiges';
        $preismodell = isset($PM[$_POST['preismodell'] ?? ''])   ? $_POST['preismodell'] : 'pauschale';
        $art         = isset($ARTEN[$_POST['art'] ?? ''])        ? $_POST['art']         : 'beides';
        $wieder      = isset($WIEDER[$_POST['wiederkehrend'] ?? ''])  ? $_POST['wiederkehrend'] : 'einmalig';
        $baustein    = isset($BAUSTEINE[$_POST['baustein'] ?? '']) ? ($_POST['baustein'] ?: null) : null;
        $mwst        = in_array((string)($_POST['mwst_satz'] ?? '19'), ['0','7','19'], true) ? (float)$_POST['mwst_satz'] : 19;
        // Preise: bei "auf Anfrage" kein VK. EK ist immer rein intern (Marge).
        $vk_cent = ($preismodell === 'auf_anfrage') ? 0 : dienstleistung_cent($_POST['vk'] ?? '');
        $ek_cent = dienstleistung_cent($_POST['ek'] ?? '');
        $einheit = trim($_POST['einheit'] ?? '') ?: null;
        $beschreibung = trim($_POST['beschreibung'] ?? '') ?: null;
        $notiz   = trim($_POST['notiz'] ?? '') ?: null;
        $aktiv   = isset($_POST['aktiv']) ? 1 : 0;

        if ($neu) {
            q("INSERT INTO dienstleistung (nummer,name,kategorie,beschreibung,preismodell,einheit,ek_cent,vk_cent,mwst_satz,art,wiederkehrend,baustein,aktiv)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
              [naechste_nummer('DL'), $name, $kategorie, $beschreibung, $preismodell, $einheit, $ek_cent, $vk_cent, $mwst, $art, $wieder, $baustein, $aktiv]);
            $id = insert_id();
        } else {
            q("UPDATE dienstleistung SET name=?,kategorie=?,beschreibung=?,preismodell=?,einheit=?,ek_cent=?,vk_cent=?,mwst_satz=?,art=?,wiederkehrend=?,baustein=?,aktiv=? WHERE id=?",
              [$name, $kategorie, $beschreibung, $preismodell, $einheit, $ek_cent, $vk_cent, $mwst, $art, $wieder, $baustein, $aktiv, (int)$id]);
        }
        header('Location: ?p=dienstleistung&id=' . (int)$id . '&gespeichert=1'); exit;
    }
}

$d = $neu ? ['aktiv'=>1,'mwst_satz'=>19,'art'=>'beides','preismodell'=>'pauschale','wiederkehrend'=>'einmalig'] : dienstleistung_laden((int)$id);
if (!$d) { $neu = true; $d = ['aktiv'=>1,'mwst_satz'=>19,'art'=>'beides','preismodell'=>'pauschale','wiederkehrend'=>'einmalig']; }
$v   = fn($k) => h((string)($d[$k] ?? ''));
$sel = fn($a, $b) => ((string)$a === (string)$b) ? ' selected' : '';

render_header('dienstleistungen', $neu ? 'Neue Dienstleistung' : (string)$d['name']);
bx_head($neu ? 'Neue Dienstleistung' : (string)$d['name'],
        $neu ? 'Dienstleistung anlegen' : trim((($d['nummer'] ?? '') ? $d['nummer'] . ' · ' : '') . dienstleistung_label($KAT, $d['kategorie'])),
        bx_btn('Zurück zur Liste', '?p=dienstleistungen', 'ghost'));
dl_subtabs('dienstleistungen');

if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($fehler) . '</div>';
?>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="save">

  <div class="bx-panel">
    <h2 style="margin-top:0">Stammdaten</h2>
    <div class="bx-grid">
      <div class="bx-field"><label>Name</label><input type="text" name="name" value="<?= $v('name') ?>" required placeholder="z. B. Laboranalyse (Standard)"></div>
      <div class="bx-field"><label>Kategorie</label>
        <select name="kategorie"><?php foreach ($KAT as $k=>$l): ?><option value="<?= h($k) ?>"<?= $sel($d['kategorie'] ?? '', $k) ?>><?= h($l) ?></option><?php endforeach; ?></select>
      </div>
      <div class="bx-field"><label>Verkaufsart <?= bx_hint('Eigenständig = reines Dienstleistungs-Angebot (Phase 2). Add-on = als Zusatz zum Produktangebot (Phase 1b).') ?></label>
        <select name="art"><?php foreach ($ARTEN as $k=>$l): ?><option value="<?= h($k) ?>"<?= $sel($d['art'] ?? 'beides', $k) ?>><?= h($l) ?></option><?php endforeach; ?></select>
      </div>
      <div class="bx-field"><label>Abrechnung <?= bx_hint('Monatlich (Lagerung/Fulfillment) wird erst mit dem wiederkehrenden Abrechnungslauf in Phase 3 automatisch abgerechnet.') ?></label>
        <select name="wiederkehrend"><?php foreach ($WIEDER as $k=>$l): ?><option value="<?= h($k) ?>"<?= $sel($d['wiederkehrend'] ?? 'einmalig', $k) ?>><?= h($l) ?></option><?php endforeach; ?></select>
      </div>
    </div>
    <div class="bx-field"><label>Beschreibung <?= bx_hint('Kundensichtbarer Text (später im Angebot/Portal).') ?></label><textarea name="beschreibung" placeholder="Was umfasst die Leistung?"><?= $v('beschreibung') ?></textarea></div>
  </div>

  <div class="bx-panel">
    <h2 style="margin-top:0">Preis</h2>
    <div class="bx-grid">
      <div class="bx-field"><label>Preismodell</label>
        <select name="preismodell" id="f_pm"><?php foreach ($PM as $k=>$l): ?><option value="<?= h($k) ?>"<?= $sel($d['preismodell'] ?? 'pauschale', $k) ?>><?= h($l) ?></option><?php endforeach; ?></select>
      </div>
      <div class="bx-field" id="f_einheit_wrap"><label>Einheit <?= bx_hint('nur bei „pro Einheit / pro Stunde / monatlich" – z. B. Stück, Probe, Stunde, Monat') ?></label><input type="text" name="einheit" value="<?= $v('einheit') ?>" placeholder="z. B. Stück"></div>
      <div class="bx-field" id="f_vk_wrap" style="max-width:180px"><label>VK je Einheit (netto, €)</label><input type="text" name="vk" value="<?= $neu || (int)($d['vk_cent']??0)===0 ? '' : dienstleistung_eur((int)$d['vk_cent']) ?>" placeholder="z. B. 120,00"></div>
      <div class="bx-field" style="max-width:180px"><label>EK (intern, €) <?= bx_hint('nur für die Marge, erscheint nie beim Kunden') ?></label><input type="text" name="ek" value="<?= $neu || (int)($d['ek_cent']??0)===0 ? '' : dienstleistung_eur((int)$d['ek_cent']) ?>" placeholder="z. B. 60,00"></div>
      <div class="bx-field" style="max-width:140px"><label>MwSt</label>
        <select name="mwst_satz"><?php foreach ($MWST as $k=>$l): ?><option value="<?= h($k) ?>"<?= $sel((string)(float)($d['mwst_satz'] ?? 19), (string)(float)$k) ?>><?= h($l) ?></option><?php endforeach; ?></select>
      </div>
    </div>
    <p class="muted" style="font-size:12px;margin-top:4px" id="f_anfrage_hint" hidden>Bei „auf Anfrage" wird kein Preis gespeichert – der Preis wird je Fall im Angebot festgelegt.</p>
  </div>

  <div class="bx-panel">
    <h2 style="margin-top:0">Verknüpfung &amp; intern</h2>
    <div class="bx-grid">
      <div class="bx-field"><label>Vorhandener Baustein <?= bx_hint('Zeigt auf einen Service, der schon im System existiert (z. B. Rezepturbewertung mit Auto-Rechnung) – damit wir die Logik nicht doppelt bauen.') ?></label>
        <select name="baustein"><?php foreach ($BAUSTEINE as $k=>$l): ?><option value="<?= h($k) ?>"<?= $sel($d['baustein'] ?? '', $k) ?>><?= h($l) ?></option><?php endforeach; ?></select>
      </div>
      <div class="bx-field"><label>Status</label>
        <div class="bx-check" style="padding-top:8px">
          <input type="checkbox" name="aktiv" id="f_aktiv" value="1" <?= (int)($d['aktiv'] ?? 1) === 1 ? 'checked' : '' ?>>
          <label for="f_aktiv" style="margin:0">aktiv (im Angebot auswählbar)</label>
        </div>
      </div>
    </div>
    <div class="bx-field"><label>Notiz (intern)</label><textarea name="notiz" placeholder="interne Hinweise"><?= $v('notiz') ?></textarea></div>
  </div>

  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit"><?= $neu ? 'Dienstleistung anlegen' : 'Speichern' ?></button>
    <a class="btn btn-ghost" href="?p=dienstleistungen">Abbrechen</a>
    <?php if (!$neu): ?>
    <span style="flex:1"></span>
    <button class="btn btn-ghost btn-sm" type="submit" name="aktion" value="loeschen" onclick="return confirm('Diese Dienstleistung wirklich löschen?');">Löschen</button>
    <?php endif; ?>
  </div>
</form>

<script>
(function(){
  var pm = document.getElementById('f_pm');
  var einheitWrap = document.getElementById('f_einheit_wrap');
  var vkWrap = document.getElementById('f_vk_wrap');
  var anfrageHint = document.getElementById('f_anfrage_hint');
  function apply(){
    var val = pm ? pm.value : 'pauschale';
    var zeigtEinheit = (val === 'pro_einheit' || val === 'pro_stunde' || val === 'monatlich');
    if (einheitWrap) einheitWrap.style.display = zeigtEinheit ? '' : 'none';
    var aufAnfrage = (val === 'auf_anfrage');
    if (vkWrap) vkWrap.style.display = aufAnfrage ? 'none' : '';
    if (anfrageHint) anfrageHint.hidden = !aufAnfrage;
  }
  if (pm) pm.addEventListener('change', apply);
  apply();
})();
</script>
<?php render_footer();
