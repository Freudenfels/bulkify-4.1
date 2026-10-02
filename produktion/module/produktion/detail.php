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
// Verpackung lesbar zusammensetzen (Name · Typ · Volumen · Material), ohne Dopplungen zum Namen.
$vpName = trim((string)($pa['verpackung_name'] ?? ''));
$vpArt  = ucfirst(trim((string)($pa['verpackung_art'] ?? '')));
$vpVol  = !empty($pa['verpackung_volumen']) ? rtrim(rtrim(number_format((float)$pa['verpackung_volumen'], 2, ',', '.'), '0'), ',') . ' ml' : '';
$vpMat  = trim((string)($pa['verpackung_material'] ?? ''));
$vpTeile = [$vpName];
foreach ([$vpArt, $vpVol, $vpMat] as $t)   // nur ergänzen, was nicht schon im Namen steht
    if ($t !== '' && stripos($vpName, $t) === false) $vpTeile[] = $t;
$verpackungTxt = implode(' · ', array_filter($vpTeile));

kopf($pa['nummer'] . ' – Produktion', 'liste');
seitenkopf((string)$pa['nummer'], (string)($pa['produkt_name'] ?? ''), '<a class="btn btn-ghost btn-sm" href="?p=liste">Zurück zur Liste</a>');
?>
<style>
.bx-ovsek{margin-top:20px}
.bx-ovsek:first-child{margin-top:0}
.bx-ovsek-t{font-size:12px;letter-spacing:.05em;text-transform:uppercase;color:var(--gruen);margin-bottom:4px}
.bx-ovrow{display:grid;grid-template-columns:200px 1fr;gap:16px;padding:9px 0;border-bottom:1px solid var(--line-2)}
.bx-ovrow:last-child{border-bottom:none}
.bx-ovk{color:var(--muted)}
.bx-ovv{color:var(--text)}
@media(max-width:600px){.bx-ovrow{grid-template-columns:1fr;gap:2px;padding:7px 0}}
</style>
<?php
// Sammelt Label/Wert-Zeilen einer Gruppe; leere Werte fallen raus. Rückgabe: HTML (oder '').
$zeilen = function (array $paare): string {
    $out = '';
    foreach ($paare as $p) {
        [$label, $html, $force] = [$p[0], $p[1], $p[2] ?? false];
        if (!$force && trim(strip_tags($html)) === '') continue;
        $out .= '<div class="bx-ovrow"><div class="bx-ovk">' . h($label) . '</div><div class="bx-ovv">' . $html . '</div></div>';
    }
    return $out;
};
// Gruppe nur ausgeben, wenn sie Zeilen hat.
$sektion = function (string $titel, string $zeilenHtml) {
    if (trim($zeilenHtml) === '') return;
    echo '<div class="bx-ovsek"><div class="bx-ovsek-t">' . h($titel) . '</div>' . $zeilenHtml . '</div>';
};
$muted = fn(string $s) => $s !== '' ? h($s) : '<span class="muted">–</span>';
?>
<div class="bx-panel" style="margin-bottom:16px">
  <h2 style="margin-top:0">Übersicht</h2>
  <?php
  $sektion('Auftrag', $zeilen([
      ['Status', pa_badge((string)$pa['status']), true],
      ['Produzierbar?', bereit_badge($ber['status']), true],
      ['Auftragseingang', $muted($eingang ? fmt_zeit($eingang, 'd.m.Y') : ''), true],
      ['Kunde', $muted((string)($pa['kunde'] ?? '')), true],
      ['Herstellung', h((string)$pa['produktionsart'] === 'eigen' ? 'Eigenproduktion' : 'Fremdproduktion'), true],
  ]));
  $sektion('Produkt', $zeilen([
      ['Produkt', $muted((string)($pa['produkt_name'] ?? '')), true],
      ['Rezeptur', h((string)($pa['rezeptur_name'] ?? ''))],
      ['Kapselgröße', h((string)($pa['kapselgroesse'] ?? ''))],
      ['Verpackung', h($verpackungTxt)],
  ]));
  $sektion('Menge', $zeilen([
      ['Packungen', number_format((int)$pa['menge'], 0, ',', '.'), true],
      [$stkWort . ' je VPE', $vpe > 0 ? number_format($vpe, 0, ',', '.') : ''],
      [$stkWort . ' gesamt', $gesamt > 0 ? number_format($gesamt, 0, ',', '.') : ''],
  ]));
  $sektion('Charge', $zeilen([
      ['Chargennummer' . ($charge['gebucht'] ? ($charge['anzahl'] > 1 ? ' (' . $charge['anzahl'] . ')' : '') : ' (geplant)'), h($charge['nr']), true],
      ['MHD' . ($charge['gebucht'] ? '' : ' (+18 Mon.)'), $muted($charge['mhd'] ? date('d.m.Y', strtotime($charge['mhd'])) : ''), true],
  ]));
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
