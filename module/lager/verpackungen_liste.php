<?php
// Verpackungen – Liste (Items mit kategorie=verpackung)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_verpackung_if_empty();
seed_behaelter_kapazitaet();   // Standard-Behälter (PET/Glas) + Kapsel-Fassung (Herstellerwerte, einmalig)
seed_etikett_preise();         // Etiketten-EK je Gebinde (Labelisten, Stand Juni 2026), einmalig

$VART = ['dose'=>'Dose','flasche'=>'Flasche','blister'=>'Blister','beutel'=>'Beutel/Doypack','stick'=>'Stick','karton'=>'Karton','etikett'=>'Etikett'];
$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'name';
$dir  = $_GET['dir']  ?? 'asc';

$rows = all("SELECT * FROM item WHERE kategorie='verpackung'");
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['name','artikelnummer','material','farbe'] as $f) {
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        }
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

$preis = fn($r) => number_format((float)$r['ek_preis'], 2, ',', '.') . ' €';
$statusBadge = fn($r) => (int)$r['gesperrt'] === 1 ? bx_badge('gesperrt','err') : bx_badge('aktiv','ok');
$vol = fn($r) => $r['volumen_ml'] !== null ? rtrim(rtrim(number_format((float)$r['volumen_ml'],2,',','.'),'0'),',').' ml' : '<span class="muted">–</span>';

$cols = [
    'artikelnummer' => ['label' => 'VP-Nr.', 'sort' => true],
    'name'          => ['label' => 'Name', 'sort' => true],
    'verpackungsart'=> ['label' => 'Art', 'sort' => true, 'render' => fn($r)=> h($VART[$r['verpackungsart']] ?? $r['verpackungsart'])],
    'material'      => ['label' => 'Material', 'sort' => true],
    'volumen_ml'    => ['label' => 'Volumen', 'sort' => true, 'num' => true, 'render' => $vol],
    'ek_preis'      => ['label' => 'EK-Preis', 'sort' => true, 'num' => true, 'render' => $preis],
    'gesperrt'      => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
];

render_header('verpackungen', 'Verpackungen');
bx_head('Verpackungen', count($rows) . ' Einträge', bx_btn('Neue Verpackung', '?p=verpackung&id=neu', 'primary'));
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="verpackungen">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Name, Material, Farbe …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=verpackungen">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=verpackungen' . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=verpackung&id=' . $r['id'],
    'empty'   => 'Keine Verpackungen gefunden.',
]);
render_footer();
