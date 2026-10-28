<?php
// Einkauf – „Bestellt": EINE Zeile je Bestellung (nicht je Position). Inhalt zusammengefasst
// (ein Produkt direkt, mehrere als „N Produkte" mit Namensliste). Klick öffnet die Bestellung.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

// B4: alle überfälligen, unbestätigten Portal-Bestellungen jetzt erinnern (Mail über Dashboard-SMTP).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'erinnern_alle') {
    require_once BX_ROOT . '/core/mail.php';
    $r = einkauf_erinnerungen_cron(3, 0);   // minAbstand 0: manueller Klick erinnert sofort
    header('Location: ?p=einkauf&erinnert=' . (int)$r['gesendet']); exit;
}
// B4: einzelne Bestellung stornieren (aus der Liste heraus).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'storno') {
    $wer = trim((string)(current_user()['name'] ?? '')) ?: 'Team';
    $res = bestellung_stornieren((int)($_POST['bid'] ?? 0), $wer, trim((string)($_POST['grund'] ?? '')));
    header('Location: ?p=einkauf&' . ($res['ok'] ? 'storniert=1' : 'stornofehler=' . urlencode($res['msg']))); exit;
}

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
                b.lieferant_id, b.eta_geplant, b.station, b.tracking, b.angekommen_am, b.erinnert_am,
                b.bestellart, l.firma AS lieferant_firma
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
            'bestellung_id'=>$bid, 'id'=>$bid, 'nummer'=>(string)$r['nummer'], 'status'=>(string)$r['status'],
            'bestelldatum'=>$r['bestelldatum'], 'angelegt'=>$r['angelegt'], 'bestaetigt'=>(int)$r['bestaetigt'],
            'lieferant_id'=>$r['lieferant_id'], 'lieferant_firma'=>(string)($r['lieferant_firma'] ?? ''),
            'station'=>(string)($r['station'] ?? ''), 'tracking'=>(string)($r['tracking'] ?? ''),
            'angekommen_am'=>$r['angekommen_am'], 'erinnert_am'=>$r['erinnert_am'], 'bestellart'=>(string)($r['bestellart'] ?? ''),
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
// B5: echte Statuskette über bestellung_stufe() (wartet auf Bestätigung -> bestätigt/Station -> versandbereit
// -> teilweise eingegangen -> versendet -> eingegangen). Für Lieferant ohne Portal/Online der selbstgepflegte Stand.
$statusBadge = function($r) {
    $s = bestellung_stufe($r);
    return bx_badge($s['label'], $s['kind']);
};
// B4: „Offen seit" – Tage seit Bestellung; unbestätigte Portal-Bestellungen ab 3 Tagen überfällig (T+N).
$offenCell = function($r) {
    if (in_array((string)$r['status'], ['geliefert','storniert','offen'], true)) return '<span class="muted">–</span>';
    $tage = bestellung_tage_offen($r);
    $txt = $tage <= 0 ? 'heute' : $tage . ' Tag' . ($tage === 1 ? '' : 'e');
    if (bestellung_ueberfaellig($r)) return bx_badge('überfällig · T+' . $tage, 'err');
    return '<span class="muted">' . h($txt) . '</span>' . (!empty($r['erinnert_am']) ? ' <span class="muted" style="font-size:11px">· erinnert</span>' : '');
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
    'offen'           => ['label'=>'Offen seit', 'render'=>$offenCell],
    'status'          => ['label'=>'Status', 'sort'=>true, 'render'=>$statusBadge],
];

render_header('einkauf', $archiv ? 'Bestellt – Archiv' : 'Bestellt');
bx_head($archiv ? 'Bestellt – Archiv' : 'Bestellt',
        count($rows) . ($archiv ? ' gelieferte Bestellungen' : ' offene Bestellungen (unterwegs / wartet auf Bestätigung)'),
        bx_btn('Zu „Bestellen"', '?p=einkaufsliste', 'ghost') . ' ' . bx_btn('Neue Bestellung', '?p=bestellung&id=neu', 'ghost'));
if (isset($_GET['vorsorglich'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Vorsorgliche Bestellung angelegt – sie steht jetzt hier unter „Bestellt".</div>';
if (isset($_GET['erinnert']))    echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['erinnert'] . ' Erinnerung(en) an Portal-Lieferanten gesendet.</div>';
if (isset($_GET['storniert']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Bestellung storniert.</div>';
if (isset($_GET['stornofehler'])) echo '<div class="bx-panel" style="border-color:var(--warn);border-left:3px solid var(--warn);padding:12px 16px">' . h((string)$_GET['stornofehler']) . '</div>';
// B4: Überfällige (unbestätigt, Portal, >=3 Tage) hervorheben + Sammel-Erinnerung + Cron-Hinweis (Admin).
if (!$archiv):
    $ueberfaellig = array_values(array_filter($rows, fn($r) => bestellung_ueberfaellig($r)));
    if ($ueberfaellig):
?>
<div class="bx-panel" style="border-color:var(--warn);border-left:3px solid var(--warn);padding:12px 16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
  <strong><?= count($ueberfaellig) ?> überfällige Bestellung(en)</strong>
  <span class="muted" style="font-size:13px">seit ≥ 3 Tagen unbestätigt (Portal-Lieferant).</span>
  <span style="flex:1"></span>
  <form method="post" style="margin:0" onsubmit="return confirm('Allen überfälligen Portal-Lieferanten jetzt eine Erinnerungsmail senden?');">
    <input type="hidden" name="aktion" value="erinnern_alle">
    <button class="btn btn-primary btn-sm" type="submit">Überfällige jetzt erinnern</button>
  </form>
</div>
<?php endif;
    if (function_exists('has_role') && has_role('admin')):
        $cronUrl = (function_exists('mail_basis_url') ? mail_basis_url() : '') . '/einkauf_cron.php?token=' . einkauf_cron_token();
?>
<details class="bx-panel" style="padding:10px 16px;margin-bottom:12px">
  <summary style="cursor:pointer;font-size:13px" class="muted">Automatische Erinnerungen (Cron) einrichten</summary>
  <p class="muted" style="font-size:13px;margin:8px 0 0">Server-Cron (z. B. 1× täglich) diese URL aufrufen – erinnert alle überfälligen, unbestätigten Portal-Bestellungen (max. 1×/24 h je Bestellung):</p>
  <code style="display:block;word-break:break-all;background:var(--panel-2);padding:8px 10px;border-radius:8px;margin-top:6px;font-size:12px"><?= h($cronUrl) ?></code>
</details>
<?php endif; endif; ?>
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
