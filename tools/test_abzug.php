<?php
// Abzug live -> test von der Kommandozeile (Testumgebung). Nur in der Testumgebung lauffähig (secrets.php mit
// define('BX_TESTUMGEBUNG', true) und LIVE_DB_*). Schreibt nur in die eigene (Test-)Datenbank.
// Aufruf:  php tools/test_abzug.php            Tabellen + fehlende Dateien
//          php tools/test_abzug.php --ohne-dateien
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/test_abzug.php';

if ($s = ta_sperre()) { fwrite(STDERR, "Abzug gesperrt: $s\n"); exit(1); }
@set_time_limit(0);
$start = microtime(true); $summe = 0;
foreach (ta_tabellen() as $t) {
    try { $n = ta_tabelle($t); $summe += $n; echo str_pad($t, 40) . " $n\n"; }
    catch (Throwable $e) { fwrite(STDERR, "FEHLER $t: " . $e->getMessage() . "\n"); }
}
require_once __DIR__ . '/../core/schema.php';
init_schema();   // Ergänzungen des aktuellen Codes (ensure_column) auf den frischen Stand
ta_fertig();
if (!in_array('--ohne-dateien', $argv, true)) { $r = ta_dateien(1000000); echo 'Dateien: ' . $r['msg'] . "\n"; }
printf("Fertig: %d Zeilen in %.0f s\n", $summe, microtime(true) - $start);
