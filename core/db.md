# db.php – Datenbank-Schicht

**Zweck:** Die einzige Stelle, die mit der Datenbank spricht. Alle anderen Dateien nutzen die Helfer von hier – nie direkt SQL-Verbindungen.

**Was passiert hier:**
- `db()` – baut **einmal** die Verbindung zur MariaDB auf und gibt sie immer wieder zurück (kein doppeltes Verbinden). Nur MySQL/MariaDB, kein Dual-Treiber mehr wie in v3.
- Die praktischen Kurz-Helfer für den Alltag:
  - `q($sql, $params)` – führt eine Abfrage sicher aus (immer „prepared", schützt vor SQL-Angriffen).
  - `one($sql, $params)` – holt **eine** Zeile (oder `null`).
  - `all($sql, $params)` – holt **alle** Zeilen als Liste.
  - `scalar($sql, $params)` – holt **einen** Wert (z. B. eine Anzahl).
  - `insert_id()` – die ID des zuletzt eingefügten Datensatzes.

**Wichtig / Regel:**
- **Immer** diese Helfer verwenden und Werte als Parameter übergeben (`?`), nie direkt in den SQL-Text schreiben. Das hält alles sicher und einheitlich.
