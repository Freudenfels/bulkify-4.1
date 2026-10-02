<?php
// Erwartete Lieferungen ("Waren, auf die wir warten"): beim Lieferanten bestellt, noch nicht angekommen.
// Zeigt Termin + Sendungsnummer + Positionen; je Position "Einbuchen" -> Wareneingang vorbefuellt.
$lieferungen = erp_erwartete_lieferungen();

kopf('Erwartete Lieferungen', 'erwartet');
seitenkopf('Erwartete Lieferungen', 'Waren, auf die wir warten – beim Lieferanten bestellt, noch nicht angekommen.',
    '<a class="btn btn-ghost" href="?p=eingang">Freier Wareneingang</a>');
flash_zeigen();

if (!$lieferungen) {
    hinweis('Aktuell sind keine Lieferungen unterwegs. Sobald im Dashboard eine Bestellung als „bestellt" markiert ist, erscheint sie hier.', 'ok');
    fuss();
    return;
}

$heute = date('Y-m-d');
foreach ($lieferungen as $l):
    $eta = (string)($l['eta_geplant'] ?? '');
    $ueberfaellig = $eta !== '' && $eta < $heute;
?>
<div class="bx-panel">
  <div class="bx-head" style="margin:0 0 10px">
    <div>
      <h2 style="margin:0"><?= h((string)($l['lieferant'] ?: 'Ohne Lieferant')) ?>
        <?php if (!empty($l['nummer'])): ?><span class="muted" style="font-weight:normal">· <?= h((string)$l['nummer']) ?></span><?php endif; ?>
      </h2>
      <p class="bx-sub" style="margin:4px 0 0">
        <?php if (!empty($l['bestelldatum'])): ?>bestellt am <?= h(date('d.m.Y', strtotime((string)$l['bestelldatum']))) ?><?php endif; ?>
        <?php if ($eta !== ''): ?> · erwartet <strong><?= h(date('d.m.Y', strtotime($eta))) ?></strong>
          <?php if ($ueberfaellig): ?><span class="badge badge-warn" style="margin-left:6px">überfällig</span><?php endif; ?>
        <?php endif; ?>
      </p>
    </div>
    <?php if (!empty($l['tracking'])): ?>
      <div class="bx-row" style="align-items:center;gap:8px">
        <span class="muted">Sendung<?= !empty($l['versandanbieter']) ? ' (' . h((string)$l['versandanbieter']) . ')' : '' ?>:</span>
        <span class="lg-code"><?= h((string)$l['tracking']) ?></span>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($l['positionen'])): ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Artikel</th><th class="bx-num">Erwartete Menge</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($l['positionen'] as $p):
        $name = (string)($p['name'] ?? '');
        $menge = (float)($p['menge'] ?? 0);
        $einh = (string)($p['einheit'] ?? '');
        $buchUrl = '?p=eingang'
          . '&item=' . (int)($p['item_id'] ?? 0)
          . '&menge=' . rawurlencode(menge_txt($menge))
          . ($l['lieferant_id'] ? '&lieferant=' . (int)$l['lieferant_id'] : '')
          . (!empty($l['nummer']) ? '&charge=' . rawurlencode((string)$l['nummer']) : '');
      ?>
      <tr>
        <td><?= $name !== '' ? h($name) : '<span class="muted">(unbekannter Artikel)</span>' ?></td>
        <td class="bx-num"><?= h(menge_txt($menge)) ?> <?= h($einh) ?></td>
        <td style="text-align:right">
          <?php if (!empty($p['item_id'])): ?>
            <a class="btn btn-primary btn-sm" href="<?= h($buchUrl) ?>">Einbuchen</a>
          <?php else: ?>
            <span class="muted" style="font-size:12px">kein Artikel hinterlegt</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?>
    <p class="muted" style="margin:0">Keine Positionen hinterlegt. <a href="?p=eingang">Freier Wareneingang</a>.</p>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php
fuss();
