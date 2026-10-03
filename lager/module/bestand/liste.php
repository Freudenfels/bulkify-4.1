<?php
// Bestand: alle eigenen Chargen, nach Kategorie getrennt, anklickbar. Startseite des Lagers.
$kat = (string)($_GET['kat'] ?? '');
if ($kat !== '' && !isset(erp_kategorien()[$kat])) $kat = '';
$q = trim((string)($_GET['q'] ?? ''));
$mit_leer = ($_GET['leer'] ?? '') === '1';
$sortOpt = ['neu' => 'Neuste zuerst', 'alt' => 'Älteste zuerst', 'mhd' => 'MHD (zuerst ablaufend)', 'name' => 'Name (A–Z)', 'menge' => 'Menge (viel zuerst)'];
$sort = (string)($_GET['sort'] ?? 'neu');
if (!isset($sortOpt[$sort])) $sort = 'neu';

$zeilen = erp_bestand($kat, $q, $mit_leer, 500, $sort);
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

<?php $qs = ($kat ? '&kat=' . h($kat) : '') . ($q ? '&q=' . urlencode($q) : '') . ($mit_leer ? '&leer=1' : ''); ?>
<div class="bx-listbar">
  <input type="search" class="bx-search" placeholder="Suchen: Rohstoff, Artikelnummer, Charge" data-filter="lg-bestand" value="<?= h($q) ?>" autofocus>
  <label class="bx-check" style="margin:0;white-space:nowrap">Sortierung
    <select onchange="location.href='?p=bestand<?= $qs ?>&sort=' + this.value" style="margin-left:6px">
      <?php foreach ($sortOpt as $sv => $sl): ?><option value="<?= h($sv) ?>" <?= $sort === $sv ? 'selected' : '' ?>><?= h($sl) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label class="bx-check" style="margin:0"><input type="checkbox" onchange="location.href='?p=bestand<?= ($kat ? '&kat=' . h($kat) : '') . ($q ? '&q=' . urlencode($q) : '') ?>&sort=<?= h($sort) ?>' + (this.checked ? '&leer=1' : '')" <?= $mit_leer ? 'checked' : '' ?>> auch leere zeigen</label>
</div>

<?php if (!$zeilen): ?>
  <div class="bx-panel muted">Nichts im Bestand<?= $q ? ' für „' . h($q) . '"' : '' ?>.</div>
<?php else: ?>
<div class="bx-tablewrap" style="margin-bottom:var(--sp-6)">
  <table class="bx-table lg-karten" id="lg-bestand">
    <thead><tr>
      <th>Rohstoff / Produkt</th><?= $kat === '' ? '<th>Kategorie</th>' : '' ?>
      <th>Charge</th><th>Eingang</th><th>MHD</th><th>Bestand</th><th>Status</th><th>Ort</th><th>Finden</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($zeilen as $z):
      $bf = blinker_fuer_charge((int)$z['id']); $lid = (int)($bf['leiste']['id'] ?? 0);
      $ort = $bf['kiste'] ? 'Kiste ' . (string)$bf['kiste']['kiste_name'] : ($bf['leiste'] ? (string)$bf['leiste']['code'] : '');
    ?>
      <tr onclick="location.href='?p=charge&id=<?= (int)$z['id'] ?>'" style="cursor:pointer">
        <td data-label=""><a href="?p=charge&id=<?= (int)$z['id'] ?>" class="lg-namelink" onclick="event.stopPropagation()"><?= h((string)$z['item_name']) ?></a><?= $z['artikelnummer'] ? ' <span class="muted">' . h((string)$z['artikelnummer']) . '</span>' : '' ?></td>
        <?= $kat === '' ? '<td data-label="Kategorie" class="muted">' . h(erp_kategorie_label($z)) . '</td>' : '' ?>
        <td data-label="Charge" class="lg-code"><?= h((string)$z['charge_nr']) ?></td>
        <td data-label="Eingang" class="muted"><?= !empty($z['wareneingang']) ? h(date('d.m.Y', strtotime((string)$z['wareneingang']))) : '–' ?></td>
        <td data-label="MHD"><?= mhd_html($z['mhd']) ?></td>
        <td data-label="Bestand"><?= h(menge_txt($z['menge_verfuegbar'])) ?> <?= h((string)$z['einheit']) ?></td>
        <td data-label="Status"><?= status_badge($z['status']) ?></td>
        <td data-label="Ort"><?= $ort !== '' ? '<span class="lg-code">' . h($ort) . '</span>' : '<span class="muted">–</span>' ?></td>
        <td data-label="Finden" class="lg-td-finden"><?php if ($lid): ?><button type="button" class="btn btn-primary btn-sm" data-klingeln="<?= $lid ?>" data-farbe="gruen" data-sek="40" onclick="event.stopPropagation()">Finden</button><?php else: ?><span class="muted" style="font-size:12px">kein Blinker</span><?php endif; ?></td>
        <td data-label="" style="text-align:right"><a class="btn btn-ghost btn-sm" href="?p=etikett_ansicht&id=<?= (int)$z['id'] ?>&zurueck=<?= rawurlencode('?p=bestand' . $qs . '&sort=' . $sort) ?>" onclick="event.stopPropagation()" title="Etikett ansehen / drucken">Etikett</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
fuss();
