# system/bruecke_skript.php – Brückenprogramm herunterladen

Liefert die Lager-Brücke (`bruecke/bruecke.ps1`) als Download zum Doppelklicken. Server-Adresse, Schlüssel und Version sind schon eingetragen, Zeilenenden im Windows-Format.

**Bugfix (v1.4):** Das PS1 wird NICHT mehr in einer einzigen cmd-Zeile geschrieben. Der Base64-Code ist > 8191 Zeichen (cmd-Zeilenlimit) → die Zeile wurde abgeschnitten, das PS1 war kaputt und die .bat startete eine alte Datei aus `%TEMP%` (alter Banner, kein Druck). Jetzt: Base64 in Stücken (`str_split 3000`) per `echo` in eine `.b64`-Datei schreiben, dann per PowerShell entpacken (`-replace '\s',''` entfernt die Chunk-Zeilenumbrüche). Beide Varianten beenden vorher evtl. laufende Brücken (`Get-CimInstance Win32_Process`, ohne sich selbst).

**Version:** `$version` in dieser Datei ist die EINE Quelle. Sie wird in die `.ps1` eingesetzt (`{{VERSION}}` → UA + Fenster-Startmeldung „Version: …") UND in den Dateinamen des Downloads (`bulkify-lager-bruecke-v1-3-einrichten.bat` bzw. `-test.bat`). So erkennt man am Dateinamen und im Fenster sofort, welche Version läuft. Bei jeder Änderung an `bruecke.ps1` die `$version` hochzählen.

Zwei Varianten:

- **Standard (`?p=bruecke_skript`)** → `.bat` „mit Fenster". Schreibt das PS1 nach `%TEMP%` und startet es sichtbar (zum Testen). Fenster muss offen bleiben.
- **Hintergrund (`?p=bruecke_skript&art=hintergrund`)** → `.bat`, die das PS1 dauerhaft nach `%LOCALAPPDATA%\bulkify-bruecke\bruecke.ps1` ablegt, den **Autostart in den HKCU-Run-Key** (`HKCU\Software\Microsoft\Windows\CurrentVersion\Run`, Wert `bulkify-lager-bruecke`) einträgt und die Brücke sofort versteckt startet (`start "" powershell … -WindowStyle Hidden`). Läuft unsichtbar und startet automatisch mit Windows. Einrichtungsfenster schließt nach `pause`.

**Kein Admin nötig:** HKCU-Run-Key + Benutzerordner brauchen keine Rechte. Eine **Windows-Aufgabe** (`Register-ScheduledTask`) wäre schöner, scheitert aber ohne Admin mit `Zugriff verweigert (0x80070005)` – darum bewusst der Run-Key.

Warum keine `.vbs` mehr: Chrome/Defender blockieren `.vbs`-Downloads grundsätzlich als „Virus gefunden" (versteckter Prozessstart = Malware-Muster). Die `.bat` lädt normal.

Technik-Hinweise: alles in EINEM `powershell -Command`. Doppelte Anführungszeichen im Pfad über `[char]34` statt `\"` (sicher bei Pfaden mit Leerzeichen wie `C:\Users\Big Beast\…`). In den PowerShell-Zeilen keine `%`-Zeichen, damit cmd nichts expandiert; der `start`-Befehl nutzt dagegen bewusst `%LOCALAPPDATA%` (cmd-Expansion gewollt). Ein kurzes Aufblitzen beim Logon ist durch `-WindowStyle Hidden` möglich, aber minimal.

Beenden: Task-Manager → Details → `powershell.exe`. Autostart aus: Task-Manager → Autostart → `bulkify-lager-bruecke` deaktivieren.
