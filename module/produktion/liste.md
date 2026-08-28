# produktion/liste.php – Produktions-Liste

**Zweck:** Übersicht aller Produktionsaufträge (PR-). Entstehen **automatisch** mit dem Auftrag – man legt sie nicht von Hand an.

**Was passiert hier:**
- Liest alle Produktionsaufträge inkl. Kunde, Produkt (Joins) und – per Unterabfrage – **Fortschritt** (erledigte / gesamte Stationen) und **nächste offene Station**.
- **Suche** nach Nummer, Kunde, Produkt. **Sortierung** Standard = neueste zuerst.
- Tabelle: **Nummer · Kunde · Produkt · Menge · Fortschritt · Nächste Station · Status** (offen / läuft / fertig).
- Klick öffnet den Produktionsauftrag (`?p=produktionsauftrag&id=...`).
