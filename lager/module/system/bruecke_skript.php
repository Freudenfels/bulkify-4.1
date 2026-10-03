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

// Hintergrund-Variante: eine ganz normale .bat (keine .vbs -> kein Chrome-Virusblock).
// OHNE Admin-Rechte: legt das PS1 dauerhaft nach %LOCALAPPDATA%\bulkify-bruecke ab, traegt den
// Autostart in den HKCU-Run-Key ein (nur dieser Benutzer, kein Admin noetig) und startet die
// Bruecke sofort versteckt. Register-ScheduledTask faellt hier aus, weil das Admin braucht.
if (($_GET['art'] ?? '') === 'hintergrund') {
    // Ein einziger PowerShell-Aufruf: Ordner anlegen, PS1 schreiben, Autostart in HKCU setzen.
    // [char]34 statt \" -> keine Zitat-Probleme bei Pfaden mit Leerzeichen.
    $psSetup = '$d=$env:LOCALAPPDATA+' . "'\\bulkify-bruecke'; " .
        '$null=New-Item -ItemType Directory -Force -Path $d; ' .
        "[IO.File]::WriteAllBytes(\$d+'\\bruecke.ps1',[Convert]::FromBase64String('" . $b64 . "')); " .
        "\$cmd='powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File '+[char]34+\$d+'\\bruecke.ps1'+[char]34; " .
        "Set-ItemProperty -Path 'HKCU:\\Software\\Microsoft\\Windows\\CurrentVersion\\Run' -Name 'bulkify-lager-bruecke' -Value \$cmd";
    $zeilenBg = [
        '@echo off',
        'title bulkify Lager-Bruecke einrichten',
        'echo bulkify Lager-Bruecke wird eingerichtet - einen Moment bitte...',
        'echo.',
        'powershell -NoProfile -Command "' . $psSetup . '"',
        'echo Starte Bruecke...',
        'start "" powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "%LOCALAPPDATA%\\bulkify-bruecke\\bruecke.ps1"',
        'echo.',
        'echo Fertig. Die Bruecke laeuft jetzt unsichtbar im Hintergrund',
        'echo und startet kuenftig automatisch mit Windows - ohne Admin-Rechte.',
        'echo.',
        'echo Beenden: Task-Manager -^> Tab "Details" -^> powershell.exe beenden.',
        'echo Autostart aus: Task-Manager -^> Tab "Autostart" -^> "bulkify-lager-bruecke" deaktivieren.',
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
