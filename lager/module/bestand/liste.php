<?php
// Bestand: alle eigenen Chargen, nach Kategorie getrennt, anklickbar. Startseite des Lagers.
$kat = (string)($_GET['kat'] ?? '');
if ($kat !== '' && !isset(erp_kategorien()[$kat])) $kat = '';
$q = trim((string)($_GET['q'] ?? ''));
$mit_leer = ($_GET['leer'] ?? '') === '1';
$nur_kisten = ($_GET['nur_kisten'] ?? '') === '1';
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
  <a class="btn btn-ghost btn-sm" href="?p=bestand<?= $nur_kisten ? '' : '&nur_kisten=1' ?>" style="margin-left:auto"><?= $nur_kisten ? 'Alle Chargen' : 'Nur Kisten' ?></a>
</div>

<?php
// --- Kisten-Ansicht: ausklappbare Blöcke mit Inhalt. Bei ?nur_kisten=1 statt der Chargenliste. ---
if ($nur_kisten):
    $kisten = function_exists('kiste_alle') ? kiste_alle() : [];
    if (!$kisten): ?>
      <div class="bx-panel muted">Noch keine Kisten angelegt. Unter „Kisten" anlegen, dann beim Einbuchen zuweisen.</div>
    <?php else: foreach ($kisten as $kk):
        $inh = kiste_inhalt((int)$kk['id']);
    ?>
      <details class="bx-panel lg-kiste" style="margin-bottom:var(--sp-3)">
        <summary style="cursor:pointer;display:flex;align-items:center;gap:var(--sp-3);flex-wrap:wrap">
          <strong><?= h((string)$kk['name']) ?></strong>
          <span class="muted"><?= count($inh) ?> Posten</span>
          <?php if (!empty($kk['blinker'])): ?>
            <span class="lg-code"><?= h((string)$kk['blinker']) ?></span>
            <button type="button" class="btn btn-primary btn-sm" data-klingeln="<?= (int)$kk['leiste_id'] ?>" data-farbe="gruen" data-sek="40">Kiste finden</button>
          <?php else: ?><span class="muted" style="font-size:12px">kein Blinker</span><?php endif; ?>
        </summary>
        <?php if (!$inh): ?>
          <div class="muted" style="margin-top:var(--sp-3)">Kiste ist leer.</div>
        <?php else: ?>
        <div class="bx-tablewrap" style="margin-top:var(--sp-3)">
          <table class="bx-table lg-karten">
            <thead><tr><th>Rohstoff / Produkt</th><th>Charge</th><th>MHD</th><th>Menge</th><th>Fach</th></tr></thead>
            <tbody>
            <?php foreach ($inh as $it): $c = $it['charge']; if (!$c) continue; ?>
              <tr onclick="location.href='?p=charge&id=<?= (int)$it['charge_id'] ?>'" style="cursor:pointer">
                <td data-label=""><a href="?p=charge&id=<?= (int)$it['charge_id'] ?>" class="lg-namelink" onclick="event.stopPropagation()"><?= h((string)($c['item_name'] ?? '')) ?></a></td>
                <td data-label="Charge" class="lg-code"><?= h((string)($c['charge_nr'] ?? '')) ?></td>
                <td data-label="MHD"><?= mhd_html($c['mhd'] ?? null) ?></td>
                <td data-label="Menge"><?= h(menge_txt($c['menge_verfuegbar'] ?? 0)) ?> <?= h((string)($c['einheit'] ?? '')) ?></td>
                <td data-label="Fach"><?= !empty($it['fach']) ? h((string)$it['fach']) : '<span class="muted">–</span>' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </details>
    <?php endforeach; endif; ?>
<?php fuss(); return; endif; ?>

<?php if (!$zeilen): ?>
  <div class="bx-panel muted">Nichts im Bestand<?= $q ? ' für „' . h($q) . '"' : '' ?>.</div>
<?php else: ?>
<?php // Schmale Tabelle (Nico 08.11.2026): Finden zuerst, dann nur Produkt, Menge, Status, Kategorie.
      // Charge steht klein unter dem Namen (zum Unterscheiden und für die Suche); MHD, Eingang, Ort, Etikett auf der Chargen-Seite. ?>
<div class="bx-tablewrap" style="margin-bottom:var(--sp-6)">
  <table class="bx-table lg-karten lg-bestand-schmal" id="lg-bestand">
    <thead><tr>
      <th style="width:1%"></th><th>Produkt</th><th>Menge</th><th>Status</th><?= $kat === '' ? '<th>Kategorie</th>' : '' ?>
    </tr></thead>
    <tbody>
    <?php foreach ($zeilen as $z):
      $bf = blinker_fuer_charge((int)$z['id']); $lid = (int)($bf['leiste']['id'] ?? 0);
      $unter = array_filter([$z['artikelnummer'] ? (string)$z['artikelnummer'] : '', $z['charge_nr'] ? 'Charge ' . (string)$z['charge_nr'] : '', erp_bezug_text($z)]);
    ?>
      <tr onclick="location.href='?p=charge&id=<?= (int)$z['id'] ?>'" style="cursor:pointer">
        <td data-label="" class="lg-td-finden"><?php if ($lid): ?><button type="button" class="btn btn-primary btn-sm" data-klingeln="<?= $lid ?>" data-farbe="gruen" data-sek="40" onclick="event.stopPropagation()">Finden</button><?php else: ?><span class="muted" style="font-size:12px;white-space:nowrap">kein Blinker</span><?php endif; ?></td>
        <td data-label="" class="lg-td-name"><a href="?p=charge&id=<?= (int)$z['id'] ?>" class="lg-namelink" onclick="event.stopPropagation()"><?= h((string)$z['item_name']) ?></a><?php if ($unter): ?><div class="muted" style="font-size:12px;margin-top:2px;font-weight:400"><?= h(implode(' · ', $unter)) ?></div><?php endif; ?></td>
        <td data-label="Menge" style="white-space:nowrap"><?= h(menge_txt($z['menge_verfuegbar'])) ?> <?= h((string)$z['einheit']) ?></td>
        <td data-label="Status"><?= status_badge($z['status']) ?><?= (($z['standort'] ?? 'lager1') === 'produktion') ? ' <span class="bx-badge" style="background:#eef3e0;color:#3d5a18;border:1px solid #c9d9a3">in Produktion</span>' : '' ?></td>
        <?= $kat === '' ? '<td data-label="Kategorie" class="muted">' . h(erp_kategorie_label($z)) . '</td>' : '' ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
fuss();
