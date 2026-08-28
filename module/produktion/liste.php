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

// Alle Produktionsaufträge laden (inkl. Fortschritt + nächste Station), Bereitschaft je Auftrag bestimmen.
$alle = all("SELECT pa.*, k.firma AS kunde_firma, p.name AS produkt_name,
             (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id) AS n_total,
             (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=1) AS n_done,
             (SELECT station FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=0 ORDER BY s.sort LIMIT 1) AS naechste_station
             FROM produktionsauftrag pa
             LEFT JOIN kunden k ON k.id=pa.kunde_id LEFT JOIN produkt p ON p.id=pa.produkt_id");
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
        default    => bx_badge($r['status']),
    };
};

$cols = [
    'prio'         => ['label' => 'Prio', 'sort' => true, 'render' => fn($r)=> prio_badge((int)($r['prio'] ?? 2))],
    'bereit'       => ['label' => 'Bereit', 'render' => fn($r)=> bereitschaft_badge($r['_bereit'] ?? '')],
    'nummer'       => ['label' => 'Nummer', 'sort' => true],
    'kunde_firma'  => ['label' => 'Kunde', 'sort' => true, 'render' => fn($r)=> $r['kunde_firma']?h($r['kunde_firma']):'<span class="muted">–</span>'],
    'produkt_name' => ['label' => 'Produkt', 'render' => fn($r)=> $r['produkt_name']?h($r['produkt_name']):'<span class="muted">–</span>'],
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

render_header('produktion', 'Produktion');
bx_head('Produktion', count($rows) . ' ' . $sub[$tab]);
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
render_footer();
