<?php
// Produktionschargen (CH/CHE) – durchsuchbare Entitaet analog R…/AN… (Spec 16).
// Zeigt die HAUPT-Produktionschargen; Unterchargen (-A/-B) stehen im Detail. Reine Lese-/Suchansicht.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$q    = trim($_GET['q'] ?? '');
$typ  = in_array($_GET['typ'] ?? '', ['intern', 'extern'], true) ? $_GET['typ'] : '';

$w = ['pc.parent_id IS NULL']; $args = [];
if ($q !== '') {
    $like = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $q) . '%';
    $w[] = "(pc.nummer LIKE ? ESCAPE '=' OR pa.nummer LIKE ? ESCAPE '=' OR r.name LIKE ? ESCAPE '=' OR p.name LIKE ? ESCAPE '=' OR EXISTS (SELECT 1 FROM prod_charge_rohstoff x WHERE x.prod_charge_id=pc.id AND x.batch_nr LIKE ? ESCAPE '='))";
    array_push($args, $like, $like, $like, $like, $like);
}
if ($typ !== '') { $w[] = 'pc.typ=?'; $args[] = $typ; }
$where = 'WHERE ' . implode(' AND ', $w);

$rows = all("SELECT pc.*, pa.nummer AS pa_nr, r.name AS rezeptur_name, p.name AS produkt_name,
                    (SELECT COUNT(*) FROM prod_charge s WHERE s.parent_id=pc.id) AS sub_anzahl,
                    (SELECT COUNT(*) FROM prod_charge_rohstoff x WHERE x.prod_charge_id=pc.id) AS roh_anzahl
             FROM prod_charge pc
             LEFT JOIN produktionsauftrag pa ON pa.id=pc.pa_id
             LEFT JOIN rezeptur r ON r.id=pc.rezeptur_id
             LEFT JOIN produkt p ON p.id=pc.produkt_id
             $where ORDER BY pc.id DESC LIMIT 300", $args);

$typBadge = fn($r) => (string)$r['typ'] === 'extern' ? bx_badge('CHE · extern', 'warn') : bx_badge('CH · intern', 'info');
$stBadge  = fn($r) => bx_badge((string)($r['status'] ?: 'offen'), ($r['status'] ?? '') === 'fertig' ? 'ok' : 'info');

$cols = [
    'nummer'  => ['label' => 'Charge-Nr.', 'sort' => true],
    'typ'     => ['label' => 'Typ', 'render' => $typBadge],
    'produkt' => ['label' => 'Rezeptur / Produkt', 'render' => fn($r) => h($r['rezeptur_name'] ?: ($r['produkt_name'] ?: '–')) . ($r['pa_nr'] ? ' <span class="muted" style="font-size:12px">· ' . h($r['pa_nr']) . '</span>' : '')],
    'menge'   => ['label' => 'Menge', 'num' => true, 'render' => fn($r) => $r['menge'] !== null ? h(rtrim(rtrim(number_format((float)$r['menge'], 3, ',', '.'), '0'), ',') . ' ' . ($r['einheit'] ?: '')) : '–'],
    'sub_anzahl' => ['label' => 'Unterchargen', 'num' => true, 'render' => fn($r) => (int)$r['sub_anzahl'] > 0 ? (int)$r['sub_anzahl'] : '<span class="muted">0</span>'],
    'roh_anzahl' => ['label' => 'Rohstoffe', 'num' => true, 'render' => fn($r) => (int)$r['roh_anzahl'] > 0 ? (int)$r['roh_anzahl'] : '<span class="muted">0</span>'],
    'status'  => ['label' => 'Status', 'render' => $stBadge],
    'tag'     => ['label' => 'Tag', 'render' => fn($r) => $r['tag'] ? h(date('d.m.Y', strtotime((string)$r['tag']))) : '<span class="muted">–</span>'],
];

render_header('produktionschargen', 'Produktionschargen');
bx_head('Produktionschargen (CH / CHE)', count($rows) . ' Haupt-Chargen · intern gemischt (CH) oder extern zugekauft (CHE), mit voller Rohstoff-Rückverfolgung');
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="produktionschargen">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Charge-Nr., PA, Rezeptur, Produkt, Rohstoff-Batch …">
  <select name="typ" onchange="this.form.submit()">
    <option value="">Typ: alle</option>
    <option value="intern" <?= $typ === 'intern' ? 'selected' : '' ?>>CH – intern</option>
    <option value="extern" <?= $typ === 'extern' ? 'selected' : '' ?>>CHE – extern</option>
  </select>
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== '' || $typ !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=produktionschargen">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, $rows, [
    'baseUrl' => '?p=produktionschargen' . ($q !== '' ? '&q=' . urlencode($q) : '') . ($typ !== '' ? '&typ=' . $typ : ''),
    'rowUrl'  => fn($r) => '?p=produktionscharge&id=' . (int)$r['id'],
    'empty'   => 'Keine Produktionschargen gefunden. Sie entstehen in der Produktion beim Mischen/Abfüllen.',
]);
render_footer();
