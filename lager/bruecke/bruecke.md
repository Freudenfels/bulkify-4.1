# lager/bruecke/bruecke.ps1 – die Brücke im Lager (Vorlage)

Ein PowerShell-Programm für einen Windows-PC im Lager, der im selben Netz hängt wie der Sender. Es fragt jede Sekunde `/lager/bruecke.php` nach neuen Befehlen, ruft die fertigen URLs beim Sender auf und meldet `ok` oder den Fehler zurück.

`{{URL}}` und `{{TOKEN}}` werden beim Herunterladen ersetzt (siehe `system/bruecke_skript.php`). Die Datei im Repo enthält keinen Schlüssel.

Starten: Rechtsklick und dann „Mit PowerShell ausführen“, das Fenster offen lassen. Für den Autostart eine Verknüpfung in `shell:startup` legen.
