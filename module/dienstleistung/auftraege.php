<?php
// DL-Aufträge (Liste) – aus bestätigten DL-Angeboten entstanden.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dienstleistung.php';

$rows = dl_auftraege_alle();
$statusBadge = fn($s) => match ($s) {
    'offen'=>bx_badge('offen','info'), 'in_arbeit'=>bx_badge('in Arbeit','warn'),
    'erledigt'=>bx_badge('erledigt','ok'), default=>bx_badge(status_text($s)) };

render_header('dienstleistungen', 'DL-Aufträge');
bx_head('Dienstleistungs-Aufträge', count($rows) . ' Aufträge', bx_hint('Eigener Nummernkreis DB-… – aus bestätigten DL-Angeboten.'));
dl_subtabs('dl_auftraege');
?>
<div class="bx-panel">
  <?php if (!$rows): ?><div class="muted">Noch keine Dienstleistungs-Aufträge. Entstehen aus einem bestätigten DL-Angebot.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Nummer</th><th>Kunde</th><th>aus Angebot</th><th class="bx-num">Netto</th><th>Status</th><th>Rechnung</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr style="cursor:pointer" onclick="location.href='?p=dl_auftrag&id=<?= (int)$r['id'] ?>'">
        <td><strong><?= h($r['nummer'] ?: '–') ?></strong></td>
        <td><?= kunde_link($r['kunde_id'] ?? null, $r['kunde_firma']) ?></td>
        <td class="muted"><?= h($r['angebot_nummer'] ?: '–') ?></td>
        <td class="bx-num"><?= number_format((float)$r['gesamt_netto'],2,',','.') ?> €</td>
        <td><?= $statusBadge($r['status']) ?></td>
        <td onclick="event.stopPropagation()"><?= $r['rechnung_id'] ? '<a href="/buchhaltung/?p=rechnung&id=' . (int)$r['rechnung_id'] . '">' . h($r['rechnung_nummer']) . '</a>' : '<span class="muted">–</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php render_footer();
