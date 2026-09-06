<?php
// Einen kompletten mysqldump (.sql) über die PDO-Verbindung einspielen.
//
// Warum eigener Splitter statt PDO::exec($ganzeDatei): PDO/mysqlnd führt zwar
// mehrere Statements aus, meldet Fehler späterer Statements aber nicht sauber.
// Deshalb zerlegen wir den Dump quote-bewusst in Einzel-Statements und führen
// jedes einzeln aus – so bekommen wir Anzahl + erste Fehlermeldung zurück.
//
// Genutzt von module/system/db_import.php (Web, Admin) und vom CLI-Test.

// Einen SQL-Text in Einzel-Statements zerlegen. Beachtet '..' ".." `..` mit
// Backslash- und Doppel-Quote-Escapes, überspringt --Zeilen und /* */-Blöcke
// (auch die /*!... */-Optimizer-Hints von mysqldump; die brauchen wir nicht,
// FOREIGN_KEY_CHECKS/sql_mode setzen wir selbst).
function sql_split(string $sql): array {
    $stmts = [];
    $buf = '';
    $len = strlen($sql);
    $i = 0;
    $inStr = false; $q = '';
    while ($i < $len) {
        $c = $sql[$i];
        if ($inStr) {
            $buf .= $c;
            if ($c === '\\' && $i + 1 < $len) { $buf .= $sql[$i + 1]; $i += 2; continue; }
            if ($c === $q) {
                if ($i + 1 < $len && $sql[$i + 1] === $q) { $buf .= $sql[$i + 1]; $i += 2; continue; } // '' = escaptes Quote
                $inStr = false;
            }
            $i++; continue;
        }
        // außerhalb eines Strings
        if ($c === "'" || $c === '"' || $c === '`') { $inStr = true; $q = $c; $buf .= $c; $i++; continue; }
        if ($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-' && ($i === 0 || $sql[$i - 1] === "\n")) {
            $nl = strpos($sql, "\n", $i); if ($nl === false) break; $i = $nl + 1; continue;   // --Zeilenkommentar
        }
        if ($c === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
            $end = strpos($sql, '*/', $i + 2); if ($end === false) break; $i = $end + 2; continue; // /* */-Block
        }
        if ($c === ';') { $s = trim($buf); if ($s !== '') $stmts[] = $s; $buf = ''; $i++; continue; }
        $buf .= $c; $i++;
    }
    $s = trim($buf); if ($s !== '') $stmts[] = $s;
    return $stmts;
}

// Dump einspielen. Gibt zurück: ['stmts'=>int, 'ok'=>int, 'fehler'=>[...], 'abbruch'=>bool].
// FK-Prüfung und strenger sql_mode werden für die Dauer des Imports abgeschaltet,
// damit DROP/CREATE-Reihenfolge und Alt-Daten (z. B. 0000-00-00) nicht querschlagen.
function db_import_sql(PDO $pdo, string $sql): array {
    // MariaDB-Sandbox-Direktive am Dateianfang wegnehmen (sonst Syntaxfehler auf manchen Servern).
    $sql = preg_replace('~/\*M!999999.*?\*/~s', '', $sql);
    $stmts = sql_split($sql);
    $res = ['stmts' => count($stmts), 'ok' => 0, 'fehler' => [], 'abbruch' => false];

    $altMode = null;
    try { $altMode = $pdo->query("SELECT @@SESSION.sql_mode")->fetchColumn(); } catch (\Throwable $e) {}
    try { $pdo->exec("SET SESSION sql_mode=''"); } catch (\Throwable $e) {}
    try { $pdo->exec("SET FOREIGN_KEY_CHECKS=0"); } catch (\Throwable $e) {}

    foreach ($stmts as $st) {
        try {
            $pdo->exec($st);
            $res['ok']++;
        } catch (\Throwable $e) {
            $res['fehler'][] = ['sql' => mb_substr($st, 0, 160), 'meldung' => $e->getMessage()];
            if (count($res['fehler']) >= 25) { $res['abbruch'] = true; break; }  // Notbremse bei kaputter Datei
        }
    }

    try { $pdo->exec("SET FOREIGN_KEY_CHECKS=1"); } catch (\Throwable $e) {}
    if ($altMode !== null) { try { $pdo->exec("SET SESSION sql_mode=" . $pdo->quote((string)$altMode)); } catch (\Throwable $e) {} }
    return $res;
}
