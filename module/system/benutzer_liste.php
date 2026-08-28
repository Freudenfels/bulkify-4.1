<?php
// Benutzerliste (nur Admin – Route ist entsprechend geschützt)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/auth.php';

$ROLLEN = rollen_liste();
$q = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'name';
$dir  = $_GET['dir']  ?? 'asc';

$rows = all("SELECT * FROM benutzer");
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, fn($r) => mb_strpos(mb_strtolower($r['name'].' '.$r['email']), $needle) !== false);
}
$rows = bx_sort_rows($rows, $sort, $dir);

$rollenText = function ($r) use ($ROLLEN) {
    $set = array_filter(array_map('trim', explode(',', (string)$r['rollen'])));
    if (!$set) return '<span class="muted">keine</span>';
    return h(implode(' · ', array_map(fn($x) => $ROLLEN[$x] ?? $x, $set)));
};

$cols = [
    'name'  => ['label' => 'Name', 'sort' => true],
    'email' => ['label' => 'E-Mail', 'sort' => true],
    'rollen'=> ['label' => 'Rollen', 'render' => $rollenText],
    'aktiv' => ['label' => 'Status', 'sort' => true, 'render' => fn($r) => (int)$r['aktiv'] === 1 ? bx_badge('aktiv','ok') : bx_badge('gesperrt','err')],
    'letzter_login' => ['label' => 'Letzter Login', 'sort' => true, 'render' => fn($r) => $r['letzter_login'] ? h(fmt_zeit($r['letzter_login'])) : '<span class="muted">nie</span>'],
];

render_header('benutzer', 'Benutzer');
bx_head('Benutzer', count($rows) . ' Mitarbeiter', bx_btn('Neuer Benutzer', '?p=benutzer_detail&id=neu', 'primary'));
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="benutzer">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Name, E-Mail …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=benutzer">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=benutzer' . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=benutzer_detail&id=' . $r['id'],
    'empty'   => 'Noch keine Benutzer.',
]);
render_footer();
