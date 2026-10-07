<?php
// Worklist „Rezepturen überarbeiten": importierte Rezepturen, deren Zutaten NICHT sauber auf die echten
// Lager-Rohstoffe gematcht sind (Freitext, Verweis ins Leere, oder Rohstoff ohne Wirkstoffdaten). Genau
// diese liefern leere Nährwerte und keine automatische CoA/Spec-Verknüpfung. Von hier aus springt man per
// „Überarbeiten" direkt in die jeweilige Rezeptur (entsperrt, matcht, speichert → wieder gesichert).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$DFORM = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig'];
$rows  = rezepturen_zu_ueberarbeiten();

bx_head('Rezepturen überarbeiten', count($rows) . ' Rezeptur(en) mit nicht gematchten Rohstoffen',
        bx_btn('Alle Rezepturen', '?p=rezeptur'));
?>
<div class="bx-panel">
  <p class="bx-sub" style="margin-top:0">
    Diese Rezepturen haben Zutaten, die (noch) nicht sauber auf die echten Lager-Rohstoffe zeigen – deshalb
    bleiben Nährwerte leer und es hängen keine Spec/CoA automatisch dran. Pro Rezeptur auf <strong>Überarbeiten</strong>:
    du ordnest die Zutaten den richtigen Rohstoffen zu und speicherst. Die Rezeptur wird dabei nur temporär
    entsperrt und danach automatisch wieder gesichert (Status bleibt, Nährwerte werden neu festgeschrieben).
  </p>
</div>

<?php if (!$rows): ?>
  <div class="bx-panel badge-ok" style="padding:14px 16px">Alles sauber – keine Rezeptur mit nicht gematchten Rohstoffen.</div>
<?php else: ?>
<div class="bx-panel">
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr>
      <th>Rezeptur</th><th>Kunde</th><th>Form</th><th>Status</th>
      <th class="bx-num">Zutaten</th><th>Problem</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $frei = (int)$r['frei']; $tot = (int)$r['tot']; $ohne = (int)$r['ohne_wirkstoff'];
      $probleme = [];
      if ($frei > 0) $probleme[] = $frei . '× nicht zugeordnet';
      if ($tot  > 0) $probleme[] = $tot . '× Rohstoff fehlt';
      if ($ohne > 0) $probleme[] = $ohne . '× ohne Wirkstoffdaten';
    ?>
      <tr>
        <td><a href="?p=rezeptur_detail&id=<?= (int)$r['id'] ?>"><strong><?= h((string)$r['name']) ?></strong></a>
            <div class="muted" style="font-size:12px"><?= h((string)($r['nummer'] ?? '')) ?></div></td>
        <td><?= $r['kunde'] ? h((string)$r['kunde']) : '<span class="muted">Hausrezeptur</span>' ?></td>
        <td><?= h($DFORM[(string)$r['darreichungsform']] ?? (string)$r['darreichungsform']) ?></td>
        <td><?= match((string)$r['status']){'freigegeben'=>bx_badge('freigegeben','ok'),'eingefroren'=>bx_badge('eingefroren','warn'),'vorschlag'=>bx_badge('Vorschlag','info'),'entwurf'=>bx_badge('Entwurf'),default=>bx_badge((string)$r['status'])} ?>
            <?php if ((int)$r['naehrwerte_fixiert']): ?><span class="muted" style="font-size:11px">· NW fix</span><?php endif; ?></td>
        <td class="bx-num"><?= (int)$r['zutaten'] ?></td>
        <td><span style="color:var(--warn);font-size:13px"><?= h(implode(' · ', $probleme)) ?></span></td>
        <td style="text-align:right">
          <?php if (has_role('admin') && in_array((string)$r['status'], ['freigegeben','eingefroren'], true)): ?>
            <form method="post" action="?p=rezeptur_detail&id=<?= (int)$r['id'] ?>" style="display:inline">
              <input type="hidden" name="aktion" value="ueberarbeiten_start">
              <button class="btn btn-primary btn-sm" type="submit">Überarbeiten</button>
            </form>
          <?php else: ?>
            <a class="btn btn-ghost btn-sm" href="?p=rezeptur_detail&id=<?= (int)$r['id'] ?>#zutaten">Bearbeiten</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
