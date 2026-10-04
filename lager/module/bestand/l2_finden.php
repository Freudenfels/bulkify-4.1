<?php
// Lager 2 (Fremdlager) – Finden: Kundenware suchen, der Blinker an der Charge blinkt.
$q = trim((string)($_GET['q'] ?? ''));
$kunde_id = (int)($_GET['kunde'] ?? 0);
$kunden = erp_bestand_fremd_kunden();
$treffer = ($q !== '' || $kunde_id > 0) ? erp_chargen_suche_fremd($q, $kunde_id, 60) : [];

kopf('Lager 2 – Suche', 'l2_finden');
seitenkopf('Lager 2 (Fremdlager) – Suche', 'Kundenware suchen – der Blinker an der Palette blinkt.');
flash_zeigen();
?>
<form method="get" class="bx-listbar">
  <input type="hidden" name="p" value="l2_finden">
  <select name="kunde" onchange="this.form.submit()" style="max-width:220px">
    <option value="0">Alle Kunden</option>
    <?php foreach ($kunden as $k): ?><option value="<?= (int)$k['id'] ?>" <?= $kunde_id === (int)$k['id'] ? 'selected' : '' ?>><?= h((string)$k['firma']) ?></option><?php endforeach; ?>
  </select>
  <input type="search" class="bx-search" name="q" placeholder="Suchen: Produkt, Charge, Kunde" value="<?= h($q) ?>" autofocus>
  <button class="btn btn-primary" type="submit">Suchen</button>
</form>

<?php if (!$treffer): ?>
  <div class="bx-panel muted"><?= ($q !== '' || $kunde_id > 0) ? 'Nichts gefunden.' : 'Kunde wählen oder einen Begriff eingeben – der Blinker an der Palette blinkt.' ?></div>
<?php else: ?>
<div class="bx-tablewrap">
  <table class="bx-table">
    <thead><tr><th>Kunde</th><th>Produkt</th><th>Charge</th><th>MHD</th><th>Bestand</th><th>Blinker</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($treffer as $t): $bl = leiste_fuer_charge((int)$t['id']); ?>
      <tr>
        <td><?= h((string)($t['kunde'] ?? '–')) ?></td>
        <td><a href="?p=charge&id=<?= (int)$t['id'] ?>" class="lg-namelink"><?= h((string)$t['item_name']) ?></a></td>
        <td class="lg-code"><?= h((string)$t['charge_nr']) ?></td>
        <td><?= mhd_html($t['mhd']) ?></td>
        <td><?= h(menge_txt($t['menge_verfuegbar'])) ?> <?= h((string)$t['einheit']) ?></td>
        <td><?= $bl ? '<span class="lg-code">' . h((string)$bl['code']) . '</span>' : '<span class="muted">kein Blinker</span>' ?></td>
        <td style="text-align:right">
          <?php if ($bl): ?>
            <button type="button" class="btn btn-primary btn-sm" data-klingeln="<?= (int)$bl['id'] ?>" data-farbe="blau" data-sek="40">Finden</button>
          <?php else: ?><span class="muted" style="font-size:12px">–</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
fuss();
