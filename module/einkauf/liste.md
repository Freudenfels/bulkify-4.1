# einkauf/liste.php – Einkauf: Zu bestellen (Reiter) + alle Bestellungen

**Zweck:** Übersicht der Bestellungen bei Lieferanten (Rohstoffe, Verpackung, Verbrauch).

**Was passiert hier:**
- Liest alle Bestellungen inkl. Lieferant, Anzahl Positionen und Summe (Menge × EK, Unterabfrage).
- **Suche** nach Nummer, Lieferant. **Sortierung** Standard = neueste zuerst.
- Tabelle: **Nummer · Lieferant · Positionen · Summe · Status** (offen / bestellt / geliefert).
- Klick öffnet die Bestellung (`?p=bestellung&id=...`).
- Button „Neue Bestellung".
- Spalten **Zugesagt** (vom Lieferanten bestätigter Termin) und **Fortschritt** (Station x von 5) sowie ein **⇩** je Zeile für das Bestell-PDF.

## „Bestellt" = einfache Positionsliste (Umbau 2026-10-06)
Route `?p=einkauf`, Titel **„Bestellt"**. Eine Zeile je Bestellposition: **Produkt · Menge · Lieferant · Bestellt am · Bestellung-Nr · Status**. Klick auf die Zeile → Bestellung-Detail (`?p=bestellung&id=`) mit allen weiteren Infos. Archiv = gelieferte Positionen.

**Status je Zeile** (Zugang-Regel, [[bulkify-lieferanten]]): geliefert → „geliefert"; `bestaetigt=1`/Status `bestaetigt` → „bestätigt"; Lieferant **mit Portal-Zugang** (`benutzer.lieferant_id` aktiv) und Status gesendet/bestellt, noch nicht bestätigt → **„wartet auf Bestätigung"**; sonst (extern / Lieferant ohne Zugang) → „bestellt" (nur erfasst); `offen` → „Entwurf". Quelle der Bestätigung: Lieferanten-Portal (`lief_bestaetigen`).
