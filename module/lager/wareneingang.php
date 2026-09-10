<?php
// Wareneingang – Charge buchen; Quarantäne freigeben
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$hinweis = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    if ($aktion === 'buchen') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $menge = (float) str_replace(',', '.', trim($_POST['menge'] ?? '0'));
        $lief = ($_POST['lieferant_id'] ?? '') !== '' ? (int)$_POST['lieferant_id'] : null;
        $aid = ($_POST['auftrag_id'] ?? '') !== '' ? (int)$_POST['auftrag_id'] : null;
        $cid = wareneingang_buchen($item_id, $menge, trim($_POST['charge_nr'] ?? ''), trim($_POST['mhd'] ?? '') ?: null, $lief, trim($_POST['notiz'] ?? ''), $aid);
        header('Location: ?p=wareneingang' . ($cid ? '&ok=1' : '&fehler=1')); exit;
    }
    if ($aktion === 'freigeben') {
        q("UPDATE charge SET status='frei' WHERE id=? AND status='quarantaene'", [(int)($_POST['charge_id'] ?? 0)]);
        header('Location: ?p=wareneingang&frei=1'); exit;
    }
}

$items = all("SELECT id, name, kategorie, einheit FROM item WHERE kategorie IN ('rohstoff','verpackung','fertig','verkaufsfertig') AND gesperrt=0 ORDER BY name");
$lieferanten = all("SELECT id, firma FROM lieferanten ORDER BY firma");
$offeneAuftraege = all("SELECT a.id, a.nummer, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt, k.firma
                        FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN kunden k ON k.id=a.kunde_id
                        WHERE a.status <> 'versendet' ORDER BY a.angelegt DESC");
$charges = all("SELECT c.*, i.name AS item_name, l.firma AS lieferant_firma
                FROM charge c LEFT JOIN item i ON i.id=c.item_id LEFT JOIN lieferanten l ON l.id=c.lieferant_id
                ORDER BY c.angelegt DESC LIMIT 25");

$statusBadge = fn($s) => match ($s) {
    'frei'        => bx_badge('frei','ok'),
    'quarantaene' => bx_badge('Quarantäne','warn'),
    'gesperrt'    => bx_badge('gesperrt','err'),
    'leer'        => bx_badge('leer'),
    default       => bx_badge($s),
};
$mng = fn($x,$e) => rtrim(rtrim(number_format((float)$x,3,',','.'),'0'),',') . ' ' . h($e ?: '');

render_header('wareneingang', 'Wareneingang');
bx_head('Wareneingang', 'Charge buchen', bx_btn('Zum Bestand', '?p=lager', 'ghost'));
if (isset($_GET['ok']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Wareneingang gebucht.</div>';
if (isset($_GET['frei']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Charge freigegeben.</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">Menge oder Artikel fehlt.</div>';
?>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="buchen">
  <div class="bx-panel"><div class="bx-grid">
    <style>
      .bx-combo{position:relative}
      .bx-combo-list{position:absolute;left:0;right:0;top:100%;z-index:30;max-height:300px;overflow:auto;
        background:var(--panel,#fff);border:1px solid var(--line,#ddd);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.14);margin-top:3px}
      .bx-combo-list .opt{padding:8px 12px;cursor:pointer;font-size:14px}
      .bx-combo-list .opt:hover,.bx-combo-list .opt.hl{background:var(--panel-2,#f2f2f0)}
      .bx-combo-list .opt .muted{font-size:12px}
      .bx-combo-empty{padding:8px 12px;color:var(--muted);font-size:13px}
    </style>
    <div class="bx-field bx-combo"><label>Artikel</label>
      <input type="text" id="weArtSuche" autocomplete="off" placeholder="Artikel suchen oder wählen…" required aria-expanded="false">
      <input type="hidden" name="item_id" id="weArtId">
      <div id="weArtList" class="bx-combo-list" hidden></div>
    </div>
    <div class="bx-field"><label>Menge</label><input type="number" step="0.001" name="menge" required></div>
    <div class="bx-field"><label>Charge (Lieferant) <?= bx_hint('Chargennummer laut Lieferant/CoA') ?></label><input type="text" name="charge_nr"></div>
    <div class="bx-field"><label>MHD</label><input type="date" name="mhd"></div>
    <div class="bx-field"><label>Lieferant</label>
      <select name="lieferant_id">
        <option value="">– keiner –</option>
        <?php foreach ($lieferanten as $lf): ?><option value="<?= $lf['id'] ?>"><?= h($lf['firma']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Für Auftrag <?= bx_hint('Optional: gehört diese Lieferung zu einem Kundenauftrag? Dann wird die Charge dem Auftrag zugeordnet. Sonst „Lager / allgemein".') ?></label>
      <select name="auftrag_id">
        <option value="">Lager / allgemein</option>
        <?php foreach ($offeneAuftraege as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h($a['nummer'] . ($a['produkt'] ? ' · ' . $a['produkt'] : '') . ($a['firma'] ? ' · ' . $a['firma'] : '')) ?></option><?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="bx-field"><label>Notiz</label><input type="text" name="notiz"></div>
  <div class="muted" style="margin-bottom:8px">Rohstoffe gehen zunächst in <strong>Quarantäne</strong> und müssen unten freigegeben werden. Verpackungen sind sofort frei.</div>
  <button class="btn btn-primary" type="submit">Wareneingang buchen</button>
  </div>
</form>
<script>
(function(){
  var items = <?= json_encode(array_map(fn($it)=>['id'=>(int)$it['id'],'n'=>(string)$it['name'],'e'=>(string)$it['einheit'],'k'=>(string)$it['kategorie']], $items), JSON_UNESCAPED_UNICODE) ?>;
  var box=document.getElementById('weArtSuche'), hid=document.getElementById('weArtId'), list=document.getElementById('weArtList');
  if(!box||!hid||!list) return;
  var katLbl={rohstoff:'Rohstoff',verpackung:'Verpackung',fertig:'Fertigware',verkaufsfertig:'Verkaufsfertig'};
  var hl=-1, shown=[];
  function esc(s){ return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function render(q){
    q=(q||'').trim().toLowerCase();
    shown = items.filter(function(it){ return !q || it.n.toLowerCase().indexOf(q)>=0; }).slice(0,50);
    if(!shown.length){ list.innerHTML='<div class="bx-combo-empty">Kein Artikel gefunden.</div>'; }
    else list.innerHTML = shown.map(function(it,i){
      return '<div class="opt" data-i="'+i+'">'+esc(it.n)+' <span class="muted">('+esc(it.e||'')+(it.k&&katLbl[it.k]?' · '+katLbl[it.k]:'')+')</span></div>';
    }).join('');
    hl=-1; list.hidden=false; box.setAttribute('aria-expanded','true');
  }
  function paint(){ Array.prototype.forEach.call(list.querySelectorAll('.opt'),function(o){ o.classList.toggle('hl', +o.dataset.i===hl); }); var el=list.querySelector('.opt.hl'); if(el) el.scrollIntoView({block:'nearest'}); }
  function choose(i){ var it=shown[i]; if(!it) return; hid.value=it.id; box.value=it.n; box.setCustomValidity(''); close(); }
  function close(){ list.hidden=true; box.setAttribute('aria-expanded','false'); }
  box.addEventListener('input', function(){ hid.value=''; render(box.value); });
  box.addEventListener('focus', function(){ render(box.value); });
  box.addEventListener('keydown', function(e){
    if(list.hidden){ if(e.key==='ArrowDown') render(box.value); return; }
    if(e.key==='ArrowDown'){ e.preventDefault(); hl=Math.min(hl+1,shown.length-1); paint(); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); hl=Math.max(hl-1,0); paint(); }
    else if(e.key==='Enter'){ if(hl>=0){ e.preventDefault(); choose(hl); } }
    else if(e.key==='Escape'){ close(); }
  });
  list.addEventListener('mousedown', function(e){ var o=e.target.closest('.opt'); if(o){ e.preventDefault(); choose(+o.dataset.i); } });
  document.addEventListener('click', function(e){ if(!e.target.closest('#weArtSuche')&&!e.target.closest('#weArtList')) close(); });
  if(box.form) box.form.addEventListener('submit', function(e){
    if((box.value||'').trim()==='') return;   // leer -> HTML5 required greift
    if(!hid.value){ e.preventDefault(); box.setCustomValidity('Bitte einen Artikel aus der Liste wählen.'); box.reportValidity(); }
  });
})();
</script>

<div class="bx-panel">
  <h2>Letzte Chargen</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Charge</th><th>Artikel</th><th class="bx-num">Menge</th><th>MHD</th><th>Lieferant</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$charges): ?><tr><td colspan="7" class="muted">Noch keine Chargen gebucht.</td></tr><?php endif; ?>
    <?php foreach ($charges as $c): ?>
      <tr>
        <td><?= h($c['charge_nr'] ?: '–') ?></td>
        <td><?= h($c['item_name'] ?: '–') ?></td>
        <td class="bx-num"><?= $mng($c['menge_verfuegbar'], $c['einheit']) ?></td>
        <td><?= $c['mhd'] ? h(date('d.m.Y', strtotime($c['mhd']))) : '<span class="muted">–</span>' ?></td>
        <td><?= $c['lieferant_firma'] ? h($c['lieferant_firma']) : '<span class="muted">–</span>' ?></td>
        <td><?= $statusBadge($c['status']) ?></td>
        <td style="text-align:right">
          <?php if ($c['status']==='quarantaene'): ?>
            <form method="post" style="display:inline"><input type="hidden" name="aktion" value="freigeben"><input type="hidden" name="charge_id" value="<?= (int)$c['id'] ?>"><button class="btn btn-primary btn-sm" type="submit">freigeben</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer(); ?>
