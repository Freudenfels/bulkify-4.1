<?php
// Rezepturanfragen – Liste
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_anfrage_if_empty();

$DFORM = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig'];
$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'angelegt';
$dir  = $_GET['dir']  ?? 'desc';

$rows = all("SELECT a.*, k.firma AS kunde_firma,
             (SELECT COUNT(*) FROM rezeptur_anfrage_wunsch w WHERE w.anfrage_id=a.id) AS wunsch_anzahl,
             r.nummer AS rezeptur_nr
             FROM rezeptur_anfrage a LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN rezeptur r ON r.id=a.rezeptur_id");
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['nummer','kunde_firma'] as $f) if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);
// Vom Kunden abgelehnte Vorschläge („überarbeiten") immer zuoberst – da muss das Team reagieren.
usort($rows, fn($a, $b) => (($b['status'] === 'ueberarbeiten') <=> ($a['status'] === 'ueberarbeiten')));

$statusBadge = fn($r) => match ($r['status']) {
    'neu'            => bx_badge('neu','info'),
    'in_bearbeitung' => bx_badge('in Bearbeitung','warn'),
    'beantwortet'    => bx_badge('beantwortet','ok'),
    'ueberarbeiten'  => bx_badge('Vorschlag abgelehnt – überarbeiten','err'),
    'abgelehnt'      => bx_badge('abgelehnt','err'),
    default          => bx_badge(status_text($r['status'])),
};

$cols = [
    'nummer'        => ['label'=>'Nummer', 'sort'=>true],
    'kunde_firma'   => ['label'=>'Kunde', 'sort'=>true, 'render'=>fn($r)=> $r['kunde_firma']?h($r['kunde_firma']):'<span class="muted">–</span>'],
    'produktname'   => ['label'=>'Wunsch-Produkt', 'sort'=>true, 'render'=>fn($r)=> !empty($r['produktname'])?h($r['produktname']):'<span class="muted">–</span>'],
    'darreichungsform' => ['label'=>'Form', 'sort'=>true, 'render'=>fn($r)=> h($DFORM[$r['darreichungsform']] ?? $r['darreichungsform'])],
    'wunsch_anzahl' => ['label'=>'Wünsche', 'sort'=>true, 'num'=>true],
    'rezeptur_nr'   => ['label'=>'Rezeptur', 'render'=>fn($r)=> $r['rezeptur_nr']?h($r['rezeptur_nr']):'<span class="muted">–</span>'],
    'status'        => ['label'=>'Status', 'sort'=>true, 'render'=>$statusBadge],
];

render_header('anfragen', 'Rezepturanfragen');
bx_head('Rezepturanfragen', count($rows) . ' Einträge', bx_btn('Neue Anfrage', '?p=anfrage&id=neu', 'primary'));
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="anfragen">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Kunde …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=anfragen">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=anfragen' . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=anfrage&id=' . $r['id'],
    'empty'   => 'Keine Anfragen.',
]);
render_footer();
