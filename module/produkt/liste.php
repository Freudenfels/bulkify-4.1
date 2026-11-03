<?php
// Produkt-Liste (SKU = Rezeptur + Verpackung + Kunde)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_produkt_if_empty();

$q    = trim($_GET['q'] ?? '');
// Standard: nach Rezeptur gruppiert (alle Größen/Verpackungen einer Rezeptur untereinander). Ein Klick auf eine
// Spaltenüberschrift sortiert die Liste flach wie bisher.
$sort = $_GET['sort'] ?? 'rezeptur';
$dir  = $_GET['dir']  ?? 'asc';
$gruppiert = $sort === 'rezeptur';

$rows = all("SELECT p.*, k.firma AS kunde_firma, r.name AS rezeptur_name, r.nummer AS rezeptur_nummer, v.name AS verpackung_name
             FROM produkt p
             LEFT JOIN kunden k ON k.id=p.kunde_id
             LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
             LEFT JOIN item v ON v.id=p.verpackung_id");
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['nummer','name','kundenname','synonyme','kunde_firma','rezeptur_name'] as $f) {
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        }
        return false;
    });
}
if ($gruppiert) {
    // Rezeptur (Name, dann Nummer), darin Einheiten/Packung, Verpackung, Nummer. Produkte ohne Rezeptur ans Ende.
    usort($rows, fn($a, $b) =>
        [empty($a['rezeptur_id']), mb_strtolower((string)$a['rezeptur_name']), (string)$a['rezeptur_nummer'], (float)$a['einheiten_pro_packung'], mb_strtolower((string)$a['verpackung_name']), (string)$a['nummer']]
        <=>
        [empty($b['rezeptur_id']), mb_strtolower((string)$b['rezeptur_name']), (string)$b['rezeptur_nummer'], (float)$b['einheiten_pro_packung'], mb_strtolower((string)$b['verpackung_name']), (string)$b['nummer']]);
} else {
    $rows = bx_sort_rows($rows, $sort, $dir);
}

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
    'kunde_firma'     => ['label' => 'Kunde', 'sort' => true, 'render' => fn($r)=> kunde_link($r['kunde_id'] ?? null, $r['kunde_firma'])],
    'rezeptur_name'   => ['label' => 'Rezeptur', 'sort' => true, 'render' => fn($r)=> $dash($r['rezeptur_name'])],
    'verpackung_name' => ['label' => 'Verpackung', 'render' => fn($r)=> $dash($r['verpackung_name'])],
    'einheiten_pro_packung' => ['label' => 'Einh./Pack', 'sort' => true, 'num' => true],
    'status'          => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
    'angelegt'        => ['label' => 'Angelegt', 'sort' => true, 'render' => fn($r)=> $r['angelegt'] ? h(fmt_zeit($r['angelegt'], 'd.m.Y H:i')) : '<span class="muted">–</span>'],
];

render_header('produkte', 'Produkte');
bx_head('Produkte', count($rows) . ' Einträge', bx_btn('Kundenpreise', '?p=kundenpreise', 'ghost') . ' ' . bx_btn('Neues Produkt', '?p=produkt&id=neu', 'primary'));
if (isset($_GET['geloescht'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Produkt gelöscht.</div>';
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="produkte">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Produkt, Kunde …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=produkte">zurücksetzen</a><?php endif; ?>
  <?php if (!$gruppiert): ?><a class="btn btn-ghost btn-sm" href="?p=produkte<?= $q !== '' ? '&q=' . h(urlencode($q)) : '' ?>">Nach Rezeptur gruppieren</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=produkte' . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=produkt&id=' . $r['id'],
    'empty'   => 'Keine Produkte gefunden.',
    'group'   => $gruppiert ? fn($r) => !empty($r['rezeptur_id'])
        ? '<span class="muted" style="font-family:var(--mono)">' . h((string)$r['rezeptur_nummer']) . '</span> &nbsp;' . h((string)$r['rezeptur_name'])
        : '<span class="muted">Ohne Rezeptur</span>' : null,
]);
render_footer();
