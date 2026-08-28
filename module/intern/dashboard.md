# dashboard.php – Team-Dashboard (Cockpit)

**Zweck:** Die interne Startseite als **Cockpit** mit echten Zahlen über den laufenden Betrieb.

**Kennzahlen (anklickbar):** Neue Anfragen · Offene Angebote · In Produktion · Versandbereit · Offene Posten (Σ Brutto offener Rechnungen) · Rohstoffe leer (freier Bestand = 0). Kritische Werte farbig (offene Posten gelb, Rohstoffe leer rot).

**Listen:** Neue Rezepturanfragen (→ Bearbeitung), Versandbereite Aufträge (→ Versand), Offene Rechnungen (→ Rechnung). Jede Zeile klickbar.

**Technik:** rechnet die Zahlen live per `scalar()`/`all()` über die Modul-Tabellen; keine eigenen Daten.
