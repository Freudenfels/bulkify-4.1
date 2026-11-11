# tools/test_abzug.php – Abzug live -> test von der Kommandozeile

`php tools/test_abzug.php` kopiert alle Tabellen von live in die Test-Datenbank und danach fehlende Upload-Dateien.
`--ohne-dateien` lässt die Dateien weg. Läuft nur in der Testumgebung (siehe `core/test_abzug.md`).
