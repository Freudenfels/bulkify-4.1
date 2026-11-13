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
$posRows = all("SELECT bp.id AS pos_id, bp.menge, bp.einheit, bp.bestellung_id, COALESCE(bp.geliefert_menge,0) AS gel, bp.rest_storniert_am, bp.item_id, bp.sort, bp.auftrag_id, au.nummer AS auftrag_nr,
                COALESCE(NULLIF(bp.bezeichnung,''), i.name, '–') AS produkt,
                b.nummer, b.status, b.bestelldatum, b.angelegt, COALESCE(b.bestaetigt,0) AS bestaetigt,
                b.lieferant_id, b.eta_geplant, b.station, b.tracking, b.angekommen_am, b.erinnert_am,
                b.bestellart, l.firma AS lieferant_firma
                FROM bestellung_position bp
                JOIN bestellung b ON b.id=bp.bestellung_id
                LEFT JOIN item i ON i.id=bp.item_id
                LEFT JOIN lieferanten l ON l.id=b.lieferant_id
                LEFT JOIN auftrag au ON au.id=bp.auftrag_id
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
            'einzel_menge'=>(float)$r['menge'], 'einzel_einheit'=>(string)($r['einheit'] ?? ''), 'auftraege'=>[],
        ];
    }
    if (!empty($r['auftrag_id'])) $best[$bid]['auftraege'][(int)$r['auftrag_id']] = (string)$r['auftrag_nr'];
    // Sofortfix 13.11.2026: erste noch offene Position -> Knopf „Ware ist da“ führt in den Wareneingang dieser Position.
    if (empty($best[$bid]['erste_offen']) && empty($r['rest_storniert_am']) && (float)$r['menge'] > 0 && (float)$r['gel'] < (float)$r['menge'] * 0.98)
        $best[$bid]['erste_offen'] = (int)$r['pos_id'];
    $best[$bid]['pos_anz']++;
    $best[$bid]['produkte'][] = (string)$r['produkt'];
    if (empty($r['item_id']) && strncmp((string)$r['produkt'], 'Bulk', 4) === 0)
        $best[$bid]['bulk_basis'][mb_strtolower($basisName((string)$r['produkt']))] = true;
}
$rows = array_values($best);
require_once BX_ROOT . '/core/lieferant_unterlagen.php';   // Kennzeichen „Unterlagen fehlen“ (Paket 12.11.2026 Teil 4)

if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        if (mb_strpos(mb_strtolower((string)$r['nummer']), $needle) !== false) return true;
        if (mb_strpos(mb_strtolower((string)$r['lieferant_firma']), $needle) !== false) return true;
        foreach ($r['produkte'] as $p) if (mb_strpos(mb_strtolower((string)$p), $needle) !== false) return true;
        foreach ($r['auftraege'] as $an) if (mb_strpos(mb_strtolower((string)$an), $needle) !== false) return true;
        return false;
    });
}
// 13.11.2026 (Paket Teil 6.5): nach Lieferant filtern (0 = ohne Lieferant/extern) und Ansicht „Offen je Lieferant“.
$liefF = isset($_GET['lief']) && $_GET['lief'] !== '' ? (int)$_GET['lief'] : null;
if ($liefF !== null) $rows = array_filter($rows, fn($r) => (int)($r['lieferant_id'] ?? 0) === $liefF);
$ansicht = ($_GET['ansicht'] ?? '') === 'lieferant' && !$archiv ? 'lieferant' : '';
require_once BX_ROOT . '/core/bestellung_einzeln.php';
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
        $nr = '<a href="?p=bestellung&id=' . (int)$r['id'] . '" onclick="event.stopPropagation()">' . h((string)$r['nummer']) . '</a>';   // Teil 8
        $n  = count($r['bulk_basis']);
        // Paket 12.11.2026 Teil 4: offene Bestellung, aber Unterlagen des Lieferanten fehlen (Liste an der Bestellung).
        $luF = (!in_array((string)$r['status'], ['geliefert', 'storniert'], true) && (int)($r['lieferant_id'] ?? 0) > 0) ? lu_bestellung_fehlt((int)$r['id']) : [];
        return $nr . ($n >= 2 ? ' ' . bx_badge('Bündel: ' . $n . ' Produkte', 'warn') : '')
             . ($luF ? ' <a href="?p=bestellung&id=' . (int)$r['id'] . '#unterlagen" onclick="event.stopPropagation()" title="' . h(lu_liste_text($luF)) . '">' . bx_badge('Unterlagen fehlen', 'warn') . '</a>' : '');
    }],
    'auftrag'         => ['label'=>'Auftrag', 'render'=>fn($r)=> $r['auftraege'] ? implode(', ', array_map(fn($aid, $anr) => '<a href="?p=auftrag&id=' . (int)$aid . '" onclick="event.stopPropagation()">' . h($anr !== '' ? $anr : '#' . $aid) . '</a>', array_keys($r['auftraege']), $r['auftraege'])) : '<span class="muted">Lager</span>'],
    'lieferant_firma' => ['label'=>'Lieferant', 'sort'=>true, 'render'=>fn($r)=> $r['lieferant_firma'] !== '' ? h($r['lieferant_firma']) : '<span class="muted">extern</span>'],
    'inhalt'          => ['label'=>'Inhalt', 'render'=>$inhalt],
    'bestelldatum'    => ['label'=>'Bestellt am', 'sort'=>true, 'render'=>fn($r)=> !empty($r['bestelldatum']) ? h(date('d.m.Y', strtotime((string)$r['bestelldatum']))) : '<span class="muted">–</span>'],
    'offen'           => ['label'=>'Offen seit', 'render'=>$offenCell],
    'status'          => ['label'=>'Status', 'sort'=>true, 'render'=>$statusBadge],
    // Sofortfix 13.11.2026: Lieferung bestätigen heißt immer Wareneingang buchen (Charge, MHD, Menge) – nie „als geliefert schließen“.
    'ware'            => ['label'=>'', 'render'=>$wareKnopf = fn($r) => (in_array((string)$r['status'], ['bestellt', 'gesendet'], true) && empty($r['angekommen_am']) && !empty($r['erste_offen']))
                            ? '<a class="btn btn-primary btn-sm" href="?p=wareneingang&bp=' . (int)$r['erste_offen'] . '" onclick="event.stopPropagation()">Ware ist da</a>' : ''],
];

render_header('einkauf', $archiv ? 'Bestellt – Archiv' : 'Bestellt');
bx_head($archiv ? 'Bestellt – Archiv' : 'Bestellt',
        count($rows) . ($archiv ? ' gelieferte Bestellungen' : ' offene Bestellungen (unterwegs / wartet auf Bestätigung)'),
        bx_btn('Zu „Bestellen"', '?p=einkaufsliste', 'ghost') . ' ' . bx_btn('Neue Bestellung', '?p=bestellung&id=neu', 'ghost'));
if (isset($_GET['vorsorglich'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Vorsorgliche Bestellung angelegt – sie steht jetzt hier unter „Bestellt".</div>';
if (!empty($_GET['lu_hinweis'])) { require_once BX_ROOT . '/core/lieferant_unterlagen.php'; echo lu_hinweis_panel((string)$_GET['lu_hinweis']); }   // 13.11.2026
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
        // Sofortfix 11.11.2026: Token nur maskiert; vollständig erst auf ausdrücklichen Klick (nur Admin).
        $cronTok = einkauf_cron_token(); $cronZeigen = !empty($_GET['cron_zeigen']);
        $cronUrl = (function_exists('mail_basis_url') ? mail_basis_url() : '') . '/einkauf_cron.php?token='
                 . ($cronZeigen ? $cronTok : substr($cronTok, 0, 4) . str_repeat('•', 8) . substr($cronTok, -4));
?>
<div class="muted" style="font-size:13px;margin:0 0 6px"><?= cron_lauf_hinweis() ?></div>
<details class="bx-panel" style="padding:10px 16px;margin-bottom:12px">
  <summary style="cursor:pointer;font-size:13px" class="muted">Automatische Erinnerungen (Cron) einrichten</summary>
  <p class="muted" style="font-size:13px;margin:8px 0 0">Server-Cron 1× täglich (z. B. 7:00 Uhr) diese URL aufrufen – erinnert überfällige, unbestätigte Portal-Bestellungen (max. 1×/24 h je Bestellung), prüft bald ablaufende Preise und erinnert Kunden an fehlende Etiketten (max. 1 Mail je Kunde und Tag):</p>
  <code style="display:block;word-break:break-all;background:var(--panel-2);padding:8px 10px;border-radius:8px;margin-top:6px;font-size:12px"><?= h($cronUrl) ?></code>
  <?php if (!$cronZeigen): ?><a class="btn btn-ghost btn-sm" style="margin-top:6px" href="?p=einkauf&cron_zeigen=1">Vollständig anzeigen (zum Einrichten)</a><?php endif; ?>
</details>
<?php endif; endif; ?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="einkauf">
  <?php if ($archiv): ?><input type="hidden" name="archiv" value="1"><?php endif; ?>
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Produkt, Lieferant, Bestellnummer …">
  <?php $liefOpt = []; foreach ($best as $bx) $liefOpt[(int)($bx['lieferant_id'] ?? 0)] = $bx['lieferant_firma'] !== '' ? $bx['lieferant_firma'] : 'ohne Lieferant (extern)'; asort($liefOpt); ?>
  <select name="lief" onchange="this.form.submit()" style="min-width:200px" class="rscombo" data-ph="Lieferant …"><option value="">alle Lieferanten</option>
    <?php foreach ($liefOpt as $lk => $ll): ?><option value="<?= (int)$lk ?>"<?= $liefF === (int)$lk ? ' selected' : '' ?>><?= h($ll) ?></option><?php endforeach; ?></select>
  <?php if ($ansicht): ?><input type="hidden" name="ansicht" value="lieferant"><?php endif; ?>
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if (!$archiv): ?><a class="btn btn-<?= $ansicht ? 'primary' : 'ghost' ?> btn-sm" href="?p=einkauf<?= $ansicht ? '' : '&ansicht=lieferant' ?>"><?= $ansicht ? 'Liste' : 'Offen je Lieferant' ?></a><?php endif; ?>
  <span style="flex:1"></span>
  <?php if ($archiv): ?>
    <a class="btn btn-ghost btn-sm" href="?p=einkauf">Zurück zu laufenden</a>
  <?php else: ?>
    <a class="btn btn-ghost btn-sm" href="?p=einkauf&archiv=1">Archiv (geliefert)<?= $anzArchiv ? ' (' . $anzArchiv . ')' : '' ?></a>
  <?php endif; ?>
</form>
<?php
if ($ansicht === 'lieferant') {
    // „Offen je Lieferant“ (Teil 6.5): je Lieferant seine offenen Bestellungen mit dem, was noch fehlt.
    $grp = [];
    foreach ($rows as $r) $grp[$r['lieferant_firma'] !== '' ? $r['lieferant_firma'] : 'ohne Lieferant (extern)'][] = $r;
    ksort($grp);
    if (!$grp) echo '<div class="bx-panel muted">Keine offenen Bestellungen.</div>';
    foreach ($grp as $lf => $lr) {
        echo '<div class="bx-panel"><h2 style="margin-top:0">' . h($lf) . ' <span class="muted" style="font-size:14px;font-weight:400">· ' . count($lr) . ' offen</span></h2><div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Bestellung</th><th>Inhalt</th><th>Bestellt am</th><th>Status</th><th>Fehlt noch</th></tr></thead><tbody>';
        foreach ($lr as $r) {
            $fehlt = bestellung_fehlt($r);
            echo '<tr id="be-row-' . (int)$r['id'] . '" style="cursor:pointer" data-pop-url="?p=bestellung_pop&id=' . (int)$r['id'] . '" data-pop-titel="' . h((string)$r['nummer']) . '" data-pop-refresh="#be-row-' . (int)$r['id'] . '">'
               . '<td><a href="?p=bestellung&id=' . (int)$r['id'] . '" onclick="event.stopPropagation()">' . h((string)$r['nummer']) . '</a></td><td>' . $inhalt($r) . '</td>'
               . '<td>' . (!empty($r['bestelldatum']) ? h(date('d.m.Y', strtotime((string)$r['bestelldatum']))) : '–') . '</td><td>' . $statusBadge($r) . '</td>'
               . '<td>' . ($fehlt ? implode(' ', array_map(fn($x) => bx_badge($x, $x === 'Lieferung' ? 'info' : 'warn'), $fehlt)) : bx_badge('vollständig', 'ok')) . ' ' . $wareKnopf($r) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }
} else
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=einkauf' . ($archiv ? '&archiv=1' : '') . ($q !== '' ? '&q=' . urlencode($q) : '') . ($liefF !== null ? '&lief=' . $liefF : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    // 13.11.2026 (Paket Teil 6.4): Klick auf die Zeile öffnet das Bestell-Pop-up (Dokumente, Versand, Sendung, Notiz); die Nummer führt zur Seite.
    'rowPop'  => fn($r) => '?p=bestellung_pop&id=' . $r['bestellung_id'],
    'rowPopTitel' => fn($r) => 'Bestellung ' . $r['nummer'],
    'rowId'   => fn($r) => 'be-row-' . $r['bestellung_id'],
    'empty'   => 'Noch nichts bestellt.',
]);
render_footer();
