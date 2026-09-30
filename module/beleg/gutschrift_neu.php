<?php
// Storno-Rechnung / Gutschrift manuell erstellen: Kunde + Datum + Positionen (wie beim Angebot).
// Für alte Bestellungen, aus denen etwas herausstorniert werden soll.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'gutschrift_save') {
    $kid   = (int)($_POST['kunde_id'] ?? 0);
    $datum = trim($_POST['datum'] ?? '') !== '' ? $_POST['datum'] : date('Y-m-d');
    $grund = trim($_POST['grund'] ?? '');
    // Nummer der Ursprungsrechnung: verknuepft die Storno-Rechnung (storno_von_id) und befuellt Kunde/Bezug.
    $stornoNr  = trim($_POST['storno_nr'] ?? '');
    $stornoVon = null;
    if ($stornoNr !== '') {
        $rb = one("SELECT id, kunde_id, nummer FROM beleg WHERE nummer=? AND typ='rechnung' LIMIT 1", [$stornoNr]);
        if ($rb) { $stornoVon = (int)$rb['id']; if (!$kid) $kid = (int)$rb['kunde_id']; if ($grund === '') $grund = 'Storno zu Rechnung ' . $rb['nummer']; }
        elseif ($grund === '') { $grund = 'Storno zu Rechnung ' . $stornoNr; }   // Nummer trotzdem als Bezug uebernehmen
    }
    $positionen = [];
    foreach (($_POST['p_bez'] ?? []) as $i => $bez) {
        $bez = trim((string)$bez);
        if ($bez === '') continue;
        $menge = (float) str_replace(',', '.', $_POST['p_menge'][$i] ?? '0');
        $preis = (float) str_replace(',', '.', $_POST['p_preis'][$i] ?? '0');
        $mwst  = (float) str_replace(',', '.', $_POST['p_mwst'][$i] ?? '0');
        if ($menge <= 0) $menge = 1;
        $positionen[] = [
            'artikelnr'   => trim((string)($_POST['p_artikelnr'][$i] ?? '')),
            'bezeichnung' => $bez,
            'beschreibung'=> trim((string)($_POST['p_besch'][$i] ?? '')),
            'menge'       => $menge,
            'einheit'     => trim((string)($_POST['p_einheit'][$i] ?? '')) ?: 'Stk.',
            'preis_cent'  => -abs((int) round($preis * 100)),   // Gutschrift = negativer Betrag
            'mwst_satz'   => $mwst,
        ];
    }
    if (!$kid)            $fehler = 'Bitte einen Kunden wählen.';
    elseif (!$positionen) $fehler = 'Bitte mindestens eine Position mit Bezeichnung angeben.';
    else {
        $bid = gutschrift_erstellen($kid, $datum, $positionen, $grund, $stornoVon);
        header('Location: ?p=rechnung&id=' . $bid . '&gespeichert=1'); exit;
    }
}
// Schnellweg: ausgewählte offene Rechnungen des Kunden stornieren & verrechnen (je Rechnung eine Gutschrift).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'storno_offene') {
    $akteur = (function_exists('current_user') && ($u = current_user())) ? $u['name'] : 'team';
    $grund  = trim($_POST['grund'] ?? '') ?: 'Verrechnung offener Rechnung';
    $n = 0;
    foreach (array_map('intval', $_POST['re_ids'] ?? []) as $rid)
        if ($rid && gutschrift_aus_rechnung($rid, $grund, $akteur)) $n++;
    header('Location: ?p=rechnungen&verrechnet=' . $n); exit;
}

// KI: hochgeladene Original-Rechnung (PDF/Bild) in Positionen zerlegen -> Formular wird damit vorbefüllt.
$prefill = []; $kiInfo = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'ki_extract') {
    require_once BX_ROOT . '/core/ki.php';
    $vorKidPost = (int)($_POST['kunde_id'] ?? 0);
    if (!ki_bereit()) {
        $fehler = 'KI ist nicht verfügbar (kein Schlüssel hinterlegt). Bitte Positionen manuell erfassen.';
    } elseif (empty($_FILES['rechnung']['name']) || (int)($_FILES['rechnung']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $fehler = 'Bitte eine Datei (PDF oder Bild) hochladen.';
    } else {
        $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo((string)$_FILES['rechnung']['name'], PATHINFO_EXTENSION)));
        $save = rtrim(sys_get_temp_dir(), '/\\') . '/re_' . bin2hex(random_bytes(5)) . ($ext ? '.' . $ext : '');
        if (!@move_uploaded_file($_FILES['rechnung']['tmp_name'], $save)) $save = (string)$_FILES['rechnung']['tmp_name'];
        $prompt = "Dies ist eine Rechnung oder ein Angebot. Extrahiere ALLE Positionen (Zeilenartikel) als JSON-Array.\n"
            . "Jede Position: {\"artikelnr\":\"\",\"bezeichnung\":\"\",\"beschreibung\":\"\",\"menge\":0,\"einheit\":\"\",\"einzelpreis\":0,\"ust\":19}.\n"
            . "einzelpreis = Netto-Einzelpreis je Einheit (NICHT die Zeilensumme). beschreibung = die Detailzeilen unter der Position, mehrzeilig mit \\n getrennt.\n"
            . "ust = USt-Satz in Prozent (falls nicht erkennbar 19). Zahlen mit Punkt als Dezimaltrennzeichen. Antworte nur mit dem JSON-Array.";
        $r = ki_datei_frage($save, $prompt, ['json' => true, 'max_tokens' => 4000]);
        if (@is_file($save) && strpos($save, sys_get_temp_dir()) === 0) @unlink($save);
        if (empty($r['ok'])) {
            $fehler = 'Die Rechnung konnte nicht gelesen werden: ' . ($r['fehler'] ?? 'unbekannter Fehler');
        } else {
            $d = $r['daten'] ?? [];
            $list = (isset($d['positionen']) && is_array($d['positionen'])) ? $d['positionen'] : (array_is_list($d) ? $d : []);
            $ustStdV = (float) meta_get('ust_inland', 19);
            foreach ($list as $p) {
                if (!is_array($p)) continue;
                $bez = trim((string)($p['bezeichnung'] ?? $p['name'] ?? '')); if ($bez === '') continue;
                $prefill[] = [
                    'artikelnr' => trim((string)($p['artikelnr'] ?? $p['artikelnummer'] ?? '')),
                    'bez'       => $bez,
                    'besch'     => trim((string)($p['beschreibung'] ?? '')),
                    'menge'     => (float) str_replace(',', '.', (string)($p['menge'] ?? 1)) ?: 1,
                    'einheit'   => trim((string)($p['einheit'] ?? 'Stk.')) ?: 'Stk.',
                    'preis'     => (float) str_replace(',', '.', (string)($p['einzelpreis'] ?? $p['preis'] ?? 0)),
                    'ust'       => (float) str_replace(',', '.', (string)($p['ust'] ?? $ustStdV)),
                ];
            }
            if ($prefill) $kiInfo = count($prefill) . ' Position(en) erkannt – prüfe die Werte und entferne, was NICHT storniert werden soll.';
            else $fehler = 'Es konnten keine Positionen erkannt werden. Bitte manuell erfassen.';
        }
    }
    if ($vorKidPost) $_GET['kunde_id'] = $vorKidPost;   // Kunde-Auswahl beibehalten
}

$kunden  = all("SELECT id, firma, kundennummer FROM kunden ORDER BY firma");
$ustStd  = rtrim(rtrim(number_format((float) meta_get('ust_inland', 19), 2, '.', ''), '0'), '.');
$vorKid  = (int)($_GET['kunde_id'] ?? ($_POST['kunde_id'] ?? 0));

// Offene Rechnungen des gewählten Kunden (für den Schnellweg „verrechnen").
$offeneRe = [];
if ($vorKid) {
    foreach (all("SELECT * FROM beleg WHERE kunde_id=? AND typ='rechnung' AND status<>'storniert' ORDER BY datum, id", [$vorKid]) as $b) {
        $zs = beleg_zahlstatus($b);
        if ($zs['rest'] > 0.005) $offeneRe[] = $b + ['_rest' => $zs['rest']];
    }
}

render_header('rechnungen', 'Storno-Rechnung');
bx_head('Storno-Rechnung / Gutschrift', 'Kunde, Datum und Positionen angeben – wie beim Angebot', bx_btn('Zurück zu Rechnungen', '?p=rechnungen', 'ghost'));
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($fehler) . '</div>';
$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
?>
<!-- Schnellweg: offene Rechnungen des Kunden stornieren & verrechnen -->
<div class="bx-panel" style="border-color:var(--gruen)">
  <h2 style="margin-top:0">Offene Rechnungen stornieren &amp; verrechnen</h2>
  <p class="muted" style="margin-top:0">1. Kunde wählen &middot; 2. offene Rechnungen anhaken &middot; 3. „Stornieren &amp; verrechnen". Je gewählte Rechnung entsteht eine Storno-Rechnung (Gutschrift), die sie ausgleicht.</p>
  <form method="get" class="bx-row" style="gap:8px;align-items:flex-end;margin-bottom:6px">
    <input type="hidden" name="p" value="gutschrift_neu">
    <div class="bx-field" style="margin:0;min-width:320px"><label>Kunde</label>
      <select name="kunde_id" onchange="this.form.submit()">
        <option value="">– Kunde wählen –</option>
        <?php foreach ($kunden as $k): ?><option value="<?= (int)$k['id'] ?>" <?= $vorKid===(int)$k['id']?'selected':'' ?>><?= h($k['firma']) ?><?= $k['kundennummer'] ? ' · '.h($k['kundennummer']) : '' ?></option><?php endforeach; ?>
      </select>
    </div>
    <noscript><button class="btn btn-ghost btn-sm" type="submit">Offene Rechnungen laden</button></noscript>
  </form>
  <?php if ($vorKid && !$offeneRe): ?>
    <div class="muted">Keine offenen Rechnungen für diesen Kunden.</div>
  <?php elseif ($offeneRe): ?>
  <form method="post" onsubmit="return confirm('Ausgewählte Rechnungen stornieren und verrechnen? Je Rechnung entsteht eine Gutschrift.');">
    <input type="hidden" name="aktion" value="storno_offene">
    <input type="hidden" name="kunde_id" value="<?= (int)$vorKid ?>">
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th style="width:34px"><input type="checkbox" id="reAll" checked></th><th>Rechnung</th><th>Datum</th><th class="bx-num">Brutto</th><th class="bx-num">Offener Rest</th></tr></thead>
      <tbody>
        <?php foreach ($offeneRe as $b): ?>
        <tr>
          <td><input type="checkbox" class="reChk" name="re_ids[]" value="<?= (int)$b['id'] ?>" checked></td>
          <td><a href="?p=rechnung&id=<?= (int)$b['id'] ?>" target="_blank"><?= h($b['nummer']) ?></a></td>
          <td><?= $b['datum'] ? h(date('d.m.Y', strtotime($b['datum']))) : '–' ?></td>
          <td class="bx-num"><?= $eur($b['brutto']) ?></td>
          <td class="bx-num"><strong><?= $eur($b['_rest']) ?></strong></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="bx-field" style="max-width:420px;margin-top:8px"><label>Grund (optional)</label><input type="text" name="grund" placeholder="z. B. Storno / Verrechnung"></div>
    <div class="bx-row" style="margin-top:var(--sp-4)"><button class="btn btn-primary" type="submit">Ausgewählte stornieren &amp; verrechnen</button></div>
  </form>
  <script>var _reAll=document.getElementById('reAll'); if(_reAll)_reAll.addEventListener('change',function(){var c=this.checked;document.querySelectorAll('.reChk').forEach(function(x){x.checked=c;});});</script>
  <?php endif; ?>
</div>

<?php if ($kiInfo) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h($kiInfo) . '</div>'; ?>
<div class="bx-panel" style="border-color:var(--gruen)">
  <h2 style="margin-top:0">Original-Rechnung hochladen (KI liest die Positionen)</h2>
  <p class="muted" style="margin-top:0">PDF oder Bild der Original-Rechnung hochladen – die KI zerlegt sie in Positionen und füllt das Formular unten. Dann nur noch entfernen, was <strong>nicht</strong> storniert werden soll, und erstellen.</p>
  <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:8px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="ki_extract">
    <input type="hidden" name="kunde_id" value="<?= (int)$vorKid ?>">
    <div class="bx-field" style="margin:0"><label>Rechnung (PDF / Bild)</label><input type="file" name="rechnung" accept="application/pdf,image/*" required></div>
    <button class="btn btn-primary" type="submit">Hochladen &amp; auslesen</button>
  </form>
</div>

<h2 style="margin:24px 0 8px">Storno-Rechnung – Positionen prüfen &amp; erstellen</h2>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="gutschrift_save">
  <div class="bx-panel">
    <div class="bx-grid">
      <div class="bx-field"><label>Kunde</label>
        <select name="kunde_id" required>
          <option value="">– Kunde wählen –</option>
          <?php foreach ($kunden as $k): ?>
            <option value="<?= (int)$k['id'] ?>" <?= $vorKid===(int)$k['id']?'selected':'' ?>><?= h($k['firma']) ?><?= $k['kundennummer'] ? ' · '.h($k['kundennummer']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Datum</label><input type="date" name="datum" value="<?= h(date('Y-m-d')) ?>"></div>
      <div class="bx-field"><label>Storno zu Rechnung (Nummer) <?= bx_hint('Nummer der Ursprungsrechnung, z. B. RE-2026-0123. Wird verknüpft und auf dem Beleg als „Storno zu Rechnung …" ausgewiesen; Kunde wird übernommen, falls leer.') ?></label><input type="text" name="storno_nr" value="<?= h((string)($_POST['storno_nr'] ?? '')) ?>" placeholder="z. B. RE-2026-0123"></div>
      <div class="bx-field"><label>Grund / Bezug (optional) <?= bx_hint('Erscheint zusätzlich auf dem Beleg. Leer = automatisch „Storno zu Rechnung <Nummer>".') ?></label><input type="text" name="grund" placeholder="z. B. Teilstorno / falsche Menge"></div>
    </div>
  </div>

  <div class="bx-panel">
    <h2 style="margin-top:0">Positionen</h2>
    <p class="muted" style="margin-top:0">Preise als normale (positive) Beträge eintragen – der Beleg weist sie als Gutschrift (negativ) aus.</p>

    <details style="margin-bottom:12px">
      <summary style="cursor:pointer;color:var(--gruen)">Positionen aus Text einfügen (aus altem Angebot / alter Rechnung)</summary>
      <p class="muted" style="font-size:12px;margin:8px 0 4px">Text aus dem alten Angebot / der alten Rechnung kopieren und einfügen. „Positionen übernehmen" erkennt das Spaltenformat (Pos · Artikel-Nr. · Bezeichnung · Menge · Einheit · Einzelpreis · Gesamt). Klappt das nicht, nimmt <strong>„Roh übernehmen"</strong> einfach <strong>jede Zeile als eigene Position</strong> (Betrag am Zeilenende wird als Preis übernommen) – dann nur noch prüfen.</p>
      <textarea id="gsPaste" rows="6" style="width:100%;font-family:monospace;font-size:12px" placeholder="14 VCB 1.32.8 V Collagen Booster 1.000,00 Stk. 7,54 7.540,00&#10;V-COL® – 6 928 mg&#10;8g pro Tag, 30 Portionen&#10;15 STB 500g Standbodenbeutel 1.000,00 Stk. 0,75 750,00"></textarea>
      <div class="bx-row" style="margin-top:6px;gap:8px;align-items:center;flex-wrap:wrap">
        <button type="button" class="btn btn-primary btn-sm" id="gsPasteBtn">Positionen übernehmen</button>
        <button type="button" class="btn btn-ghost btn-sm" id="gsRawBtn">Roh übernehmen (1 Zeile = 1 Position)</button>
        <span class="muted" id="gsPasteInfo" style="font-size:12px"></span>
      </div>
    </details>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr>
        <th style="width:32px"></th>
        <th>Artikel-Nr.</th><th>Bezeichnung / Beschreibung</th>
        <th class="bx-num">Menge</th><th>Einheit</th><th class="bx-num">Einzelpreis (€)</th><th class="bx-num">USt %</th>
      </tr></thead>
      <tbody id="gsrows">
        <?php
        $rows = $prefill ?: [];
        if (!$rows) for ($i = 0; $i < 3; $i++) $rows[] = ['artikelnr'=>'','bez'=>'','besch'=>'','menge'=>'1','einheit'=>'Stk.','preis'=>'','ust'=>$ustStd];
        foreach ($rows as $r):
            $pv = ($r['preis'] ?? '') !== '' && $r['preis'] !== null ? rtrim(rtrim(number_format((float)$r['preis'], 2, ',', ''), '0'), ',') : '';
        ?>
        <tr>
          <td><button type="button" class="btn btn-ghost btn-sm gsDel" title="Zeile entfernen" style="padding:2px 9px;line-height:1">&times;</button></td>
          <td><input type="text" name="p_artikelnr[]" value="<?= h((string)($r['artikelnr'] ?? '')) ?>" style="max-width:110px"></td>
          <td>
            <input type="text" name="p_bez[]" value="<?= h((string)($r['bez'] ?? '')) ?>" placeholder="Bezeichnung" style="width:100%">
            <textarea name="p_besch[]" rows="2" placeholder="Beschreibung (optional, mehrzeilig)" style="width:100%;margin-top:4px"><?= h((string)($r['besch'] ?? '')) ?></textarea>
          </td>
          <td class="bx-num"><input type="text" inputmode="decimal" name="p_menge[]" value="<?= h((string)($r['menge'] ?? '1')) ?>" style="max-width:90px;text-align:right"></td>
          <td><input type="text" name="p_einheit[]" value="<?= h((string)($r['einheit'] ?? 'Stk.')) ?>" style="max-width:80px"></td>
          <td class="bx-num"><input type="text" inputmode="decimal" name="p_preis[]" value="<?= h($pv) ?>" placeholder="0,00" style="max-width:120px;text-align:right"></td>
          <td class="bx-num"><input type="text" inputmode="decimal" name="p_mwst[]" value="<?= h((string)($r['ust'] ?? $ustStd)) ?>" style="max-width:70px;text-align:right"></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="bx-row" style="margin-top:var(--sp-4)">
      <button type="button" class="btn btn-ghost btn-sm" id="gsAdd">+ Position</button>
    </div>
  </div>

  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit">Storno-Rechnung erstellen</button>
    <a class="btn btn-ghost" href="?p=rechnungen">Abbrechen</a>
  </div>
</form>

<script>
(function(){
  var UST = <?= json_encode($ustStd) ?>;
  var tbody = document.getElementById('gsrows');
  function rowHTML(){
    return '<td><button type="button" class="btn btn-ghost btn-sm gsDel" title="Zeile entfernen" style="padding:2px 9px;line-height:1">&times;</button></td>'
      + '<td><input type="text" name="p_artikelnr[]" style="max-width:110px"></td>'
      + '<td><input type="text" name="p_bez[]" placeholder="Bezeichnung" style="width:100%">'
      + '<textarea name="p_besch[]" rows="2" placeholder="Beschreibung (optional, mehrzeilig)" style="width:100%;margin-top:4px"></textarea></td>'
      + '<td class="bx-num"><input type="text" inputmode="decimal" name="p_menge[]" value="1" style="max-width:90px;text-align:right"></td>'
      + '<td><input type="text" name="p_einheit[]" value="Stk." style="max-width:80px"></td>'
      + '<td class="bx-num"><input type="text" inputmode="decimal" name="p_preis[]" placeholder="0,00" style="max-width:120px;text-align:right"></td>'
      + '<td class="bx-num"><input type="text" inputmode="decimal" name="p_mwst[]" value="'+UST+'" style="max-width:70px;text-align:right"></td>';
  }
  function addRow(d){
    var tr=document.createElement('tr'); tr.innerHTML=rowHTML(); tbody.appendChild(tr);
    if(d){
      tr.querySelector('[name="p_artikelnr[]"]').value = d.artikelnr || '';
      tr.querySelector('[name="p_bez[]"]').value = d.bez || '';
      tr.querySelector('[name="p_besch[]"]').value = d.besch || '';
      if(d.menge!=='' && d.menge!=null)  tr.querySelector('[name="p_menge[]"]').value = d.menge;
      tr.querySelector('[name="p_einheit[]"]').value = d.einheit || 'Stk.';
      if(d.preis!=='' && d.preis!=null)  tr.querySelector('[name="p_preis[]"]').value = d.preis;
      if(d.mwst!=='' && d.mwst!=null)    tr.querySelector('[name="p_mwst[]"]').value = d.mwst;
    }
    return tr;
  }
  document.getElementById('gsAdd').addEventListener('click', function(){ addRow(); });
  // Zeile entfernen (× je Zeile) – auswählen, was NICHT storniert werden soll
  tbody.addEventListener('click', function(e){ var b=e.target.closest('.gsDel'); if(b){ var tr=b.closest('tr'); if(tr) tr.remove(); } });

  function deNum(s){ if(s==null) return ''; s=String(s).trim().replace(/\s/g,'').replace(/\./g,'').replace(',','.'); var n=parseFloat(s); return isNaN(n)?'':n; }
  function fmtDe(n){ return n===''?'':String(n).replace('.',','); }
  // STRICT: Kopfzeile "Pos Artikelnr Bezeichnung Menge Einheit Einzelpreis Gesamt" + Beschreibungszeilen darunter.
  function parseStrict(text){
    var lines=text.split(/\r?\n/), out=[], cur=null;
    var hdr=/^\s*\d+\s+(.+?)\s+([\d.]+,\d{2})\s+(\S+)\s+([\d.]+,\d{2})\s+([\d.]+,\d{2})\s*$/;
    lines.forEach(function(ln){
      var m=ln.match(hdr);
      if(m){
        if(cur) out.push(cur);
        var mid=m[1].trim(), art='', bez=mid;
        var am=mid.match(/^([A-Za-zÄÖÜäöü]{2,6}\s+[\w.\-]+)\s+(.+)$/);
        if(am){ art=am[1]; bez=am[2]; }
        cur={artikelnr:art, bez:bez, besch:[], menge:m[2], einheit:m[3], preis:m[4]};
      } else if(cur && ln.trim()!==''){ cur.besch.push(ln.trim()); }
    });
    if(cur) out.push(cur);
    return out;
  }
  // ROH: jede nicht-leere Zeile = eine Position. Betrag am Zeilenende (z. B. 7.540,00 oder 12,00 €) wird als
  // Preis uebernommen (Menge 1 -> Positionssumme = dieser Betrag), der Rest der Zeile wird die Bezeichnung.
  function parseRaw(text){
    return text.split(/\r?\n/).map(function(l){return l.replace(/\t/g,' ').trim();}).filter(Boolean).map(function(l){
      var m=l.match(/(\d{1,3}(?:[.\s]\d{3})*,\d{2}|\d+[.,]\d{2})\s*(?:€|EUR)?\s*$/);
      var preis='', bez=l;
      if(m){ preis=m[1]; bez=l.slice(0,m.index).trim() || l; }
      return {artikelnr:'', bez:bez, besch:[], menge:'1', einheit:'Stk.', preis:preis};
    });
  }
  // SMART: erkennt Positionen und behält Beschreibungen. Zeile mit Betrag am Ende = Position;
  // Zeile OHNE Betrag = Beschreibung der aktuellen Position (nichts geht verloren, egal welches Format).
  function parseSmart(text){
    var lines=text.split(/\r?\n/).map(function(l){return l.replace(/\t/g,' ').trim();});
    var out=[], cur=null;
    var full=/^\s*\d+\s+(.+?)\s+([\d.]+,\d{2})\s+(\S+)\s+([\d.]+,\d{2})\s+([\d.]+,\d{2})\s*$/;   // Pos Artnr Bez Menge Einheit Preis Gesamt
    var tail=/(\d{1,3}(?:[.\s]\d{3})*,\d{2}|\d+[.,]\d{2})\s*(?:€|EUR)?\s*$/;                       // Betrag am Zeilenende
    lines.forEach(function(l){
      if(l==='') return;
      var m=l.match(full);
      if(m){
        var mid=m[1].trim(), art='', bez=mid;
        var am=mid.match(/^([A-Za-zÄÖÜäöü]{2,6}\s+[\w.\-]+)\s+(.+)$/);
        if(am){ art=am[1]; bez=am[2]; }
        cur={artikelnr:art, bez:bez, besch:[], menge:m[2], einheit:m[3], preis:m[4]}; out.push(cur); return;
      }
      var t=l.match(tail);
      if(t){   // Betrag am Ende, aber kein volles Spaltenformat -> Position (Bez = Zeile ohne Betrag)
        var bez2=l.slice(0,t.index).trim().replace(/^\d+\s+/,'') || l;
        cur={artikelnr:'', bez:bez2, besch:[], menge:'1', einheit:'Stk.', preis:t[1]}; out.push(cur); return;
      }
      if(cur){ cur.besch.push(l); }   // keine Zahl am Ende -> Beschreibung der laufenden Position
      else { cur={artikelnr:'', bez:l, besch:[], menge:'1', einheit:'Stk.', preis:''}; out.push(cur); }
    });
    return out;
  }
  function clearEmptyRows(){
    Array.prototype.slice.call(tbody.querySelectorAll('tr')).forEach(function(tr){
      var b=tr.querySelector('[name="p_bez[]"]'); if(b && b.value.trim()==='') tr.remove();
    });
  }
  function fill(items){
    clearEmptyRows();
    items.forEach(function(it){
      addRow({artikelnr:it.artikelnr||'', bez:it.bez, besch:(it.besch||[]).join('\n'),
              menge:fmtDe(deNum(it.menge)), einheit:it.einheit||'Stk.', preis:fmtDe(deNum(it.preis)), mwst:UST});
    });
  }
  document.getElementById('gsPasteBtn').addEventListener('click', function(){
    var txt=document.getElementById('gsPaste').value||'', info=document.getElementById('gsPasteInfo');
    var items=parseSmart(txt);   // behält Beschreibungen (Zeilen ohne Betrag) an der Position
    if(!items.length){ info.textContent='Kein Text zum Übernehmen.'; return; }
    fill(items);
    var mitBesch=items.filter(function(it){return it.besch && it.besch.length;}).length;
    info.textContent=items.length+' Position(en) übernommen'+(mitBesch?' (inkl. Beschreibungen)':'')+' – bitte prüfen/anpassen.';
  });
  document.getElementById('gsRawBtn').addEventListener('click', function(){
    var items=parseRaw(document.getElementById('gsPaste').value||''), info=document.getElementById('gsPasteInfo');
    if(!items.length){ info.textContent='Kein Text zum Übernehmen.'; return; }
    fill(items); info.textContent=items.length+' Zeile(n) roh übernommen – bitte Beträge/Mengen prüfen.';
  });
})();
</script>
<?php render_footer(); ?>
