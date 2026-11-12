# test_abzug.php – Datenstand live -> test (Paket 12.11.2026 Teil 2)

Kopiert alle Tabellen der Live-Datenbank in die Datenbank der Testumgebung, optional dazu fehlende Upload-Dateien.

**Nur in diese Richtung:**
- geschrieben wird nur über `db()` (eigene Installation = Test),
- die Live-Verbindung ist nur lesend (`SET SESSION TRANSACTION READ ONLY`, nur SHOW/SELECT),
- `ta_sperre()` verweigert, wenn kein Testmodus aktiv ist oder Quelle und Ziel dieselbe Datenbank sind.

Zugang zur Quelle in der `secrets.php` der Testumgebung: `LIVE_DB_HOST`, `LIVE_DB_PORT`, `LIVE_DB_NAME`,
`LIVE_DB_USER`, `LIVE_DB_PASS` (am besten ein Benutzer nur mit Leserecht), optional `LIVE_DATA_DIR`
(der `data`-Ordner von live, für Dokumente/Bilder; kopiert werden nur fehlende Dateien, nichts wird gelöscht).

Reihenfolge: `app_meta` zuerst (Merker gelten sofort), `benutzer` zuletzt (danach gelten die Logins von live).

Funktionen: `ta_sperre()`, `ta_live()`, `ta_tabellen()`, `ta_tabelle($t)`, `ta_dateien($max)`, `ta_fertig()`.
Aufruf: Dashboard `?p=test_abzug` (Admin) oder `php tools/test_abzug.php`.

## Sicherer Abzug (13.11.2026)
- Quelle und Ziel werden über die echte Verbindung verglichen (`@@hostname`, `@@port`, `DATABASE()`, `@@server_uuid`), nicht nur als Text.
- Trägt das Ziel den Live-Marker `app_meta umgebung=live`: Abbruch.
- Beim Kopieren von `app_meta` wird der Marker im Ziel auf `test` gesetzt.
- `ta_fertig()` erzeugt `einlager_api_token` neu.
