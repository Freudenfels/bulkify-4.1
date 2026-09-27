<?php
// Detailansicht einer Charge: Produkt, Charge, MHD, Menge, Lieferant, Wareneingang, Tracking, Blinker.
$id = (int)($_GET['id'] ?? 0);
$c = erp_charge_voll($id);
if (!$c) { flash('Diese Charge gibt es nicht.', 'warn'); weiter('?p=bestand'); }

// Blinker binden/lösen direkt hier.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'binden') {
        $scan = trim((string)($_POST['code'] ?? ''));
        $code = led_leiste_normalisieren($scan);
        if ($code === null) flash('"' . $scan . '" ist kein Blinker-Code (z. B. CF64B6XD).', 'warn');
        else {
            $fehler = leiste_binden($code, $id);
            if ($fehler !== '') flash($fehler, 'warn');
            else { leiste_finden((int)leiste_per_code($code)['id'], 'blau', 3, false); flash('Blinker ' . $code . ' gebunden, leuchtet kurz blau.'); }
        }
        weiter('?p=charge&id=' . $id);
    }
    if ($aktion === 'loesen') {
        $lid = (int)($_POST['leiste_id'] ?? 0);
        leiste_finden($lid, 'rot', 3, false); leiste_loesen($lid);
        flash('Blinker gelöst.');
        weiter('?p=charge&id=' . $id);
    }
}

$bl = leiste_fuer_charge($id);

kopf('Charge ' . (string)$c['charge_nr'], 'bestand');
seitenkopf((string)$c['item_name'], erp_kategorie_label($c) . ($c['artikelnummer'] ? ' · ' . $c['artikelnummer'] : ''),
    '<a class="btn btn-ghost" href="?p=bestand">Zum Bestand</a>');
flash_zeigen();
?>
<div class="bx-grid" style="margin-bottom:var(--sp-5)">
  <div class="bx-card"><div class="k">Bestand</div><div class="v"><?= h(menge_txt($c['menge_verfuegbar'])) ?> <?= h((string)$c['einheit']) ?></div></div>
  <div class="bx-card"><div class="k">MHD</div><div class="v" style="font-size:var(--fs-lg)"><?= mhd_html($c['mhd']) ?></div></div>
  <div class="bx-card"><div class="k">Status</div><div class="v" style="font-size:var(--fs-lg)"><?= status_badge($c['status']) ?></div></div>
  <div class="bx-card"><div class="k">Charge</div><div class="v lg-code" style="font-size:var(--fs-lg)"><?= h((string)$c['charge_nr']) ?: '–' ?></div></div>
</div>

<div class="bx-panel">
  <h2>Blinker</h2>
  <?php if ($bl): ?>
    <p>Am Blinker <span class="lg-code"><strong><?= h((string)$bl['code']) ?></strong></span> seit <?= h(fmt_zeit((string)$bl['gebunden_am'])) ?>.</p>
    <div class="bx-row" style="gap:var(--sp-3)">
      <button type="button" class="btn btn-primary" data-klingeln="<?= (int)$bl['id'] ?>">Finden</button>
      <button type="button" class="btn btn-ghost" data-klingeln="<?= (int)$bl['id'] ?>" data-aktion="aus">Aus</button>
      <form method="post" style="display:inline" onsubmit="return confirm('Blinker <?= h((string)$bl['code']) ?> lösen?')">
        <input type="hidden" name="aktion" value="loesen"><input type="hidden" name="leiste_id" value="<?= (int)$bl['id'] ?>">
        <button class="btn btn-ghost lg-x" type="submit" title="Blinker lösen">×</button>
      </form>
    </div>
  <?php else: ?>
    <p class="muted">Kein Blinker an dieser Charge.</p>
    <form method="post" class="bx-row" style="gap:6px" data-no-busy>
      <input type="hidden" name="aktion" value="binden">
      <input name="code" class="lg-code" style="max-width:200px" placeholder="Blinker scannen (CF64B6XD)" autocomplete="off" autofocus>
      <button class="btn btn-primary" type="submit">Binden</button>
    </form>
  <?php endif; ?>
</div>

<div class="bx-panel">
  <h2>Angaben</h2>
  <table class="bx-table"><tbody>
    <tr><td class="muted" style="width:200px">Rohstoff / Produkt</td><td><?= h((string)$c['item_name']) ?></td></tr>
    <tr><td class="muted">Kategorie</td><td><?= h(erp_kategorie_label($c)) ?></td></tr>
    <tr><td class="muted">Artikelnummer</td><td><?= h((string)$c['artikelnummer']) ?: '–' ?></td></tr>
    <tr><td class="muted">Chargennummer</td><td class="lg-code"><?= h((string)$c['charge_nr']) ?: '–' ?></td></tr>
    <tr><td class="muted">Eingegangene Menge</td><td><?= h(menge_txt($c['menge'] ?? null)) ?> <?= h((string)$c['einheit']) ?></td></tr>
    <tr><td class="muted">Verfügbar</td><td><?= h(menge_txt($c['menge_verfuegbar'])) ?> <?= h((string)$c['einheit']) ?></td></tr>
    <tr><td class="muted">MHD</td><td><?= mhd_html($c['mhd']) ?></td></tr>
    <tr><td class="muted">Lieferant</td><td><?= h((string)($c['lieferant'] ?? '')) ?: '–' ?></td></tr>
    <tr><td class="muted">Wareneingang</td><td><?= $c['wareneingang'] ? h(date('d.m.Y', strtotime((string)$c['wareneingang']))) : '–' ?></td></tr>
    <?php if (!empty($c['tracking'])): ?><tr><td class="muted">Sendungsnummer(n)</td><td><?= nl2br(h((string)$c['tracking'])) ?></td></tr><?php endif; ?>
    <?php if (!empty($c['notiz'])): ?><tr><td class="muted">Notiz</td><td><?= nl2br(h((string)$c['notiz'])) ?></td></tr><?php endif; ?>
  </tbody></table>
</div>
<?php
fuss();
