<?php
// Produktions-Liste (Produktionsaufträge)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'prio';
$dir  = $_GET['dir']  ?? 'asc';
$zeigeErledigt = ($_GET['erledigt'] ?? '') === '1';   // abgeschlossene standardmäßig ausblenden

$wo = $zeigeErledigt ? '' : "WHERE pa.status <> 'erledigt'";
$rows = all("SELECT pa.*, k.firma AS kunde_firma, p.name AS produkt_name,
             (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id) AS n_total,
             (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=1) AS n_done,
             (SELECT station FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=0 ORDER BY s.sort LIMIT 1) AS naechste_station
             FROM produktionsauftrag pa
             LEFT JOIN kunden k ON k.id=pa.kunde_id LEFT JOIN produkt p ON p.id=pa.produkt_id $wo");
$anzErledigt = (int) scalar("SELECT COUNT(*) FROM produktionsauftrag WHERE status='erledigt'");
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['nummer','kunde_firma','produkt_name'] as $f) {
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        }
        return false;
    });
}
// Baustein 3: Produktionsbereitschaft je Auftrag
$nurBereit = ($_GET['bereit'] ?? '') === '1';
foreach ($rows as &$r) { $r['_bereit'] = produktion_bereitschaft((int)$r['id'])['status']; } unset($r);
if ($nurBereit) $rows = array_filter($rows, fn($r) => ($r['_bereit'] ?? '') === 'bereit');
$rows = bx_sort_rows($rows, $sort, $dir);

$statusBadge = function($r) {
    return match ($r['status']) {
        'offen'    => bx_badge('offen','info'),
        'laufend'  => bx_badge('läuft','warn'),
        'erledigt' => bx_badge('fertig','ok'),
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

render_header('produktion', 'Produktion');
bx_head('Produktion', count($rows) . ' ' . ($zeigeErledigt ? 'Produktionsaufträge (inkl. abgeschlossene)' : 'offene Produktionsaufträge'));
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="produktion">
  <?php if ($zeigeErledigt): ?><input type="hidden" name="erledigt" value="1"><?php endif; ?>
  <?php if ($nurBereit): ?><input type="hidden" name="bereit" value="1"><?php endif; ?>
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Kunde, Produkt …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=produktion<?= $zeigeErledigt ? '&erledigt=1' : '' ?><?= $nurBereit ? '&bereit=1' : '' ?>">zurücksetzen</a><?php endif; ?>
  <span style="flex:1"></span>
  <?php $qs = ($q !== '' ? '&q=' . urlencode($q) : '') . ($zeigeErledigt ? '&erledigt=1' : ''); ?>
  <?php if ($nurBereit): ?>
    <a class="btn btn-primary btn-sm" href="?p=produktion<?= $qs ?>">Nur produktionsbereite ✕</a>
  <?php else: ?>
    <a class="btn btn-ghost btn-sm" href="?p=produktion&bereit=1<?= $qs ?>">Nur produktionsbereite</a>
  <?php endif; ?>
  <?php if ($zeigeErledigt): ?>
    <a class="btn btn-ghost btn-sm" href="?p=produktion<?= $nurBereit ? '&bereit=1' : '' ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">Nur offene zeigen</a>
  <?php else: ?>
    <a class="btn btn-ghost btn-sm" href="?p=produktion&erledigt=1<?= $nurBereit ? '&bereit=1' : '' ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">Abgeschlossene einblenden<?= $anzErledigt ? ' (' . $anzErledigt . ')' : '' ?></a>
  <?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=produktion' . ($zeigeErledigt ? '&erledigt=1' : '') . ($nurBereit ? '&bereit=1' : '') . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=produktionsauftrag&id=' . $r['id'],
    'empty'   => 'Noch keine Produktionsaufträge – entstehen automatisch aus bestätigten Angeboten.',
]);
render_footer();
