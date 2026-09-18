<?php
// TEMPORÄR (v3-Migration): v3-SQL-Dump im Browser hochladen und den bewährten Importer
// (tools/v3_import.php) darauf laufen lassen – ohne Kommandozeile.
// Ablauf: 1) .sql in eine SEPARATE v3-DB laden  2) Trockenlauf ansehen  3) Import schreiben.
// NACH Abschluss der Migration alles entfernen (siehe Memory v3-reparatur-temporaer-loeschen).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if (!has_role('admin')) { render_header('', 'Kein Zugriff'); echo '<div class="bx-panel">Nur für Admins.</div>'; render_footer(); exit; }

// Ziel-DB (separat von der App-DB!). Nur a-z0-9_ zulassen. Default v3import.
$target = preg_replace('/[^a-z0-9_]/i', '', (string)($_POST['db'] ?? $_GET['db'] ?? 'v3import')) ?: 'v3import';
if (strcasecmp($target, DB_NAME) === 0) { $target = 'v3import'; }   // niemals in die App-DB

// mysqli-Verbindung (für Dump laden). Ohne db = Serverebene (CREATE DATABASE).
function v3imp_connect(?string $db): array {
    if (!class_exists('mysqli')) return [null, 'Die PHP-Erweiterung „mysqli" fehlt auf diesem Server.'];
    mysqli_report(MYSQLI_REPORT_OFF);
    $m = @new mysqli(DB_HOST, DB_USER, DB_PASS, $db ?? '', (int)DB_PORT);
    if ($m->connect_errno) return [null, 'DB-Verbindung fehlgeschlagen: ' . $m->connect_error];
    $m->set_charset('utf8mb4');
    return [$m, ''];
}

$hinweis = ''; $fehler = ''; $importOut = '';

// ---- 1) Dump hochladen + in die Ziel-DB laden ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'upload') {
    if (empty($_FILES['sql']['tmp_name']) || !is_uploaded_file($_FILES['sql']['tmp_name'])) {
        $fehler = 'Keine Datei erhalten.';
    } else {
        $sql = file_get_contents($_FILES['sql']['tmp_name']);
        if ($sql === false || trim($sql) === '') { $fehler = 'Datei ist leer oder nicht lesbar.'; }
        else {
            // Zeilen entfernen, die eine andere DB anlegen/auswählen würden – wir laden immer in $target.
            $sql = preg_replace('/^\s*(CREATE\s+DATABASE|USE)\b[^\n;]*;?\s*$/im', '', $sql);
            // Ziel-DB anlegen (falls der App-User das darf) + auswählen
            [$srv, $e1] = v3imp_connect(null);
            if (!$srv) { $fehler = $e1; }
            else {
                @$srv->query("CREATE DATABASE IF NOT EXISTS `$target` CHARACTER SET utf8mb4");
                $srv->close();
                [$db, $e2] = v3imp_connect($target);
                if (!$db) { $fehler = $e2 . ' – DB „' . h($target) . '" muss existieren und dem App-User gehören (einmalig per SSH anlegen + Rechte geben).'; }
                else {
                    // Ganzen Dump in einem Rutsch (mehrere Statements). Fehler je Statement einsammeln.
                    $ok = $db->multi_query($sql);
                    $stmts = 0; $err = '';
                    if ($ok) { do { $stmts++; if ($r = $db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result()); }
                    if ($db->errno) $err = $db->error;
                    $tabellen = (int) ($db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" . $db->real_escape_string($target) . "'")->fetch_row()[0] ?? 0);
                    $db->close();
                    if ($err) $fehler = 'Beim Laden trat ein Fehler auf: ' . h($err);
                    else $hinweis = 'Dump geladen in DB „' . h($target) . '" – ' . $tabellen . ' Tabellen. Jetzt unten den Trockenlauf prüfen.';
                }
            }
        }
    }
}

// ---- 2/3) Importer (tools/v3_import.php) auf die Ziel-DB laufen lassen (Trockenlauf oder schreiben) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['aktion'] ?? '', ['dry', 'write'], true)) {
    // Vorprüfung: erreichbar + hat die Kern-Tabelle „kunden"?
    [$db, $e] = v3imp_connect($target);
    if (!$db) { $fehler = $e; }
    else {
        $hat = @$db->query("SELECT 1 FROM `kunden` LIMIT 1");
        $db->close();
        if (!$hat) { $fehler = 'In DB „' . h($target) . '" ist kein v3-Stand (Tabelle „kunden" fehlt). Erst oben den Dump hochladen.'; }
        else {
            @set_time_limit(0);
            $write = ($_POST['aktion'] === 'write');
            // Den CLI-Importer UNVERÄNDERT nutzen: $argv synthetisieren + Ausgabe abfangen.
            $argv = ['v3_import.php', $target]; if ($write) $argv[] = '--write'; $argc = count($argv);
            ob_start();
            try { include BX_ROOT . '/tools/v3_import.php'; }
            catch (Throwable $ex) { echo "\n\nABBRUCH: " . $ex->getMessage() . "\n"; }
            $importOut = ob_get_clean();
        }
    }
}

// Aktueller Zustand der Ziel-DB (für die Anzeige)
$zielTabellen = 0; $zielKunden = -1;
[$dbi, $ei] = v3imp_connect($target);
if ($dbi) {
    $zielTabellen = (int) ($dbi->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" . $dbi->real_escape_string($target) . "'")->fetch_row()[0] ?? 0);
    if ($r = @$dbi->query("SELECT COUNT(*) FROM `kunden`")) { $zielKunden = (int)$r->fetch_row()[0]; }
    $dbi->close();
}

render_header('angebote', 'v3 neu einlesen (Upload)');
bx_head('v3 neu einlesen (Upload)',
        'v3-SQL-Export hochladen und den Import laufen lassen – ohne Kommandozeile. Einmalige Migration.',
        bx_btn('Zur Angebotsliste', '?p=angebote', 'ghost'));

if ($fehler)  echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . $fehler . '</div>';
if ($hinweis) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . $hinweis . '</div>';
?>
<div class="bx-panel">
  <h2 style="margin-top:0">1 · v3-Export hochladen</h2>
  <p class="muted" style="margin-top:0">In der alten Software (phpMyAdmin) die v3-Datenbank <strong>Exportieren → SQL</strong> und die <code>.sql</code>-Datei hier hochladen. Sie wird in eine <strong>separate</strong> Datenbank geladen – die App-Daten bleiben unberührt.</p>
  <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="upload">
    <div class="bx-field" style="margin:0;width:180px"><label>Ziel-DB</label><input type="text" name="db" value="<?= h($target) ?>"></div>
    <div class="bx-field" style="margin:0"><label>SQL-Datei</label><input type="file" name="sql" accept=".sql,text/plain" required></div>
    <button class="btn btn-primary" type="submit" data-busy="Lade …">Hochladen &amp; laden</button>
  </form>
  <div class="muted" style="font-size:12px;margin-top:10px">Ziel-DB aktuell: <strong><?= h($target) ?></strong> · <?= $zielTabellen ?> Tabellen<?= $zielKunden >= 0 ? ' · v3-Kunden: ' . $zielKunden : ' · noch kein v3-Stand' ?>.
    Existiert die DB nicht/ohne Rechte, einmalig per SSH: <code>CREATE DATABASE <?= h($target) ?>;</code> + <code>GRANT ALL ON <?= h($target) ?>.* TO '&lt;app-user&gt;'@'localhost';</code></div>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">2 · Trockenlauf &amp; Import</h2>
  <?php if ($zielKunden < 0): ?>
    <div class="muted">Erst oben einen v3-Export hochladen.</div>
  <?php else: ?>
    <div class="bx-row" style="gap:10px;flex-wrap:wrap">
      <form method="post" style="margin:0"><input type="hidden" name="aktion" value="dry"><input type="hidden" name="db" value="<?= h($target) ?>">
        <button class="btn btn-ghost" type="submit" data-busy="Prüfe …">Trockenlauf (nur anzeigen)</button></form>
      <form method="post" style="margin:0" onsubmit="return confirm('v3-Import jetzt SCHREIBEN? Bestehende Angebote/Kunden werden über v3_id aktualisiert (idempotent). Vorher eine Sicherung der App-DB anlegen!');">
        <input type="hidden" name="aktion" value="write"><input type="hidden" name="db" value="<?= h($target) ?>">
        <button class="btn btn-primary" type="submit" data-busy="Importiere …">Import schreiben (--write)</button></form>
    </div>
    <p class="muted" style="font-size:12px;margin-top:8px">Idempotent über <code>v3_id</code> – beliebig oft wiederholbar. Empfehlung: vor dem Schreiben eine Sicherung der App-DB ziehen.</p>
  <?php endif; ?>
  <?php if ($importOut !== ''): ?>
    <pre style="margin-top:12px;max-height:520px;overflow:auto;background:var(--panel-2);border:1px solid var(--line);border-radius:8px;padding:12px;font-size:12px;white-space:pre-wrap"><?= h($importOut) ?></pre>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
