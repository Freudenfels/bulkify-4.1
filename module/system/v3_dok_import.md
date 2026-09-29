# module/system/v3_dok_import.php – v3-Dokumente übernehmen (TEMPORÄR)

Admin-Reiter (System, admin-only), um die v3-**Dateien** nach v4 zu migrieren. Route `?p=v3_dok_import`.
Liest den v3-Datenordner (`board.sqlite` + `uploads/`, Standard-Guess: Nachbarordner von `/bulkify4.1`,
z. B. `/bulkifyv2/bulkify-data`) und legt je Datei eine `dokument`-Zeile an. Logik: `tools/v3_dokumente_import.php`.

Ablauf: **Trockenlauf** (zählt nur) → **Import** (kopiert Dateien nach `data/uploads`, idempotent per `dokument.v3_ref`).
Braucht `pdo_sqlite` auf dem Server. Mapping/Details siehe `tools/v3_dokumente_import.php` und Memory
`v3-dokumente-migration-temporaer`. Nach dem Umzug wieder entfernen.
