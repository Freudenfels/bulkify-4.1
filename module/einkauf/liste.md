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

**Statuskette (B5, 2026-10-28):** Die Status-Spalte zeigt den echten Fortschritt über `bestellung_stufe()` (`core/schema.php`): für Portal-Lieferanten **wartet auf Bestätigung → bestätigt/Station (angenommen·Produktion·Qualität) → versandbereit → teilweise eingegangen → versendet → eingegangen**; für Lieferant ohne Portal bzw. Online der selbstgepflegte Stand („online bestellt"/„bestellt"). Quelle: `bestellung.station`/`bestaetigt`/`tracking`/`angekommen_am` (+ `lieferung_paket` für den Teil-Eingang), Bestätigung übers Lieferanten-Portal (`lief_bestaetigen`).

**Offen seit + Überfälligkeit (B4):** Spalte „Offen seit" zeigt die Tage seit Bestellung (`bestellung_tage_offen()`); unbestätigte **Portal**-Bestellungen ab **3 Tagen** als **überfällig · T+N** (rot, `bestellung_ueberfaellig()`). Über der Liste ein Panel „N überfällige Bestellung(en)" mit Button **„Überfällige jetzt erinnern"** (POST `aktion=erinnern_alle` → `einkauf_erinnerungen_cron(3,0)`, Mail an alle überfälligen Portal-Lieferanten). **Storno** (`aktion=storno` → `bestellung_stornieren()`): markiert storniert, informiert einen Portal-Lieferanten (Chat+Mail); der Button sitzt auf der Bestelldetailseite. Nach dem Stornieren verschwindet die Bestellung aus der laufenden Liste (storniert ist ausgeblendet).

**Automatische Erinnerung (Cron):** `public/einkauf_cron.php?token=<einkauf_cron_token()>` (kein Login, Token aus `app_meta`) ruft `einkauf_erinnerungen_cron()` auf – erinnert alle überfälligen, unbestätigten Portal-Bestellungen, je Bestellung höchstens 1×/24 h (`bestellung.erinnert_am`). Die URL zeigt ein Admin-Aufklappkasten über der Liste; ein Server-Cron (z. B. täglich) muss sie aufrufen.
