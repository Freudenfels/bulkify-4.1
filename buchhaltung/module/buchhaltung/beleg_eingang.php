<?php
// Beleg-Posteingang: Übersicht der hochgeladenen/eingegangenen Belege (KI-ausgelesen), mit Upload,
// Handy-Foto-Link, E-Mail-Abholung (falls konfiguriert) und Steuerberater-Paket. Route: beleg_eingang.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/belegeingang.php';
be_init();

$hinweis = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['aktion'] ?? '';
    if ($a === 'token_neu') { be_token_neu(); header('Location: ?p=beleg_eingang&tokneu=1'); exit; }
    if ($a === 'mail_abholen') { $r = be_mail_abholen(); header('Location: ?p=beleg_eingang&mail=' . ($r['ok'] ? (int)$r['anzahl'] : 'fehler')); exit; }
}

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$status = preg_replace('/[^a-z]/', '', $_GET['status'] ?? '');
$q = trim($_GET['q'] ?? '');

$anzNeu    = be_zaehlen('neu');
$anzErfasst= be_zaehlen('erfasst');
$jahr = (int) date('Y');
$sumJahr = (float) scalar("SELECT COALESCE(SUM(brutto),0) FROM bu_beleg_eingang WHERE status IN ('erfasst','verbucht') AND datum IS NOT NULL AND YEAR(datum)=?", [$jahr]);

$rows = be_liste($status, $q);

$fotoBase = (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off' ? 'http' : 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$fotoLink = $fotoBase . '/buchhaltung/?p=beleg_foto&token=' . be_token();

$statusBadge = fn(string $s) => match ($s) {
    'neu'      => bx_badge('neu', 'warn'),
    'erfasst'  => bx_badge('erfasst', 'info'),
    'verbucht' => bx_badge('verbucht', 'ok'),
    'verworfen'=> bx_badge('verworfen', 'err'),
    default    => bx_badge($s),
};
$artLabel = ['eingangsrechnung' => 'Eingangsrechnung', 'quittung' => 'Quittung', 'sonstiges' => 'Sonstiges'];

render_header('beleg_eingang', 'Beleg-Posteingang');
bx_head('Beleg-Posteingang', 'Belege hochladen · KI liest aus · fürs Steuerbüro sammeln',
        bx_btn('+ Beleg hochladen', '?p=beleg_upload', 'primary'));
if (isset($_GET['tokneu'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Neuer Foto-Link erzeugt – der alte Link funktioniert nicht mehr.</div>';
if (isset($_GET['mail'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . ($_GET['mail'] === 'fehler' ? 'E-Mail-Abholung nicht möglich (Postfach/Extension prüfen).' : (int)$_GET['mail'] . ' Beleg(e) aus dem Postfach geholt.') . '</div>';
if (isset($_GET['hochgeladen'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['hochgeladen'] . ' Beleg(e) hochgeladen und ausgelesen – bitte unten prüfen und erfassen.</div>';
?>
<div class="bx-cards">
  <a class="bx-card" style="text-decoration:none;min-width:150px" href="?p=beleg_eingang&status=neu"><div class="k">Neu (zu prüfen)</div><div class="v" style="<?= $anzNeu ? 'color:var(--warn)' : '' ?>"><?= $anzNeu ?: '<span class="muted">0</span>' ?></div></a>
  <a class="bx-card" style="text-decoration:none;min-width:150px" href="?p=beleg_eingang&status=erfasst"><div class="k">Erfasst</div><div class="v"><?= $anzErfasst ?: '<span class="muted">0</span>' ?></div></a>
  <div class="bx-card" style="min-width:170px"><div class="k">Belege <?= $jahr ?> (brutto)</div><div class="v"><?= $eur($sumJahr) ?></div></div>
</div>

<div class="bx-cards" style="align-items:flex-start">
  <div class="bx-panel" style="flex:1;min-width:320px">
    <h2>Per Handy-Foto hochladen</h2>
    <p class="muted" style="margin-top:0">Diesen Link auf dem Handy öffnen (z. B. als Lesezeichen) und den Beleg direkt abfotografieren – ohne Login. Nicht öffentlich teilen.</p>
    <input type="text" readonly value="<?= h($fotoLink) ?>" onclick="this.select()" style="width:100%;font-size:12px">
    <form method="post" style="margin-top:10px" onsubmit="return confirm('Neuen Foto-Link erzeugen? Der alte wird ungültig.');">
      <input type="hidden" name="aktion" value="token_neu">
      <button class="btn btn-ghost btn-sm" type="submit">Neuen Link erzeugen</button>
    </form>
  </div>
  <div class="bx-panel" style="flex:1;min-width:320px">
    <h2>Steuerberater-Paket</h2>
    <p class="muted" style="margin-top:0">Alle erfassten Belege eines Zeitraums als ZIP (Excel-Tabelle + alle Belegdateien) herunterladen.</p>
    <form class="bx-listbar" method="get" style="margin:0" action="">
      <input type="hidden" name="p" value="beleg_export"><input type="hidden" name="art" value="stb_zip">
      <label class="muted" style="align-self:center">von</label><input type="date" name="von" value="<?= $jahr ?>-01-01">
      <label class="muted" style="align-self:center">bis</label><input type="date" name="bis" value="<?= $jahr ?>-12-31">
      <button class="btn btn-primary btn-sm" type="submit">ZIP herunterladen</button>
    </form>
    <p style="margin:8px 0 0"><a class="btn btn-ghost btn-sm" href="?p=beleg_export&art=stb_csv&von=<?= $jahr ?>-01-01&bis=<?= $jahr ?>-12-31">Nur Excel-Tabelle (CSV)</a>
    <?php if (be_mail_bereit()): ?><form method="post" style="display:inline;margin-left:6px"><input type="hidden" name="aktion" value="mail_abholen"><button class="btn btn-ghost btn-sm" type="submit">E-Mail-Postfach abholen</button></form><?php endif; ?></p>
  </div>
</div>

<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="beleg_eingang">
  <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Lieferant, Belegnr, Kategorie …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== '' || $status !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=beleg_eingang">alle</a><?php endif; ?>
  <span style="flex:1"></span>
  <span class="muted" style="align-self:center"><?= count($rows) ?> Belege<?= $status ? ' (' . h($status) . ')' : '' ?></span>
</form>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr><th>Datum</th><th>Lieferant/Aussteller</th><th>Art</th><th>Kategorie</th><th class="bx-num">Brutto</th><th>Status</th><th>Quelle</th></tr></thead>
  <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">Noch keine Belege. Oben „+ Beleg hochladen" oder per Handy-Foto-Link.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr style="cursor:pointer" onclick="location.href='?p=beleg_detail&id=<?= (int)$r['id'] ?>'">
        <td><?= $r['datum'] ? h(date('d.m.Y', strtotime($r['datum']))) : '<span class="muted">–</span>' ?></td>
        <td><?= $r['lieferant_name'] ? h($r['lieferant_name']) : '<span class="muted">–</span>' ?><?= $r['beleg_nummer'] ? ' <span class="muted">· ' . h($r['beleg_nummer']) . '</span>' : '' ?></td>
        <td><?= h($artLabel[$r['belegart']] ?? $r['belegart']) ?></td>
        <td><?= $r['kategorie'] ? h($r['kategorie']) : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= (float)$r['brutto'] != 0 ? $eur($r['brutto']) . ($r['waehrung'] !== 'EUR' ? ' <span class="muted">' . h($r['waehrung']) . '</span>' : '') : '<span class="muted">–</span>' ?></td>
        <td><?= $statusBadge($r['status']) ?></td>
        <td><span class="muted"><?= h(['upload'=>'Upload','foto'=>'Foto','mail'=>'E-Mail'][$r['quelle']] ?? $r['quelle']) ?></span></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<?php
render_footer();
