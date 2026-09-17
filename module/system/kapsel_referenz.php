<?php
// Nachschlagewerk: Kapselgrößen-Referenz (Füllgewichte je Dichte, Volumen, Maße, Leergewicht).
// Reine Info-/Lookup-Seite. Datenquelle: kapsel_referenz_tabelle() (eine Wahrheit, auch fürs Seeding).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$ref = kapsel_referenz_tabelle();
$nf  = fn($v, $d = 2) => rtrim(rtrim(number_format((float)$v, $d, ',', '.'), '0'), ',');

render_header('kapsel_referenz', 'Kapselgrößen');
bx_head('Kapselgrößen – Nachschlagewerk',
        'Füllgewichte je Pulverdichte, Volumen, Maße und Leergewicht der Kapselhülle',
        bx_btn('Zurück', '?p=einstellungen&tab=produktion', 'ghost'));
?>
<div class="bx-panel">
  <p class="muted" style="margin-top:0">Richtwerte (Standard-Hartkapseln). Das Füllgewicht hängt von der <strong>Schüttdichte</strong> des Pulvers ab
    (0,45 leicht · 0,70 typisch · 1,00 dicht). Für die automatische Kapselgrößen-Wahl nutzt das System die Dichte der Rohstoffe;
    fehlt sie, gilt der hinterlegte Backup-Wert je Größe.</p>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead>
      <tr>
        <th>Kapselgröße</th>
        <th class="bx-num">0,45 (leicht)</th>
        <th class="bx-num">0,70 (typisch)</th>
        <th class="bx-num">1,00 (dicht)</th>
        <th class="bx-num">Volumen (ml)</th>
        <th class="bx-num">Verschlusslänge (mm)</th>
        <th class="bx-num">Ø Kappe / Körper (mm)</th>
        <th class="bx-num">Leergewicht (mg)</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($ref as $size => $r): ?>
      <tr>
        <td><strong><?= h($size) ?></strong></td>
        <td class="bx-num"><?= $nf($r['fill'][0], 0) ?> mg</td>
        <td class="bx-num"><?= $nf($r['fill'][1], 0) ?> mg</td>
        <td class="bx-num"><?= $nf($r['fill'][2], 0) ?> mg</td>
        <td class="bx-num"><?= $nf($r['vol']) ?></td>
        <td class="bx-num"><?= $nf($r['lock']) ?></td>
        <td class="bx-num"><?= $nf($r['cap'][0]) ?> / <?= $nf($r['body'][0]) ?></td>
        <td class="bx-num"><?= $nf($r['leer'], 0) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px;margin-bottom:0">Füllgewicht = Volumen × Dichte × 1000. Leergewicht = Ø aus 100 Kapseln (±10 %). Maße mit fertigungsbedingter Toleranz.</p>
</div>
<?php render_footer(); ?>
