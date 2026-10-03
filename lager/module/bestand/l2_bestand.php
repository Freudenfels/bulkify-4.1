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
  <table class="bx-table lg-karten" id="lg-l2">
    <thead><tr>
      <th>Kunde</th><th>Produkt</th><th>Charge</th><th>MHD</th><th>Bestand</th><th>Status</th><th>Ort</th><th>Finden</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($zeilen as $z):
      $bf = blinker_fuer_charge((int)$z['id']); $lid = (int)($bf['leiste']['id'] ?? 0);
      $ort = $bf['kiste'] ? 'Kiste ' . (string)$bf['kiste']['kiste_name'] : ($bf['leiste'] ? (string)$bf['leiste']['code'] : '');
    ?>
      <tr onclick="location.href='?p=charge&id=<?= (int)$z['id'] ?>'" style="cursor:pointer">
        <td data-label="Kunde"><?= h((string)($z['kunde'] ?? '–')) ?></td>
        <td data-label=""><a href="?p=charge&id=<?= (int)$z['id'] ?>" class="lg-namelink" onclick="event.stopPropagation()"><?= h((string)$z['item_name']) ?></a><?= $z['artikelnummer'] ? ' <span class="muted">' . h((string)$z['artikelnummer']) . '</span>' : '' ?></td>
        <td data-label="Charge" class="lg-code"><?= h((string)$z['charge_nr']) ?></td>
        <td data-label="MHD"><?= mhd_html($z['mhd']) ?></td>
        <td data-label="Bestand"><?= h(menge_txt($z['menge_verfuegbar'])) ?> <?= h((string)$z['einheit']) ?></td>
        <td data-label="Status"><?= status_badge($z['status']) ?></td>
        <td data-label="Ort"><?= $ort !== '' ? '<span class="lg-code">' . h($ort) . '</span>' : '<span class="muted">–</span>' ?></td>
        <td data-label="Finden" class="lg-td-finden"><?php if ($lid): ?><button type="button" class="btn btn-primary btn-sm" data-klingeln="<?= $lid ?>" data-farbe="gruen" data-sek="40" onclick="event.stopPropagation()">Finden</button><?php else: ?><span class="muted" style="font-size:12px">kein Blinker</span><?php endif; ?></td>
        <td data-label="" style="text-align:right"><a class="btn btn-ghost btn-sm" href="?p=etikett_ansicht&id=<?= (int)$z['id'] ?>&zurueck=<?= rawurlencode('?p=l2_bestand') ?>" onclick="event.stopPropagation()" title="Etikett ansehen / drucken">Etikett</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
fuss();
