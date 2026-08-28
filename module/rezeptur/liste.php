<?php
// Rezeptur-Liste
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_rezeptur_if_empty();

$DFORM = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig'];
$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'aktualisiert';
$dir  = $_GET['dir']  ?? 'desc';

// Nur „echte" Rezepturen: Hausrezepturen (ohne Kunde) sowie vom Kunden ANGENOMMENE (eingefroren).
// Kundenvorschläge (Entwurf/Vorschlag/abgelehnt) sind noch keine Rezeptur → werden an der Anfrage geführt.
$rows = all("SELECT r.*, k.firma AS kunde_firma,
             (SELECT COUNT(*) FROM rezeptur_zutat z WHERE z.rezeptur_id=r.id) AS zutat_anzahl
             FROM rezeptur r LEFT JOIN kunden k ON k.id=r.kunde_id
             WHERE r.kunde_id IS NULL OR r.status='eingefroren'");
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['nummer','name','kunde_firma'] as $f) {
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        }
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

$statusBadge = function($r) {
    return match ($r['status']) {
        'entwurf'     => bx_badge('Entwurf'),
        'vorschlag'   => bx_badge('Vorschlag','info'),
        'freigegeben' => bx_badge('freigegeben','ok'),
        'eingefroren' => bx_badge('eingefroren','warn'),
        'abgelehnt'   => bx_badge('abgelehnt','err'),
        default       => bx_badge($r['status']),
    };
};

$cols = [
    'nummer'           => ['label' => 'Nummer', 'sort' => true],
    'name'             => ['label' => 'Name', 'sort' => true],
    'kunde_firma'      => ['label' => 'Kunde', 'sort' => true, 'render' => fn($r)=> $r['kunde_firma'] ? h($r['kunde_firma']) : '<span class="muted">–</span>'],
    'darreichungsform' => ['label' => 'Form', 'sort' => true, 'render' => fn($r)=> h($DFORM[$r['darreichungsform']] ?? $r['darreichungsform'])],
    'zutat_anzahl'     => ['label' => 'Zutaten', 'sort' => true, 'num' => true],
    'status'           => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
];

render_header('rezeptur', 'Rezepturen');
bx_head('Rezepturen', count($rows) . ' Einträge', bx_btn('Neue Rezeptur', '?p=rezeptur_detail&id=neu', 'primary'));
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="rezeptur">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Name, Kunde …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=rezeptur">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=rezeptur' . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=rezeptur_detail&id=' . $r['id'],
    'empty'   => 'Keine Rezepturen gefunden.',
]);
render_footer();
