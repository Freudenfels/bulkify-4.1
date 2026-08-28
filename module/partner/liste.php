<?php
// Partnerliste (Hybrid: Kunde + Lieferant)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_partner_if_empty();

$q    = trim($_GET['q']   ?? '');
$sort = $_GET['sort'] ?? 'firma';
$dir  = $_GET['dir']  ?? 'asc';

$rows = all("SELECT p.*, (SELECT COUNT(*) FROM partner_subkunde s WHERE s.partner_id=p.id) AS sub_anzahl FROM partner p");

if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['firma','ansprechpartner','ort','partnernummer','email'] as $f) {
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        }
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

$statusBadge = fn($r) => (int)$r['gesperrt'] === 1 ? bx_badge('gesperrt', 'err') : bx_badge('aktiv', 'ok');
$sprache = fn($r) => h(strtoupper($r['sprache'] ?? ''));
$subs = fn($r) => (int)$r['sub_anzahl'] > 0 ? h($r['sub_anzahl']) : '<span class="muted">0</span>';

$cols = [
    'partnernummer' => ['label' => 'Partner-Nr.', 'sort' => true],
    'firma'         => ['label' => 'Firma', 'sort' => true],
    'ort'           => ['label' => 'Ort', 'sort' => true],
    'land'          => ['label' => 'Land', 'sort' => true],
    'sub_anzahl'    => ['label' => 'SubKunden', 'sort' => true, 'num' => true, 'render' => $subs],
    'sprache'       => ['label' => 'Sprache', 'sort' => true, 'render' => $sprache],
    'gesperrt'      => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
];

render_header('partner', 'Partner');
bx_head('Partner', count($rows) . ' Einträge', bx_btn('Neuer Partner', '?p=partner_detail&id=neu', 'primary'));
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="partner">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Firma, Ort, Ansprechpartner …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=partner">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=partner' . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=partner_detail&id=' . $r['id'],
    'empty'   => 'Keine Partner gefunden.',
]);
render_footer();
