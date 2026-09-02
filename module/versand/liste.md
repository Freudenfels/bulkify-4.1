# versand/liste.php – Versand

**Zweck:** Versandbereite Aufträge versenden – Fertigware ausbuchen und Lieferschein erzeugen.

**Was passiert hier:**
- **POST `aktion=versenden`:** ruft `auftrag_versenden()` – bucht die **Fertigware nach FEFO** aus dem Lager (Verkaufsfertig-Charge des Produkts), erstellt einen **Lieferschein** (`beleg` typ=lieferschein, Nummer LS-) und setzt den Auftrag auf **„versendet"** (+ Verlaufseintrag am Kunden). Blockiert, wenn nicht genug Fertigware da ist.
- **Anzeige:** Liste der Aufträge mit Status **erledigt** (versandbereit) und **versendet**, je mit Kunde, Produkt, Menge, **Fertig im Lager**, Lieferschein und Status. Versandbereite Zeilen haben einen „Versenden"-Button (nur wenn genug Fertigware vorhanden).

**Einordnung:** Schließt die Ausgangsseite des Materialflusses – die Fertigware verlässt das Lager, der Kunde sieht im Portal „versendet".

## Fulfillment-Kunden
Bei einem Kunden mit Fremdlager heißt der Knopf **Ins Fremdlager** und der Status **für das Fremdlager** bzw. **im Fremdlager**. Dabei wird nichts ausgebucht und kein Lieferschein geschrieben – die Fertigware bleibt als Bestand in Lager 2 stehen, bis der Endkunde im Shop bestellt. Erst dann bucht die Fulfillment-Kopplung sie ab (`lager2_verbrauch`). Ob ein Auftrag so läuft, sagt `auftrag_ist_fulfillment()`.
