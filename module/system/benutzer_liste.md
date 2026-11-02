# system/benutzer_liste.php – Benutzerliste (Admin)

**Zweck:** Übersicht aller Mitarbeiter-Konten. Nur für **admin** (Route `benutzer` ist entsprechend geschützt).

**Anzeige:** Suche (Name/E-Mail), sortierbare Tabelle über `bx_table()` mit **Name · E-Mail · Rollen · Status (aktiv/gesperrt) · Letzter Login**. Zeile führt zur Detailseite. Button „Neuer Benutzer".

**Rollen-Spalte:** übersetzt das CSV-Set (`benutzer.rollen`) in lesbare Labels aus `rollen_liste()`.

## Nur Team-Mitarbeiter (Aufräumen 5)
Die Liste zeigt nur Benutzer ohne `lieferant_id`. Lieferanten-Zugänge stehen in den Einstellungen bzw. am Lieferanten. Ebenso gefiltert: Mitarbeiter-Dropdowns in Aufgaben, Kalender, Easy Production, Buchhaltung (Bearbeiter) und CRM.
