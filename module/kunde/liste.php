<?php
// Kundenliste – Phase: Stammdaten
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_kunden_if_empty();

$q    = trim($_GET['q']   ?? '');
$sort = $_GET['sort'] ?? 'aktualisiert';
$dir  = $_GET['dir']  ?? 'desc';

$rows = all("SELECT * FROM kunden");

// Marken je Kunde (White-Label) – für Anzeige und Suche
$markenByKunde = [];
foreach (all("SELECT kunde_id, name FROM kunde_marke WHERE name<>'' ORDER BY sort,id") as $m)
    $markenByKunde[(int)$m['kunde_id']][] = (string)$m['name'];

// Suche (Firma, Marke, Ansprechpartner, Ort, Kundennummer, E-Mail)
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle, $markenByKunde) {
        foreach (['firma','ansprechpartner','ort','kundennummer','email'] as $f) {
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        }
        foreach ($markenByKunde[(int)$r['id']] ?? [] as $mn)
            if (mb_strpos(mb_strtolower($mn), $needle) !== false) return true;
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

$statusBadge = fn($r) => (int)$r['gesperrt'] === 1 ? bx_badge('gesperrt', 'err') : bx_badge('aktiv', 'ok');
$datum = fn($r) => h(date('d.m.Y', strtotime($r['aktualisiert'])));

$cols = [
    'kundennummer'    => ['label' => 'Kundennr.', 'sort' => true],
    'firma'           => ['label' => 'Firma', 'sort' => true],
    'marke'           => ['label' => 'Marke', 'sort' => false, 'render' => fn($r) => h(implode(', ', $markenByKunde[(int)$r['id']] ?? []))],
    'ort'             => ['label' => 'Ort', 'sort' => true],
    'ansprechpartner' => ['label' => 'Ansprechpartner', 'sort' => true],
    'gesperrt'        => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
    'aktualisiert'    => ['label' => 'zuletzt geändert', 'sort' => true, 'render' => $datum],
];

render_header('kunden', 'Kunden');
bx_head('Kunden', count($rows) . ' Einträge', bx_btn('Neuer Kunde', '?p=kunde&id=neu', 'primary'));
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="kunden">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Firma, Ort, Ansprechpartner …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=kunden">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=kunden' . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=kunde&id=' . $r['id'],
    'empty'   => 'Keine Kunden gefunden.',
]);
render_footer();
