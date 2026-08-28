<?php
// Warenlager – Bestandsübersicht mit Typ-Reitern.
// Charge-Typen (Rohstoff/Verpackung/Fertigware): Bestand aus Chargen. Betriebsmittel (Karton/Verbrauch/Inventar/Maschine/Sonstiges): einfacher Bestand.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_charge_if_empty();

$CHARGE_KAT = ['rohstoff'=>'Rohstoff', 'verpackung'=>'Verpackung', 'fertig'=>'Fertigware', 'verkaufsfertig'=>'Verkaufsfertig'];
$BM_KAT     = betriebsmittel_kategorien();
$ALLE_KAT   = $CHARGE_KAT + $BM_KAT;

// Reiter (Reihenfolge)
$TABS = ['alle'=>'Alle', 'rohstoff'=>'Rohstoffe', 'verpackung'=>'Verpackung', 'karton'=>'Kartons',
         'verbrauch'=>'Verbrauchsgüter', 'inventar'=>'Inventar', 'maschine'=>'Maschinen', 'sonstiges'=>'Sonstiges', 'fertig'=>'Fertigware'];

$q    = trim($_GET['q'] ?? '');
$kat  = $_GET['kat'] ?? 'alle';
if ($kat !== 'alle' && !array_key_exists($kat, $ALLE_KAT)) $kat = 'alle';
$sort = $_GET['sort'] ?? 'name';
$dir  = $_GET['dir']  ?? 'asc';
$istBM = ist_betriebsmittel_kat($kat);

if ($kat === 'alle') { $where = "i.kategorie IN ('" . implode("','", array_keys($ALLE_KAT)) . "')"; $params = []; }
else                 { $where = "i.kategorie=?"; $params = [$kat]; }

$rows = all("SELECT i.id,i.artikelnummer,i.name,i.kategorie,i.einheit,i.bestand_menge,i.mindestbestand,
             i.elektrisch,i.pruef_intervall_monate,i.letzte_pruefung,
             (SELECT COALESCE(SUM(menge_verfuegbar),0) FROM charge c WHERE c.item_id=i.id AND c.status='frei') AS frei_charge,
             (SELECT COALESCE(SUM(menge_verfuegbar),0) FROM charge c WHERE c.item_id=i.id AND c.status='quarantaene') AS quarantaene,
             (SELECT COUNT(*) FROM charge c WHERE c.item_id=i.id AND c.status IN ('frei','quarantaene')) AS n_chargen
             FROM item i WHERE $where", $params);

// Bestand vereinheitlichen: Betriebsmittel = manueller Bestand, sonst Chargen-Bestand.
foreach ($rows as &$r) $r['frei'] = ist_betriebsmittel_kat($r['kategorie']) ? (float)$r['bestand_menge'] : (float)$r['frei_charge'];
unset($r);

if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['name','artikelnummer'] as $f) if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        return false;
    });
}
$rows = bx_sort_rows(array_values($rows), $sort, $dir);

$mng = fn($x,$e) => $x > 0 ? rtrim(rtrim(number_format((float)$x,3,',','.'),'0'),',') . ' ' . h($e) : '<span class="muted">0</span>';
$detailUrl = function($r) {
    if (ist_betriebsmittel_kat($r['kategorie'])) return '?p=betriebsmittel&id=' . $r['id'];
    return $r['kategorie'] === 'verpackung' ? '?p=verpackung&id=' . $r['id'] : '?p=rohstoff&id=' . $r['id'];
};
$pruefRender = function($r) {
    if (empty($r['elektrisch'])) return '<span class="muted">–</span>';
    $s = pruefung_status($r);
    if (!$s) return '<span class="muted">–</span>';
    $kind = $s['stufe'] === 'faellig' ? 'err' : ($s['stufe'] === 'bald' || $s['stufe'] === 'offen' ? 'warn' : 'ok');
    $txt  = $s['datum'] ? date('d.m.Y', strtotime($s['datum'])) : $s['label'];
    return bx_badge($txt . ($s['stufe'] === 'faellig' ? ' · überfällig' : ($s['stufe'] === 'offen' ? ' · offen' : '')), $kind);
};

// Spalten je Reiter
if ($istBM) {
    $zeigePruef = in_array($kat, ['maschine','inventar','sonstiges'], true);
    $cols = [
        'artikelnummer' => ['label'=>'Art.-Nr.', 'sort'=>true],
        'name'          => ['label'=>'Name', 'sort'=>true],
        'frei'          => ['label'=>'Bestand', 'sort'=>true, 'num'=>true, 'render'=>fn($r)=> $mng($r['frei'],$r['einheit'] ?: 'Stück')],
    ];
    if ($zeigePruef) $cols['pruefung'] = ['label'=>'Geräteprüfung', 'render'=>$pruefRender];
} elseif ($kat === 'alle') {
    $cols = [
        'artikelnummer' => ['label'=>'Art.-Nr.', 'sort'=>true],
        'name'          => ['label'=>'Name', 'sort'=>true],
        'kategorie'     => ['label'=>'Typ', 'sort'=>true, 'render'=>fn($r)=> h($ALLE_KAT[$r['kategorie']] ?? $r['kategorie'])],
        'frei'          => ['label'=>'Bestand', 'sort'=>true, 'num'=>true, 'render'=>fn($r)=> $mng($r['frei'],$r['einheit'] ?: 'Stück')],
        'pruefung'      => ['label'=>'Prüfung', 'render'=>$pruefRender],
    ];
} else {
    $cols = [
        'artikelnummer' => ['label'=>'Art.-Nr.', 'sort'=>true],
        'name'          => ['label'=>'Name', 'sort'=>true],
        'kategorie'     => ['label'=>'Kategorie', 'sort'=>true, 'render'=>fn($r)=> h($ALLE_KAT[$r['kategorie']] ?? $r['kategorie'])],
        'n_chargen'     => ['label'=>'Chargen', 'sort'=>true, 'num'=>true],
        'frei'          => ['label'=>'Bestand (frei)', 'sort'=>true, 'num'=>true, 'render'=>fn($r)=> $mng($r['frei'],$r['einheit'])],
        'quarantaene'   => ['label'=>'Quarantäne', 'sort'=>true, 'num'=>true, 'render'=>fn($r)=> (float)$r['quarantaene']>0 ? '<span class="badge badge-warn">'.$mng($r['quarantaene'],$r['einheit']).'</span>' : '<span class="muted">–</span>'],
    ];
}

// Header-Aktion: auf Betriebsmittel-Reitern „Neu", sonst Wareneingang
$aktion = $istBM
    ? bx_btn('Neu: ' . $BM_KAT[$kat], '?p=betriebsmittel&id=neu&kat=' . h($kat), 'primary')
    : bx_btn('Wareneingang', '?p=wareneingang', 'primary');

render_header('lager', 'Warenlager');
bx_head('Warenlager', count($rows) . ' Artikel', $aktion);
?>
<div class="settabs" style="margin:0 0 14px">
  <?php foreach ($TABS as $k => $lbl): ?>
    <a href="?p=lager&kat=<?= $k ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="<?= $kat === $k ? 'on' : '' ?>"><?= h($lbl) ?></a>
  <?php endforeach; ?>
</div>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="lager">
  <input type="hidden" name="kat" value="<?= h($kat) ?>">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Name, Art.-Nr …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
</form>
<?php
if ($istBM && !$rows && $q === '') {
    echo '<div class="bx-panel"><div class="muted">Noch keine ' . h($BM_KAT[$kat]) . ' angelegt. Über „Neu: ' . h($BM_KAT[$kat]) . '" oben rechts hinzufügen.</div></div>';
} else {
    bx_table($cols, $rows, [
        'baseUrl' => '?p=lager&kat=' . h($kat) . ($q !== '' ? '&q=' . urlencode($q) : ''),
        'sort'    => $sort,
        'dir'     => $dir,
        'rowUrl'  => $detailUrl,
        'empty'   => 'Keine Artikel gefunden.',
    ]);
}
render_footer();
