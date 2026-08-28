<?php
// Lieferantenliste – gleiches Muster wie Kundenliste
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_lieferanten_if_empty();

$q    = trim($_GET['q']   ?? '');
$sort = $_GET['sort'] ?? 'firma';
$dir  = $_GET['dir']  ?? 'asc';

$rows = all("SELECT * FROM lieferanten");

if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['firma','ansprechpartner','ort','lieferantennummer','email','kategorien'] as $f) {
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        }
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

$statusBadge = fn($r) => (int)$r['gesperrt'] === 1 ? bx_badge('gesperrt', 'err') : bx_badge('aktiv', 'ok');
$sprache = fn($r) => h(strtoupper($r['sprache'] ?? ''));
$kats = fn($r) => $r['kategorien'] ? h(str_replace(',', ', ', $r['kategorien'])) : '<span class="muted">–</span>';

$cols = [
    'lieferantennummer' => ['label' => 'Lief.-Nr.', 'sort' => true],
    'firma'             => ['label' => 'Firma', 'sort' => true],
    'ort'               => ['label' => 'Ort', 'sort' => true],
    'land'              => ['label' => 'Land', 'sort' => true],
    'kategorien'        => ['label' => 'Kategorien', 'render' => $kats],
    'sprache'           => ['label' => 'Sprache', 'sort' => true, 'render' => $sprache],
    'gesperrt'          => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
];

render_header('lieferanten', 'Lieferanten');
bx_head('Lieferanten', count($rows) . ' Einträge', bx_btn('Neuer Lieferant', '?p=lieferant&id=neu', 'primary'));
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="lieferanten">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Firma, Ort, Kategorie …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=lieferanten">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=lieferanten' . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=lieferant&id=' . $r['id'],
    'empty'   => 'Keine Lieferanten gefunden.',
]);
render_footer();
