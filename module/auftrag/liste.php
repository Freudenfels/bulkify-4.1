<?php
// Auftrags-Liste (Auftragsbestätigungen)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'angelegt';
$dir  = $_GET['dir']  ?? 'desc';
$tab  = $_GET['tab']  ?? 'offen';
if (!in_array($tab, ['offen', 'abgeschlossen', 'storniert'], true)) $tab = 'offen';

$alle = all("SELECT a.*, k.firma AS kunde_firma, COALESCE(k.nutzt_fulfillment,0) AS nutzt_fulfillment,
             COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name) AS produkt_name,
             NULLIF(p.kundenname,'') AS kundenname,
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
// Abgeschlossen = versendet; storniert = eigener Reiter; offen = alles andere (offen, in Produktion, versandbereit).
$istStorno = fn($r) => ($r['status'] ?? '') === 'storniert';
$istAbg    = fn($r) => ($r['status'] ?? '') === 'versendet';
$istOffen  = fn($r) => !$istAbg($r) && !$istStorno($r);
$anzOffen  = count(array_filter($alle, $istOffen));
$anzAbg    = count(array_filter($alle, $istAbg));
$anzStorno = count(array_filter($alle, $istStorno));
// Der Reiter (offen/abgeschlossen/storniert) ist IMMER der erste Filter – auch bei einer Suche. So zeigt
// „Offen" + Suche nur offene Treffer. Wer einen versendeten/abgeschlossenen Auftrag sucht, wechselt in den
// passenden Reiter (die Treffer stehen dann dort). Die Suche filtert zusätzlich über Nummer/Kunde/Produkt.
$tabPred = match ($tab) {
    'abgeschlossen' => $istAbg,
    'storniert'     => $istStorno,
    default         => $istOffen,
};
$rows = array_filter($alle, $tabPred);
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['nummer','kunde_firma','produkt_name'] as $f) {
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        }
        return false;
    });
}
// Produktion fertig (letzter erledigter Schritt) je Auftrag -> Spalte „Dauer / Stand" UND zum Sortieren.
// Vor dem Sortieren berechnen (die IDs hängen nicht von der Sortierreihenfolge ab).
$fertigMap = [];
// array_values: $rows ist hier noch das array_filter-Ergebnis mit LÜCKEN in den Schlüsseln; für die
// IN(?)-Bindung (PDO, positionsbasiert) müssen die Parameter lückenlos durchnummeriert sein, sonst HY093.
$auftragIds = array_values(array_map(fn($r) => (int)$r['id'], $rows));
if ($auftragIds) {
    $in = implode(',', array_fill(0, count($auftragIds), '?'));
    foreach (all("SELECT pa.auftrag_id, MAX(ps.erledigt_at) fertig
                  FROM produktionsauftrag pa JOIN produktion_schritt ps ON ps.pa_id=pa.id
                  WHERE pa.status='erledigt' AND pa.auftrag_id IN ($in) GROUP BY pa.auftrag_id", $auftragIds) as $x)
        if ($x['fertig']) $fertigMap[(int)$x['auftrag_id']] = $x['fertig'];
}
$jetztUtc = gmdate('Y-m-d H:i:s');
// Sortierwerte für die BERECHNETEN Spalten (Art, Dauer) in die Zeilen schreiben, damit man auch nach ihnen
// sortieren kann. bx_sort_rows sortiert nach dem Spalten-Key in der Zeile; die Render-Closures lesen diese
// Keys NICHT (Art über $auftragArt, Dauer über $fertigMap), daher kein Konflikt.
$artSort = ['neu_rez' => '1', 'neu_prod' => '2', 'nach' => '3', 'none' => '9'];
foreach ($rows as &$rr) {
    $rr['art']   = $artSort[$auftragArt($rr)] ?? '9';
    $start       = $rr['angelegt'] ?? null;
    $rr['dauer'] = $start ? (strtotime(($fertigMap[(int)$rr['id']] ?? $jetztUtc)) - strtotime((string)$start)) : 0;
}
unset($rr);
$rows = bx_sort_rows($rows, $sort, $dir);

// Statt des groben Status den TATSÄCHLICHEN Fortschritt zeigen: „Rohstoff bestellt", „Rohstoff angekommen",
// „In Produktion", „Etikettiert", „Qualitätsprüfung", „Versandbereit", „Eingelagert", „Versendet" – abgeleitet
// aus kunde_auftrag_phase() (Bestellungen/Chargen/Produktionsschritte). So sieht man auf einen Blick, wo der
// Auftrag gerade steht, nicht nur den Sammelstatus.
$phaseStil = [0=>'info', 1=>'info', 2=>'info', 3=>'warn', 4=>'warn', 5=>'warn', 6=>'info', 7=>'ok', 8=>'ok'];
$standBadge = function($r) use ($phaseStil) {
    $st = (string)($r['status'] ?? '');
    if ($st === 'storniert') return bx_badge('storniert', 'err');
    $ff = !empty($r['nutzt_fulfillment']);   // Fulfillment: eingelagert statt versendet
    if ($st === 'versendet')  return bx_badge($ff ? 'eingelagert' : 'versendet', 'ok');
    $ph  = kunde_auftrag_phase($r);
    $idx = (int)$ph['idx'];
    $lbl = auftrag_phasen($ff)[$idx] ?? status_text($st);
    return bx_badge($lbl, $phaseStil[$idx] ?? 'info');
};
$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';

$dauerFmt = function (?string $a, ?string $b): string {
    if (!$a || !$b) return '';
    $s = strtotime($b) - strtotime($a); if ($s < 0) return '';
    if ($s < 3600) return max(1, intdiv($s, 60)) . ' Min';
    $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600);
    return $d > 0 ? $d . ' T' . ($h ? ' ' . $h . ' Std' : '') : $h . ' Std';
};

$cols = [
    'nummer'       => ['label' => 'Nummer', 'sort' => true],
    'kunde_firma'  => ['label' => 'Kunde', 'sort' => true, 'render' => fn($r)=> kunde_link($r['kunde_id'] ?? null, $r['kunde_firma'])],
    'produkt_name' => ['label' => 'Produkt', 'sort' => true, 'render' => fn($r)=>
        ($r['produkt_name'] ? h($r['produkt_name']) : '<span class="muted">–</span>')
        . (!empty($r['kundenname']) && $r['kundenname'] !== $r['produkt_name'] ? ' <span class="muted">· ' . h($r['kundenname']) . '</span>' : '')],
    'art'          => ['label' => 'Art', 'sort' => true, 'render' => function($r) use ($auftragArt) {
                        $k = $auftragArt($r);
                        if ($k === 'none') return '<span class="muted">–</span>';
                        [$label, $stil, $hint] = auftrag_art_meta($k);
                        return '<span title="' . h($hint) . '">' . bx_badge($label, $stil) . '</span>';
                     }],
    'menge'        => ['label' => 'Menge', 'sort' => true, 'num' => true],
    'gesamt_netto' => ['label' => 'Netto', 'sort' => true, 'num' => true, 'render' => fn($r)=> $eur($r['gesamt_netto'])],
    'rechnung_nr'  => ['label' => 'Rechnung', 'sort' => true, 'render' => fn($r)=> $r['rechnung_nr'] ? h($r['rechnung_nr']) : '<span class="muted">–</span>'],
    'status'       => ['label' => 'Fortschritt', 'sort' => true, 'render' => $standBadge],
    'angelegt'     => ['label' => 'Bestellt am', 'sort' => true, 'render' => fn($r)=> !empty($r['angelegt']) ? h(fmt_zeit($r['angelegt'], 'd.m.Y H:i')) : '<span class="muted">–</span>'],
    'dauer'        => ['label' => 'Dauer / Stand', 'sort' => true, 'render' => function($r) use ($fertigMap, $jetztUtc, $dauerFmt) {
                        $start = $r['angelegt'] ?? null;
                        $fertig = $fertigMap[(int)$r['id']] ?? null;
                        if ($fertig) {
                            $t = $dauerFmt($start, $fertig);
                            return $t !== ''
                                ? '<span title="bestellt → Produktion fertig am ' . h(fmt_zeit($fertig, 'd.m.Y H:i')) . '"><strong>' . h($t) . '</strong> <span class="muted">bis fertig</span></span>'
                                : '<span class="muted">fertig</span>';
                        }
                        if (($r['status'] ?? '') === 'versendet') return '<span class="muted">abgeschlossen</span>';
                        if (($r['status'] ?? '') === 'storniert') return '<span class="muted">–</span>';
                        $t = $dauerFmt($start, $jetztUtc);
                        return $t !== '' ? '<span class="muted">läuft seit ' . h($t) . '</span>' : '<span class="muted">–</span>';
                     }],
];

$TABS = ['offen' => 'Offen', 'abgeschlossen' => 'Abgeschlossen'];
if ($anzStorno > 0) $TABS['storniert'] = 'Storniert';   // Reiter nur zeigen, wenn es stornierte gibt
$TABCOUNT = ['offen' => $anzOffen, 'abgeschlossen' => $anzAbg, 'storniert' => $anzStorno];

render_header('auftraege', 'Aufträge');
$ohnePreis = (function_exists('has_role') && (has_role('admin') || has_role('finance') || has_role('sales')))
    ? (int) scalar("SELECT COUNT(*) FROM auftrag WHERE status<>'storniert' AND COALESCE(gesamt_netto,0) <= 0") : 0;
$kopfAktion = $ohnePreis > 0 ? bx_btn('Aufträge ohne Preis (' . $ohnePreis . ')', '?p=auftrag_preise', 'ghost') : '';
$tabWort = match ($tab) { 'abgeschlossen' => 'abgeschlossene (versendet)', 'storniert' => 'stornierte', default => 'offene' };
bx_head('Aufträge', $q !== '' ? count($rows) . ' Treffer unter „' . h((string)($TABS[$tab] ?? ucfirst($tab))) . '" (andere Reiter separat durchsuchen)' : count($rows) . ' ' . $tabWort, $kopfAktion);
if (isset($_GET['geloescht'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Auftrag' . (isset($_GET['nr']) ? ' ' . h((string)$_GET['nr']) : '') . ' gelöscht.</div>';
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
    'empty'   => match ($tab) {
        'abgeschlossen' => 'Noch keine abgeschlossenen (versendeten) Aufträge.',
        'storniert'     => 'Keine stornierten Aufträge.',
        default         => 'Kein offener Auftrag – entstehen automatisch aus bestätigten Angeboten.',
    },
]);
render_footer();
