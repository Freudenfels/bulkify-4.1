# bulkify Lager - Bruecke (Pick-to-Light)
# ========================================
# Holt Leuchtbefehle vom Server ab und gibt sie an den Sender im Lager-Netz weiter.
# Der Server kommt nicht an die Sender-IP im Lager - dieses Programm schon.
#
# Adresse und Schluessel sind beim Download schon eingetragen.
# Starten: Rechtsklick auf die Datei -> "Mit PowerShell ausfuehren".
# Fenster OFFEN lassen. Beenden: Fenster schliessen oder Strg+C.
# Automatisch starten: Verknuepfung in den Autostart-Ordner legen (shell:startup).

$Url   = "{{URL}}"
$Token = "{{TOKEN}}"

# PowerShell 5.1 nutzt sonst teils TLS 1.0 -> HTTPS zum Server schlaegt fehl.
try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch {}
$UA = "bulkify-lager-bruecke/1.1"

# SumatraPDF finden (fuer lautlosen Etikettendruck). Kostenlos: https://www.sumatrapdfreader.org
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
Write-Host (" Server: " + $Url)
Write-Host " Fenster offen lassen. Beenden mit Strg+C."
Write-Host ("=" * 54)

# Installierte Drucker einmal an den Server melden (fuer die Drucker-Auswahl in den Einstellungen).
try {
  $prn = Get-CimInstance Win32_Printer -ErrorAction Stop
  $namen = ($prn | ForEach-Object { $_.Name }) -join "|"
  $std = ($prn | Where-Object { $_.Default } | Select-Object -First 1 -ExpandProperty Name)
  Invoke-RestMethod -Uri ($Url + "?token=" + $Token) -Method Post -TimeoutSec 10 -UserAgent $UA `
    -Body @{ printers = $namen; standard = $std } | Out-Null
  Write-Host (" Drucker gemeldet: " + $namen)
} catch {}

$offline = $false
while ($true) {
  try {
    # 1) Nachfragen, ob etwas leuchten soll (das meldet die Bruecke zugleich als "online").
    $poll = Invoke-RestMethod -Uri ($Url + "?token=" + $Token) -Method Get -TimeoutSec 10 -UserAgent $UA
    if ($offline) { Write-Host ((Get-Date -Format "HH:mm:ss") + "  Server wieder erreichbar.") -ForegroundColor Green; $offline = $false }

    # 2) Jeden Befehl an den Sender im Lager weitergeben und das Ergebnis zurueckmelden.
    if ($poll.befehle) {
      foreach ($b in $poll.befehle) {
        $ok = "0"; $antwort = ""
        try {
          $r = Invoke-WebRequest -Uri $b.url -Method Get -TimeoutSec 5 -UseBasicParsing
          $antwort = ([string]$r.StatusCode + " " + $r.Content)
          if ([int]$r.StatusCode -eq 200) { $ok = "1" }
        } catch {
          $antwort = "Sender nicht erreichbar: " + $_.Exception.Message
        }
        try {
          Invoke-RestMethod -Uri ($Url + "?token=" + $Token) -Method Post -TimeoutSec 10 -UserAgent $UA `
            -Body @{ id = $b.id; ok = $ok; antwort = $antwort } | Out-Null
        } catch {}
        if ($ok -eq "1") { $st = "OK"; $farbe = "Green" } else { $st = "FEHLER"; $farbe = "Red" }
        Write-Host ((Get-Date -Format "HH:mm:ss") + "  Befehl " + $b.id + " -> " + $st + "  " + $antwort) -ForegroundColor $farbe
      }
    }

    # 3) Etiketten drucken (lautlos per SumatraPDF auf Standard-/Etikettendrucker).
    if ($poll.druck) {
      foreach ($d in $poll.druck) {
        $ok = "0"; $antwort = ""
        try {
          $bytes = [Convert]::FromBase64String($d.pdf_b64)
          $tmp = Join-Path $env:TEMP ("bulkify-etikett-" + $d.id + ".pdf")
          [IO.File]::WriteAllBytes($tmp, $bytes)
          $sumatra = Get-SumatraPath
          if ($sumatra) {
            $a = @("-silent")
            if ($d.drucker) { $a += @("-print-to", [string]$d.drucker) } else { $a += @("-print-to-default") }
            $a += $tmp
            $p = Start-Process -FilePath $sumatra -ArgumentList $a -PassThru -Wait -WindowStyle Hidden
            if ($p.ExitCode -eq 0) { $ok = "1"; $antwort = "gedruckt (SumatraPDF)" } else { $antwort = "SumatraPDF ExitCode " + $p.ExitCode }
          } else {
            Start-Process -FilePath $tmp -Verb Print -ErrorAction Stop
            $ok = "1"; $antwort = "an Standard-PDF-Programm uebergeben (SumatraPDF nicht gefunden)"
          }
        } catch { $antwort = "Druckfehler: " + $_.Exception.Message }
        try {
          Invoke-RestMethod -Uri ($Url + "?token=" + $Token) -Method Post -TimeoutSec 10 -UserAgent $UA `
            -Body @{ druck_id = $d.id; ok = $ok; antwort = $antwort } | Out-Null
        } catch {}
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
