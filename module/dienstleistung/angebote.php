<?php
// DL-Angebote (Liste) – eigener Dienstleistungs-Strang, getrennt von den Produkt-Angeboten.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dienstleistung.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'neu') {
    $kid = (int)($_POST['kunde_id'] ?? 0) ?: null;
    $aid = dl_angebot_neu($kid);
    header('Location: ?p=dl_angebot&id=' . $aid); exit;
}

$rows   = dl_angebote_alle();
$kunden = all("SELECT id, firma FROM kunden WHERE COALESCE(gesperrt,0)=0 ORDER BY firma");
$statusBadge = fn($s) => match ($s) {
    'offen'      => bx_badge('offen','info'),
    'gesendet'   => bx_badge('gesendet'),
    'bestaetigt' => bx_badge('bestätigt','ok'),
    'abgelehnt'  => bx_badge('abgelehnt','err'),
    default      => bx_badge(status_text($s)),
};

render_header('dienstleistungen', 'DL-Angebote');
bx_head('Dienstleistungs-Angebote', count($rows) . ' Angebote', bx_hint('Eigener Nummernkreis DA-… – getrennt von den Produkt-Angeboten (AN-…).'));
dl_subtabs('dl_angebote');
?>
<div class="bx-panel">
  <h2 style="margin-top:0">Neues DL-Angebot</h2>
  <form method="post" class="bx-row" style="align-items:flex-end;gap:12px">
    <input type="hidden" name="aktion" value="neu">
    <div class="bx-field" style="min-width:280px"><label>Kunde</label>
      <select name="kunde_id"><option value="">– ohne Kunde (später wählen) –</option>
        <?php foreach ($kunden as $k): ?><option value="<?= (int)$k['id'] ?>"><?= h($k['firma']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-primary" type="submit">Angebot anlegen</button>
  </form>
</div>

<div class="bx-panel">
  <?php if (!$rows): ?><div class="muted">Noch keine Dienstleistungs-Angebote.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Nummer</th><th>Kunde</th><th class="bx-num">Positionen</th><th class="bx-num">Netto</th><th>Status</th><th>Auftrag</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $sum = dl_angebot_summe((int)$r['id']); ?>
      <tr style="cursor:pointer" onclick="location.href='?p=dl_angebot&id=<?= (int)$r['id'] ?>'">
        <td><strong><?= h($r['nummer'] ?: '–') ?></strong></td>
        <td><?= kunde_link($r['kunde_id'] ?? null, $r['kunde_firma']) ?></td>
        <td class="bx-num"><?= (int)$r['pos_anzahl'] ?></td>
        <td class="bx-num"><?= number_format((float)$sum['netto'], 2, ',', '.') ?> €</td>
        <td><?= $statusBadge($r['status']) ?></td>
        <td onclick="event.stopPropagation()"><?= $r['auftrag_id'] ? '<a href="?p=dl_auftrag&id=' . (int)$r['auftrag_id'] . '">Auftrag</a>' : '<span class="muted">–</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php render_footer();
