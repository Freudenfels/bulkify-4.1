<?php
// Auftrags-Liste (Auftragsbestätigungen)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'angelegt';
$dir  = $_GET['dir']  ?? 'desc';
$tab  = $_GET['tab']  ?? 'offen';
if (!in_array($tab, ['offen', 'abgeschlossen'], true)) $tab = 'offen';

$alle = all("SELECT a.*, k.firma AS kunde_firma, COALESCE(k.nutzt_fulfillment,0) AS nutzt_fulfillment,
             COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name) AS produkt_name,
             p.rezeptur_id AS rezeptur_id,
             (SELECT nummer FROM beleg b WHERE b.auftrag_id=a.id AND b.typ='rechnung' LIMIT 1) AS rechnung_nr
             FROM auftrag a LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN produkt p ON p.id=a.produkt_id
             WHERE COALESCE(a.kategorie,'produkt') <> 'dienstleistung'");

// Erstauftrag vs. Nachbestellung – berechnet über ALLE Aufträge (nicht nur den aktuellen Reiter).
// Nachbestellung = ein früherer, nicht stornierter Auftrag mit demselben Produkt existiert.
// Erstauftrag wird feiner unterschieden: neue Rezeptur (noch nie gemacht) vs. neues Produkt (Rezeptur bekannt).
$erstProd = []; $erstRez = [];
foreach ($alle as $r) {
    if (($r['status'] ?? '') === 'storniert') continue;
    $pid = (int)($r['produkt_id'] ?? 0); $rid = (int)($r['rezeptur_id'] ?? 0); $aid = (int)$r['id'];
    if ($pid > 0 && (!isset($erstProd[$pid]) || $aid < $erstProd[$pid])) $erstProd[$pid] = $aid;
    if ($rid > 0 && (!isset($erstRez[$rid])  || $aid < $erstRez[$rid]))  $erstRez[$rid]  = $aid;
}
// Key je Auftrag (bulk, ohne Einzelabfragen); Label/Stil kommen aus auftrag_art_meta().
$auftragArt = function($r) use ($erstProd, $erstRez) {
    $pid = (int)($r['produkt_id'] ?? 0); $rid = (int)($r['rezeptur_id'] ?? 0); $aid = (int)$r['id'];
    if ($pid <= 0 && $rid <= 0) return 'none';
    if ($pid > 0 && isset($erstProd[$pid]) && $aid > $erstProd[$pid]) return 'nach';
    if ($rid > 0 && isset($erstRez[$rid]) && $aid > $erstRez[$rid])   return 'neu_prod';
    return 'neu_rez';
};
// Abgeschlossen = versendet; offen = alles andere (offen, in Produktion, versandbereit).
$istAbg   = fn($r) => ($r['status'] ?? '') === 'versendet';
$anzOffen = count(array_filter($alle, fn($r) => !$istAbg($r)));
$anzAbg   = count(array_filter($alle, $istAbg));
// Bei einer Suche über ALLE Aufträge gehen (offen UND abgeschlossen) – sonst findet man einen bereits
// versendeten/abgeschlossenen Auftrag im Standard-Reiter „Offen" nicht. Ohne Suche gilt der Reiter.
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($alle, function($r) use ($needle) {
        foreach (['nummer','kunde_firma','produkt_name'] as $f) {
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        }
        return false;
    });
} else {
    $rows = $tab === 'abgeschlossen' ? array_filter($alle, $istAbg) : array_filter($alle, fn($r) => !$istAbg($r));
}
$rows = bx_sort_rows($rows, $sort, $dir);

$statusBadge = function($r) {
    $ff = !empty($r['nutzt_fulfillment']);   // Fulfillment: eingelagert statt versendet -> „abgeschlossen"
    return match ($r['status']) {
        'offen'         => bx_badge('offen','info'),
        'in_produktion' => bx_badge('in Produktion','warn'),
        'erledigt'      => bx_badge('versandbereit','info'),
        'versendet'     => bx_badge($ff ? 'abgeschlossen' : 'versendet','ok'),
        default         => bx_badge(status_text($r['status'])),
    };
};
$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';

$cols = [
    'nummer'       => ['label' => 'Nummer', 'sort' => true],
    'kunde_firma'  => ['label' => 'Kunde', 'sort' => true, 'render' => fn($r)=> kunde_link($r['kunde_id'] ?? null, $r['kunde_firma'])],
    'produkt_name' => ['label' => 'Produkt', 'render' => fn($r)=> $r['produkt_name'] ? h($r['produkt_name']) : '<span class="muted">–</span>'],
    'art'          => ['label' => 'Art', 'render' => function($r) use ($auftragArt) {
                        $k = $auftragArt($r);
                        if ($k === 'none') return '<span class="muted">–</span>';
                        [$label, $stil, $hint] = auftrag_art_meta($k);
                        return '<span title="' . h($hint) . '">' . bx_badge($label, $stil) . '</span>';
                     }],
    'menge'        => ['label' => 'Menge', 'sort' => true, 'num' => true],
    'gesamt_netto' => ['label' => 'Netto', 'sort' => true, 'num' => true, 'render' => fn($r)=> $eur($r['gesamt_netto'])],
    'rechnung_nr'  => ['label' => 'Rechnung', 'render' => fn($r)=> $r['rechnung_nr'] ? h($r['rechnung_nr']) : '<span class="muted">–</span>'],
    'status'       => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
    'angelegt'     => ['label' => 'Erstellt', 'sort' => true, 'render' => fn($r)=> !empty($r['angelegt']) ? h(fmt_zeit($r['angelegt'], 'd.m.Y H:i')) : '<span class="muted">–</span>'],
];

$TABS = ['offen' => 'Offen', 'abgeschlossen' => 'Abgeschlossen'];
$TABCOUNT = ['offen' => $anzOffen, 'abgeschlossen' => $anzAbg];

render_header('auftraege', 'Aufträge');
$ohnePreis = (function_exists('has_role') && (has_role('admin') || has_role('finance') || has_role('sales')))
    ? (int) scalar("SELECT COUNT(*) FROM auftrag WHERE status<>'storniert' AND COALESCE(gesamt_netto,0) <= 0") : 0;
$kopfAktion = $ohnePreis > 0 ? bx_btn('Aufträge ohne Preis (' . $ohnePreis . ')', '?p=auftrag_preise', 'ghost') : '';
bx_head('Aufträge', $q !== '' ? count($rows) . ' Treffer (Suche über alle Aufträge, auch abgeschlossene)' : count($rows) . ' ' . ($tab === 'abgeschlossen' ? 'abgeschlossene (versendet)' : 'offene'), $kopfAktion);
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
