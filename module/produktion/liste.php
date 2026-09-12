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

// Alle Produktionsaufträge laden (inkl. Fortschritt + nächste Station), Bereitschaft je Auftrag bestimmen.
$alle = all("SELECT pa.*, k.firma AS kunde_firma, COALESCE(NULLIF(p.name,''), a.produkt_bezeichnung) AS produkt_name,
             (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id) AS n_total,
             (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=1) AS n_done,
             (SELECT station FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=0 ORDER BY s.sort LIMIT 1) AS naechste_station
             FROM produktionsauftrag pa
             LEFT JOIN kunden k ON k.id=pa.kunde_id LEFT JOIN produkt p ON p.id=pa.produkt_id
             LEFT JOIN auftrag a ON a.id=pa.auftrag_id");
foreach ($alle as &$r) { $r['_bereit'] = produktion_bereitschaft((int)$r['id'])['status']; } unset($r);

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
    'kunde_firma'  => ['label' => 'Kunde', 'sort' => true, 'render' => fn($r)=> kunde_link($r['kunde_id'] ?? null, $r['kunde_firma'])],
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
render_header('produktion', 'Produktion');
bx_head('Produktion', count($rows) . ' ' . $sub[$tab]);
if ($flash) echo '<div class="bx-panel badge-ok" style="padding:8px 12px">' . h($flash) . '</div>';
?>
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
render_footer();
