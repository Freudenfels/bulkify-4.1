<?php
// Lieferantenliste – gleiches Muster wie Kundenliste
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_lieferanten_if_empty();

$q    = trim($_GET['q']   ?? '');
$sort = $_GET['sort'] ?? 'firma';
$dir  = $_GET['dir']  ?? 'asc';

$rows = all("SELECT * FROM lieferanten");

if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['firma','ansprechpartner','ort','lieferantennummer','email','kategorien'] as $f) {
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        }
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

$statusBadge = fn($r) => (int)$r['gesperrt'] === 1 ? bx_badge('gesperrt', 'err') : bx_badge('aktiv', 'ok');
$sprache = fn($r) => h(strtoupper($r['sprache'] ?? ''));
$kats = fn($r) => $r['kategorien'] ? h(str_replace(',', ', ', $r['kategorien'])) : '<span class="muted">–</span>';

// Offene Katalog-Zeilen je Lieferant (vom Lieferanten hinzugefügt, warten auf unsere Prüfung).
$katMap = [];
foreach (all("SELECT lieferant_id, COUNT(*) AS n FROM lieferant_katalog WHERE status='neu' GROUP BY lieferant_id") as $kr) {
    $katMap[(int)$kr['lieferant_id']] = (int)$kr['n'];
}
$katOffenGesamt = array_sum($katMap);
// Badge verlinkt direkt in den Katalog-Reiter des Lieferanten; stopPropagation, damit nicht der
// Zeilen-Klick (Übersicht) dazwischenfunkt.
$katCol = function($r) use ($katMap) {
    $n = (int)($katMap[(int)$r['id']] ?? 0);
    return $n > 0
        ? '<a href="?p=lieferant&id=' . (int)$r['id'] . '#katalog" onclick="event.stopPropagation()" style="text-decoration:none">' . bx_badge($n . ' zu prüfen', 'warn') . '</a>'
        : '<span class="muted">–</span>';
};

$cols = [
    'lieferantennummer' => ['label' => 'Lief.-Nr.', 'sort' => true],
    'firma'             => ['label' => 'Firma', 'sort' => true],
    'ort'               => ['label' => 'Ort', 'sort' => true],
    'land'              => ['label' => 'Land', 'sort' => true],
    'kategorien'        => ['label' => 'Kategorien', 'render' => $kats],
    'katalog'           => ['label' => 'Zu prüfen', 'render' => $katCol],
    'sprache'           => ['label' => 'Sprache', 'sort' => true, 'render' => $sprache],
    'gesperrt'          => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
];

render_header('lieferanten', 'Lieferanten');
bx_head('Lieferanten', count($rows) . ' Einträge', bx_btn('Neuer Lieferant', '?p=lieferant&id=neu', 'primary'));
?>
<?php if ($katOffenGesamt > 0): ?>
<div class="bx-panel" style="border-color:#e6c4c0;background:rgba(230,196,192,.12);padding:12px 16px">
  <strong><?= (int)$katOffenGesamt ?></strong> Katalog-Eintrag/-Einträge von Lieferanten warten auf Prüfung.
  Öffne den betreffenden Lieferanten und den Reiter <strong>Katalog</strong> (Spalte „Zu prüfen").
</div>
<?php endif; ?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="lieferanten">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Firma, Ort, Kategorie …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=lieferanten">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=lieferanten' . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=lieferant&id=' . $r['id'],
    'empty'   => 'Keine Lieferanten gefunden.',
]);
render_footer();
