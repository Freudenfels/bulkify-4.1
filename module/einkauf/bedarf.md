# einkauf/bedarf.php – Einkaufsbedarf (Prüfen & Melden)

**Stufe 1 des Einkaufs-Ablaufs.** Nach der Auftragsbestätigung erscheint jeder offene/laufende Produktionsauftrag hier automatisch. Route `?p=bedarf` (Rollen production/labor/fulfillment/einkauf, admin). Menü: Werk → Warenwirtschaft, Admin → Einkauf.

**Je Auftrag:**
- **Kopfzeile:** Auftragsnummer · Produkt · Kunde · Packungen · **Kapsel-/Tablettengröße** (`produktion_groesse_label()`, nur wenn vorhanden) · Priorität · Meldestatus.
- **Produktionsart** (`produktionsauftrag.produktionsart`, Dropdown): **Eigenproduktion** (wir machen es selbst → Rohstoff-/Verpackungsbedarf) oder **Fremdproduktion (zukaufen)** (fertiges Produkt extern beschaffen, kein Komponentenbedarf). Umschalten macht ein POST + Redirect (bleibt im aktuellen Reiter über das Hidden-Feld `tab`); die Seite startet danach wieder **oben** (`history.scrollRestoration='manual'`, sonst stellt der Browser bei gleicher URL die alte Scroll-Position wieder her).
- Bei Eigenproduktion: Kurzliste des **Fehlbedarfs** (`auftrag_fehlbedarf`, netto inkl. Netting gegen offene Bestellungen – auch Sammelbestellungen/Lager).
- **„An Einkauf melden"** (`aktion=melden` → `produktionsauftrag.bedarf_gemeldet`): schiebt den Bedarf in die Einkäufer-Tagesliste. Rücknahme per `melden_zurueck`. Badge „an Einkauf gemeldet".

**Stufe 2 = die **Einkaufsliste** (`?p=einkaufsliste`, einkauf/einkaufsliste.php): gemeldete Bedarfe nach Typ-Reitern; Artikel auswählen + Lieferant + Bestelldatum wählen und gebündelt bestellen. Stufe 3 = Bestellungen (`?p=einkauf`) = nur Historie.

**Datenbasis (core/schema.php):** `auftrag_bedarf`/`auftrag_fehlbedarf` (Stückliste × Menge vs. Netto-Bestand), `produktion_ist_zukauf`, `bedarf_typ`. Sammelbestellung in `bestellung_sammel_anlegen()` (nur **gemeldete** Bedarfe).

**Auch je Auftrag** (Produktionsauftrag-Detailseite): Panel „Einkaufsbedarf" (ganze Stückliste, Reservieren, direkt bestellen) + „Bestellungen für diesen Auftrag".

## Artikel / Betriebsmittel melden (Mitarbeiter)
Oben auf der Seite ein aufklappbares Panel **„+ Artikel / Betriebsmittel melden"** (`aktion=artikel_melden`): Bezeichnung · Menge · Einheit · Typ/Kategorie (`betriebsmittel_kategorien`) · Notiz. Für alles, was gekauft werden soll, aber **nichts mit der Produktion** zu tun hat (Handschuhe, Kartons, Werkzeug …). Die Meldung wird als **`freibedarf`** gespeichert (mit `gemeldet_von` = angemeldeter Mitarbeiter) und erscheint **direkt auf der Einkaufsliste** – kein Melden-Schritt nötig. Bewusst hier (Werk-Rollen), weil die Einkaufsliste selbst nur für Einkauf/Admin ist. Das ausführliche Formular („Neuen Bedarf eintragen", inkl. Lieferant/Elektro-Flag) bleibt zusätzlich auf der Einkaufsliste.
