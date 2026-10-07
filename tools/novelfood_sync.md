# tools/novelfood_sync.php – monatlicher Novel-Food-Abgleich (CLI)

Für den **Server-Scheduler** (Cron), einmal im Monat. Ruft denselben Job wie der Admin-Button:
`novelfood_sync_lauf('cli')` aus `core/novelfood_sync.php`.

## Aufruf
```
php tools/novelfood_sync.php
```
Gibt eine Zusammenfassung aus (gesamt / neu / geändert / Statuswechsel / entfernt / übersetzt / Dauer) und
setzt `app_meta['novelfood_next_run']` grob auf den nächsten Monat. Exit-Code 1 bei Abruf-Fehler.

## Cron (Server, Beispiel: am 1. um 09:00)
```
0 9 1 * * php /pfad/zu/bulkify-4.1/tools/novelfood_sync.php >> /pfad/logs/novelfood.log 2>&1
```

## Hinweise
- Braucht ein CA-Bundle für HTTPS (auf dem Server vorhanden). Lokal: `-d curl.cainfo=<ca-bundle.crt>`.
- Übersetzt nur, wenn der KI-Schlüssel (`secrets.php`) gesetzt ist.
- Schreibt in `novelfood_katalog`, `novelfood_lauf`, `novelfood_change` und legt `data/novelfood_export.json` an.
