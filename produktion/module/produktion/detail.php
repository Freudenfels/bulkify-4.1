<?php
// Detail eines Produktionsauftrags: Kopf + Schritte. Der jeweils erste offene Schritt lässt sich
// abschließen – das bucht über die Naht (erp.php) das Material nach FEFO ab (Mangel-Guard) und
// setzt den Auftragsstatus; der letzte Schritt bucht die Fertigware als Charge ein.
$id = (int)($_GET['id'] ?? 0);

// Schritt abschließen (POST) – danach Redirect (Post/Redirect/Get), damit kein Reload doppelt bucht.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['aktion'] ?? '') === 'schritt_ab') {
    $schritt_id = (int)($_POST['schritt_id'] ?? 0);
    $akteur = (string)(pr_benutzer()['name'] ?? '');
    $r = erp_schritt_abschliessen($schritt_id, $akteur);
    if ($r['ok']) {
        flash($r['fertig']
            ? 'Letzter Schritt abgeschlossen – Produktion fertig, Fertigware eingebucht.'
            : 'Schritt „' . $r['station'] . '" abgeschlossen.', 'ok');
    } else {
        flash($r['msg'] ?: 'Schritt konnte nicht abgeschlossen werden.', 'warn');
    }
    weiter('?p=pa&id=' . $id);
}

$pa = erp_pa($id);
if (!$pa) { kopf('Produktionsauftrag'); seitenkopf('Nicht gefunden'); echo '<div class="bx-panel"><a class="btn btn-ghost" href="?p=liste">Zurück</a></div>'; fuss(); return; }
$schritte = erp_pa_schritte($id);

// Erster noch offener Schritt (nur der darf abgeschlossen werden – Reihenfolge).
$erster_offen = 0;
foreach ($schritte as $s) { if ((int)($s['erledigt'] ?? 0) === 0) { $erster_offen = (int)$s['id']; break; } }

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
    <thead><tr><th class="bx-num">#</th><th>Station</th><th>Status</th><th>Erledigt von</th><th>Wann</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($schritte as $i => $s):
          $fertig = (int)($s['erledigt'] ?? 0) === 1;
          $dran = (int)$s['id'] === $erster_offen; ?>
      <tr>
        <td class="bx-num"><?= (int)$i + 1 ?></td>
        <td><?= h((string)$s['station']) ?></td>
        <td><?= $fertig ? '<span class="badge badge-ok">erledigt</span>' : ($dran ? '<span class="badge badge-info">als Nächstes</span>' : '<span class="badge badge-warn">offen</span>') ?></td>
        <td class="muted"><?= h((string)($s['erledigt_von'] ?? '')) ?></td>
        <td class="muted"><?= h(fmt_zeit($s['erledigt_at'] ?? null)) ?></td>
        <td class="bx-num">
          <?php if ($dran): ?>
          <form method="post" style="margin:0" onsubmit="return confirm('Schritt &quot;<?= h((string)$s['station']) ?>&quot; abschließen? Material wird nach FEFO abgebucht.');">
            <input type="hidden" name="aktion" value="schritt_ab">
            <input type="hidden" name="schritt_id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="btn btn-sm">Abschließen</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <p class="muted" style="font-size:12px;margin-top:12px">Es lässt sich immer nur der nächste offene Schritt abschließen (feste Reihenfolge). Reicht der Bestand für die Entnahme nicht, wird der Schritt nicht abgeschlossen und zeigt, was fehlt.</p>
</div>
<?php fuss();
