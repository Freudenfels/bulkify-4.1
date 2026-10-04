<?php
// Rezepturanfragen – Eingangsliste wie die Portal-Anfragen: Ansicht-Tabs (Offen/Beantwortet/Abgelehnt/
// Alle) mit Zählern, Suche und sortierbaren Spalten. Standard „Offen" – beantwortete sind im Archiv.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_anfrage_if_empty();

$DFORM = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig'];
$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'angelegt';
$dir  = $_GET['dir']  ?? 'desc';

// Ansicht: offen = das Arbeitsfach (neu + in Bearbeitung + „überarbeiten"), Rest im Archiv.
$ANSICHT = ['offen'=>'Offen', 'beantwortet'=>'Beantwortet', 'abgelehnt'=>'Abgelehnt', 'alle'=>'Alle'];
$OFFEN   = ['neu','in_bearbeitung','ueberarbeiten'];
$ansicht = isset($ANSICHT[$_GET['ansicht'] ?? '']) ? $_GET['ansicht'] : 'offen';

$alle = all("SELECT a.*, k.firma AS kunde_firma,
             (SELECT COUNT(*) FROM rezeptur_anfrage_wunsch w WHERE w.anfrage_id=a.id) AS wunsch_anzahl,
             r.nummer AS rezeptur_nr
             FROM rezeptur_anfrage a LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN rezeptur r ON r.id=a.rezeptur_id");

// Zähler je Ansicht (unabhängig von Suche/Auswahl)
$cnt = ['offen'=>0,'beantwortet'=>0,'abgelehnt'=>0,'alle'=>count($alle)];
foreach ($alle as $r) {
    if (in_array($r['status'], $OFFEN, true)) $cnt['offen']++;
    elseif ($r['status'] === 'beantwortet')   $cnt['beantwortet']++;
    elseif ($r['status'] === 'abgelehnt')      $cnt['abgelehnt']++;
}

// Bei aktiver Suche über ALLE Status (die Ansicht zählt dann nicht), sonst nach Ansicht filtern.
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($alle, function($r) use ($needle) {
        foreach (['nummer','kunde_firma','produktname','rezeptur_nr'] as $f)
            if (mb_strpos(mb_strtolower((string)($r[$f] ?? '')), $needle) !== false) return true;
        return false;
    });
} elseif ($ansicht === 'offen')       { $rows = array_filter($alle, fn($r) => in_array($r['status'], $OFFEN, true)); }
elseif   ($ansicht === 'beantwortet') { $rows = array_filter($alle, fn($r) => $r['status'] === 'beantwortet'); }
elseif   ($ansicht === 'abgelehnt')   { $rows = array_filter($alle, fn($r) => $r['status'] === 'abgelehnt'); }
else                                   { $rows = $alle; }

$rows = bx_sort_rows(array_values($rows), $sort, $dir);
// Vom Kunden abgelehnte Vorschläge („überarbeiten") immer zuoberst – da muss das Team reagieren.
usort($rows, fn($a, $b) => (($b['status'] === 'ueberarbeiten') <=> ($a['status'] === 'ueberarbeiten')));

$statusBadge = fn($r) => match ($r['status']) {
    'neu'            => bx_badge('neu','info'),
    'in_bearbeitung' => bx_badge('in Bearbeitung','warn'),
    'beantwortet'    => bx_badge('beantwortet','ok'),
    'ueberarbeiten'  => bx_badge('Vorschlag abgelehnt – überarbeiten','err'),
    'abgelehnt'      => bx_badge('abgelehnt','err'),
    default          => bx_badge(status_text($r['status'])),
};

$cols = [
    'nummer'        => ['label'=>'Nummer', 'sort'=>true],
    'produktname'   => ['label'=>'Rezeptur / Wunsch-Produkt', 'sort'=>true, 'render'=>fn($r)=> !empty($r['produktname'])?h($r['produktname']):'<span class="muted">–</span>'],
    'kunde_firma'   => ['label'=>'Kunde', 'sort'=>true, 'render'=>fn($r)=> $r['kunde_firma'] ? h($r['kunde_firma']) : '<span class="muted">–</span>'],
    'darreichungsform' => ['label'=>'Form', 'sort'=>true, 'render'=>fn($r)=> h($DFORM[$r['darreichungsform']] ?? $r['darreichungsform'])],
    'wunsch_anzahl' => ['label'=>'Wünsche', 'sort'=>true, 'num'=>true],
    'rezeptur_nr'   => ['label'=>'Rezeptur', 'render'=>fn($r)=> $r['rezeptur_nr']?h($r['rezeptur_nr']):'<span class="muted">–</span>'],
    'status'        => ['label'=>'Status', 'sort'=>true, 'render'=>$statusBadge],
    'angelegt'      => ['label'=>'Angefragt', 'sort'=>true, 'render'=>fn($r)=> $r['angelegt'] ? h(fmt_zeit($r['angelegt'], 'd.m.Y H:i')) : '<span class="muted">–</span>'],
];

render_header('anfragen', 'Rezepturanfragen');
bx_head('Rezepturanfragen', count($rows) . ($q !== '' ? ' Treffer' : ' ' . ($ANSICHT[$ansicht] ?? '')), bx_btn('Neue Anfrage', '?p=anfrage&id=neu', 'primary'));
?>
<div class="settabs" style="margin:0 0 12px">
  <?php foreach ($ANSICHT as $k => $lbl): $aktiv = ($q === '' && $ansicht === $k); ?>
    <a href="?p=anfragen&ansicht=<?= $k ?>" class="<?= $aktiv ? 'on' : '' ?>"><?= h($lbl) ?><?= isset($cnt[$k]) && $cnt[$k] ? ' (' . $cnt[$k] . ')' : '' ?></a>
  <?php endforeach; ?>
</div>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="anfragen">
  <input type="hidden" name="ansicht" value="<?= h($ansicht) ?>">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Kunde, Produkt, Rezeptur …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=anfragen">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=anfragen&ansicht=' . $ansicht . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=anfrage&id=' . $r['id'],
    'empty'   => $q !== '' ? 'Keine Treffer für „' . h($q) . '".' : ('Keine Anfragen in der Ansicht „' . h($ANSICHT[$ansicht] ?? '') . '".'),
]);
render_footer();
