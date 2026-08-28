# versand/liste.php – Versand

**Zweck:** Versandbereite Aufträge versenden – Fertigware ausbuchen und Lieferschein erzeugen.

**Was passiert hier:**
- **POST `aktion=versenden`:** ruft `auftrag_versenden()` – bucht die **Fertigware nach FEFO** aus dem Lager (Verkaufsfertig-Charge des Produkts), erstellt einen **Lieferschein** (`beleg` typ=lieferschein, Nummer LS-) und setzt den Auftrag auf **„versendet"** (+ Verlaufseintrag am Kunden). Blockiert, wenn nicht genug Fertigware da ist.
- **Anzeige:** Liste der Aufträge mit Status **erledigt** (versandbereit) und **versendet**, je mit Kunde, Produkt, Menge, **Fertig im Lager**, Lieferschein und Status. Versandbereite Zeilen haben einen „Versenden"-Button (nur wenn genug Fertigware vorhanden).

**Einordnung:** Schließt die Ausgangsseite des Materialflusses – die Fertigware verlässt das Lager, der Kunde sieht im Portal „versendet".
