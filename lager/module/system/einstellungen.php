<?php
// Lager-Einstellungen (nur Admin): Etikett-Format, Drucker, kombinierte Brücke + Downloads,
// und der Zugang zum Blinker/Sender-Setup. Ein Ort für alles rund ums Lager.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'speichern') {
    lg_meta_schreiben('etikett_format', ($_POST['etikett_format'] ?? '') === 'gross' ? 'gross' : 'klein');
    lg_meta_schreiben('drucker_name', trim((string)($_POST['drucker_name'] ?? '')));
    flash('Einstellungen gespeichert.');
    weiter('?p=einstellungen');
}

$etikettFormat = lg_meta_lesen('etikett_format', 'klein');
$drucker       = lg_meta_lesen('drucker_name', '');
$zuletzt       = lg_meta_lesen('bruecke_zuletzt', '');
$wach          = $zuletzt !== '' && (time() - strtotime($zuletzt . ' UTC')) < 15;
$sender        = function_exists('led_sender_alle') ? led_sender_alle() : [];

kopf('Einstellungen', 'einstellungen');
seitenkopf('Einstellungen', 'Etikett, Drucker, Brücke und Blinker');
flash_zeigen();
?>
<form method="post">
  <input type="hidden" name="aktion" value="speichern">

  <!-- Etikett + Drucker -->
  <div class="bx-panel">
    <h2 style="margin-top:0">Etikett &amp; Drucker</h2>
    <div class="bx-grid">
      <div class="bx-field"><label>Standard-Etikettengröße</label>
        <select name="etikett_format">
          <option value="klein" <?= $etikettFormat !== 'gross' ? 'selected' : '' ?>>Klein – 100 × 70 mm (quer)</option>
          <option value="gross" <?= $etikettFormat === 'gross' ? 'selected' : '' ?>>Groß – 100 × 150 mm (hoch)</option>
        </select>
        <div class="muted" style="font-size:12px;margin-top:4px">Gilt für alle „Etikett drucken"-Knöpfe. Je Druck kann man trotzdem umschalten.</div>
      </div>
      <div class="bx-field"><label>Drucker-Name <span class="muted">(leer = Standarddrucker)</span></label>
        <input type="text" name="drucker_name" value="<?= h($drucker) ?>" placeholder="z. B. Zebra ZD420">
        <div class="muted" style="font-size:12px;margin-top:4px">Name genau wie in Windows unter „Drucker &amp; Scanner".</div>
      </div>
    </div>
    <div style="margin-top:var(--sp-3)"><button type="submit" class="btn btn-primary">Speichern</button></div>
  </div>
</form>

<!-- Brücke + Downloads -->
<div class="bx-panel">
  <h2 style="margin-top:0">Brücke auf dem Lager-PC <span class="badge <?= $wach ? 'badge-ok' : 'badge-warn' ?>" style="margin-left:6px"><?= $wach ? 'läuft' : 'nicht aktiv' ?></span></h2>
  <p class="muted" style="margin:0 0 var(--sp-3)">Ein Programm auf einem PC im Lager. Es lässt die <strong>Blinker</strong> leuchten (an den Sender im Netz) und <strong>druckt Etiketten</strong> lautlos (SumatraPDF). Fenster offen lassen – am besten in den Autostart legen.</p>
  <div class="bx-row" style="gap:var(--sp-3);flex-wrap:wrap">
    <a class="btn btn-primary" href="?p=bruecke_skript">Brücke herunterladen (.bat)</a>
    <a class="btn btn-ghost" href="https://www.sumatrapdfreader.org/download-free-pdf-viewer" target="_blank" rel="noopener">SumatraPDF herunterladen</a>
    <a class="btn btn-ghost" href="?p=sender">Sender &amp; Blinker einrichten</a>
  </div>
  <ol class="muted" style="margin:var(--sp-4) 0 0;padding-left:1.2em;line-height:1.7">
    <li><strong>SumatraPDF</strong> auf dem Lager-PC installieren (kostenlos, für lautlosen Druck).</li>
    <li><strong>Brücke (.bat)</strong> herunterladen und per Doppelklick starten – Fenster offen lassen.</li>
    <li>Oben erscheint dann „läuft". Unter <a href="?p=sender">Sender &amp; Blinker</a> die Sender-IP eintragen und testen.</li>
    <li>Drucker-Name oben eintragen (oder leer = Standarddrucker). Fertig – „Drucken" am Etikett druckt direkt.</li>
  </ol>
</div>

<!-- Blinker/Sender Kurzüberblick -->
<div class="bx-panel">
  <h2 style="margin-top:0">Blinker / Sender</h2>
  <?php if (!$sender): ?>
    <p class="muted" style="margin:0 0 var(--sp-3)">Noch kein Sender angelegt. Zum Einrichten:</p>
  <?php else: ?>
    <p class="muted" style="margin:0 0 var(--sp-3)"><?= count($sender) ?> Sender angelegt. Verwalten, IP eintragen und testen:</p>
  <?php endif; ?>
  <a class="btn btn-ghost" href="?p=sender">Sender &amp; Brücke verwalten</a>
  <a class="btn btn-ghost" href="?p=leisten">Blinker testen</a>
</div>
<?php
fuss();
