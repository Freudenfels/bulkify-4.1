<?php
// Bestand: alle eigenen Chargen, nach Kategorie getrennt, anklickbar. Startseite des Lagers.
$kat = (string)($_GET['kat'] ?? '');
if ($kat !== '' && !isset(erp_kategorien()[$kat])) $kat = '';
$q = trim((string)($_GET['q'] ?? ''));
$mit_leer = ($_GET['leer'] ?? '') === '1';

$zeilen = erp_bestand($kat, $q, $mit_leer);
$zaehl = erp_bestand_zaehlung();

kopf('Bestand', 'bestand');
seitenkopf('Bestand', count($zeilen) . ' Chargen' . ($kat ? ' · ' . erp_kategorien()[$kat] : ''));
flash_zeigen();

if (!tabelle_da('charge')) { hinweis('Es sind noch keine Chargen im Dashboard vorhanden.', 'warn'); fuss(); return; }
?>
<div class="lg-reiter">
  <a href="?p=bestand<?= $q ? '&q=' . urlencode($q) : '' ?>" class="<?= $kat === '' ? 'an' : '' ?>">Alle</a>
  <?php foreach (erp_kategorien() as $k => $label): ?>
    <a href="?p=bestand&kat=<?= h($k) ?><?= $q ? '&q=' . urlencode($q) : '' ?>" class="<?= $kat === $k ? 'an' : '' ?>">
      <?= h($label) ?> <span class="lg-reiter-z"><?= (int)$zaehl[$k] ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="bx-listbar">
  <input type="search" class="bx-search" placeholder="Suchen: Rohstoff, Artikelnummer, Charge" data-filter="lg-bestand" value="<?= h($q) ?>" autofocus>
  <label class="bx-check" style="margin:0"><input type="checkbox" onchange="location.href='?p=bestand<?= $kat ? '&kat=' . h($kat) : '' ?><?= $q ? '&q=' . urlencode($q) : '' ?>' + (this.checked ? '&leer=1' : '')" <?= $mit_leer ? 'checked' : '' ?>> auch leere zeigen</label>
</div>

<?php if (!$zeilen): ?>
  <div class="bx-panel muted">Nichts im Bestand<?= $q ? ' für „' . h($q) . '"' : '' ?>.</div>
<?php else: ?>
<div class="bx-tablewrap" style="margin-bottom:var(--sp-6)">
  <table class="bx-table" id="lg-bestand">
    <thead><tr>
      <th>Rohstoff / Produkt</th><?= $kat === '' ? '<th>Kategorie</th>' : '' ?>
      <th>Charge</th><th>MHD</th><th>Bestand</th><th>Status</th><th>Blinker</th>
    </tr></thead>
    <tbody>
    <?php foreach ($zeilen as $z): ?>
      <tr onclick="location.href='?p=charge&id=<?= (int)$z['id'] ?>'" style="cursor:pointer">
        <td><a href="?p=charge&id=<?= (int)$z['id'] ?>" class="lg-namelink" onclick="event.stopPropagation()"><?= h((string)$z['item_name']) ?></a><?= $z['artikelnummer'] ? ' <span class="muted">' . h((string)$z['artikelnummer']) . '</span>' : '' ?></td>
        <?= $kat === '' ? '<td class="muted">' . h(erp_kategorie_label($z)) . '</td>' : '' ?>
        <td class="lg-code"><?= h((string)$z['charge_nr']) ?></td>
        <td><?= mhd_html($z['mhd']) ?></td>
        <td><?= h(menge_txt($z['menge_verfuegbar'])) ?> <?= h((string)$z['einheit']) ?></td>
        <td><?= status_badge($z['status']) ?></td>
        <td class="lg-code"><?php $bl = leiste_fuer_charge((int)$z['id']); ?><?= $bl ? h((string)$bl['code']) : '<span class="muted">–</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
fuss();
