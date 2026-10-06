<?php
// Einkauf – „Bestellt": einfache Positionsliste von allem, was bestellt wurde (Produkt, Menge, Lieferant,
// Datum, Status). Klick auf eine Zeile öffnet die Bestellung mit allen weiteren Infos.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'bestelldatum';
$dir  = $_GET['dir']  ?? 'desc';
$archiv = ($_GET['archiv'] ?? '') === '1';   // Archiv = gelieferte Bestellungen

// Lieferanten mit Portal-Zugang (aktiver Benutzer): deren Bestellungen „warten auf Bestätigung", bis der
// Lieferant im Portal bestätigt. Ohne Zugang = extern (nur erfasst). Einmal laden.
$zugangIds = [];
foreach (all("SELECT DISTINCT lieferant_id FROM benutzer WHERE lieferant_id IS NOT NULL AND aktiv=1") as $z) $zugangIds[(int)$z['lieferant_id']] = true;

$wo = $archiv ? "WHERE b.status='geliefert'" : "WHERE b.status<>'geliefert'";
// Eine Zeile je Bestellposition (Produkt + Menge), mit den Kopfdaten der Bestellung.
$rows = all("SELECT bp.id AS pos_id, bp.menge, bp.einheit, bp.bestellung_id,
             COALESCE(NULLIF(bp.bezeichnung,''), i.name, '–') AS produkt,
             b.nummer, b.status, b.bestelldatum, b.angelegt, COALESCE(b.bestaetigt,0) AS bestaetigt,
             b.lieferant_id, b.eta_geplant, l.firma AS lieferant_firma
             FROM bestellung_position bp
             JOIN bestellung b ON b.id=bp.bestellung_id
             LEFT JOIN item i ON i.id=bp.item_id
             LEFT JOIN lieferanten l ON l.id=b.lieferant_id
             $wo");
$anzArchiv = (int) scalar("SELECT COUNT(*) FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id WHERE b.status='geliefert'");

if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['produkt','lieferant_firma','nummer'] as $f) if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

$mfmt = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
// Status je Zeile: geliefert > bestätigt > wartet auf Bestätigung (Portal-Lieferant, noch nicht bestätigt) > bestellt (erfasst) > Entwurf.
$statusBadge = function($r) use ($zugangIds) {
    $st = (string)$r['status'];
    if ($st === 'geliefert')             return bx_badge('geliefert','ok');
    if ((int)$r['bestaetigt'] === 1 || $st === 'bestaetigt') return bx_badge('bestätigt','ok');
    $portal = !empty($r['lieferant_id']) && isset($zugangIds[(int)$r['lieferant_id']]);
    if ($portal && in_array($st, ['gesendet','bestellt'], true)) return bx_badge('wartet auf Bestätigung','warn');
    if ($st === 'offen')                 return bx_badge('Entwurf','info');
    return bx_badge('bestellt','info');   // extern / Lieferant ohne Zugang: nur erfasst
};

$cols = [
    'produkt'          => ['label'=>'Produkt', 'sort'=>true, 'render'=>fn($r)=> h((string)$r['produkt'])],
    'menge'            => ['label'=>'Menge', 'sort'=>true, 'num'=>true, 'render'=>fn($r)=> $mfmt($r['menge']) . ' ' . h((string)$r['einheit'])],
    'lieferant_firma'  => ['label'=>'Lieferant', 'sort'=>true, 'render'=>fn($r)=> $r['lieferant_firma'] ? h($r['lieferant_firma']) : '<span class="muted">extern</span>'],
    'bestelldatum'     => ['label'=>'Bestellt am', 'sort'=>true, 'render'=>fn($r)=> !empty($r['bestelldatum']) ? h(date('d.m.Y', strtotime($r['bestelldatum']))) : '<span class="muted">–</span>'],
    'nummer'           => ['label'=>'Bestellung', 'sort'=>true],
    'status'           => ['label'=>'Status', 'sort'=>true, 'render'=>$statusBadge],
];

render_header('einkauf', $archiv ? 'Bestellt – Archiv' : 'Bestellt');
bx_head($archiv ? 'Bestellt – Archiv' : 'Bestellt',
        count($rows) . ($archiv ? ' gelieferte Positionen' : ' bestellte Positionen (unterwegs / wartet auf Bestätigung)'),
        bx_btn('Zu „Bestellen"', '?p=einkaufsliste', 'ghost') . ' ' . bx_btn('Neue Bestellung', '?p=bestellung&id=neu', 'ghost'));
if (isset($_GET['vorsorglich'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Vorsorgliche Bestellung angelegt – sie steht jetzt hier unter „Bestellt".</div>';
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="einkauf">
  <?php if ($archiv): ?><input type="hidden" name="archiv" value="1"><?php endif; ?>
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Produkt, Lieferant, Bestellnummer …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <span style="flex:1"></span>
  <?php if ($archiv): ?>
    <a class="btn btn-ghost btn-sm" href="?p=einkauf">Zurück zu laufenden</a>
  <?php else: ?>
    <a class="btn btn-ghost btn-sm" href="?p=einkauf&archiv=1">Archiv (geliefert)<?= $anzArchiv ? ' (' . $anzArchiv . ')' : '' ?></a>
  <?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=einkauf' . ($archiv ? '&archiv=1' : '') . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=bestellung&id=' . $r['bestellung_id'],
    'empty'   => 'Noch nichts bestellt.',
]);
render_footer();
