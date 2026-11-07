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

$rows = all("SELECT pa.id, pa.nummer, pa.kunde_id, pa.produkt_id, pa.menge, pa.status, pa.angelegt, k.firma AS kunde_firma,
                    COALESCE(NULLIF(a.produkt_bezeichnung,''), NULLIF(p.name,''), CONCAT(rz.name, ' · Bulk')) AS produkt_name
             FROM produktionsauftrag pa
             LEFT JOIN kunden k ON k.id=pa.kunde_id LEFT JOIN produkt p ON p.id=pa.produkt_id
             LEFT JOIN rezeptur rz ON rz.id=pa.rezeptur_id LEFT JOIN auftrag a ON a.id=pa.auftrag_id
             WHERE " . pa_aktiv_sql());   // ohne stornierte PAs / PAs stornierter Aufträge

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
    $n[$r['status'] === 'erledigt' ? 'erledigt' : 'offen']++;
}
unset($r);
if ($tab !== 'alle') $rows = array_values(array_filter($rows, fn($r) => ($r['status'] === 'erledigt') === ($tab === 'erledigt')));

$key = ['tage' => fn($r) => $r['tage'], 'nummer' => fn($r) => (string)$r['nummer'], 'produkt' => fn($r) => mb_strtolower((string)$r['produkt_name']),
        'kunde' => fn($r) => mb_strtolower((string)$r['kunde_firma']), 'menge' => fn($r) => (float)$r['menge']][$sort] ?? null;
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
    'seit'    => ['label' => 'Seit', 'render' => fn($r) => h(date('d.m.Y', strtotime($r['seit'])))],
    'tage'    => ['label' => 'Tage', 'sort' => true, 'num' => true, 'render' => fn($r) => '<strong>' . $r['tage'] . '</strong>'],
], $rows, [
    'baseUrl' => '?p=produktion_neu&tab=' . $tab, 'sort' => $sort, 'dir' => $dir,
    'rowUrl'  => fn($r) => '?p=produktionsauftrag&id=' . (int)$r['id'],
    'empty'   => 'Keine Produktionsaufträge.',
]); ?>
</div>
<?php render_footer();
