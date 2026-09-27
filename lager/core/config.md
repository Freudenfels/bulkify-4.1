# lager/core/config.php – Grundeinstellungen

Findet die `secrets.php` und setzt daraus die Datenbank-Zugangsdaten. Gesucht wird erst in `lager/`, dann unter `BULKIFY_SECRETS`, dann eine Ebene höher im Dashboard-Projekt. Fehlt sie, gelten die lokalen Vorgaben (`bulkify41`, `bulkify`/`bulkify`).

- Optional aus der `secrets.php`: `LG_CLOUD_URL`, `LG_CLOUD_APP_ID`, `LG_CLOUD_SECRET` für die Hersteller-Cloud der Blinker.
- Eigene Sitzung `BXLAGER`, damit sich Dashboard, CRM und Lager nicht gegenseitig abmelden.
- `LG_BEFEHL_VERFALL_SEK` (30): So lange darf ein Leuchtbefehl auf die Brücke warten, danach wird er verworfen.
- `ist_lokal()` erlaubt den Autologin nur auf dem eigenen Rechner. `jetzt_utc()` liefert die aktuelle Zeit in UTC.
