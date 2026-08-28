<?php
// Wareneingang – Charge buchen; Quarantäne freigeben
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$hinweis = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    if ($aktion === 'buchen') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $menge = (float) str_replace(',', '.', trim($_POST['menge'] ?? '0'));
        $lief = ($_POST['lieferant_id'] ?? '') !== '' ? (int)$_POST['lieferant_id'] : null;
        $aid = ($_POST['auftrag_id'] ?? '') !== '' ? (int)$_POST['auftrag_id'] : null;
        $cid = wareneingang_buchen($item_id, $menge, trim($_POST['charge_nr'] ?? ''), trim($_POST['mhd'] ?? '') ?: null, $lief, trim($_POST['notiz'] ?? ''), $aid);
        header('Location: ?p=wareneingang' . ($cid ? '&ok=1' : '&fehler=1')); exit;
    }
    if ($aktion === 'freigeben') {
        q("UPDATE charge SET status='frei' WHERE id=? AND status='quarantaene'", [(int)($_POST['charge_id'] ?? 0)]);
        header('Location: ?p=wareneingang&frei=1'); exit;
    }
}

$items = all("SELECT id, name, kategorie, einheit FROM item WHERE kategorie IN ('rohstoff','verpackung','fertig','verkaufsfertig') AND gesperrt=0 ORDER BY name");
$lieferanten = all("SELECT id, firma FROM lieferanten ORDER BY firma");
$offeneAuftraege = all("SELECT a.id, a.nummer, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt, k.firma
                        FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN kunden k ON k.id=a.kunde_id
                        WHERE a.status <> 'versendet' ORDER BY a.angelegt DESC");
$charges = all("SELECT c.*, i.name AS item_name, l.firma AS lieferant_firma
                FROM charge c LEFT JOIN item i ON i.id=c.item_id LEFT JOIN lieferanten l ON l.id=c.lieferant_id
                ORDER BY c.angelegt DESC LIMIT 25");

$statusBadge = fn($s) => match ($s) {
    'frei'        => bx_badge('frei','ok'),
    'quarantaene' => bx_badge('Quarantäne','warn'),
    'gesperrt'    => bx_badge('gesperrt','err'),
    'leer'        => bx_badge('leer'),
    default       => bx_badge($s),
};
$mng = fn($x,$e) => rtrim(rtrim(number_format((float)$x,3,',','.'),'0'),',') . ' ' . h($e ?: '');

render_header('wareneingang', 'Wareneingang');
bx_head('Wareneingang', 'Charge buchen', bx_btn('Zum Bestand', '?p=lager', 'ghost'));
if (isset($_GET['ok']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Wareneingang gebucht.</div>';
if (isset($_GET['frei']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Charge freigegeben.</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">Menge oder Artikel fehlt.</div>';
?>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="buchen">
  <div class="bx-panel"><div class="bx-grid">
    <div class="bx-field"><label>Artikel</label>
      <select name="item_id" required>
        <option value="">– wählen –</option>
        <?php foreach ($items as $it): ?><option value="<?= $it['id'] ?>"><?= h($it['name']) ?> (<?= h($it['einheit']) ?>)</option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Menge</label><input type="number" step="0.001" name="menge" required></div>
    <div class="bx-field"><label>Charge (Lieferant) <?= bx_hint('Chargennummer laut Lieferant/CoA') ?></label><input type="text" name="charge_nr"></div>
    <div class="bx-field"><label>MHD</label><input type="date" name="mhd"></div>
    <div class="bx-field"><label>Lieferant</label>
      <select name="lieferant_id">
        <option value="">– keiner –</option>
        <?php foreach ($lieferanten as $lf): ?><option value="<?= $lf['id'] ?>"><?= h($lf['firma']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Für Auftrag <?= bx_hint('Optional: gehört diese Lieferung zu einem Kundenauftrag? Dann wird die Charge dem Auftrag zugeordnet. Sonst „Lager / allgemein".') ?></label>
      <select name="auftrag_id">
        <option value="">Lager / allgemein</option>
        <?php foreach ($offeneAuftraege as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h($a['nummer'] . ($a['produkt'] ? ' · ' . $a['produkt'] : '') . ($a['firma'] ? ' · ' . $a['firma'] : '')) ?></option><?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="bx-field"><label>Notiz</label><input type="text" name="notiz"></div>
  <div class="muted" style="margin-bottom:8px">Rohstoffe gehen zunächst in <strong>Quarantäne</strong> und müssen unten freigegeben werden. Verpackungen sind sofort frei.</div>
  <button class="btn btn-primary" type="submit">Wareneingang buchen</button>
  </div>
</form>

<div class="bx-panel">
  <h2>Letzte Chargen</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Charge</th><th>Artikel</th><th class="bx-num">Menge</th><th>MHD</th><th>Lieferant</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$charges): ?><tr><td colspan="7" class="muted">Noch keine Chargen gebucht.</td></tr><?php endif; ?>
    <?php foreach ($charges as $c): ?>
      <tr>
        <td><?= h($c['charge_nr'] ?: '–') ?></td>
        <td><?= h($c['item_name'] ?: '–') ?></td>
        <td class="bx-num"><?= $mng($c['menge_verfuegbar'], $c['einheit']) ?></td>
        <td><?= $c['mhd'] ? h(date('d.m.Y', strtotime($c['mhd']))) : '<span class="muted">–</span>' ?></td>
        <td><?= $c['lieferant_firma'] ? h($c['lieferant_firma']) : '<span class="muted">–</span>' ?></td>
        <td><?= $statusBadge($c['status']) ?></td>
        <td style="text-align:right">
          <?php if ($c['status']==='quarantaene'): ?>
            <form method="post" style="display:inline"><input type="hidden" name="aktion" value="freigeben"><input type="hidden" name="charge_id" value="<?= (int)$c['id'] ?>"><button class="btn btn-primary btn-sm" type="submit">freigeben</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer(); ?>
