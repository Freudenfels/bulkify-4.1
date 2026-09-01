<?php
// Angebot bearbeiten (Hybrid): Kopf (Kunde/Produkt/Status/Marge/Produktionszeit/Notiz)
// + Positionen automatisch erzeugt & überschreibbar, mit interner Marge (nur intern).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? 'kopf_save';
    $f = fn($k) => trim($_POST[$k] ?? '');

    if ($aktion === 'kopf_save') {
        $kunde_id   = ($_POST['kunde_id'] ?? '') !== '' ? (int)$_POST['kunde_id'] : null;
        $produkt_id = ($_POST['produkt_id'] ?? '') !== '' ? (int)$_POST['produkt_id'] : null;
        if (!$produkt_id) { $fehler = 'Bitte ein Produkt wählen.'; }
        else {
            $status  = $f('status') ?: 'offen';
            $gueltig = $f('gueltig_bis') !== '' ? $f('gueltig_bis') : null;
            $marge   = $f('marge') !== '' ? (float)str_replace(',', '.', $f('marge')) : null;
            $pz      = $f('produktionszeit') !== '' ? (float)str_replace(',', '.', $f('produktionszeit')) : null;
            if ((int) scalar("SELECT COUNT(*) FROM produkt_preis WHERE produkt_id=?", [$produkt_id]) === 0) produkt_matrix_generieren($produkt_id);
            if ($neu) {
                q("INSERT INTO angebot (nummer,kunde_id,produkt_id,status,gueltig_bis,notiz,marge_override,produktionszeit_wochen) VALUES (?,?,?,?,?,?,?,?)",
                  [naechste_nummer('AN'), $kunde_id, $produkt_id, $status, $gueltig, $f('notiz'), $marge, $pz]);
                $id = insert_id();
                if ($kunde_id) log_aktivitaet('kunde', $kunde_id, 'team', 'Angebot erstellt.', 'angebot', 'angebot', (int)$id);
            } else {
                q("UPDATE angebot SET kunde_id=?,produkt_id=?,status=?,gueltig_bis=?,notiz=?,marge_override=?,produktionszeit_wochen=? WHERE id=?",
                  [$kunde_id, $produkt_id, $status, $gueltig, $f('notiz'), $marge, $pz, (int)$id]);
            }
            // Sobald das Angebot beim Kunden ist, gelten die angebotenen Konfigurationen als eigene Produkte
            // (Rezeptur x Menge + Verpackung) – und der Kunde darf deren Preise im Portal sehen.
            if (in_array($status, ['gesendet', 'bestaetigt'], true)) angebot_produkte_sichern((int)$id);
            header('Location: ?p=angebot&id=' . $id . '&gespeichert=1'); exit;
        }
    } elseif ($aktion === 'pos_save' && !$neu) {
        q("DELETE FROM angebot_position WHERE angebot_id=?", [(int)$id]);
        $bez = $_POST['p_bez'] ?? []; $art = $_POST['p_art'] ?? []; $mng = $_POST['p_menge'] ?? [];
        $einh = $_POST['p_einheit'] ?? []; $preis = $_POST['p_preis'] ?? []; $mwst = $_POST['p_mwst'] ?? [];
        $ek = $_POST['p_ek'] ?? []; $besch = $_POST['p_besch'] ?? []; $quelle = $_POST['p_quelle'] ?? []; $grp = $_POST['p_gruppe'] ?? [];
        $sort = 0;
        foreach ($bez as $i => $b) {
            $b = trim($b); if ($b === '') continue;
            $gv = strtoupper(trim($grp[$i] ?? '')); $gv = ($gv !== '' && ctype_alpha($gv)) ? substr($gv, 0, 2) : null;
            q("INSERT INTO angebot_position (angebot_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,ek_cent,mwst_satz,quelle,gruppe) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
              [(int)$id, $sort++, trim($art[$i] ?? ''), $b, trim($besch[$i] ?? ''),
               (float)str_replace(',', '.', $mng[$i] ?? '0'), trim($einh[$i] ?? ''),
               (int) round((float)str_replace(',', '.', $preis[$i] ?? '0') * 100),
               (int) round((float)str_replace(',', '.', $ek[$i] ?? '0') * 100),
               (float)str_replace(',', '.', $mwst[$i] ?? '0'), in_array($quelle[$i] ?? '', ['herstellung','verpackung','manuell'], true) ? $quelle[$i] : 'manuell', $gv]);
        }
        header('Location: ?p=angebot&id=' . $id . '&gespeichert=1'); exit;
    } elseif ($aktion === 'pos_reset' && !$neu) {
        q("DELETE FROM angebot_position WHERE angebot_id=?", [(int)$id]);
        header('Location: ?p=angebot&id=' . $id . '&zurueckgesetzt=1'); exit;
    } elseif (in_array($aktion, ['add_rezeptur','add_rohstoff','add_dienstleistung'], true) && !$neu) {
        $aRow = one("SELECT kunde_id, marge_override FROM angebot WHERE id=?", [(int)$id]);
        $kid = (int)($aRow['kunde_id'] ?? 0) ?: null;
        $mo = ($aRow['marge_override'] ?? '') !== '' && $aRow['marge_override'] !== null ? (float)$aRow['marge_override'] : null;
        if ($aktion === 'add_rezeptur') {
            $rid = (int)($_POST['add_rezeptur_id'] ?? 0);
            $stk = (int)($_POST['add_stueck'] ?? 0) ?: 1;
            $menge = (int)($_POST['add_menge'] ?? 0);
            $verps = array_values(array_filter([(int)($_POST['add_verp_id'] ?? 0), (int)($_POST['add_deckel_id'] ?? 0), (int)($_POST['add_etikett_id'] ?? 0)]));
            if ($rid) angebot_gruppe_anhaengen((int)$id, angebot_rezeptur_zeilen($rid, $stk, $verps, $menge, $mo, $kid));
        } elseif ($aktion === 'add_rohstoff') {
            $iid = (int)($_POST['add_rohstoff_id'] ?? 0);
            $mng = (float)str_replace(',', '.', $_POST['add_menge'] ?? '0');
            if ($iid) angebot_gruppe_anhaengen((int)$id, angebot_rohstoff_zeile($iid, $mng, trim($_POST['add_einheit'] ?? ''), $kid));
        } else { // add_dienstleistung
            $bez = trim($_POST['add_bez'] ?? '');
            if ($bez !== '') {
                $preis = (float)str_replace(',', '.', $_POST['add_preis'] ?? '0');
                $mng = (float)str_replace(',', '.', $_POST['add_menge'] ?? '1') ?: 1;
                $mwst = ($_POST['add_mwst'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['add_mwst']) : angebot_ust_satz($kid);
                angebot_gruppe_anhaengen((int)$id, [['artikelnr'=>'', 'bezeichnung'=>$bez, 'beschreibung'=>trim($_POST['add_besch'] ?? ''), 'menge'=>$mng, 'einheit'=>trim($_POST['add_einheit'] ?? ''), 'preis_cent'=>(int)round($preis*100), 'ek_cent'=>0, 'mwst_satz'=>$mwst, 'quelle'=>'manuell']]);
            }
        }
        header('Location: ?p=angebot&id=' . $id . '&gespeichert=1'); exit;
    }
}

$a = $neu ? ['status'=>'offen'] : one("SELECT * FROM angebot WHERE id=?", [(int)$id]);
if (!$a) { $neu = true; $a = ['status'=>'offen']; }
$v = fn($k) => h((string)($a[$k] ?? ''));

$kunden   = all("SELECT id, firma, portal_token FROM kunden ORDER BY firma");
$produkte = all("SELECT id, name FROM produkt ORDER BY name");
// Kataloge für „Position hinzufügen" (Typ zuerst)
$rezepturKatalog = all("SELECT id, name, darreichungsform FROM rezeptur WHERE status IN ('entwurf','vorschlag','eingefroren','freigegeben') ORDER BY name");
$rohstoffKatalog = all("SELECT id, name, artikelnummer, preis_bezug FROM item WHERE kategorie='rohstoff' AND gesperrt=0 ORDER BY name");
$verpPrim   = all("SELECT id, name FROM item WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND gesperrt=0 ORDER BY name");
$verpDeckel = all("SELECT id, name FROM item WHERE kategorie='verpackung' AND verpackung_rolle='verschluss' AND gesperrt=0 ORDER BY name");
$verpEtik   = all("SELECT id, name FROM item WHERE kategorie='verpackung' AND verpackung_rolle='etikett' AND gesperrt=0 ORDER BY name");

$pid  = (int)($a['produkt_id'] ?? 0);
$kid  = (int)($a['kunde_id'] ?? 0);
$form = $pid ? (string) scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$pid]) : 'kapsel';
$defMarge = max(marge_typ_prozent($form ?: 'kapsel'), marge_min_prozent());
$defPz    = (float) meta_get('produktionszeit_wochen', 7);

render_header('angebote', $neu ? 'Neues Angebot' : ($a['nummer'] ?? 'Angebot'));
bx_head($neu ? 'Neues Angebot' : $v('nummer'),
        $neu ? 'Produkt + Positionen' : 'Angebot bearbeiten',
        bx_btn('Zurück zur Liste', '?p=angebote', 'ghost'));
if (isset($_GET['gespeichert']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['zurueckgesetzt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Positionen auf die automatische Berechnung zurückgesetzt.</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($fehler) . '</div>';
?>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="kopf_save">
  <div class="bx-panel"><div class="bx-grid">
    <div class="bx-field"><label>Kunde</label>
      <select name="kunde_id"><option value="">– keiner –</option>
        <?php foreach ($kunden as $k): ?><option value="<?= $k['id'] ?>" <?= $kid===(int)$k['id']?'selected':'' ?>><?= h($k['firma']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Produkt</label>
      <select name="produkt_id"><option value="">– wählen –</option>
        <?php foreach ($produkte as $pr): ?><option value="<?= $pr['id'] ?>" <?= $pid===(int)$pr['id']?'selected':'' ?>><?= h($pr['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Status</label>
      <select name="status">
        <?php foreach (['offen'=>'offen','gesendet'=>'gesendet','bestaetigt'=>'bestätigt','abgelehnt'=>'abgelehnt'] as $key=>$lbl): ?>
          <option value="<?= $key ?>" <?= ($a['status']??'')===$key?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Gültig bis</label><input type="date" name="gueltig_bis" value="<?= $v('gueltig_bis') ?>"></div>
    <div class="bx-field"><label>Marge (%) <?= bx_hint('wirkt auf die automatischen Positionen. Leer = Marge je Form ('.rtrim(rtrim(number_format($defMarge,2,',','.'),'0'),',').' %).') ?></label><input type="number" step="0.1" name="marge" value="<?= ($a['marge_override'] ?? '') !== '' && $a['marge_override'] !== null ? h(rtrim(rtrim(number_format((float)$a['marge_override'],2,'.',''),'0'),'.')) : '' ?>" placeholder="<?= h(rtrim(rtrim(number_format($defMarge,2,',','.'),'0'),',')) ?> (Standard)"></div>
    <div class="bx-field"><label>Produktionszeit (Wochen)</label><input type="number" step="0.5" name="produktionszeit" value="<?= ($a['produktionszeit_wochen'] ?? '') !== '' && $a['produktionszeit_wochen'] !== null ? h(rtrim(rtrim(number_format((float)$a['produktionszeit_wochen'],1,'.',''),'0'),'.')) : '' ?>" placeholder="<?= h(rtrim(rtrim(number_format($defPz,1,',','.'),'0'),',')) ?> (Standard)"></div>
  </div>
  <div class="bx-field"><label>Notiz</label><textarea name="notiz"><?= $v('notiz') ?></textarea></div>
  <div class="bx-row"><button class="btn btn-primary" type="submit"><?= $neu ? 'Angebot anlegen' : 'Kopfdaten speichern' ?></button><a class="btn btn-ghost" href="?p=angebote">Abbrechen</a></div>
  </div>
</form>

<?php
if (!$neu && $pid):
    $pos = angebot_positionen((int)$id);
    $ueberschrieben = angebot_hat_positionen((int)$id);
    $eur = fn($c) => number_format($c/100, 2, ',', '.') . ' €';
    // interne Summen
    $sumVk = 0; $sumEk = 0;
    foreach ($pos as $pp) { $sumVk += $pp['menge'] * $pp['preis_cent']; $sumEk += $pp['menge'] * $pp['ek_cent']; }
    $marge = $sumVk - $sumEk; $margePct = $sumVk > 0 ? $marge / $sumVk * 100 : 0;
    $ktok = ''; foreach ($kunden as $k) if ((int)$k['id'] === $kid) { $ktok = $k['portal_token']; break; }
?>
<div class="bx-panel">
  <h2 style="margin-top:0">Position hinzufügen</h2>
  <p class="muted" style="margin-top:0">Erst den Typ wählen – dann kommt der passende Katalog. Jede Position wird als eigene Gruppe (A, B, C …) angehängt.</p>
  <div class="bx-field" style="max-width:280px"><label>Typ</label>
    <select id="addTyp">
      <option value="rezeptur">Rezeptur (Lohnherstellung)</option>
      <option value="rohstoff">Rohstoff</option>
      <option value="dienstleistung">Dienstleistung</option>
    </select>
  </div>

  <form method="post" data-add="rezeptur">
    <input type="hidden" name="aktion" value="add_rezeptur">
    <div class="bx-grid">
      <div class="bx-field"><label>Rezeptur</label>
        <select name="add_rezeptur_id" required><option value="">– wählen –</option>
          <?php foreach ($rezepturKatalog as $rz): ?><option value="<?= (int)$rz['id'] ?>"><?= h($rz['name']) ?><?= $rz['darreichungsform'] ? ' · '.h($rz['darreichungsform']) : '' ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Stückzahl / Füllmenge je Packung</label><input type="number" name="add_stueck" placeholder="z. B. 30" required></div>
      <div class="bx-field"><label>Anzahl Packungen</label><input type="number" name="add_menge" placeholder="z. B. 1000" required></div>
      <div class="bx-field"><label>Verpackung (Primär)</label><select name="add_verp_id"><option value="">– keine –</option><?php foreach ($verpPrim as $vp): ?><option value="<?= (int)$vp['id'] ?>"><?= h($vp['name']) ?></option><?php endforeach; ?></select></div>
      <div class="bx-field"><label>Deckel (optional)</label><select name="add_deckel_id"><option value="">– keiner –</option><?php foreach ($verpDeckel as $vp): ?><option value="<?= (int)$vp['id'] ?>"><?= h($vp['name']) ?></option><?php endforeach; ?></select></div>
      <div class="bx-field"><label>Etikett (optional)</label><select name="add_etikett_id"><option value="">– keins –</option><?php foreach ($verpEtik as $vp): ?><option value="<?= (int)$vp['id'] ?>"><?= h($vp['name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit">+ Rezeptur hinzufügen</button></div>
  </form>

  <form method="post" data-add="rohstoff" style="display:none">
    <input type="hidden" name="aktion" value="add_rohstoff">
    <div class="bx-grid">
      <div class="bx-field"><label>Rohstoff</label>
        <select name="add_rohstoff_id" required><option value="">– wählen –</option>
          <?php foreach ($rohstoffKatalog as $rs): ?><option value="<?= (int)$rs['id'] ?>"><?= h($rs['name']) ?><?= $rs['artikelnummer'] ? ' · '.h($rs['artikelnummer']) : '' ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Menge</label><input type="number" step="0.001" name="add_menge" placeholder="z. B. 25" required></div>
      <div class="bx-field"><label>Einheit</label><select name="add_einheit"><?php foreach (['kg','g','Stück','L'] as $e): ?><option value="<?= $e ?>"><?= $e ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit">+ Rohstoff hinzufügen</button></div>
  </form>

  <form method="post" data-add="dienstleistung" style="display:none">
    <input type="hidden" name="aktion" value="add_dienstleistung">
    <div class="bx-grid">
      <div class="bx-field"><label>Bezeichnung</label><input type="text" name="add_bez" placeholder="z. B. Laboranalyse" required></div>
      <div class="bx-field"><label>Menge</label><input type="number" step="0.01" name="add_menge" value="1"></div>
      <div class="bx-field"><label>Einheit</label><input type="text" name="add_einheit" placeholder="Stück / Pauschal"></div>
      <div class="bx-field"><label>Preis je Einheit (€)</label><input type="number" step="0.01" name="add_preis" placeholder="0,00"></div>
    </div>
    <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit">+ Dienstleistung hinzufügen</button></div>
  </form>
</div>

<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center">
    <h2 style="margin:0">Positionen <span class="muted" style="font-weight:400;font-size:13px"><?= $ueberschrieben ? 'manuell überschrieben' : 'automatisch berechnet' ?></span></h2>
    <div class="bx-row" style="gap:8px">
      <?php if ($ktok): ?><a class="btn btn-ghost btn-sm" target="_blank" href="?p=portal&token=<?= h($ktok) ?>&v=angebot_pdf&aid=<?= (int)$id ?>">PDF ansehen</a><?php endif; ?>
      <?php if ($ueberschrieben): ?>
        <form method="post" style="margin:0" onsubmit="return confirm('Positionen auf die automatische Berechnung zurücksetzen? Manuelle Änderungen gehen verloren.');"><input type="hidden" name="aktion" value="pos_reset"><button class="btn btn-ghost btn-sm" type="submit">Automatik wiederherstellen</button></form>
      <?php endif; ?>
    </div>
  </div>
  <p class="muted" style="margin-top:4px;font-size:13px">Automatisch erzeugt aus Produkt + Preismatrix + Verpackung (Dose/Deckel/Etikett kommen extra). Du kannst Menge, Preis, MwSt anpassen oder Positionen hinzufügen/entfernen. <strong>Speichern friert die Positionen ein</strong> (überschreibt die Automatik).</p>

  <style>
    #postab{table-layout:fixed;width:100%}
    #postab th,#postab td{padding:6px 8px;vertical-align:middle;overflow:hidden}
    #postab input,#postab textarea{box-sizing:border-box;width:100%;display:block;min-width:0}
    #postab .p_besch{margin-top:4px;font-size:14px;color:var(--muted);resize:vertical;line-height:1.35}
    #postab .bx-num{white-space:nowrap;text-align:right}
  </style>
  <form method="post">
    <input type="hidden" name="aktion" value="pos_save">
    <table class="bx-table" id="postab">
      <colgroup>
        <col style="width:400px"><col style="width:92px"><col style="width:72px"><col style="width:92px"><col style="width:78px">
        <col style="width:84px"><col style="width:92px"><col style="width:96px"><col style="width:40px"><col>
      </colgroup>
      <thead><tr>
        <th>Bezeichnung</th><th class="bx-num">Menge</th><th>Einheit</th>
        <th class="bx-num">Preis/Einh €</th><th class="bx-num">MwSt %</th>
        <th class="bx-num">EK/Einh</th><th class="bx-num">Marge</th><th class="bx-num">Gesamt</th><th></th><th></th>
      </tr></thead>
      <tbody id="posrows">
        <?php foreach ($pos as $i => $pp): ?>
        <tr class="posrow">
          <td>
            <input type="text" name="p_bez[]" value="<?= h($pp['bezeichnung']) ?>">
            <textarea name="p_besch[]" class="p_besch" rows="<?= max(2, substr_count((string)($pp['beschreibung'] ?? ''), "\n") + 1) ?>" placeholder="Beschreibung / Rezeptur (optional)"><?= h($pp['beschreibung'] ?? '') ?></textarea>
            <input type="hidden" name="p_art[]" value="<?= h($pp['artikelnr'] ?? '') ?>">
            <input type="hidden" name="p_quelle[]" value="<?= h($pp['quelle'] ?? 'manuell') ?>">
            <input type="hidden" name="p_gruppe[]" value="<?= h($pp['gruppe'] ?? '') ?>">
            <input type="hidden" name="p_ek[]" class="p_ek" value="<?= h(number_format($pp['ek_cent']/100,4,'.','')) ?>">
          </td>
          <td><input type="number" step="0.001" name="p_menge[]" class="p_menge" value="<?= h(rtrim(rtrim(number_format($pp['menge'],3,'.',''),'0'),'.')) ?>" style="width:100%"></td>
          <td><input type="text" name="p_einheit[]" value="<?= h($pp['einheit'] ?? '') ?>" style="width:100%"></td>
          <td><input type="number" step="0.0001" name="p_preis[]" class="p_preis" value="<?= h(rtrim(rtrim(number_format($pp['preis_cent']/100,4,'.',''),'0'),'.')) ?>" style="width:100%"></td>
          <td><input type="number" step="0.1" name="p_mwst[]" value="<?= h(rtrim(rtrim(number_format($pp['mwst_satz'],2,'.',''),'0'),'.')) ?>" style="width:100%"></td>
          <td class="bx-num c_ek">–</td><td class="bx-num c_marge">–</td><td class="bx-num c_ges">–</td>
          <td><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.posrow').remove();posRecalc()">×</button></td>
          <td></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="bx-row" style="justify-content:space-between;align-items:flex-start;margin-top:8px">
      <button type="button" class="btn btn-ghost btn-sm" id="addPos">+ freie Position</button>
      <span class="muted" style="font-size:12px;margin-left:8px">für Zuschläge/Dienstleistungen – Produkte bitte oben aus dem Katalog hinzufügen</span>
      <div style="text-align:right;font-size:14px">
        <div>Netto: <strong id="sumNetto"><?= $eur($sumVk) ?></strong></div>
        <div class="muted" style="font-size:12px" id="intMarge">Intern: EK <?= $eur($sumEk) ?> · Marge <?= $eur($marge) ?> (<?= number_format($margePct,0,',','.') ?> %)</div>
      </div>
    </div>
    <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit">Positionen speichern</button></div>
  </form>
</div>

<script>
(function(){
  var sel = document.getElementById('addTyp'); if (!sel) return;
  function upd(){
    document.querySelectorAll('[data-add]').forEach(function(f){ f.style.display = (f.getAttribute('data-add') === sel.value) ? '' : 'none'; });
  }
  sel.addEventListener('change', upd); upd();
})();
function nf(x,d){ return x.toLocaleString('de-DE',{minimumFractionDigits:d,maximumFractionDigits:d}); }
function posRecalc(){
  var netto=0, ekges=0;
  document.querySelectorAll('.posrow').forEach(function(r){
    var m=parseFloat((r.querySelector('.p_menge').value||'').replace(',','.'))||0;
    var p=parseFloat((r.querySelector('.p_preis').value||'').replace(',','.'))||0;
    var ek=parseFloat((r.querySelector('.p_ek').value||'').replace(',','.'))||0;
    var g=m*p, mg=(p-ek)*m;
    r.querySelector('.c_ek').textContent = ek?nf(ek,2)+' €':'–';
    r.querySelector('.c_marge').textContent = (p&&ek)?nf(mg,2)+' €':'–';
    r.querySelector('.c_ges').textContent = g?nf(g,2)+' €':'–';
    netto+=g; ekges+=m*ek;
  });
  document.getElementById('sumNetto').textContent=nf(netto,2)+' €';
  var marge=netto-ekges, pct=netto>0?marge/netto*100:0;
  document.getElementById('intMarge').textContent='Intern: EK '+nf(ekges,2)+' € · Marge '+nf(marge,2)+' € ('+nf(pct,0)+' %)';
}
(function(){
  document.getElementById('addPos').addEventListener('click',function(){
    var tr=document.createElement('tr'); tr.className='posrow';
    tr.innerHTML='<td><input type="text" name="p_bez[]">'
      +'<textarea name="p_besch[]" class="p_besch" rows="2" placeholder="Beschreibung / Rezeptur (optional)"></textarea>'
      +'<input type="hidden" name="p_art[]" value=""><input type="hidden" name="p_quelle[]" value="manuell"><input type="hidden" name="p_gruppe[]" value=""><input type="hidden" name="p_ek[]" class="p_ek" value="0"></td>'
      +'<td><input type="number" step="0.001" name="p_menge[]" class="p_menge"></td>'
      +'<td><input type="text" name="p_einheit[]" value="Stück"></td>'
      +'<td><input type="number" step="0.0001" name="p_preis[]" class="p_preis"></td>'
      +'<td><input type="number" step="0.1" name="p_mwst[]" value="0"></td>'
      +'<td class="bx-num c_ek">–</td><td class="bx-num c_marge">–</td><td class="bx-num c_ges">–</td>'
      +'<td><button type="button" class="btn btn-ghost btn-sm">×</button></td><td></td>';
    tr.querySelector('button').addEventListener('click',function(){tr.remove();posRecalc();});
    tr.querySelectorAll('input').forEach(function(i){i.addEventListener('input',posRecalc);});
    document.getElementById('posrows').appendChild(tr);
  });
  document.querySelectorAll('#postab input').forEach(function(i){i.addEventListener('input',posRecalc);});
  posRecalc();
})();
</script>
<?php endif;
render_footer();
