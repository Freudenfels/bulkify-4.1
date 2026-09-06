<?php
// Rohstoffe / Items – Liste
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_item_if_empty();

$KAT  = ['rohstoff'=>'Rohstoff','verpackung'=>'Verpackung','verbrauch'=>'Verbrauch','fertig'=>'Fertigware','verkaufsfertig'=>'Verkaufsfertig','maschine'=>'Maschine'];
$FORM = ['pulver'=>'Pulver','granulat'=>'Granulat','fluessig'=>'Flüssig','oel'=>'Öl','paste'=>'Paste','kristallin'=>'Kristallin','kapselhuelle'=>'Kapselhülle'];

$q    = trim($_GET['q'] ?? '');
$kat  = $_GET['kat'] ?? 'rohstoff';          // Standard: Rohstoffe
$sort = $_GET['sort'] ?? 'name';
$dir  = $_GET['dir']  ?? 'asc';
$istKapsel = ($kat === 'leerkapsel');

if ($kat === 'alle')            $rows = all("SELECT * FROM item");
elseif ($istKapsel)             $rows = all("SELECT * FROM item WHERE kategorie='rohstoff' AND form='kapselhuelle'");
elseif ($kat === 'rohstoff')    $rows = all("SELECT * FROM item WHERE kategorie='rohstoff' AND (form<>'kapselhuelle' OR form IS NULL)");
else                            $rows = all("SELECT * FROM item WHERE kategorie=?", [$kat]);

// Kapselgrößen-Namen für die Leerkapsel-Sicht
$KGMAP = [];
foreach (all("SELECT id, name FROM kapselgroesse") as $kg) $KGMAP[(int)$kg['id']] = $kg['name'];

if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['name','name_en','name_lat','artikelnummer'] as $f) {
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        }
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

// Wirkstoffe je Item nachladen (mehrere möglich) -> Map item_id => ["95 % Curcumin", ...]
$wmap = [];
$ids = array_column($rows, 'id');
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (all("SELECT iw.item_id, iw.gehalt_prozent, n.name FROM item_wirkstoff iw
                  JOIN naehrstoff n ON n.id=iw.naehrstoff_id WHERE iw.item_id IN ($in) ORDER BY iw.sort, iw.id", $ids) as $w) {
        $g = $w['gehalt_prozent'] !== null ? rtrim(rtrim(number_format((float)$w['gehalt_prozent'],2,',','.'),'0'),',') . ' % ' : '';
        $wmap[$w['item_id']][] = trim($g . $w['name']);
    }
}

$preis = fn($r) => number_format((float)$r['ek_preis'], (float)$r['ek_preis'] < 1 ? 4 : 2, ',', '.') . ' €/' . h($r['preis_bezug']);
$statusBadge = fn($r) => (int)$r['gesperrt'] === 1 ? bx_badge('gesperrt','err') : bx_badge('aktiv','ok');
$katBadge = fn($r) => bx_badge($KAT[$r['kategorie']] ?? $r['kategorie']);

// Für die Rohstoffliste: je Item ermitteln, ob Spec/CoA-Unterlagen vorliegen, ob es einen
// (id-verknüpften) Lieferanten gibt und den günstigsten bekannten EK ("ab …"). Alles über
// wenige Sammelabfragen auf die angezeigten Item-IDs – nicht pro Zeile.
$specSet = $coaSet = $liefSet = $liefMin = [];
if ($ids && !$istKapsel) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    // Spec vorhanden: hinterlegtes Spec-PDF, strukturierte Spec-Inhalte oder ein Spec-Dokument
    foreach (all("SELECT id FROM item WHERE id IN ($in) AND spec_pdf IS NOT NULL AND spec_pdf<>''", $ids) as $r) $specSet[(int)$r['id']] = 1;
    foreach (['item_kennwert','item_wirkstoff','item_grenzwert'] as $tab)
        foreach (all("SELECT DISTINCT item_id AS id FROM $tab WHERE item_id IN ($in)", $ids) as $r) $specSet[(int)$r['id']] = 1;
    foreach (all("SELECT DISTINCT objekt_id AS id FROM dokument WHERE objekt_typ='item' AND typ='spec' AND objekt_id IN ($in)", $ids) as $r) $specSet[(int)$r['id']] = 1;
    // CoA vorhanden: Charge mit Analysewerten oder ein CoA-Dokument
    foreach (all("SELECT DISTINCT c.item_id AS id FROM charge c JOIN charge_analyse a ON a.charge_id=c.id WHERE c.item_id IN ($in)", $ids) as $r) $coaSet[(int)$r['id']] = 1;
    foreach (all("SELECT DISTINCT objekt_id AS id FROM dokument WHERE objekt_typ='item' AND typ='coa' AND objekt_id IN ($in)", $ids) as $r) $coaSet[(int)$r['id']] = 1;
    // Lieferant vorhanden + günstigster Lieferanten-EK je Item
    foreach (all("SELECT item_id AS id, MIN(preis) AS mp FROM lieferant_preis WHERE item_id IN ($in) AND preis>0 GROUP BY item_id", $ids) as $r) {
        $liefSet[(int)$r['id']] = 1;
        $liefMin[(int)$r['id']] = (float)$r['mp'];
    }
}
// „Preis ab": günstigster bekannter EK (eigener EK vs. Lieferanten-EK), in der Einheit des Items.
$preisAb = function ($r) use ($liefMin) {
    $min = (float)$r['ek_preis'];
    $lm = $liefMin[(int)$r['id']] ?? 0;
    if ($lm > 0) $min = ($min > 0) ? min($min, $lm) : $lm;
    if ($min <= 0) return '<span class="muted">–</span>';
    return 'ab ' . number_format($min, $min < 1 ? 4 : 2, ',', '.') . ' €/' . h($r['preis_bezug']);
};
// Kompakte Verfügbarkeits-Marker: grün = vorhanden, grau = nicht.
$verfTok = fn($label, $on) => '<span style="font-size:12px;' . ($on ? 'color:var(--gruen);font-weight:600' : 'color:#bbb') . '">' . $label . '</span>';
$verf = fn($r) => $verfTok('Spec', isset($specSet[(int)$r['id']])) . ' <span style="color:#ddd">·</span> '
                . $verfTok('CoA', isset($coaSet[(int)$r['id']])) . ' <span style="color:#ddd">·</span> '
                . $verfTok('Lief.', isset($liefSet[(int)$r['id']]));

if ($istKapsel) {
    $mg = fn($x) => $x !== null && $x !== '' ? rtrim(rtrim(number_format((float)$x,2,',','.'),'0'),',').' mg' : '<span class="muted">–</span>';
    $cols = [
        'artikelnummer' => ['label' => 'Art.-Nr.', 'sort' => true],
        'name'          => ['label' => 'Name', 'sort' => true],
        'kapselgroesse_id' => ['label' => 'Größe', 'sort' => true, 'render' => fn($r)=> h($KGMAP[(int)$r['kapselgroesse_id']] ?? '–')],
        'material'      => ['label' => 'Material', 'sort' => true, 'render' => fn($r)=> h($r['material'] ?: '–')],
        'farbe'         => ['label' => 'Farbe', 'render' => fn($r)=> h($r['farbe'] ?: '–')],
        'leergewicht_mg'=> ['label' => 'Leergewicht', 'sort' => true, 'num' => true, 'render' => fn($r)=> $mg($r['leergewicht_mg'])],
        'ek_preis'      => ['label' => 'EK-Preis', 'sort' => true, 'num' => true, 'render' => $preis],
        'gesperrt'      => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
    ];
} else {
$cols = [
    'artikelnummer' => ['label' => 'Art.-Nr.', 'sort' => true],
    'name'          => ['label' => 'Name', 'sort' => true],
    'ek_preis'      => ['label' => 'Preis ab', 'sort' => true, 'num' => true, 'render' => $preisAb],
    'form'          => ['label' => 'Form', 'sort' => true, 'render' => fn($r)=> h($FORM[$r['form']] ?? $r['form'])],
    'wirkstoffe' => ['label' => 'Wirkstoffe', 'render' => function($r) use ($wmap) {
        $list = $wmap[$r['id']] ?? [];
        return $list ? h(implode(' · ', $list)) : '<span class="muted">–</span>';
    }],
    'unterlagen'    => ['label' => 'Unterlagen / Lieferant', 'render' => $verf],
    'gesperrt'      => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
];
}
if ($kat === 'alle') {
    $cols = array_slice($cols, 0, 2, true)
          + ['kategorie' => ['label'=>'Kategorie','sort'=>true,'render'=>$katBadge]]
          + array_slice($cols, 2, null, true);
}

$titel   = $istKapsel ? 'Leerkapseln' : 'Rohstoffe';
$neuBtn  = $istKapsel ? bx_btn('Neue Leerkapsel', '?p=rohstoff&id=neu&form=kapselhuelle', 'primary')
                      : bx_btn('Neuer Rohstoff', '?p=rohstoff&id=neu', 'primary');
render_header('rohstoffe', $titel);
bx_head($titel, count($rows) . ' Einträge', $neuBtn);
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="rohstoffe">
  <select name="kat" onchange="this.form.submit()">
    <option value="rohstoff" <?= $kat==='rohstoff'?'selected':'' ?>>Rohstoffe (Wirkstoffe)</option>
    <option value="leerkapsel" <?= $istKapsel?'selected':'' ?>>Leerkapseln</option>
    <?php foreach ($KAT as $k=>$lbl) if ($k!=='rohstoff'): ?>
      <option value="<?= $k ?>" <?= $kat===$k?'selected':'' ?>><?= $lbl ?></option>
    <?php endif; ?>
    <option value="alle" <?= $kat==='alle'?'selected':'' ?>>Alle Kategorien</option>
  </select>
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Name, lat. Name, Art.-Nr …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=rohstoffe&kat=<?= h($kat) ?>">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=rohstoffe&kat=' . h($kat) . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=rohstoff&id=' . $r['id'],
    'empty'   => 'Keine Einträge gefunden.',
]);
render_footer();
