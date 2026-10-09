<?php
// Produktionscharge (CH/CHE) – Detail mit Unterchargen + voller Rohstoff-Rückverfolgung (Spec 7.5 + 16).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$id = (int)($_GET['id'] ?? 0);
$pc = $id ? prod_charge_voll($id) : null;
if (!$pc) { render_header('produktionschargen', 'Charge'); bx_head('Charge', 'Nicht gefunden.', bx_btn('Zurück', '?p=produktionschargen', 'ghost')); render_footer(); return; }

$mg = fn($x) => $x === null || $x === '' ? '–' : rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
$pa = $pc['pa_id'] ? one("SELECT id, nummer FROM produktionsauftrag WHERE id=?", [(int)$pc['pa_id']]) : null;
$rez = $pc['rezeptur_id'] ? one("SELECT id, nummer, name FROM rezeptur WHERE id=?", [(int)$pc['rezeptur_id']]) : null;
$prod = $pc['produkt_id'] ? one("SELECT id, name FROM produkt WHERE id=?", [(int)$pc['produkt_id']]) : null;
// Mitarbeiter/Maschine best-effort (Maschine liegt in der Produktions-Sub-App; Lesezugriff tolerant).
$mitarbeiter = $pc['mitarbeiter_id'] ? (string) scalar("SELECT name FROM mitarbeiter WHERE id=?", [(int)$pc['mitarbeiter_id']]) : '';
$maschine = '';
if (!empty($pc['maschine_id'])) { try { $maschine = (string) scalar("SELECT name FROM pr_maschine WHERE id=?", [(int)$pc['maschine_id']]); } catch (\Throwable $e) {} }

render_header('produktionschargen', 'Charge ' . $pc['nummer']);
bx_head('Charge ' . h((string)$pc['nummer']), ((string)$pc['typ'] === 'extern' ? 'CHE – extern zugekaufte Bulkware' : 'CH – intern gemischt/produziert'), bx_btn('Zurück zur Liste', '?p=produktionschargen', 'ghost'));
?>
<div class="bx-panel">
  <h2 style="margin-top:0">Kopfdaten</h2>
  <div class="bx-tablewrap"><table class="bx-table"><tbody>
    <tr><td style="width:220px" class="muted">Charge-Nr.</td><td><strong><?= h((string)$pc['nummer']) ?></strong> <?= (string)$pc['typ'] === 'extern' ? bx_badge('extern', 'warn') : bx_badge('intern', 'info') ?></td></tr>
    <tr><td class="muted">Status</td><td><?= bx_badge((string)($pc['status'] ?: 'offen'), ($pc['status'] ?? '') === 'fertig' ? 'ok' : 'info') ?></td></tr>
    <?php if ($pa): ?><tr><td class="muted">Produktionsauftrag</td><td><a class="kundenlink" href="?p=produktionsauftrag&id=<?= (int)$pa['id'] ?>"><?= h((string)$pa['nummer']) ?></a></td></tr><?php endif; ?>
    <?php if ($rez): ?><tr><td class="muted">Rezeptur</td><td><a class="kundenlink" href="?p=rezeptur_detail&id=<?= (int)$rez['id'] ?>"><?= h($rez['nummer'] . ' · ' . $rez['name']) ?></a></td></tr><?php endif; ?>
    <?php if ($prod): ?><tr><td class="muted">Produkt</td><td><a class="kundenlink" href="?p=produkt&id=<?= (int)$prod['id'] ?>"><?= h((string)$prod['name']) ?></a></td></tr><?php endif; ?>
    <tr><td class="muted">Menge</td><td><?= $mg($pc['menge']) ?> <?= h((string)($pc['einheit'] ?? '')) ?><?= $pc['gebinde'] ? ' <span class="muted">· ' . h((string)$pc['gebinde']) . '</span>' : '' ?></td></tr>
    <?php if ($mitarbeiter !== ''): ?><tr><td class="muted">Mitarbeiter</td><td><?= h($mitarbeiter) ?></td></tr><?php endif; ?>
    <?php if ($maschine !== ''): ?><tr><td class="muted">Maschine</td><td><?= h($maschine) ?></td></tr><?php endif; ?>
    <tr><td class="muted">Produktionstag</td><td><?= $pc['tag'] ? h(date('d.m.Y', strtotime((string)$pc['tag']))) : '–' ?></td></tr>
    <tr><td class="muted">Angelegt</td><td><?= $pc['angelegt'] ? h(fmt_zeit((string)$pc['angelegt'], 'd.m.Y H:i')) : '–' ?></td></tr>
    <?php if (trim((string)($pc['notiz'] ?? '')) !== ''): ?><tr><td class="muted">Notiz</td><td style="white-space:pre-line"><?= h((string)$pc['notiz']) ?></td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Unterchargen <span class="muted" style="font-weight:normal">(<?= count($pc['unterchargen']) ?>)</span></h2>
  <p class="muted" style="margin-top:0;font-size:13px">Je Gebinde / Tag / Mitarbeiter eine Untercharge (-A/-B/-C). So bleibt jede geschlossene Einheit einzeln rückverfolgbar.</p>
  <?php if (!$pc['unterchargen']): ?><div class="muted">Noch keine Unterchargen.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Charge-Nr.</th><th>Gebinde</th><th class="bx-num">Menge</th><th>Status</th><th>Tag</th></tr></thead>
    <tbody>
      <?php foreach ($pc['unterchargen'] as $s): ?>
      <tr>
        <td><a class="kundenlink" href="?p=produktionscharge&id=<?= (int)$s['id'] ?>"><?= h((string)$s['nummer']) ?></a></td>
        <td><?= h((string)($s['gebinde'] ?? '')) ?: '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= $mg($s['menge']) ?> <?= h((string)($s['einheit'] ?? '')) ?></td>
        <td><?= bx_badge((string)($s['status'] ?: 'offen'), ($s['status'] ?? '') === 'fertig' ? 'ok' : 'info') ?></td>
        <td><?= $s['tag'] ? h(date('d.m.Y', strtotime((string)$s['tag']))) : '<span class="muted">–</span>' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Eingesetzte Rohstoffe / Batches <span class="muted" style="font-weight:normal">(<?= count($pc['rohstoffe']) ?>)</span></h2>
  <p class="muted" style="margin-top:0;font-size:13px">Rückverfolgung rückwärts: jede eingesetzte Rohstoff-Charge (Hersteller-Batchnummer). Klick auf einen Batch zeigt alle Chargen, in die er geflossen ist (vorwärts).</p>
  <?php if (!$pc['rohstoffe']): ?><div class="muted">Noch keine Rohstoffe verknüpft (entsteht beim Mischen in der Produktion).</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Rohstoff</th><th>Batch-Nr. (Hersteller)</th><th class="bx-num">Menge</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($pc['rohstoffe'] as $r): $bn = trim((string)($r['batch_nr'] ?? '')); ?>
      <tr>
        <td><?php if (!empty($r['item_id'])): ?><a class="kundenlink" href="?p=rohstoff&id=<?= (int)$r['item_id'] ?>"><?= h((string)($r['item_name'] ?: '–')) ?></a><?php else: ?><?= h((string)($r['item_name'] ?: '–')) ?><?php endif; ?><?= $r['artikelnummer'] ? ' <span class="muted" style="font-size:12px">· ' . h((string)$r['artikelnummer']) . '</span>' : '' ?></td>
        <td><?= $bn !== '' ? h($bn) : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= $mg($r['menge']) ?> <?= h((string)($r['einheit'] ?? '')) ?></td>
        <td><?php if ($bn !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=produktionschargen&q=<?= urlencode($bn) ?>">Vorwärts verfolgen</a><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php render_footer();
