<?php
// TEMPORÄR: v3-Dokumente (Dateien) nach v4 übernehmen. Liest v3 bulkify-data/board.sqlite + uploads/ und legt
// je Datei eine dokument-Zeile an (Auftrag/Rohstoff/Lieferant), idempotent per dokument.v3_ref. Nur Admin.
// Läuft auf dem Server, wo der v3-Datenordner erreichbar ist (Standard: Nachbarordner von /bulkify4.1).
// Nach dem Umzug wieder entfernen (siehe Memory v3-dokumente-migration-temporaer).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/tools/v3_dokumente_import.php';

// Standard-Pfad raten: /bulkify4.1 und /bulkifyv2 liegen meist als Nachbarn unter demselben Elternordner.
$standard = dirname(BX_ROOT) . '/bulkifyv2/bulkify-data';
$pfad = trim((string)($_POST['pfad'] ?? $_GET['pfad'] ?? $standard));
$ergebnis = null; $aktion = (string)($_POST['aktion'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($aktion, ['trocken', 'import'], true)) {
    $ergebnis = v3_dok_import($pfad, $aktion === 'import');
}

render_header('v3_dok_import', 'v3-Dokumente übernehmen');
bx_head('v3-Dokumente übernehmen', 'Temporär: die hochgeladenen Dateien aus v3 (CoAs, Rechnungen, PIB, Etiketten, Qualität, Rohstoff-/Lieferanten-CoAs) nach v4 übernehmen.');
?>
<div class="bx-panel" style="border-color:#e6c4c0;padding:10px 14px;font-size:13px">Erst <strong>Trockenlauf</strong> (zählt nur, ändert nichts), dann <strong>Import</strong>. Idempotent – schon übernommene Dateien werden übersprungen. Kopiert die Dateien in <code>data/uploads</code>. Rechnungen werden für den Kunden sichtbar, der Rest bleibt intern (später freigeben).</div>

<form method="post" class="bx-panel" data-busy="Verarbeite … (kann bei vielen Dateien etwas dauern)">
  <div class="bx-field"><label>Pfad zum v3-Datenordner (enthält <code>board.sqlite</code> + <code>uploads/</code>)</label>
    <input type="text" name="pfad" value="<?= h($pfad) ?>" style="min-width:420px">
    <div class="muted" style="font-size:12px;margin-top:4px">Standard geraten: <code><?= h($standard) ?></code>. Falls falsch, absoluten Serverpfad eintragen (z. B. <code>/bulkifyv2/bulkify-data</code>).</div>
  </div>
  <div class="bx-row" style="gap:8px;margin-top:var(--sp-3)">
    <button class="btn btn-ghost" type="submit" name="aktion" value="trocken">Trockenlauf</button>
    <button class="btn btn-primary" type="submit" name="aktion" value="import" onclick="return confirm('Dokumente jetzt übernehmen? (idempotent, kopiert Dateien nach data/uploads)');">Import ausführen</button>
  </div>
</form>

<?php if ($ergebnis !== null): ?>
  <?php if (empty($ergebnis['ok'])): ?>
    <div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px"><?= h((string)($ergebnis['fehler'] ?? 'Fehler.')) ?></div>
  <?php else: $s = $ergebnis; ?>
    <div class="bx-panel <?= $s['commit'] ? 'badge-ok' : '' ?>">
      <h2 style="margin-top:0;font-size:16px"><?= $s['commit'] ? 'Import abgeschlossen' : 'Trockenlauf' ?></h2>
      <div class="bx-tablewrap"><table class="bx-table"><tbody>
        <tr><td class="muted" style="width:280px"><?= $s['commit'] ? 'Übernommen (gesamt)' : 'Würde übernehmen (gesamt)' ?></td><td><strong><?= (int)$s['gesamt'] ?></strong></td></tr>
        <tr><td class="muted">– Auftrags-Dokumente (dateien)</td><td><?= (int)$s['dateien'] ?><?php if (!empty($s['gates'])): ?> <span class="muted">(<?php foreach ($s['gates'] as $g => $c) echo h($g) . ': ' . (int)$c . '  '; ?>)</span><?php endif; ?></td></tr>
        <tr><td class="muted">– Rohstoff-CoAs (coa_dateien)</td><td><?= (int)$s['coa'] ?></td></tr>
        <tr><td class="muted">– Lieferanten-CoA/Spec</td><td><?= (int)$s['liefdoc'] ?></td></tr>
        <tr><td class="muted">Schon vorhanden (übersprungen)</td><td><?= (int)$s['schon'] ?></td></tr>
        <tr><td class="muted">Kein passender Auftrag in v4</td><td><?= (int)$s['kein_auftrag'] ?></td></tr>
        <tr><td class="muted">Kein passender Rohstoff in v4</td><td><?= (int)$s['kein_item'] ?></td></tr>
        <tr><td class="muted">Datei fehlt im uploads-Ordner</td><td><?= (int)$s['datei_fehlt'] ?></td></tr>
      </tbody></table></div>
      <?php if (!$s['commit'] && (int)$s['gesamt'] > 0): ?><p class="muted" style="margin:10px 0 0">Sieht gut aus? Dann oben „Import ausführen".</p><?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php render_footer(); ?>
