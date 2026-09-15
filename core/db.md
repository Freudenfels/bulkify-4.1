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

**Tempo:**
- Prepared Statements laufen **emuliert** (`PDO::ATTR_EMULATE_PREPARES=true`): 1 statt 2 Roundtrips je Abfrage – spürbar schneller, besonders bei entfernter DB. Ausgabe bleibt gleich (Werte werden ohnehin per Parameter gebunden und im Code typisiert).
- `db()` misst den Verbindungsaufbau (`bx_db_connect_ms`). `q()` zählt je Request Anzahl und Zeit der Abfragen. `db_stats()` liefert `['anzahl','db_ms','connect_ms']` – genutzt von der Diagnose (`core/perf.php`, Einstellungen → Diagnose).

**Wichtig / Regel:**
- **Immer** diese Helfer verwenden und Werte als Parameter übergeben (`?`), nie direkt in den SQL-Text schreiben. Das hält alles sicher und einheitlich.
