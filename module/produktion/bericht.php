<?php
// Produktionsbericht (Herstellprotokoll) – druckbare Gesamtuebersicht eines Produktionsauftrags.
// Buendelt alles an einem Ort: Kopf, Produkt/Rezeptur, Menge, Zusammensetzung, Schritte (mit Zeit +
// wer + gescannter Charge), entnommene Materialien (chargengenau), eingebuchte Fertigware.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$id = (int)($_GET['id'] ?? 0);
$pa = $id ? one("SELECT pa.*, k.firma AS kunde_firma, p.name AS produkt_name, a.nummer AS auftrag_nr,
                        a.produkt_bezeichnung AS auftrag_produkt_bez, a.produkt_form AS auftrag_produkt_form,
                        rz.name AS rezeptur_name, rz.darreichungsform AS rezeptur_form
                 FROM produktionsauftrag pa
                 LEFT JOIN kunden k ON k.id=pa.kunde_id LEFT JOIN produkt p ON p.id=pa.produkt_id
                 LEFT JOIN rezeptur rz ON rz.id=pa.rezeptur_id
                 LEFT JOIN auftrag a ON a.id=pa.auftrag_id WHERE pa.id=?", [$id]) : null;
if (!$pa) { render_header('produktion','Produktionsbericht'); bx_head('Produktionsauftrag nicht gefunden','', bx_btn('Zurück','?p=produktion','ghost')); render_footer(); exit; }

$istBulk = function_exists('pa_ist_bulk') ? pa_ist_bulk($pa) : (empty($pa['produkt_id']) && !empty($pa['rezeptur_id']));
$form    = (string)($pa['rezeptur_form'] ?: $pa['auftrag_produkt_form'] ?: '');
$formWort = fn(string $f) => in_array($f, ['kapsel','softgel'], true) ? 'Kapseln' : ($f === 'tablette' ? 'Tabletten' : ($f === 'stick' ? 'Sticks' : 'Stück'));
$wort = $formWort($form);
$formLabel = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig','granulat'=>'Granulat'][$form] ?? ($form ?: '–');

$einh   = produktion_stueck_je_packung($pa);        // Einheiten je Packung
$pack   = (int)$pa['menge'];                          // Packungen
$gesamt = $einh > 0 ? $pack * $einh : 0;             // Gesamtstückzahl

$rezId = (int)($pa['rezeptur_id'] ?? 0);
if (!$rezId && !empty($pa['produkt_id'])) $rezId = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]);
$zutaten = $rezId ? all("SELECT z.menge_mg, COALESCE(NULLIF(z.bezeichnung,''), i.name) AS name
                         FROM rezeptur_zutat z LEFT JOIN item i ON i.id=z.item_id
                         WHERE z.rezeptur_id=? ORDER BY z.sort, z.id", [$rezId]) : [];

$schritte  = all("SELECT * FROM produktion_schritt WHERE pa_id=? ORDER BY sort, id", [$id]);
$verbrauch = all("SELECT v.*, c.charge_nr, i.name AS item_name FROM produktion_verbrauch v
                  LEFT JOIN charge c ON c.id=v.charge_id LEFT JOIN item i ON i.id=v.item_id
                  WHERE v.pa_id=? ORDER BY v.id", [$id]);
$fwChargen = all("SELECT c.charge_nr, c.menge, c.menge_verfuegbar, c.mhd, c.status, i.artikelnummer, i.name
                  FROM charge c JOIN item i ON i.id=c.item_id WHERE c.pa_id=? ORDER BY c.id", [$id]);
$groesse = !empty($pa['produkt_id']) ? produktion_groesse_label((int)$pa['produkt_id']) : '';

// Abschlussdatum = spaetester erledigter Schritt.
$abg = null; foreach ($schritte as $s) if ((int)$s['erledigt'] === 1 && $s['erledigt_at'] && (!$abg || $s['erledigt_at'] > $abg)) $abg = $s['erledigt_at'];
$done = count(array_filter($schritte, fn($s) => (int)$s['erledigt'] === 1));
$fertig = $pa['status'] === 'erledigt';

$mng = fn($x,$e) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',') . ' ' . h($e ?: '');
$zahl = fn($x,$d=0) => number_format((float)$x, $d, ',', '.');
$dt   = fn($s) => $s ? h(fmt_zeit($s)) . ' Uhr' : '–';

render_header('produktion', 'Bericht ' . $pa['nummer']);
?>
<style>
@media print {
  .bx-side, .bx-mobilbar, .bx-sideauf, .bx-sidegriff, .bx-head-actions, .no-print { display:none !important; }
  .bx-shell, .bx-main { display:block !important; margin:0 !important; }
  .bx-panel { break-inside:avoid; box-shadow:none; }
  body { background:#fff; }
}
.pb-sec h2 { margin:0 0 8px; font-size:15px }
.pb-kv td:first-child { color:var(--muted); width:220px; white-space:nowrap }
</style>

<div class="bx-row no-print" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
  <div>
    <h1 style="margin:0 0 2px">Produktionsbericht <?= h($pa['nummer']) ?></h1>
    <div class="muted" style="font-size:13px"><?= $fertig ? 'Abgeschlossen' : 'Fortschritt ' . $done . '/' . count($schritte) . ' – noch nicht abgeschlossen' ?></div>
  </div>
  <div class="bx-row" style="gap:8px">
    <button type="button" class="btn btn-primary" onclick="window.print()">Drucken / PDF</button>
    <a class="btn btn-ghost" href="?p=produktionsauftrag&id=<?= $id ?>">Zurück zum Auftrag</a>
  </div>
</div>

<?php if (!$fertig): ?>
<div class="bx-panel no-print" style="border-color:#e6c4c0;padding:10px 14px;font-size:13px">Hinweis: Der Auftrag ist noch <strong>nicht abgeschlossen</strong> – der Bericht zeigt den aktuellen Zwischenstand.</div>
<?php endif; ?>

<div class="bx-panel pb-sec">
  <h2>Auftrag</h2>
  <div class="bx-tablewrap"><table class="bx-table pb-kv"><tbody>
    <tr><td>Produktionsauftrag</td><td><strong><?= h($pa['nummer']) ?></strong></td></tr>
    <tr><td>Status</td><td><?= $fertig ? 'Abgeschlossen' : ($pa['status'] === 'laufend' ? 'In Produktion' : 'Offen') ?> · Fortschritt <?= $done ?>/<?= count($schritte) ?></td></tr>
    <?php if ($pa['auftrag_nr']): ?><tr><td>Kundenauftrag</td><td><?= h($pa['auftrag_nr']) ?></td></tr><?php endif; ?>
    <?php if ($pa['kunde_firma']): ?><tr><td>Kunde (intern)</td><td><?= h($pa['kunde_firma']) ?></td></tr><?php endif; ?>
    <tr><td>Produktionsart</td><td><?= ($pa['produktionsart'] ?? '') === 'fremd' ? 'Fremdfertigung / Zukauf' : 'Eigenfertigung' ?></td></tr>
    <tr><td>Angelegt</td><td><?= $dt($pa['angelegt']) ?></td></tr>
    <?php if ($pa['geplant_am']): ?><tr><td>Geplant am</td><td><?= h(date('d.m.Y', strtotime((string)$pa['geplant_am']))) ?></td></tr><?php endif; ?>
    <tr><td>Abgeschlossen</td><td><?= $abg ? $dt($abg) : '<span class="muted">–</span>' ?></td></tr>
  </tbody></table></div>
</div>

<div class="bx-panel pb-sec">
  <h2><?= $istBulk ? 'Rezeptur (Bulk)' : 'Produkt' ?></h2>
  <div class="bx-tablewrap"><table class="bx-table pb-kv"><tbody>
    <tr><td>Bezeichnung</td><td><strong><?= h($istBulk ? ($pa['rezeptur_name'] ?: '–') : ($pa['produkt_name'] ?: $pa['auftrag_produkt_bez'] ?: '–')) ?></strong><?= $istBulk ? ' <span class="muted">· Bulk</span>' : '' ?></td></tr>
    <tr><td>Darreichungsform</td><td><?= h($formLabel) ?></td></tr>
    <?php if ($groesse !== ''): ?><tr><td>Kapsel/Tablette</td><td><?= h($groesse) ?></td></tr><?php endif; ?>
    <tr><td>Charge<?= count($fwChargen) > 1 ? 'n' : '' ?></td><td><?= $fwChargen ? h(implode(', ', array_column($fwChargen, 'charge_nr'))) : '<span class="muted">noch nicht gebucht</span>' ?></td></tr>
    <?php $mhd = $fwChargen[0]['mhd'] ?? null; if ($mhd): ?><tr><td>MHD</td><td><?= h(date('d.m.Y', strtotime((string)$mhd))) ?></td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<div class="bx-panel pb-sec">
  <h2>Menge</h2>
  <div class="bx-tablewrap"><table class="bx-table pb-kv"><tbody>
    <tr><td>Packungen</td><td><?= $zahl($pack) ?></td></tr>
    <?php if ($einh > 0): ?>
    <tr><td><?= h($wort) ?> je Packung</td><td><?= $zahl($einh) ?></td></tr>
    <tr><td><?= h($wort) ?> gesamt</td><td><strong><?= $zahl($gesamt) ?></strong></td></tr>
    <?php endif; ?>
  </tbody></table></div>
</div>

<?php if ($zutaten): ?>
<div class="bx-panel pb-sec">
  <h2>Zusammensetzung je <?= h(rtrim($wort, 'n') ?: 'Einheit') ?></h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Bestandteil</th><th class="bx-num">mg je <?= h(rtrim($wort, 'n') ?: 'Einheit') ?></th><th class="bx-num">Soll gesamt</th></tr></thead>
    <tbody>
      <?php $summe = 0.0; foreach ($zutaten as $z): $summe += (float)$z['menge_mg']; $totMg = (float)$z['menge_mg'] * $gesamt; ?>
      <tr><td><?= h((string)$z['name']) ?></td>
          <td class="bx-num"><?= (float)$z['menge_mg'] > 0 ? $mng($z['menge_mg'], 'mg') : '<span class="muted">–</span>' ?></td>
          <td class="bx-num"><?= $gesamt > 0 && (float)$z['menge_mg'] > 0 ? ($totMg >= 1000000 ? $zahl($totMg/1000000, 3) . ' kg' : ($totMg >= 1000 ? $zahl($totMg/1000, 3) . ' g' : $zahl($totMg, 0) . ' mg')) : '<span class="muted">–</span>' ?></td></tr>
      <?php endforeach; ?>
      <tr><td><strong>Füllgewicht</strong></td><td class="bx-num"><strong><?= $mng($summe, 'mg') ?></strong></td>
          <td class="bx-num"><strong><?= $gesamt > 0 ? ($summe*$gesamt >= 1000000 ? $zahl($summe*$gesamt/1000000, 3) . ' kg' : $zahl($summe*$gesamt/1000, 3) . ' g') : '–' ?></strong></td></tr>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="bx-panel pb-sec">
  <h2>Produktionsschritte</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>#</th><th>Station</th><th>Status</th><th>Zeitpunkt</th><th>Bearbeiter</th><th>Charge (Scan)</th></tr></thead>
    <tbody>
      <?php foreach ($schritte as $i => $s): $d = (int)$s['erledigt'] === 1; ?>
      <tr>
        <td class="muted"><?= $i + 1 ?></td>
        <td><?= h((string)$s['station']) ?></td>
        <td><?= $d ? bx_badge('erledigt', 'ok') : bx_badge('offen', 'info') ?></td>
        <td class="muted"><?= $d && $s['erledigt_at'] ? $dt($s['erledigt_at']) : '–' ?></td>
        <td><?= $d && !empty($s['erledigt_von']) ? h((string)$s['erledigt_von']) : '<span class="muted">–</span>' ?></td>
        <td><?= !empty($s['scan_charge']) ? h((string)$s['scan_charge']) : '<span class="muted">–</span>' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php if ($verbrauch): ?>
<div class="bx-panel pb-sec">
  <h2>Entnommene Materialien (chargengenau, FEFO)</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Material</th><th>Charge</th><th class="bx-num">Entnommen</th></tr></thead>
    <tbody>
      <?php foreach ($verbrauch as $vb): ?>
      <tr><td><?= h($vb['item_name'] ?: '–') ?></td><td><?= h($vb['charge_nr'] ?: '–') ?></td><td class="bx-num"><?= $mng($vb['menge'], $vb['einheit'] ?? '') ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($fwChargen): ?>
<div class="bx-panel pb-sec">
  <h2>Eingebuchte Fertigware</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Charge</th><th>Artikel</th><th class="bx-num">Menge</th><th class="bx-num">verfügbar</th><th>MHD</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($fwChargen as $c): ?>
      <tr><td><strong><?= h($c['charge_nr'] ?: '–') ?></strong></td><td class="muted"><?= h(($c['artikelnummer'] ? $c['artikelnummer'] . ' · ' : '') . $c['name']) ?></td>
          <td class="bx-num"><?= $zahl($c['menge']) ?></td><td class="bx-num"><?= $zahl($c['menge_verfuegbar']) ?></td>
          <td><?= $c['mhd'] ? h(date('d.m.Y', strtotime((string)$c['mhd']))) : '–' ?></td><td><?= h((string)$c['status']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<p class="muted" style="font-size:12px;margin-top:10px">Erstellt am <?= h(fmt_zeit(gmdate('Y-m-d H:i:s'))) ?> Uhr<?= (function_exists('current_user') && ($cu = current_user())) ? ' von ' . h((string)($cu['name'] ?? '')) : '' ?> · bulkify Produktionsbericht</p>

<?php render_footer(); ?>
