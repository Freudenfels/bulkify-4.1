<?php
// Rohstoffe / Items – Liste
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_item_if_empty();

// Stoffklasse ("Art") leerer Rohstoffe per Namens-Heuristik vorbelegen (nur Admin). Setzt nur klare Treffer.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'art_autofuellen' && has_role('admin')) {
    $n = rohstoff_art_autofuellen();
    header('Location: ?p=rohstoffe&kat=rohstoff&artfill=' . (int)$n); exit;
}
// Alle Rohstoffe gegen den EU-Novel-Food-Katalog prüfen + Status mit Datum festschreiben.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'nf_pruefen_alle' && has_role('admin')) {
    $r = rohstoffe_novelfood_pruefen_alle();
    header('Location: ?p=rohstoffe&kat=rohstoff&nfall=' . (int)($r['geprueft'] ?? 0) . '&nfnf=' . (int)($r['novel_food'] ?? 0) . '&nfpr=' . (int)($r['pruefung'] ?? 0)); exit;
}

$KAT  = ['rohstoff'=>'Rohstoff','verpackung'=>'Verpackung','verbrauch'=>'Verbrauch','fertig'=>'Fertigware','verkaufsfertig'=>'Verkaufsfertig','maschine'=>'Maschine'];
$FORM = ['pulver'=>'Pulver','granulat'=>'Granulat','extrakt'=>'Extrakt','fluessig'=>'Flüssig','oel'=>'Öl','paste'=>'Paste','kristallin'=>'Kristallin','kapselhuelle'=>'Kapselhülle'];
$ART  = rohstoff_art_optionen();

$q    = trim($_GET['q'] ?? '');
$kat  = $_GET['kat'] ?? 'rohstoff';          // Standard: Rohstoffe
$sort = $_GET['sort'] ?? 'name';
$dir  = $_GET['dir']  ?? 'asc';
$fehlt = $_GET['fehlt'] ?? '';               // Lücken-Filter: '', 'lief', 'spec', 'coa', 'preis', 'irgendwas'
$artF  = $_GET['art']  ?? '';                // Stoffklasse-Filter (item.art): '', vitamin, mineralstoff, …
$formF = $_GET['form'] ?? '';                // Form-Filter (item.form): '', pulver, extrakt, fluessig, …
$istKapsel = ($kat === 'leerkapsel');

if ($kat === 'alle')            $rows = all("SELECT * FROM item");
elseif ($istKapsel)             $rows = all("SELECT * FROM item WHERE kategorie='rohstoff' AND form='kapselhuelle'");
elseif ($kat === 'rohstoff')    $rows = all("SELECT * FROM item WHERE kategorie='rohstoff' AND (form<>'kapselhuelle' OR form IS NULL)");
else                            $rows = all("SELECT * FROM item WHERE kategorie=?", [$kat]);
// Art-/Form-Filter (serverseitig, wie kat/fehlt). Greift für Rohstoffe; bei Leerkapseln ohne Wirkung.
if ($artF !== '')  $rows = array_values(array_filter($rows, fn($r) => (string)($r['art'] ?? '') === $artF));
if ($formF !== '') $rows = array_values(array_filter($rows, fn($r) => (string)($r['form'] ?? '') === $formF));

// Kapselgrößen-Namen für die Leerkapsel-Sicht
$KGMAP = [];
foreach (all("SELECT id, name FROM kapselgroesse") as $kg) $KGMAP[(int)$kg['id']] = $kg['name'];

// Textsuche ist LIVE (clientseitig, siehe Script unten): alle Zeilen werden gerendert und beim Tippen
// sofort gefiltert. $q befüllt nur das Feld vor (Deep-Link). Die Filter kat/fehlt bleiben serverseitig.
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

// Lücken-Filter: nur die Rohstoffe zeigen, bei denen etwas fehlt – damit man gezielt
// sieht, was noch anzufragen/zu hinterlegen ist.
if ($fehlt !== '' && !$istKapsel) {
    $rows = array_filter($rows, function ($r) use ($fehlt, $specSet, $coaSet, $liefSet) {
        $id = (int)$r['id'];
        $hatLief = isset($liefSet[$id]);
        $hatSpec = isset($specSet[$id]);
        $hatCoa  = isset($coaSet[$id]);
        $hatPreis = (float)$r['ek_preis'] > 0 || isset($liefSet[$id]);
        return match ($fehlt) {
            'lief'      => !$hatLief,
            'spec'      => !$hatSpec,
            'coa'       => !$hatCoa,
            'preis'     => !$hatPreis,
            'irgendwas' => !$hatLief || !$hatSpec || !$hatCoa || !$hatPreis,
            default     => true,
        };
    });
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
    // Namen sind teils sehr lang – kleiner + umbrechen (Tabelle ist sonst global nowrap und läuft über den Rand).
    'name'          => ['label' => 'Name', 'sort' => true, 'render' => fn($r)=> '<span style="white-space:normal">' . h($r['name']) . '</span>'],
    'ek_preis'      => ['label' => 'Preis ab', 'sort' => true, 'num' => true, 'render' => $preisAb],
    'art'           => ['label' => 'Art', 'sort' => true, 'render' => fn($r) => ($ART[$r['art'] ?? ''] ?? '') !== '' ? h($ART[$r['art']]) : '<span class="muted">–</span>'],
    'form'          => ['label' => 'Form', 'sort' => true, 'render' => fn($r)=> h($FORM[$r['form']] ?? $r['form'])],
    'novelfood'     => ['label' => 'Novel Food', 'sort' => true, 'render' => function($r){
                        $s = (string)($r['novelfood_status'] ?? '');
                        if ($s === '' ) return '<span class="muted" title="noch nicht geprüft">–</span>';
                        $m = novelfood_status_meta($s);
                        $kurz = $s === 'konform' ? 'konform' : ($s === 'novel_food' ? 'Novel Food' : ($s === 'pruefung' ? 'prüfen' : '?'));
                        return '<span title="' . h($m['label']) . '" style="color:' . $m['farbe'] . '">' . h($kurz) . '</span>';
                     }],
    'wirkstoffe' => ['label' => 'Wirkstoffe', 'th' => 'width:160px', 'render' => function($r) use ($wmap) {
        $list = $wmap[$r['id']] ?? [];
        return $list ? '<span style="white-space:normal;display:inline-block">' . h(implode(' · ', $list)) . '</span>' : '<span class="muted">–</span>';
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
$neuBtn  = ($istKapsel ? bx_btn('Neue Leerkapsel', '?p=rohstoff&id=neu&form=kapselhuelle', 'primary')
                      : bx_btn('Neuer Rohstoff', '?p=rohstoff&id=neu', 'primary'))
         . ' ' . bx_btn('Specs/CoAs Massen-Import', '?p=dok_massenimport', 'ghost');
$fehltLbl = ['irgendwas'=>'etwas fehlt','lief'=>'ohne Lieferant','preis'=>'ohne Preis','spec'=>'ohne Spec','coa'=>'ohne CoA'][$fehlt] ?? '';
render_header('rohstoffe', $titel);
bx_head($titel, count($rows) . ' Einträge' . ($fehltLbl ? ' · Filter: ' . $fehltLbl : ''), $neuBtn);
if (isset($_GET['artfill'])) echo '<div class="bx-panel badge-ok" style="padding:10px 14px">Stoffklasse (Art) bei <strong>' . (int)$_GET['artfill'] . '</strong> Rohstoff(en) automatisch vorbelegt. Leer gebliebene bitte am Rohstoff pflegen.</div>';
if (isset($_GET['nfall'])) echo '<div class="bx-panel badge-ok" style="padding:10px 14px"><strong>' . (int)$_GET['nfall'] . '</strong> Rohstoffe gegen den EU-Novel-Food-Katalog geprüft (mit Datum gespeichert): <strong style="color:var(--err)">' . (int)($_GET['nfnf'] ?? 0) . ' Novel Food</strong>, ' . (int)($_GET['nfpr'] ?? 0) . ' zu prüfen, Rest konform.</div>';
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
  <input class="bx-search" type="text" id="rohSuche" name="q" value="<?= h($q) ?>" placeholder="Suchen: Name, lat. Name, Art.-Nr …" autocomplete="off">
  <span class="muted" id="rohCount" style="font-size:13px;white-space:nowrap"></span>
  <?php if (!$istKapsel): ?>
  <select name="art" onchange="this.form.submit()" title="Nach Stoffklasse (Art) filtern">
    <option value="">Art: alle</option>
    <?php foreach ($ART as $k => $lbl): ?><option value="<?= h($k) ?>" <?= $artF === $k ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
  </select>
  <select name="form" onchange="this.form.submit()" title="Nach Form filtern">
    <option value="">Form: alle</option>
    <?php foreach (rohstoff_form_optionen() as $k => $lbl): ?><option value="<?= h($k) ?>" <?= $formF === $k ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
  </select>
  <select name="fehlt" onchange="this.form.submit()" title="Nur Rohstoffe zeigen, bei denen etwas fehlt">
    <?php foreach (['' => 'alle', 'irgendwas' => 'etwas fehlt', 'lief' => 'ohne Lieferant', 'preis' => 'ohne Preis', 'spec' => 'ohne Spec', 'coa' => 'ohne CoA'] as $k => $lbl): ?>
      <option value="<?= $k ?>" <?= $fehlt === $k ? 'selected' : '' ?>><?= $lbl ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== '' || $fehlt !== '' || $artF !== '' || $formF !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=rohstoffe&kat=<?= h($kat) ?>">zurücksetzen</a><?php endif; ?>
</form>
<?php if (!$istKapsel && has_role('admin')): ?>
<div class="bx-row" style="gap:16px;flex-wrap:wrap;margin:-6px 0 10px;align-items:center">
  <form method="post" style="margin:0" onsubmit="return confirm('Leere „Art" aller Rohstoffe per Namens-Heuristik vorbelegen? Nur klare Treffer werden gesetzt, Bestehendes bleibt. Danach manuell nachpflegbar.');">
    <input type="hidden" name="aktion" value="art_autofuellen">
    <button class="btn btn-ghost btn-sm" type="submit">Art automatisch vorbelegen</button>
    <span class="muted" style="font-size:12px">– füllt leere „Art" anhand des Namens (konservativ)</span>
  </form>
  <form method="post" style="margin:0" onsubmit="return confirm('Alle Rohstoffe gegen den EU-Novel-Food-Katalog prüfen und den Status mit Datum speichern? (Überschreibt den gespeicherten Novel-Food-Status.)');">
    <input type="hidden" name="aktion" value="nf_pruefen_alle">
    <button class="btn btn-ghost btn-sm" type="submit">Rohstoffe auf Novel Food prüfen</button>
    <span class="muted" style="font-size:12px">– gleicht Namen mit dem EU-Katalog ab, speichert Status + Datum</span>
  </form>
</div>
<?php endif; ?>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=rohstoffe&kat=' . h($kat) . ($q !== '' ? '&q=' . urlencode($q) : '') . ($fehlt !== '' ? '&fehlt=' . h($fehlt) : '') . ($artF !== '' ? '&art=' . h($artF) : '') . ($formF !== '' ? '&form=' . h($formF) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=rohstoff&id=' . $r['id'],
    'empty'   => 'Keine Einträge gefunden.',
]);
?>
<script>
(function(){
  var box=document.getElementById('rohSuche'); if(!box) return;
  var tbody=document.querySelector('.bx-table tbody'); if(!tbody) return;
  var rows=Array.prototype.slice.call(tbody.querySelectorAll('tr')),
      cnt=document.getElementById('rohCount'), total=rows.length;
  var leer=document.createElement('tr'); leer.hidden=true;
  leer.innerHTML='<td colspan="<?= count($cols) ?>" class="muted">Keine Einträge gefunden.</td>';
  tbody.appendChild(leer);
  function filter(){
    var q=(box.value||'').trim().toLowerCase(), sichtbar=0;
    rows.forEach(function(tr){ var m=!q||tr.textContent.toLowerCase().indexOf(q)>=0; tr.hidden=!m; if(m) sichtbar++; });
    leer.hidden=sichtbar>0;
    cnt.textContent = q ? (sichtbar+' von '+total) : (total+' Einträge');
  }
  box.addEventListener('input', filter); filter();
  var v=box.value; box.value=''; box.value=v; box.focus();
})();
</script>
<?php
render_footer();
