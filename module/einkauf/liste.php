<?php
// Einkauf – Bestellungen beim Lieferanten (Historie der getätigten/angelegten Bestellungen).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'angelegt';
$dir  = $_GET['dir']  ?? 'desc';
$archiv = ($_GET['archiv'] ?? '') === '1';   // Archiv = gelieferte Bestellungen

$wo = $archiv ? "WHERE b.status='geliefert'" : "WHERE b.status<>'geliefert'";
$rows = all("SELECT b.*, l.firma AS lieferant_firma,
             (SELECT COUNT(*) FROM bestellung_position p WHERE p.bestellung_id=b.id) AS pos_anzahl,
             (SELECT COALESCE(SUM(menge*ek_preis),0) FROM bestellung_position p WHERE p.bestellung_id=b.id) AS summe
             FROM bestellung b LEFT JOIN lieferanten l ON l.id=b.lieferant_id $wo");
$anzArchiv = (int) scalar("SELECT COUNT(*) FROM bestellung WHERE status='geliefert'");
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['nummer','lieferant_firma'] as $f) if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$statusBadge = fn($r) => match ($r['status']) {
    'offen'     => bx_badge('Entwurf','info'),
    'bestellt'  => bx_badge('bestellt','warn'),
    'geliefert' => bx_badge('geliefert','ok'),
    default     => bx_badge(status_text($r['status'])),
};

$cols = [
    'nummer'           => ['label'=>'Nummer', 'sort'=>true],
    'lieferant_firma'  => ['label'=>'Lieferant', 'sort'=>true, 'render'=>fn($r)=> $r['lieferant_firma']?h($r['lieferant_firma']):'<span class="muted">–</span>'],
    'bestelldatum'     => ['label'=>'Bestellt am', 'sort'=>true, 'render'=>fn($r)=> !empty($r['bestelldatum']) ? h(date('d.m.Y', strtotime($r['bestelldatum']))) : '<span class="muted">–</span>'],
    'pos_anzahl'       => ['label'=>'Positionen', 'sort'=>true, 'num'=>true],
    'summe'            => ['label'=>'Summe', 'sort'=>true, 'num'=>true, 'render'=>fn($r)=> $eur($r['summe'])],
    'eta_geplant'      => ['label'=>'Zugesagt', 'sort'=>true, 'render'=>fn($r)=> !empty($r['eta_geplant'])
        ? h(date('d.m.Y', strtotime($r['eta_geplant']))) . ((int)($r['bestaetigt'] ?? 0) === 1 ? '' : '')
        : '<span class="muted" title="Vom Lieferanten noch nicht bestätigt">–</span>'],
    'station'          => ['label'=>'Fortschritt', 'render'=>function($r) {
        $s = (string)($r['station'] ?? ''); if ($s === '') return '<span class="muted">–</span>';
        $alle = bestellung_stationen();
        return h($alle[$s] ?? $s) . '<div class="muted" style="font-size:12px">' . (bestellung_station_index($s)+1) . ' / ' . count($alle) . '</div>';
    }],
    'status'           => ['label'=>'Status', 'sort'=>true, 'render'=>$statusBadge],
    'pdf'              => ['label'=>'', 'render'=>fn($r) => $r['lieferant_id']
        ? pdf_btn('?p=bestellung_pdf&id=' . (int)$r['id'], 'PDF', true, 'Bestellung als PDF')
        : '<span class="muted" title="Kein Lieferant hinterlegt">–</span>'],
];

render_header('einkauf', $archiv ? 'Bestellarchiv' : 'Bestellungen');
bx_head($archiv ? 'Bestellarchiv' : 'Bestellungen',
        count($rows) . ($archiv ? ' gelieferte Bestellungen' : ' laufende Bestellungen (unterwegs / Entwurf)'),
        bx_btn('Neue Bestellung', '?p=bestellung&id=neu', 'ghost'));
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="einkauf">
  <?php if ($archiv): ?><input type="hidden" name="archiv" value="1"><?php endif; ?>
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Lieferant …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <span style="flex:1"></span>
  <?php if ($archiv): ?>
    <a class="btn btn-ghost btn-sm" href="?p=einkauf">Zurück zu laufenden</a>
  <?php else: ?>
    <a class="btn btn-ghost btn-sm" href="?p=einkauf&archiv=1">Bestellarchiv<?= $anzArchiv ? ' (' . $anzArchiv . ')' : '' ?></a>
  <?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=einkauf' . ($archiv ? '&archiv=1' : '') . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=bestellung&id=' . $r['id'],
    'empty'   => 'Noch keine Bestellungen.',
]);
render_footer();
