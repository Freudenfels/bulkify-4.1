# produktion/bericht.php – Produktionsbericht (Herstellprotokoll)

**Zweck:** Eine **druckbare Gesamtübersicht** eines Produktionsauftrags – alles an einem Ort, als Beleg/Herstellprotokoll. Route `?p=produktion_bericht&id=<pa_id>`. Erreichbar über den Button **„Produktionsbericht"** auf der Auftragsdetailseite (bei fertigen Aufträgen als Primär-Button).

**Inhalt (Abschnitte):**
- **Auftrag:** PR-Nummer, Status + Fortschritt X/N, Kundenauftrag + Kunde (intern), Produktionsart (Eigen-/Fremdfertigung), angelegt, geplant, abgeschlossen (spätester erledigter Schritt).
- **Produkt / Rezeptur (Bulk):** Bezeichnung, Darreichungsform, Kapsel-/Tablettengröße (`produktion_groesse_label`), Charge(n), MHD.
- **Menge:** Packungen, Einheiten je Packung + gesamt (Wort je Form: Kapseln/Tabletten/Sticks/Stück).
- **Zusammensetzung je Einheit:** Zutaten aus `rezeptur_zutat` (Name, mg je Einheit, Soll gesamt = mg × Gesamtstück) + Füllgewicht.
- **Produktionsschritte:** je Station Status, **Zeitpunkt**, **Bearbeiter** (`produktion_schritt.erledigt_von`), **gescannte Charge** (`scan_charge`).
- **Entnommene Materialien (chargengenau, FEFO):** aus `produktion_verbrauch` (Material, Charge, Menge) – erscheint nur, wenn Material abgebucht wurde (bei Master-Scan-Freigaben leer).
- **Eingebuchte Fertigware:** alle Chargen zum Auftrag (Nummer, Artikel, Menge, verfügbar, MHD, Status).
- Fußzeile: erstellt am/von.

**Druck:** Button „Drucken / PDF" (`window.print()`). Ein `@media print`-Block blendet Seitenleiste, Mobilbar und die Aktions-/Hinweiszeilen (`.no-print`) aus, sodass ein sauberes Dokument entsteht.

**Bearbeiter-Erfassung:** `produktion_schritt.erledigt_von` (neu) wird in `produktion_schritt_erledigen()` (core/schema.php) beim Abschluss mit `current_user()['name']` gefüllt – gilt für die geführte Produktion und die Detailseite. Vor Einführung abgeschlossene Schritte haben keinen Eintrag (zeigen „–").

**Rechte/Route:** `production`, `labor`, `fulfillment` (core/auth.php); Route in `public/index.php`. Funktioniert für normale (Produkt-)Aufträge und Bulk-Aufträge (`pa_ist_bulk()` → „Rezeptur (Bulk)"). Der Bericht ist **intern** (keine Kundenansicht).
