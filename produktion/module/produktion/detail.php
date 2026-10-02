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
$fertig_cnt = 0;
foreach ($schritte as $s) { if ((int)($s['erledigt'] ?? 0) === 1) $fertig_cnt++; elseif ($erster_offen === 0) $erster_offen = (int)$s['id']; }

// Übersichtsdaten
$ber    = erp_pa_bereitschaft($id, (string)$pa['status'], $fertig_cnt);
$charge = erp_pa_charge_info($id);
$vpe    = erp_stueck_je_packung($pa);                     // Stück/Kapseln je Packung (VPE)
$gesamt = $vpe > 0 ? (int)$pa['menge'] * $vpe : 0;        // Gesamtstückzahl
$form   = (string)($pa['form'] ?? '');
$stkWort = in_array($form, ['kapsel','softgel'], true) ? 'Kapseln' : ($form === 'tablette' ? 'Tabletten' : 'Stück');
$eingang = $pa['auftrag_eingang'] ?? ($pa['angelegt'] ?? null);
// Verpackung lesbar zusammensetzen (Name · Typ · Volumen · Material)
$vpTeile = array_filter([
    (string)($pa['verpackung_name'] ?? ''),
    (string)($pa['verpackung_art'] ?? ''),
    !empty($pa['verpackung_volumen']) ? rtrim(rtrim(number_format((float)$pa['verpackung_volumen'], 2, ',', '.'), '0'), ',') . ' ml' : '',
    (string)($pa['verpackung_material'] ?? ''),
]);
$verpackungTxt = $vpTeile ? implode(' · ', $vpTeile) : '';

kopf($pa['nummer'] . ' – Produktion', 'liste');
seitenkopf((string)$pa['nummer'], (string)($pa['produkt_name'] ?? ''), '<a class="btn btn-ghost btn-sm" href="?p=liste">Zurück zur Liste</a>');
?>
<?php
// Kennzahl-Karte (nur rendern, wenn ein Wert da ist).
$karte = function (string $label, string $wertHtml, string $roh = '') {
    if ($roh === '' && trim(strip_tags($wertHtml)) === '') return;
    echo '<div class="bx-panel" style="margin:0"><div class="muted">' . h($label) . '</div><div style="margin-top:6px">' . $wertHtml . '</div></div>';
};
?>
<div class="bx-cards" style="margin-bottom:16px">
  <?php
  $karte('Status', pa_badge((string)$pa['status']), 'x');
  $karte('Produzierbar?', bereit_badge($ber['status']), 'x');
  $karte('Auftragseingang', $eingang ? h(fmt_zeit($eingang, 'd.m.Y')) : '<span class="muted">–</span>', 'x');
  $karte('Kunde', h((string)($pa['kunde'] ?: '–')), 'x');
  $karte('Produkt', h((string)($pa['produkt_name'] ?: '–')), 'x');
  $karte('Rezeptur', h((string)($pa['rezeptur_name'] ?? '')));
  $karte('Kapselgröße', h((string)($pa['kapselgroesse'] ?? '')));
  $karte('Menge', number_format((int)$pa['menge'], 0, ',', '.') . ' <span class="muted" style="font-size:13px">Packungen</span>', 'x');
  if ($vpe > 0)    $karte($stkWort . ' je VPE', number_format($vpe, 0, ',', '.'));
  if ($gesamt > 0) $karte($stkWort . ' gesamt', number_format($gesamt, 0, ',', '.'));
  $karte('Charge' . ($charge['gebucht'] ? ($charge['anzahl'] > 1 ? ' (' . $charge['anzahl'] . ')' : '') : ' (geplant)'),
         h($charge['nr']), 'x');
  $karte('MHD' . ($charge['gebucht'] ? '' : ' (+18 Mon.)'),
         $charge['mhd'] ? h(date('d.m.Y', strtotime($charge['mhd']))) : '<span class="muted">–</span>', 'x');
  $karte('Verpackung', h($verpackungTxt));
  $karte('Herstellung', h((string)$pa['produktionsart'] === 'eigen' ? 'Eigenproduktion' : 'Fremdproduktion'), 'x');
  ?>
</div>

<?php if ($ber['status'] === 'wartet' && $ber['fehlend']): ?>
<div class="bx-panel warn" style="margin-bottom:16px">
  <h2 style="margin-top:0">Wartet auf Material</h2>
  <p class="muted" style="margin-top:0">Für die Produktion fehlt noch Bestand. Sobald alles da ist, wird der Auftrag „produzierbar".</p>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Material</th><th class="bx-num">Benötigt</th><th class="bx-num">Verfügbar</th><th class="bx-num">Fehlt</th></tr></thead>
    <tbody>
      <?php foreach ($ber['fehlend'] as $fdd): ?>
        <tr>
          <td><?= h((string)$fdd['name']) ?></td>
          <td class="bx-num"><?= menge_txt($fdd['benoetigt']) ?> <?= h((string)$fdd['einheit']) ?></td>
          <td class="bx-num"><?= menge_txt($fdd['verfuegbar']) ?> <?= h((string)$fdd['einheit']) ?></td>
          <td class="bx-num" style="color:#8f231b"><?= menge_txt($fdd['fehlt']) ?> <?= h((string)$fdd['einheit']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

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
