<?php
// Detail eines Produktionsauftrags (nur lesend): Kopf + Schritte. Das Abschließen von Schritten
// (inkl. Chargen-Entnahme über die Naht) baut der Produktions-Chat als Nächstes.
$id = (int)($_GET['id'] ?? 0);
$pa = erp_pa($id);
if (!$pa) { kopf('Produktionsauftrag'); seitenkopf('Nicht gefunden'); echo '<div class="bx-panel"><a class="btn btn-ghost" href="?p=liste">Zurück</a></div>'; fuss(); return; }
$schritte = erp_pa_schritte($id);

kopf($pa['nummer'] . ' – Produktion', 'liste');
seitenkopf((string)$pa['nummer'], (string)($pa['produkt_name'] ?? ''), '<a class="btn btn-ghost btn-sm" href="?p=liste">Zurück zur Liste</a>');
?>
<div class="bx-cards" style="margin-bottom:16px">
  <div class="bx-panel" style="margin:0"><div class="muted">Status</div><div style="margin-top:6px"><?= pa_badge((string)$pa['status']) ?></div></div>
  <div class="bx-panel" style="margin:0"><div class="muted">Menge</div><div style="margin-top:6px"><?= menge_txt($pa['menge']) ?><?php if (!empty($pa['stueck'])): ?> · <?= (int)$pa['stueck'] ?>/Pkg.<?php endif; ?></div></div>
  <div class="bx-panel" style="margin:0"><div class="muted">Kunde</div><div style="margin-top:6px"><?= h((string)($pa['kunde'] ?: '–')) ?></div></div>
  <div class="bx-panel" style="margin:0"><div class="muted">Herstellung</div><div style="margin-top:6px"><?= h((string)$pa['produktionsart'] === 'eigen' ? 'Eigenproduktion' : 'Fremdproduktion') ?></div></div>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Schritte</h2>
  <?php if (!$schritte): ?>
    <div class="muted">Keine Schritte hinterlegt.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th class="bx-num">#</th><th>Station</th><th>Status</th><th>Erledigt von</th></tr></thead>
    <tbody>
      <?php foreach ($schritte as $i => $s): ?>
      <tr>
        <td class="bx-num"><?= (int)$i + 1 ?></td>
        <td><?= h((string)$s['station']) ?></td>
        <td><?= (int)($s['erledigt'] ?? 0) === 1 ? '<span class="badge badge-ok">erledigt</span>' : '<span class="badge badge-warn">offen</span>' ?></td>
        <td class="muted"><?= h((string)($s['erledigt_von'] ?? '')) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <p class="muted" style="font-size:12px;margin-top:12px">Hinweis: Diese Seite ist der Start des eigenständigen Produktions-Programms. Das Abschließen von Schritten (mit Chargen-Entnahme) wird über die Naht <code>produktion/core/erp.php</code> ergänzt.</p>
</div>
<?php fuss();
