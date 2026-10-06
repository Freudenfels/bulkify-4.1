<?php
// Lager 2 – Artikel-Stammdaten (Katalog je Fulfillment-Kunde). Liste mit Kunden-Reitern + Suche.
$kunde_id = (int)($_GET['kunde'] ?? 0);
$q        = trim((string)($_GET['q'] ?? ''));
$kunden   = function_exists('erp_fulfillment_kunden') ? erp_fulfillment_kunden() : [];
$zeilen   = lg_artikel_liste($kunde_id, $q);
$typen    = lg_artikel_typen();

kopf('Lager 2 – Artikel', 'l2_artikel');
seitenkopf('Lager 2 – Artikel-Stammdaten', count($zeilen) . ' Artikel',
    '<a class="btn btn-primary" href="?p=l2_artikel_edit' . ($kunde_id ? '&kunde=' . $kunde_id : '') . '">+ Neuer Artikel</a>');
flash_zeigen();
?>
<?php if ($kunden): ?>
<div class="lg-reiter">
  <a href="?p=l2_artikel<?= $q ? '&q=' . urlencode($q) : '' ?>" class="<?= $kunde_id === 0 ? 'an' : '' ?>">Alle Kunden</a>
  <?php foreach ($kunden as $k): ?>
    <a href="?p=l2_artikel&kunde=<?= (int)$k['id'] ?><?= $q ? '&q=' . urlencode($q) : '' ?>" class="<?= $kunde_id === (int)$k['id'] ? 'an' : '' ?>"><?= h((string)$k['firma']) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="bx-listbar">
  <form method="get" class="bx-row" style="gap:6px;margin:0;flex:1" role="search">
    <input type="hidden" name="p" value="l2_artikel"><?php if ($kunde_id): ?><input type="hidden" name="kunde" value="<?= $kunde_id ?>"><?php endif; ?>
    <input type="search" class="bx-search" name="q" value="<?= h($q) ?>" placeholder="Suchen: Name, EAN, Kunden-SKU" autofocus>
    <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=l2_artikel<?= $kunde_id ? '&kunde=' . $kunde_id : '' ?>">×</a><?php endif; ?>
  </form>
</div>

<?php if (!$zeilen): ?>
  <div class="bx-panel muted">Noch keine Artikel<?= $q ? ' für „' . h($q) . '"' : '' ?>. Oben „+ Neuer Artikel" anlegen.</div>
<?php else: ?>
<div class="bx-tablewrap">
  <table class="bx-table lg-karten">
    <thead><tr><th>Bild</th><th>Artikel</th><th>Typ</th><th>Verkaufsartikel</th><th>Gewicht</th><th>Maße (L×B×H)</th><th>EAN</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($zeilen as $a):
      $masse = array_filter([$a['masse_l_mm'], $a['masse_b_mm'], $a['masse_h_mm']], fn($x) => $x !== null);
      $masseTxt = count($masse) ? implode(' × ', array_map(fn($x) => rtrim(rtrim(number_format((float)$x, 1, ',', ''), '0'), ','), [$a['masse_l_mm'] ?? 0, $a['masse_b_mm'] ?? 0, $a['masse_h_mm'] ?? 0])) . ' mm' : '–'; ?>
      <tr onclick="location.href='?p=l2_artikel_edit&id=<?= (int)$a['id'] ?>'" style="cursor:pointer">
        <td data-label="Bild"><?php if (!empty($a['etikett_bild'])): ?><img src="?p=bild&f=<?= h(rawurlencode((string)$a['etikett_bild'])) ?>" alt="" style="height:40px;border-radius:4px;border:1px solid var(--line)"><?php else: ?><span class="muted">–</span><?php endif; ?></td>
        <td data-label=""><a href="?p=l2_artikel_edit&id=<?= (int)$a['id'] ?>" class="lg-namelink" onclick="event.stopPropagation()"><?= h((string)$a['name']) ?></a><?= $a['kunden_sku'] ? ' <span class="muted">' . h((string)$a['kunden_sku']) . '</span>' : '' ?></td>
        <td data-label="Typ"><?= h($typen[$a['typ']] ?? (string)$a['typ']) ?></td>
        <td data-label="Verkaufsartikel"><?= (int)$a['verkaufsartikel'] === 1 ? 'Ja' : '<span class="muted">nein</span>' ?></td>
        <td data-label="Gewicht"><?= $a['gewicht_g'] !== null ? h(rtrim(rtrim(number_format((float)$a['gewicht_g'], 2, ',', '.'), '0'), ',')) . ' g' : '–' ?></td>
        <td data-label="Maße (L×B×H)" class="muted"><?= h($masseTxt) ?></td>
        <td data-label="EAN" class="lg-code"><?= h((string)($a['ean'] ?? '')) ?: '<span class="muted">–</span>' ?></td>
        <td data-label="" style="text-align:right"><a class="btn btn-ghost btn-sm" href="?p=l2_artikel_edit&id=<?= (int)$a['id'] ?>" onclick="event.stopPropagation()">Bearbeiten</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php fuss();
