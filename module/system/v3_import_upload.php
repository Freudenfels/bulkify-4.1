<?php
// TEMPORÄR (v3-Migration): v3-SQL-Dump hochladen und importieren – EIN Klick, keine Konfiguration.
// Die v3-Tabellen werden intern mit Prefix „v3imp_" in die App-DB geladen (kollidieren also NICHT mit
// den echten Tabellen), und der bewährte tools/v3_import.php liest sie über eine Prefix-PDO und
// schreibt das Ergebnis sauber nach v4. NACH Abschluss der Migration wieder entfernen.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if (!has_role('admin')) { render_header('einstellungen', 'v3 neu einlesen'); echo '<div class="bx-panel">Nur für Admins.</div>'; render_footer(); exit; }

// PDO, die v3-Quelltabellen automatisch auf den Prefix „v3imp_" umschreibt (nur Lesezugriffe des Importers).
class V3PrefixPDO extends PDO {
    private array $tabs = ['produktanfrage_staffel','produktanfrage','rezept_zutaten','rezept_kunde','rezepte',
                           'auftraege','bestellungen','lieferanten','preisliste','rohstoffe','lieferant_angebot','kunden'];
    private function rw(string $sql): string {
        foreach ($this->tabs as $t) $sql = preg_replace('/\b(FROM|JOIN)\s+`?' . $t . '`?\b/i', '$1 v3imp_' . $t, $sql);
        return $sql;
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false { return parent::query($this->rw($query), $fetchMode, ...$args); }
    public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare($this->rw($query), $options); }
}

function v3imp_pdo(): PDO {
    $p = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $p;
}
// Anzahl bereits geladener v3-Tabellen (v3imp_*)
function v3imp_geladen(): int {
    return (int) scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name LIKE 'v3imp\\_%'", [DB_NAME]);
}

$fehler = ''; $hinweis = ''; $out = ''; $geschrieben = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';

    if ($aktion === 'upload') {
        if (empty($_FILES['sql']['tmp_name']) || !is_uploaded_file($_FILES['sql']['tmp_name'])) $fehler = 'Keine .sql-Datei erhalten (oder größer als das Upload-Limit des Servers).';
        else {
            $sql = file_get_contents($_FILES['sql']['tmp_name']);
            // .sql.gz automatisch entpacken (praktisch bei kleinem Upload-Limit: 3 MB -> ~0,5 MB).
            if ($sql !== false && (strtolower(substr((string)($_FILES['sql']['name'] ?? ''), -3)) === '.gz' || substr($sql, 0, 2) === "\x1f\x8b")) {
                $dec = @gzdecode($sql); if ($dec !== false) $sql = $dec;
            }
            if ($sql === false || trim($sql) === '') $fehler = 'Datei leer oder nicht lesbar.';
            elseif (!class_exists('mysqli')) $fehler = 'PHP-Erweiterung „mysqli" fehlt auf dem Server.';
            else {
                // DB-Auswahl raus, Kollation normieren, alle Tabellennamen auf v3imp_ umschreiben (nur nach TABLE/INTO/LOCK).
                $sql = preg_replace('/^\s*(CREATE\s+DATABASE|USE)\b[^\n;]*;?\s*$/im', '', $sql);
                $sql = preg_replace('/utf8mb4_uca1400\w*/i', 'utf8mb4_unicode_ci', $sql);
                // Fremdschlüssel entfernen – die Zwischentabellen brauchen keine referentielle Integrität.
                // phpMyAdmin legt FKs als eigene „ALTER TABLE `t` ADD CONSTRAINT `..` FOREIGN KEY .. ;" ans
                // Dateiende; die komplett entfernen. Zusätzlich der Fallback für inline-FKs in CREATE TABLE.
                $sql = preg_replace('/ALTER TABLE\s+`[^`]+`[^;]*FOREIGN KEY[^;]*;/is', '', $sql);
                $sql = preg_replace('/^\s*(ADD\s+)?CONSTRAINT\s+`[^`]+`\s+FOREIGN KEY.*$\n?/im', '', $sql);
                $sql = preg_replace('/^\s*FOREIGN KEY\s*\(.*$\n?/im', '', $sql);
                $sql = preg_replace('/,(\s*\n\s*)\)(\s*ENGINE=)/i', '$1)$2', $sql);   // evtl. hängendes Komma vor der Klammer
                // Alle Tabellennamen auf den Prefix v3imp_ umschreiben (nur nach TABLE/INTO/LOCK).
                $sql = preg_replace('/\b(DROP TABLE IF EXISTS|CREATE TABLE|ALTER TABLE|TRUNCATE TABLE|INSERT INTO|REPLACE INTO|LOCK TABLES)\s+`([^`]+)`/i', '$1 `v3imp_$2`', $sql);
                $sql = "SET FOREIGN_KEY_CHECKS=0;\n" . $sql;
                mysqli_report(MYSQLI_REPORT_OFF);
                $db = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
                if ($db->connect_errno) $fehler = 'DB-Verbindung fehlgeschlagen: ' . $db->connect_error;
                else {
                    $db->set_charset('utf8mb4');
                    // Sauberer Neustart: einen evtl. halb geladenen v3imp_-Stand vorher wegräumen
                    // (Exporte ohne „DROP TABLE" würden sonst an „already exists" scheitern).
                    $db->query("SET FOREIGN_KEY_CHECKS=0");
                    if ($alt = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema='" . $db->real_escape_string(DB_NAME) . "' AND table_name LIKE 'v3imp\\_%'")) {
                        while ($row = $alt->fetch_row()) $db->query('DROP TABLE IF EXISTS `' . $row[0] . '`');
                        $alt->free();
                    }
                    $ok = $db->multi_query($sql); $err = '';
                    if ($ok) { do { if ($r = $db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result()); }
                    if ($db->errno) $err = $db->error;
                    $db->close();
                    if ($err) $fehler = 'Beim Laden trat ein Fehler auf: ' . h($err);
                    else $hinweis = 'v3-Export geladen (' . v3imp_geladen() . ' Tabellen). Jetzt unten den Trockenlauf prüfen.';
                }
            }
        }
    }

    if (in_array($aktion, ['dry', 'write'], true)) {
        if (v3imp_geladen() === 0 || (int) scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name='v3imp_kunden'", [DB_NAME]) === 0) {
            $fehler = 'Es ist noch kein v3-Stand geladen – erst oben den Export hochladen.';
        } else {
            @set_time_limit(0);
            try {
                $GLOBALS['V3_PDO'] = new V3PrefixPDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
                $argv = ['v3_import.php', DB_NAME]; if ($aktion === 'write') $argv[] = '--write'; $argc = count($argv);
                ob_start();
                try { include BX_ROOT . '/tools/v3_import.php'; }
                catch (\Throwable $ex) { echo "\n\nABBRUCH: " . $ex->getMessage() . "\n"; }
                $out = ob_get_clean();
                if ($aktion === 'write' && strpos($out, 'ABBRUCH') === false) $geschrieben = true;
            } catch (\Throwable $e) { $fehler = 'Import nicht möglich: ' . h($e->getMessage()); }
            unset($GLOBALS['V3_PDO']);
        }
    }

    if ($aktion === 'aufraeumen') {
        foreach (all("SELECT table_name FROM information_schema.tables WHERE table_schema=? AND table_name LIKE 'v3imp\\_%'", [DB_NAME]) as $t) {
            try { db()->exec('DROP TABLE IF EXISTS `' . $t['table_name'] . '`'); } catch (\Throwable $e) {}
        }
        $hinweis = 'Geladener v3-Zwischenstand (v3imp_-Tabellen) entfernt.';
    }
}

$geladen = v3imp_geladen();

render_header('einstellungen', 'v3 neu einlesen (Upload)');
bx_head('v3 neu einlesen (Upload)', 'v3-SQL-Export hochladen und importieren. Einmalige Migration.', bx_btn('Zurück', '?p=einstellungen', 'ghost'));
if ($fehler)  echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . $fehler . '</div>';
if ($hinweis) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . $hinweis . '</div>';
if ($geschrieben) echo '<div class="bx-panel badge-ok" style="padding:14px 16px"><strong>Import abgeschlossen.</strong> Nichts wurde gelöscht – Bestehendes wurde über <code>v3_id</code> aktualisiert, Neues angelegt. Details in der Ausgabe unten (Blöcke „GESCHRIEBEN …").</div>';

// Abgleich v3-Quelle (geladene v3imp_-Tabellen) vs. unser Stand (v4) – Bestätigung, dass alles drin ist.
if ($geladen > 0):
    $c = fn($q) => (int) scalar($q);
    $rezV3    = $c("SELECT COUNT(*) FROM v3imp_rezepte");
    $rezImp   = $c("SELECT COUNT(*) FROM v3imp_rezepte WHERE kunde_id NOT IN (SELECT id FROM v3imp_kunden WHERE intern=1)");
    $rezV4    = $c("SELECT COUNT(*) FROM rezeptur WHERE v3_id IS NOT NULL");
    $anfV3    = $c("SELECT COUNT(*) FROM v3imp_produktanfrage");
    $angV4    = $c("SELECT COUNT(*) FROM angebot WHERE v3_id IS NOT NULL");
    $aufV3    = $c("SELECT COUNT(*) FROM v3imp_auftraege");
    $aufV4    = $c("SELECT COUNT(*) FROM auftrag WHERE v3_id IS NOT NULL");
    $ok = fn($b) => $b ? '<span class="badge badge-ok">vollständig</span>' : '<span class="muted">–</span>';
?>
<div class="bx-panel">
  <h2 style="margin-top:0">Abgleich: v3-Quelle ↔ bei uns</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Typ</th><th class="bx-num">v3-Export</th><th class="bx-num">bei uns (v4)</th><th>Status</th></tr></thead>
    <tbody>
      <tr><td>Rezepturen</td><td class="bx-num"><?= $rezV3 ?><?= $rezV3 !== $rezImp ? ' <span class="muted" style="font-size:11px">(importierbar ' . $rezImp . ', ohne internen Kunden)</span>' : '' ?></td><td class="bx-num"><?= $rezV4 ?></td><td><?= $ok($rezV4 >= $rezImp) ?></td></tr>
      <tr><td>Aufträge</td><td class="bx-num"><?= $aufV3 ?></td><td class="bx-num"><?= $aufV4 ?></td><td><?= $ok($aufV4 >= $aufV3 - 2) ?></td></tr>
      <tr><td>Angebote</td><td class="bx-num"><?= $anfV3 ?> <span class="muted" style="font-size:11px">Anfragen</span></td><td class="bx-num"><?= $angV4 ?></td><td><span class="muted" style="font-size:12px">nur Anfragen mit Preis/Bestätigung werden zum Angebot</span></td></tr>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px;margin:8px 0 0">Bewusst nicht importiert: der <strong>interne Kunde</strong> (Lagerproduktion) und <strong>reine Anfragen ohne Preis</strong>. Deshalb sind „bei uns" bei Angeboten weniger als die reinen Anfragen – das ist korrekt, kein Verlust.</p>
</div>
<?php endif; ?>
<div class="bx-panel">
  <h2 style="margin-top:0">1 · v3-Export hochladen</h2>
  <p class="muted" style="margin-top:0">In der alten Software (phpMyAdmin) die v3-Datenbank <strong>Exportieren → SQL</strong> und die <code>.sql</code> hier hochladen. Deine App-Daten bleiben unberührt – die v3-Daten werden intern getrennt gehalten.</p>
  <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="upload">
    <div class="bx-field" style="margin:0"><label>SQL-Datei <?= bx_hint('.sql oder .sql.gz (gepackt, falls das Upload-Limit klein ist)') ?></label><input type="file" name="sql" accept=".sql,.gz,.sql.gz,text/plain,application/gzip" required></div>
    <button class="btn btn-primary" type="submit" data-busy="Lade …">Hochladen</button>
  </form>
  <div class="muted" style="font-size:12px;margin-top:8px">Status: <?= $geladen > 0 ? '<strong>v3-Stand geladen</strong> (' . $geladen . ' Tabellen)' : 'noch kein v3-Stand geladen' ?> · Upload-Limit: <?= h((string)ini_get('upload_max_filesize')) ?>.</div>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">2 · Trockenlauf &amp; Import</h2>
  <?php if ($geladen === 0): ?>
    <div class="muted">Erst oben den v3-Export hochladen.</div>
  <?php else: ?>
    <div class="bx-row" style="gap:10px;flex-wrap:wrap">
      <form method="post" style="margin:0"><input type="hidden" name="aktion" value="dry"><button class="btn btn-ghost" type="submit" data-busy="Prüfe …">Trockenlauf (nur anzeigen)</button></form>
      <form method="post" style="margin:0" onsubmit="return confirm('v3-Import jetzt SCHREIBEN? Bestehende Vorgänge werden über v3_id aktualisiert (idempotent). Vorher die App-DB sichern!');">
        <input type="hidden" name="aktion" value="write"><button class="btn btn-primary" type="submit" data-busy="Importiere …">Import schreiben</button></form>
      <form method="post" style="margin:0" onsubmit="return confirm('Den geladenen v3-Zwischenstand (v3imp_-Tabellen) entfernen?');">
        <input type="hidden" name="aktion" value="aufraeumen"><button class="btn btn-ghost btn-sm" type="submit">v3-Zwischenstand entfernen</button></form>
    </div>
    <p class="muted" style="font-size:12px;margin-top:8px">Idempotent über <code>v3_id</code> – beliebig wiederholbar. Empfehlung: vor dem Schreiben eine Sicherung der App-DB.</p>
  <?php endif; ?>
  <?php if ($out !== ''): ?>
    <pre style="margin-top:12px;max-height:520px;overflow:auto;background:var(--panel-2);border:1px solid var(--line);border-radius:8px;padding:12px;font-size:12px;white-space:pre-wrap"><?= h($out) ?></pre>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
