# config.php – Zentrale Konfiguration

**Zweck:** Eine Stelle für alle Grundeinstellungen: Datenbank-Zugang, Pfade, App-Version.

**Was drinsteht:**
- **Datenbank:** Host, Port, Name (`bulkify41`), Benutzer, Passwort – für die lokale MariaDB.
- **Pfade:** `BX_ROOT` (Projektwurzel), `BX_DATA` / `BX_UPLOADS` (Daten & Uploads liegen **außerhalb** von `public`, damit nichts öffentlich erreichbar ist).
- **App:** Version (4.1) und Marke (bulkify).
- **Zeit:** intern alles in **UTC** – Anzeige später über `fmt_zeit()` in Berliner Zeit.

**Wichtig / Regel:**
- Wenn eine Datei `secrets.php` im Projekt liegt, überschreibt sie diese Standardwerte (für Live-Zugangsdaten). Diese `secrets.php` wird **nie** ins Git/Deploy gegeben.
- Zugangsdaten stehen nur hier – nirgends sonst im Code.
