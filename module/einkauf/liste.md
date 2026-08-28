# einkauf/liste.php – Einkauf: Zu bestellen (Reiter) + alle Bestellungen

**Zweck:** Übersicht der Bestellungen bei Lieferanten (Rohstoffe, Verpackung, Verbrauch).

**Was passiert hier:**
- Liest alle Bestellungen inkl. Lieferant, Anzahl Positionen und Summe (Menge × EK, Unterabfrage).
- **Suche** nach Nummer, Lieferant. **Sortierung** Standard = neueste zuerst.
- Tabelle: **Nummer · Lieferant · Positionen · Summe · Status** (offen / bestellt / geliefert).
- Klick öffnet die Bestellung (`?p=bestellung&id=...`).
- Button „Neue Bestellung".
