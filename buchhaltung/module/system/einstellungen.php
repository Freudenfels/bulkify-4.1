<?php
// Buchhaltungs-Einstellungen. Route: einstellungen (finance/admin). Aktuell: GoBD scharfschalten.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';   // meta_get/meta_set, gobd_scharf() (über erp.php)

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'gobd_set') {
    meta_set('gobd_scharf', ($_POST['wert'] ?? '0') === '1' ? '1' : '0');
    if (($_POST['wert'] ?? '') === '1') meta_set('gobd_scharf_am', gmdate('Y-m-d H:i:s'));
    header('Location: ?p=einstellungen&gobd=' . (($_POST['wert'] ?? '') === '1' ? 'an' : 'aus')); exit;
}

$scharf = gobd_scharf();
$seit = (string) meta_get('gobd_scharf_am', '');

render_header('einstellungen', 'Buchhaltung – Einstellungen');
bx_head('Einstellungen', 'Buchhaltung');
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
render_footer();
