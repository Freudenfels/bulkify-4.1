# einkauf/bedarf.php – Einkaufsbedarf (Prüfen & Melden)

**Stufe 1 des Einkaufs-Ablaufs.** Nach der Auftragsbestätigung erscheint jeder offene/laufende Produktionsauftrag hier automatisch. Route `?p=bedarf` (Rollen production/labor/fulfillment/einkauf, admin). Menü: Werk → Warenwirtschaft, Admin → Einkauf.

**Je Auftrag:**
- **Produktionsart** (`produktionsauftrag.produktionsart`, Dropdown): **Eigenproduktion** (wir machen es selbst → Rohstoff-/Verpackungsbedarf) oder **Fremdproduktion (zukaufen)** (fertiges Produkt extern beschaffen, kein Komponentenbedarf).
- Bei Eigenproduktion: Kurzliste des **Fehlbedarfs** (`auftrag_fehlbedarf`, netto inkl. Netting gegen offene Bestellungen – auch Sammelbestellungen/Lager).
- **„An Einkauf melden"** (`aktion=melden` → `produktionsauftrag.bedarf_gemeldet`): schiebt den Bedarf in die Einkäufer-Tagesliste. Rücknahme per `melden_zurueck`. Badge „an Einkauf gemeldet".

**Stufe 2 = die **Einkaufsliste** (`?p=einkaufsliste`, einkauf/einkaufsliste.php): gemeldete Bedarfe nach Typ-Reitern; Artikel auswählen + Lieferant + Bestelldatum wählen und gebündelt bestellen. Stufe 3 = Bestellungen (`?p=einkauf`) = nur Historie.

**Datenbasis (core/schema.php):** `auftrag_bedarf`/`auftrag_fehlbedarf` (Stückliste × Menge vs. Netto-Bestand), `produktion_ist_zukauf`, `bedarf_typ`. Sammelbestellung in `bestellung_sammel_anlegen()` (nur **gemeldete** Bedarfe).

**Auch je Auftrag** (Produktionsauftrag-Detailseite): Panel „Einkaufsbedarf" (ganze Stückliste, Reservieren, direkt bestellen) + „Bestellungen für diesen Auftrag".
