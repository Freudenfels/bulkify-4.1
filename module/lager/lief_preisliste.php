<?php
// EK-Preisliste (Referenz) – Rohstoff-Einkaufspreise aus v3, zum Nachschlagen „was zahlen wir wofür".
// Bewusst OHNE Lager-Verknüpfung (v4 hat einen eigenen Rohstoffstamm).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/anfrage_ui.php';   // Preisanfrage-Popup

$q = trim((string)($_GET['q'] ?? ''));
$where = ''; $args = [];
if ($q !== '') { $where = "WHERE rohstoff_name LIKE ? OR lieferant LIKE ?"; $args = ['%' . $q . '%', '%' . $q . '%']; }
$rows = all("SELECT rohstoff_name, lieferant, eur_kg, stand FROM lieferant_preisliste $where ORDER BY rohstoff_name LIMIT 1000", $args);
$gesamt = (int) scalar("SELECT COUNT(*) FROM lieferant_preisliste");

// Verknüpfung zum v4-Rohstoff über den normierten Namen: gefundene Einträge werden anklickbar
// (Info am Rohstoff) und direkt anfragbar; der Rest führt in die Rohstoff-Suche.
$norm = fn($s) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9äöüß ]+/u', ' ', mb_strtolower(trim((string)$s)))));
$itemMap = [];
foreach (all("SELECT id, name FROM item WHERE kategorie='rohstoff'") as $it) { $n = $norm($it['name']); if ($n !== '' && !isset($itemMap[$n])) $itemMap[$n] = (int)$it['id']; }
$lieferanten = all("SELECT id, firma, land FROM lieferanten WHERE gesperrt=0 ORDER BY firma");

render_header('lief_preisliste', 'EK-Preisliste');
bx_head('EK-Preisliste (Referenz)', $gesamt . ' Rohstoffpreise aus v3 – bekannte Rohstoffe sind verlinkt und direkt anfragbar');
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
    <thead><tr><th>Rohstoff</th><th>Lieferant</th><th class="bx-num">EUR / kg</th><th>Stand</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): $mid = $itemMap[$norm($r['rohstoff_name'])] ?? 0; ?>
        <tr>
          <td><?php if ($mid): ?><a class="kundenlink" href="?p=rohstoff&id=<?= $mid ?>&tab=ek"><?= h($r['rohstoff_name']) ?></a><?php else: ?><?= h($r['rohstoff_name']) ?><?php endif; ?></td>
          <td><?= $r['lieferant'] !== '' && $r['lieferant'] !== null ? h($r['lieferant']) : '<span class="muted">–</span>' ?></td>
          <td class="bx-num"><?= $r['eur_kg'] !== null ? number_format((float)$r['eur_kg'], 2, ',', '.') . ' &euro;' : '<span class="muted">–</span>' ?></td>
          <td class="muted"><?= $r['stand'] ? h(date('d.m.Y', strtotime((string)$r['stand']))) : '–' ?></td>
          <td class="bx-num" style="white-space:nowrap">
            <?php if ($mid): ?>
              <button type="button" class="btn btn-ghost btn-sm" data-name="<?= h($r['rohstoff_name']) ?>" onclick="bxAnfrageOeffnen(<?= $mid ?>, this)">anfragen</button>
            <?php else: ?>
              <a class="btn btn-ghost btn-sm" href="?p=rohstoffe&q=<?= urlencode((string)$r['rohstoff_name']) ?>">im Lager suchen</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($rows) >= 1000): ?><p class="muted" style="font-size:12px;margin-top:8px">Nur die ersten 1.000 Treffer – bitte die Suche eingrenzen.</p><?php endif; ?>
  <?php endif; ?>
  <p class="muted" style="font-size:12px;margin-top:8px">Aus v3 übernommen (Referenz). Verlinkte Rohstoffe öffnen den Rohstoff mit allen Infos und Lieferantenpreisen; „anfragen" holt ein Angebot bei weiteren Lieferanten ein. Nicht verlinkte Einträge (v3-Freitextname) über „im Lager suchen" finden.</p>
</div>
<?php anfrage_modal($lieferanten, '?p=lief_preisliste' . ($q !== '' ? '&q=' . urlencode($q) : '')); ?>
<?php render_footer(); ?>
