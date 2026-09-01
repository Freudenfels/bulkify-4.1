<?php
// Bestellung anlegen & bearbeiten – Positionen + Aktionen (bestellen / Wareneingang)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    $lief = ($_POST['lieferant_id'] ?? '') !== '' ? (int)$_POST['lieferant_id'] : null;
    if ($aktion === 'liefern' && !$neu) { bestellung_wareneingang((int)$id); header('Location: ?p=bestellung&id=' . $id . '&geliefert=1'); exit; }
    if ($aktion === 'bestellt' && !$neu) { q("UPDATE bestellung SET status='bestellt' WHERE id=?", [(int)$id]); header('Location: ?p=bestellung&id=' . $id . '&ok=1'); exit; }
    // Entwurf zurück in den Einkaufsbedarf: Entwurf löschen -> Bedarf erscheint wieder (kein Netting mehr)
    if ($aktion === 'zurueck_bedarf' && !$neu) {
        $st = scalar("SELECT status FROM bestellung WHERE id=?", [(int)$id]);
        if ($st === 'offen') {
            q("DELETE FROM bestellung_position WHERE bestellung_id=?", [(int)$id]);
            q("DELETE FROM bestellung WHERE id=?", [(int)$id]);
            header('Location: ?p=bedarf&zurueck=1'); exit;
        }
        header('Location: ?p=bestellung&id=' . $id); exit;
    }
    if ($neu) {
        q("INSERT INTO bestellung (nummer,lieferant_id,status,notiz) VALUES (?,?,?,?)",
          [naechste_nummer('BE'), $lief, 'offen', trim($_POST['notiz'] ?? '')]);
        $id = insert_id();
    } else {
        q("UPDATE bestellung SET lieferant_id=?,notiz=? WHERE id=?", [$lief, trim($_POST['notiz'] ?? ''), (int)$id]);
    }
    // Positionen synchronisieren – NUR Lagerartikel-Positionen; Bulk-Freitext (item_id NULL) bleibt erhalten
    q("DELETE FROM bestellung_position WHERE bestellung_id=? AND item_id IS NOT NULL", [(int)$id]);
    $pi = $_POST['p_item'] ?? []; $pm = $_POST['p_menge'] ?? []; $pe = $_POST['p_ek'] ?? []; $pa = $_POST['p_auftrag'] ?? [];
    foreach ($pi as $i => $iid) {
        $iid = (int)$iid; if ($iid <= 0) continue;
        $menge = (float)str_replace(',', '.', $pm[$i] ?? '0');
        $ek = (float)str_replace(',', '.', $pe[$i] ?? '0');
        $einh = scalar("SELECT einheit FROM item WHERE id=?", [$iid]);
        $aid = ($pa[$i] ?? '') !== '' ? (int)$pa[$i] : null;
        q("INSERT INTO bestellung_position (bestellung_id,item_id,menge,ek_preis,einheit,auftrag_id,sort) VALUES (?,?,?,?,?,?,?)",
          [(int)$id, $iid, $menge, $ek, $einh, $aid, $i]);
    }
    header('Location: ?p=bestellung&id=' . $id . '&ok=1'); exit;
}

$b = $neu ? ['status'=>'offen'] : one("SELECT * FROM bestellung WHERE id=?", [(int)$id]);
if (!$b) { $neu = true; $b = ['status'=>'offen']; }
$v = fn($k) => h((string)($b[$k] ?? ''));
$geliefert = ($b['status'] ?? '') === 'geliefert';

$lieferanten = all("SELECT id, firma FROM lieferanten ORDER BY firma");
$items = all("SELECT id, name, einheit, ek_preis, kategorie FROM item WHERE kategorie IN ('rohstoff','verpackung','verbrauch') AND gesperrt=0 ORDER BY name");
$positionen = $neu ? [] : all("SELECT * FROM bestellung_position WHERE bestellung_id=? AND item_id IS NOT NULL ORDER BY sort,id", [(int)$id]);
$bulkPositionen = $neu ? [] : all("SELECT * FROM bestellung_position WHERE bestellung_id=? AND item_id IS NULL ORDER BY sort,id", [(int)$id]);
$EK = []; foreach ($items as $it) $EK[$it['id']] = (float)$it['ek_preis'];

function pos_options(array $items, $sel): string {
    $s = '<option value="">– Artikel wählen –</option>';
    foreach ($items as $it) $s .= '<option value="' . (int)$it['id'] . '"' . ((int)$sel === (int)$it['id'] ? ' selected' : '') . '>' . h($it['name']) . ' (' . h($it['einheit']) . ')</option>';
    return $s;
}
// Offene Kundenaufträge, damit eine Bestellposition „für Auftrag X" markiert werden kann (Baustein 4)
$auftraege = all("SELECT a.id, a.nummer, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt, k.firma
                  FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN kunden k ON k.id=a.kunde_id
                  WHERE a.status <> 'versendet' ORDER BY a.angelegt DESC");
function auftrag_options(array $auftraege, $sel): string {
    $s = '<option value="">Lager / allgemein</option>';
    foreach ($auftraege as $a) {
        $lbl = $a['nummer'] . ($a['produkt'] ? ' · ' . $a['produkt'] : '') . ($a['firma'] ? ' · ' . $a['firma'] : '');
        $s .= '<option value="' . (int)$a['id'] . '"' . ((int)$sel === (int)$a['id'] ? ' selected' : '') . '>' . h($lbl) . '</option>';
    }
    return $s;
}

render_header('einkauf', $neu ? 'Neue Bestellung' : ($b['nummer'] ?? 'Bestellung'));
bx_head($neu ? 'Neue Bestellung' : $v('nummer'), $neu ? 'Beim Lieferanten bestellen' : 'Bestellung bearbeiten', bx_btn('Zurück zur Liste', '?p=einkauf', 'ghost'));
if (isset($_GET['ok']))        echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['geliefert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Als geliefert verbucht – Chargen im Wareneingang (Quarantäne) angelegt.</div>';

if (!$neu) {
    echo '<div class="bx-panel"><div class="bx-row" style="justify-content:space-between;align-items:center">';
    echo '<div>Status: ' . (match($b['status']){'offen'=>bx_badge('offen','info'),'bestellt'=>bx_badge('bestellt','warn'),'geliefert'=>bx_badge('geliefert','ok'),default=>bx_badge(status_text($b['status']))}) . '</div><div class="bx-row">';
    if ($b['status'] === 'offen')    echo '<form method="post" style="display:inline"><input type="hidden" name="aktion" value="zurueck_bedarf"><button class="btn btn-ghost btn-sm" type="submit" title="Diesen Entwurf verwerfen – der Bedarf erscheint wieder im Einkaufsbedarf">Zurück in den Einkaufsbedarf</button></form> ';
    if ($b['status'] === 'offen')    echo '<form method="post" style="display:inline"><input type="hidden" name="aktion" value="bestellt"><button class="btn btn-ghost btn-sm" type="submit">als bestellt markieren</button></form>';
    if ($b['status'] === 'bestellt') echo '<form method="post" style="display:inline"><input type="hidden" name="aktion" value="liefern"><button class="btn btn-primary btn-sm" type="submit">Wareneingang buchen</button></form>';
    echo '</div></div></div>';

    // Kontext: für welche(n) Auftrag/Produkt/Kunde wird bestellt? Damit die Dringlichkeit einschätzbar ist.
    $kontext = all("SELECT DISTINCT a.id, a.nummer, a.menge, a.stueck,
                           COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt, k.firma AS kunde,
                           pa.id AS pa_id, pa.prio, pa.geplant_am
                    FROM bestellung_position bp
                    JOIN auftrag a ON a.id=bp.auftrag_id
                    LEFT JOIN produkt p ON p.id=a.produkt_id
                    LEFT JOIN kunden k ON k.id=a.kunde_id
                    LEFT JOIN produktionsauftrag pa ON pa.auftrag_id=a.id
                    WHERE bp.bestellung_id=? ORDER BY pa.prio, a.nummer", [(int)$id]);
    if ($kontext) {
        echo '<div class="bx-panel"><h2 style="margin-top:0">Wofür wird bestellt</h2>';
        echo '<div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Auftrag</th><th>Produkt</th><th>Kunde</th><th class="bx-num">Menge</th><th>Priorität</th><th>Geplant am</th><th>Bereitschaft</th></tr></thead><tbody>';
        foreach ($kontext as $c) {
            $mengeTxt = (int)$c['menge'] . ' Pkg' . ((int)$c['stueck'] ? ' &times; ' . (int)$c['stueck'] : '');
            $ber  = $c['pa_id'] ? bereitschaft_badge(produktion_bereitschaft((int)$c['pa_id'])['status']) : '<span class="muted">–</span>';
            $ziel = $c['pa_id'] ? '?p=produktionsauftrag&id=' . (int)$c['pa_id'] : '?p=auftrag&id=' . (int)$c['id'];
            echo '<tr onclick="location.href=\'' . $ziel . '\'" style="cursor:pointer">';
            echo '<td>' . h($c['nummer']) . '</td>';
            echo '<td>' . h($c['produkt'] ?: '–') . '</td>';
            echo '<td>' . ($c['kunde'] ? h($c['kunde']) : '<span class="muted">–</span>') . '</td>';
            echo '<td class="bx-num">' . $mengeTxt . '</td>';
            echo '<td>' . prio_badge((int)($c['prio'] ?? 2)) . '</td>';
            echo '<td>' . ($c['geplant_am'] ? h(date('d.m.Y', strtotime($c['geplant_am']))) : '<span class="muted">–</span>') . '</td>';
            echo '<td>' . $ber . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="muted" style="font-size:12px;margin:10px 0 0">Priorität und geplantes Produktionsdatum zeigen, wie dringend diese Bestellung ist. Zeile öffnet den Produktionsauftrag.</p>';
        echo '</div>';
    }
    if ($bulkPositionen) {
        echo '<div class="bx-panel"><h2 style="margin-top:0">Bulk-Zukauf (Fremdproduktion)</h2>';
        echo '<div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Position</th><th class="bx-num">Menge</th></tr></thead><tbody>';
        foreach ($bulkPositionen as $bp) echo '<tr><td>' . h($bp['bezeichnung'] ?: 'Bulk') . '</td><td class="bx-num">' . rtrim(rtrim(number_format((float)$bp['menge'],3,',','.'),'0'),',') . ' ' . h($bp['einheit']) . '</td></tr>';
        echo '</tbody></table></div><p class="muted" style="font-size:12px;margin:10px 0 0">Zugekaufter Bulk (fertige Kapseln/Tabletten/Pulver). Beim Wareneingang als Fertigware-Charge auf den Auftrag buchen.</p></div>';
    }
    // Etiketten dieser Bestellung: Druckdatei zum Herunterladen (für den Lieferanten/die Druckerei) + Maße + Produkt.
    $etikettPos = all("SELECT bp.auftrag_id, i.name FROM bestellung_position bp
                       JOIN item i ON i.id=bp.item_id
                       WHERE bp.bestellung_id=? AND i.verpackung_rolle='etikett' AND bp.auftrag_id IS NOT NULL
                       GROUP BY bp.auftrag_id, i.name", [(int)$id]);
    if ($etikettPos) {
        echo '<div class="bx-panel"><h2 style="margin-top:0">Etiketten (Druckdateien)</h2>';
        echo '<div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Etikett</th><th>Produkt</th><th>Maße</th><th>Druckdatei</th></tr></thead><tbody>';
        foreach ($etikettPos as $ep) {
            $ei = etikett_info((int)$ep['auftrag_id']);
            $dl = !empty($ei['dok'])
                ? '<a href="?p=dokument&id=' . (int)$ei['dok']['id'] . '" target="_blank" style="white-space:nowrap;text-decoration:none">&#11015;&#65039; ' . h($ei['dok']['datei_orig'] ?: 'Etikett') . '</a>'
                : bx_badge('kein Design hochgeladen', 'warn');
            echo '<tr><td>' . h($ep['name']) . '</td>'
               . '<td>' . ($ei['produkt'] ? h($ei['produkt']) : '<span class="muted">–</span>') . '</td>'
               . '<td>' . ($ei['masse'] ? h($ei['masse']['label']) : '<span class="muted">–</span>') . '</td>'
               . '<td>' . $dl . '</td></tr>';
        }
        echo '</tbody></table></div><p class="muted" style="font-size:12px;margin:10px 0 0">Kundenspezifische Etiketten. Druckdatei herunterladen und an die Druckerei/den Lieferanten weitergeben.</p></div>';
    }
}
?>
<form method="post" class="bx-form">
  <fieldset <?= $geliefert ? 'disabled' : '' ?> style="border:0;padding:0;margin:0;min-width:0">
  <div class="bx-panel"><div class="bx-grid">
    <div class="bx-field"><label>Lieferant</label>
      <select name="lieferant_id"><option value="">– keiner –</option>
        <?php foreach ($lieferanten as $l): ?><option value="<?= $l['id'] ?>" <?= (int)($b['lieferant_id']??0)===(int)$l['id']?'selected':'' ?>><?= h($l['firma']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Notiz</label><input type="text" name="notiz" value="<?= $v('notiz') ?>"></div>
  </div></div>

  <div class="bx-panel">
    <h2>Positionen</h2>
    <table class="bx-table">
      <thead><tr><th style="width:34%">Artikel</th><th>Für Auftrag <?= bx_hint('Wofür wird das bestellt? Beim Wareneingang wird die Charge automatisch diesem Auftrag zugeordnet. „Lager / allgemein" = Vorrat.') ?></th><th style="width:120px">Menge</th><th style="width:120px">EK / Einheit</th><th class="bx-num">Summe</th><th></th></tr></thead>
      <tbody id="prows">
        <?php $pr = $positionen ?: [['item_id'=>'','menge'=>'','ek_preis'=>'','auftrag_id'=>'']]; foreach ($pr as $p): ?>
        <tr class="prow">
          <td><select name="p_item[]" class="pitem"><?= pos_options($items, $p['item_id']) ?></select></td>
          <td><select name="p_auftrag[]" class="pauf"><?= auftrag_options($auftraege, $p['auftrag_id'] ?? '') ?></select></td>
          <td><input type="number" step="0.001" class="pmenge" name="p_menge[]" value="<?= h($p['menge']!==''&&$p['menge']!==null ? rtrim(rtrim(number_format((float)$p['menge'],3,'.',''),'0'),'.') : '') ?>"></td>
          <td><input type="number" step="0.0001" class="pek" name="p_ek[]" value="<?= h($p['ek_preis']!==''&&$p['ek_preis']!==null ? rtrim(rtrim(number_format((float)$p['ek_preis'],4,'.',''),'0'),'.') : '') ?>"></td>
          <td class="bx-num psum">–</td>
          <td><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.prow').remove();psum()">×</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th colspan="4" style="text-align:right">Gesamt</th><th class="bx-num" id="pgesamt">–</th><th></th></tr></tfoot>
    </table>
    <button type="button" class="btn btn-ghost btn-sm" id="addP">+ Position</button>
  </div>
  </fieldset>
  <div class="bx-row" style="margin-top:var(--sp-4)">
    <?php if (!$geliefert): ?><button class="btn btn-primary" type="submit">Speichern</button><?php endif; ?>
    <a class="btn btn-ghost" href="?p=einkauf">Zurück</a>
  </div>
</form>

<script>
var EK = <?= json_encode($EK, JSON_UNESCAPED_UNICODE) ?>;
var OPT = <?= json_encode(pos_options($items, 0), JSON_UNESCAPED_UNICODE) ?>;
var OPTA = <?= json_encode(auftrag_options($auftraege, 0), JSON_UNESCAPED_UNICODE) ?>;
function eur(x){ return x.toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2})+' €'; }
function psum(){
  var g=0;
  document.querySelectorAll('.prow').forEach(function(r){
    var m=parseFloat((r.querySelector('.pmenge').value||'').replace(',','.'))||0;
    var e=parseFloat((r.querySelector('.pek').value||'').replace(',','.'))||0;
    var s=m*e; g+=s;
    r.querySelector('.psum').textContent = (m&&e)?eur(s):'–';
  });
  document.getElementById('pgesamt').textContent = g?eur(g):'–';
}
(function(){
  document.getElementById('addP').addEventListener('click',function(){
    var tr=document.createElement('tr'); tr.className='prow';
    tr.innerHTML='<td><select name="p_item[]" class="pitem">'+OPT+'</select></td>'
      +'<td><select name="p_auftrag[]" class="pauf">'+OPTA+'</select></td>'
      +'<td><input type="number" step="0.001" class="pmenge" name="p_menge[]"></td>'
      +'<td><input type="number" step="0.0001" class="pek" name="p_ek[]"></td>'
      +'<td class="bx-num psum">–</td><td><button type="button" class="btn btn-ghost btn-sm">×</button></td>';
    tr.querySelector('button').addEventListener('click',function(){tr.remove();psum();});
    wire(tr); document.getElementById('prows').appendChild(tr);
  });
  function wire(row){
    row.querySelector('.pitem').addEventListener('change',function(){ var ek=EK[this.value]; if(ek!==undefined && !row.querySelector('.pek').value) row.querySelector('.pek').value=ek; psum(); });
    row.querySelector('.pmenge').addEventListener('input',psum);
    row.querySelector('.pek').addEventListener('input',psum);
  }
  document.querySelectorAll('.prow').forEach(wire);
  psum();
})();
</script>
<?php render_footer(); ?>
