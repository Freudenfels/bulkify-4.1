<?php
// Produktions-Liste (Produktionsaufträge) – nach Reitern: Produktionsbereit / Wartet auf Material / Abgeschlossen.
// Standard: nur produktionsbereite (alles Material da) + laufende. „Wartet auf Material" nur im eigenen Reiter.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'prio';
$dir  = $_GET['dir']  ?? 'asc';
$tab  = $_GET['tab']  ?? 'bereit';
if (!in_array($tab, ['bereit', 'wartet', 'erledigt'], true)) $tab = 'bereit';

// Sammel-Umstellung Eigen-/Fremdproduktion für markierte Aufträge.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'art_bulk') {
    $art = ($_POST['art'] ?? '') === 'eigen' ? 'eigen' : 'fremd';
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['pa'] ?? [])), fn($x) => $x > 0));
    $n = 0; $skip = 0;
    foreach ($ids as $pid) { if (produktionsauftrag_art_setzen($pid, $art)) $n++; else $skip++; }
    $_SESSION['prod_flash'] = $n . ' auf ' . ($art === 'eigen' ? 'Eigenproduktion' : 'Fremdproduktion') . ' gesetzt'
        . ($skip ? ', ' . $skip . ' übersprungen (schon begonnen)' : '') . '.';
    header('Location: ?p=produktion&tab=' . $tab . ($q !== '' ? '&q=' . urlencode($q) : '')); exit;
}

// Neuen Produktionsauftrag OHNE Kundenbezug anlegen (Lager-/Vorratsproduktion).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'neu') {
    $modus = ($_POST['modus'] ?? 'produkt') === 'bulk' ? 'bulk' : 'produkt';
    $prio  = max(1, min(3, (int)($_POST['prio'] ?? 2)));
    if ($modus === 'bulk') {
        // Nur Kapseln (Bulk) – auf Basis einer Rezeptur, Menge = Stück.
        $rid    = (int)($_POST['rezeptur_id'] ?? 0);
        $stueck = (int) str_replace(['.', ' '], '', (string)($_POST['stueck'] ?? '0'));
        if ($rid <= 0)   { $_SESSION['prod_flash'] = 'Bitte eine Rezeptur wählen.'; header('Location: ?p=produktion&neu=1'); exit; }
        $paid = produktionsauftrag_bulk_erstellen($rid, $stueck, $prio);
        if ($paid) { header('Location: ?p=produktionsauftrag&id=' . $paid . '&ok=1'); exit; }
        $_SESSION['prod_flash'] = 'Bulk-Produktionsauftrag konnte nicht angelegt werden (Rezeptur ungültig).';
        header('Location: ?p=produktion&neu=1'); exit;
    }
    $pid   = (int)($_POST['produkt_id'] ?? 0);
    $menge = (int) str_replace(['.', ' '], '', (string)($_POST['menge'] ?? '0'));
    $art   = ($_POST['produktionsart'] ?? 'eigen') === 'fremd' ? 'fremd' : 'eigen';
    if ($pid <= 0) { $_SESSION['prod_flash'] = 'Bitte ein Produkt wählen.'; header('Location: ?p=produktion&neu=1'); exit; }
    $paid = produktionsauftrag_lager_erstellen($pid, $menge, $art, $prio);
    if ($paid) { header('Location: ?p=produktionsauftrag&id=' . $paid . '&ok=1'); exit; }
    $_SESSION['prod_flash'] = 'Produktionsauftrag konnte nicht angelegt werden (Produkt ungültig).';
    header('Location: ?p=produktion&neu=1'); exit;
}

// Alle Produktionsaufträge laden (inkl. Fortschritt + nächste Station), Bereitschaft je Auftrag bestimmen.
$alle = all("SELECT pa.*, k.firma AS kunde_firma,
             COALESCE(NULLIF(p.name,''), a.produkt_bezeichnung, CONCAT(rz.name, ' · Bulk')) AS produkt_name,
             (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id) AS n_total,
             (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=1) AS n_done,
             (SELECT station FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=0 ORDER BY s.sort LIMIT 1) AS naechste_station
             FROM produktionsauftrag pa
             LEFT JOIN kunden k ON k.id=pa.kunde_id LEFT JOIN produkt p ON p.id=pa.produkt_id
             LEFT JOIN rezeptur rz ON rz.id=pa.rezeptur_id
             LEFT JOIN auftrag a ON a.id=pa.auftrag_id");
// Bereitschaft (Material da?) sparsam bestimmen – die volle Prüfung (produktion_bereitschaft ->
// auftrag_bedarf) macht mehrere Abfragen je Auftrag. Sie ist NUR für noch nicht begonnene Aufträge
// nötig: 'erledigt' wird nie ausgewertet, und wer schon einen Schritt erledigt hat, „läuft" bereits
// (das weiß die Liste aus n_done, ohne weitere Abfrage). So bleibt die teure Prüfung den offenen vorbehalten.
// Diese Liste ist schreibfrei -> Bestandsabfragen dürfen request-lokal gecacht werden. Viele Aufträge
// teilen dieselben Artikel (Verpackung/Leerkapsel/Rohstoffe); so wird jeder Bestand nur EINMAL geholt.
$GLOBALS['bx_stock_cache'] = [];
// Vorladen in EINER Sammelabfrage statt je Auftrag einzeln: alle Auftrag-/Produktzeilen der noch nicht
// begonnenen Aufträge auf einmal holen und in den Cache legen (pa_row_cached/produkt_row_cached finden sie dann).
$vorPa = []; $vorProd = []; $vorAuf = [];
foreach ($alle as $r) {
    if ($r['status'] !== 'erledigt' && (int)$r['n_done'] === 0) {
        $vorPa[] = (int)$r['id'];
        if (!empty($r['produkt_id']))  $vorProd[] = (int)$r['produkt_id'];
        if (!empty($r['auftrag_id']))  $vorAuf[]  = (int)$r['auftrag_id'];
    }
}
$vorPa = array_values(array_unique($vorPa));
$vorProd = array_values(array_unique($vorProd));
$vorAuf = array_values(array_unique($vorAuf));
$sc = &$GLOBALS['bx_stock_cache'];
if ($vorPa) { $in = implode(',', array_fill(0, count($vorPa), '?'));
    foreach (all("SELECT * FROM produktionsauftrag WHERE id IN ($in)", $vorPa) as $row) $sc['pa:' . (int)$row['id']] = $row;
    // Erledigte Schritte je Auftrag gebündelt (default 0, dann aus der Gruppierung überschreiben).
    foreach ($vorPa as $id) $sc['schr:' . $id] = 0;
    foreach (all("SELECT pa_id, COUNT(*) AS n FROM produktion_schritt WHERE pa_id IN ($in) AND erledigt=1 GROUP BY pa_id", $vorPa) as $row) $sc['schr:' . (int)$row['pa_id']] = (int)$row['n'];
}
if ($vorProd) { $in = implode(',', array_fill(0, count($vorProd), '?'));
    foreach (all("SELECT * FROM produkt WHERE id IN ($in)", $vorProd) as $row) $sc['prod:' . (int)$row['id']] = $row;
    foreach (all("SELECT p.id, p.name, COALESCE(r.darreichungsform,'') AS form FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id IN ($in)", $vorProd) as $row)
        $sc['pbulk:' . (int)$row['id']] = ['name'=>$row['name'], 'form'=>$row['form']]; }
if ($vorAuf) { $in = implode(',', array_fill(0, count($vorAuf), '?'));
    foreach (all("SELECT * FROM auftrag WHERE id IN ($in)", $vorAuf) as $row) $sc['auf:' . (int)$row['id']] = $row;
    // Fertigware-Zukauf je Auftrag gebündelt: Anzahl fertig-Chargen + freie Summe (default 0).
    foreach ($vorAuf as $id) $sc['fw:' . $id] = ['n'=>0, 'frei'=>0.0];
    foreach (all("SELECT c.auftrag_id, COUNT(*) AS n, COALESCE(SUM(CASE WHEN c.status='frei' THEN c.menge_verfuegbar ELSE 0 END),0) AS frei
                  FROM charge c JOIN item i ON i.id=c.item_id WHERE c.auftrag_id IN ($in) AND i.kategorie='fertig' GROUP BY c.auftrag_id", $vorAuf) as $row)
        $sc['fw:' . (int)$row['auftrag_id']] = ['n'=>(int)$row['n'], 'frei'=>(float)$row['frei']]; }
unset($sc);
foreach ($alle as &$r) {
    if ($r['status'] === 'erledigt')  $r['_bereit'] = 'erledigt';
    elseif ((int)$r['n_done'] > 0)    $r['_bereit'] = 'laeuft';                                    // begonnen -> Material war da
    else                              $r['_bereit'] = produktion_bereitschaft((int)$r['id'])['status'];
}
unset($r);
// Cache bleibt für die (schreibfreie) Anzeige aktiv (Spalte „Kapsel/Tablette"); am Dateiende wieder aus.

// Einteilung in die Reiter
$istErledigt = fn($r) => $r['status'] === 'erledigt';
$istWartet   = fn($r) => !$istErledigt($r) && ($r['_bereit'] ?? '') === 'wartet';
$istBereit   = fn($r) => !$istErledigt($r) && !$istWartet($r);   // produktionsbereit + laufend (alles außer wartend/erledigt)

$anzBereit   = count(array_filter($alle, $istBereit));
$anzWartet   = count(array_filter($alle, $istWartet));
$anzErledigt = count(array_filter($alle, $istErledigt));

if ($tab === 'erledigt')      $rows = array_filter($alle, $istErledigt);
elseif ($tab === 'wartet')    $rows = array_filter($alle, $istWartet);
else                          $rows = array_filter($alle, $istBereit);

// Suche innerhalb des Reiters
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function ($r) use ($needle) {
        foreach (['nummer', 'kunde_firma', 'produkt_name'] as $f)
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

// Spalte „Kapsel/Tablette": Grunddaten aller ANGEZEIGTEN Produkte in EINER Abfrage vorladen
// (statt je Zeile eine) -> produktion_groesse_label() findet sie dann im Cache.
$grlIds = array_values(array_unique(array_filter(array_map(fn($r) => (int)($r['produkt_id'] ?? 0), $rows))));
if ($grlIds) { $in = implode(',', array_fill(0, count($grlIds), '?'));
    foreach (all("SELECT p.id, r.darreichungsform AS form, kg.name AS kapsel_name,
                         (SELECT COALESCE(SUM(z.menge_mg),0) FROM rezeptur_zutat z WHERE z.rezeptur_id=r.id) AS fg
                  FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
                  LEFT JOIN kapselgroesse kg ON kg.id=r.kapselgroesse_id WHERE p.id IN ($in)", $grlIds) as $row)
        $GLOBALS['bx_stock_cache']['grl:' . (int)$row['id']] = $row;
}

$statusBadge = function ($r) {
    return match ($r['status']) {
        'offen'    => bx_badge('offen', 'info'),
        'laufend'  => bx_badge('läuft', 'warn'),
        'erledigt' => bx_badge('fertig', 'ok'),
        default    => bx_badge(status_text($r['status'])),
    };
};

$cols = [
    '_sel'         => ['label' => '', 'render' => fn($r)=> '<input type="checkbox" name="pa[]" form="prodBulk" value="'.(int)$r['id'].'" onclick="event.stopPropagation()">'],
    'prio'         => ['label' => 'Prio', 'sort' => true, 'render' => fn($r)=> prio_badge((int)($r['prio'] ?? 2))],
    'bereit'       => ['label' => 'Bereit', 'render' => fn($r)=> bereitschaft_badge($r['_bereit'] ?? '')],
    'nummer'       => ['label' => 'Nummer', 'sort' => true],
    'kunde_firma'  => ['label' => 'Kunde', 'sort' => true, 'render' => fn($r)=> kunde_link($r['kunde_id'] ?? null, firma_kurz($r['kunde_firma']))],
    'produkt_name' => ['label' => 'Produkt', 'render' => fn($r)=> $r['produkt_name']?h($r['produkt_name']):'<span class="muted">–</span>'],
    'groesse'      => ['label' => 'Kapsel/Tablette', 'render' => function($r){ $g = produktion_groesse_label((int)($r['produkt_id'] ?? 0), true); return $g !== '' ? h($g) : '<span class="muted">–</span>'; }],
    'produktionsart' => ['label' => 'Art', 'render' => fn($r)=> ($r['produktionsart'] ?? 'fremd')==='eigen' ? bx_badge('Eigen','ok') : bx_badge('Fremd','info')],
    'menge'        => ['label' => 'Menge', 'sort' => true, 'num' => true],
    'fortschritt'  => ['label' => 'Fortschritt', 'render' => fn($r)=> (int)$r['n_done'].' / '.(int)$r['n_total']],
    'naechste_station' => ['label' => 'Nächste Station', 'render' => fn($r)=> $r['naechste_station']?h($r['naechste_station']):'<span class="bx-ok">abgeschlossen</span>'],
    'status'       => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
];

$TABS = [
    'bereit'   => 'Produktionsbereit',
    'wartet'   => 'Wartet auf Material',
    'erledigt' => 'Abgeschlossen',
];
$TABCOUNT = ['bereit' => $anzBereit, 'wartet' => $anzWartet, 'erledigt' => $anzErledigt];
$sub = ['bereit' => 'produktionsbereite Aufträge (Material vollständig da)', 'wartet' => 'Aufträge, die auf Material warten', 'erledigt' => 'abgeschlossene Produktionsaufträge'];

$flash = $_SESSION['prod_flash'] ?? null; unset($_SESSION['prod_flash']);
$zeigeNeu = isset($_GET['neu']);
render_header('produktion', 'Produktion');
bx_head('Produktion', count($rows) . ' ' . $sub[$tab], bx_btn('+ Neuer Produktionsauftrag', '?p=produktion&neu=1', 'primary'));
if ($flash) echo '<div class="bx-panel badge-ok" style="padding:8px 12px">' . h($flash) . '</div>';

if ($zeigeNeu):
    // Produkte für die Auswahl (tippbar mit Live-Filter). Mit Rezeptur zuerst (dort ist die Darreichungsform bekannt).
    $produkteNeu = all("SELECT p.id, p.name, p.nummer, p.einheiten_pro_packung AS epp, r.darreichungsform AS form
                        FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
                        ORDER BY (p.rezeptur_id IS NULL), p.name");
    $DFORMN = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','gummi'=>'Fruchtgummi','gel'=>'Gel','pulver'=>'Pulver','fluessig'=>'Flüssig'];
    // Einheit-Wort + Label je Produkt einmal bauen (datalist + JS-Map nutzen dasselbe -> müssen identisch sein).
    $ehlWort = fn($form) => in_array($form, ['kapsel','softgel'], true) ? 'Kapseln' : ($form === 'tablette' ? 'Tabletten' : 'Stück');
    foreach ($produkteNeu as &$pn) {
        $epp = (int)$pn['epp']; $ehl = $ehlWort($pn['form'] ?? '');
        $mengeTeil = $epp > 0 ? number_format($epp, 0, ',', '.') . ' ' . $ehl : ($DFORMN[$pn['form'] ?? ''] ?? ($pn['form'] ?? ''));
        $pn['_ehl'] = $ehl;
        $pn['_lbl'] = trim($pn['name'] . ($pn['nummer'] ? ' · ' . $pn['nummer'] : '') . ($mengeTeil !== '' ? ' · ' . $mengeTeil : ''));
    }
    unset($pn);
    // Rezepturen für den Bulk-Modus (tippbar). Nur Kapsel/Softgel/Tablette sind als Bulk sinnvoll herstellbar,
    // wir zeigen aber alle (die Form steht im Label).
    $rezepteNeu = all("SELECT id, name, nummer, darreichungsform AS form FROM rezeptur ORDER BY name");
    foreach ($rezepteNeu as &$rn) {
        $rn['_ehl'] = $ehlWort($rn['form'] ?? '');
        $rn['_lbl'] = trim($rn['name'] . ($rn['nummer'] ? ' · ' . $rn['nummer'] : '') . (($rn['form'] ?? '') ? ' · ' . ($DFORMN[$rn['form']] ?? $rn['form']) : ''));
    }
    unset($rn);
?>
<div class="bx-panel" style="margin-bottom:16px">
  <h2 style="margin-top:0">Neuer Produktionsauftrag <span class="muted" style="font-weight:400;font-size:13px">ohne Kundenbezug – z. B. Lager-/Vorratsproduktion</span></h2>
  <div class="bx-row" style="gap:18px;margin-bottom:14px">
    <label style="cursor:pointer"><input type="radio" name="modus_ui" value="produkt" checked> Fertiges Produkt (mit Verpackung)</label>
    <label style="cursor:pointer"><input type="radio" name="modus_ui" value="bulk"> Nur Kapseln (Bulk, ohne Verpackung)</label>
  </div>
  <form method="post" class="bx-row" style="gap:14px;align-items:flex-end;flex-wrap:wrap" data-busy="Lege an …">
    <input type="hidden" name="aktion" value="neu">
    <input type="hidden" name="modus" id="modusField" value="produkt">

    <!-- Modus: Fertiges Produkt -->
    <div class="modus-produkt bx-row" style="gap:14px;align-items:flex-end;flex-wrap:wrap;margin:0">
      <div class="bx-field" style="margin:0;flex:1 1 320px">
        <label>Produkt</label>
        <input type="text" class="prodpick-txt" list="prod_dl" autocomplete="off" placeholder="Produkt tippen … (Name oder Nummer)" style="width:100%">
        <input type="hidden" name="produkt_id" class="prodpick-id">
        <datalist id="prod_dl"><?php foreach ($produkteNeu as $p): ?><option value="<?= h($p['_lbl']) ?>"></option><?php endforeach; ?></datalist>
      </div>
      <div class="bx-field" style="margin:0;width:170px"><label>Menge (Packungen)</label><input type="number" name="menge" id="pmenge" min="1" step="1" value="1" style="width:100%"><div class="muted" id="pmengeHint" style="font-size:12px;margin-top:4px">&nbsp;</div></div>
      <div class="bx-field" style="margin:0;width:190px"><label>Produktionsart</label>
        <select name="produktionsart" style="width:100%">
          <option value="eigen" selected>Eigenproduktion</option>
          <option value="fremd">Fremdproduktion (Zukauf)</option>
        </select>
      </div>
    </div>

    <!-- Modus: Nur Kapseln (Bulk) -->
    <div class="modus-bulk bx-row" style="gap:14px;align-items:flex-end;flex-wrap:wrap;margin:0;display:none">
      <div class="bx-field" style="margin:0;flex:1 1 320px">
        <label>Rezeptur</label>
        <input type="text" class="rezpick-txt" list="rez_dl" autocomplete="off" placeholder="Rezeptur tippen … (Name oder Nummer)" style="width:100%">
        <input type="hidden" name="rezeptur_id" class="rezpick-id">
        <datalist id="rez_dl"><?php foreach ($rezepteNeu as $rz): ?><option value="<?= h($rz['_lbl']) ?>"></option><?php endforeach; ?></datalist>
      </div>
      <div class="bx-field" style="margin:0;width:170px"><label>Stückzahl (Kapseln)</label><input type="number" name="stueck" min="1" step="1" value="1000" style="width:100%"><div class="muted" id="bulkHint" style="font-size:12px;margin-top:4px">&nbsp;</div></div>
    </div>

    <div class="bx-field" style="margin:0;width:150px"><label>Priorität</label>
      <select name="prio" style="width:100%">
        <option value="2" selected>Normal</option>
        <option value="1">Hoch</option>
        <option value="3">Niedrig</option>
      </select>
    </div>
    <div class="bx-row" style="gap:8px;margin:0">
      <button class="btn btn-primary" type="submit">Anlegen</button>
      <a class="btn btn-ghost" href="?p=produktion&tab=<?= h($tab) ?>">Abbrechen</a>
    </div>
  </form>
  <div class="muted" style="font-size:12px;margin-top:8px" id="modusHinweis"></div>
  <script>
  (function(){
    // Produkt-Modus: Live-Gesamtstückzahl
    var pmap = {};
    <?php foreach ($produkteNeu as $p): ?>
    pmap[<?= json_encode($p['_lbl'], JSON_UNESCAPED_UNICODE) ?>] = {id:<?= (int)$p['id'] ?>, epp:<?= (int)$p['epp'] ?>, ehl:<?= json_encode($p['_ehl'], JSON_UNESCAPED_UNICODE) ?>};
    <?php endforeach; ?>
    var pt = document.querySelector('.prodpick-txt'), ph = document.querySelector('.prodpick-id');
    var pm = document.getElementById('pmenge'), phint = document.getElementById('pmengeHint');
    function pcur(){ return pmap[(pt.value||'').trim()] || null; }
    function pupd(){
      var p = pcur(); ph.value = p ? p.id : '';
      if (!p) { phint.innerHTML = '&nbsp;'; phint.style.color=''; return; }
      if (!p.epp) { phint.textContent = 'Einheiten je Packung am Produkt nicht gepflegt – bitte am Produkt ergänzen.'; phint.style.color='var(--err)'; return; }
      var ges = (parseInt((pm.value||'0'),10)||0) * p.epp;
      phint.textContent = p.epp.toLocaleString('de-DE') + ' ' + p.ehl + ' je Packung · Gesamt: ' + ges.toLocaleString('de-DE') + ' ' + p.ehl;
      phint.style.color='';
    }
    if (pt){ pt.addEventListener('input', pupd); pt.addEventListener('change', pupd); }
    if (pm) pm.addEventListener('input', pupd);

    // Bulk-Modus: Rezeptur -> id
    var rmap = {};
    <?php foreach ($rezepteNeu as $rz): ?>
    rmap[<?= json_encode($rz['_lbl'], JSON_UNESCAPED_UNICODE) ?>] = {id:<?= (int)$rz['id'] ?>, ehl:<?= json_encode($rz['_ehl'], JSON_UNESCAPED_UNICODE) ?>};
    <?php endforeach; ?>
    var rt = document.querySelector('.rezpick-txt'), rh = document.querySelector('.rezpick-id');
    function rsync(){ var r = rmap[(rt.value||'').trim()]; rh.value = r ? r.id : ''; }
    if (rt){ rt.addEventListener('input', rsync); rt.addEventListener('change', rsync); }

    // Modus-Umschalter
    var mf = document.getElementById('modusField');
    var gp = document.querySelector('.modus-produkt'), gb = document.querySelector('.modus-bulk');
    var hinweis = document.getElementById('modusHinweis');
    function setModus(mo){
      mf.value = mo;
      gp.style.display = mo==='produkt' ? '' : 'none';
      gb.style.display = mo==='bulk' ? '' : 'none';
      hinweis.textContent = mo==='bulk'
        ? 'Bulk = nur Kapseln herstellen (Rohstoffe + Leerkapseln), ohne Verpacken/Etikettieren. Die fertigen Kapseln gehen als eigene Bulk-Charge (Lagerbestand) ein und können später zu einem Produkt verpackt werden.'
        : 'Menge = Anzahl Packungen; der Materialbedarf (Rohstoffe/Leerkapseln/Verpackung) skaliert über die Einheiten je Packung des Produkts. Fertigware geht als eigener Lagerbestand ein.';
      (mo==='produkt' ? pt : rt).focus();
    }
    document.querySelectorAll('input[name="modus_ui"]').forEach(function(r){ r.addEventListener('change', function(){ setModus(this.value); }); });
    setModus('produkt'); pupd();
  })();
  </script>
</div>
<?php endif; ?>
<div class="settabs">
  <?php foreach ($TABS as $key => $lbl): ?>
    <a href="?p=produktion&tab=<?= $key ?>" class="<?= $tab===$key?'on':'' ?>"><?= h($lbl) ?><?= $TABCOUNT[$key] ? ' (' . $TABCOUNT[$key] . ')' : '' ?></a>
  <?php endforeach; ?>
</div>

<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="produktion">
  <input type="hidden" name="tab" value="<?= h($tab) ?>">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Kunde, Produkt …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=produktion&tab=<?= h($tab) ?>">zurücksetzen</a><?php endif; ?>
</form>
<?php
if ($tab === 'wartet' && $rows)
    echo '<div class="bx-panel" style="padding:10px 14px;font-size:13px;color:var(--muted)">Diese Aufträge sind noch nicht produzierbar – es fehlt Material. Im jeweiligen Auftrag siehst du, was fehlt, und kannst es bestellen. Sobald alles da ist, wandert der Auftrag automatisch nach „Produktionsbereit".</div>';

bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=produktion&tab=' . $tab . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=produktionsauftrag&id=' . $r['id'],
    'empty'   => match ($tab) {
        'wartet'   => 'Kein Auftrag wartet aktuell auf Material.',
        'erledigt' => 'Noch keine abgeschlossenen Produktionsaufträge.',
        default    => 'Kein Auftrag ist aktuell produktionsbereit.',
    },
]);
?>
<?php if ($rows): ?>
<form id="prodBulk" method="post" class="bx-row" style="gap:10px;margin-top:12px;align-items:center;flex-wrap:wrap"
      onsubmit="if(!document.querySelectorAll('input[name=&quot;pa[]&quot;]:checked').length){alert('Bitte zuerst Aufträge markieren.');return false;}">
  <input type="hidden" name="aktion" value="art_bulk">
  <input type="hidden" name="tab" value="<?= h($tab) ?>">
  <label class="muted" style="font-size:13px;cursor:pointer"><input type="checkbox" onclick="document.querySelectorAll('input[name=&quot;pa[]&quot;]').forEach(function(c){c.checked=this.checked;}.bind(this))"> alle markieren</label>
  <span class="muted" style="font-size:13px">· Markierte umstellen:</span>
  <button class="btn btn-primary btn-sm" type="submit" name="art" value="eigen" data-busy="…">auf Eigenproduktion</button>
  <button class="btn btn-ghost btn-sm" type="submit" name="art" value="fremd" data-busy="…">auf Fremdproduktion (Zukauf)</button>
</form>
<div class="muted" style="font-size:12px;margin-top:6px">Eigenproduktion = wir stellen selbst her (voller Weg mit Rohstoffen/Mischen/Verkapseln). Fremdproduktion = fertige Bulkware zukaufen (verkürzter Weg). Bereits begonnene Aufträge lassen sich nicht mehr umstellen.</div>
<?php endif; ?>
<?php
unset($GLOBALS['bx_stock_cache']);   // Anzeige fertig -> Cache aus (falls danach noch etwas rechnet)
render_footer();
