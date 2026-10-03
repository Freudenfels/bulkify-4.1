<?php
// Liefert das Brueckenprogramm zum Herunterladen - als .bat zum DOPPELKLICKEN.
// Adresse, Schluessel und Version sind schon eingetragen. Vorlage: bruecke/bruecke.ps1.
//
// Warum eine .bat statt direkt .ps1: Ein Doppelklick auf eine .ps1 oeffnet nur den Editor und
// "Mit PowerShell ausfuehren" wird oft von der Ausfuehrungsrichtlinie blockiert. Die .bat schreibt
// das Skript auf die Platte und startet es mit -ExecutionPolicy Bypass.
//
// WICHTIG (Bugfix): Das PS1 wird NICHT mehr in EINER cmd-Zeile geschrieben. Der Base64-Code ist zu
// lang (>8191 Zeichen = cmd-Zeilenlimit) -> die Zeile wurde abgeschnitten, das PS1 war kaputt und
// es lief eine alte Datei aus %TEMP%. Jetzt: Base64 in STUECKEN in eine .b64-Datei schreiben und
// per PowerShell entpacken (Whitespace wird entfernt) - keine Zeilenlaengen-Grenze mehr.
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$basis = ($https ? 'https' : 'http') . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost') . '/lager/bruecke.php';

// EINE Quelle fuer die Version: steht im Dateinamen, in der Fenster-Startmeldung und im UA.
// Bei jeder Aenderung an bruecke.ps1 hier hochzaehlen.
$version = '1.4';

$vorlage = (string)file_get_contents(BX_ROOT . '/bruecke/bruecke.ps1');
$skript  = strtr($vorlage, ['{{URL}}' => $basis, '{{TOKEN}}' => lg_bruecke_token(), '{{VERSION}}' => $version]);
$skript  = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $skript);   // saubere Windows-Zeilenenden
$b64     = base64_encode($skript);                                 // UTF-8-Bytes des PS1
$dateiBasis = 'bulkify-lager-bruecke-v' . str_replace('.', '-', $version);

// Base64 in Stuecke von 3000 Zeichen -> je eine "echo"-Zeile (weit unter dem cmd-Limit).
// $cmdPfad ist der Zielpfad der .b64-Datei in cmd-Schreibweise (mit %VAR%).
$chunkLines = function (string $b64, string $cmdPfad): array {
    $out = []; $first = true;
    foreach (str_split($b64, 3000) as $c) {
        $out[] = ($first ? '>' : '>>') . '"' . $cmdPfad . '" echo ' . $c;
        $first = false;
    }
    return $out;
};

// Alte, evtl. noch laufende Bruecken beenden (nicht sich selbst) - damit die neue sicher uebernimmt.
$killZeile = 'powershell -NoProfile -Command "Get-CimInstance Win32_Process | Where-Object { $_.Name -eq '
    . "'powershell.exe' -and \$_.ProcessId -ne \$PID -and (\$_.CommandLine -like '*bulkify-lager-bruecke.ps1*' -or \$_.CommandLine -like '*bulkify-bruecke*') } | ForEach-Object { Stop-Process -Id \$_.ProcessId -Force -ErrorAction SilentlyContinue }\"";

// ---- Hintergrund-Variante: unsichtbar + Autostart (HKCU-Run-Key, KEIN Admin noetig) -------------
if (($_GET['art'] ?? '') === 'hintergrund') {
    $ordner = '%LOCALAPPDATA%\\bulkify-bruecke';
    // Entpacken + Autostart-Eintrag in EINEM PowerShell-Aufruf. [char]34 = " (Pfade mit Leerzeichen).
    $decode = 'powershell -NoProfile -Command "'
        . '$d=$env:LOCALAPPDATA+' . "'\\bulkify-bruecke'; "
        . "\$b=(Get-Content -Raw (\$d+'\\bruecke.b64')) -replace '\\s',''; "
        . "[IO.File]::WriteAllBytes(\$d+'\\bruecke.ps1',[Convert]::FromBase64String(\$b)); "
        . "\$cmd='powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File '+[char]34+\$d+'\\bruecke.ps1'+[char]34; "
        . "Set-ItemProperty -Path 'HKCU:\\Software\\Microsoft\\Windows\\CurrentVersion\\Run' -Name 'bulkify-lager-bruecke' -Value \$cmd\"";
    $zeilenBg = array_merge(
        [
            '@echo off',
            'title bulkify Lager-Bruecke einrichten',
            'echo bulkify Lager-Bruecke wird eingerichtet - einen Moment bitte...',
            'echo.',
            'echo Beende evtl. schon laufende Bruecken...',
            $killZeile,
            'if not exist "' . $ordner . '" mkdir "' . $ordner . '"',
            'echo Schreibe Programm...',
        ],
        $chunkLines($b64, $ordner . '\\bruecke.b64'),
        [
            $decode,
            'echo Starte Bruecke...',
            'start "" powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' . $ordner . '\\bruecke.ps1"',
            'echo.',
            'echo Fertig. Die Bruecke laeuft jetzt unsichtbar im Hintergrund',
            'echo und startet kuenftig automatisch mit Windows - ohne Admin-Rechte.',
            'echo.',
            'echo Beenden: Task-Manager -^> Tab "Details" -^> powershell.exe beenden.',
            'echo Autostart aus: Task-Manager -^> Tab "Autostart" -^> "bulkify-lager-bruecke" deaktivieren.',
            'echo.',
            'pause',
        ]
    );
    $batBg = implode("\r\n", $zeilenBg) . "\r\n";
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $dateiBasis . '-einrichten.bat"');
    header('Cache-Control: no-store');
    echo $batBg;
    exit;
}

// ---- Test-Variante: mit Fenster, zeigt alles live (Version, SumatraPDF, Druckauftraege) ----------
$decodeTest = 'powershell -NoProfile -Command "'
    . "\$b=(Get-Content -Raw (\$env:TEMP+'\\bulkify-lager-bruecke.b64')) -replace '\\s',''; "
    . "[IO.File]::WriteAllBytes(\$env:TEMP+'\\bulkify-lager-bruecke.ps1',[Convert]::FromBase64String(\$b))\"";
$zeilen = array_merge(
    [
        '@echo off',
        'title bulkify Lager-Bruecke',
        'echo bulkify Lager-Bruecke wird gestartet - dieses Fenster bitte offen lassen.',
        'echo.',
        'echo Beende evtl. schon laufende Bruecken...',
        $killZeile,
        'echo Schreibe Programm...',
    ],
    $chunkLines($b64, '%TEMP%\\bulkify-lager-bruecke.b64'),
    [
        $decodeTest,
        'powershell -NoProfile -ExecutionPolicy Bypass -File "%TEMP%\\bulkify-lager-bruecke.ps1"',
        'echo.',
        'echo Bruecke beendet. Taste druecken zum Schliessen.',
        'pause >nul',
    ]
);
$bat = implode("\r\n", $zeilen) . "\r\n";

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $dateiBasis . '-test.bat"');
header('Cache-Control: no-store');
echo $bat;
exit;
