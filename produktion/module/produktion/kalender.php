<?php
// Produktionskalender: Monatsraster mit terminierten Produktionsaufträgen (nach geplant_am),
// darunter die Aufträge ohne Termin. Nur lesend über die Naht erp.php.
$monat = (string)($_GET['m'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $monat)) $monat = date('Y-m');
$erster = $monat . '-01';
$ts     = strtotime($erster);
$tage   = (int)date('t', $ts);
$prev   = date('Y-m', strtotime($erster . ' -1 month'));
$next   = date('Y-m', strtotime($erster . ' +1 month'));

// Alle Aufträge holen, nach Tag im Monat gruppieren; Rest ohne Termin.
$proTag = []; $ohneTermin = [];
foreach (erp_produktionsauftraege('alle') as $pa) {
    $g = (string)($pa['geplant_am'] ?? '');
    if ($g !== '' && str_starts_with($g, $monat)) $proTag[(int)date('j', strtotime($g))][] = $pa;
    elseif ($g === '' && in_array((string)$pa['status'], ['offen','laufend'], true)) $ohneTermin[] = $pa;
}

$monLabel = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'][(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
kopf('Kalender', 'kalender');
seitenkopf('Produktionskalender', $monLabel,
    '<a class="btn btn-ghost btn-sm" href="?p=kalender&m=' . h($prev) . '">&larr; Vormonat</a> '
    . '<a class="btn btn-ghost btn-sm" href="?p=kalender&m=' . h(date('Y-m')) . '">Heute</a> '
    . '<a class="btn btn-ghost btn-sm" href="?p=kalender&m=' . h($next) . '">Folgemonat &rarr;</a>');

// Wochentags-Offset: Mo=0 … So=6
$startWd = ((int)date('N', $ts)) - 1;
$heute = date('Y-m-d');
?>
<style>
.pk-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}
.pk-wd{color:var(--muted);font-size:12px;text-align:left;padding:2px 4px}
.pk-cell{min-height:96px;border:1px solid var(--line);border-radius:var(--r-sm);padding:5px;background:var(--panel-2);display:flex;flex-direction:column;gap:3px}
.pk-cell.heute{border-color:var(--gruen)}
.pk-leer{background:transparent;border:none}
.pk-tag{font-size:12px;color:var(--muted)}
.pk-item{display:block;font-size:11px;line-height:1.25;padding:3px 5px;border-radius:var(--r-sm);background:var(--panel);border:1px solid var(--line);color:var(--text);text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(max-width:700px){.pk-grid{grid-template-columns:repeat(2,1fr)}.pk-wd{display:none}}
</style>
<div class="bx-panel">
  <div class="pk-grid" style="margin-bottom:6px">
    <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $wd): ?><div class="pk-wd"><?= $wd ?></div><?php endforeach; ?>
  </div>
  <div class="pk-grid">
    <?php for ($i = 0; $i < $startWd; $i++): ?><div class="pk-cell pk-leer"></div><?php endfor; ?>
    <?php for ($t = 1; $t <= $tage; $t++): $datum = sprintf('%s-%02d', $monat, $t); ?>
      <div class="pk-cell<?= $datum === $heute ? ' heute' : '' ?>">
        <div class="pk-tag"><?= $t ?></div>
        <?php foreach ($proTag[$t] ?? [] as $pa): ?>
          <a class="pk-item" href="?p=pa&id=<?= (int)$pa['id'] ?>" title="<?= h((string)$pa['nummer'] . ' · ' . ($pa['produkt_name'] ?: '')) ?>"><?= h((string)$pa['nummer']) ?> · <?= h((string)($pa['produkt_name'] ?: '–')) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endfor; ?>
  </div>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Ohne Termin <span class="muted" style="font-size:14px"><?= count($ohneTermin) ?></span></h2>
  <?php if (!$ohneTermin): ?>
    <div class="muted">Alle aktiven Aufträge sind terminiert.</div>
  <?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Nr.</th><th>Produkt</th><th>Kunde</th><th class="bx-num">Menge</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($ohneTermin as $pa): ?>
          <tr onclick="location.href='?p=pa&id=<?= (int)$pa['id'] ?>'" style="cursor:pointer">
            <td><strong><?= h((string)$pa['nummer']) ?></strong></td>
            <td><?= h((string)($pa['produkt_name'] ?: '–')) ?></td>
            <td><?= h((string)($pa['kunde'] ?: '–')) ?></td>
            <td class="bx-num"><?= menge_txt($pa['menge']) ?></td>
            <td><?= pa_badge((string)$pa['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="muted" style="font-size:12px;margin:10px 0 0">Ein Produktionsdatum setzt das Dashboard am Auftrag (geplant_am). Danach erscheinen sie oben im Kalender.</p>
  <?php endif; ?>
</div>
<?php fuss();
