<?php
// Rechnung frei erstellen – KI-gestützt: Nutzer beschreibt die Rechnung in eigenen Worten
// (optional zusätzlich eine Datei), die KI baut Kunde + Positionen + Zahlungsziel daraus.
// Alles bleibt editierbar; erst „Rechnung erstellen" legt den Beleg an (rechnung_frei_erstellen()).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$fehler = ''; $kiInfo = '';
$ustStdV = (float) meta_get('ust_inland', 19);
$ustStd  = rtrim(rtrim(number_format($ustStdV, 2, '.', ''), '0'), '.');

// Aktuell eingeloggter Nutzer = Bearbeiter/Ersteller.
$akteurU  = (function_exists('current_user')) ? current_user() : null;
$akteur   = $akteurU ? ($akteurU['name'] ?: 'team') : 'team';
$akteurId = $akteurU ? (int)$akteurU['id'] : 0;

// --- Rechnung speichern -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'rechnung_save') {
    $kid   = (int)($_POST['kunde_id'] ?? 0);
    $positionen = [];
    foreach (($_POST['p_bez'] ?? []) as $i => $bez) {
        $bez = trim((string)$bez);
        if ($bez === '') continue;
        $positionen[] = [
            'artikelnr'   => trim((string)($_POST['p_artikelnr'][$i] ?? '')),
            'bezeichnung' => $bez,
            'beschreibung'=> trim((string)($_POST['p_besch'][$i] ?? '')),
            'menge'       => $_POST['p_menge'][$i] ?? '1',
            'einheit'     => trim((string)($_POST['p_einheit'][$i] ?? '')) ?: 'Stk.',
            'preis'       => $_POST['p_preis'][$i] ?? '0',
            'mwst_satz'   => $_POST['p_mwst'][$i] ?? $ustStd,
        ];
    }
    if (!$positionen) {
        $fehler = 'Bitte mindestens eine Position mit Bezeichnung und Preis angeben.';
    } else {
        $bid = rechnung_frei_erstellen($positionen, [
            'kunde_id'          => $kid,
            'datum'             => trim($_POST['datum'] ?? ''),
            'zahlungsziel_tage' => trim($_POST['zahlungsziel_tage'] ?? ''),
            'leistung_datum'    => trim($_POST['leistung_datum'] ?? ''),
            'text'              => trim($_POST['text'] ?? ''),
            'freigeben'         => !empty($_POST['freigeben']),
            'ersteller'         => $akteur,
            'bearbeiter_id'     => $akteurId,
        ]);
        if ($bid) { header('Location: ?p=rechnung&id=' . $bid . '&gespeichert=1'); exit; }
        $fehler = 'Rechnung konnte nicht erstellt werden – fehlt ein Preis? Bitte Positionen prüfen.';
    }
}

// --- KI: Freitext (+ optionale Datei) -> Vorbefüllung ----------------------
$prefill = [];
$kiKunde = ''; $kiZiel = ''; $kiLeist = ''; $kiText = ''; $kiDatum = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'ki_bauen') {
    require_once BX_ROOT . '/core/ki.php';
    $text = trim((string)($_POST['frei'] ?? ''));
    $hatDatei = !empty($_FILES['anhang']['name']) && (int)($_FILES['anhang']['error'] ?? 1) === UPLOAD_ERR_OK;
    if (!ki_bereit()) {
        $fehler = 'KI ist nicht verfügbar (kein Schlüssel hinterlegt). Bitte Positionen unten manuell erfassen.';
    } elseif ($text === '' && !$hatDatei) {
        $fehler = 'Bitte die Rechnung in eigenen Worten beschreiben – oder eine Datei hochladen.';
    } else {
        // Kundenliste als Kontext, damit die KI auf eine bestehende Firma matcht.
        $kundenAll = all("SELECT firma FROM kunden WHERE firma<>'' ORDER BY firma");
        $firmen = array_slice(array_column($kundenAll, 'firma'), 0, 400);
        $system = "Du bist die Rechnungs-Assistenz eines Lohnherstellers für Nahrungsergänzungsmittel (Marke bulkify). "
            . "Aus der Beschreibung des Nutzers baust du die Daten für EINE Ausgangsrechnung. "
            . "Gib NUR JSON zurück, exakt in dieser Form:\n"
            . '{"kunde":"","datum":"","zahlungsziel_tage":null,"leistung_datum":"","text":"","positionen":[{"artikelnr":"","bezeichnung":"","beschreibung":"","menge":1,"einheit":"Stk.","einzelpreis":0,"ust":' . $ustStd . "}]}\n"
            . "Regeln: einzelpreis = NETTO-Einzelpreis je Einheit (nicht die Zeilensumme), Zahl mit Punkt als Dezimaltrennzeichen. "
            . "Nennt der Nutzer nur eine Zeilensumme und eine Menge, rechne den Einzelpreis aus. "
            . "ust = USt-Satz in Prozent; wenn nicht genannt, nutze " . $ustStd . ". "
            . "datum = RECHNUNGSDATUM im Format YYYY-MM-DD. Nennt der Nutzer ein Datum, ist das das Rechnungsdatum (datum) – NICHT das Leistungsdatum, außer er sagt ausdrücklich \"Leistung/Lieferung am\". Gibt er kein Datum an, lass datum leer (\"\"). "
            . "leistung_datum = NUR wenn der Nutzer ausdrücklich ein separates Leistungs-/Lieferdatum nennt, im Format YYYY-MM-DD, sonst \"\". "
            . "zahlungsziel_tage = Zahl der Tage (z. B. 14), sonst null. "
            . "text = kurzer Rechnungs-/Einleitungstext nur wenn der Nutzer einen wünscht, sonst \"\". "
            . "kunde = passender Firmenname. Wenn er zu einer dieser bekannten Firmen passt, gib EXAKT diese Schreibweise zurück:\n"
            . implode("\n", $firmen);
        $anw = "Beschreibung des Nutzers für die Rechnung:\n\n" . ($text !== '' ? $text : '(siehe angehängte Datei)');
        if ($hatDatei) {
            $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo((string)$_FILES['anhang']['name'], PATHINFO_EXTENSION)));
            $save = rtrim(sys_get_temp_dir(), '/\\') . '/refr_' . bin2hex(random_bytes(5)) . ($ext ? '.' . $ext : '');
            if (!@move_uploaded_file($_FILES['anhang']['tmp_name'], $save)) $save = (string)$_FILES['anhang']['tmp_name'];
            $r = ki_datei_frage($save, $anw, ['json' => true, 'system' => $system, 'max_tokens' => 4000, 'zweck' => 'rechnung_frei']);
            if (@is_file($save) && strpos($save, sys_get_temp_dir()) === 0) @unlink($save);
        } else {
            $r = ki_json($anw, ['system' => $system, 'max_tokens' => 4000, 'zweck' => 'rechnung_frei']);
        }
        if (empty($r['ok'])) {
            $fehler = 'Die KI konnte daraus keine Rechnung bauen: ' . ($r['fehler'] ?? 'unbekannter Fehler');
        } else {
            $d = $r['daten'] ?? [];
            $kiKunde = trim((string)($d['kunde'] ?? ''));
            $kiZiel  = ($d['zahlungsziel_tage'] ?? null) !== null && $d['zahlungsziel_tage'] !== '' ? (string)(int)$d['zahlungsziel_tage'] : '';
            $kiDatum = (is_string($d['datum'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['datum'])) ? $d['datum'] : '';
            $kiLeist = (is_string($d['leistung_datum'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['leistung_datum'])) ? $d['leistung_datum'] : '';
            $kiText  = trim((string)($d['text'] ?? ''));
            $list = (isset($d['positionen']) && is_array($d['positionen'])) ? $d['positionen'] : (array_is_list($d) ? $d : []);
            foreach ($list as $p) {
                if (!is_array($p)) continue;
                $bez = trim((string)($p['bezeichnung'] ?? $p['name'] ?? '')); if ($bez === '') continue;
                $prefill[] = [
                    'artikelnr' => trim((string)($p['artikelnr'] ?? '')),
                    'bez'       => $bez,
                    'besch'     => trim((string)($p['beschreibung'] ?? '')),
                    'menge'     => (float) str_replace(',', '.', (string)($p['menge'] ?? 1)) ?: 1,
                    'einheit'   => trim((string)($p['einheit'] ?? 'Stk.')) ?: 'Stk.',
                    'preis'     => (float) str_replace(',', '.', (string)($p['einzelpreis'] ?? $p['preis'] ?? 0)),
                    'ust'       => (float) str_replace(',', '.', (string)($p['ust'] ?? $ustStdV)),
                ];
            }
            if ($prefill) $kiInfo = count($prefill) . ' Position(en) erkannt – bitte alles prüfen und ggf. anpassen, dann Rechnung erstellen.';
            else $fehler = 'Es konnten keine Positionen erkannt werden. Bitte unten manuell erfassen.';
        }
    }
}

$kunden = all("SELECT id, firma, kundennummer FROM kunden ORDER BY firma");
// Kunde aus KI-Treffer vorauswählen (exakt, sonst „enthält").
$vorKid = (int)($_POST['kunde_id'] ?? 0);
if (!$vorKid && $kiKunde !== '') {
    $n = mb_strtolower($kiKunde);
    foreach ($kunden as $k) { if (mb_strtolower((string)$k['firma']) === $n) { $vorKid = (int)$k['id']; break; } }
    if (!$vorKid) foreach ($kunden as $k) { if (mb_strpos(mb_strtolower((string)$k['firma']), $n) !== false || mb_strpos($n, mb_strtolower((string)$k['firma'])) !== false) { $vorKid = (int)$k['id']; break; } }
}

$kiStatus = (function(){ require_once BX_ROOT . '/core/ki.php'; return ki_bereit(); })();

render_header('rechnungen', 'Rechnung erstellen');
bx_head('Rechnung erstellen', 'In eigenen Worten beschreiben – die KI baut Kunde und Positionen, du prüfst und erstellst', bx_btn('Zurück zu Rechnungen', '?p=rechnungen', 'ghost'));
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($fehler) . '</div>';
if ($kiInfo) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h($kiInfo) . '</div>';
?>
<!-- KI-Eingabe: Freitext + optionale Datei -->
<div class="bx-panel" style="border-color:var(--gruen)">
  <h2 style="margin-top:0">Rechnung in eigenen Worten beschreiben</h2>
  <?php if (!$kiStatus): ?>
    <p class="muted" style="margin-top:0">KI ist auf diesem Server nicht eingerichtet – du kannst die Rechnung unten direkt manuell erfassen.</p>
  <?php else: ?>
  <p class="muted" style="margin-top:0">Alles Wichtige nennen: Kunde, Positionen (Menge, Preis), ggf. Zahlungsziel und Leistungsdatum. Beispiel: „Rechnung an Pure Health GmbH: 500 Dosen Vitamin D3 à 4,20 €, Zahlungsziel 14 Tage, Leistung September 2026." Optional zusätzlich eine Datei (Angebot/Lieferschein/Notiz) anhängen.</p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="aktion" value="ki_bauen">
    <div class="bx-field"><label>Beschreibung</label>
      <textarea name="frei" rows="4" style="width:100%;box-sizing:border-box" placeholder="z. B. Rechnung an … – 500 × … à … €, Zahlungsziel 14 Tage, Leistungsdatum …"><?= h((string)($_POST['frei'] ?? '')) ?></textarea>
    </div>
    <div class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
      <div class="bx-field" style="margin:0"><label>Datei anhängen (optional, PDF / Bild)</label><input type="file" name="anhang" accept="application/pdf,image/*"></div>
      <button class="btn btn-primary" type="submit">Rechnung bauen (KI)</button>
    </div>
  </form>
  <?php endif; ?>
</div>

<h2 style="margin:24px 0 8px">Rechnung prüfen &amp; erstellen</h2>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="rechnung_save">
  <div class="bx-panel">
    <div class="bx-grid">
      <div class="bx-field"><label>Kunde</label>
        <select name="kunde_id" required>
          <option value="">– Kunde wählen –</option>
          <?php foreach ($kunden as $k): ?>
            <option value="<?= (int)$k['id'] ?>" <?= $vorKid===(int)$k['id']?'selected':'' ?>><?= h($k['firma']) ?><?= $k['kundennummer'] ? ' · '.h($k['kundennummer']) : '' ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($kiKunde !== '' && !$vorKid): ?><span class="muted" style="font-size:12px">KI-Vorschlag „<?= h($kiKunde) ?>" – kein passender Kunde gefunden, bitte wählen.</span><?php endif; ?>
      </div>
      <div class="bx-field"><label>Rechnungsdatum</label><input type="date" name="datum" value="<?= h($kiDatum ?: date('Y-m-d')) ?>"></div>
      <div class="bx-field"><label>Leistungs-/Lieferdatum <?= bx_hint('Wann die Leistung erbracht wurde. Leer = kein gesondertes Leistungsdatum (dann gilt das Rechnungsdatum).') ?></label><input type="date" name="leistung_datum" value="<?= h($kiLeist) ?>"></div>
      <div class="bx-field"><label>Zahlungsziel (Tage) <?= bx_hint('Fälligkeit = Rechnungsdatum + Tage. Leer = kein gesondertes Zahlungsziel.') ?></label><input type="text" inputmode="numeric" name="zahlungsziel_tage" value="<?= h($kiZiel) ?>" placeholder="z. B. 14" style="max-width:140px"></div>
    </div>
    <div class="bx-field"><label>Rechnungstext / Hinweis (optional)</label><textarea name="text" rows="2" style="width:100%;box-sizing:border-box" placeholder="Erscheint auf der Rechnung, z. B. Bezug oder Danktext"><?= h($kiText) ?></textarea></div>
  </div>

  <div class="bx-panel">
    <h2 style="margin-top:0">Positionen</h2>
    <div class="bx-tablewrap"><table class="bx-table" style="table-layout:fixed;width:100%">
      <colgroup>
        <col style="width:38px"><col style="width:130px"><col>
        <col style="width:90px"><col style="width:80px"><col style="width:120px"><col style="width:78px"><col style="width:120px">
      </colgroup>
      <thead><tr>
        <th></th>
        <th>Artikel-Nr.</th><th>Bezeichnung / Beschreibung</th>
        <th class="bx-num">Menge</th><th>Einheit</th><th class="bx-num">Einzelpreis (€)</th><th class="bx-num">USt %</th><th class="bx-num">Zeile netto</th>
      </tr></thead>
      <tbody id="rfrows">
        <?php
        $rows = $prefill ?: [];
        if (!$rows) for ($i = 0; $i < 3; $i++) $rows[] = ['artikelnr'=>'','bez'=>'','besch'=>'','menge'=>'1','einheit'=>'Stk.','preis'=>'','ust'=>$ustStd];
        foreach ($rows as $r):
            $pv = ($r['preis'] ?? '') !== '' && $r['preis'] !== null ? rtrim(rtrim(number_format((float)$r['preis'], 2, ',', ''), '0'), ',') : '';
        ?>
        <tr>
          <td style="vertical-align:top"><button type="button" class="btn btn-ghost btn-sm rfDel" title="Zeile entfernen" style="padding:2px 9px;line-height:1">&times;</button></td>
          <td style="vertical-align:top"><input type="text" name="p_artikelnr[]" value="<?= h((string)($r['artikelnr'] ?? '')) ?>" style="width:100%;box-sizing:border-box"></td>
          <td style="vertical-align:top">
            <input type="text" name="p_bez[]" value="<?= h((string)($r['bez'] ?? '')) ?>" placeholder="Bezeichnung" style="display:block;width:100%;box-sizing:border-box">
            <textarea name="p_besch[]" rows="2" placeholder="Beschreibung (optional, mehrzeilig)" style="display:block;width:100%;box-sizing:border-box;margin-top:4px"><?= h((string)($r['besch'] ?? '')) ?></textarea>
          </td>
          <td style="vertical-align:top"><input type="text" inputmode="decimal" name="p_menge[]" value="<?= h((string)($r['menge'] ?? '1')) ?>" class="rfCalc" style="width:100%;box-sizing:border-box;text-align:right"></td>
          <td style="vertical-align:top"><input type="text" name="p_einheit[]" value="<?= h((string)($r['einheit'] ?? 'Stk.')) ?>" style="width:100%;box-sizing:border-box"></td>
          <td style="vertical-align:top"><input type="text" inputmode="decimal" name="p_preis[]" value="<?= h($pv) ?>" placeholder="0,00" class="rfCalc" style="width:100%;box-sizing:border-box;text-align:right"></td>
          <td style="vertical-align:top"><input type="text" inputmode="decimal" name="p_mwst[]" value="<?= h((string)($r['ust'] ?? $ustStd)) ?>" class="rfCalc" style="width:100%;box-sizing:border-box;text-align:right"></td>
          <td style="vertical-align:top;text-align:right" class="rfZeile muted">–</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="bx-row" style="margin-top:var(--sp-4);justify-content:space-between;flex-wrap:wrap;gap:10px">
      <button type="button" class="btn btn-ghost btn-sm" id="rfAdd">+ Position</button>
      <div style="text-align:right;min-width:240px">
        <div class="bx-row" style="justify-content:space-between;gap:24px"><span class="muted">Netto</span><strong id="rfNetto">0,00 €</strong></div>
        <div class="bx-row" style="justify-content:space-between;gap:24px"><span class="muted">USt</span><span id="rfUst">0,00 €</span></div>
        <div class="bx-row" style="justify-content:space-between;gap:24px;font-size:16px"><span>Brutto</span><strong id="rfBrutto">0,00 €</strong></div>
      </div>
    </div>
  </div>

  <div class="bx-panel">
    <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
      <input type="checkbox" name="freigeben" value="1" style="margin-top:3px">
      <span>Rechnung direkt im <strong>Kundenportal freigeben</strong> (Kunde sieht sie sofort). Sonst erst intern – Freigabe später auf der Rechnung.</span>
    </label>
  </div>

  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit">Rechnung erstellen</button>
    <a class="btn btn-ghost" href="?p=rechnungen">Abbrechen</a>
  </div>
</form>

<script>
(function(){
  var tbody = document.getElementById('rfrows');
  function num(v){ v=(v||'').toString().trim().replace(/\./g,'').replace(',','.'); var n=parseFloat(v); return isNaN(n)?0:n; }
  function eur(x){ return x.toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2})+' €'; }
  function rowHTML(){
    var vt='vertical-align:top';
    return '<td style="'+vt+'"><button type="button" class="btn btn-ghost btn-sm rfDel" title="Zeile entfernen" style="padding:2px 9px;line-height:1">&times;</button></td>'
      + '<td style="'+vt+'"><input type="text" name="p_artikelnr[]" style="width:100%;box-sizing:border-box"></td>'
      + '<td style="'+vt+'"><input type="text" name="p_bez[]" placeholder="Bezeichnung" style="display:block;width:100%;box-sizing:border-box">'
      + '<textarea name="p_besch[]" rows="2" placeholder="Beschreibung (optional, mehrzeilig)" style="display:block;width:100%;box-sizing:border-box;margin-top:4px"></textarea></td>'
      + '<td style="'+vt+'"><input type="text" inputmode="decimal" name="p_menge[]" value="1" class="rfCalc" style="width:100%;box-sizing:border-box;text-align:right"></td>'
      + '<td style="'+vt+'"><input type="text" name="p_einheit[]" value="Stk." style="width:100%;box-sizing:border-box"></td>'
      + '<td style="'+vt+'"><input type="text" inputmode="decimal" name="p_preis[]" placeholder="0,00" class="rfCalc" style="width:100%;box-sizing:border-box;text-align:right"></td>'
      + '<td style="'+vt+'"><input type="text" inputmode="decimal" name="p_mwst[]" value="'+<?= json_encode($ustStd) ?>+'" class="rfCalc" style="width:100%;box-sizing:border-box;text-align:right"></td>'
      + '<td style="'+vt+';text-align:right" class="rfZeile muted">–</td>';
  }
  function recalc(){
    var netto=0, ust=0;
    tbody.querySelectorAll('tr').forEach(function(tr){
      var m=num(tr.querySelector('[name="p_menge[]"]').value), p=num(tr.querySelector('[name="p_preis[]"]').value), u=num(tr.querySelector('[name="p_mwst[]"]').value);
      var zeile=m*p; netto+=zeile; ust+=zeile*u/100;
      var z=tr.querySelector('.rfZeile'); if(z){ z.textContent = zeile?eur(zeile):'–'; z.classList.toggle('muted',!zeile); }
    });
    document.getElementById('rfNetto').textContent=eur(netto);
    document.getElementById('rfUst').textContent=eur(ust);
    document.getElementById('rfBrutto').textContent=eur(netto+ust);
  }
  document.getElementById('rfAdd').addEventListener('click',function(){ var tr=document.createElement('tr'); tr.innerHTML=rowHTML(); tbody.appendChild(tr); recalc(); });
  tbody.addEventListener('click',function(e){ var b=e.target.closest('.rfDel'); if(!b)return; if(tbody.querySelectorAll('tr').length>1) b.closest('tr').remove(); else { b.closest('tr').querySelectorAll('input,textarea').forEach(function(x){ if(x.name==='p_menge[]')x.value='1'; else if(x.name==='p_einheit[]')x.value='Stk.'; else x.value=''; }); } recalc(); });
  tbody.addEventListener('input',function(e){ if(e.target.classList.contains('rfCalc')) recalc(); });
  recalc();
})();
</script>
<?php
render_footer();
