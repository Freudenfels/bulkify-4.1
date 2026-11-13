<?php
// Einkauf – Schnellansicht (mobil). Grosse Karten, ein Tap je Statuswechsel:
//   offen (Entwurf) -> "Als bestellt markieren"  -> bestellt
//   bestellt         -> "Ware eingegangen"        -> geliefert (bucht Wareneingang)
// Nutzt dieselben Funktionen wie die Detailseite (einkauf/detail.php).
// Bestellseite aufgeräumt (13.11.2026): jede Karte = Kopf der Bestellseite (Status, Badges, Betrag, EIN Hauptknopf volle Breite)
// + Position (bestellt / geliefert / offen, Balken) – Bausteine aus core/bestellung_seite.php.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $st     = $id ? (string) scalar("SELECT status FROM bestellung WHERE id=?", [$id]) : '';
    if ($id && $aktion === 'bestellt' && $st === 'offen') {
        $rB = bestellung_als_bestellt_markieren($id);   // zentral: Aufgabe „Unterlagen nachfordern“ (keine Sperre mehr) + Mail (13.11.2026)
        if (!$rB['ok']) { header('Location: ?p=bestellung&id=' . $id . '&fehler=' . urlencode($rB['msg']) . '#unterlagen'); exit; }
        header('Location: ?p=einkauf_mobil&ok=bestellt#b' . $id); exit;
    }
    if ($id && $aktion === 'liefern' && $st === 'bestellt') {
        // Einkauf gerade ziehen (Teil 1): EIN Wareneingang mit vorgewählter Position (Charge, MHD, Menge echt erfasst).
        $bpErst = (int) scalar("SELECT id FROM bestellung_position WHERE bestellung_id=? AND menge>0 AND COALESCE(geliefert_menge,0) < menge AND rest_storniert_am IS NULL ORDER BY sort, id LIMIT 1", [$id]);   // Fable-4 T3.7
        header('Location: ?p=wareneingang' . ($bpErst ? '&bp=' . $bpErst : '')); exit;
    }
    header('Location: ?p=einkauf_mobil'); exit;
}

$q  = trim((string)($_GET['q'] ?? ''));
$wo = "b.status IN ('offen','bestellt','gesendet') AND b.angekommen_am IS NULL";
$args = [];
if ($q !== '') { $wo .= " AND (b.nummer LIKE ? OR l.firma LIKE ?)"; $args[] = '%' . $q . '%'; $args[] = '%' . $q . '%'; }
$rows = all("SELECT b.*, l.firma AS lieferant_firma,
             (SELECT COUNT(*) FROM bestellung_position p WHERE p.bestellung_id=b.id) AS pos_anzahl,
             (SELECT COALESCE(SUM(menge*ek_preis),0) FROM bestellung_position p WHERE p.bestellung_id=b.id) AS summe
             FROM bestellung b LEFT JOIN lieferanten l ON l.id=b.lieferant_id
             WHERE $wo ORDER BY (b.status='bestellt'), b.angelegt DESC", $args);
$offen    = array_values(array_filter($rows, fn($r) => $r['status'] === 'offen'));
$bestellt = array_values(array_filter($rows, fn($r) => $r['status'] !== 'offen'));
require_once BX_ROOT . '/core/bestellung_seite.php';
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
  <?php if (isset($_GET['ok'])): $t = $_GET['ok'] === 'eingang' ? 'Wareneingang gebucht – Bestand aktualisiert.' : ($_GET['ok'] === 'lager' ? 'Nicht gebucht: Die Bestellung enthält Bulkware ohne Lagerartikel – bitte in der Lager-App unter Wareneingang einbuchen.' : 'Als bestellt markiert.'); ?>
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
    <?php foreach ($offen as $r): $bd = bs_daten($r); ?>
    <div id="b<?= (int)$r['id'] ?>">
      <?= bs_kopf_html($r, $bd, '<form method="post" style="margin:0"><input type="hidden" name="aktion" value="bestellt"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn btn-primary bs-haupt" type="submit">Bestellen</button></form>') ?>
      <?= bs_position_html($r) ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($bestellt): ?>
    <div class="em-sec" style="margin-top:18px">Unterwegs – auf Wareneingang wartend (<?= count($bestellt) ?>)</div>
    <?php foreach ($bestellt as $r): $bd = bs_daten($r); ?>
    <div id="b<?= (int)$r['id'] ?>">
      <?= bs_kopf_html($r, $bd, bs_haupt_link($r, $bd)) ?>
      <?= bs_position_html($r) ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
