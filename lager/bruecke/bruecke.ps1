# bulkify Lager - Bruecke zu den Lichtleisten
#
# Laeuft auf einem Windows-PC im Lager, der im selben Netz haengt wie der Sender der Leisten.
# Fragt jede Sekunde bei bulkify nach, ob etwas leuchten soll, ruft dann den Sender im Lager auf
# und meldet das Ergebnis zurueck.
#
# Starten: Rechtsklick -> "Mit PowerShell ausfuehren". Fenster offen lassen.
# Beenden: Fenster schliessen oder Strg+C.

$Server = '{{URL}}'
$Token  = '{{TOKEN}}'
$Pause  = 1   # Sekunden zwischen zwei Nachfragen

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$Adresse = $Server + '?token=' + $Token
$Host.UI.RawUI.WindowTitle = 'bulkify Lager - Bruecke'
Write-Host 'bulkify Lager - Bruecke laeuft. Fenster offen lassen.'
Write-Host ('Server: ' + $Server)

$FehlerGemeldet = $false
while ($true) {
    try {
        $Antwort = Invoke-RestMethod -Uri $Adresse -TimeoutSec 15 -UserAgent 'bulkify-bruecke/1'
        if ($FehlerGemeldet) { Write-Host ((Get-Date -Format 'HH:mm:ss') + '  Verbindung wieder da.'); $FehlerGemeldet = $false }

        foreach ($Befehl in $Antwort.befehle) {
            $Ok = 0
            $Text = ''
            try {
                $Sender = Invoke-WebRequest -Uri $Befehl.url -TimeoutSec 4 -UseBasicParsing
                $Text = [string]$Sender.Content
                if ($Sender.StatusCode -eq 200 -and $Text -match '"ok"\s*:\s*true') { $Ok = 1 }
            } catch {
                $Text = 'Sender nicht erreichbar: ' + $_.Exception.Message
            }
            Write-Host ((Get-Date -Format 'HH:mm:ss') + '  ' + $Befehl.url + '  ->  ' + $(if ($Ok) { 'ok' } else { $Text }))
            try {
                Invoke-RestMethod -Method Post -Uri $Adresse -TimeoutSec 15 -UserAgent 'bulkify-bruecke/1' `
                    -Body @{ id = $Befehl.id; ok = $Ok; antwort = $Text } | Out-Null
            } catch { }
        }
    } catch {
        if (-not $FehlerGemeldet) {
            Write-Host ((Get-Date -Format 'HH:mm:ss') + '  bulkify nicht erreichbar: ' + $_.Exception.Message)
            $FehlerGemeldet = $true
        }
        Start-Sleep -Seconds 5
    }
    Start-Sleep -Seconds $Pause
}
