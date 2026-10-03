<?php
// Lager-Einstellungen (nur Admin): Etikett-Format, Drucker, kombinierte Brücke + Downloads,
// und der Zugang zum Blinker/Sender-Setup. Ein Ort für alles rund ums Lager.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'speichern') {
    lg_meta_schreiben('etikett_format', ($_POST['etikett_format'] ?? '') === 'gross' ? 'gross' : 'klein');
    lg_meta_schreiben('drucker_name', trim((string)($_POST['drucker_name'] ?? '')));
    flash('Einstellungen gespeichert.');
    weiter('?p=einstellungen');
}
// Neuer Brücken-Schlüssel: meldet ALLE alten Brücken ab (falscher Schlüssel -> kein Zugriff mehr).
// Danach muss die Brücke auf dem Lager-PC einmal neu heruntergeladen/gestartet werden.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'token_neu') {
    lg_bruecke_token(true);
    lg_meta_schreiben('drucker_liste', '');     // alte Druckermeldung verwerfen
    lg_meta_schreiben('drucker_standard', '');
    flash('Neuer Schlüssel erzeugt. Alle alten Brücken sind abgemeldet. Jetzt die Brücke NUR auf dem Lager-PC neu herunterladen und starten.');
    weiter('?p=einstellungen');
}

$etikettFormat = lg_meta_lesen('etikett_format', 'klein');
$drucker       = lg_meta_lesen('drucker_name', '');
$druckerListe  = array_values(array_filter(array_map('trim', explode('|', lg_meta_lesen('drucker_liste', '')))));
$druckerStd    = lg_meta_lesen('drucker_standard', '');
$zuletzt       = lg_meta_lesen('bruecke_zuletzt', '');
$wach          = $zuletzt !== '' && (time() - strtotime($zuletzt . ' UTC')) < 15;
$programm      = lg_meta_lesen('bruecke_programm', '');
$brueckeIp     = lg_meta_lesen('bruecke_ip', '');
$zuletztTxt    = $zuletzt !== '' ? (function_exists('fmt_zeit') ? fmt_zeit($zuletzt) : $zuletzt) : '';
$sender        = function_exists('led_sender_alle') ? led_sender_alle() : [];

// Letzter Druckauftrag – zeigt, ob SumatraPDF wirklich gedruckt hat oder wo es klemmt.
$letztDruck = null;
try { $letztDruck = one("SELECT id, format, status, antwort, angelegt, erledigt FROM lg_druckjob ORDER BY id DESC LIMIT 1"); } catch (Throwable $e) {}
$druckStatusTxt = [
    'offen'     => 'wartet auf die Brücke …',
    'abgeholt'  => 'von der Brücke geholt, druckt …',
    'ok'        => 'gedruckt',
    'fehler'    => 'Fehler',
    'verfallen' => 'abgelaufen (Brücke war offline)',
];

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
      <div class="bx-field"><label>Drucker</label>
        <?php if ($druckerListe): ?>
          <select name="drucker_name">
            <option value="">Standarddrucker<?= $druckerStd !== '' ? ' (' . h($druckerStd) . ')' : '' ?></option>
            <?php $gefunden = false; foreach ($druckerListe as $p): $gefunden = $gefunden || ($p === $drucker); ?>
              <option value="<?= h($p) ?>" <?= $drucker === $p ? 'selected' : '' ?>><?= h($p) ?></option>
            <?php endforeach; ?>
            <?php if ($drucker !== '' && !$gefunden): ?><option value="<?= h($drucker) ?>" selected><?= h($drucker) ?> (nicht gefunden)</option><?php endif; ?>
          </select>
          <div class="muted" style="font-size:12px;margin-top:4px">Liste von der Brücke auf dem Lager-PC. Leer = Standarddrucker.</div>
        <?php else: ?>
          <input type="text" name="drucker_name" value="<?= h($drucker) ?>" placeholder="z. B. Zebra ZD420">
          <div class="muted" style="font-size:12px;margin-top:4px">Die Drucker-Auswahl erscheint hier, sobald die Brücke einmal lief. Solange Name von Hand eintragen (leer = Standarddrucker).</div>
        <?php endif; ?>
      </div>
    </div>
    <div style="margin-top:var(--sp-3)"><button type="submit" class="btn btn-primary">Speichern</button></div>
  </div>
</form>

<!-- Brücke + Downloads -->
<div class="bx-panel">
  <h2 style="margin-top:0">Brücke auf dem Lager-PC <span class="badge <?= $wach ? 'badge-ok' : 'badge-warn' ?>" style="margin-left:6px"><?= $wach ? 'läuft' : 'nicht aktiv' ?></span></h2>
  <p class="muted" style="margin:0 0 var(--sp-3)">Ein Programm auf einem PC im Lager. Es lässt die <strong>Blinker</strong> leuchten (an den Sender im Netz) und <strong>druckt Etiketten</strong> lautlos (SumatraPDF).</p>

  <div class="bx-panel" style="background:var(--panel-2);margin:0 0 var(--sp-4)">
    <div style="font-weight:600;margin-bottom:6px">Diagnose</div>
    <div class="bx-grid" style="gap:var(--sp-2)">
      <div><span class="muted" style="font-size:12px">Letzter Kontakt</span><br><?= $zuletztTxt !== '' ? h($zuletztTxt) . ($wach ? ' (gerade eben)' : '') : '<span class="muted">noch nie – Brücke hat sich nie gemeldet</span>' ?></div>
      <div><span class="muted" style="font-size:12px">Gemeldetes Programm</span><br><?= $programm !== '' ? h($programm) : '<span class="muted">–</span>' ?><?= $brueckeIp !== '' ? ' <span class="muted" style="font-size:12px">· von ' . h($brueckeIp) . '</span>' : '' ?></div>
    </div>
    <div style="margin-top:var(--sp-2)"><span class="muted" style="font-size:12px">Gemeldete Drucker</span><br>
      <?= $druckerListe ? h(implode(', ', $druckerListe)) : '<span class="muted">keine gemeldet – Brücke lief nicht ODER der PC hat keinen installierten Windows-Drucker</span>' ?>
    </div>
    <div style="margin-top:var(--sp-2)"><span class="muted" style="font-size:12px">Letzter Druckauftrag</span><br>
      <?php if ($letztDruck): ?>
        #<?= (int)$letztDruck['id'] ?> (<?= h((string)$letztDruck['format']) ?>) · <strong><?= h($druckStatusTxt[$letztDruck['status']] ?? (string)$letztDruck['status']) ?></strong>
        <?= !empty($letztDruck['antwort']) ? '· ' . h((string)$letztDruck['antwort']) : '' ?>
        <?= !empty($letztDruck['angelegt']) ? '<span class="muted" style="font-size:12px"> · ' . h(function_exists('fmt_zeit') ? fmt_zeit((string)$letztDruck['angelegt']) : (string)$letztDruck['angelegt']) . '</span>' : '' ?>
      <?php else: ?>
        <span class="muted">noch kein Druckauftrag</span>
      <?php endif; ?>
    </div>
    <?php if ($zuletzt === ''): ?>
      <p class="muted" style="font-size:12px;margin:var(--sp-2) 0 0">Tipp: Lade unten „mit Fenster (zum Testen)" und starte es per Doppelklick. Das Fenster zeigt sofort, ob Drucker gefunden und der Server erreicht wird.</p>
    <?php endif; ?>
  </div>
  <div class="bx-row" style="gap:var(--sp-3);flex-wrap:wrap">
    <a class="btn btn-primary" href="?p=bruecke_skript&art=hintergrund" download>Brücke einrichten (Hintergrund)</a>
    <a class="btn btn-ghost" href="?p=bruecke_skript" download>mit Fenster (zum Testen)</a>
    <a class="btn btn-ghost" href="https://www.sumatrapdfreader.org/download-free-pdf-viewer" target="_blank" rel="noopener">SumatraPDF herunterladen</a>
    <a class="btn btn-ghost" href="?p=sender">Sender &amp; Blinker einrichten</a>
  </div>
  <ol class="muted" style="margin:var(--sp-4) 0 0;padding-left:1.2em;line-height:1.7">
    <li><strong>SumatraPDF</strong> auf dem Lager-PC installieren (kostenlos, für lautlosen Druck).</li>
    <li><strong>Brücke einrichten (Hintergrund)</strong> herunterladen und per Doppelklick starten (kein Admin nötig). Das kurze schwarze Fenster richtet alles ein und startet die Brücke sofort – danach läuft sie <strong>unsichtbar im Hintergrund</strong> und startet <strong>automatisch mit Windows</strong>. Oben erscheint dann „läuft".</li>
    <li><strong>Drucker</strong> oben auswählen (die Liste erscheint, sobald die Brücke einmal lief). Unter <a href="?p=sender">Sender &amp; Blinker</a> die Sender-IP eintragen und testen. Fertig – „Direkt drucken" am Etikett druckt sofort.</li>
    <li><strong>Beenden:</strong> Task-Manager → Tab <em>Details</em> → <code>powershell.exe</code> beenden. <strong>Autostart aus:</strong> Task-Manager → Tab <em>Autostart</em> → <code>bulkify-lager-bruecke</code> deaktivieren.</li>
  </ol>
  <p class="muted" style="font-size:12px;margin:var(--sp-3) 0 0">Falls der Browser beim Herunterladen warnt: Es ist kein Virus – nur eine ganz normale Windows-Datei (.bat). Im Download-Pfeil auf „Behalten" klicken.</p>

  <div style="margin-top:var(--sp-4);padding-top:var(--sp-3);border-top:1px solid var(--line)">
    <form method="post" onsubmit="return confirm('Neuen Schlüssel erzeugen? Damit werden ALLE laufenden Brücken abgemeldet – auch versteckte auf anderen PCs. Danach die Brücke nur auf dem Lager-PC neu herunterladen und starten.');" style="display:inline">
      <input type="hidden" name="aktion" value="token_neu">
      <button type="submit" class="btn btn-ghost">Schlüssel neu erzeugen</button>
    </form>
    <span class="muted" style="font-size:12px;margin-left:var(--sp-2)">Meldet alle alten/versteckten Brücken ab. Danach die Brücke nur auf dem richtigen Lager-PC neu einrichten.</span>
  </div>
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
