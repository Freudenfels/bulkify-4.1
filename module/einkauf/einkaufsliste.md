# einkauf/einkaufsliste.php – Einkaufsliste (Stufe 2)

Die ans Einkauf **gemeldeten** Bedarfe (`produktionsauftrag.bedarf_gemeldet`), gleiche Artikel über alle Aufträge gebündelt. Route `?p=einkaufsliste` (Rolle einkauf, admin). Menü: Einkauf.

**Typ-Reiter** (`.settabs`, `?typ=`): Alle · Etiketten · Verpackung · Rohstoffe · Fertige Produkte (`bedarf_typ`). Tabelle mit Auswahl-Checkbox je Artikel + Σ benötigt / auf Lager / offen bestellt / zu bestellen / Aufträge. Quelle `bedarf_aggregiert(true)` (nur gemeldet + Eigenproduktion).

**Bestellen:** Artikel auswählen, **Lieferant + Bestelldatum** wählen → `bestellung_aus_positionen($mengen,$lieferant,$datum)`: EINE gebündelte Bestellung (mit Datum = Status „bestellt", ohne = Entwurf), in jeden betroffenen Auftrag wird der Bestellvermerk geschrieben (`log_aktivitaet`). Mengen werden serverseitig aus dem aktuellen Bedarf genommen.

**Fremdproduktion = Reiter „Fertige Produkte":** der Bulk-Zukauf je Fremd-Auftrag (`bedarf_bulk()`) erscheint IM Reiter „Fertige Produkte" (keine eigene Sektion), auswählbar + bestellbar über `bestellung_bulk_anlegen()` (Freitext-Position item_id NULL + `bezeichnung`, auftrag_id gesetzt, Vermerk im Auftrag). Verpackung/Etiketten der Fremd-Aufträge laufen in den normalen Reitern. Melden zeigt eine Bestätigung.

**Ablauf:** Einkaufsbedarf (melden, eigen/fremd) → **Einkaufsliste** (hier bestellen) → Bestellungen (`?p=einkauf`, nur Historie).
