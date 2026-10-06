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
        $ergebnis_upload     = isset($_POST['ergebnis_upload']) ? 1 : 0;
        $upload_schliesst_ab = ($ergebnis_upload && isset($_POST['upload_schliesst_ab'])) ? 1 : 0;
        $ohne_fortschritt    = isset($_POST['ohne_fortschritt']) ? 1 : 0;

        if ($neu) {
            q("INSERT INTO dienstleistung (nummer,name,kategorie,beschreibung,preismodell,einheit,ek_cent,vk_cent,mwst_satz,art,wiederkehrend,baustein,aktiv,ergebnis_upload,upload_schliesst_ab,ohne_fortschritt)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
              [naechste_nummer('DL'), $name, $kategorie, $beschreibung, $preismodell, $einheit, $ek_cent, $vk_cent, $mwst, $art, $wieder, $baustein, $aktiv, $ergebnis_upload, $upload_schliesst_ab, $ohne_fortschritt]);
            $id = insert_id();
        } else {
            q("UPDATE dienstleistung SET name=?,kategorie=?,beschreibung=?,preismodell=?,einheit=?,ek_cent=?,vk_cent=?,mwst_satz=?,art=?,wiederkehrend=?,baustein=?,aktiv=?,ergebnis_upload=?,upload_schliesst_ab=?,ohne_fortschritt=? WHERE id=?",
              [$name, $kategorie, $beschreibung, $preismodell, $einheit, $ek_cent, $vk_cent, $mwst, $art, $wieder, $baustein, $aktiv, $ergebnis_upload, $upload_schliesst_ab, $ohne_fortschritt, (int)$id]);
        }
        // Workflow-Schritte (eine je Zeile). „Ohne Fortschritt" (Fulfillment/Lagerung) = keine Schritte.
        // Sonst leer = Standard-Vorlage für den Baustein, damit es nie ohne läuft.
        if ($ohne_fortschritt) {
            dl_katalog_schritte_setzen((int)$id, []);
        } else {
            $namen = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($_POST['schritte'] ?? ''))), fn($x) => $x !== ''));
            if (!$namen) $namen = dl_schritte_vorlage($baustein);
            dl_katalog_schritte_setzen((int)$id, $namen);
        }
        // Kundenpreis-Staffeln (optional): Zeilen kunde_id[] + kp_menge[] (ab Menge) + kp_vk[] (€).
        $kpZeilen = [];
        foreach ((array)($_POST['kp_kunde'] ?? []) as $i => $kuid) {
            $kuid = (int)$kuid; if ($kuid <= 0) continue;
            $vkTxt = (string)($_POST['kp_vk'][$i] ?? '');
            if (trim($vkTxt) === '') continue;
            $kpZeilen[] = ['kunde_id' => $kuid, 'menge_ab' => max(1, (int)($_POST['kp_menge'][$i] ?? 1)), 'vk_cent' => dienstleistung_cent($vkTxt)];
        }
        dl_kundenpreise_setzen((int)$id, $kpZeilen);
        header('Location: ?p=dienstleistung&id=' . (int)$id . '&gespeichert=1'); exit;
    }
}

$d = $neu ? ['aktiv'=>1,'mwst_satz'=>19,'art'=>'beides','preismodell'=>'pauschale','wiederkehrend'=>'einmalig'] : dienstleistung_laden((int)$id);
if (!$d) { $neu = true; $d = ['aktiv'=>1,'mwst_satz'=>19,'art'=>'beides','preismodell'=>'pauschale','wiederkehrend'=>'einmalig']; }
$v   = fn($k) => h((string)($d[$k] ?? ''));
$sel = fn($a, $b) => ((string)$a === (string)$b) ? ' selected' : '';
// Workflow-Schritte zur Vorbelegung: vorhandene, sonst Vorlage des Bausteins.
$schritteListe = $neu ? [] : array_map(fn($s) => (string)$s['name'], dl_katalog_schritte((int)$id));
if (!$schritteListe) $schritteListe = dl_schritte_vorlage($d['baustein'] ?? '');
$schritteText  = implode("\n", $schritteListe);
// Vorlagen je Baustein für das JS (fuellt leeres Feld beim Wechsel).
$vorlagenMap = [];
foreach (array_keys($BAUSTEINE) as $bk) $vorlagenMap[$bk] = implode("\n", dl_schritte_vorlage($bk));
// Kundenpreise (Ausnahmen) + Kundenliste für die Auswahl.
$kundenpreise = $neu ? [] : dl_kundenpreise((int)$id);
$kundenListe  = all("SELECT id, firma FROM kunden WHERE COALESCE(gesperrt,0)=0 ORDER BY firma");

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
    <h2 style="margin-top:0">Kundenpreise <span class="muted" style="font-weight:400;font-size:13px">(optional – Ausnahmen vom Standard-VK, mit Mengenstaffel)</span></h2>
    <p class="muted" style="margin-top:0;font-size:13px">Standard ist der VK oben. Hier je Kunde einen abweichenden Preis hinterlegen – optional mehrere Zeilen als <strong>Mengenstaffel</strong> („ab Menge"). Greift automatisch, sobald die Dienstleistung diesem Kunden angeboten wird (passende Staffel nach Menge).</p>
    <div class="bx-tablewrap"><table class="bx-table" id="kpTab">
      <thead><tr><th style="width:50%">Kunde</th><th>ab Menge</th><th>VK je Einheit (netto, €)</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($kundenpreise as $kp): ?>
        <tr>
          <td><select name="kp_kunde[]"><option value="">– wählen –</option><?php foreach ($kundenListe as $ku): ?><option value="<?= (int)$ku['id'] ?>" <?= (int)$kp['kunde_id'] === (int)$ku['id'] ? 'selected' : '' ?>><?= h($ku['firma']) ?></option><?php endforeach; ?></select></td>
          <td><input type="number" name="kp_menge[]" min="1" value="<?= (int)($kp['menge_ab'] ?? 1) ?>" style="max-width:110px"></td>
          <td><input type="text" name="kp_vk[]" value="<?= h(dienstleistung_eur((int)$kp['vk_cent'])) ?>" style="max-width:160px" placeholder="z. B. 99,00"></td>
          <td style="text-align:right"><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('tr').remove()">entfernen</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="bx-row" style="margin-top:8px"><button type="button" class="btn btn-ghost btn-sm" onclick="kpAdd()">+ Preis / Staffel</button></div>
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

  <div class="bx-panel">
    <h2 style="margin-top:0">Ablauf &amp; Ergebnis</h2>
    <div class="bx-field"><label>Fortschritt</label>
      <div class="bx-check" style="padding-top:8px">
        <input type="checkbox" name="ohne_fortschritt" id="f_ohnefort" value="1" <?= (int)($d['ohne_fortschritt'] ?? 0) === 1 ? 'checked' : '' ?>>
        <label for="f_ohnefort" style="margin:0">Kein Fortschritt – nur Abrechnung (z. B. Fulfillment/Lagerung; vom Kunden gebucht, keine Schritte, nur Rechnung)</label>
      </div>
    </div>
    <div class="bx-field" id="f_schritte_wrap"><label>Schritte / Status <?= bx_hint('Die Fortschritts-Punkte für Aufträge dieser Dienstleistung – ein Schritt je Zeile, in Reihenfolge. Je Dienstleistungstyp eigene Punkte (z. B. Laboranalyse: Bestätigung · Probe versendet · Ergebnis; Abfüllen: Bestätigung · Mischen · Abfüllung · Abschluss).') ?></label>
      <textarea name="schritte" id="f_schritte" rows="5" placeholder="ein Schritt je Zeile"><?= h($schritteText) ?></textarea>
      <div class="muted" style="font-size:12px;margin-top:2px">Ein Schritt je Zeile. Leer lassen = Standard-Vorlage für den gewählten Baustein.</div>
    </div>
    <div class="bx-grid">
      <div class="bx-field"><label>Endergebnis-Upload</label>
        <div class="bx-check" style="padding-top:8px">
          <input type="checkbox" name="ergebnis_upload" id="f_ergup" value="1" <?= (int)($d['ergebnis_upload'] ?? 0) === 1 ? 'checked' : '' ?>>
          <label for="f_ergup" style="margin:0">Hochladen eines Endergebnis-Dokuments erlauben (z. B. Analysebericht, fertige Rezeptur)</label>
        </div>
      </div>
      <div class="bx-field"><label>Upload schließt ab</label>
        <div class="bx-check" style="padding-top:8px">
          <input type="checkbox" name="upload_schliesst_ab" id="f_updone" value="1" <?= (int)($d['upload_schliesst_ab'] ?? 0) === 1 ? 'checked' : '' ?>>
          <label for="f_updone" style="margin:0">Mit dem Upload wird der Auftrag auf „erledigt" gesetzt und der Kunde benachrichtigt</label>
        </div>
      </div>
    </div>
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
(function(){
  // Schritte-Vorlage beim Baustein-Wechsel einfuellen, solange das Feld leer/unveraendert ist.
  var map = <?= json_encode($vorlagenMap, JSON_UNESCAPED_UNICODE) ?>;
  var baustein = document.querySelector('select[name="baustein"]');
  var feld = document.getElementById('f_schritte');
  if (baustein && feld) {
    var autoFill = (feld.value.trim() === '');
    feld.addEventListener('input', function(){ autoFill = false; });
    baustein.addEventListener('change', function(){
      if (autoFill || feld.value.trim() === '') { feld.value = map[baustein.value] || map[''] || ''; }
    });
  }
  // „Upload schließt ab" nur aktivierbar, wenn Endergebnis-Upload erlaubt ist.
  var up = document.getElementById('f_ergup'), done = document.getElementById('f_updone');
  function syncUp(){ if (!up || !done) return; done.disabled = !up.checked; if (!up.checked) done.checked = false; }
  if (up) up.addEventListener('change', syncUp);
  syncUp();
  // „Kein Fortschritt" -> Schritte-Editor ausgrauen (wird beim Speichern ohnehin geleert).
  var ohne = document.getElementById('f_ohnefort'), schrWrap = document.getElementById('f_schritte_wrap'), schr = document.getElementById('f_schritte');
  function syncFort(){ var off = ohne && ohne.checked; if (schrWrap) schrWrap.style.opacity = off ? .4 : 1; if (schr) schr.disabled = off; }
  if (ohne) ohne.addEventListener('change', syncFort);
  syncFort();
})();
// Kundenpreis-Zeile hinzufügen (globale Funktion für onclick).
var KP_KUNDEN_OPT = <?= json_encode('<option value="">– wählen –</option>' . implode('', array_map(fn($ku) => '<option value="' . (int)$ku['id'] . '">' . h($ku['firma']) . '</option>', $kundenListe)), JSON_UNESCAPED_UNICODE) ?>;
function kpAdd(){
  var tb = document.querySelector('#kpTab tbody'); if (!tb) return;
  var tr = document.createElement('tr');
  tr.innerHTML = '<td><select name="kp_kunde[]">' + KP_KUNDEN_OPT + '</select></td>'
    + '<td><input type="number" name="kp_menge[]" min="1" value="1" style="max-width:110px"></td>'
    + '<td><input type="text" name="kp_vk[]" style="max-width:160px" placeholder="z. B. 99,00"></td>'
    + '<td style="text-align:right"><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest(\'tr\').remove()">entfernen</button></td>';
  tb.appendChild(tr);
}
</script>
<?php render_footer();
