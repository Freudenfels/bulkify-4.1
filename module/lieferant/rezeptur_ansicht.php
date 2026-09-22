<?php
// Lieferantenportal – Rezeptur ansehen (read-only). Route: ?p=lieferant_rezeptur&id=<rezeptur_id>
// Der Lieferant sieht die Zusammensetzung je Einheit, damit er weiss, was er fuer die Fremdfertigung
// bepreisen/produzieren soll. KEIN Kundenbezug, keine Preise, keine internen Artikelnummern – nur die
// Bestandteile mit Menge je Einheit. Nur Rezepturen der fuer ihn FREIGESCHALTETEN Formen sind sichtbar
// (gleiche Regel wie bei den Rezeptur-Preisen), damit er keine fremden Rezepturen ueber die ID aufrufen kann.
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
require_once BX_ROOT . '/core/schema.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$lid = aktueller_lieferant_id();
$spr = lp_sprache();
$rid = (int)($_GET['id'] ?? 0);

// Welche Formen darf dieser Lieferant sehen?
$fertigFormen = array_values(array_filter(array_map('trim', explode(',', (string) scalar("SELECT fertig_formen FROM lieferanten WHERE id=?", [$lid])))));
$r = $rid ? one("SELECT id, nummer, name, darreichungsform AS form, kapselgroesse_id FROM rezeptur WHERE id=?", [$rid]) : null;
// Nur anzeigen, wenn die Form fuer den Lieferanten freigeschaltet ist.
if ($r && !in_array((string)$r['form'], $fertigFormen, true)) $r = null;

$formLabel = ['kapsel'=>'Kapseln','tablette'=>'Tabletten','softgel'=>'Softgels','stick'=>'Sticks','pulver'=>'Pulver','fluessig'=>'Flüssig','gummi'=>'Fruchtgummi','gel'=>'Gel'];

lp_head('bulkify – ' . lp_t('rez_ansehen'));
lp_shell_start('lieferant_rezepturpreise');

if (!$r) {
    echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h(lp_t('rez_nicht_da')) . '</div>';
    echo '<p style="margin-top:12px"><a href="?p=lieferant_rezepturpreise">&larr; ' . h(lp_t('rez_preise_menu')) . '</a></p>';
    lp_shell_ende(); lp_foot(); return;
}

// Bestandteile je Einheit (mg). Name-Snapshot bevorzugt, sonst der aktuelle Artikelname.
$zutaten = all("SELECT z.menge_mg, COALESCE(NULLIF(z.bezeichnung,''), i.name) AS name
                FROM rezeptur_zutat z LEFT JOIN item i ON i.id=z.item_id
                WHERE z.rezeptur_id=? ORDER BY z.sort, z.id", [$rid]);
$fuell = 0.0; foreach ($zutaten as $z) $fuell += (float)$z['menge_mg'];

// Kapselgroesse international lesbar (#0 statt „Größe 0").
$kgN = '';
if ($r['kapselgroesse_id']) {
    $kgN = (string) scalar("SELECT name FROM kapselgroesse WHERE id=?", [(int)$r['kapselgroesse_id']]);
    $kgN = $kgN !== '' ? '#' . trim(str_ireplace(['Größe', 'Gr.', 'Gr'], '', $kgN)) : '';
}
$mg = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
?>
<h1 style="margin-bottom:4px"><?= h($r['name']) ?> <span class="muted" style="font-weight:normal;font-size:16px"><?= h($r['nummer']) ?></span></h1>
<p class="bx-sub"><a href="?p=lieferant_rezepturpreise">&larr; <?= h(lp_t('rez_preise_menu')) ?></a></p>

<div class="bx-panel">
  <div class="bx-tablewrap"><table class="bx-table"><tbody>
    <tr><td style="width:220px"><?= h(lp_t('form_lbl')) ?></td><td><?= h($formLabel[(string)$r['form']] ?? (string)$r['form']) ?></td></tr>
    <?php if ($kgN !== ''): ?><tr><td><?= h(lp_t('kapselgroesse')) ?></td><td><?= h($kgN) ?></td></tr><?php endif; ?>
    <?php if ($fuell > 0): ?><tr><td><?= h(lp_t('fuellgewicht')) ?></td><td><?= h($mg($fuell)) ?> mg<?= $fuell >= 1000 ? ' (' . h(rtrim(rtrim(number_format($fuell / 1000, 3, ',', '.'), '0'), ',')) . ' g)' : '' ?></td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0"><?= h(lp_t('rez_zusammensetzung')) ?></h2>
  <?php if (!$zutaten): ?>
    <div class="muted"><?= h(lp_t('rez_preise_leer')) ?></div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th><?= h(lp_t('bestandteil')) ?></th><th class="bx-num"><?= h(lp_t('menge_je_einheit')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($zutaten as $z): ?>
      <tr><td><?= h((string)$z['name']) ?></td><td class="bx-num"><?= (float)$z['menge_mg'] > 0 ? h($mg($z['menge_mg'])) . ' mg' : '<span class="muted">–</span>' ?></td></tr>
      <?php endforeach; ?>
      <?php if ($fuell > 0): ?>
      <tr><td><strong><?= h(lp_t('fuellgewicht')) ?></strong></td><td class="bx-num"><strong><?= h($mg($fuell)) ?> mg</strong></td></tr>
      <?php endif; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php lp_shell_ende(); lp_foot();
