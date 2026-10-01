<?php
// Warenausgang (Warenlager-Manager): Charge suchen, Menge abbuchen. Wird eine Charge leer, wird ihr
// Blinker automatisch gelöst (und aus der Kiste genommen). Schreibt ueber erp_charge_entnehmen().
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'entnehmen') {
    $cid   = (int)($_POST['charge_id'] ?? 0);
    $menge = (float) str_replace(',', '.', trim((string)($_POST['menge'] ?? '0')));
    $grund = trim((string)($_POST['grund'] ?? '')) ?: 'Warenausgang';
    $r = erp_charge_entnehmen($cid, $menge);
    if (!$r['ok']) { flash($r['meldung'], 'warn'); weiter('?p=ausgang' . ($_POST['q'] ?? '' ? '&q=' . urlencode((string)$_POST['q']) : '')); }
    lg_bewegung_log($cid, 'aus', $menge, $r['einheit'], (string)$r['item_name'], $grund);
    $extra = '';
    if (!empty($r['leer'])) {
        $bl = leiste_fuer_charge($cid);
        if ($bl) { leiste_aus((int)$bl['id']); leiste_loesen((int)$bl['id']); $extra .= ' Blinker ' . (string)$bl['code'] . ' gelöst.'; }
        $ik = kiste_fuer_charge($cid);
        if ($ik) { kiste_charge_entfernen($cid); $extra .= ' Aus der Kiste genommen.'; }
        $extra = ' Charge ist jetzt leer.' . $extra;
    }
    flash('Abgebucht: ' . menge_txt($menge) . ' ' . (string)$r['einheit'] . ' ' . (string)$r['item_name'] . '.' . $extra);
    weiter('?p=ausgang' . (($_POST['q'] ?? '') !== '' ? '&q=' . urlencode((string)$_POST['q']) : ''));
}

$q      = trim((string)($_GET['q'] ?? ''));
$zeilen = erp_bestand('', $q, false, 100);   // nur eigener, nicht-leerer Bestand
$letzte = lg_bewegungen(10);

kopf('Warenausgang', 'ausgang');
seitenkopf('Warenausgang', 'Was geht raus? Charge suchen und Menge abbuchen.',
    '<a class="btn btn-ghost" href="?p=bestand">Zum Bestand</a>');
flash_zeigen();

if (!tabelle_da('charge')) { hinweis('Es sind noch keine Chargen im Dashboard vorhanden.', 'warn'); fuss(); return; }
?>
<div class="bx-listbar">
  <form method="get" class="bx-row" style="gap:6px;margin:0;flex:1" role="search">
    <input type="hidden" name="p" value="ausgang">
    <input type="search" class="bx-search" name="q" value="<?= h($q) ?>" placeholder="Suchen: Rohstoff, Artikelnummer, Charge" autofocus>
    <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=ausgang">×</a><?php endif; ?>
  </form>
</div>

<?php if (!$zeilen): ?>
  <div class="bx-panel muted">Nichts im Bestand<?= $q ? ' für „' . h($q) . '"' : '' ?>.</div>
<?php else: ?>
<div class="bx-tablewrap" style="margin-bottom:var(--sp-6)">
  <table class="bx-table">
    <thead><tr><th>Rohstoff / Produkt</th><th>Charge</th><th>MHD</th><th>Verfügbar</th><th>Status</th><th style="width:260px">Abbuchen</th></tr></thead>
    <tbody>
    <?php foreach ($zeilen as $z): ?>
      <tr>
        <td><a href="?p=charge&id=<?= (int)$z['id'] ?>" class="lg-namelink"><?= h((string)$z['item_name']) ?></a><?= $z['artikelnummer'] ? ' <span class="muted">' . h((string)$z['artikelnummer']) . '</span>' : '' ?></td>
        <td class="lg-code"><?= h((string)$z['charge_nr']) ?: '–' ?></td>
        <td><?= mhd_html($z['mhd']) ?></td>
        <td><?= h(menge_txt($z['menge_verfuegbar'])) ?> <?= h((string)$z['einheit']) ?></td>
        <td><?= status_badge($z['status']) ?></td>
        <td>
          <form method="post" class="bx-row" style="gap:6px;margin:0;align-items:center" data-no-busy>
            <input type="hidden" name="aktion" value="entnehmen">
            <input type="hidden" name="charge_id" value="<?= (int)$z['id'] ?>">
            <input type="hidden" name="q" value="<?= h($q) ?>">
            <input type="text" inputmode="decimal" name="menge" required placeholder="Menge" style="width:90px">
            <button class="btn btn-primary btn-sm" type="submit">Abbuchen</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($letzte): ?>
<div class="bx-panel">
  <h2>Zuletzt bewegt</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Zeit</th><th>Richtung</th><th>Artikel</th><th>Menge</th><th>Grund</th></tr></thead>
    <tbody>
    <?php foreach ($letzte as $b): ?>
      <tr>
        <td class="muted"><?= h(fmt_zeit((string)$b['angelegt'], 'd.m. H:i')) ?></td>
        <td><?= $b['typ'] === 'ein' ? '<span class="badge badge-ok">Eingang</span>' : '<span class="badge badge-warn">Ausgang</span>' ?></td>
        <td><?= h((string)$b['item_name']) ?></td>
        <td><?= h(menge_txt($b['menge'])) ?> <?= h((string)$b['einheit']) ?></td>
        <td class="muted"><?= h((string)$b['grund']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div style="margin-top:10px"><a class="btn btn-ghost btn-sm" href="?p=bewegungen">Alle Bewegungen</a></div>
</div>
<?php endif; ?>
<?php
fuss();
