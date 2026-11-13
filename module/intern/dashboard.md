# dashboard.php – Team-Dashboard (Cockpit)

**Zweck:** Die interne Startseite als **Cockpit** mit echten Zahlen über den laufenden Betrieb.

**Kennzahlen (anklickbar):** Neue Anfragen · Offene Angebote · In Produktion · Versandbereit · Offene Posten (offene + teilbezahlte Rechnungen, je Rechnung Brutto minus Zahlungen, `op_rechnungen()` aus `core/offene_posten.php` – gleiche Summe wie Buchhaltung „Offene Posten“, Klick dorthin; seit 13.11.2026) · Rohstoffe leer (freier Bestand = 0). Kritische Werte farbig (offene Posten gelb, Rohstoffe leer rot).

**Listen:** Neue Rezepturanfragen (→ Bearbeitung), Versandbereite Aufträge (→ Versand), Offene Rechnungen (→ Rechnung). Jede Zeile klickbar.

**Technik:** rechnet die Zahlen live per `scalar()`/`all()` über die Modul-Tabellen; keine eigenen Daten.

Stornierte Produktionsaufträge und Produktionsaufträge eines stornierten Auftrags zählen hier nicht mehr (`pa_aktiv_sql()`, 03.11.2026). Anlass: Leberkomplex 10.000, ein in ein Kontingent umgewandelter Auftrag, dessen Produktionsauftrag weiterlief. (Zähler „Produktion offen“)

## Preis-Gültigkeit (11.11.2026)
Beim Aufruf läuft einmal am Tag `pg_bald_pruefen()`. Bald ablaufende Preise in offenen Angeboten erzeugen eine Benachrichtigung.

## Versandbereit nur bei fertigem Fortschritt (11.11.2026)
Kachel und Liste „Versandbereit“ zählen Aufträge mit Status `erledigt` nur, wenn `auftrag_fortschritt_fertig()` gilt, also Labor da und alle Mengen da. Dienstleistungen zählen wie bisher. Teillieferungen bleiben unter „Versand“ möglich.


**Offene Rechnungen (Liste rechts):** die 6 neuesten offenen Rechnungen mit dem **offenen Rest** (nicht Brutto); teilbezahlte tragen den kleinen Hinweis „teilbezahlt“ (13.11.2026).

**Zähler nur offene Vorgänge (13.11.2026, Paket Teil 8):**
- „Offene Angebote“ ohne abgelaufene (gueltig_bis vor heute).
- „In Produktion“ ohne Produktionsaufträge versendeter oder stornierter Aufträge.
- „Rohstoffe leer“ nur für Rohstoffe, die ein offener Produktionsauftrag braucht.
