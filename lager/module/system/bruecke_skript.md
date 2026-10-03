# system/bruecke_skript.php – Brückenprogramm herunterladen

Liefert die Lager-Brücke (`bruecke/bruecke.ps1`) als Download zum Doppelklicken. Server-Adresse und Schlüssel sind schon eingetragen, Zeilenenden im Windows-Format.

Zwei Varianten:

- **Standard (`?p=bruecke_skript`)** → `.bat` „mit Fenster". Schreibt das PS1 nach `%TEMP%` und startet es sichtbar (zum Testen). Fenster muss offen bleiben.
- **Hintergrund (`?p=bruecke_skript&art=hintergrund`)** → `.bat`, die das PS1 dauerhaft nach `%LOCALAPPDATA%\bulkify-bruecke\bruecke.ps1` ablegt und eine **Windows-Aufgabe** `bulkify Lager Bruecke` anlegt (Trigger: bei Anmeldung, `-WindowStyle Hidden`). Läuft dann unsichtbar und startet automatisch mit Windows. Das Einrichtungsfenster schließt sich nach `pause`.

Warum keine `.vbs` mehr: Chrome/Defender blockieren `.vbs`-Downloads grundsätzlich als „Virus gefunden" (versteckter Prozessstart = Malware-Muster). Die `.bat` lädt normal und erledigt Hintergrund + Autostart über die Aufgabenplanung.

Technik-Hinweise: Der Argument-String der Aufgabe wird per `New-ScheduledTaskAction` in PowerShell gebaut (kein manuelles Zitieren → Pfade mit Leerzeichen wie `C:\Users\Big Beast\...` sind sicher). Doppelte Anführungszeichen im Pfad über `[char]34` statt `\"`. Keine `%`-Zeichen in den PowerShell-Zeilen, damit cmd nichts expandiert. `Register-ScheduledTask` mit `-AtLogOn` für den aktuellen Benutzer braucht keine Admin-Rechte.

Entfernen: Aufgabenplanung → `bulkify Lager Bruecke` → löschen.
