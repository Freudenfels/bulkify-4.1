<?php
// Nährstoff-Referenz (NRV) – Liste
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_naehrstoff_if_empty();

$KATN = ['vitamin'=>'Vitamin','mineral'=>'Mineralstoff','sonstige'=>'Sonstige'];
$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'name';
$dir  = $_GET['dir']  ?? 'asc';

$rows = all("SELECT * FROM naehrstoff");
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, fn($r) => mb_strpos(mb_strtolower((string)$r['name']), $needle) !== false);
}
$rows = bx_sort_rows($rows, $sort, $dir);

$nrv = function($r) {
    if ($r['nrv_wert'] === null) return '<span class="muted">keine NRV</span>';
    $w = rtrim(rtrim(number_format((float)$r['nrv_wert'],4,',','.'),'0'),',');
    return h($w . ' ' . $r['einheit']);
};
$typ = fn($r) => (int)$r['ist_nrv'] === 1 ? bx_badge('NRV offiziell','ok') : bx_badge('eigen','info');
$katLabel = fn($r) => h($KATN[$r['kategorie']] ?? $r['kategorie']);

$cols = [
    'name'      => ['label' => 'Nährstoff / Wirkstoff', 'sort' => true],
    'kategorie' => ['label' => 'Kategorie', 'sort' => true, 'render' => $katLabel],
    'nrv_wert'  => ['label' => 'NRV / Tag', 'sort' => true, 'num' => true, 'render' => $nrv],
    'ist_nrv'   => ['label' => 'Typ', 'sort' => true, 'render' => $typ],
];

render_header('naehrstoffe', 'Nährstoffe (NRV)');
bx_head('Nährstoffe / NRV-Referenz', count($rows) . ' Einträge', bx_btn('Neuer Nährstoff', '?p=naehrstoff&id=neu', 'primary'));
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="naehrstoffe">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nährstoff …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=naehrstoffe">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=naehrstoffe' . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=naehrstoff&id=' . $r['id'],
    'empty'   => 'Keine Nährstoffe gefunden.',
]);
render_footer();
