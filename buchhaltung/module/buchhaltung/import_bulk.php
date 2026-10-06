<?php
// Bulk-Import-Assistent: alte Angebote + Rechnungen stapelweise hochladen, per KI auslesen, Kunden zuordnen,
// Rechnungen den Angeboten zuordnen, Zahlungen erfassen und übernehmen. Route: import_bulk (finance).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/importer.php';
imp_init();

$batch = preg_replace('/[^A-Za-z0-9_]/', '', (string)($_GET['batch'] ?? ($_POST['batch'] ?? '')));
$step  = preg_replace('/[^a-z]/', '', (string)($_GET['step'] ?? 'start'));
$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';

// Staging-Datei ansehen
if ($batch && isset($_GET['datei'])) {
    $it = imp_item((int)$_GET['datei']);
    $pf = ($it && !empty($it['datei'])) ? be_pfad((string)$it['datei']) : '';
    if (!$pf || !is_file($pf)) { http_response_code(404); echo 'Keine Datei.'; exit; }
    $ext = strtolower(pathinfo($pf, PATHINFO_EXTENSION));
    header('Content-Type: ' . (['pdf'=>'application/pdf','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif'][$ext] ?? 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/','_',(string)($it['orig_name'] ?: 'beleg')) . '"');
    header('Content-Length: ' . filesize($pf)); readfile($pf); exit;
}

// KI-Auslesung EINES Items als eigener Request (JSON) – vom Auto-Durchlauf (JS) aufgerufen, damit viele
// Dateien nicht in einen Timeout laufen. Antwortet schlank und beendet sofort.
if ($batch && isset($_GET['kiitem'])) {
    $res = imp_ki_item((int)$_GET['kiitem']);
    header('Content-Type: application/json'); echo json_encode($res); exit;
}

$hinweis = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['aktion'] ?? '';
    if ($a === 'upload') {
        if ($batch === '') $batch = 'B' . date('ymdHis');
        $n = 0;
        $files = $_FILES['dateien'] ?? null;
        if ($files && is_array($files['name'])) for ($i=0;$i<count($files['name']);$i++) {
            if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
            if (imp_datei_hinzufuegen($batch, ['name'=>$files['name'][$i],'type'=>$files['type'][$i],'tmp_name'=>$files['tmp_name'][$i],'error'=>$files['error'][$i],'size'=>$files['size'][$i]])) $n++;
        }
        // KEINE KI hier – das läuft danach pro Datei (schnell + timeout-sicher).
        header('Location: ?p=import_bulk&batch=' . $batch . '&step=pruefen&hoch=' . $n); exit;
    }
    if ($a === 'ki_item') { imp_ki_item((int)($_POST['item'] ?? 0)); header('Location: ?p=import_bulk&batch=' . $batch . '&step=pruefen'); exit; }
    if ($a === 'item_save') {
        foreach (($_POST['it'] ?? []) as $iid => $d) imp_item_update((int)$iid, (array)$d);
        foreach (($_POST['kunde'] ?? []) as $iid => $kid) if ((int)$kid > 0) imp_item_kunde_setzen((int)$iid, (int)$kid);
        header('Location: ?p=import_bulk&batch=' . $batch . '&step=pruefen&gespeichert=1'); exit;
    }
    if ($a === 'kunde_neu') {
        $kid = imp_item_kunde_neu((int)($_POST['item'] ?? 0), trim((string)($_POST['firma'] ?? '')));
        header('Location: ?p=import_bulk&batch=' . $batch . '&step=pruefen' . ($kid ? '&kneu=1' : '')); exit;
    }
    if ($a === 'verwerfen') { imp_item_verwerfen((int)($_POST['item'] ?? 0)); header('Location: ?p=import_bulk&batch=' . $batch . '&step=' . ($_POST['zurueck'] ?? 'pruefen')); exit; }
    if ($a === 'verknuepfen') {
        foreach (($_POST['link'] ?? []) as $rid => $aid) imp_item_verknuepfen((int)$rid, (int)$aid ?: null);
        header('Location: ?p=import_bulk&batch=' . $batch . '&step=verknuepfen&gespeichert=1'); exit;
    }
    if ($a === 'auto_verknuepfen') { $n = imp_auto_verknuepfen($batch); header('Location: ?p=import_bulk&batch=' . $batch . '&step=verknuepfen&auto=' . $n); exit; }
    if ($a === 'zahlung_add') {
        imp_zahlung_add((int)($_POST['item'] ?? 0), (float) str_replace(',', '.', (string)($_POST['betrag'] ?? '0')), $_POST['datum'] ?? null, $_POST['art'] ?? '');
        header('Location: ?p=import_bulk&batch=' . $batch . '&step=zahlungen'); exit;
    }
    if ($a === 'zahlung_del') { imp_zahlung_del((int)($_POST['zid'] ?? 0)); header('Location: ?p=import_bulk&batch=' . $batch . '&step=zahlungen'); exit; }
    if ($a === 'uebernehmen') {
        $res = imp_uebernehmen($batch);
        $_SESSION['imp_res'] = $res;
        header('Location: ?p=import_bulk&batch=' . $batch . '&step=fertig'); exit;
    }
    if ($a === 'batch_loeschen') { imp_batch_loeschen($batch); header('Location: ?p=import_bulk&geloescht=1'); exit; }
}

$kundenListe = erp_kunden_alle();
$kunName = function($id) use ($kundenListe) { foreach ($kundenListe as $k) if ((int)$k['id'] === (int)$id) return $k['firma']; return ''; };

render_header('import_bulk', 'Bulk-Import');
bx_head('Bulk-Import – alte Angebote & Rechnungen', 'Hochladen → KI liest aus → Kunde & Verknüpfung → Zahlungen → Übernehmen');

// Schritt-Leiste
if ($batch && $step !== 'start') {
    $steps = ['pruefen'=>'1. Prüfen & Kunde','verknuepfen'=>'2. Verknüpfen','zahlungen'=>'3. Zahlungen','fertig'=>'4. Übernehmen'];
    echo '<div class="settabs">';
    foreach ($steps as $s => $lbl) echo '<a class="' . ($s===$step?'on':'') . '" href="?p=import_bulk&batch=' . h($batch) . '&step=' . $s . '">' . h($lbl) . '</a>';
    echo '</div>';
}
foreach (['hoch'=>'%d Datei(en) hochgeladen – KI-Auslesung läuft unten.','fertigki'=>'KI-Auslesung abgeschlossen – bitte prüfen.','gespeichert'=>'Gespeichert.','kneu'=>'Kunde neu angelegt und zugeordnet.','auto'=>'%d Rechnung(en) automatisch verknüpft.','geloescht'=>'Import verworfen.'] as $k=>$msg)
    if (isset($_GET[$k])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(sprintf($msg, (int)($_GET[$k] ?: 0))) . '</div>';

// ===========================================================================
if (!$batch || $step === 'start'):
// ===========================================================================
?>
<div class="bx-panel" style="max-width:720px">
  <h2 style="margin-top:0">Neuer Import</h2>
  <p class="muted">Lade beliebig viele PDFs auf einmal hoch (Rechnungen und Angebote gemischt). Der Upload speichert sie nur – die KI-Auslesung (Art, Kunde, Beträge; bei Angeboten die Positionen Produkt/Verpackung/Etikett) läuft danach <strong>Datei für Datei</strong> im Schritt „Prüfen", damit nichts in einen Timeout läuft.</p>
  <?php if (!ki_bereit()) echo '<p class="muted">Hinweis: KI nicht eingerichtet – Dateien werden gespeichert, Felder trägst du im nächsten Schritt ein.</p>'; ?>
  <form method="post" enctype="multipart/form-data" class="bx-form">
    <input type="hidden" name="aktion" value="upload">
    <div class="bx-field"><input type="file" name="dateien[]" accept="application/pdf,image/*" multiple required></div>
    <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit" data-busy="lädt hoch …">Hochladen</button></div>
  </form>
</div>
<?php $batches = array_filter(imp_batches(), fn($b)=>(int)$b['anz'] > (int)$b['uebernommen']); if ($batches): ?>
<div class="bx-panel" style="max-width:720px;margin-top:16px">
  <h2 style="margin-top:0">Offene Importe</h2>
  <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Stapel</th><th class="bx-num">Belege</th><th>seit</th><th></th></tr></thead><tbody>
    <?php foreach ($batches as $b): ?>
      <tr><td><?= h($b['batch']) ?></td><td class="bx-num"><?= (int)$b['anz'] ?> <span class="muted">(<?= (int)$b['uebernommen'] ?> übern.)</span></td><td><?= h(fmt_zeit($b['seit'])) ?></td><td><a class="btn btn-ghost btn-sm" href="?p=import_bulk&batch=<?= h($b['batch']) ?>&step=pruefen">weiter</a></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<?php
// ===========================================================================
elseif ($step === 'pruefen'): $items = imp_items($batch); $ungelesen = imp_ungelesen($batch);
// ===========================================================================
?>
<?php if ($ungelesen && ki_bereit()): ?>
<div class="bx-panel" id="kiBox" style="border-color:var(--gruen)">
  <h2 style="margin-top:0">KI liest die Belege aus …</h2>
  <p class="muted" id="kiProg">0 / <?= count($ungelesen) ?> ausgelesen</p>
  <div style="background:var(--line);border-radius:6px;height:10px;overflow:hidden"><div id="kiBar" style="background:var(--gruen);height:10px;width:0%"></div></div>
  <p class="muted" style="font-size:12px;margin:8px 0 0">Läuft automatisch, Datei für Datei. Du kannst warten oder die Seite offen lassen.</p>
</div>
<script>
(function(){
  var ids = <?= json_encode($ungelesen) ?>, base = '?p=import_bulk&batch=<?= h($batch) ?>&kiitem=', done = 0, total = ids.length;
  var prog = document.getElementById('kiProg'), bar = document.getElementById('kiBar');
  function next(){
    if (!ids.length){ location.href='?p=import_bulk&batch=<?= h($batch) ?>&step=pruefen&fertigki=1'; return; }
    var id = ids.shift();
    fetch(base + id).catch(function(){}).finally(function(){
      done++; if(prog) prog.textContent = done + ' / ' + total + ' ausgelesen';
      if(bar) bar.style.width = Math.round(done/total*100) + '%';
      setTimeout(next, 120);
    });
  }
  next();
})();
</script>
<?php elseif ($ungelesen): ?>
<div class="bx-panel" style="padding:12px 16px">KI nicht eingerichtet – bitte Art/Kunde/Beträge je Zeile selbst eintragen.</div>
<?php endif; ?>
<form method="post">
  <input type="hidden" name="aktion" value="item_save"><input type="hidden" name="batch" value="<?= h($batch) ?>">
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Datei</th><th>Art</th><th>Kunde</th><th>Nummer</th><th>Datum</th><th class="bx-num">Netto</th><th class="bx-num">USt%</th><th class="bx-num">Brutto</th><th>Angebots-Ref</th><th></th></tr></thead>
    <tbody>
      <?php if (!$items): ?><tr><td colspan="10" class="muted">Keine Belege im Stapel.</td></tr><?php endif; ?>
      <?php foreach ($items as $it): $iid=(int)$it['id']; ?>
      <tr>
        <td><a href="?p=import_bulk&batch=<?= h($batch) ?>&datei=<?= $iid ?>" target="_blank"><?= h(mb_strimwidth((string)($it['orig_name'] ?: 'Datei'),0,22,'…')) ?></a>
          <?php if (empty($it['ki_ok'])): ?><br><button class="btn btn-ghost btn-sm" type="submit" formnovalidate name="aktion" value="ki_item" style="padding:2px 8px;font-size:12px" onclick="this.form.querySelector('[name=item]').value='<?= $iid ?>'" title="Diese Datei per KI auslesen">KI auslesen</button><?php endif; ?></td>
        <td><select name="it[<?= $iid ?>][art]"><?php foreach (['rechnung'=>'Rechnung','angebot'=>'Angebot','unklar'=>'unklar'] as $k=>$v): ?><option value="<?= $k ?>" <?= $it['art']===$k?'selected':'' ?>><?= h($v) ?></option><?php endforeach; ?></select></td>
        <td>
          <select name="kunde[<?= $iid ?>]" style="max-width:170px">
            <option value="0">– <?= $it['kunde_name'] ? h(mb_strimwidth($it['kunde_name'],0,18,'…')).'? ' : '' ?>wählen –</option>
            <?php foreach ($kundenListe as $k): ?><option value="<?= (int)$k['id'] ?>" <?= (int)$it['kunde_id']===(int)$k['id']?'selected':'' ?>><?= h($k['firma']) ?></option><?php endforeach; ?>
          </select>
          <?php if (!$it['kunde_id'] && $it['kunde_name']): ?>
          <button class="btn btn-ghost btn-sm" type="submit" formaction="?p=import_bulk&batch=<?= h($batch) ?>" name="aktion" value="kunde_neu" formnovalidate onclick="this.form.querySelector('[name=item]').value='<?= $iid ?>';this.form.querySelector('[name=firma]').value=<?= htmlspecialchars(json_encode($it['kunde_name']),ENT_QUOTES) ?>;" title="Als neuen Kunden anlegen">+ neu</button>
          <?php endif; ?>
        </td>
        <td><input type="text" name="it[<?= $iid ?>][nummer]" value="<?= h((string)$it['nummer']) ?>" style="width:100px"></td>
        <td><input type="date" name="it[<?= $iid ?>][datum]" value="<?= h((string)$it['datum']) ?>"></td>
        <td class="bx-num"><input type="text" inputmode="decimal" name="it[<?= $iid ?>][netto]" value="<?= h(number_format((float)$it['netto'],2,',','')) ?>" style="width:90px;text-align:right"></td>
        <td class="bx-num"><input type="text" inputmode="decimal" name="it[<?= $iid ?>][ust_prozent]" value="<?= h(number_format((float)$it['ust_prozent'],0)) ?>" style="width:50px;text-align:right"></td>
        <td class="bx-num"><input type="text" inputmode="decimal" name="it[<?= $iid ?>][brutto]" value="<?= h(number_format((float)$it['brutto'],2,',','')) ?>" style="width:90px;text-align:right"></td>
        <td><input type="text" name="it[<?= $iid ?>][angebot_ref]" value="<?= h((string)$it['angebot_ref']) ?>" placeholder="–" style="width:90px"></td>
        <td><button class="btn btn-ghost btn-sm" type="submit" formnovalidate name="aktion" value="verwerfen" onclick="this.form.querySelector('[name=item]').value='<?= $iid ?>';return confirm('Diese Datei aus dem Import entfernen?');">×</button></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <input type="hidden" name="item" value="0"><input type="hidden" name="firma" value="">
  <div class="bx-row" style="margin-top:14px;gap:8px">
    <button class="btn btn-primary" type="submit">Speichern</button>
    <a class="btn btn-ghost" href="?p=import_bulk&batch=<?= h($batch) ?>&step=verknuepfen">Weiter: Verknüpfen</a>
    <span style="flex:1"></span>
    <a class="btn btn-ghost btn-sm" href="?p=import_bulk">+ weitere hochladen</a>
  </div>
</form>

<?php
// ===========================================================================
elseif ($step === 'verknuepfen'): imp_auto_verknuepfen($batch); $rechnungen = imp_items($batch,'rechnung'); $angebote = imp_items($batch,'angebot');
// ===========================================================================
?>
<form method="post" class="bx-listbar" style="margin-bottom:12px"><input type="hidden" name="aktion" value="auto_verknuepfen"><input type="hidden" name="batch" value="<?= h($batch) ?>">
  <span class="muted" style="align-self:center">Rechnungen den Angeboten zuordnen (Auto-Vorschlag über die Angebots-Referenz).</span><span style="flex:1"></span>
  <button class="btn btn-ghost btn-sm" type="submit">Automatisch verknüpfen</button>
</form>
<form method="post">
  <input type="hidden" name="aktion" value="verknuepfen"><input type="hidden" name="batch" value="<?= h($batch) ?>">
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Rechnung</th><th>Kunde</th><th class="bx-num">Brutto</th><th>Angebots-Ref</th><th>→ verknüpftes Angebot</th></tr></thead>
    <tbody>
      <?php if (!$rechnungen): ?><tr><td colspan="5" class="muted">Keine Rechnungen im Stapel.</td></tr><?php endif; ?>
      <?php foreach ($rechnungen as $r): ?>
      <tr>
        <td><strong><?= h((string)($r['nummer'] ?: '#'.$r['id'])) ?></strong></td>
        <td><?= $r['kunde_firma'] ? h($r['kunde_firma']) : '<span style="color:var(--warn)">kein Kunde</span>' ?></td>
        <td class="bx-num"><?= $eur($r['brutto']) ?></td>
        <td><?= $r['angebot_ref'] ? h($r['angebot_ref']) : '<span class="muted">–</span>' ?></td>
        <td><select name="link[<?= (int)$r['id'] ?>]">
          <option value="0">– kein –</option>
          <?php foreach ($angebote as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$r['link_item_id']===(int)$a['id']?'selected':'' ?>><?= h(($a['nummer'] ?: '#'.$a['id']).' · '.($a['kunde_firma'] ?: $a['kunde_name']).' · '.number_format((float)$a['brutto'],0,',','.').'€') ?></option><?php endforeach; ?>
        </select></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="bx-row" style="margin-top:14px;gap:8px">
    <button class="btn btn-primary" type="submit">Verknüpfung speichern</button>
    <a class="btn btn-ghost" href="?p=import_bulk&batch=<?= h($batch) ?>&step=zahlungen">Weiter: Zahlungen</a>
  </div>
</form>

<?php
// ===========================================================================
elseif ($step === 'zahlungen'): $rechnungen = imp_items($batch,'rechnung');
// ===========================================================================
?>
<p class="muted">Je Rechnung Zahlungen mit Datum und Betrag erfassen (Teilzahlungen möglich). Der Status (offen/teilbezahlt/bezahlt) ergibt sich aus der Summe und wird dem Kunden im Portal angezeigt.</p>
<?php foreach ($rechnungen as $r): $zs = imp_zahlungen((int)$r['id']); $sum = imp_zahlung_summe((int)$r['id']); $rest = round((float)$r['brutto'] - $sum, 2);
  $st = $sum <= 0.005 ? 'offen' : ($rest > 0.005 ? 'teilbezahlt' : 'bezahlt'); ?>
<div class="bx-panel" style="margin-bottom:14px">
  <h2 style="margin-top:0"><?= h((string)($r['nummer'] ?: '#'.$r['id'])) ?> · <?= h((string)($r['kunde_firma'] ?: '—')) ?> · <?= $eur($r['brutto']) ?> <?= bx_badge($st, $st==='bezahlt'?'ok':($st==='teilbezahlt'?'info':'warn')) ?> <span class="muted" style="font-size:13px">offen: <?= $eur($rest) ?></span></h2>
  <?php if ($zs): ?><div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Datum</th><th>Art</th><th class="bx-num">Betrag</th><th></th></tr></thead><tbody>
    <?php foreach ($zs as $z): ?><tr><td><?= $z['datum']?h(date('d.m.Y',strtotime($z['datum']))):'' ?></td><td><?= h((string)$z['art']) ?></td><td class="bx-num"><?= $eur($z['betrag']) ?></td>
      <td><form method="post" style="display:inline"><input type="hidden" name="aktion" value="zahlung_del"><input type="hidden" name="batch" value="<?= h($batch) ?>"><input type="hidden" name="zid" value="<?= (int)$z['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">×</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <form method="post" class="bx-row" style="margin-top:8px;gap:8px;align-items:end">
    <input type="hidden" name="aktion" value="zahlung_add"><input type="hidden" name="batch" value="<?= h($batch) ?>"><input type="hidden" name="item" value="<?= (int)$r['id'] ?>">
    <label>Datum<input type="date" name="datum" value="<?= h((string)($r['datum'] ?: date('Y-m-d'))) ?>"></label>
    <label>Betrag<input type="text" inputmode="decimal" name="betrag" value="<?= h(number_format($rest>0?$rest:0,2,',','')) ?>" style="width:110px;text-align:right"></label>
    <label>Art<input type="text" name="art" placeholder="Überweisung" style="width:130px"></label>
    <button class="btn btn-ghost btn-sm" type="submit">+ Zahlung</button>
  </form>
</div>
<?php endforeach; ?>
<div class="bx-panel" style="border-color:var(--gruen)">
  <h2 style="margin-top:0">Übernehmen</h2>
  <p class="muted">Erzeugt die bleibenden Rechnungen (für den Kunden sichtbar, mit Zahlungen) und archiviert die Angebote mit ihren Positionen. Das Import-Staging kann danach verworfen werden.</p>
  <form method="post" onsubmit="return confirm('Jetzt übernehmen? Es werden bleibende Belege/Angebote erzeugt.');">
    <input type="hidden" name="aktion" value="uebernehmen"><input type="hidden" name="batch" value="<?= h($batch) ?>">
    <button class="btn btn-primary" type="submit">Import übernehmen</button>
  </form>
</div>

<?php
// ===========================================================================
elseif ($step === 'fertig'): $res = $_SESSION['imp_res'] ?? ['angebote'=>0,'rechnungen'=>0,'fehler'=>[]]; unset($_SESSION['imp_res']);
// ===========================================================================
?>
<div class="bx-panel badge-ok" style="padding:16px">
  <h2 style="margin-top:0">Übernommen</h2>
  <p><strong><?= (int)$res['rechnungen'] ?></strong> Rechnung(en) als Beleg angelegt (für Kunden sichtbar), <strong><?= (int)$res['angebote'] ?></strong> Angebot(e) archiviert.</p>
  <?php if (!empty($res['fehler'])): ?><ul style="color:var(--warn)"><?php foreach ($res['fehler'] as $f) echo '<li>' . h($f) . '</li>'; ?></ul><?php endif; ?>
  <div class="bx-row" style="gap:8px;margin-top:10px">
    <a class="btn btn-ghost" href="?p=rechnungen">Zu den Rechnungen</a>
    <a class="btn btn-ghost" href="?p=import_bulk">Neuer Import</a>
    <form method="post" style="display:inline" onsubmit="return confirm('Import-Staging dieses Stapels löschen? Die übernommenen Belege/Angebote bleiben erhalten.');">
      <input type="hidden" name="aktion" value="batch_loeschen"><input type="hidden" name="batch" value="<?= h($batch) ?>">
      <button class="btn btn-danger btn-sm" type="submit">Import-Staging löschen</button>
    </form>
  </div>
</div>
<?php
endif;
render_footer();
