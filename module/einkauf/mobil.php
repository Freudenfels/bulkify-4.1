<?php
// Einkauf – Schnellansicht (mobil). Grosse Karten, ein Tap je Statuswechsel:
//   offen (Entwurf) -> "Als bestellt markieren"  -> bestellt
//   bestellt         -> "Ware eingegangen"        -> geliefert (bucht Wareneingang)
// Nutzt dieselben Funktionen wie die Detailseite (einkauf/detail.php).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $st     = $id ? (string) scalar("SELECT status FROM bestellung WHERE id=?", [$id]) : '';
    if ($id && $aktion === 'bestellt' && $st === 'offen') {
        q("UPDATE bestellung SET status='bestellt', bestelldatum=COALESCE(bestelldatum, CURDATE()) WHERE id=?", [$id]);
        if (function_exists('mail_bereit') && mail_bereit() && function_exists('mail_lieferant_bestellung')) mail_lieferant_bestellung($id);
        header('Location: ?p=einkauf_mobil&ok=bestellt#b' . $id); exit;
    }
    if ($id && $aktion === 'liefern' && $st === 'bestellt') {
        bestellung_wareneingang($id);
        header('Location: ?p=einkauf_mobil&ok=eingang#b' . $id); exit;
    }
    header('Location: ?p=einkauf_mobil'); exit;
}

$q  = trim((string)($_GET['q'] ?? ''));
$wo = "b.status IN ('offen','bestellt')";
$args = [];
if ($q !== '') { $wo .= " AND (b.nummer LIKE ? OR l.firma LIKE ?)"; $args[] = '%' . $q . '%'; $args[] = '%' . $q . '%'; }
$rows = all("SELECT b.*, l.firma AS lieferant_firma,
             (SELECT COUNT(*) FROM bestellung_position p WHERE p.bestellung_id=b.id) AS pos_anzahl,
             (SELECT COALESCE(SUM(menge*ek_preis),0) FROM bestellung_position p WHERE p.bestellung_id=b.id) AS summe
             FROM bestellung b LEFT JOIN lieferanten l ON l.id=b.lieferant_id
             WHERE $wo ORDER BY (b.status='bestellt'), b.angelegt DESC", $args);
$offen    = array_values(array_filter($rows, fn($r) => $r['status'] === 'offen'));
$bestellt = array_values(array_filter($rows, fn($r) => $r['status'] === 'bestellt'));
$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';

render_header('einkauf_mobil', 'Einkauf schnell');
?>
<style>
  .em-wrap { max-width:640px }
  .em-card { background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:16px; margin-bottom:14px; box-shadow:var(--shadow) }
  .em-firma { font-size:19px; font-weight:700; line-height:1.2 }
  .em-meta { color:var(--muted); font-size:14px; margin-top:3px }
  .em-btn { display:block; width:100%; text-align:center; padding:15px; border-radius:12px; font-size:17px; font-weight:600; border:1px solid transparent; margin-top:14px; cursor:pointer }
  .em-btn-order { background:var(--panel-2); color:var(--text); border-color:var(--line) }
  .em-btn-in { background:var(--gruen); color:#fff }
  .em-sub { display:inline-block; margin-top:10px; font-size:13px; color:var(--muted); text-decoration:none }
  .em-sec { font-size:14px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; margin:8px 2px 10px; font-weight:600 }
  .em-badge { display:inline-block; font-size:12px; padding:2px 8px; border-radius:999px; background:var(--panel-2); border:1px solid var(--line); color:var(--muted) }
</style>
<div class="em-wrap">
  <?php if (isset($_GET['ok'])): $t = $_GET['ok'] === 'eingang' ? 'Wareneingang gebucht – Bestand aktualisiert.' : 'Als bestellt markiert.'; ?>
    <div class="bx-panel badge-ok" style="padding:12px 16px"><?= h($t) ?></div>
  <?php endif; ?>

  <form method="get" style="margin:0 0 14px">
    <input type="hidden" name="p" value="einkauf_mobil">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Lieferant oder Nummer …" style="width:100%;padding:11px 14px;border:1px solid var(--line);border-radius:999px;background:var(--panel);font-size:16px">
  </form>

  <?php if (!$offen && !$bestellt): ?>
    <div class="bx-panel"><div class="muted"><?= $q !== '' ? 'Keine Treffer.' : 'Nichts offen – alle Bestellungen sind geliefert.' ?></div></div>
  <?php endif; ?>

  <?php if ($offen): ?>
    <div class="em-sec">Zu bestellen (<?= count($offen) ?>)</div>
    <?php foreach ($offen as $r): ?>
    <div class="em-card" id="b<?= (int)$r['id'] ?>">
      <div class="em-firma"><?= h($r['lieferant_firma'] ?: 'Ohne Lieferant') ?></div>
      <div class="em-meta"><?= h($r['nummer']) ?> · <?= (int)$r['pos_anzahl'] ?> Position<?= (int)$r['pos_anzahl'] === 1 ? '' : 'en' ?><?= (float)$r['summe'] > 0 ? ' · ' . $eur($r['summe']) : '' ?></div>
      <form method="post"><input type="hidden" name="aktion" value="bestellt"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="em-btn em-btn-order" type="submit">Als bestellt markieren</button>
      </form>
      <a class="em-sub" href="?p=bestellung&id=<?= (int)$r['id'] ?>">Details öffnen</a>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($bestellt): ?>
    <div class="em-sec" style="margin-top:18px">Unterwegs – auf Wareneingang wartend (<?= count($bestellt) ?>)</div>
    <?php foreach ($bestellt as $r): ?>
    <div class="em-card" id="b<?= (int)$r['id'] ?>">
      <div class="bx-row" style="justify-content:space-between;align-items:flex-start;gap:8px">
        <div>
          <div class="em-firma"><?= h($r['lieferant_firma'] ?: 'Ohne Lieferant') ?></div>
          <div class="em-meta"><?= h($r['nummer']) ?> · <?= (int)$r['pos_anzahl'] ?> Position<?= (int)$r['pos_anzahl'] === 1 ? '' : 'en' ?><?= (float)$r['summe'] > 0 ? ' · ' . $eur($r['summe']) : '' ?></div>
          <?php if (!empty($r['bestelldatum'])): ?><div class="em-meta">bestellt am <?= h(date('d.m.Y', strtotime((string)$r['bestelldatum']))) ?><?= !empty($r['eta_geplant']) ? ' · erwartet ' . h(date('d.m.Y', strtotime((string)$r['eta_geplant']))) : '' ?></div><?php endif; ?>
        </div>
        <span class="em-badge">bestellt</span>
      </div>
      <form method="post" onsubmit="return confirm('Ware für <?= h($r['nummer']) ?> als eingegangen buchen? Der Bestand wird erhöht.');">
        <input type="hidden" name="aktion" value="liefern"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="em-btn em-btn-in" type="submit">Ware eingegangen</button>
      </form>
      <a class="em-sub" href="?p=bestellung&id=<?= (int)$r['id'] ?>">Details / Charge &amp; MHD anpassen</a>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
