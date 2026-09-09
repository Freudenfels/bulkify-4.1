# auftrag/liste.php – Auftrags-Liste

**Zweck:** Übersicht aller Auftragsbestätigungen (AB-). Aufträge entstehen **automatisch** aus bestätigten Angeboten – hier legt man sie nicht von Hand an.

**Was passiert hier:**
- Liest alle Aufträge inkl. Kunde, Produkt (Joins) und der zugehörigen Rechnungsnummer (Unterabfrage).
- **Reiter Offen / Abgeschlossen** (`?tab=`): „Abgeschlossen" = Status `versendet`, „Offen" = alles andere (offen, in Produktion, versandbereit). Die Zahl je Reiter zählt den ganzen Bestand; Suche + Sortierung wirken innerhalb des aktiven Reiters. Standard-Reiter = Offen (deckt sich mit dem Menü-Badge „noch nicht fertig").
- **Suche** nach Nummer, Kunde, Produkt. **Sortierung** Standard = neueste zuerst.
- Tabelle: **Nummer · Kunde · Produkt · Menge · Netto · Rechnung · Status** (offen / in Produktion / erledigt).
- Klick öffnet den Auftrag (`?p=auftrag&id=...`).
