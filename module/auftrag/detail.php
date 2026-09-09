<?php
// Auftrag (Auftragsbestätigung) – Ansicht + Status
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    q("UPDATE auftrag SET status=? WHERE id=?", [trim($_POST['status'] ?? 'offen'), $id]);
    header('Location: ?p=auftrag&id=' . $id . '&gespeichert=1'); exit;
}

$a = $id ? one("SELECT a.*, k.firma AS kunde_firma, p.name AS produkt_name, ang.nummer AS angebot_nr
                FROM auftrag a
                LEFT JOIN kunden k ON k.id=a.kunde_id
                LEFT JOIN produkt p ON p.id=a.produkt_id
                LEFT JOIN angebot ang ON ang.id=a.angebot_id
                WHERE a.id=?", [$id]) : null;
if (!$a) { render_header('auftraege','Auftrag'); bx_head('Auftrag nicht gefunden','', bx_btn('Zurück','?p=auftraege','ghost')); render_footer(); exit; }

$rechnung = one("SELECT id, nummer, brutto, status FROM beleg WHERE auftrag_id=? AND typ='rechnung' LIMIT 1", [$id]);
$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$statusBadge = match ($a['status']) {
    'offen'         => bx_badge('offen','info'),
    'in_produktion' => bx_badge('in Produktion','warn'),
    'erledigt'      => bx_badge('versandbereit','info'),
    'versendet'     => bx_badge('versendet','ok'),
    default         => bx_badge(status_text($a['status'])),
};

// Produktion + Beschaffung zu diesem Auftrag
$pa = one("SELECT * FROM produktionsauftrag WHERE auftrag_id=? ORDER BY id DESC LIMIT 1", [$id]);
$istFremd = $pa && ($pa['produktionsart'] ?? '') === 'fremd';
$paStatusBadge = $pa ? match ($pa['status']) {
    'offen'=>bx_badge('offen','info'),'laufend'=>bx_badge('läuft','warn'),'erledigt'=>bx_badge('fertig','ok'),
    default=>bx_badge(status_text((string)$pa['status'])),
} : '';
$ber = $pa ? produktion_bereitschaft((int)$pa['id']) : ['status'=>''];
$einhProP  = (int) scalar("SELECT einheiten_pro_packung FROM produkt WHERE id=?", [(int)$a['produkt_id']]);
$gesamtStk = $einhProP > 0 ? (int)$a['menge'] * $einhProP : 0;
$groesseLbl = produktion_groesse_label((int)$a['produkt_id']);
// Bestellungen (bei welchem Lieferanten, welcher Status) – verknüpft über die Position.
$best = all("SELECT DISTINCT b.id, b.nummer, b.status, b.bestaetigt, b.angekommen_am, l.firma AS lieferant
             FROM bestellung b JOIN bestellung_position bp ON bp.bestellung_id=b.id
             LEFT JOIN lieferanten l ON l.id=b.lieferant_id
             WHERE bp.auftrag_id=? ORDER BY b.angelegt DESC", [$id]);
$bStatus = function($b) {
    if (!empty($b['angekommen_am']))        return bx_badge('angekommen','ok');
    if ((int)($b['bestaetigt'] ?? 0) === 1) return bx_badge('bestätigt','warn');
    return match ((string)$b['status']) {
        'offen'=>bx_badge('offen','info'),'bestellt'=>bx_badge('bestellt','warn'),'geliefert'=>bx_badge('geliefert','ok'),
        default=>bx_badge((string)$b['status']),
    };
};

render_header('auftraege', $a['nummer']);
bx_head($a['nummer'], 'Auftragsbestätigung', bx_btn('Zurück zur Liste', '?p=auftraege', 'ghost'));
if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';

echo '<div class="bx-cards">';
echo '<div class="bx-card"><div class="k">Status</div><div class="v">' . $statusBadge . '</div></div>';
echo '<div class="bx-card"><div class="k">Menge (Packungen)</div><div class="v">' . (int)$a['menge'] . '</div></div>';
if ($gesamtStk > 0) echo '<div class="bx-card"><div class="k">Gesamtstückzahl</div><div class="v">' . number_format($gesamtStk, 0, ',', '.') . '</div></div>';
if ($groesseLbl !== '') echo '<div class="bx-card"><div class="k">Kapsel/Tablette</div><div class="v">' . h($groesseLbl) . '</div></div>';
echo '<div class="bx-card"><div class="k">Herstellung</div><div class="v">' . ($istFremd ? bx_badge('Zukauf','info') : bx_badge('Eigenproduktion','ok')) . '</div></div>';
echo '<div class="bx-card"><div class="k">VK / Stück</div><div class="v">' . $eur($a['vk_stueck']) . '</div></div>';
echo '<div class="bx-card"><div class="k">Netto gesamt</div><div class="v">' . $eur($a['gesamt_netto']) . '</div></div>';
echo '</div>';
?>
<div class="bx-panel">
  <h2>Details</h2>
  <div class="bx-grid">
    <div><div class="k muted">Kunde</div><div><?= kunde_link($a['kunde_id'] ?? null, $a['kunde_firma']) ?></div></div>
    <div><div class="k muted">Produkt</div><div><?= $a['produkt_name'] ? h($a['produkt_name']) : '–' ?></div></div>
    <div><div class="k muted">Aus Angebot</div><div><?php if ($a['angebot_id']): ?><a href="?p=angebot&id=<?= (int)$a['angebot_id'] ?>"><?= h($a['angebot_nr']) ?></a><?php else: ?>–<?php endif; ?></div></div>
    <div><div class="k muted">Rechnung</div><div><?php if ($rechnung): ?><a href="?p=rechnung&id=<?= (int)$rechnung['id'] ?>"><?= h($rechnung['nummer']) ?></a> · <?= $eur($rechnung['brutto']) ?> · <?= $rechnung['status']==='bezahlt'?bx_badge('bezahlt','ok'):bx_badge('offen','warn') ?><?php else: ?>–<?php endif; ?></div></div>
  </div>
</div>

<div class="bx-panel">
  <h2>Produktion &amp; Beschaffung</h2>
  <div class="bx-grid">
    <div><div class="k muted">Herstellung</div><div>
      <?= $istFremd ? bx_badge('Fremdproduktion · fertige Bulkware zukaufen','info') : bx_badge('Eigenproduktion · aus Rohstoffen','ok') ?>
    </div></div>
    <?php if ($pa): ?>
    <div><div class="k muted">Produktionsauftrag</div><div><a href="?p=produktionsauftrag&id=<?= (int)$pa['id'] ?>"><?= h($pa['nummer']) ?></a> · <?= $paStatusBadge ?></div></div>
    <div><div class="k muted">Material</div><div>
      <?= bereitschaft_badge($ber['status'] ?? '') ?>
      <?php if (($ber['status'] ?? '') === 'wartet'): ?> <a href="?p=produktionsauftrag&id=<?= (int)$pa['id'] ?>" style="font-size:12px">was fehlt?</a><?php endif; ?>
    </div></div>
    <?php else: ?>
    <div><div class="k muted">Produktionsauftrag</div><div class="muted">noch keiner angelegt</div></div>
    <?php endif; ?>
  </div>

  <h3 style="margin:18px 0 6px;font-size:14px;font-weight:600">Bestellungen zu diesem Auftrag</h3>
  <?php if (!$best): ?>
    <div class="muted"><?= $istFremd ? 'Noch keine Bestellung erfasst – fertige Bulkware ist noch nicht bestellt.' : 'Noch keine Bestellung erfasst – Rohstoffe sind noch offen.' ?>
      <?php if ($pa): ?> <a href="?p=bedarf" style="font-size:12px">zum Einkaufsbedarf</a><?php endif; ?></div>
  <?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Bestellung</th><th>Lieferant</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($best as $b): ?>
        <tr><td><a href="?p=bestellung&id=<?= (int)$b['id'] ?>"><?= h($b['nummer']) ?></a></td>
            <td><?= $b['lieferant'] ? h($b['lieferant']) : '<span class="muted">–</span>' ?></td>
            <td><?= $bStatus($b) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<form method="post" class="bx-form">
  <div class="bx-panel"><div class="bx-grid">
    <div class="bx-field"><label>Status</label>
      <select name="status">
        <?php foreach (['offen'=>'offen','in_produktion'=>'in Produktion','erledigt'=>'versandbereit','versendet'=>'versendet'] as $key=>$lbl): ?>
          <option value="<?= $key ?>" <?= $a['status']===$key?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
      </select>
    </div>
  </div></div>
  <button class="btn btn-primary" type="submit">Status speichern</button>
</form>
<?php render_footer(); ?>
