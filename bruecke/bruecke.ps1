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
$UA = "bulkify-lager-bruecke/1.0"

Write-Host ("=" * 54)
Write-Host " bulkify Lager-Bruecke laeuft"
Write-Host (" Server: " + $Url)
Write-Host " Fenster offen lassen. Beenden mit Strg+C."
Write-Host ("=" * 54)

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
  } catch {
    if (-not $offline) {
      Write-Host ((Get-Date -Format "HH:mm:ss") + "  Kein Kontakt zum Server: " + $_.Exception.Message) -ForegroundColor Yellow
      $offline = $true
    }
  }
  Start-Sleep -Seconds 1
}
