<?php
// Lager 2 (Fremdlager) – Bestand: Chargen, die einem Kunden gehoeren (charge.fremd_kunde_id).
$kunde_id = (int)($_GET['kunde'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$mit_leer = ($_GET['leer'] ?? '') === '1';

$zeilen = erp_bestand_fremd($kunde_id, $q, $mit_leer);
$kunden = erp_bestand_fremd_kunden();

kopf('Lager 2 – Bestand', 'l2_bestand');
seitenkopf('Lager 2 (Fremdlager) – Bestand', count($zeilen) . ' Chargen · Kundenware',
    '<a class="btn btn-ghost" href="?p=l2_eingang">Kundenware einbuchen</a>');
flash_zeigen();

if (!tabelle_da('charge')) { hinweis('Es sind noch keine Chargen vorhanden.', 'warn'); fuss(); return; }
?>
<?php if ($kunden): ?>
<div class="lg-reiter">
  <a href="?p=l2_bestand<?= $q ? '&q=' . urlencode($q) : '' ?>" class="<?= $kunde_id === 0 ? 'an' : '' ?>">Alle Kunden</a>
  <?php foreach ($kunden as $k): ?>
    <a href="?p=l2_bestand&kunde=<?= (int)$k['id'] ?><?= $q ? '&q=' . urlencode($q) : '' ?>" class="<?= $kunde_id === (int)$k['id'] ? 'an' : '' ?>">
      <?= h((string)$k['firma']) ?> <span class="lg-reiter-z"><?= (int)$k['chargen'] ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="bx-listbar">
  <input type="search" class="bx-search" placeholder="Suchen: Kunde, Produkt, Charge" data-filter="lg-l2" value="<?= h($q) ?>">
  <label class="bx-check" style="margin:0"><input type="checkbox" onchange="location.href='?p=l2_bestand<?= $kunde_id ? '&kunde=' . $kunde_id : '' ?><?= $q ? '&q=' . urlencode($q) : '' ?>' + (this.checked ? '&leer=1' : '')" <?= $mit_leer ? 'checked' : '' ?>> auch leere zeigen</label>
</div>

<?php if (!$zeilen): ?>
  <div class="bx-panel muted">Nichts im Fremdlager<?= $q ? ' für „' . h($q) . '"' : '' ?>. <a href="?p=l2_eingang">Kundenware einbuchen</a>.</div>
<?php else: ?>
<div class="bx-tablewrap" style="margin-bottom:var(--sp-6)">
  <table class="bx-table" id="lg-l2">
    <thead><tr>
      <th>Kunde</th><th>Produkt</th><th>Charge</th><th>MHD</th><th>Bestand</th><th>Status</th><th>Ort</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($zeilen as $z): ?>
      <tr onclick="location.href='?p=charge&id=<?= (int)$z['id'] ?>'" style="cursor:pointer">
        <td><?= h((string)($z['kunde'] ?? '–')) ?></td>
        <td><a href="?p=charge&id=<?= (int)$z['id'] ?>" class="lg-namelink" onclick="event.stopPropagation()"><?= h((string)$z['item_name']) ?></a><?= $z['artikelnummer'] ? ' <span class="muted">' . h((string)$z['artikelnummer']) . '</span>' : '' ?></td>
        <td class="lg-code"><?= h((string)$z['charge_nr']) ?></td>
        <td><?= mhd_html($z['mhd']) ?></td>
        <td><?= h(menge_txt($z['menge_verfuegbar'])) ?> <?= h((string)$z['einheit']) ?></td>
        <td><?= status_badge($z['status']) ?></td>
        <td><?php $ik = kiste_fuer_charge((int)$z['id']); $bl = leiste_fuer_charge((int)$z['id']);
          if ($ik): ?>Kiste <?= h((string)$ik['kiste_name']) ?><?php elseif ($bl): ?><span class="lg-code"><?= h((string)$bl['code']) ?></span><?php else: ?><span class="muted">–</span><?php endif; ?></td>
        <td style="text-align:right"><a class="btn btn-ghost btn-sm" href="?p=etikett&id=<?= (int)$z['id'] ?>" target="_blank" onclick="event.stopPropagation()" title="QR-Etikett als PDF">Etikett</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
fuss();
