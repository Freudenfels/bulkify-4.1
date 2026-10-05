<?php
// DL-Rechnungen (Liste) – eigener Nummernkreis DR-. Verwaltung (bezahlt/mahnen) in der Buchhaltung.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dienstleistung.php';

$rows = dl_rechnungen_alle();
$statusBadge = fn($s) => match ($s) {
    'offen'=>bx_badge('offen','info'), 'bezahlt'=>bx_badge('bezahlt','ok'),
    'storniert'=>bx_badge('storniert','err'), default=>bx_badge(status_text($s)) };

render_header('dienstleistungen', 'DL-Rechnungen');
bx_head('Dienstleistungs-Rechnungen', count($rows) . ' Rechnungen', bx_hint('Eigener Nummernkreis DR-… – getrennt von den Produkt-Rechnungen (RE-…). Bezahlung/Mahnung in der Buchhaltung.'));
dl_subtabs('dl_rechnungen');
?>
<div class="bx-panel">
  <?php if (!$rows): ?><div class="muted">Noch keine Dienstleistungs-Rechnungen. Entstehen aus einem DL-Auftrag.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Nummer</th><th>Datum</th><th>Kunde</th><th>aus Auftrag</th><th class="bx-num">Netto</th><th class="bx-num">Brutto</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="/buchhaltung/?p=rechnung&id=<?= (int)$r['id'] ?>"><strong><?= h($r['nummer'] ?: '–') ?></strong></a></td>
        <td class="muted"><?= $r['datum'] ? h(date('d.m.Y', strtotime((string)$r['datum']))) : '–' ?></td>
        <td><?= kunde_link($r['kunde_id'] ?? null, $r['kunde_firma']) ?></td>
        <td class="muted"><?= h($r['auftrag_nummer'] ?: '–') ?></td>
        <td class="bx-num"><?= number_format((float)$r['netto'],2,',','.') ?> €</td>
        <td class="bx-num"><?= number_format((float)$r['brutto'],2,',','.') ?> €</td>
        <td><?= $statusBadge($r['status']) ?></td>
        <td class="bx-num"><?= pdf_btn('/buchhaltung/?p=rechnung_pdf&id=' . (int)$r['id'], 'PDF', false, 'Rechnung als PDF') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php render_footer();
