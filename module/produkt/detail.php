<?php
// Produkt anlegen & bearbeiten – Rezeptur + Verpackung + Kunde, mit Live-Kalkulation
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dokument_ui.php';

$DFORM = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig'];
$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

$fehler = '';
// Preismatrix neu berechnen (eigene Aktion)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'matrix' && is_numeric($id)) {
    $n = produkt_matrix_generieren((int)$id);
    header('Location: ?p=produkt&id=' . $id . '&matrix=' . $n); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'dok_upload' && is_numeric($id)) {
    dokument_upload('produkt', (int)$id);
    header('Location: ?p=produkt&id=' . $id . '&gespeichert=1#dok'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'dok_del' && is_numeric($id)) {
    dokument_delete('produkt', (int)$id, (int)($_POST['dok_id'] ?? 0));
    header('Location: ?p=produkt&id=' . $id . '#dok'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === '') {
    $f = fn($k) => trim($_POST[$k] ?? '');
    if ($f('name') === '') {
        $fehler = 'Name ist ein Pflichtfeld.';
    } else {
        $kunde_id = ($_POST['kunde_id'] ?? '') !== '' ? (int)$_POST['kunde_id'] : null;
        $rez_id   = ($_POST['rezeptur_id'] ?? '') !== '' ? (int)$_POST['rezeptur_id'] : null;
        $iid = fn($k) => ($_POST[$k] ?? '') !== '' ? (int)$_POST[$k] : null;
        $verp_id  = $iid('verpackung_id');
        $exkl = isset($_POST['exklusiv']) ? 1 : 0;
        // Der Kunde ist nur bei einem exklusiven Produkt der Besitzer. Ein Katalogprodukt gehört niemandem –
        // sonst steht in der Produktliste ein Kundenname bei einem Produkt, das jeder Kunde bestellen kann.
        if (!$exkl) $kunde_id = null;
        $einh = $f('einheiten_pro_packung') === '' ? 0 : (int)$f('einheiten_pro_packung');
        $tag  = $f('einnahme_pro_tag') === '' ? 1 : $f('einnahme_pro_tag');
        $kdname = $f('kundenname') ?: null;   // Name für den Kunden (leer = interner Name)
        $name = produkt_name_versioniert($f('name'), $neu ? 0 : (int)$id);   // interner Name eindeutig (v2, v3 …)
        if ($neu) {
            q("INSERT INTO produkt (nummer,name,kundenname,kunde_id,rezeptur_id,verpackung_id,verschluss_id,etikett_id,karton_id,beipack_id,leerkapsel_id,exklusiv,einheiten_pro_packung,einnahme_pro_tag,status,notiz)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
              [naechste_nummer('P'), $name, $kdname, $kunde_id, $rez_id, $verp_id, $iid('verschluss_id'), $iid('etikett_id'), $iid('karton_id'), $iid('beipack_id'), $iid('leerkapsel_id'), $exkl, $einh, $tag, $f('status') ?: 'entwurf', $f('notiz')]);
            $id = insert_id();
            log_aktivitaet('kunde', (int)($kunde_id ?: 0), 'team', 'Produkt „' . $name . '" angelegt.', 'produkt', (int)$id);
        } else {
            q("UPDATE produkt SET name=?,kundenname=?,kunde_id=?,rezeptur_id=?,verpackung_id=?,verschluss_id=?,etikett_id=?,karton_id=?,beipack_id=?,leerkapsel_id=?,exklusiv=?,einheiten_pro_packung=?,einnahme_pro_tag=?,status=?,notiz=? WHERE id=?",
              [$name, $kdname, $kunde_id, $rez_id, $verp_id, $iid('verschluss_id'), $iid('etikett_id'), $iid('karton_id'), $iid('beipack_id'), $iid('leerkapsel_id'), $exkl, $einh, $tag, $f('status'), $f('notiz'), (int)$id]);
        }
        header('Location: ?p=produkt&id=' . $id . '&gespeichert=1'); exit;
    }
}

$p = $neu ? ['status'=>'entwurf','einnahme_pro_tag'=>2,'einheiten_pro_packung'=>120]
          : one("SELECT * FROM produkt WHERE id=?", [(int)$id]);
if (!$p) { $neu = true; $p = ['status'=>'entwurf','einnahme_pro_tag'=>2,'einheiten_pro_packung'=>120]; }
$v = fn($k) => h((string)($p[$k] ?? ''));

$kunden = all("SELECT id, firma FROM kunden ORDER BY firma");
$lieferanten = all("SELECT id, firma FROM lieferanten ORDER BY firma");
$rezepte = all("SELECT id, name, darreichungsform, kapselgroesse_id FROM rezeptur ORDER BY name");
$verpackungen = all("SELECT id, name, ek_preis, max_fuellgewicht_g, volumen_ml, COALESCE(verpackung_rolle,'primaer') AS rolle FROM item WHERE kategorie='verpackung' AND gesperrt=0 ORDER BY name");
// Verpackungen nach Rolle gruppieren (für die Stückliste-Auswahlen)
$VERP_ROLLE = ['primaer'=>[],'verschluss'=>[],'etikett'=>[],'karton'=>[],'beipack'=>[]];
foreach ($verpackungen as $vp) { $r = $vp['rolle'] ?: 'primaer'; if (!isset($VERP_ROLLE[$r])) $r='primaer'; $VERP_ROLLE[$r][] = $vp; }
seed_kapselgroesse_if_empty();
$KAPSELN = all("SELECT id, name, fuellmenge_mg FROM kapselgroesse ORDER BY fuellmenge_mg ASC");
$leerkapseln = all("SELECT id, name, kapselgroesse_id FROM item WHERE kategorie='rohstoff' AND form='kapselhuelle' AND gesperrt=0 ORDER BY name");
// Auto-Bestimmung nur bei bestehendem Kapselprodukt (für den Hinweis unter der Auswahl)
$kapKandidaten = $neu ? [] : produkt_leerkapsel_kandidaten((int)$id);
$kapEffektiv   = $neu ? null : produkt_leerkapsel_id((int)$id);
// Darreichungsform des Produkts – bestimmt, ob die Matrixgröße Stück, Gramm oder Milliliter meint
$pForm = $neu ? 'kapsel' : ((string) scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [(int)$id]) ?: 'kapsel');
// Preis-Matrix laden + nach (Stück, Verpackung) gruppieren, Bestellmengen als Spalten
$matrix = $neu ? [] : all("SELECT pp.*, i.name AS verp FROM produkt_preis pp JOIN item i ON i.id=pp.verpackung_id WHERE pp.produkt_id=? ORDER BY pp.stueck, i.name, pp.bestellmenge", [(int)$id]);
$matrixMengen = array_values(array_unique(array_map(fn($r) => (int)$r['bestellmenge'], $matrix)));
sort($matrixMengen);
$matrixGrid = [];
foreach ($matrix as $r) {
    $key = $r['stueck'] . '|' . $r['verp'];
    $matrixGrid[$key]['stueck'] = (int)$r['stueck'];
    $matrixGrid[$key]['verp'] = $r['verp'];
    $matrixGrid[$key]['cells'][(int)$r['bestellmenge']] = ['ek'=>(float)$r['ek_preis'], 'vk'=>(float)$r['vk_preis']];
}

// Rezepturen vorberechnen: Nährstoffe (mg/Einheit), Kosten/Einheit, Gewicht/Einheit
$REZEPTE = [];
foreach ($rezepte as $rz) {
    $nutr = []; $cost = 0.0; $weight = 0.0;
    foreach (all("SELECT item_id, menge_mg FROM rezeptur_zutat WHERE rezeptur_id=?", [$rz['id']]) as $z) {
        $mg = (float)$z['menge_mg']; $weight += $mg;
        $it = $z['item_id'] ? one("SELECT ek_preis,preis_bezug,dichte FROM item WHERE id=?", [$z['item_id']]) : null;
        if ($it) {
            $pb = $it['preis_bezug']; $ek = (float)$it['ek_preis'];
            $perMg = $pb === 'kg' ? $ek/1e6 : ($pb === 'g' ? $ek/1e3 : ($pb === 'L' && $it['dichte'] ? ($ek/(1000*(float)$it['dichte']))/1e3 : 0));
            $cost += $mg * $perMg;
        }
        if ($z['item_id']) foreach (all("SELECT n.name,n.nrv_wert,n.einheit,iw.gehalt_prozent
                 FROM item_wirkstoff iw JOIN naehrstoff n ON n.id=iw.naehrstoff_id WHERE iw.item_id=?", [$z['item_id']]) as $w) {
            if ($w['gehalt_prozent'] === null) continue;
            $mgN = $mg * (float)$w['gehalt_prozent'] / 100;
            if (!isset($nutr[$w['name']])) $nutr[$w['name']] = ['name'=>$w['name'],'mg'=>0.0,'nrv'=>$w['nrv_wert'],'einheit'=>$w['einheit']];
            $nutr[$w['name']]['mg'] += $mgN;
        }
    }
    $REZEPTE[$rz['id']] = ['name'=>$rz['name'],'form'=>$rz['darreichungsform'],'kapselgroesse_id'=>(int)($rz['kapselgroesse_id']??0),'nutrients'=>array_values($nutr),'cost'=>$cost,'weight'=>$weight];
}
$VERP = [];
foreach ($verpackungen as $vp) $VERP[$vp['id']] = [
    'name'=>$vp['name'], 'ek'=>(float)$vp['ek_preis'], 'rolle'=>$vp['rolle'],
    'max_g'=>$vp['max_fuellgewicht_g']!==null?(float)$vp['max_fuellgewicht_g']:null,
    'vol'=>$vp['volumen_ml']!==null?(float)$vp['volumen_ml']:null,
    'kap'=>pack_kapazitaet_fuer((int)$vp['id']),   // [kapselgroesse_id => stück]
];

// Eine Auswahl für einen Stücklisten-Slot (Verschluss/Etikett/Karton/Beipack …)
function verp_slot(string $label, string $name, array $rows, $current, string $hint = ''): string {
    $opts = '<option value="">– keine –</option>';
    foreach ($rows as $vp) {
        $sel = (int)$current === (int)$vp['id'] ? 'selected' : '';
        $opts .= '<option value="' . (int)$vp['id'] . '" ' . $sel . '>' . h($vp['name']) . '</option>';
    }
    $h = $hint ? ' ' . bx_hint($hint) : '';
    return '<div class="bx-field"><label>' . h($label) . $h . '</label>'
         . '<select name="' . $name . '" id="' . $name . '">' . $opts . '</select></div>';
}

render_header('produkte', $neu ? 'Neues Produkt' : $p['name']);
bx_head($neu ? 'Neues Produkt' : $v('name'),
        $neu ? 'Rezeptur + Verpackung + Kunde' : trim($v('nummer')),
        bx_btn('Zurück zur Liste', '?p=produkte', 'ghost'));
if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($fehler) . '</div>';
?>
<form method="post" class="bx-form">
  <div class="bx-panel"><div class="bx-grid">
    <div class="bx-field"><label>Produktname (intern) <?= bx_hint('unser Arbeitsname, z. B. „Zink". Gleiche Namen werden automatisch mit v2, v3 … fortlaufend nummeriert.') ?></label><input type="text" name="name" value="<?= $v('name') ?>" required placeholder="z. B. Zink"></div>
    <div class="bx-field"><label>Name für den Kunden <?= bx_hint('so heißt es beim Kunden im Portal / auf Belegen, z. B. „Super Zink". Leer = interner Name.') ?></label><input type="text" name="kundenname" value="<?= $v('kundenname') ?>" placeholder="z. B. Super Zink"></div>
    <div class="bx-field"><label>Kunde <?= bx_hint('nur bei exklusiven Produkten der Besitzer. Ohne Häkchen „exklusiv" bleibt das Produkt ein Katalogprodukt und der Kunde wird beim Speichern entfernt.') ?></label>
      <select name="kunde_id">
        <option value="">– Katalogprodukt –</option>
        <?php foreach ($kunden as $k): ?><option value="<?= $k['id'] ?>" <?= (int)($p['kunde_id']??0)===(int)$k['id']?'selected':'' ?>><?= h($k['firma']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Status</label>
      <select name="status">
        <?php foreach (['entwurf'=>'Entwurf','aktiv'=>'aktiv','inaktiv'=>'inaktiv'] as $key=>$lbl): ?>
          <option value="<?= $key ?>" <?= ($p['status']??'')===$key?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Katalog / Exklusiv <?= bx_hint('Standard: gemeinsamer Katalog (für alle Kunden mit Produkt-Freischaltung). Exklusiv = nur für den oben gewählten Kunden sichtbar.') ?></label>
      <div class="bx-check" style="padding-top:8px">
        <input type="checkbox" name="exklusiv" id="f_exkl" value="1" <?= (int)($p['exklusiv']??0)===1?'checked':'' ?>>
        <label for="f_exkl" style="margin:0">exklusiv (nur für den Kunden)</label>
      </div>
    </div>
  </div></div>

  <div class="bx-panel"><div class="bx-grid">
    <div class="bx-field"><label>Rezeptur</label>
      <select name="rezeptur_id" id="rezeptur">
        <option value="">– wählen –</option>
        <?php foreach ($rezepte as $rz): ?><option value="<?= $rz['id'] ?>" <?= (int)($p['rezeptur_id']??0)===(int)$rz['id']?'selected':'' ?>><?= h($rz['name']) ?> · <?= h($DFORM[$rz['darreichungsform']] ?? $rz['darreichungsform']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?= verp_slot('Verpackung (Primär)', 'verpackung_id', $VERP_ROLLE['primaer'], $p['verpackung_id'] ?? '', 'Dose/Glas/Beutel, der das Produkt direkt hält') ?>
    <div class="bx-field"><label>Einheiten je Packung <?= bx_hint('z. B. 120 Kapseln je Dose') ?></label><input type="number" name="einheiten_pro_packung" id="einheiten" value="<?= $v('einheiten_pro_packung') ?>"></div>
    <div class="bx-field"><label>Verzehr je Tag <?= bx_hint('Empfehlung – Basis für % NRV pro Tag und Reichweite') ?></label><input type="number" step="0.5" name="einnahme_pro_tag" id="intake" value="<?= $v('einnahme_pro_tag') ?>"></div>
  </div>
  <div class="bx-field"><label>Notiz</label><textarea name="notiz"><?= $v('notiz') ?></textarea></div>
  </div>

  <div class="bx-panel">
    <div style="font-weight:600;margin-bottom:8px">Stückliste – weitere Verpackung</div>
    <div class="bx-grid">
      <?= verp_slot('Verschluss/Deckel', 'verschluss_id', $VERP_ROLLE['verschluss'], $p['verschluss_id'] ?? '') ?>
      <?= verp_slot('Etikett', 'etikett_id', $VERP_ROLLE['etikett'], $p['etikett_id'] ?? '') ?>
      <?= verp_slot('Faltschachtel/Karton', 'karton_id', $VERP_ROLLE['karton'], $p['karton_id'] ?? '') ?>
      <?= verp_slot('Beipackzettel', 'beipack_id', $VERP_ROLLE['beipack'], $p['beipack_id'] ?? '') ?>
      <div class="bx-field"><label>Leerkapsel <?= bx_hint('nur Kapselprodukte. Leer = automatisch nach Kapselgröße; nur wählen, wenn mehrere Kapseln gleicher Größe existieren (Material/Farbe).') ?></label>
        <select name="leerkapsel_id">
          <option value="">– automatisch nach Größe –</option>
          <?php foreach ($leerkapseln as $lk): ?><option value="<?= (int)$lk['id'] ?>" <?= (int)($p['leerkapsel_id']??0)===(int)$lk['id']?'selected':'' ?>><?= h($lk['name']) ?></option><?php endforeach; ?>
        </select>
        <?php if (!$neu): ?>
          <div class="muted" style="margin-top:4px;font-size:13px">
          <?php if ((int)($p['leerkapsel_id']??0)): ?>manuell gewählt
          <?php elseif ($kapEffektiv): ?>automatisch: <?= h((string)scalar("SELECT name FROM item WHERE id=?", [$kapEffektiv])) ?>
          <?php elseif (count($kapKandidaten) > 1): ?><span style="color:var(--warn)">mehrere Kapseln dieser Größe – bitte wählen</span>
          <?php elseif (count($kapKandidaten) === 0): ?><span class="muted">keine passende Leerkapsel dieser Größe im Lager</span>
          <?php else: ?>–<?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="bx-panel" id="ergebnis">
    <h2>Kalkulation &amp; Tages-Deklaration</h2>
    <div class="bx-cards" style="margin-bottom:16px">
      <div class="bx-card"><div class="k">Kosten / Packung</div><div class="v" id="k_kosten">–</div></div>
      <div class="bx-card"><div class="k">Reichweite</div><div class="v" id="k_reichweite">–</div></div>
      <div class="bx-card"><div class="k">Gewicht / Einheit</div><div class="v" id="k_gewicht">–</div></div>
      <div class="bx-card"><div class="k">Verpackung</div><div class="v" id="k_verp" style="font-size:15px">–</div></div>
    </div>
    <div class="muted" id="verp_hinweis" style="margin-bottom:16px"></div>
    <div style="font-weight:600;margin-bottom:8px">Nährwerte pro Tagesdosis <span class="muted" style="font-weight:400" id="intakeLabel"></span></div>
    <table class="bx-table"><thead><tr><th>Nährstoff</th><th class="bx-num">je Tag</th><th class="bx-num">% NRV</th></tr></thead>
      <tbody id="deklaration"><tr><td colspan="3" class="muted">Rezeptur wählen …</td></tr></tbody>
    </table>
  </div>

  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit"><?= $neu ? 'Produkt anlegen' : 'Speichern' ?></button>
    <a class="btn btn-ghost" href="?p=produkte">Abbrechen</a>
  </div>
</form>

<?php if (!$neu): ?>
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center">
    <h2 style="margin:0">Preis-Matrix <?= bx_hint('automatische VK-Kalkulation: Packungsgröße (Stück, Gramm bei Pulver, Milliliter bei Flüssig) × passende Verpackung × Bestellmenge. VK = EK (Rezeptur + Kapsel/Presshilfsstoffe/Trägerflüssigkeit) × Marge je Typ, ohne Kundenrabatt. Interne Sale-Auskunft.') ?></h2>
    <form method="post" style="margin:0"><input type="hidden" name="aktion" value="matrix"><button class="btn btn-primary btn-sm" type="submit">Matrix neu berechnen</button></form>
  </div>
  <?php if (isset($_GET['matrix'])): ?><div class="bx-panel badge-ok" style="padding:8px 12px;margin-top:10px"><?= (int)$_GET['matrix'] ?> Preiszeilen berechnet.</div><?php endif; ?>
  <?php if (!$matrix): ?>
    <p class="muted">Noch keine Matrix. „Matrix neu berechnen" erzeugt alle Kombinationen (Produkt mit Rezeptur + hinterlegter Behälter-Fassung: Kapseln je Größe, Füllgewicht in g bzw. Fassungsvermögen in ml).</p>
  <?php else: ?>
    <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
      <thead><tr><th>Größe</th><th>Verpackung</th><?php foreach ($matrixMengen as $mn): ?><th class="bx-num"><?= number_format($mn,0,',','.') ?> Stk</th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($matrixGrid as $row): ?>
        <tr>
          <td><?= h(form_groessen_label($pForm, (float)$row['stueck'])) ?></td>
          <td><?= h($row['verp']) ?></td>
          <?php foreach ($matrixMengen as $mn): $c = $row['cells'][$mn] ?? null; ?>
            <td class="bx-num"><?php if ($c): ?><strong><?= number_format($c['vk'],2,',','.') ?> €</strong><div class="muted" style="font-size:11px">EK <?= number_format($c['ek'],2,',','.') ?></div><?php else: ?><span class="muted">–</span><?php endif; ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="muted" style="margin-top:8px">VK je Packung (Basis, ohne Kundenrabatt). Stand: <?= h(fmt_zeit($matrix[0]['stand'])) ?>.</div>
  <?php endif; ?>
</div>
<div id="dok"><?php dokument_panel('produkt', (int)$id, $lieferanten); ?></div>
<?php endif; ?>

<script>
var REZEPTE = <?= json_encode($REZEPTE, JSON_UNESCAPED_UNICODE) ?>;
var VERP = <?= json_encode($VERP, JSON_UNESCAPED_UNICODE) ?>;
var KAPSELN = <?= json_encode($KAPSELN, JSON_UNESCAPED_UNICODE) ?>;
var TAB_HILFSSTOFF = <?= json_encode(tablette_hilfsstoff_prozent()) ?>;   // Presshilfsstoffe % oben auf das Wirkstoffgewicht (Tablette)
var SLOTS = ['verpackung_id','verschluss_id','etikett_id','karton_id','beipack_id'];
function nf(x,d){ return x.toLocaleString('de-DE',{minimumFractionDigits:d,maximumFractionDigits:d}); }
function getVerp(id){ var el=document.getElementById(id); return (el&&el.value)?VERP[el.value]:null; }

// Verpackungs-Prüfung der Primärverpackung – je Darreichungsform über die richtige Kennzahl.
function verpCheck(rz, vp, einh, kV, vh){
  function set(t,c,hint){ kV.textContent=t; kV.style.color=c||''; vh.innerHTML=hint||''; }
  if (!einh){ set('–','',''); return; }
  var form = rz.form;
  if (form==='kapsel' || form==='softgel'){
    if (!rz.weight){ set('–','',''); return; }
    var kg=null;
    // Am Rezept gewählte Kapselgröße hat Vorrang, sonst kleinste passende nach Füllgewicht.
    if (rz.kapselgroesse_id){ for (var j=0;j<KAPSELN.length;j++){ if ((+KAPSELN[j].id)===(+rz.kapselgroesse_id)){ kg=KAPSELN[j]; break; } } }
    if (!kg){ for (var i=0;i<KAPSELN.length;i++){ if ((+KAPSELN[i].fuellmenge_mg)>=rz.weight){ kg=KAPSELN[i]; break; } } }
    if (!kg){ set('aufteilen','var(--err)','Rezeptur '+nf(rz.weight,0)+' mg passt in keine Kapsel – auf mehrere Kapseln aufteilen.'); return; }
    if (!vp){ set('– keine –','','Kapselgröße '+kg.name+' · '+einh+' Kapseln. Bitte Primärverpackung wählen.'); return; }
    var cap = vp.kap ? vp.kap[kg.id] : null;
    if (cap===undefined||cap===null||cap===0){ set('keine Fassung','var(--warn)','Für '+kg.name+' ist an dieser Verpackung keine Kapselzahl hinterlegt (Verpackung → Reiter „Kapsel-Fassung").'); return; }
    if (cap>=einh){ set('passt','var(--gruen)',einh+' von max. '+cap+' Kapseln ('+kg.name+').'); return; }
    var pa=[]; for (var k in VERP){ var a=VERP[k]; if (a.rolle==='primaer'&&a.kap&&a.kap[kg.id]>=einh) pa.push(a.name+' ('+a.kap[kg.id]+')'); }
    set('zu klein','var(--err)','Fasst nur '+cap+' Kapseln, gebraucht '+einh+' ('+kg.name+'). '+(pa.length?'Passend: '+pa.join(', '):'Keine passende Verpackung hinterlegt.'));
    return;
  }
  if (form==='pulver' || form==='granulat' || form==='stick'){
    if (!rz.weight){ set('–','',''); return; }
    var g = rz.weight*einh/1000;
    if (!vp){ set('– keine –','','Füllgewicht je Packung: '+nf(g,1)+' g. Bitte Verpackung wählen.'); return; }
    if (vp.max_g===null||vp.max_g===undefined){ set('kein Füllgewicht','var(--warn)','Verpackung hat kein max. Füllgewicht hinterlegt (benötigt: '+nf(g,1)+' g).'); return; }
    if (g<=vp.max_g){ set('passt','var(--gruen)',nf(g,1)+' g von max. '+nf(vp.max_g,0)+' g.'); return; }
    var pb=[]; for (var kk in VERP){ var b=VERP[kk]; if (b.rolle==='primaer'&&b.max_g!==null&&b.max_g!==undefined&&b.max_g>=g) pb.push(b.name+' ('+nf(b.max_g,0)+' g)'); }
    set('zu klein','var(--err)','Füllgewicht '+nf(g,1)+' g übersteigt max. '+nf(vp.max_g,0)+' g. '+(pb.length?'Passend: '+pb.join(', '):'Keine passende Verpackung hinterlegt.'));
    return;
  }
  if (form==='tablette'){
    // Tablettengewicht = Wirkstoffe + Presshilfsstoffe; geprüft wird wie bei Pulver gegen das max. Füllgewicht.
    if (!rz.weight){ set('–','',''); return; }
    var tg = rz.weight*(1+TAB_HILFSSTOFF/100)*einh/1000;
    if (!vp){ set('– keine –','','Füllgewicht je Packung: '+nf(tg,1)+' g ('+einh+' Tabletten). Bitte Verpackung wählen.'); return; }
    if (vp.max_g===null||vp.max_g===undefined){ set('kein Füllgewicht','var(--warn)','Verpackung hat kein max. Füllgewicht hinterlegt (benötigt: '+nf(tg,1)+' g).'); return; }
    if (tg<=vp.max_g){ set('passt','var(--gruen)',nf(tg,1)+' g von max. '+nf(vp.max_g,0)+' g ('+einh+' Tabletten inkl. Presshilfsstoffe).'); return; }
    var pt=[]; for (var mm in VERP){ var t=VERP[mm]; if (t.rolle==='primaer'&&t.max_g!==null&&t.max_g!==undefined&&t.max_g>=tg) pt.push(t.name+' ('+nf(t.max_g,0)+' g)'); }
    set('zu klein','var(--err)','Füllgewicht '+nf(tg,1)+' g übersteigt max. '+nf(vp.max_g,0)+' g. '+(pt.length?'Passend: '+pt.join(', '):'Keine passende Verpackung hinterlegt.'));
    return;
  }
  if (form==='fluessig'){
    var ml=einh;
    if (!vp){ set('– keine –','','Füllvolumen: '+nf(ml,0)+' ml. Bitte Flasche wählen.'); return; }
    if (vp.vol===null||vp.vol===undefined){ set('kein Volumen','var(--warn)','Verpackung hat kein Volumen hinterlegt (benötigt: '+nf(ml,0)+' ml).'); return; }
    if (ml<=vp.vol){ set('passt','var(--gruen)',nf(ml,0)+' ml von '+nf(vp.vol,0)+' ml.'); return; }
    set('zu klein','var(--err)','Füllvolumen '+nf(ml,0)+' ml übersteigt '+nf(vp.vol,0)+' ml.');
    return;
  }
  set(vp?'ok':'– keine –','','');
}

function recalc(){
  var rz = REZEPTE[document.getElementById('rezeptur').value];
  var vp = getVerp('verpackung_id');
  var einh = parseFloat((document.getElementById('einheiten').value||'').replace(',','.'))||0;
  var intake = parseFloat((document.getElementById('intake').value||'').replace(',','.'))||0;
  var kK=document.getElementById('k_kosten'), kR=document.getElementById('k_reichweite'), kG=document.getElementById('k_gewicht'), tb=document.getElementById('deklaration');
  var kV=document.getElementById('k_verp'), vh=document.getElementById('verp_hinweis');
  document.getElementById('intakeLabel').textContent = intake ? '(bei '+nf(intake,intake%1?1:0)+' Einheiten/Tag)' : '';
  // EK der ganzen Stückliste (alle Slots)
  var ekVerp=0; SLOTS.forEach(function(id){ var s=getVerp(id); if(s) ekVerp+=s.ek; });
  if (!rz){ kK.textContent='–'; kR.textContent='–'; kG.textContent='–'; kV.textContent='–'; kV.style.color=''; vh.textContent=''; tb.innerHTML='<tr><td colspan="3" class="muted">Rezeptur wählen …</td></tr>'; return; }
  var costPack = rz.cost*einh + ekVerp;
  kK.textContent = einh ? nf(costPack,2)+' €' : '–';
  kR.textContent = (einh && intake) ? nf(einh/intake,0)+' Tage' : '–';
  kG.textContent = rz.weight ? nf(rz.weight,0)+' mg' : '–';
  verpCheck(rz, vp, einh, kV, vh);
  if (!rz.nutrients.length){ tb.innerHTML='<tr><td colspan="3" class="muted">Diese Rezeptur hat keine deklarierbaren Wirkstoffe.</td></tr>'; return; }
  tb.innerHTML = rz.nutrients.map(function(n){
    var mgDay = n.mg * intake;
    var betrag = n.einheit==='µg' ? nf(mgDay*1000,1)+' µg' : nf(mgDay,1)+' mg';
    var pct = '<span class="muted">keine NRV</span>';
    if (n.nrv!==null && n.nrv!==undefined) { var nrvMg = n.einheit==='µg'?parseFloat(n.nrv)/1000:parseFloat(n.nrv); if (nrvMg>0) pct = nf(mgDay/nrvMg*100,0)+' %'; }
    return '<tr><td>'+n.name+'</td><td class="bx-num">'+betrag+'</td><td class="bx-num">'+pct+'</td></tr>';
  }).join('');
}
['rezeptur','einheiten','intake'].concat(SLOTS).forEach(function(idn){
  var el=document.getElementById(idn); if(el){ el.addEventListener('change',recalc); el.addEventListener('input',recalc); }
});
recalc();
</script>
<?php
render_footer();
