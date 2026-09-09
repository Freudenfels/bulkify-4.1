<?php
// Auftrags-Liste (Auftragsbestätigungen)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'angelegt';
$dir  = $_GET['dir']  ?? 'desc';
$tab  = $_GET['tab']  ?? 'offen';
if (!in_array($tab, ['offen', 'abgeschlossen'], true)) $tab = 'offen';

$alle = all("SELECT a.*, k.firma AS kunde_firma, p.name AS produkt_name,
             (SELECT nummer FROM beleg b WHERE b.auftrag_id=a.id AND b.typ='rechnung' LIMIT 1) AS rechnung_nr
             FROM auftrag a LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN produkt p ON p.id=a.produkt_id");
// Abgeschlossen = versendet; offen = alles andere (offen, in Produktion, versandbereit).
$istAbg   = fn($r) => ($r['status'] ?? '') === 'versendet';
$anzOffen = count(array_filter($alle, fn($r) => !$istAbg($r)));
$anzAbg   = count(array_filter($alle, $istAbg));
$rows     = $tab === 'abgeschlossen' ? array_filter($alle, $istAbg) : array_filter($alle, fn($r) => !$istAbg($r));
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['nummer','kunde_firma','produkt_name'] as $f) {
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        }
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

$statusBadge = function($r) {
    return match ($r['status']) {
        'offen'         => bx_badge('offen','info'),
        'in_produktion' => bx_badge('in Produktion','warn'),
        'erledigt'      => bx_badge('versandbereit','info'),
        'versendet'     => bx_badge('versendet','ok'),
        default         => bx_badge(status_text($r['status'])),
    };
};
$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';

$cols = [
    'nummer'       => ['label' => 'Nummer', 'sort' => true],
    'kunde_firma'  => ['label' => 'Kunde', 'sort' => true, 'render' => fn($r)=> kunde_link($r['kunde_id'] ?? null, $r['kunde_firma'])],
    'produkt_name' => ['label' => 'Produkt', 'render' => fn($r)=> $r['produkt_name'] ? h($r['produkt_name']) : '<span class="muted">–</span>'],
    'menge'        => ['label' => 'Menge', 'sort' => true, 'num' => true],
    'gesamt_netto' => ['label' => 'Netto', 'sort' => true, 'num' => true, 'render' => fn($r)=> $eur($r['gesamt_netto'])],
    'rechnung_nr'  => ['label' => 'Rechnung', 'render' => fn($r)=> $r['rechnung_nr'] ? h($r['rechnung_nr']) : '<span class="muted">–</span>'],
    'status'       => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
];

$TABS = ['offen' => 'Offen', 'abgeschlossen' => 'Abgeschlossen'];
$TABCOUNT = ['offen' => $anzOffen, 'abgeschlossen' => $anzAbg];

render_header('auftraege', 'Aufträge');
bx_head('Aufträge', count($rows) . ' ' . ($tab === 'abgeschlossen' ? 'abgeschlossene (versendet)' : 'offene'));
?>
<div class="settabs">
  <?php foreach ($TABS as $key => $lbl): ?>
    <a href="?p=auftraege&tab=<?= $key ?>" class="<?= $tab===$key?'on':'' ?>"><?= h($lbl) ?><?= $TABCOUNT[$key] ? ' (' . $TABCOUNT[$key] . ')' : '' ?></a>
  <?php endforeach; ?>
</div>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="auftraege">
  <input type="hidden" name="tab" value="<?= h($tab) ?>">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Kunde, Produkt …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=auftraege&tab=<?= h($tab) ?>">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=auftraege&tab=' . $tab . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=auftrag&id=' . $r['id'],
    'empty'   => $tab === 'abgeschlossen'
        ? 'Noch keine abgeschlossenen (versendeten) Aufträge.'
        : 'Kein offener Auftrag – entstehen automatisch aus bestätigten Angeboten.',
]);
render_footer();
