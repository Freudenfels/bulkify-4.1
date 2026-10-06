<?php
// Rechnungen (Belege typ=rechnung) – Liste
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'angelegt';
$dir  = $_GET['dir']  ?? 'desc';
// Nur Dienstleistungs-Rechnungen (DR-)? Über die Route ?p=dl_rechnungen oder ?art=dienstleistung.
$nurDL = (($p ?? '') === 'dl_rechnungen') || (($_GET['art'] ?? '') === 'dienstleistung');
$route = $nurDL ? 'dl_rechnungen' : 'rechnungen';

$rows = all("SELECT b.*, k.firma AS kunde_firma
             FROM beleg b LEFT JOIN kunden k ON k.id=b.kunde_id
             WHERE b.typ IN ('rechnung','gutschrift')"
            . ($nurDL ? " AND b.kategorie='dienstleistung'" : ""));
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['nummer','kunde_firma'] as $f) {
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        }
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

$offen = 0.0;
foreach ($rows as $r) if ($r['status'] === 'offen') $offen += (float)$r['brutto'];

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$datum = fn($r) => $r['datum'] ? h(date('d.m.Y', strtotime($r['datum']))) : '';
$statusBadge = fn($r) => match ($r['status']) {
    'bezahlt'     => bx_badge('bezahlt','ok'),
    'teilbezahlt' => bx_badge('teilbezahlt','info'),
    'offen'       => bx_badge('offen','warn'),
    'storniert'   => bx_badge('storniert','err'),
    default       => bx_badge(status_text($r['status'])),
};

$cols = [
    'nummer'      => ['label' => 'Nummer', 'sort' => true],
    'art'         => ['label' => 'Art', 'render' => fn($r)=> ($r['typ'] ?? '')==='gutschrift' ? bx_badge('Gutschrift','info') : (($r['kategorie'] ?? 'produkt')==='dienstleistung' ? bx_badge('Dienstleistung','info') : 'Rechnung')],
    'datum'       => ['label' => 'Datum', 'sort' => true, 'render' => $datum],
    'kunde_firma' => ['label' => 'Kunde', 'sort' => true, 'render' => fn($r)=> kunde_link($r['kunde_id'] ?? null, $r['kunde_firma'])],
    'netto'       => ['label' => 'Netto', 'sort' => true, 'num' => true, 'render' => fn($r)=> $eur($r['netto'])],
    'brutto'      => ['label' => 'Brutto', 'sort' => true, 'num' => true, 'render' => fn($r)=> $eur($r['brutto'])],
    'status'      => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
];

render_header($route, $nurDL ? 'Dienstleistungs-Rechnungen' : 'Rechnungen');
bx_head($nurDL ? 'Dienstleistungs-Rechnungen (DR-)' : 'Rechnungen', count($rows) . ' Einträge · offene Posten: ' . $eur($offen));
if (isset($_GET['verrechnet'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['verrechnet'] . ' Rechnung(en) storniert &amp; verrechnet (Gutschrift erzeugt).</div>';
?>
<div class="bx-row" style="gap:8px;margin:0 0 12px">
  <a class="btn btn-sm <?= $nurDL ? 'btn-ghost' : 'btn-primary' ?>" href="?p=rechnungen">Alle</a>
  <a class="btn btn-sm <?= $nurDL ? 'btn-primary' : 'btn-ghost' ?>" href="?p=dl_rechnungen">Dienstleistungen</a>
</div>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="<?= h($route) ?>">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Kunde …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=<?= h($route) ?>">zurücksetzen</a><?php endif; ?>
  <span style="flex:1"></span>
  <a class="btn btn-ghost btn-sm" href="?p=gutschrift_neu">Storno-Rechnung</a>
  <a class="btn btn-ghost btn-sm" href="?p=rechnung_import">Alt-Rechnungen importieren</a>
  <a class="btn btn-ghost btn-sm" href="?p=auftrag_import">Auftrag aus Angebot importieren</a>
  <a class="btn btn-primary btn-sm" href="?p=rechnung_frei">+ Rechnung erstellen</a>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=' . $route . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=rechnung&id=' . $r['id'],
    'empty'   => $nurDL ? 'Noch keine Dienstleistungs-Rechnungen – diese entstehen aus einem DL-Auftrag (Dienstleistungen-Modul → „Rechnung in der Buchhaltung erstellen").' : 'Noch keine Rechnungen – mit „+ Rechnung erstellen" (KI-gestützt) oder automatisch aus einem Auftrag.',
]);
render_footer();
