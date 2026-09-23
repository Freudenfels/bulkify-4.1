<?php
// Gemeinsamer Render-Include fuer den Produktionsbericht. Erwartet im Scope:
//   $D        = Ergebnis von produktion_bericht_daten($pa_id)
//   $fuerKunde = bool  (Kundenversion: OHNE Lieferanten, OHNE Mitarbeiternamen, OHNE Rohstoff-Chargen,
//                       OHNE Produktionsart – es darf nie ein Zukauf durchscheinen)
$pa = $D['pa']; $fertig = $D['fertig']; $done = $D['done']; $schritte = $D['schritte'];
$fuerKunde = !empty($fuerKunde);
$mng  = fn($x,$e) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',') . ' ' . h($e ?: '');
$zahl = fn($x,$d=0) => number_format((float)$x, $d, ',', '.');
$dt   = fn($s) => $s ? h(fmt_zeit($s)) . ' Uhr' : '–';
$mhd  = $D['fwChargen'][0]['mhd'] ?? null;
$einheitWort = ['kapsel'=>'Kapsel','softgel'=>'Kapsel','tablette'=>'Tablette','stick'=>'Stick'][$D['form']] ?? 'Einheit';
$grosMg = fn($mg) => $mg >= 1000000 ? $zahl($mg/1000000, 3) . ' kg' : ($mg >= 1000 ? $zahl($mg/1000, 3) . ' g' : $zahl($mg, 0) . ' mg');
// Kundenneutrale Schritt-Bezeichnungen (verraten keinen Zukauf: „Fertigware bereitstellen" -> „Material bereitgestellt").
$kundeStation = function(string $st): string {
    $m = ['Rohstoffe bereitstellen'=>'Material bereitgestellt','Fertigware bereitstellen'=>'Material bereitgestellt',
          'Mischen'=>'Mischen','Verkapselung'=>'Herstellung','Tablettierung'=>'Herstellung','Abfüllung'=>'Herstellung',
          'Verpacken'=>'Verpackt','Etikettieren'=>'Etikettiert','Qualitätsprüfung'=>'Qualitätsprüfung',
          'Einlagern (Bulk)'=>'Eingelagert','Produktions-Freigabe'=>'Produktionsfreigabe','Versand-Freigabe'=>'Versandfreigabe'];
    return $m[$st] ?? $st;
};
?>
<div class="bx-panel pb-sec">
  <h2>Auftrag</h2>
  <div class="bx-tablewrap"><table class="bx-table pb-kv"><tbody>
    <?php if (!$fuerKunde): ?><tr><td>Produktionsauftrag</td><td><strong><?= h($pa['nummer']) ?></strong></td></tr><?php endif; ?>
    <?php if ($pa['auftrag_nr']): ?><tr><td>Auftrag</td><td><?= h($pa['auftrag_nr']) ?></td></tr><?php endif; ?>
    <tr><td>Status</td><td><?= $fertig ? 'Abgeschlossen' : ($pa['status'] === 'laufend' ? 'In Produktion' : 'Offen') ?><?= $fuerKunde ? '' : ' · Fortschritt ' . $done . '/' . count($schritte) ?></td></tr>
    <?php if (!$fuerKunde && $pa['kunde_firma']): ?><tr><td>Kunde (intern)</td><td><?= h($pa['kunde_firma']) ?></td></tr><?php endif; ?>
    <?php if (!$fuerKunde): ?><tr><td>Produktionsart</td><td><?= ($pa['produktionsart'] ?? '') === 'fremd' ? 'Fremdfertigung / Zukauf' : 'Eigenfertigung' ?></td></tr><?php endif; ?>
    <tr><td>Angelegt</td><td><?= $dt($pa['angelegt']) ?></td></tr>
    <tr><td>Abgeschlossen</td><td><?= $D['abg'] ? $dt($D['abg']) : '<span class="muted">–</span>' ?></td></tr>
    <?php if ($fuerKunde && !empty($pa['bericht_freigegeben_am'])): ?><tr><td>Freigegeben am</td><td><?= h(date('d.m.Y', strtotime((string)$pa['bericht_freigegeben_am']))) ?></td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<div class="bx-panel pb-sec">
  <h2><?= (!$fuerKunde && $D['istBulk']) ? 'Rezeptur (Bulk)' : 'Produkt' ?></h2>
  <div class="bx-tablewrap"><table class="bx-table pb-kv"><tbody>
    <tr><td>Bezeichnung</td><td><strong><?= h($D['istBulk'] ? ($pa['rezeptur_name'] ?: '–') : ($pa['produkt_name'] ?: $pa['auftrag_produkt_bez'] ?: '–')) ?></strong><?= (!$fuerKunde && $D['istBulk']) ? ' <span class="muted">· Bulk</span>' : '' ?></td></tr>
    <tr><td>Darreichungsform</td><td><?= h($D['formLabel']) ?></td></tr>
    <?php if ($D['groesse'] !== ''): ?><tr><td>Kapsel/Tablette</td><td><?= h($D['groesse']) ?></td></tr><?php endif; ?>
    <tr><td>Charge<?= count($D['fwChargen']) > 1 ? 'n' : '' ?></td><td><?= $D['fwChargen'] ? h(implode(', ', array_column($D['fwChargen'], 'charge_nr'))) : '<span class="muted">noch nicht vergeben</span>' ?></td></tr>
    <?php if ($mhd): ?><tr><td>Mindesthaltbarkeit</td><td><?= h(date('d.m.Y', strtotime((string)$mhd))) ?></td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<div class="bx-panel pb-sec">
  <h2>Menge</h2>
  <div class="bx-tablewrap"><table class="bx-table pb-kv"><tbody>
    <tr><td>Packungen</td><td><?= $zahl($D['pack']) ?></td></tr>
    <?php if ($D['einh'] > 0): ?>
    <tr><td><?= h($D['wort']) ?> je Packung</td><td><?= $zahl($D['einh']) ?></td></tr>
    <tr><td><?= h($D['wort']) ?> gesamt</td><td><strong><?= $zahl($D['gesamt']) ?></strong></td></tr>
    <?php endif; ?>
  </tbody></table></div>
</div>

<?php if ($D['zutaten']): ?>
<div class="bx-panel pb-sec">
  <h2>Zusammensetzung je <?= h($einheitWort) ?></h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Bestandteil</th><th class="bx-num">mg je <?= h($einheitWort) ?></th><?php if ($D['gesamt'] > 0): ?><th class="bx-num">Gesamt</th><?php endif; ?></tr></thead>
    <tbody>
      <?php $summe = 0.0; foreach ($D['zutaten'] as $z): $summe += (float)$z['menge_mg']; ?>
      <tr><td><?= h((string)$z['name']) ?></td>
          <td class="bx-num"><?= (float)$z['menge_mg'] > 0 ? $mng($z['menge_mg'], 'mg') : '<span class="muted">–</span>' ?></td>
          <?php if ($D['gesamt'] > 0): ?><td class="bx-num"><?= (float)$z['menge_mg'] > 0 ? h($grosMg((float)$z['menge_mg'] * $D['gesamt'])) : '<span class="muted">–</span>' ?></td><?php endif; ?></tr>
      <?php endforeach; ?>
      <tr><td><strong>Füllgewicht</strong></td><td class="bx-num"><strong><?= $mng($summe, 'mg') ?></strong></td>
          <?php if ($D['gesamt'] > 0): ?><td class="bx-num"><strong><?= h($grosMg($summe * $D['gesamt'])) ?></strong></td><?php endif; ?></tr>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="bx-panel pb-sec">
  <h2><?= $fuerKunde ? 'Herstellung &amp; Prüfung' : 'Produktionsschritte' ?></h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>#</th><th>Schritt</th><th>Status</th><th>Datum</th><?php if (!$fuerKunde): ?><th>Bearbeiter</th><th>Charge (Scan)</th><?php endif; ?></tr></thead>
    <tbody>
      <?php foreach ($schritte as $i => $s): $d = (int)$s['erledigt'] === 1; ?>
      <tr>
        <td class="muted"><?= $i + 1 ?></td>
        <td><?= h($fuerKunde ? $kundeStation((string)$s['station']) : (string)$s['station']) ?></td>
        <td><?= $d ? bx_badge('erledigt', 'ok') : bx_badge('ausstehend', 'info') ?></td>
        <td class="muted"><?= $d && $s['erledigt_at'] ? $dt($s['erledigt_at']) : '–' ?></td>
        <?php if (!$fuerKunde): ?>
          <td><?= $d && !empty($s['erledigt_von']) ? h((string)$s['erledigt_von']) : '<span class="muted">–</span>' ?></td>
          <td><?= !empty($s['scan_charge']) ? h((string)$s['scan_charge']) : '<span class="muted">–</span>' ?></td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php if (!$fuerKunde && $D['verbrauch']): ?>
<div class="bx-panel pb-sec">
  <h2>Entnommene Materialien (chargengenau, FEFO)</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Material</th><th>Charge</th><th>Lieferant</th><th class="bx-num">Entnommen</th><th>Datum</th></tr></thead>
    <tbody>
      <?php foreach ($D['verbrauch'] as $vb): ?>
      <tr><td><?= h($vb['item_name'] ?: '–') ?></td><td><?= h($vb['charge_nr'] ?: '–') ?></td>
          <td class="muted"><?= h($vb['lieferant'] ?: '–') ?></td>
          <td class="bx-num"><?= $mng($vb['menge'], $vb['einheit'] ?? '') ?></td>
          <td class="muted"><?= $vb['angelegt'] ? h(date('d.m.Y', strtotime((string)$vb['angelegt']))) : '–' ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if (!$fuerKunde && $D['zugeChargen']): $katLbl = ['rohstoff'=>'Rohstoff','verpackung'=>'Verpackung','fertig'=>'Fertigware (Bulk)','verkaufsfertig'=>'Fertigware','kapselhuelle'=>'Leerkapsel']; ?>
<div class="bx-panel pb-sec">
  <h2>Zugeordnete Chargen</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Charge</th><th>Artikel</th><th>Art</th><th>Lieferant</th><th>MHD</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($D['zugeChargen'] as $c): ?>
      <tr><td><?= h($c['charge_nr'] ?: '–') ?></td><td><?= h($c['item_name'] ?: '–') ?></td>
          <td class="muted"><?= h($katLbl[$c['kategorie']] ?? (string)$c['kategorie']) ?></td>
          <td class="muted"><?= h($c['lieferant'] ?: '–') ?></td>
          <td><?= $c['mhd'] ? h(date('d.m.Y', strtotime((string)$c['mhd']))) : '–' ?></td><td><?= h((string)$c['status']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($D['fwChargen']): ?>
<div class="bx-panel pb-sec">
  <h2><?= $fuerKunde ? 'Ihre Charge' : 'Eingebuchte Fertigware' ?></h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Charge</th><?php if (!$fuerKunde): ?><th>Artikel</th><?php endif; ?><th class="bx-num">Menge</th><?php if (!$fuerKunde): ?><th class="bx-num">verfügbar</th><?php endif; ?><th>MHD</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($D['fwChargen'] as $c): ?>
      <tr><td><strong><?= h($c['charge_nr'] ?: '–') ?></strong></td>
          <?php if (!$fuerKunde): ?><td class="muted"><?= h(($c['artikelnummer'] ? $c['artikelnummer'] . ' · ' : '') . $c['name']) ?></td><?php endif; ?>
          <td class="bx-num"><?= $zahl($c['menge']) ?></td>
          <?php if (!$fuerKunde): ?><td class="bx-num"><?= $zahl($c['menge_verfuegbar']) ?></td><?php endif; ?>
          <td><?= $c['mhd'] ? h(date('d.m.Y', strtotime((string)$c['mhd']))) : '–' ?></td><td><?= $fuerKunde ? 'freigegeben' : h((string)$c['status']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if (trim((string)($pa['bericht_notiz'] ?? '')) !== ''): ?>
<div class="bx-panel pb-sec">
  <h2>Bemerkung</h2>
  <div style="white-space:pre-line"><?= h((string)$pa['bericht_notiz']) ?></div>
</div>
<?php endif; ?>
