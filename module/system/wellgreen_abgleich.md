# wellgreen_abgleich.php – Abgleich Wellgreen gegen Maggis Liste

Admin-Seite `?p=wellgreen_abgleich` (nicht im Menü), angelegt für den Abgleich vom 12.11.2026.

- **Nur lesen:** `?p=wellgreen_abgleich&format=json` gibt alle Bestellungen von Wellgreen (L-2789) und alle Bestellungen ohne Lieferant
  mit ihren Positionen aus, dazu die Chargen, die als Wareneingang in Frage kommen (Lieferant Wellgreen, gleiche Artikel, Fertigware/Bulk
  seit 01.01.2026, ohne Produktions-Chargen). Daraus entsteht die Abgleich-Tabelle (Rückmeldung `rueckmeldung-wellgreen-abgleich.md`).
- **Ergebnis:** zeigt, was die einmalige Datenpflege (`core/datenpflege_wellgreen.php`, `app_meta dpf_wg_ergebnis`) gemacht hat.
