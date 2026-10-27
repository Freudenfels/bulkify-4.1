<?php
// Einkauf – „Bestellt": EINE Zeile je Bestellung (nicht je Position). Inhalt zusammengefasst
// (ein Produkt direkt, mehrere als „N Produkte" mit Namensliste). Klick öffnet die Bestellung.
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

// Laufend = alles außer geliefert UND storniert (aufgeteilte Ur-Bestellungen sind storniert und sollen hier weg).
$wo = $archiv ? "WHERE b.status='geliefert'" : "WHERE b.status NOT IN ('geliefert','storniert')";
// Positionen holen (mit Kopfdaten der Bestellung) und in PHP je Bestellung zusammenfassen.
$posRows = all("SELECT bp.id AS pos_id, bp.menge, bp.einheit, bp.bestellung_id, bp.item_id, bp.sort,
                COALESCE(NULLIF(bp.bezeichnung,''), i.name, '–') AS produkt,
                b.nummer, b.status, b.bestelldatum, b.angelegt, COALESCE(b.bestaetigt,0) AS bestaetigt,
                b.lieferant_id, b.eta_geplant, l.firma AS lieferant_firma
                FROM bestellung_position bp
                JOIN bestellung b ON b.id=bp.bestellung_id
                LEFT JOIN item i ON i.id=bp.item_id
                LEFT JOIN lieferanten l ON l.id=b.lieferant_id
                $wo ORDER BY bp.sort, bp.id");
$anzArchiv = (int) scalar("SELECT COUNT(DISTINCT b.id) FROM bestellung b WHERE b.status='geliefert'");

// Produktname ohne „(AB-…)"/„(Puffer…)"-Zusatz – für die Bündel-Zählung (verschiedene Bulk-Produkte).
$basisName = fn(string $p) => trim(preg_replace('/\s*\(.*$/', '', preg_replace('/^Bulk:\s*/', '', $p)));

$best = [];   // bestellung_id => aggregierte Zeile
foreach ($posRows as $r) {
    $bid = (int)$r['bestellung_id'];
    if (!isset($best[$bid])) {
        $best[$bid] = [
            'bestellung_id'=>$bid, 'nummer'=>(string)$r['nummer'], 'status'=>(string)$r['status'],
            'bestelldatum'=>$r['bestelldatum'], 'angelegt'=>$r['angelegt'], 'bestaetigt'=>(int)$r['bestaetigt'],
            'lieferant_id'=>$r['lieferant_id'], 'lieferant_firma'=>(string)($r['lieferant_firma'] ?? ''),
            'pos_anz'=>0, 'produkte'=>[], 'bulk_basis'=>[], 'erstes'=>(string)$r['produkt'],
            'einzel_menge'=>(float)$r['menge'], 'einzel_einheit'=>(string)($r['einheit'] ?? ''),
        ];
    }
    $best[$bid]['pos_anz']++;
    $best[$bid]['produkte'][] = (string)$r['produkt'];
    if (empty($r['item_id']) && strncmp((string)$r['produkt'], 'Bulk', 4) === 0)
        $best[$bid]['bulk_basis'][mb_strtolower($basisName((string)$r['produkt']))] = true;
}
$rows = array_values($best);

if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        if (mb_strpos(mb_strtolower((string)$r['nummer']), $needle) !== false) return true;
        if (mb_strpos(mb_strtolower((string)$r['lieferant_firma']), $needle) !== false) return true;
        foreach ($r['produkte'] as $p) if (mb_strpos(mb_strtolower((string)$p), $needle) !== false) return true;
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

$mfmt = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
// Status je Zeile: geliefert > bestätigt > wartet auf Bestätigung (Portal-Lieferant) > bestellt (erfasst) > Entwurf.
$statusBadge = function($r) use ($zugangIds) {
    $st = (string)$r['status'];
    if ($st === 'geliefert')             return bx_badge('geliefert','ok');
    if ($st === 'storniert')             return bx_badge('storniert');
    if ((int)$r['bestaetigt'] === 1 || $st === 'bestaetigt') return bx_badge('bestätigt','ok');
    $portal = !empty($r['lieferant_id']) && isset($zugangIds[(int)$r['lieferant_id']]);
    if ($portal && in_array($st, ['gesendet','bestellt'], true)) return bx_badge('wartet auf Bestätigung','warn');
    if ($st === 'offen')                 return bx_badge('Entwurf','info');
    return bx_badge('bestellt','info');   // extern / Lieferant ohne Zugang: nur erfasst
};
// Inhalt: ein Produkt -> Name + Menge; mehrere -> „N Positionen" + Namensliste (verschiedene, gekürzt).
$inhalt = function($r) use ($mfmt) {
    if ((int)$r['pos_anz'] <= 1)
        return h((string)$r['erstes']) . ' <span class="muted">· ' . $mfmt($r['einzel_menge']) . ' ' . h((string)$r['einzel_einheit']) . '</span>';
    $namen = array_values(array_unique($r['produkte']));
    $kurz = array_slice($namen, 0, 4);
    $liste = implode(' · ', array_map('h', $kurz)) . (count($namen) > 4 ? ' …' : '');
    return '<strong>' . (int)$r['pos_anz'] . ' Positionen</strong><div class="muted" style="font-size:12px;margin-top:2px">' . $liste . '</div>';
};

$cols = [
    'nummer'          => ['label'=>'Bestellung', 'sort'=>true, 'render'=>function($r) {
        $nr = h((string)$r['nummer']);
        $n  = count($r['bulk_basis']);
        return $nr . ($n >= 2 ? ' ' . bx_badge('Bündel: ' . $n . ' Produkte', 'warn') : '');
    }],
    'lieferant_firma' => ['label'=>'Lieferant', 'sort'=>true, 'render'=>fn($r)=> $r['lieferant_firma'] !== '' ? h($r['lieferant_firma']) : '<span class="muted">extern</span>'],
    'inhalt'          => ['label'=>'Inhalt', 'render'=>$inhalt],
    'bestelldatum'    => ['label'=>'Bestellt am', 'sort'=>true, 'render'=>fn($r)=> !empty($r['bestelldatum']) ? h(date('d.m.Y', strtotime((string)$r['bestelldatum']))) : '<span class="muted">–</span>'],
    'status'          => ['label'=>'Status', 'sort'=>true, 'render'=>$statusBadge],
];

render_header('einkauf', $archiv ? 'Bestellt – Archiv' : 'Bestellt');
bx_head($archiv ? 'Bestellt – Archiv' : 'Bestellt',
        count($rows) . ($archiv ? ' gelieferte Bestellungen' : ' offene Bestellungen (unterwegs / wartet auf Bestätigung)'),
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
