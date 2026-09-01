<?php
// Rechnungen (Belege typ=rechnung) – Liste
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'angelegt';
$dir  = $_GET['dir']  ?? 'desc';

$rows = all("SELECT b.*, k.firma AS kunde_firma
             FROM beleg b LEFT JOIN kunden k ON k.id=b.kunde_id
             WHERE b.typ='rechnung'");
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['nummer','kunde_firma'] as $f) {
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        }
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

$offen = 0.0;
foreach ($rows as $r) if ($r['status'] === 'offen') $offen += (float)$r['brutto'];

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$datum = fn($r) => $r['datum'] ? h(date('d.m.Y', strtotime($r['datum']))) : '';
$statusBadge = fn($r) => match ($r['status']) {
    'bezahlt'     => bx_badge('bezahlt','ok'),
    'teilbezahlt' => bx_badge('teilbezahlt','info'),
    'offen'       => bx_badge('offen','warn'),
    'storniert'   => bx_badge('storniert','err'),
    default       => bx_badge(status_text($r['status'])),
};

$cols = [
    'nummer'      => ['label' => 'Nummer', 'sort' => true],
    'datum'       => ['label' => 'Datum', 'sort' => true, 'render' => $datum],
    'kunde_firma' => ['label' => 'Kunde', 'sort' => true, 'render' => fn($r)=> $r['kunde_firma'] ? h($r['kunde_firma']) : '<span class="muted">–</span>'],
    'netto'       => ['label' => 'Netto', 'sort' => true, 'num' => true, 'render' => fn($r)=> $eur($r['netto'])],
    'brutto'      => ['label' => 'Brutto', 'sort' => true, 'num' => true, 'render' => fn($r)=> $eur($r['brutto'])],
    'status'      => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
];

render_header('rechnungen', 'Rechnungen');
bx_head('Rechnungen', count($rows) . ' Einträge · offene Posten: ' . $eur($offen));
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="rechnungen">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Kunde …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=rechnungen">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=rechnungen' . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=rechnung&id=' . $r['id'],
    'empty'   => 'Noch keine Rechnungen – entstehen automatisch mit dem Auftrag.',
]);
render_footer();
