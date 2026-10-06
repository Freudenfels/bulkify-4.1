<?php
// Buchhaltungs-Einstellungen (Reiter). Route: einstellungen (finance/admin). GoBD-Schutz + Mahnwesen.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';   // meta_get/meta_set, gobd_scharf() (über erp.php)

// --- POST: GoBD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'gobd_set') {
    meta_set('gobd_scharf', ($_POST['wert'] ?? '0') === '1' ? '1' : '0');
    if (($_POST['wert'] ?? '') === '1') meta_set('gobd_scharf_am', gmdate('Y-m-d H:i:s'));
    header('Location: ?p=einstellungen&gobd=' . (($_POST['wert'] ?? '') === '1' ? 'an' : 'aus')); exit;
}
// --- POST: Mahnwesen ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'mahn_set') {
    $num = fn($k, $d='0') => (string) (float) str_replace(',', '.', trim((string)($_POST[$k] ?? $d)));
    $int = fn($k, $d='0') => (string) max(0, (int)($_POST[$k] ?? $d));
    meta_set('mahn_aktiv', !empty($_POST['mahn_aktiv']) ? '1' : '0');
    meta_set('mahn_zins', $num('mahn_zins'));
    meta_set('mahn_neue_frist', $int('mahn_neue_frist', '7'));
    foreach ([1,2,3] as $s) {
        meta_set('mahn_frist_' . $s, $int('mahn_frist_' . $s));
        meta_set('mahn_gebuehr_' . $s, $num('mahn_gebuehr_' . $s));
        meta_set('mahn_text_' . $s, trim((string)($_POST['mahn_text_' . $s] ?? '')));
    }
    header('Location: ?p=einstellungen&tab=mahnwesen&saved=1'); exit;
}

$tab = preg_replace('/[^a-z]/', '', $_GET['tab'] ?? 'gobd') ?: 'gobd';
require_once BX_ROOT . '/core/mahnung.php';   // mahn_config()

render_header('einstellungen', 'Buchhaltung – Einstellungen');
bx_head('Einstellungen', 'Buchhaltung');
bx_tabs(['gobd' => 'GoBD-Schutz', 'mahnwesen' => 'Mahnwesen'], $tab, '?p=einstellungen');

if ($tab === 'mahnwesen'):
    $cfg = mahn_config();
    if (isset($_GET['saved'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Mahnwesen-Einstellungen gespeichert.</div>';
?>
<form method="post" class="bx-panel" style="max-width:820px">
  <input type="hidden" name="aktion" value="mahn_set">
  <h2 style="margin-top:0">Mahnwesen</h2>
  <label class="bx-check" style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="mahn_aktiv" value="1" <?= $cfg['aktiv']?'checked':'' ?>> Mahnwesen aktiv</label>
  <p class="muted" style="margin:6px 0 14px">3 Stufen. „Frist" = Tage überfällig, ab denen die Stufe im Mahnlauf vorgeschlagen wird. Die Gebühr wird nur auf der Mahnung ausgewiesen, der Rechnungsbetrag bleibt unverändert.</p>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Stufe</th><th class="bx-num">Frist (Tage überfällig)</th><th class="bx-num">Mahngebühr (€)</th></tr></thead>
    <tbody>
      <?php foreach ([1,2,3] as $s): ?>
      <tr>
        <td><?= h($cfg['stufen'][$s]['label']) ?></td>
        <td class="bx-num"><input type="number" name="mahn_frist_<?= $s ?>" value="<?= (int)$cfg['stufen'][$s]['frist'] ?>" min="0" style="max-width:120px"></td>
        <td class="bx-num"><input type="text" inputmode="decimal" name="mahn_gebuehr_<?= $s ?>" value="<?= h(number_format((float)$cfg['stufen'][$s]['gebuehr'],2,',','')) ?>" style="max-width:120px"></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="bx-grid" style="margin-top:12px">
    <div class="bx-field"><label>Verzugszinsen p. a. (%) – 0 = aus</label><input type="text" inputmode="decimal" name="mahn_zins" value="<?= h(number_format((float)$cfg['zins_prozent'],2,',','')) ?>" style="max-width:160px"></div>
    <div class="bx-field"><label>Neue Zahlungsfrist (Tage ab Mahndatum)</label><input type="number" name="mahn_neue_frist" value="<?= (int)$cfg['neue_frist'] ?>" min="0" style="max-width:160px"></div>
  </div>
  <h3 style="margin:18px 0 6px">Mahntexte</h3>
  <?php foreach ([1,2,3] as $s): ?>
    <div class="bx-field"><label><?= h($cfg['stufen'][$s]['label']) ?></label><textarea name="mahn_text_<?= $s ?>" rows="2" style="width:100%;box-sizing:border-box"><?= h($cfg['stufen'][$s]['text']) ?></textarea></div>
  <?php endforeach; ?>
  <div class="bx-row" style="margin-top:14px"><button class="btn btn-primary" type="submit">Mahnwesen speichern</button>
    <a class="btn btn-ghost" href="?p=mahnlauf">Zum Mahnlauf</a></div>
</form>
<?php
else:   // GoBD
    $scharf = gobd_scharf();
    $seit = (string) meta_get('gobd_scharf_am', '');
    if (isset($_GET['gobd'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">GoBD-Schutz ' . ($_GET['gobd'] === 'an' ? 'scharfgeschaltet.' : 'ausgeschaltet (Aufbaumodus).') . '</div>';
?>
<div class="bx-panel" style="max-width:760px">
  <h2 style="margin-top:0">GoBD-Schutz <?= $scharf ? bx_badge('scharf', 'ok') : bx_badge('aus (Aufbaumodus)', 'warn') ?></h2>
  <p class="muted">
    <strong>Aufbaumodus (aus):</strong> Belege, Beträge und Positionen lassen sich frei korrigieren –
    gedacht fürs Importieren/Nachtragen alter Rechnungen und das Anlegen der Positionen während des Aufbaus.<br>
    <strong>Scharf:</strong> festgeschriebene/freigegebene/bezahlte Belege sind unveränderbar; Korrekturen nur
    über Storno/Gutschrift. Beträge aus Positionen übernehmen geht dann nur noch bei Entwürfen
    (offen, nicht freigegeben, unbezahlt).
  </p>
  <?php if ($scharf && $seit): ?><p class="muted" style="margin-top:0">Scharfgeschaltet am <?= h(fmt_zeit($seit)) ?>.</p><?php endif; ?>
  <?php if (!$scharf): ?>
    <form method="post" onsubmit="return confirm('GoBD scharfschalten? Danach sind festgeschriebene/freigegebene/bezahlte Belege unveränderbar (Korrektur nur über Storno/Gutschrift). Für den Live-Betrieb gedacht.');">
      <input type="hidden" name="aktion" value="gobd_set"><input type="hidden" name="wert" value="1">
      <button class="btn btn-primary" type="submit">GoBD scharfschalten</button>
      <span class="muted" style="margin-left:10px">Erst machen, wenn der Import/Aufbau abgeschlossen ist.</span>
    </form>
  <?php else: ?>
    <form method="post" onsubmit="return confirm('GoBD wieder ausschalten (Aufbaumodus)? Nur während Aufbau/Korrekturen sinnvoll – im Live-Betrieb sollte GoBD scharf bleiben.');">
      <input type="hidden" name="aktion" value="gobd_set"><input type="hidden" name="wert" value="0">
      <button class="btn btn-ghost" type="submit">GoBD-Schutz ausschalten (Aufbaumodus)</button>
    </form>
  <?php endif; ?>
</div>
<?php
endif;
render_footer();
