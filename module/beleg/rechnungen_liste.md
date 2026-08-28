# beleg/rechnungen_liste.php – Rechnungen (Liste)

**Zweck:** Übersicht aller Rechnungen (Belege mit `typ='rechnung'`). Rechnungen entstehen **automatisch** mit dem Auftrag.

**Was passiert hier:**
- Liest alle Belege vom Typ Rechnung inkl. Kunde (Join).
- Zeigt oben die **offenen Posten** (Summe der Brutto-Beträge mit Status „offen").
- **Suche** nach Nummer, Kunde. **Sortierung** Standard = neueste zuerst.
- Tabelle: **Nummer · Datum · Kunde · Netto · Brutto · Status** (offen / bezahlt / storniert).
- Klick öffnet die Rechnung (`?p=rechnung&id=...`).
