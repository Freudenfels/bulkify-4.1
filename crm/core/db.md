# db.php – Datenbankzugriff

Dieselben Helfer wie im Dashboard (`q`, `all`, `one`, `scalar`, `insert_id`), damit man beim Wechsel zwischen beiden Projekten nicht umdenken muss.

`tabelle_da($name)` prüft über `information_schema`, ob eine Tabelle existiert – **nicht** über `SHOW TABLES LIKE ?`. Letzteres verträgt keine Platzhalter und würde stillschweigend immer `false` liefern; die Liste bliebe dann ohne Fehlermeldung leer. (Genau dieser Fehler ist beim Bauen einmal passiert.)
