# module/lager/labor_senden.php – Ans Labor senden

Route `?p=labor_senden`. Rollen wie bei Laboranalysen: production, einkauf, labor, sales und Admin.

Der Knopf „Ans Labor senden“ steht oben auf der Seite Laboranalysen (`?p=laboranalysen`). Am Auftrag steht er im Block „Externer Labortest“ als `?p=labor_senden&auftrag=<id>`, der Auftrag ist dann vorausgewählt.

## Ablauf
1. **Labor und Proben wählen.** Das Labor ist ein Lieferant mit Kategorie Labor und Formular-Vorlage. Dazu kommt ein Standort, z. B. GBA Hamburg oder Hameln. Die Liste zeigt die Aufträge mit Labortest ohne Bericht, bereits versendete mit Datum. Über „Weiterer Auftrag“ (Suchfeld) lässt sich jede andere Bestellung dazunehmen.
2. **Proben prüfen.** Je Auftraggeber (Kunde) erscheint eine Tabelle mit Material/Produkt, Rückverfolgbarkeit (Charge, MHD, Auftrag), drei Zeilen Untersuchungsumfang (vorbelegt aus dem Labor) sowie den Haken Beurteilung und Express. Darunter steht die Bemerkung zum Auftrag. Fehlt beim Kunden die USt-IdNr. oder die Anschrift, steht ein roter Hinweis mit Link zum Kunden da. Je Kunde und je 5 Proben entsteht ein eigenes Formular, die Anzahl steht oben.
3. **Erzeugen.** `labor_versand_anlegen()` legt die PDFs am Auftrag ab und setzt den Status „Probe beim Labor“. Die Ergebnisseite verlinkt jedes PDF zum Öffnen und Drucken. Es wird in den Karton zur Probe gelegt.

Unten auf der Seite stehen die zuletzt erstellten Laboraufträge mit PDF-Link. Logik und Feldzuordnung: [core/labor_auftrag.md](../../core/labor_auftrag.md).
- Kunde in Tabelle zeigt internes Kürzel (kunde_link/kunde_anzeige), 08.11.2026
