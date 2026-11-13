<?php
// Ware in Quarantäne freigeben: ALLE Chargen, die noch auf die Freigabe warten (optional nur ein Artikel: ?item=ID).
// Ziel der Links „freigeben" aus Auftrag, Einkaufsliste und Werk-App. Freigeben setzt die Charge auf „frei" (erp_charge_status_setzen)
// – ab dann zählt die Ware als verfügbar (Bedarf/Einkauf/Produktion).
$itemF = (int)($_GET['item'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'freigeben') {
    $cid = (int)($_POST['charge_id'] ?? 0);
    $r = lg_darf_qm_freigeben() ? erp_charge_status_setzen($cid, 'frei')
       : ['ok' => false, 'meldung' => 'Freigeben darf nur QM oder Admin (Vier-Augen-Prinzip).'];   // QM-Gate (St-11)
    flash($r['ok'] ? 'Charge freigegeben.' : $r['meldung'], $r['ok'] ? 'ok' : 'warn');
    weiter('?p=quarantaene' . ((int)($_POST['item'] ?? 0) > 0 ? '&item=' . (int)$_POST['item'] : ''));
}

$zeilen = erp_quarantaene_chargen($itemF);
kopf('Quarantäne', 'quarantaene');
seitenkopf('Ware in Quarantäne', 'Eingegangen oder produziert, aber noch nicht freigegeben – erst nach der Prüfung durch QM nutzbar.',
    $itemF > 0 ? '<a class="btn btn-ghost" href="?p=quarantaene">Alle Artikel</a>' : '');
flash_zeigen();
if (!$zeilen) {
    hinweis('Keine Ware in Quarantäne.', 'ok');
    fuss();
    return;
}
?>
<div class="bx-tablewrap"><table class="bx-table lg-karten">
  <thead><tr><th>Artikel</th><th>Charge</th><th>Lieferant / Herkunft</th><th>Eingang</th><th>MHD</th><th>Menge</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($zeilen as $z): ?>
    <tr>
      <td data-label="Artikel"><?= h($z['item_name']) ?></td>
      <td data-label="Charge"><?= $z['charge_nr'] !== null && $z['charge_nr'] !== '' ? h($z['charge_nr']) : '<span class="muted">–</span>' ?><?php $zG = erp_unterlagen_grund((int)$z['id']); if ($zG !== ''): ?><div style="font-size:12px;color:var(--err)"><?= h($zG) ?></div><?php endif; ?></td>
      <td data-label="Lieferant"><?= (string)$z['lieferant'] !== '' ? h($z['lieferant']) : '<span class="muted">–</span>' ?></td>
      <td data-label="Eingang"><?= $z['wareneingang'] ? h(date('d.m.Y', strtotime((string)$z['wareneingang']))) : '<span class="muted">–</span>' ?></td>
      <td data-label="MHD"><?= $z['mhd'] ? h(date('d.m.Y', strtotime((string)$z['mhd']))) : '<span class="muted">–</span>' ?></td>
      <td data-label="Menge"><?= h(menge_txt((float)$z['menge_verfuegbar'])) ?> <?= h((string)$z['einheit']) ?></td>
      <td data-label="" style="text-align:right">
        <a class="btn btn-ghost btn-sm" href="?p=charge&id=<?= (int)$z['id'] ?>">Charge</a>
        <?php if ($zG !== ''): // Sofortfix 13.11.2026: Freigabe erst mit Unterlage (Upload im Dashboard) ?>
        <span class="muted" style="font-size:12px">Freigabe erst, wenn die CoA/Spec da ist</span>
        <?php elseif (lg_darf_qm_freigeben()): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Diese Charge freigeben? Sie zählt danach als verfügbar.');">
          <input type="hidden" name="aktion" value="freigeben"><input type="hidden" name="charge_id" value="<?= (int)$z['id'] ?>"><input type="hidden" name="item" value="<?= (int)$itemF ?>">
          <button class="btn btn-primary btn-sm" type="submit">Freigeben</button>
        </form>
        <?php else: ?><span class="muted" style="font-size:12px">Freigabe durch QM</span><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php
fuss();
