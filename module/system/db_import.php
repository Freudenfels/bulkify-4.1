<?php
// Einmalige Datenübernahme: einen kompletten DB-Dump (.sql) hochladen und einspielen.
// Gedacht, um die lokal aufgebaute Datenbank (Rohstoffe, Kunden, Produkte …) auf einen
// anderen Stand zu bringen – z. B. von diesem Laptop auf beta – OHNE phpMyAdmin/SSH.
//
// ACHTUNG: ersetzt die komplette Datenbank dieses Servers (alle Tabellen aus dem Dump
// werden neu angelegt). Nur für Admins, und nur nach Eingabe des Bestätigungsworts.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/db_import.php';

// Harte Zusatz-Sperre: nur Admin. (Die Route ist ohnehin nicht öffentlich und unbekannte
// Routen sind Admin-only – hier trotzdem explizit, weil die Seite die DB ersetzen kann.)
if (!has_role('admin')) {
    render_header('einstellungen', 'Datenübernahme');
    bx_head('Datenübernahme', '', bx_btn('Zurück', '?p=einstellungen', 'ghost'));
    echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:16px">Diese Seite ist nur für Administratoren.</div>';
    render_footer();
    return;
}

$BESTAETIGUNG = 'ERSETZEN';
$ergebnis = null;       // Import-Ergebnis nach dem Lauf
$fehler   = '';
$dateien  = 0;          // eingespielte Upload-Dateien

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'import') {
    $wort = trim((string)($_POST['bestaetigung'] ?? ''));
    if ($wort !== $BESTAETIGUNG) {
        $fehler = 'Bitte zur Bestätigung genau ' . $BESTAETIGUNG . ' eintippen.';
    } elseif (empty($_FILES['dump']['tmp_name']) || !is_uploaded_file($_FILES['dump']['tmp_name'])) {
        $fehler = 'Keine .sql-Datei ausgewählt (oder sie ist größer als das Upload-Limit des Servers).';
    } else {
        $sql = file_get_contents($_FILES['dump']['tmp_name']);
        if ($sql === false || trim($sql) === '') {
            $fehler = 'Die Datei ließ sich nicht lesen oder ist leer.';
        } else {
            $ergebnis = db_import_sql(db(), $sql);
            // Nach dem Ersetzen: Schema additiv nachziehen, falls der Server-Code Spalten
            // erwartet, die der Dump noch nicht kennt (init_schema ist idempotent).
            try { init_schema(); } catch (\Throwable $e) {}

            // Optional: Upload-Dateien (Spec/CoA-PDFs) aus einer ZIP mit einspielen.
            if (!empty($_FILES['uploads']['tmp_name']) && is_uploaded_file($_FILES['uploads']['tmp_name']) && class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($_FILES['uploads']['tmp_name']) === true) {
                    if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $nm = (string)$zip->getNameIndex($i);
                        if ($nm === '' || substr($nm, -1) === '/') continue;   // Verzeichnisse überspringen
                        $base = basename($nm);
                        if ($base === '') continue;
                        $data = $zip->getFromIndex($i);
                        if ($data !== false && @file_put_contents(BX_UPLOADS . '/' . $base, $data) !== false) $dateien++;
                    }
                    $zip->close();
                }
            }
        }
    }
}

// PHP-Upload-Grenzen zur Info (damit klar ist, falls eine Datei zu groß ist)
$maxUpload = ini_get('upload_max_filesize');
$maxPost   = ini_get('post_max_size');

render_header('einstellungen', 'Datenübernahme');
bx_head('Datenübernahme (DB-Import)', 'kompletten Datenbank-Stand einspielen', bx_btn('Zurück', '?p=einstellungen', 'ghost'));

if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:14px 16px">' . h($fehler) . '</div>';

if ($ergebnis !== null && !$fehler) {
    $anzFehler = count($ergebnis['fehler']);
    $okKlasse = $anzFehler === 0 ? 'badge-ok' : '';
    echo '<div class="bx-panel ' . $okKlasse . '" style="padding:14px 16px">';
    if ($anzFehler === 0) {
        echo 'Import abgeschlossen: <strong>' . (int)$ergebnis['ok'] . '</strong> von ' . (int)$ergebnis['stmts'] . ' Anweisungen ausgeführt, keine Fehler.';
    } else {
        echo 'Import mit <strong>' . $anzFehler . ' Fehler(n)</strong>: ' . (int)$ergebnis['ok'] . ' von ' . (int)$ergebnis['stmts'] . ' Anweisungen ausgeführt.';
        if (!empty($ergebnis['abbruch'])) echo ' Nach zu vielen Fehlern abgebrochen – ist es wirklich ein bulkify-Dump?';
    }
    if ($dateien > 0) echo '<br>' . (int)$dateien . ' Upload-Datei(en) eingespielt.';
    // Kurzer Ist-Stand nach dem Import
    try {
        $rs = (int) scalar("SELECT COUNT(*) FROM item WHERE kategorie='rohstoff'");
        $ku = (int) scalar("SELECT COUNT(*) FROM kunden");
        $pr = (int) scalar("SELECT COUNT(*) FROM produkt");
        echo '<div class="muted" style="margin-top:8px">Jetzt in der Datenbank: ' . $rs . ' Rohstoffe · ' . $ku . ' Kunden · ' . $pr . ' Produkte.</div>';
    } catch (\Throwable $e) {}
    echo '</div>';
    if ($anzFehler > 0) {
        echo '<div class="bx-panel"><div style="font-weight:600;margin-bottom:8px">Erste Fehler</div><div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Meldung</th><th>Anweisung (Anfang)</th></tr></thead><tbody>';
        foreach (array_slice($ergebnis['fehler'], 0, 15) as $f) {
            echo '<tr><td>' . h($f['meldung']) . '</td><td class="muted" style="font-size:12px">' . h($f['sql']) . '…</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }
}
?>
<div class="bx-panel" style="border-color:#e6c4c0;background:#fdf4f2">
  <div style="font-weight:600;margin-bottom:6px;color:#8f231b">Das ersetzt die komplette Datenbank dieses Servers</div>
  <div class="muted" style="line-height:1.6">
    Alle Tabellen aus dem Dump werden gelöscht und neu angelegt – inklusive der <em>Logins</em>.
    Nach dem Import gilt hier der Anmeldestand aus dem Dump. Wenn auf diesem Server Daten liegen,
    die erhalten bleiben müssen, brich hier ab und lass dir einen Dump ohne Logins erstellen.
    Mach das nur, wenn du den Dump von der richtigen Quelle hast.
  </div>
</div>

<form method="post" enctype="multipart/form-data" class="bx-form">
  <input type="hidden" name="aktion" value="import">
  <div class="bx-panel"><div class="bx-grid">
    <div class="bx-field">
      <label>Datenbank-Dump (.sql) <?= bx_hint('Die Datei bulkify41_JJJJMMTT_HHMM.sql aus data/exports/ dieses Rechners.') ?></label>
      <input type="file" name="dump" accept=".sql,text/plain" required>
    </div>
    <div class="bx-field">
      <label>Upload-Dateien (optional, .zip) <?= bx_hint('ZIP mit den PDFs aus data/uploads/ (Spec/CoA). Ohne diese fehlen nur die hinterlegten Dokumentdateien, nicht die Daten.') ?></label>
      <input type="file" name="uploads" accept=".zip,application/zip">
    </div>
    <div class="bx-field">
      <label>Zur Bestätigung <?= $BESTAETIGUNG ?> eintippen</label>
      <input type="text" name="bestaetigung" autocomplete="off" placeholder="<?= $BESTAETIGUNG ?>" required>
    </div>
  </div>
  <div class="muted" style="font-size:12px;margin-top:4px">Upload-Limit dieses Servers: <?= h((string)$maxUpload) ?> (Post: <?= h((string)$maxPost) ?>).</div>
  </div>
  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit" data-busy="Import läuft – bitte warten…">Jetzt einspielen</button>
    <a class="btn btn-ghost" href="?p=einstellungen">Abbrechen</a>
  </div>
</form>

<div class="bx-panel">
  <div style="font-weight:600;margin-bottom:6px">So kommst du an die Dump-Datei</div>
  <div class="muted" style="line-height:1.7">
    Auf dem Quell-Rechner liegt sie unter <code>data/exports/</code> (z. B. <code>bulkify41_20260906_0754.sql</code>).
    Ist sie älter, erzeugt dieser Befehl eine frische:
    <div style="margin-top:6px"><code>mysqldump -u root -p --single-transaction --no-tablespaces --add-drop-table bulkify41 &gt; data/exports/bulkify41.sql</code></div>
    Für die Dokument-PDFs den Ordner <code>data/uploads/</code> als ZIP packen und oben mit hochladen.
  </div>
</div>
<?php
render_footer();
