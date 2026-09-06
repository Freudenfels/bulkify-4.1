# module/einkauf/ek_import.php – EK-Preise (Import)

Seite `?p=ek_import` (Nav: Einkauf → „EK-Preise (Import)"; Rolle einkauf/admin).
Zeigt die aus CSV eingelesenen Einkaufspreise (Tabelle `ek_import`).

## Zwei Reiter (`?typ=`)
- **Rohstoffe / Bulk** – Name (CSV) · Lieferant · EK (EUR/kg) · Zuordnung → Rohstoff.
- **Fertigprodukte – intern** – Produkt · Größe · Formulierung · Kapsel-EK · Menge ·
  Lieferant · Zuordnung → Produkt. **Zukauf-Preise, nie in der Kundensicht.**

Suche über Name/Lieferant/Formulierung; Filter „nur nicht zugeordnete".
„Zuordnung" verlinkt den v4-Rohstoff/das Produkt, sobald `item_id`/`produkt_id` gesetzt ist.

## Datenfluss
1. `tools/ek_import.php` füllt `ek_import` aus den CSVs (Rohnamen, noch ohne Zuordnung).
2. Zuordnung (manuell/KI auf beta) setzt `item_id`/`produkt_id` + `status`.
3. Bestätigte Rohstoff-Zeilen → `lieferant_preis` → „Preis ab"/Lieferanten-Marker in der
   Rohstoffliste. Fertigprodukt-Zeilen = interne Fertigprodukt-Preisliste.

Der KI-Zuordnungsschritt (Vorschlag je Zeile, Bestätigung von Hand) folgt und läuft auf
beta (KI nur dort).
