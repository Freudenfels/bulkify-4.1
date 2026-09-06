# core/db_import.php – DB-Dump einspielen

Spielt einen kompletten `mysqldump` (.sql) über die normale PDO-Verbindung ein.
Damit lässt sich ein Server (z. B. beta) auf den Datenbank-Stand eines anderen
Rechners bringen, ohne phpMyAdmin oder SSH.

## Zwei Funktionen
- `sql_split($sql)` – zerlegt den Dump quote-bewusst in Einzel-Statements.
  Beachtet `'…'`, `"…"`, `` `…` `` inklusive Backslash- und Doppel-Quote-Escapes,
  überspringt `--`-Zeilenkommentare und `/* … */`-Blöcke (auch die `/*! … */`-Hints
  von mysqldump – die brauchen wir nicht).
- `db_import_sql($pdo, $sql)` – führt die Statements einzeln aus. Schaltet für die
  Dauer `FOREIGN_KEY_CHECKS` und den strengen `sql_mode` ab (sonst stören DROP/CREATE-
  Reihenfolge und Alt-Daten). Gibt zurück: `stmts`, `ok`, `fehler[]`, `abbruch`
  (Notbremse ab 25 Fehlern → wahrscheinlich kein bulkify-Dump).

## Kollation normieren
Dumps von MariaDB 12+ enthalten für Tabellen ohne explizite Kollation die neue
Standardkollation `utf8mb4_uca1400_ai_ci` (z. B. die `crm_*`-Tabellen). Ältere
MariaDB/MySQL kennen sie nicht (`Unknown collation`). `db_import_sql` ersetzt alle
`utf8mb4_uca1400*`-Kollationen vor dem Ausführen durch `utf8mb4_unicode_ci`, damit der
Import auf älteren Servern (beta) durchläuft.

## Warum nicht `PDO::exec($ganzeDatei)`
mysqlnd führt zwar mehrere Statements aus, meldet Fehler späterer Statements aber
nicht sauber. Einzeln ausführen liefert Anzahl + erste Fehlermeldung.

## Verwendung
- Web (Admin): `module/system/db_import.php`.
- Lokal getestet gegen eine Wegwerf-DB: 516 Statements, 0 Fehler, 1.188 Rohstoffe,
  Umlaute intakt.
