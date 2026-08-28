# auftrag/liste.php – Auftrags-Liste

**Zweck:** Übersicht aller Auftragsbestätigungen (AB-). Aufträge entstehen **automatisch** aus bestätigten Angeboten – hier legt man sie nicht von Hand an.

**Was passiert hier:**
- Liest alle Aufträge inkl. Kunde, Produkt (Joins) und der zugehörigen Rechnungsnummer (Unterabfrage).
- **Suche** nach Nummer, Kunde, Produkt. **Sortierung** Standard = neueste zuerst.
- Tabelle: **Nummer · Kunde · Produkt · Menge · Netto · Rechnung · Status** (offen / in Produktion / erledigt).
- Klick öffnet den Auftrag (`?p=auftrag&id=...`).
