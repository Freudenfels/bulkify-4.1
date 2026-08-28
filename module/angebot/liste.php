<?php
// Angebots-Liste
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_angebot_if_empty();

$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'aktualisiert';
$dir  = $_GET['dir']  ?? 'desc';

$rows = all("SELECT a.*, k.firma AS kunde_firma, p.name AS produkt_name,
             (SELECT COUNT(*) FROM angebot_staffel s WHERE s.angebot_id=a.id) AS staffel_anzahl
             FROM angebot a LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN produkt p ON p.id=a.produkt_id");
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['nummer','kunde_firma','produkt_name'] as $f) {
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        }
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

$statusBadge = function($r) {
    return match ($r['status']) {
        'offen'      => bx_badge('offen','info'),
        'gesendet'   => bx_badge('gesendet'),
        'bestaetigt' => bx_badge('bestätigt','ok'),
        'abgelehnt'  => bx_badge('abgelehnt','err'),
        default      => bx_badge($r['status']),
    };
};
$dash = fn($x) => $x ? h($x) : '<span class="muted">–</span>';

$cols = [
    'nummer'         => ['label' => 'Nummer', 'sort' => true],
    'kunde_firma'    => ['label' => 'Kunde', 'sort' => true, 'render' => fn($r)=> $dash($r['kunde_firma'])],
    'produkt_name'   => ['label' => 'Produkt', 'sort' => true, 'render' => fn($r)=> $dash($r['produkt_name'])],
    'staffel_anzahl' => ['label' => 'Staffeln', 'sort' => true, 'num' => true],
    'status'         => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
];

render_header('angebote', 'Angebote');
bx_head('Angebote', count($rows) . ' Einträge', bx_btn('Neues Angebot', '?p=angebot&id=neu', 'primary'));
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="angebote">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Kunde, Produkt …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=angebote">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=angebote' . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=angebot&id=' . $r['id'],
    'empty'   => 'Keine Angebote gefunden.',
]);
render_footer();
