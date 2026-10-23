# beleg/rechnungen_liste.php – Rechnungen (Liste)

**Zweck:** Übersicht aller Rechnungen (Belege mit `typ='rechnung'`). Rechnungen entstehen **aus einem Auftrag** oder werden hier **frei/KI-gestützt** erstellt.

**Was passiert hier:**
- Liest alle Belege vom Typ Rechnung inkl. Kunde (Join).
- Zeigt oben die **offenen Posten** (Summe der Brutto-Beträge mit Status „offen").
- **Suche** nach Nummer, Kunde. **Sortierung** Standard = neueste zuerst.
- Tabelle: **Nummer · Datum · Kunde · Netto · Brutto · Status** (offen / bezahlt / storniert).
- Klick öffnet die Rechnung (`?p=rechnung&id=...`).
- **Knöpfe oben rechts:** **„+ Rechnung erstellen"** → freie, KI-gestützte Rechnung
  ([rechnung_frei.md](rechnung_frei.md)); **„Alt-Rechnungen importieren"** → ältere Original-Rechnungen
  per KI einlesen ([rechnung_import.md](rechnung_import.md)); **„Storno-Rechnung"** → Gutschrift/Storno
  ([gutschrift_neu.md](gutschrift_neu.md)). Rechnungen **aus einem Auftrag** entstehen weiter dort
  bzw. über [rechnung_neu.md](rechnung_neu.md).

## Produkt-Spalte (Stand 2026-10-23)
Die Liste zeigt je Rechnung das **Produkt**: aus dem verknüpften Auftrag (`COALESCE(auftrag.produkt_bezeichnung, produkt.kundenname, produkt.name)`), bei freien Rechnungen ohne Auftrag als **Fallback die erste Beleg-Position** (`$belProdukt`/`$belPosMap`). Die Suche findet auch über den Produktnamen. Gilt für normale und DL-Rechnungen.
