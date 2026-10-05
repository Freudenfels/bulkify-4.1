<?php
// QS & Labor zu einem Produktionsauftrag: Rückstellmuster erfassen, Laborprobe bereitstellen/ans Labor
// versenden (Statuswechsel) und das QS-Freigabedokument (druckbar) inkl. Freigabe. Erfasste Werte liegen
// in der eigenen Tabelle pr_pa_daten. Auftragsdaten nur lesend über die Naht erp.php.
$id = (int)($_GET['id'] ?? 0);
$akteur = (string)(pr_benutzer()['name'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'qs_muster') {
        pr_daten_setzen($id, 'rueckstellmuster_anzahl', trim((string)($_POST['anzahl'] ?? '')), $akteur);
        pr_daten_setzen($id, 'rueckstellmuster_charge', trim((string)($_POST['charge'] ?? '')), $akteur);
        pr_daten_setzen($id, 'rueckstellmuster_mhd',    trim((string)($_POST['mhd'] ?? '')), $akteur);
        pr_daten_setzen($id, 'rueckstellmuster_datum',  trim((string)($_POST['datum'] ?? '')) ?: gmdate('Y-m-d'), $akteur);
        flash('Rückstellmuster erfasst.');
    } elseif ($aktion === 'qs_labor') {
        pr_daten_setzen($id, 'laborprobe_anzahl', trim((string)($_POST['anzahl'] ?? '')), $akteur);
        pr_daten_setzen($id, 'laborprobe_labor',  trim((string)($_POST['labor'] ?? '')), $akteur);
        if ((string)(pr_daten($id)['laborstatus']['wert'] ?? '') === '') pr_daten_setzen($id, 'laborstatus', 'bereitgestellt', $akteur);
        flash('Laborprobe erfasst.');
    } elseif ($aktion === 'qs_labor_versenden') {
        pr_daten_setzen($id, 'laborprobe_versendet_am', gmdate('Y-m-d'), $akteur);
        pr_daten_setzen($id, 'laborstatus', 'versendet', $akteur);
        flash('Laborprobe als ans Labor versendet markiert.');
    } elseif ($aktion === 'qs_freigabe') {
        pr_daten_setzen($id, 'qs_freigabe_am', gmdate('Y-m-d H:i:s'), $akteur);
        pr_daten_setzen($id, 'qs_freigabe_von', $akteur, $akteur);
        flash('Qualität freigegeben.');
    } elseif ($aktion === 'qs_freigabe_zurueck') {
        pr_daten_setzen($id, 'qs_freigabe_am', '', $akteur);
        pr_daten_setzen($id, 'qs_freigabe_von', '', $akteur);
        flash('QS-Freigabe zurückgezogen.', 'warn');
    }
    weiter('?p=qs&id=' . $id);
}

$pa = erp_pa($id);
if (!$pa) { kopf('QS & Labor'); seitenkopf('Nicht gefunden'); echo '<div class="bx-panel"><a class="btn btn-ghost" href="?p=liste">Zurück</a></div>'; fuss(); return; }
$d      = pr_daten($id);
$charge = erp_pa_charge_info($id);
$wert   = fn(string $f) => (string)($d[$f]['wert'] ?? '');
$laborstatus = $wert('laborstatus');
$freigegeben = $wert('qs_freigabe_am') !== '';

kopf($pa['nummer'] . ' – QS & Labor', 'liste');
seitenkopf('QS & Labor · ' . (string)$pa['nummer'], (string)($pa['produkt_name'] ?? ''),
    '<a class="btn btn-ghost btn-sm" href="?p=pa&id=' . $id . '">Zur Übersicht</a> '
    . '<button type="button" class="btn btn-ghost btn-sm" onclick="window.print()">Drucken</button>');

$laborBadge = match ($laborstatus) {
    'versendet'     => '<span class="badge badge-info">ans Labor versendet' . ($wert('laborprobe_versendet_am') ? ' (' . h(date('d.m.Y', strtotime($wert('laborprobe_versendet_am')))) . ')' : '') . '</span>',
    'bereitgestellt'=> '<span class="badge badge-warn">bereitgestellt</span>',
    default         => '<span class="badge">offen</span>',
};
?>
<div class="bx-panel" style="margin-bottom:16px">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px 28px">
    <div><div class="muted" style="font-size:13px">Produkt</div><div><?= h((string)($pa['produkt_name'] ?: '–')) ?></div></div>
    <div><div class="muted" style="font-size:13px">Menge</div><div><?= number_format((int)$pa['menge'], 0, ',', '.') ?></div></div>
    <div><div class="muted" style="font-size:13px">Charge</div><div><?= h($charge['nr']) ?></div></div>
    <div><div class="muted" style="font-size:13px">MHD</div><div><?= $charge['mhd'] ? h(date('d.m.Y', strtotime($charge['mhd']))) : '–' ?></div></div>
    <div><div class="muted" style="font-size:13px">QS-Freigabe</div><div><?= $freigegeben ? '<span class="badge badge-ok">freigegeben</span>' : '<span class="badge badge-warn">offen</span>' ?></div></div>
  </div>
</div>

<div class="bx-panel" style="margin-bottom:16px">
  <h2 style="margin-top:0">Rückstellmuster</h2>
  <form method="post" class="bx-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="qs_muster">
    <div class="bx-field" style="margin:0;max-width:140px"><label>Anzahl</label><input type="text" name="anzahl" value="<?= h($wert('rueckstellmuster_anzahl')) ?>"></div>
    <div class="bx-field" style="margin:0;max-width:200px"><label>Charge</label><input type="text" name="charge" value="<?= h($wert('rueckstellmuster_charge') ?: $charge['nr']) ?>"></div>
    <div class="bx-field" style="margin:0;max-width:180px"><label>MHD</label><input type="date" name="mhd" value="<?= h($wert('rueckstellmuster_mhd') ?: ($charge['mhd'] ? date('Y-m-d', strtotime($charge['mhd'])) : '')) ?>"></div>
    <div class="bx-field" style="margin:0;max-width:180px"><label>Gezogen am</label><input type="date" name="datum" value="<?= h($wert('rueckstellmuster_datum') ?: date('Y-m-d')) ?>"></div>
    <button type="submit" class="btn btn-primary">Speichern</button>
  </form>
</div>

<div class="bx-panel" style="margin-bottom:16px">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">Laborprobe</h2><div>Status: <?= $laborBadge ?></div>
  </div>
  <form method="post" class="bx-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap;margin-top:10px">
    <input type="hidden" name="aktion" value="qs_labor">
    <div class="bx-field" style="margin:0;max-width:140px"><label>Anzahl Proben</label><input type="text" name="anzahl" value="<?= h($wert('laborprobe_anzahl')) ?>"></div>
    <div class="bx-field" style="margin:0;max-width:240px"><label>Labor</label><input type="text" name="labor" value="<?= h($wert('laborprobe_labor')) ?>" placeholder="z. B. Eurofins"></div>
    <button type="submit" class="btn btn-ghost">Speichern</button>
  </form>
  <?php if ($laborstatus !== 'versendet'): ?>
  <form method="post" style="margin:12px 0 0" onsubmit="return confirm('Laborprobe als ans Labor versendet markieren?');">
    <input type="hidden" name="aktion" value="qs_labor_versenden">
    <button type="submit" class="btn btn-primary">An Labor versendet</button>
  </form>
  <?php else: ?>
  <div class="muted" style="font-size:12px;margin-top:10px">Bericht-Upload/Freigabe erfolgt im Dashboard (Labortests); der Status „abgeschlossen" kommt von dort.</div>
  <?php endif; ?>
</div>

<?php // Erfasste Kontrollwerte aus dem Produktionsablauf (read-only).
$kontrollen = array_filter([
    'Gemischte Menge' => $wert('mischmenge') ? $wert('mischmenge') . ' kg' : '',
    'Kontrollgewicht' => $wert('kontrolle_gewicht') ? $wert('kontrolle_gewicht') . ' g' : '',
]);
if ($kontrollen): ?>
<div class="bx-panel" style="margin-bottom:16px">
  <h2 style="margin-top:0">Erfasste Kontrollwerte</h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 24px">
    <?php foreach ($kontrollen as $k => $v): ?>
      <div><div class="muted" style="font-size:13px"><?= h($k) ?></div><div><?= h((string)$v) ?></div></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="bx-panel" style="margin-bottom:16px">
  <h2 style="margin-top:0">Qualitäts-Freigabe</h2>
  <?php if ($freigegeben): ?>
    <p style="margin-top:0">Freigegeben am <strong><?= h(fmt_zeit($wert('qs_freigabe_am'))) ?></strong><?= $wert('qs_freigabe_von') ? ' von ' . h($wert('qs_freigabe_von')) : '' ?>.</p>
    <form method="post" style="margin:0" onsubmit="return confirm('QS-Freigabe zurückziehen?');"><input type="hidden" name="aktion" value="qs_freigabe_zurueck"><button type="submit" class="btn btn-ghost btn-sm">Freigabe zurückziehen</button></form>
  <?php else: ?>
    <p class="muted" style="margin-top:0">Rückstellmuster gezogen, Laborprobe bereitgestellt und Kontrollen in Ordnung? Dann die Produktion qualitativ freigeben.</p>
    <form method="post" style="margin:0"><input type="hidden" name="aktion" value="qs_freigabe"><button type="submit" class="btn btn-primary">Qualität freigeben</button></form>
  <?php endif; ?>
  <p class="muted" style="font-size:12px;margin:10px 0 0">Diese Seite ist zugleich das druckbare QS-Freigabedokument (Button „Drucken" oben).</p>
</div>
<?php fuss();
