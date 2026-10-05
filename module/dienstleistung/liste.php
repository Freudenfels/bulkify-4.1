<?php
// Dienstleistungen – Katalog (Liste). Stammdaten verkaufbarer Services. Phase 1.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dienstleistung.php';

// Start-Dienstleistungen anlegen (nur auf Knopfdruck, kein Auto-Seed beim Seitenaufruf).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'startseed') {
    $n = dienstleistung_startseed();
    header('Location: ?p=dienstleistungen&ok=' . urlencode($n > 0 ? ($n . ' Start-Dienstleistungen angelegt.') : 'Keine neuen – alle Start-Dienstleistungen gibt es schon.')); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    q("UPDATE dienstleistung SET aktiv = 1 - aktiv WHERE id=?", [$id]);
    header('Location: ?p=dienstleistungen&ok=' . urlencode('Status geändert.')); exit;
}

$rows = dienstleistungen_alle();
$KAT  = dienstleistung_kategorien();
$PM   = dienstleistung_preismodelle();

render_header('dienstleistungen', 'Dienstleistungen');
bx_head('Dienstleistungen', count($rows) . ' im Katalog',
        bx_btn('+ Neue Dienstleistung', '?p=dienstleistung&id=neu', 'primary'));
dl_subtabs('dienstleistungen');
echo bx_hint('Service-Katalog: alles Verkaufbare, das nicht „Produkt herstellen+ausliefern" ist – eigenständig oder als Zusatz zum Produkt. Der Katalog ist die einzige Preisquelle.');

if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h((string)$_GET['ok']) . '</div>';
?>
<div class="bx-panel">
  <?php if (!$rows): ?>
    <div class="muted" style="margin-bottom:12px">Noch keine Dienstleistungen im Katalog.</div>
    <form method="post" style="margin:0">
      <input type="hidden" name="aktion" value="startseed">
      <button class="btn btn-ghost" type="submit">Start-Dienstleistungen anlegen</button>
      <span class="muted" style="font-size:12px;margin-left:8px">legt Laboranalyse, Abfüllung, Beratung, Rezepturbewertung an (zum Bearbeiten)</span>
    </form>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Nummer</th><th>Name</th><th>Kategorie</th><th>Preismodell</th><th class="bx-num">VK (netto)</th><th>Art</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $aktiv = (int)$r['aktiv'] === 1; ?>
      <tr style="cursor:pointer" onclick="location.href='?p=dienstleistung&id=<?= (int)$r['id'] ?>'">
        <td class="muted"><?= h($r['nummer'] ?: '–') ?></td>
        <td><strong><?= h($r['name']) ?></strong><?php if (!empty($r['baustein'])): ?><div class="muted" style="font-size:12px">Baustein: <?= h(dienstleistung_label(dienstleistung_bausteine(), $r['baustein'])) ?></div><?php endif; ?></td>
        <td><?= h(dienstleistung_label($KAT, $r['kategorie'])) ?></td>
        <td><?= h(dienstleistung_label($PM, $r['preismodell'])) ?><?php if ((string)$r['wiederkehrend'] === 'monatlich'): ?> <?= bx_badge('wiederkehrend','info') ?><?php endif; ?></td>
        <td class="bx-num"><?= h(dienstleistung_preis_text($r)) ?></td>
        <td><?= h(dienstleistung_label(dienstleistung_arten(), $r['art'])) ?></td>
        <td><?= $aktiv ? bx_badge('aktiv','ok') : bx_badge('inaktiv') ?></td>
        <td class="bx-num" style="white-space:nowrap" onclick="event.stopPropagation()">
          <form method="post" style="margin:0;display:inline">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit" name="aktion" value="toggle"><?= $aktiv ? 'deaktivieren' : 'aktivieren' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php render_footer();
