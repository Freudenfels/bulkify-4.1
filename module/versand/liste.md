# versand/liste.php – Versand

**Zweck:** Versandbereite Aufträge versenden – Fertigware ausbuchen und Lieferschein erzeugen.

**Was passiert hier:**
- **POST `aktion=versenden`:** ruft `auftrag_versenden_menge($auftrag_id, $menge)` – bucht die **Fertigware nach FEFO** aus dem Lager (Verkaufsfertig-Charge des Produkts), erstellt einen **Lieferschein** (`beleg` typ=lieferschein, Nummer LS-, mit `beleg.menge` = gelieferte Packungen, netto anteilig) und zählt `auftrag.versendet_menge` hoch. **Teilversand:** Ist `menge` kleiner als der noch offene Rest, wird nur dieser Teil geliefert; der Auftrag **bleibt „erledigt" (versandbereit)**, bis alle Packungen geliefert sind – erst dann Status **„versendet"** (+ Verlaufseintrag; Teillieferungen werden einzeln protokolliert). `menge` leer/0 = kompletter Rest. `auftrag_versenden()` = Rest komplett (Rückwärtskompatibilität).
- **Anzeige:** Liste der Aufträge mit Status **erledigt** (versandbereit) und **versendet**, je mit Kunde, Produkt, **Menge** (inkl. „X versendet · Y offen" bei Teilversand), **Fertig im Lager**, Lieferschein und Status. Die **Lieferschein-Nummer(n)** sind **Download-Links** auf das Lieferschein-PDF (`?p=lieferschein_pdf&id=<beleg-id>`, `core/pdf_lieferschein.php`); bei Teilversand stehen hier mehrere LS untereinander. Versandbereite Zeilen haben ein **Mengenfeld** (vorbelegt mit dem versendbaren Rest = min(offen, fertig im Lager)) + Senden-Button. Ist weniger fertig als offen, steht ein Hinweis „nur X fertig – Rest nach weiterer Produktion". Status-Badge **teilversendet** (gelb), solange ein Rest offen ist.

**Einordnung:** Schließt die Ausgangsseite des Materialflusses – die Fertigware verlässt das Lager, der Kunde sieht im Portal „versendet".

## Fulfillment-Kunden
Bei einem Kunden mit Fremdlager heißt der Knopf **Ins Fremdlager** und der Status **für das Fremdlager** bzw. **im Fremdlager**. Dabei wird nichts ausgebucht und kein Lieferschein geschrieben – die Fertigware bleibt als Bestand in Lager 2 stehen, bis der Endkunde im Shop bestellt. Erst dann bucht die Fulfillment-Kopplung sie ab (`lager2_verbrauch`). Ob ein Auftrag so läuft, sagt `auftrag_ist_fulfillment()`.

## Lager entscheidet (2026-10-08)
Je versandbereiter Auftrag (Status `erledigt`, Ware in Lager 1): **An Kunden senden** (`aktion=versenden` -> `auftrag_versenden`) für alle; zusätzlich **An Lager 2 übergeben** (`aktion=an_lager2` -> `auftrag_ins_fremdlager`) nur bei Fulfillment-Kunden. Beide erfordern genug Fertigware. Status-Badges: `erledigt`=versandbereit, `versendet`=abgeschlossen.
