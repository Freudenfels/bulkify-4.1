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

render_header('auftraege', $a['nummer']);
bx_head($a['nummer'], 'Auftragsbestätigung', bx_btn('Zurück zur Liste', '?p=auftraege', 'ghost'));
if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';

echo '<div class="bx-cards">';
echo '<div class="bx-card"><div class="k">Status</div><div class="v">' . $statusBadge . '</div></div>';
echo '<div class="bx-card"><div class="k">Menge</div><div class="v">' . (int)$a['menge'] . '</div></div>';
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
