# module/system/db_import.php – Datenübernahme (Web)

Admin-Seite unter `?p=db_import`. Lädt einen `.sql`-Dump hoch und spielt ihn über
`core/db_import.php` ein. Zweck: einen Server (z. B. beta) auf den DB-Stand eines
anderen Rechners bringen (Laptop → beta), ohne phpMyAdmin/SSH.

## Ablauf
1. Admin öffnet die Seite (Einstellungen → Werkzeuge → „Zur Datenübernahme").
2. `.sql`-Datei wählen, optional eine `.zip` mit den PDFs aus `data/uploads/`.
3. Bestätigungswort `ERSETZEN` eintippen, absenden.
4. Nach dem Import läuft `init_schema()` (additiv) und es wird angezeigt, wie viele
   Anweisungen liefen und wie viele Rohstoffe/Kunden/Produkte jetzt drin sind.

## Sicherheit / Warnung
- Nur Admin (`has_role('admin')`), zusätzlich zur ohnehin nicht-öffentlichen Route.
- **Ersetzt die komplette Datenbank** dieses Servers – inklusive der Logins
  (`benutzer`). Danach gilt der Anmeldestand aus dem Dump.
- Soll der Ziel-Server eigene Daten behalten, stattdessen einen Dump ohne
  `benutzer`/`app_meta` erzeugen.

## Aktueller Stand + Import-Verlauf
Die Seite zeigt oben den **Live-Stand** dieser Datenbank (DB-Name + Umgebung, Anzahl
Rohstoffe/Kunden/Produkte/…). Wenige Rohstoffe (<100) → Voll-Dump hier noch nicht
eingespielt (Warnhinweis). So sieht man sofort, ob die Daten wirklich in *dieser* DB liegen.

Darunter der **Import-Verlauf** (`db_import_log`): jede Datenübernahme über diese Seite
wird protokolliert (Zeitpunkt, Datei, Anweisungen ok/gesamt, Fehler, Rohstoffe danach, wer).
Die Tabelle `db_import_log` ist bewusst **NICHT Teil des Dumps** (`--ignore-table`), damit
der Verlauf server-lokal bleibt und ein Import ihn nicht überschreibt. Frühere Importe oder
Importe über phpMyAdmin/SSH erscheinen nicht (sie liefen ohne dieses Protokoll).

## Upload-Grenzen
Die Seite zeigt `upload_max_filesize`/`post_max_size` des Servers an. Ein typischer
bulkify-Dump ist ~1–2 MB, liegt also unter den Standardgrenzen.

## ZIP mit Upload-Dateien
Optionaler zweiter Datei-Upload: eine ZIP der `data/uploads/`-PDFs. Die Dateien
werden per Basename flach nach `BX_UPLOADS` entpackt (so wie die App sie ablegt).
Braucht `ZipArchive`; fehlt die Extension, wird der Teil still übersprungen.
