<?php
// Produktion (neu) – neue Produktionsübersicht im Aufbau. Läuft parallel zur bestehenden Liste
// (module/produktion/liste.php), die unverändert bleibt. Wenn fertig, ersetzt sie die Liste.
// Stufe 1: Übersicht aller Produktionsaufträge – Produkt, Kunde, Menge, seit wann + Tage bis heute (älteste zuerst).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$tab  = $_GET['tab'] ?? 'offen';
if (!in_array($tab, ['offen', 'erledigt', 'alle'], true)) $tab = 'offen';
$sort = $_GET['sort'] ?? 'tage';
$dir  = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

$rows = all("SELECT pa.id, pa.nummer, pa.kunde_id, pa.produkt_id, pa.auftrag_id, pa.menge, pa.status, pa.angelegt, pa.art_festgelegt_am, k.firma AS kunde_firma,
                    (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=1) AS n_done,
                    (SELECT station FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=0 ORDER BY s.sort LIMIT 1) AS naechste_station,
                    COALESCE(NULLIF(a.produkt_bezeichnung,''), NULLIF(p.name,''), CONCAT(rz.name, ' · Bulk')) AS produkt_name
             FROM produktionsauftrag pa
             LEFT JOIN kunden k ON k.id=pa.kunde_id LEFT JOIN produkt p ON p.id=pa.produkt_id
             LEFT JOIN rezeptur rz ON rz.id=pa.rezeptur_id LEFT JOIN auftrag a ON a.id=pa.auftrag_id
             WHERE " . pa_aktiv_sql());   // ohne stornierte PAs / PAs stornierter Aufträge

// Bereits produziert = als Ware gebuchte Chargen des PA (charge.pa_id), in EINER Abfrage. Bei Produkt-PAs nur
// Fertigware (verkaufsfertig), damit keine Bulk-/Zwischenchargen mitzählen; Bulk-PAs (ohne Produkt) alle ihre Chargen.
$prodMap = [];
foreach (all("SELECT c.pa_id, i.kategorie, COALESCE(SUM(c.menge),0) AS m FROM charge c JOIN item i ON i.id=c.item_id
              WHERE c.pa_id IS NOT NULL GROUP BY c.pa_id, i.kategorie") as $row)
    $prodMap[(int)$row['pa_id']][(string)$row['kategorie']] = (float)$row['m'];

// Fertigware in Quarantäne je PA (wartet auf QM-Freigabe).
$quarMap = [];
foreach (all("SELECT c.pa_id, COALESCE(SUM(c.menge_verfuegbar),0) AS m FROM charge c JOIN item i ON i.id=c.item_id
              WHERE c.pa_id IS NOT NULL AND c.status='quarantaene' AND i.kategorie='verkaufsfertig' GROUP BY c.pa_id") as $row)
    $quarMap[(int)$row['pa_id']] = (float)$row['m'];

// „Wartet auf": was fehlt, damit der PA weiter-/fertig wird. Gleiche Quellen wie die bisherige Liste
// (produktion_bereitschaft / produktion_material_bestellt / offene Schritte) – keine eigene Statuslogik.
// Rückgabe ['text'=>Klartext, 'art'=>'err'|'info'|'ok'|'muted', 'tip'=>Tooltip].
$GLOBALS['bx_stock_cache'] = [];   // Seite ist schreibfrei -> Bestände/Zeilen dürfen request-lokal gecacht werden
$wartetAuf = function (array $r) use ($quarMap): array {
    $quar = $quarMap[(int)$r['id']] ?? 0.0;
    $qm = $quar > 0 ? 'QM-Freigabe (' . number_format($quar, 0, ',', '.') . ' Stk in Quarantäne)' : '';
    if ($r['status'] === 'erledigt') return $qm !== '' ? ['text' => $qm, 'art' => 'info', 'tip' => ''] : ['text' => 'nichts – fertig', 'art' => 'muted', 'tip' => ''];
    if ($r['status'] === 'vorbereitung') return ['text' => 'Beschaffungsweg festlegen', 'art' => 'err', 'tip' => 'Unter Produktion → Beschaffungsweg offen'];
    if (empty($r['art_festgelegt_am'])) return ['text' => 'Eigen/Fremd festlegen', 'art' => 'err', 'tip' => ''];
    if ((int)$r['n_done'] === 0) {
        $ber = produktion_bereitschaft((int)$r['id']);
        if ($ber['status'] === 'wartet') {
            $rollen = array_values(array_unique(array_map(fn($f) => (string)($f['rolle'] ?? 'Material'), $ber['fehlend'])));
            $tip = implode("
", array_map(fn($f) => $f['name'] . ': ' . number_format((float)$f['fehlt'], 0, ',', '.') . ' ' . ($f['einheit'] ?? ''), $ber['fehlend']));
            $mb = produktion_material_bestellt($ber['fehlend'], (int)($r['auftrag_id'] ?? 0));
            if ($mb['ja']) return ['text' => 'Lieferung: ' . implode(', ', $rollen) . ($mb['eta'] ? ' (bis ' . date('d.m.Y', strtotime($mb['eta'])) . ')' : ''), 'art' => 'info', 'tip' => $tip];
            return ['text' => 'Material: ' . implode(', ', $rollen), 'art' => 'err', 'tip' => $tip];
        }
    }
    $st = trim((string)($r['naechste_station'] ?? ''));
    if ($st === '') return ['text' => $qm !== '' ? $qm : 'Abschluss', 'art' => 'info', 'tip' => ''];
    $start = (int)$r['n_done'] === 0 ? 'Start: ' : '';
    // Beim Etikettieren zusätzlich zeigen, ob die Etiketten da sind (häufige Frage).
    if (mb_stripos($st, 'etikett') !== false) {
        $etFehlt = array_filter(auftrag_bedarf_rest((int)$r['id']), fn($b) => ($b['rolle'] ?? '') === 'Etikett' && (float)$b['fehlt'] > 1e-6);
        return $etFehlt ? ['text' => $start . $st . ' – Etiketten fehlen', 'art' => 'err', 'tip' => '']
                        : ['text' => $start . $st . ' (Etiketten da)', 'art' => 'ok', 'tip' => ''];
    }
    return ['text' => $start . $st . ($qm !== '' ? ' · ' . $qm : ''), 'art' => 'ok', 'tip' => ''];
};

$heute = new DateTime('today', new DateTimeZone('Europe/Berlin'));
$n = ['offen' => 0, 'erledigt' => 0, 'alle' => count($rows)];
foreach ($rows as &$r) {
    // Kundenspezifischer Produktname, falls gepflegt (sonst Name aus Auftrag/Produkt).
    if (!empty($r['produkt_id']) && !empty($r['kunde_id'])) {
        $kn = produkt_kundenname((int)$r['produkt_id'], (int)$r['kunde_id']);
        if ($kn !== '') $r['produkt_name'] = $kn;
    }
    $seit = new DateTime(fmt_zeit((string)$r['angelegt'], 'Y-m-d'), new DateTimeZone('Europe/Berlin'));   // angelegt ist UTC
    $r['seit'] = $seit->format('Y-m-d');
    $r['tage'] = (int)$seit->diff($heute)->days;
    $pm = $prodMap[(int)$r['id']] ?? [];
    $r['produziert'] = !empty($r['produkt_id']) ? (float)($pm['verkaufsfertig'] ?? 0) : (float)array_sum($pm);
    $r['wartet'] = $wartetAuf($r);
    $n[$r['status'] === 'erledigt' ? 'erledigt' : 'offen']++;
}
unset($r);
if ($tab !== 'alle') $rows = array_values(array_filter($rows, fn($r) => ($r['status'] === 'erledigt') === ($tab === 'erledigt')));

$key = ['tage' => fn($r) => $r['tage'], 'nummer' => fn($r) => (string)$r['nummer'], 'produkt' => fn($r) => mb_strtolower((string)$r['produkt_name']),
        'kunde' => fn($r) => mb_strtolower((string)$r['kunde_firma']), 'menge' => fn($r) => (float)$r['menge'], 'produziert' => fn($r) => $r['produziert'], 'wartet' => fn($r) => $r['wartet']['text']][$sort] ?? null;
if (!$key) { $sort = 'tage'; $key = fn($r) => $r['tage']; }
usort($rows, fn($x, $y) => ($dir === 'asc' ? 1 : -1) * ($key($x) <=> $key($y)) ?: ($y['tage'] <=> $x['tage']));

render_header('produktion_neu', 'Produktion (neu)');
bx_head('Produktion (neu)', count($rows) . ' Produktionsaufträge', bx_hint('Im Aufbau. Die bisherige Produktionsliste bleibt unverändert, bis diese Seite fertig ist.'));
bx_tabs(['offen' => 'Offen (' . $n['offen'] . ')', 'erledigt' => 'Abgeschlossen (' . $n['erledigt'] . ')', 'alle' => 'Alle (' . $n['alle'] . ')'], $tab, '?p=produktion_neu');
?>
<div class="bx-panel">
<?php bx_table([
    'nummer'  => ['label' => 'Nummer', 'sort' => true, 'render' => fn($r) => '<strong>' . h((string)($r['nummer'] ?: '–')) . '</strong>'],
    'produkt' => ['label' => 'Produkt', 'sort' => true, 'render' => fn($r) => h((string)($r['produkt_name'] ?? '')) ?: '<span class="muted">–</span>'],
    'kunde'   => ['label' => 'Kunde', 'sort' => true, 'render' => fn($r) => $r['kunde_id'] ? kunde_link($r['kunde_id'], $r['kunde_firma']) : '<span class="muted">Lager</span>'],
    'menge'   => ['label' => 'Menge', 'sort' => true, 'num' => true, 'render' => fn($r) => number_format((float)$r['menge'], 0, ',', '.') . ' Stk'],
    'produziert' => ['label' => 'Produziert', 'sort' => true, 'num' => true, 'render' => function($r) {
                    $g = $r['produziert']; $z = (float)$r['menge'];
                    if ($g <= 0.0001) return '<span class="muted">–</span>';
                    $voll = $z > 0 && $g + 0.001 >= $z;
                    return '<span style="color:' . ($voll ? 'var(--gruen,#1D9E75)' : 'inherit') . '">' . number_format($g, 0, ',', '.') . ' Stk</span>'
                         . ($voll ? '' : ' <span class="muted" style="font-size:12px">(' . round($g / max($z, 1) * 100) . ' %)</span>');
                 }],
    'wartet'  => ['label' => 'Wartet auf', 'sort' => true, 'render' => function($r) {
                    $w = $r['wartet'];
                    $farbe = ['err' => 'var(--err)', 'info' => 'inherit', 'ok' => 'var(--gruen,#1D9E75)', 'muted' => 'var(--muted)'][$w['art']] ?? 'inherit';
                    return '<span style="color:' . $farbe . '"' . ($w['tip'] !== '' ? ' title="' . h($w['tip']) . '"' : '') . '>' . h($w['text']) . '</span>';
                 }],
    'seit'    => ['label' => 'Seit', 'render' => fn($r) => h(date('d.m.Y', strtotime($r['seit'])))],
    'tage'    => ['label' => 'Tage', 'sort' => true, 'num' => true, 'render' => fn($r) => '<strong>' . $r['tage'] . '</strong>'],
], $rows, [
    'baseUrl' => '?p=produktion_neu&tab=' . $tab, 'sort' => $sort, 'dir' => $dir,
    'rowUrl'  => fn($r) => '?p=produktionsauftrag&id=' . (int)$r['id'],
    'empty'   => 'Keine Produktionsaufträge.',
]); ?>
</div>
<?php unset($GLOBALS['bx_stock_cache']); render_footer();
