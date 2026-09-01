<?php
// Produkt-Liste (SKU = Rezeptur + Verpackung + Kunde)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_produkt_if_empty();

$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'aktualisiert';
$dir  = $_GET['dir']  ?? 'desc';

$rows = all("SELECT p.*, k.firma AS kunde_firma, r.name AS rezeptur_name, v.name AS verpackung_name
             FROM produkt p
             LEFT JOIN kunden k ON k.id=p.kunde_id
             LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
             LEFT JOIN item v ON v.id=p.verpackung_id");
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['nummer','name','kunde_firma','rezeptur_name'] as $f) {
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        }
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

$statusBadge = function($r) {
    return match ($r['status']) {
        'aktiv'   => bx_badge('aktiv','ok'),
        'inaktiv' => bx_badge('inaktiv','warn'),
        'entwurf' => bx_badge('Entwurf'),
        default   => bx_badge(status_text($r['status'])),
    };
};
$dash = fn($x) => $x ? h($x) : '<span class="muted">–</span>';

$cols = [
    'nummer'          => ['label' => 'Nummer', 'sort' => true],
    'name'            => ['label' => 'Produkt', 'sort' => true],
    'kunde_firma'     => ['label' => 'Kunde', 'sort' => true, 'render' => fn($r)=> $dash($r['kunde_firma'])],
    'rezeptur_name'   => ['label' => 'Rezeptur', 'sort' => true, 'render' => fn($r)=> $dash($r['rezeptur_name'])],
    'verpackung_name' => ['label' => 'Verpackung', 'render' => fn($r)=> $dash($r['verpackung_name'])],
    'einheiten_pro_packung' => ['label' => 'Einh./Pack', 'sort' => true, 'num' => true],
    'status'          => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
];

render_header('produkte', 'Produkte');
bx_head('Produkte', count($rows) . ' Einträge', bx_btn('Neues Produkt', '?p=produkt&id=neu', 'primary'));
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="produkte">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Produkt, Kunde …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=produkte">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=produkte' . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=produkt&id=' . $r['id'],
    'empty'   => 'Keine Produkte gefunden.',
]);
render_footer();
