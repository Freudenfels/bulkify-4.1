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

// Produktionsweg (Ausbaustufen) speichern – nur Admin, nur solange nichts erledigt ist.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['aktion'] ?? '') === 'weg' && pr_ist_admin()) {
    $r = erp_weg_anwenden($id, [
        'abfuellen'    => !empty($_POST['abfuellen']),
        'etikettieren' => !empty($_POST['etikettieren']),
        'beipack'      => !empty($_POST['beipack']),
        'karton'       => !empty($_POST['karton']),
    ]);
    flash($r['msg'], $r['ok'] ? 'ok' : 'warn');
    weiter('?p=pa&id=' . $id);
}

// Teilmenge produzieren (anteilig Rohstoffe verbrauchen + als Charge einbuchen).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['aktion'] ?? '') === 'teilmenge') {
    $m = (float) str_replace(',', '.', (string)($_POST['menge'] ?? '0'));
    $r = erp_teilmenge_produzieren($id, $m, (string)(pr_benutzer()['name'] ?? ''));
    if (!$r['ok'] && !empty($r['fehlt'])) {
        $t = [];
        foreach ($r['fehlt'] as $f) $t[] = (string)$f['name'] . ' (fehlt ' . menge_txt($f['fehlt']) . ' ' . (string)$f['einheit'] . ')';
        flash('Nicht genug Material für diese Teilmenge: ' . implode(', ', $t) . '.', 'warn');
    } else {
        flash($r['msg'], $r['ok'] ? 'ok' : 'warn');
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
$weg = erp_weg_lesen($id);
$istAdmin = pr_ist_admin();
$produziert = erp_produktion_gebucht($id);
$benoetigt  = (int)$pa['menge'];
$prod_rest  = max(0, $benoetigt - (int)round($produziert));
$prod_proz  = $benoetigt > 0 ? min(100, (int)round($produziert * 100 / $benoetigt)) : 0;
$etikett    = erp_etikett_datei($id);                     // hochgeladenes Kunden-Etikett (Dokument)
$etStatus   = erp_etikett_status($id);                    // physisches Etikett: angekommen/bestellt/…
$etBild     = $etikett && preg_match('/\.(png|jpe?g|gif|webp|svg)$/i', (string)($etikett['datei_orig'] ?? ''));
$etSchon    = $etikett ? erp_etikett_schon_verwendet((string)($etikett['datei_hash'] ?? ''), $id) : 0;

// Übersichtsdaten
$ber    = erp_pa_bereitschaft($id, (string)$pa['status'], $fertig_cnt);
$charge = erp_pa_charge_info($id);
$vpe    = erp_stueck_je_packung($pa);                     // Stück/Kapseln je Packung (VPE)
$gesamt = $vpe > 0 ? (int)$pa['menge'] * $vpe : 0;        // Gesamtstückzahl
$form   = (string)($pa['form'] ?? '');
$stkWort = in_array($form, ['kapsel','softgel'], true) ? 'Kapseln' : ($form === 'tablette' ? 'Tabletten' : 'Stk');
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
$zutaten = erp_pa_zutaten($id);
$bedarf  = erp_materialbedarf($id);   // Rohstoffe mit Mengen (benötigt gesamt / verfügbar)

kopf($pa['nummer'] . ' – Produktion', 'liste');
$istVorb  = (string)$pa['status'] === 'vorbereitung';
$runLabel = match ((string)$pa['status']) {
    'offen'    => 'Produktion starten',
    'laufend'  => 'Produktion fortsetzen',
    'erledigt' => 'Produktionsmodus',
    default    => 'In den Produktionsmodus',
};
// In Vorbereitung ist der Auftrag noch nicht freigegeben – „In den Produktionsmodus" dann ausgrauen
// (Freigabe erfolgt im Dashboard unter „Vor-Produktion"), statt in die gesperrte Run-Ansicht zu führen.
$vorbHint = 'Noch in Vorbereitung – erst im Dashboard unter „Vor-Produktion" freigeben, dann ist der Produktionsmodus startbar.';
$runBtn = $istVorb
    ? '<span class="btn btn-primary btn-sm" aria-disabled="true" title="' . h($vorbHint) . '" style="opacity:.5;cursor:not-allowed;pointer-events:none">' . h($runLabel) . '</span>'
      . '<span title="' . h($vorbHint) . '" style="display:inline-flex;align-items:center;justify-content:center;width:17px;height:17px;border-radius:50%;background:#2b6cd4;color:#fff;font-size:11px;font-weight:700;cursor:help;margin-left:6px;vertical-align:middle" aria-label="Info">i</span>'
    : '<a class="btn btn-primary btn-sm" href="?p=run&id=' . $id . '">' . h($runLabel) . '</a>';
$aktionen = $runBtn
          . ' <a class="btn btn-ghost btn-sm" href="?p=qs&id=' . $id . '">QS &amp; Labor</a>'
          . ' <a class="btn btn-ghost btn-sm" href="?p=liste">Zurück zur Liste</a>';
seitenkopf((string)$pa['nummer'], (string)($pa['produkt_name'] ?? ''), $aktionen);
?>
<style>
.bx-ovgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:18px 28px;margin-top:4px}
.bx-ovk{color:var(--muted);font-size:13px;margin-bottom:3px}
.bx-ovv{color:var(--text)}
</style>
<?php
$muted = fn(string $s) => $s !== '' ? h($s) : '<span class="muted">–</span>';
// Alle Felder in EINEM gleichmäßigen Raster – leere Werte (force=false) fallen raus.
$felder = [
    ['Status', pa_badge((string)$pa['status']), true],
    ['Produzierbar?', bereit_badge($ber['status']), true],
    ['Auftragseingang', $muted($eingang ? fmt_zeit($eingang, 'd.m.Y') : ''), true],
    ['Kunde', $muted((string)($pa['kunde'] ?? '')), true],
    ['Produktionstyp', h((string)$pa['produktionsart'] === 'eigen' ? 'Eigenproduktion' : 'Fremdproduktion'), true],
    ['Produkt', $muted((string)($pa['produkt_name'] ?? '')), true],
    ['Rezeptur', h((string)($pa['rezeptur_name'] ?? ''))],
    ['Kapselgröße', h((string)($pa['kapselgroesse'] ?? ''))],
    ['Verpackung', $muted($verpackungTxt), true],
    ['Packungen', number_format((int)$pa['menge'], 0, ',', '.'), true],
    [$stkWort . ' je VPE', $vpe > 0 ? number_format($vpe, 0, ',', '.') : ''],
    [$stkWort . ' gesamt', $gesamt > 0 ? number_format($gesamt, 0, ',', '.') : ''],
    ['Chargennummer' . ($charge['gebucht'] ? ($charge['anzahl'] > 1 ? ' (' . $charge['anzahl'] . ')' : '') : ' (geplant)'), h($charge['nr']), true],
    ['MHD' . ($charge['gebucht'] ? '' : ' (+18 Mon.)'), $muted($charge['mhd'] ? date('d.m.Y', strtotime($charge['mhd'])) : ''), true],
];
?>
<div class="bx-panel" style="margin-bottom:16px">
  <h2 style="margin-top:0">Übersicht</h2>
  <div class="bx-ovgrid">
  <?php foreach ($felder as $f):
      if (!($f[2] ?? false) && trim(strip_tags($f[1])) === '') continue; ?>
    <div><div class="bx-ovk"><?= h($f[0]) ?></div><div class="bx-ovv"><?= $f[1] ?></div></div>
  <?php endforeach; ?>
  </div>
</div>

<?php if ($etikett || ($etStatus['status'] ?? '') !== 'kein_etikett'):
    $etBadge = match ($etStatus['status'] ?? '') {
        'angekommen'  => '<span class="badge badge-ok">angekommen' . (!empty($etStatus['menge']) ? ' (' . menge_txt($etStatus['menge']) . ')' : '') . '</span>',
        'quarantaene' => '<span class="badge badge-warn">in Quarantäne – erst freigeben</span>',
        'bestellt'    => '<span class="badge badge-info">bestellt</span>',
        'offen'       => '<span class="badge badge-warn">noch nicht da</span>',
        default       => '',
    }; ?>
<div class="bx-panel" style="margin-bottom:16px">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">Etikett (Kunde)</h2>
    <?php if ($etBadge !== ''): ?><div>Etikett physisch: <?= $etBadge ?></div><?php endif; ?>
  </div>
  <?php if ($etikett): ?>
    <div style="margin-top:12px">
      <?php if ($etBild): ?>
        <a href="?p=etikett&id=<?= $id ?>" target="_blank"><img src="?p=etikett&id=<?= $id ?>" alt="Etikett" style="max-height:180px;max-width:100%;border:1px solid var(--line);border-radius:8px;background:#fff"></a>
      <?php else: ?>
        <iframe src="?p=etikett&id=<?= $id ?>" style="width:100%;max-width:420px;height:260px;border:1px solid var(--line);border-radius:8px;background:#fff"></iframe>
      <?php endif; ?>
    </div>
    <div class="muted" style="font-size:12px;margin-top:8px">
      <?= h((string)($etikett['datei_orig'] ?: 'Etikett-Datei')) ?> ·
      <a href="?p=etikett&id=<?= $id ?>" target="_blank">in neuem Tab öffnen</a>
      <?php if ($etSchon > 0): ?> · dieses Etikett wurde schon bei <?= (int)$etSchon ?> anderen Auftrag/Aufträgen verwendet<?php endif; ?>
    </div>
  <?php else: ?>
    <div class="muted" style="margin-top:10px">Noch kein Kunden-Etikett hochgeladen.</div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php // Rohstoffbedarf nur bei Eigen-/Bulk-Produktion. Bei Zukauf (fertige Bulkware) irrelevant -> ausblenden.
if ($bedarf && $weg['basis'] !== 'zukauf'): $sumMg = 0.0; foreach ($bedarf as $b) $sumMg += (float)$b['menge_mg']; ?>
<div class="bx-panel" style="margin-bottom:16px">
  <h2 style="margin-top:0">Rezeptur &amp; Rohstoffbedarf<?= !empty($pa['rezeptur_name']) ? ' · ' . h((string)$pa['rezeptur_name']) : '' ?></h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Rohstoff</th><th class="bx-num">mg je Einheit</th><th class="bx-num">Benötigt gesamt</th><th class="bx-num">Verfügbar</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($bedarf as $b): $ok = (float)$b['fehlt'] <= 0.0001; ?>
      <tr>
        <td><?= h((string)$b['name']) ?></td>
        <td class="bx-num"><?= (float)$b['menge_mg'] > 0 ? menge_txt($b['menge_mg']) . ' mg' : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= menge_txt($b['benoetigt']) ?> <?= h((string)$b['einheit']) ?></td>
        <td class="bx-num"><?= menge_txt($b['verfuegbar']) ?> <?= h((string)$b['einheit']) ?><?php if (!empty($b['quarantaene'])): ?><br><span class="muted" style="font-size:11px">+ <?= menge_txt($b['quarantaene']) ?> in Quarantäne – erst freigeben</span><?php endif; ?></td>
        <td><?= $ok ? '<span class="badge badge-ok">genug</span>' : '<span class="badge badge-warn">fehlt ' . menge_txt($b['fehlt']) . ' ' . h((string)$b['einheit']) . '</span>' ?></td>
      </tr>
      <?php endforeach; ?>
      <tr><td class="muted">Füllgewicht je Einheit</td><td class="bx-num"><?= menge_txt($sumMg) ?> mg</td><td colspan="3"></td></tr>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px;margin:10px 0 0">Benötigt gesamt = Rezepturmenge je Einheit × Gesamtstückzahl. Abgebucht wird nach FEFO (älteste MHD zuerst) beim Schritt „Rohstoffe bereitstellen".</p>
</div>
<?php elseif ($zutaten && $weg['basis'] !== 'zukauf'): $sumMg = 0.0; foreach ($zutaten as $z) $sumMg += (float)$z['menge_mg']; ?>
<div class="bx-panel" style="margin-bottom:16px">
  <h2 style="margin-top:0">Rezeptur<?= !empty($pa['rezeptur_name']) ? ' · ' . h((string)$pa['rezeptur_name']) : '' ?></h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Bestandteil</th><th class="bx-num">mg je Einheit</th></tr></thead>
    <tbody>
      <?php foreach ($zutaten as $z): ?>
      <tr><td><?= h((string)$z['name']) ?></td><td class="bx-num"><?= (float)$z['menge_mg'] > 0 ? menge_txt($z['menge_mg']) . ' mg' : '<span class="muted">–</span>' ?></td></tr>
      <?php endforeach; ?>
      <tr><td class="muted">Füllgewicht je Einheit</td><td class="bx-num"><?= menge_txt($sumMg) ?> mg</td></tr>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

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
          <td class="bx-num"><?= menge_txt($fdd['verfuegbar']) ?> <?= h((string)$fdd['einheit']) ?><?php if (!empty($fdd['quarantaene'])): ?><br><span class="muted" style="font-size:11px">+ <?= menge_txt($fdd['quarantaene']) ?> in Quarantäne – erst freigeben</span><?php endif; ?></td>
          <td class="bx-num" style="color:#8f231b"><?= menge_txt($fdd['fehlt']) ?> <?= h((string)$fdd['einheit']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="bx-panel" style="margin-bottom:16px">
  <h2 style="margin-top:0">Produktionsfortschritt</h2>
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
    <div>produziert <strong><?= number_format($produziert, 0, ',', '.') ?></strong> von <?= number_format($benoetigt, 0, ',', '.') ?>
      <?php if ($produziert > 0 && $prod_rest > 0): ?> <span class="badge badge-info">teilweise</span><?php elseif ($benoetigt > 0 && $prod_rest <= 0): ?> <span class="badge badge-ok">vollständig</span><?php endif; ?></div>
    <div class="muted"><?= $prod_proz ?>%</div>
  </div>
  <div style="height:12px;border-radius:6px;background:var(--line-2);overflow:hidden;margin-top:8px">
    <div style="height:100%;width:<?= $prod_proz ?>%;background:var(--gruen)"></div>
  </div>
  <?php if ($prod_rest > 0): ?>
  <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:14px" onsubmit="return confirm('Teilmenge jetzt produzieren? Rohstoffe werden anteilig abgebucht und als Fertigware-Charge eingebucht.');">
    <input type="hidden" name="aktion" value="teilmenge">
    <div class="bx-field" style="margin:0;max-width:200px"><label>Teilmenge produzieren</label>
      <input type="number" name="menge" min="1" max="<?= (int)$prod_rest ?>" step="1" required placeholder="max. <?= (int)$prod_rest ?>"></div>
    <button type="submit" class="btn btn-primary">Produzieren &amp; einbuchen</button>
    <span class="muted" style="font-size:12px">Verbraucht Rohstoffe anteilig (muss reichen) und bucht die Menge als Fertigware-Charge.</span>
  </form>
  <?php else: ?>
  <div class="muted" style="margin-top:10px">Vollständig produziert.</div>
  <?php endif; ?>
</div>

<?php if ($istAdmin && $weg['basis'] !== 'bulk'): ?>
<div class="bx-panel" style="margin-bottom:16px">
  <h2 style="margin-top:0">Produktionsweg</h2>
  <p class="muted" style="margin-top:0">Welche Ausbaustufen durchlaufen werden. Grundweg: <strong><?= $weg['basis'] === 'zukauf' ? 'Zugekaufte Fertigware' : 'Eigenproduktion' ?></strong>. Alles aus = nur Bulkware (bereitstellen, Prüfung, Freigaben).</p>
  <form method="post" class="bx-row" style="gap:18px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="weg">
    <label style="display:flex;gap:7px;align-items:center"><input type="checkbox" name="abfuellen" value="1" <?= $weg['abfuellen'] ? 'checked' : '' ?> <?= $weg['aenderbar'] ? '' : 'disabled' ?>> Abfüllen/Verpacken</label>
    <label style="display:flex;gap:7px;align-items:center"><input type="checkbox" name="etikettieren" value="1" <?= $weg['etikettieren'] ? 'checked' : '' ?> <?= $weg['aenderbar'] ? '' : 'disabled' ?>> Etikettieren</label>
    <label style="display:flex;gap:7px;align-items:center"><input type="checkbox" name="karton" value="1" <?= $weg['karton'] ? 'checked' : '' ?> <?= $weg['aenderbar'] ? '' : 'disabled' ?>> Karton/Umverpackung</label>
    <label style="display:flex;gap:7px;align-items:center"><input type="checkbox" name="beipack" value="1" <?= $weg['beipack'] ? 'checked' : '' ?> <?= $weg['aenderbar'] ? '' : 'disabled' ?>> Beipackzettel</label>
    <?php if ($weg['aenderbar']): ?>
      <button type="submit" class="btn btn-primary btn-sm">Weg speichern</button>
    <?php else: ?>
      <span class="muted" style="font-size:12px">Nicht mehr änderbar – Produktion wurde schon begonnen.</span>
    <?php endif; ?>
  </form>
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
            <button type="submit" class="btn btn-primary btn-sm">Abschließen</button>
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
