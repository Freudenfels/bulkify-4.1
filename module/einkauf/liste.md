# einkauf/liste.php – Einkauf: Zu bestellen (Reiter) + alle Bestellungen

**Zweck:** Übersicht der Bestellungen bei Lieferanten (Rohstoffe, Verpackung, Verbrauch).

**Was passiert hier:**
- Liest alle Bestellungen inkl. Lieferant, Anzahl Positionen und Summe (Menge × EK, Unterabfrage).
- **Suche** nach Nummer, Lieferant. **Sortierung** Standard = neueste zuerst.
- Tabelle: **Nummer · Lieferant · Positionen · Summe · Status** (offen / bestellt / geliefert).
- Klick öffnet die Bestellung (`?p=bestellung&id=...`).
- Button „Neue Bestellung".
- Spalten **Zugesagt** (vom Lieferanten bestätigter Termin) und **Fortschritt** (Station x von 5) sowie ein **⇩** je Zeile für das Bestell-PDF.

## „Bestellt" = eine Zeile je BESTELLUNG (Umbau 2026-10-28)
Route `?p=einkauf`, Titel **„Bestellt"**. **Eine Zeile pro Bestellung** (nicht mehr je Position): **Bestellung-Nr · Lieferant · Inhalt · Bestellt am · Status**. Die Positionen werden in PHP je `bestellung_id` zusammengefasst – **Inhalt**: bei einem Produkt der Name + Menge, bei mehreren „N Positionen" mit gekürzter Namensliste. Klick auf die Zeile → Bestellung-Detail (`?p=bestellung&id=`). Archiv = gelieferte Bestellungen (COUNT DISTINCT). Gesucht wird über Nummer, Lieferant und alle Produktnamen der Bestellung.

Die laufende Liste zeigt alles außer `geliefert` **und `storniert`** – aufgeteilte Ur-Bündel sind storniert und verschwinden damit aus der Liste (keine doppelte BE-Nummer mehr). Taucht ein stornierter Beleg doch irgendwo auf, bekommt er einen neutralen Badge **„storniert"**.

**Bündel-Marker:** Enthält eine Bestellung **≥ 2 verschiedene Bulk-Produkte** (Produktname ohne „(AB-…)"-Zusatz), steht an der Bestellnummer ein Badge **„Bündel: N Produkte"** (warn) – erkennbar, welche Alt-Bündel man im Detail über **„In Einzelbestellungen aufteilen"** trennen kann. Neue Bestellungen sind je Produkt getrennt und zeigen daher i. d. R. keinen Badge.

**Status je Zeile** (Zugang-Regel, [[bulkify-lieferanten]]): geliefert → „geliefert"; `bestaetigt=1`/Status `bestaetigt` → „bestätigt"; Lieferant **mit Portal-Zugang** (`benutzer.lieferant_id` aktiv) und Status gesendet/bestellt, noch nicht bestätigt → **„wartet auf Bestätigung"**; sonst (extern / Lieferant ohne Zugang) → „bestellt" (nur erfasst); `offen` → „Entwurf". Quelle der Bestätigung: Lieferanten-Portal (`lief_bestaetigen`).
