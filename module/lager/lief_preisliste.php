<?php
// EK-Preisliste (Referenz) – Rohstoff-Einkaufspreise aus v3, zum Nachschlagen „was zahlen wir wofür".
// Bewusst OHNE Lager-Verknüpfung (v4 hat einen eigenen Rohstoffstamm).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$q = trim((string)($_GET['q'] ?? ''));
$where = ''; $args = [];
if ($q !== '') { $where = "WHERE rohstoff_name LIKE ? OR lieferant LIKE ?"; $args = ['%' . $q . '%', '%' . $q . '%']; }
$rows = all("SELECT rohstoff_name, lieferant, eur_kg, stand FROM lieferant_preisliste $where ORDER BY rohstoff_name LIMIT 1000", $args);
$gesamt = (int) scalar("SELECT COUNT(*) FROM lieferant_preisliste");

render_header('lief_preisliste', 'EK-Preisliste');
bx_head('EK-Preisliste (Referenz)', $gesamt . ' Rohstoffpreise aus v3 – Nachschlage-Liste, ohne Lager-Verknüpfung');
?>
<form method="get" class="bx-row" style="gap:8px;margin-bottom:14px;align-items:center">
  <input type="hidden" name="p" value="lief_preisliste">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Rohstoff oder Lieferant suchen…" style="max-width:360px">
  <button class="btn btn-primary" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost" href="?p=lief_preisliste">Zurücksetzen</a><?php endif; ?>
</form>
<div class="bx-panel">
  <?php if (!$rows): ?>
    <div class="muted"><?= $q !== '' ? 'Keine Treffer.' : 'Keine Preisliste vorhanden.' ?></div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Rohstoff</th><th>Lieferant</th><th class="bx-num">EUR / kg</th><th>Stand</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= h($r['rohstoff_name']) ?></td>
          <td><?= $r['lieferant'] !== '' && $r['lieferant'] !== null ? h($r['lieferant']) : '<span class="muted">–</span>' ?></td>
          <td class="bx-num"><?= $r['eur_kg'] !== null ? number_format((float)$r['eur_kg'], 2, ',', '.') . ' &euro;' : '<span class="muted">–</span>' ?></td>
          <td class="muted"><?= $r['stand'] ? h(date('d.m.Y', strtotime((string)$r['stand']))) : '–' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($rows) >= 1000): ?><p class="muted" style="font-size:12px;margin-top:8px">Nur die ersten 1.000 Treffer – bitte die Suche eingrenzen.</p><?php endif; ?>
  <?php endif; ?>
  <p class="muted" style="font-size:12px;margin-top:8px">Aus v3 übernommen. Reine Referenz – nicht mit dem v4-Lagerartikel verknüpft.</p>
</div>
<?php render_footer(); ?>
