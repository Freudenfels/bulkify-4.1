# einkauf/einkaufsliste.php – Einkaufsliste (Stufe 2)

Die ans Einkauf **gemeldeten** Bedarfe (`produktionsauftrag.bedarf_gemeldet`), gleiche Artikel über alle Aufträge gebündelt. Route `?p=einkaufsliste` (Rolle einkauf, admin). Menü: Einkauf.

**Typ-Reiter** (`.settabs`, `?typ=`): Alle · Etiketten · Verpackung · Rohstoffe · Fertige Produkte · **Nachbestellung** · Betriebsmittel-Kategorien. Tabelle mit Auswahl-Checkbox je Artikel + Σ benötigt / auf Lager / offen bestellt / zu bestellen / Aufträge. Quelle `bedarf_aggregiert(true)` (nur gemeldet + Eigenproduktion).

**Nachbestellung (Meldebestand):** `meldebestand_bedarf()` listet Lagerartikel, deren **freier Bestand + offen Bestelltes** unter den gepflegten **Meldebestand** (`item.mindestbestand`) gefallen ist. Zeilen-Key `nach:<item_id>`, editierbare Menge (Vorschlag = Meldebestand − (Lager+offen)), Lieferant vorbelegt mit Hauptlieferant. Beim Bestellen als normale **Lagerposition ohne Auftragsbezug** (`bestellung_erstellen`, `auftrag_id` NULL). Von Mitarbeitern gemeldete freie Artikel (`freibedarf`, u. a. über `?p=bedarf`) erscheinen im passenden Typ-Reiter und zeigen „gemeldet von …".

**Bestellen:** Artikel auswählen, **Lieferant + Bestelldatum** wählen → `bestellung_aus_positionen($mengen,$lieferant,$datum)`: EINE gebündelte Bestellung (mit Datum = Status „bestellt", ohne = Entwurf), in jeden betroffenen Auftrag wird der Bestellvermerk geschrieben (`log_aktivitaet`).

**Menge anheben:** Die Spalte „zu bestellen" ist je Zeile ein **Eingabefeld** (`menge[<key>]`), vorbelegt mit dem Bedarf – der Einkauf darf **mehr** bestellen als gebraucht (z. B. 1000 benötigt → 2000 auf Lager). Darunter steht der reine Bedarf als Referenz. Parsing deutsch (Punkt = Tausender, Komma = Dezimal), leer/0 = Bedarf. Bei **Rohstoff/Verpackung** ersetzt der Wert die Positionsmenge direkt; bei **Bulk (Fertigprodukt)** bleibt die auftragsgebundene Aufteilung erhalten und der Überschuss (Wunsch − Bedarf) kommt als auftragsloser Posten „Bulk: … (Puffer/Lager)" dazu (`bestellung_erstellen(..., $bulkMenge)`). Gesperrte Etikett-Zeilen (fehlendes Design) bleiben nicht editierbar.

**Fremdproduktion = Reiter „Fertige Produkte":** der Bulk-Zukauf je Fremd-Auftrag (`bedarf_bulk()`) erscheint IM Reiter „Fertige Produkte" (keine eigene Sektion), auswählbar + bestellbar über `bestellung_bulk_anlegen()` (Freitext-Position item_id NULL + `bezeichnung`, auftrag_id gesetzt, Vermerk im Auftrag). Verpackung/Etiketten der Fremd-Aufträge laufen in den normalen Reitern. Melden zeigt eine Bestätigung.

**Ablauf:** Einkaufsbedarf (melden, eigen/fremd) → **Einkaufsliste** (hier bestellen) → Bestellungen (`?p=einkauf`, nur Historie).
## E-Mail an den Lieferanten
Wird mit Bestelldatum bestellt, entsteht die Bestellung direkt als „bestellt" – dann geht je Lieferant die Bestell-Mail raus (`mail_lieferant_bestellung()`), falls der Versand eingerichtet ist.
