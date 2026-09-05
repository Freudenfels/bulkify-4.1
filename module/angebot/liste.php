<?php
// Angebots-Liste
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_angebot_if_empty();

$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'aktualisiert';
$dir  = $_GET['dir']  ?? 'desc';

$rows = all("SELECT a.*, k.firma AS kunde_firma, p.name AS produkt_name,
             (SELECT COUNT(*) FROM angebot_staffel s WHERE s.angebot_id=a.id) AS staffel_anzahl
             FROM angebot a LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN produkt p ON p.id=a.produkt_id");
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

// Reiter: Offen (Entwurf + gesendet – noch nicht entschieden) vs. Archiv (bestätigt + abgelehnt).
$grp = fn($status) => in_array($status, ['bestaetigt','abgelehnt'], true) ? 'archiv' : 'offen';
$anzOffen = $anzArchiv = 0;
foreach ($rows as $r) { $grp($r['status']) === 'archiv' ? $anzArchiv++ : $anzOffen++; }
$tab = ($_GET['tab'] ?? 'offen') === 'archiv' ? 'archiv' : 'offen';
// Bei aktiver Suche NICHT nach Reiter filtern – der Treffer kann in Offen oder Archiv liegen; alle zeigen.
if ($q === '') $rows = array_values(array_filter($rows, fn($r) => $grp($r['status']) === $tab));

$statusBadge = function($r) {
    return match ($r['status']) {
        'offen'      => bx_badge('offen','info'),
        'gesendet'   => bx_badge('gesendet'),
        'bestaetigt' => bx_badge('bestätigt','ok'),
        'abgelehnt'  => bx_badge('abgelehnt','err'),
        default      => bx_badge(status_text($r['status'])),
    };
};
$dash = fn($x) => $x ? h($x) : '<span class="muted">–</span>';

$cols = [
    'nummer'         => ['label' => 'Nummer', 'sort' => true],
    'kunde_firma'    => ['label' => 'Kunde', 'sort' => true, 'render' => fn($r)=> kunde_link($r['kunde_id'] ?? null, $r['kunde_firma'])],
    'produkt_name'   => ['label' => 'Produkt', 'sort' => true, 'render' => fn($r)=> $dash($r['produkt_name'])],
    'staffel_anzahl' => ['label' => 'Staffeln', 'sort' => true, 'num' => true],
    'status'         => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
    'pdf'            => ['label' => '', 'render' => fn($r) => $r['kunde_id']
        ? pdf_btn('?p=angebot_pdf&id=' . (int)$r['id'], 'PDF', true, 'Angebot als PDF')
        : '<span class="muted" title="Kein Kunde hinterlegt">–</span>'],
];

$TABS = ['offen' => 'Offen', 'archiv' => 'Archiv'];
$TABCOUNT = ['offen' => $anzOffen, 'archiv' => $anzArchiv];

render_header('angebote', 'Angebote');
bx_head('Angebote', count($rows) . ' Einträge', bx_btn('Neues Angebot', '?p=angebot&id=neu', 'primary'));
?>
<?php if ($q === ''): ?>
<div class="settabs">
  <?php foreach ($TABS as $key => $lbl): ?>
    <a href="?p=angebote&tab=<?= $key ?>" class="<?= $tab === $key ? 'on' : '' ?>"><?= h($lbl) ?><?= $TABCOUNT[$key] ? ' (' . $TABCOUNT[$key] . ')' : '' ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="angebote">
  <input type="hidden" name="tab" value="<?= h($tab) ?>">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Kunde, Produkt …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=angebote&tab=<?= h($tab) ?>">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=angebote&tab=' . $tab . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=angebot&id=' . $r['id'],
    'empty'   => 'Keine Angebote gefunden.',
]);
render_footer();
