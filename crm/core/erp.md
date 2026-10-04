# erp.php – die Naht zum Dashboard

## Wozu
Die **einzige** Datei im CRM, die Tabellen des bulkify Dashboards kennt. Alles andere hier arbeitet nur mit `crm_`-Tabellen.

Der Grund: Am Dashboard wird weiterentwickelt, teilweise parallel in anderen Sitzungen. Wenn dort eine Spalte umbenannt wird, soll genau **eine** Datei kaputtgehen – diese. Dann weiß man auch, wo man sucht.

## Regeln
- **Lesen:** viel. Anfragen, Angebote, Rückfragen, Aufgaben, Preisanfragen, Kunden, Benutzer.
- **Schreiben:** an genau einer Stelle – `erp_kunde_anlegen()`, wenn aus einem Kontakt ein Kunde wird.
- **Nie:** UPDATE oder DELETE auf Dashboard-Daten. Auch kein `letzter_login` beim Anmelden – das würde die Anzeige dort verfälschen.

## Verkäufer-Workflow (nur lesen + verlinken)
- `erp_mitarbeiter()` – aktive Benutzer (ohne reine Lieferanten/Kunden) für das „Zuständig"-Feld am Lead.
- `erp_angebote_fuer_kunde()` / `erp_rezepturen_fuer_kunde()` – Angebote bzw. Rezepturen eines Kunden, nur zur Anzeige am Kontakt.
- `erp_dashboard_link('angebot&id=neu&kunde_id=5')` – baut einen Sprung in einen Dashboard-Flow (Angebot/Rezeptur anlegen). Angebot/Rezeptur werden **nicht** vom CRM geschrieben – der Kunde wird (falls nötig) über `erp_kunde_anlegen()` angelegt, dann übernimmt das Dashboard.

## Die wichtigste Funktion
`erp_offene_vorgaenge()` liefert alle Vorgänge, auf die jemand wartet. Je Zeile: `typ`, `id`, `titel`, `unter`, `seit`, `link`, `betrag`, `richtung`.

`richtung` trennt zwei verschiedene Sorgen:
- `sie` – die warten auf uns (Anfragen, Angebotsentwürfe, Rückfragen, Aufgaben)
- `wir` – wir warten auf andere (gesendete Angebote, Preisanfragen ohne Antwort)

## Was fehlt und warum
Das Dashboard merkt sich **kein Versanddatum** am Angebot. Als „wartet seit" dient deshalb `aktualisiert` – der letzte Stand. Das ist eine Näherung: Wird im Dashboard ein altes Angebot nachträglich bearbeitet, springt die Wartezeit zurück.

`tabelle_da()` schützt vor fehlenden Tabellen. Läuft das CRM auf einer Datenbank ohne Dashboard, bleibt die Liste einfach leer, statt mit einem Fehler stehenzubleiben.
