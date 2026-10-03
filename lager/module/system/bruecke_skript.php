<?php
// Liefert das Brueckenprogramm zum Herunterladen - als .bat zum DOPPELKLICKEN.
// Adresse und Schluessel sind schon eingetragen. Vorlage: bruecke/bruecke.ps1.
//
// Warum eine .bat statt direkt .ps1: Ein Doppelklick auf eine .ps1 oeffnet nur den Editor
// (laeuft nicht), und "Mit PowerShell ausfuehren" wird oft von der Ausfuehrungsrichtlinie bzw.
// der "aus-dem-Internet"-Sperre blockiert. Die .bat schreibt das Skript nach %TEMP% und startet
// es mit -ExecutionPolicy Bypass - laeuft per Doppelklick zuverlaessig.
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$basis = ($https ? 'https' : 'http') . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost') . '/lager/bruecke.php';

$vorlage = (string)file_get_contents(BX_ROOT . '/bruecke/bruecke.ps1');
$skript  = strtr($vorlage, ['{{URL}}' => $basis, '{{TOKEN}}' => lg_bruecke_token()]);
$skript  = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $skript);   // saubere Windows-Zeilenenden
$b64     = base64_encode($skript);                                 // UTF-8-Bytes des PS1

// Hintergrund-Variante: eine ganz normale .bat (keine .vbs -> wird von Chrome/Defender nicht
// als "Virus" blockiert). Sie legt das PS1 dauerhaft nach %LOCALAPPDATA%\bulkify-bruecke ab und
// richtet eine Windows-Aufgabe ein, die es bei JEDER Anmeldung UNSICHTBAR startet (kein Fenster,
// Autostart inklusive). Register-ScheduledTask baut den Argument-String selbst -> keine Zitat-
// Probleme bei Pfaden mit Leerzeichen. Entfernen: Aufgabenplanung -> "bulkify Lager Bruecke".
if (($_GET['art'] ?? '') === 'hintergrund') {
    $psWrite = '$d=$env:LOCALAPPDATA+' . "'\\bulkify-bruecke'; " .
        '$null=New-Item -ItemType Directory -Force -Path $d; ' .
        "[IO.File]::WriteAllBytes(\$d+'\\bruecke.ps1',[Convert]::FromBase64String('" . $b64 . "'))";
    $psTask = '$d=$env:LOCALAPPDATA+' . "'\\bulkify-bruecke'; \$p=\$d+'\\bruecke.ps1'; " .
        "\$a=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File '+[char]34+\$p+[char]34); " .
        '$t=New-ScheduledTaskTrigger -AtLogOn; ' .
        "\$null=Register-ScheduledTask -TaskName 'bulkify Lager Bruecke' -Action \$a -Trigger \$t -Force; " .
        "Start-ScheduledTask -TaskName 'bulkify Lager Bruecke'";
    $zeilenBg = [
        '@echo off',
        'title bulkify Lager-Bruecke einrichten',
        'echo bulkify Lager-Bruecke wird eingerichtet - einen Moment bitte...',
        'echo.',
        'powershell -NoProfile -Command "' . $psWrite . '"',
        'powershell -NoProfile -ExecutionPolicy Bypass -Command "' . $psTask . '"',
        'echo.',
        'echo Fertig. Die Bruecke laeuft jetzt unsichtbar im Hintergrund',
        'echo und startet kuenftig automatisch mit Windows.',
        'echo.',
        'echo Entfernen: Aufgabenplanung oeffnen -^> "bulkify Lager Bruecke" -^> loeschen.',
        'echo.',
        'pause',
    ];
    $batBg = implode("\r\n", $zeilenBg) . "\r\n";
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="bulkify-lager-bruecke-einrichten.bat"');
    header('Cache-Control: no-store');
    echo $batBg;
    exit;
}

// .bat: PS1 nach %TEMP% schreiben, dann mit Bypass starten. ($env:TEMP ist LITERAL -> einfache Anfuehrungszeichen.)
$zeilen = [
    '@echo off',
    'title bulkify Lager-Bruecke',
    'echo bulkify Lager-Bruecke wird gestartet - dieses Fenster bitte offen lassen.',
    'echo.',
    'powershell -NoProfile -Command "[IO.File]::WriteAllBytes(' . '$env:TEMP' . "+'\\bulkify-lager-bruecke.ps1',[Convert]::FromBase64String('" . $b64 . "'))\"",
    'powershell -NoProfile -ExecutionPolicy Bypass -File "%TEMP%\\bulkify-lager-bruecke.ps1"',
    'echo.',
    'echo Bruecke beendet. Taste druecken zum Schliessen.',
    'pause >nul',
];
$bat = implode("\r\n", $zeilen) . "\r\n";

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="bulkify-lager-bruecke.bat"');
header('Cache-Control: no-store');
echo $bat;
exit;
