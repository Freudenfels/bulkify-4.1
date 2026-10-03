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
// Bei jeder Aenderung HIER hochzaehlen.
$version = '1.6';

// WICHTIG: Das PS1 ist INLINE eingebettet (nicht mehr aus bruecke/bruecke.ps1 geladen). Grund:
// die separate Datei wurde vom inkrementellen SFTP-Deploy offenbar nicht aktualisiert, wodurch
// immer eine uralte Bruecke ausgeliefert wurde. So steckt garantiert der aktuelle Code im Download.
// (bruecke/bruecke.ps1 bleibt als Referenz im Repo - bei Aenderungen BEIDE pflegen.)
$vorlage = <<<'PS1'
# bulkify Lager - Bruecke (Pick-to-Light)
# ========================================
# Holt Leuchtbefehle vom Server ab und gibt sie an den Sender im Lager-Netz weiter.

$Url   = "{{URL}}"
$Token = "{{TOKEN}}"

# Nur EINE Bruecke gleichzeitig. Starten mehrere, beenden sich alle weiteren sofort selbst.
try {
  $global:bxMutex = New-Object System.Threading.Mutex($false, "Global\bulkify-lager-bruecke")
  if (-not $global:bxMutex.WaitOne(0)) {
    Write-Host " Es laeuft bereits eine andere bulkify-Bruecke - dieses Fenster kann zu." -ForegroundColor Yellow
    Start-Sleep -Seconds 4
    exit
  }
} catch {}

try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch {}
$UA = "bulkify-lager-bruecke/{{VERSION}}"

function Send-Printers {
  try {
    $prn = @(Get-CimInstance Win32_Printer -ErrorAction Stop)
    $namen = ($prn | ForEach-Object { $_.Name }) -join "|"
    $std = ($prn | Where-Object { $_.Default } | Select-Object -First 1 -ExpandProperty Name)
    if (-not $std) { $std = "" }
    Invoke-RestMethod -Uri ($Url + "?token=" + $Token) -Method Post -TimeoutSec 10 -UserAgent $UA -Body @{ printers = $namen; standard = $std } | Out-Null
    Write-Host ((Get-Date -Format "HH:mm:ss") + "  Drucker gemeldet: " + $namen) -ForegroundColor Green
  } catch {
    Write-Host ((Get-Date -Format "HH:mm:ss") + "  Drucker konnten nicht gemeldet werden: " + $_.Exception.Message) -ForegroundColor Yellow
  }
}

function Get-SumatraPath {
  $cands = @(
    (Join-Path $env:LOCALAPPDATA "SumatraPDF\SumatraPDF.exe"),
    "C:\Program Files\SumatraPDF\SumatraPDF.exe",
    "C:\Program Files (x86)\SumatraPDF\SumatraPDF.exe",
    (Join-Path (Split-Path -Parent $PSCommandPath) "SumatraPDF.exe")
  )
  foreach ($c in $cands) { if ($c -and (Test-Path $c)) { return $c } }
  $cmd = Get-Command SumatraPDF.exe -ErrorAction SilentlyContinue
  if ($cmd) { return $cmd.Source }
  return $null
}

Write-Host ("=" * 54)
Write-Host " bulkify Lager-Bruecke laeuft"
Write-Host (" Version: " + $UA) -ForegroundColor Cyan
Write-Host (" Server: " + $Url)
Write-Host " Fenster offen lassen. Beenden mit Strg+C."
Write-Host ("=" * 54)

$spStart = Get-SumatraPath
if ($spStart) { Write-Host (" SumatraPDF gefunden: " + $spStart) -ForegroundColor Green }
else { Write-Host " SumatraPDF NICHT gefunden - lautloser Druck geht erst nach Installation." -ForegroundColor Yellow }

Send-Printers

$offline = $false
$tick = 0
while ($true) {
  if ((++$tick) % 120 -eq 0) { Send-Printers }
  try {
    $poll = Invoke-RestMethod -Uri ($Url + "?token=" + $Token) -Method Get -TimeoutSec 10 -UserAgent $UA
    if ($offline) { Write-Host ((Get-Date -Format "HH:mm:ss") + "  Server wieder erreichbar.") -ForegroundColor Green; $offline = $false }

    if ($poll.befehle) {
      foreach ($b in $poll.befehle) {
        $ok = "0"; $antwort = ""
        try {
          $r = Invoke-WebRequest -Uri $b.url -Method Get -TimeoutSec 5 -UseBasicParsing
          $antwort = ([string]$r.StatusCode + " " + $r.Content)
          if ([int]$r.StatusCode -eq 200) { $ok = "1" }
        } catch { $antwort = "Sender nicht erreichbar: " + $_.Exception.Message }
        try { Invoke-RestMethod -Uri ($Url + "?token=" + $Token) -Method Post -TimeoutSec 10 -UserAgent $UA -Body @{ id = $b.id; ok = $ok; antwort = $antwort } | Out-Null } catch {}
        if ($ok -eq "1") { $st = "OK"; $farbe = "Green" } else { $st = "FEHLER"; $farbe = "Red" }
        Write-Host ((Get-Date -Format "HH:mm:ss") + "  Befehl " + $b.id + " -> " + $st + "  " + $antwort) -ForegroundColor $farbe
      }
    }

    if ($poll.druck) {
      foreach ($d in $poll.druck) {
        $ok = "0"; $antwort = ""
        try {
          $bytes = [Convert]::FromBase64String($d.pdf_b64)
          $tmp = Join-Path $env:TEMP ("bulkify-etikett-" + $d.id + ".pdf")
          [IO.File]::WriteAllBytes($tmp, $bytes)
          $sumatra = Get-SumatraPath
          if ($sumatra) {
            $zielName = if ($d.drucker) { [string]$d.drucker } else { "" }
            # Mehrere Druckweisen der Reihe nach - erste mit ExitCode 0 gewinnt. Zuerst SCHLICHT
            # (wie der GUI-Druck); -print-settings macht per CLI je nach Version Aerger (ExitCode 2).
            $versuche = @()
            if ($zielName -ne "") {
              $versuche += ,@("-silent", "-print-to", $zielName, $tmp)
              $versuche += ,@("-silent", "-print-to", $zielName, "-print-settings", "fit", $tmp)
              $versuche += ,@("-print-to", $zielName, $tmp)
            } else {
              $versuche += ,@("-silent", "-print-to-default", $tmp)
              $versuche += ,@("-silent", "-print-to-default", "-print-settings", "fit", $tmp)
            }
            $letzter = ""
            foreach ($va in $versuche) {
              $p = Start-Process -FilePath $sumatra -ArgumentList $va -PassThru -WindowStyle Hidden
              if (-not $p.WaitForExit(45000)) { try { $p.Kill() } catch {}; $letzter = "Timeout"; continue }
              if ($p.ExitCode -eq 0) { $ok = "1"; break }
              $letzter = "ExitCode " + $p.ExitCode
            }
            if ($ok -eq "1") { $antwort = "gedruckt -> " + ($(if ($zielName -ne "") { $zielName } else { "Standarddrucker" })) }
            else { $antwort = "SumatraPDF druckte nicht (" + $letzter + ") - Drucker: " + ($(if ($zielName -ne "") { $zielName } else { "Standard" })) }
          } else {
            $antwort = "SumatraPDF nicht gefunden - bitte auf dem Lager-PC installieren"
          }
        } catch { $antwort = "Druckfehler: " + $_.Exception.Message }
        try { Invoke-RestMethod -Uri ($Url + "?token=" + $Token) -Method Post -TimeoutSec 10 -UserAgent $UA -Body @{ druck_id = $d.id; ok = $ok; antwort = $antwort } | Out-Null } catch {}
        if ($ok -eq "1") { $st = "OK"; $farbe = "Green" } else { $st = "FEHLER"; $farbe = "Red" }
        Write-Host ((Get-Date -Format "HH:mm:ss") + "  Druck " + $d.id + " -> " + $st + "  " + $antwort) -ForegroundColor $farbe
      }
    }
  } catch {
    if (-not $offline) {
      Write-Host ((Get-Date -Format "HH:mm:ss") + "  Kein Kontakt zum Server: " + $_.Exception.Message) -ForegroundColor Yellow
      $offline = $true
    }
  }
  Start-Sleep -Seconds 1
}
PS1;
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
// Nur powershell/wscript treffen (NICHT cmd.exe - das waere die .bat selbst).
$killZeile = 'powershell -NoProfile -Command "Get-CimInstance Win32_Process | Where-Object { ($_.Name -eq '
    . "'powershell.exe' -or \$_.Name -eq 'wscript.exe') -and \$_.ProcessId -ne \$PID -and (\$_.CommandLine -like '*bulkify-lager-bruecke*' -or \$_.CommandLine -like '*bulkify-bruecke*') } | ForEach-Object { Stop-Process -Id \$_.ProcessId -Force -ErrorAction SilentlyContinue }\"";

// ---- Reset-Variante: entfernt ALLE Bruecken restlos (Prozesse, Autostarts, Dateien) --------------
// Am besten als Administrator ausfuehren, damit auch die geplante Aufgabe weg ist.
if (($_GET['art'] ?? '') === 'reset') {
    $zeilenR = [
        '@echo off',
        'title bulkify Lager-Bruecke ENTFERNEN',
        'echo Entferne alle bulkify-Bruecken (Prozesse, Autostart, Dateien)...',
        'echo.',
        $killZeile,
        'reg delete "HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Run" /v "bulkify-lager-bruecke" /f >nul 2>&1',
        'schtasks /delete /tn "bulkify Lager Bruecke" /f >nul 2>&1',
        'del /q "%TEMP%\\bulkify-lager-bruecke.ps1" >nul 2>&1',
        'del /q "%TEMP%\\bulkify-lager-bruecke.b64" >nul 2>&1',
        'rmdir /s /q "%LOCALAPPDATA%\\bulkify-bruecke" >nul 2>&1',
        'echo.',
        'echo Fertig. Es laeuft jetzt KEINE Bruecke mehr und nichts startet automatisch.',
        'echo Tipp: Lager-PC einmal neu starten - dann ist alles garantiert sauber.',
        'echo.',
        'pause',
    ];
    $batR = implode("\r\n", $zeilenR) . "\r\n";
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $dateiBasis . '-ENTFERNEN.bat"');
    header('Cache-Control: no-store');
    echo $batR;
    exit;
}

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
