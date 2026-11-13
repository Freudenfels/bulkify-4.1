<?php
// Rohstoffe / Items – Liste
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/rohstoff_moq.php';
moq_schema();

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
// KI-Kurzinfo für EINEN Rohstoff erzeugen (nur auf Knopfdruck). Fragt die KI, speichert den Text (item.ki_info).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'ki_info') {
    $iid = (int)($_POST['item_id'] ?? 0);
    $r = $iid ? item_ki_info_erzeugen($iid) : ['ok'=>false, 'fehler'=>'Kein Rohstoff.'];
    $back = (string)($_POST['zurueck'] ?? '');
    if (strncmp($back, '?p=rohstoffe', 12) !== 0) $back = '?p=rohstoffe&kat=rohstoff';   // nur zurück zur eigenen Liste
    $back = preg_replace('/[&?](kiok|kierr)=[^&]*/', '', $back);
    $sep  = strpos($back, '?') === false ? '?' : '&';
    header('Location: ' . $back . $sep . ($r['ok'] ? 'kiok=1' : 'kierr=' . urlencode($r['fehler'] ?? 'Fehler'))); exit;
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
// Block C (11.11.2026): gesperrte Artikel standardmäßig ausgeblendet (Schalter oben).
$inaktivAus = 0;
if (!bx_inaktiv_an()) { $vor = count($rows); $rows = array_values(array_filter($rows, fn($r) => (int)($r['gesperrt'] ?? 0) === 0)); $inaktivAus = $vor - count($rows); }
// Art-/Form-Filter (serverseitig, wie kat/fehlt). Greift für Rohstoffe; bei Leerkapseln ohne Wirkung.
if ($artF !== '')  $rows = array_values(array_filter($rows, fn($r) => (string)($r['art'] ?? '') === $artF));
if ($formF !== '') $rows = array_values(array_filter($rows, fn($r) => (string)($r['form'] ?? '') === $formF));
// Fable-5 T4.8 (D17): Suche serverseitig über alle Seiten (Name, Art.-Nr., lateinischer Name …), danach 100 Zeilen je Seite.
if ($q !== '') {
    $qq = mb_strtolower($q);
    $rows = array_values(array_filter($rows, function ($r) use ($qq) {
        foreach (['name', 'artikelnummer', 'synonym', 'synonyme', 'latein', 'name_latein', 'inci'] as $f)
            if (isset($r[$f]) && $r[$f] !== null && mb_strpos(mb_strtolower((string)$r[$f]), $qq) !== false) return true;
        return false;
    }));
}

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

$preis = fn($r) => h(preis_anzeige((float)$r['ek_preis'], 'EUR', (string)$r['preis_bezug']));   // 13.11.2026: unter 0,01 € je 1.000
$statusBadge = fn($r) => (int)$r['gesperrt'] === 1 ? bx_badge('gesperrt','err') : bx_badge('aktiv','ok');
$katBadge = fn($r) => bx_badge($KAT[$r['kategorie']] ?? $r['kategorie']);

// Für die Rohstoffliste: je Item ermitteln, ob Spec/CoA-Unterlagen vorliegen, ob es einen
// (id-verknüpften) Lieferanten gibt und den günstigsten bekannten EK ("ab …"). Alles über
// wenige Sammelabfragen auf die angezeigten Item-IDs – nicht pro Zeile.
$specSet = $coaSet = $liefSet = $liefMin = $liefAbg = [];
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
    // Block D (11.11.2026): „günstigster EK" nur aus gültigen Preisen; gibt es nur abgelaufene, der letzte mit Kennzeichen (nie still).
    foreach (all("SELECT lp.item_id AS id, MIN(CASE WHEN lp.gueltig_bis IS NULL OR lp.gueltig_bis >= '" . heute_ort() . "' THEN lp.preis END) AS mp, MIN(lp.preis) AS mp_alle,
                         MAX(lp.gueltig_bis) AS bis_max, SUM(lp.gueltig_bis IS NULL OR lp.gueltig_bis >= '" . heute_ort() . "') AS n_gueltig
                  FROM lieferant_preis lp LEFT JOIN lieferanten l ON l.id=lp.lieferant_id
                  WHERE lp.item_id IN ($in) AND lp.preis>0 AND COALESCE(l.gesperrt,0)=0 GROUP BY lp.item_id", $ids) as $r) {
        $liefSet[(int)$r['id']] = 1;
        $liefMin[(int)$r['id']] = (int)$r['n_gueltig'] > 0 ? (float)$r['mp'] : (float)$r['mp_alle'];
        if ((int)$r['n_gueltig'] === 0) $liefAbg[(int)$r['id']] = (string)$r['bis_max'];
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
// Fable-5 T4.8 (D17): 100 Zeilen je Seite (vorher alle 1.691 mit rund 1.700 Knöpfen auf einmal).
$ROH_JE_SEITE = 100;
$rows = array_values($rows);
$rohGesamt = count($rows);
$rohSeiten = max(1, (int) ceil($rohGesamt / $ROH_JE_SEITE));
$rohSeite = min($rohSeiten, max(1, (int)($_GET['seite'] ?? 1)));
$rows = array_slice($rows, ($rohSeite - 1) * $ROH_JE_SEITE, $ROH_JE_SEITE);
// „Preis ab": günstigster bekannter EK (eigener EK vs. Lieferanten-EK), in der Einheit des Items.
$preisAb = function ($r) use ($liefMin, $liefAbg) {
    $min = (float)$r['ek_preis'];
    $lm = $liefMin[(int)$r['id']] ?? 0;
    if ($lm > 0) $min = ($min > 0) ? min($min, $lm) : $lm;
    if ($min <= 0) return '<span class="muted">–</span>';
    $abg = $liefAbg[(int)$r['id']] ?? (!empty($r['ek_abgelaufen_seit']) ? (string)$r['ek_abgelaufen_seit'] : '');   // Block D: nie still
    return 'ab ' . h(preis_anzeige($min, 'EUR', (string)$r['preis_bezug']))
         . ($abg !== '' ? ' <span class="badge badge-err" title="Preis abgelaufen - Summe unsicher">abgelaufen seit ' . h(date('d.m.Y', strtotime($abg))) . '</span>' : '');
};
// Kompakte Verfügbarkeits-Marker: grün = vorhanden, grau = nicht.
// Fable-5 T4.7 (D17): kräftigere Marken (Kontrast ≥ 4,5 : 1) – vorhanden grün und fett, fehlt grau durchgestrichen.
$verfTok = fn($label, $on) => '<span class="' . ($on ? 'bx-mark-ja' : 'bx-mark-nein') . '" title="' . h($label . ($on ? ' vorhanden' : ' fehlt')) . '">' . $label . '</span>';
$verf = fn($r) => $verfTok('Spec', isset($specSet[(int)$r['id']])) . ' <span class="muted">·</span> '
                . $verfTok('CoA', isset($coaSet[(int)$r['id']])) . ' <span class="muted">·</span> '
                . $verfTok('Lief.', isset($liefSet[(int)$r['id']]));

// Bedarf (aus offenen Produktionsaufträgen) + Lagerbestand je Rohstoff – für die Spalten „Bedarf"/„Auf Lager".
// Beides gebündelt (nicht pro Zeile): Bedarf einmal über bedarf_aggregiert(), Bestand als eine Charge-Summe.
$bedarfMap = [];
foreach (bedarf_aggregiert() as $b) if (empty($b['etikett'])) $bedarfMap[(int)$b['item_id']] = ($bedarfMap[(int)$b['item_id']] ?? 0) + (float)$b['need'];
$bestandMap = [];
$idsBB = array_map('intval', array_column($rows, 'id'));
if ($idsBB) {
    $inBB = implode(',', array_fill(0, count($idsBB), '?'));
    foreach (all("SELECT item_id, COALESCE(SUM(menge_verfuegbar),0) AS b FROM charge
                  WHERE item_id IN ($inBB) AND status='frei' AND fremd_kunde_id IS NULL GROUP BY item_id", $idsBB) as $cb)
        $bestandMap[(int)$cb['item_id']] = (float)$cb['b'];
}
$mgMenge = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');

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
    // Anzeige = VOLLSTÄNDIGER Name wie beim Kunden (rohstoff_anzeige_name: inkl. Beschaffenheit „Extrakt" + DEV
    // „20:1"), damit die Live-Suche denselben Namen findet, den der Kunde sieht (sucht über den Zeilentext).
    'name'          => ['label' => 'Name', 'sort' => true, 'render' => fn($r)=> h(rohstoff_anzeige_name($r))],   // lange Namen: „…“ + Lauftext (bx_table, 13.11.2026)
    // Fable-5 T4.1 (D17): Spalten zusammengefasst, damit die Tabelle aufs Feld passt – MOQ unter dem Preis, Bedarf/Lager in einer
    // Spalte, Art und Form in einer Spalte.
    'ek_preis'      => ['label' => 'Preis ab · MOQ', 'sort' => true, 'num' => true, 'render' => fn($r) => $preisAb($r) . ((float)($r['moq_menge'] ?? 0) > 0
                        ? '<div class="muted" style="font-size:12px"' . (trim((string)($r['moq_notiz'] ?? '')) !== '' ? ' title="' . h((string)$r['moq_notiz']) . '"' : '') . '>MOQ ' . h(moq_zahl((float)$r['moq_menge']) . ' ' . ($r['moq_einheit'] ?: 'kg')) . '</div>' : '')],
    'bestand'       => ['label' => 'Lager · Bedarf', 'num' => true, 'render' => function($r) use ($bedarfMap, $bestandMap, $mgMenge) {
        $b = $bestandMap[(int)$r['id']] ?? 0; $n = $bedarfMap[(int)$r['id']] ?? 0; $e = h((string)($r['einheit'] ?? ''));
        return ($b > 0 ? $mgMenge($b) . ' ' . $e : '<span class="muted">0</span>')
             . ($n > 0 ? '<div style="font-size:12px">Bedarf <strong>' . $mgMenge($n) . '</strong> ' . $e . '</div>' : ''); }],
    'art'           => ['label' => 'Art · Form', 'sort' => true, 'render' => fn($r) => (($ART[$r['art'] ?? ''] ?? '') !== '' ? h($ART[$r['art']]) : '<span class="muted">–</span>')
                        . '<div class="muted" style="font-size:12px">' . h($FORM[$r['form']] ?? (string)$r['form']) . '</div>'],
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
    'kiinfo'        => ['label' => 'KI-Info', 'th' => 'width:118px', 'render' => function($r) {
        $has  = trim((string)($r['ki_info'] ?? '')) !== '';
        $snip = $has ? mb_substr(trim((string)$r['ki_info']), 0, 220) : '';
        // onclick-stopPropagation: der Button darf NICHT die Zeilen-Navigation (rowUrl) auslösen.
        return '<form method="post" style="margin:0;white-space:nowrap" onclick="event.stopPropagation()" '
             . 'onsubmit="return confirm(\'KI-Kurzinfo für diesen Rohstoff erzeugen und speichern? Fragt die KI (kann ein paar Sekunden dauern).\');">'
             . '<input type="hidden" name="aktion" value="ki_info"><input type="hidden" name="item_id" value="' . (int)$r['id'] . '">'
             . '<input type="hidden" name="zurueck" value="' . h((string)($_SERVER['REQUEST_URI'] ?? '')) . '">'
             . ($has ? '<span title="' . h($snip) . '" style="color:var(--gruen);font-size:12px">vorhanden</span> ' : '')
             . '<button class="btn btn-ghost btn-sm" type="submit">' . ($has ? 'erneuern' : 'erzeugen') . '</button>'
             . '</form>';
    }],
    'gesperrt'      => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
];
}
if (!bx_inaktiv_an()) unset($cols['gesperrt']);   // Fable-5 T4.1: ohne „Inaktive anzeigen“ sind alle aktiv – Spalte Status entfällt
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
bx_head($titel, number_format($rohGesamt, 0, ',', '.') . ' Einträge' . ($rohSeiten > 1 ? ' · Seite ' . $rohSeite . ' von ' . $rohSeiten : '') . ($fehltLbl ? ' · Filter: ' . $fehltLbl : ''), bx_inaktiv_schalter($inaktivAus) . ' ' . $neuBtn);
if (isset($_GET['artfill'])) echo '<div class="bx-panel badge-ok" style="padding:10px 14px">Stoffklasse (Art) bei <strong>' . (int)$_GET['artfill'] . '</strong> Rohstoff(en) automatisch vorbelegt. Leer gebliebene bitte am Rohstoff pflegen.</div>';
if (isset($_GET['nfall'])) echo '<div class="bx-panel badge-ok" style="padding:10px 14px"><strong>' . (int)$_GET['nfall'] . '</strong> Rohstoffe gegen den EU-Novel-Food-Katalog geprüft (mit Datum gespeichert): <strong style="color:var(--err)">' . (int)($_GET['nfnf'] ?? 0) . ' Novel Food</strong>, ' . (int)($_GET['nfpr'] ?? 0) . ' zu prüfen, Rest konform.</div>';
if (isset($_GET['kiok']))  echo '<div class="bx-panel badge-ok" style="padding:10px 14px">KI-Kurzinfo erzeugt und gespeichert – sie wird dir und dem Kunden als „KI-Info" angezeigt.</div>';
if (isset($_GET['kierr'])) echo '<div class="bx-panel" style="border-color:var(--warn);border-left:3px solid var(--warn);padding:10px 14px">KI-Kurzinfo nicht möglich: ' . h((string)$_GET['kierr']) . '</div>';
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
if ($rohSeiten > 1) {   // Fable-5 T4.8: Blättern (100 je Seite), Filter und Sortierung bleiben erhalten
    $rohBasis = '?p=rohstoffe&kat=' . rawurlencode($kat) . ($q !== '' ? '&q=' . rawurlencode($q) : '') . ($fehlt !== '' ? '&fehlt=' . rawurlencode($fehlt) : '')
              . ($artF !== '' ? '&art=' . rawurlencode($artF) : '') . ($formF !== '' ? '&form=' . rawurlencode($formF) : '') . '&sort=' . rawurlencode($sort) . '&dir=' . rawurlencode($dir);
    echo '<div class="bx-row" style="gap:6px;flex-wrap:wrap;align-items:center;margin-top:12px">';
    if ($rohSeite > 1) echo '<a class="btn btn-ghost btn-sm" href="' . h($rohBasis . '&seite=' . ($rohSeite - 1)) . '">Zurück</a>';
    for ($i = 1; $i <= $rohSeiten; $i++) {
        if ($i !== 1 && $i !== $rohSeiten && abs($i - $rohSeite) > 2) { if (abs($i - $rohSeite) === 3) echo '<span class="muted">…</span>'; continue; }
        echo '<a class="btn btn-' . ($i === $rohSeite ? 'primary' : 'ghost') . ' btn-sm" href="' . h($rohBasis . '&seite=' . $i) . '">' . $i . '</a>';
    }
    if ($rohSeite < $rohSeiten) echo '<a class="btn btn-ghost btn-sm" href="' . h($rohBasis . '&seite=' . ($rohSeite + 1)) . '">Weiter</a>';
    echo '<span class="muted" style="font-size:12px">' . number_format(($rohSeite - 1) * $ROH_JE_SEITE + 1, 0, ',', '.') . '–' . number_format(min($rohGesamt, $rohSeite * $ROH_JE_SEITE), 0, ',', '.') . ' von ' . number_format($rohGesamt, 0, ',', '.') . '</span></div>';
}
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
    cnt.textContent = q ? (sichtbar+' von '+total+' auf dieser Seite – Enter sucht in allen') : '';
  }
  box.addEventListener('input', filter); filter();
  var v=box.value; box.value=''; box.value=v; box.focus();
})();
</script>
<?php
render_footer();
