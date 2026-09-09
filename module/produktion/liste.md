# produktion/liste.php – Produktions-Liste

**Zweck:** Übersicht aller Produktionsaufträge (PR-). Entstehen **automatisch** mit dem Auftrag – man legt sie nicht von Hand an.

**Was passiert hier:**
- Liest alle Produktionsaufträge inkl. Kunde, Produkt (Joins) und – per Unterabfrage – **Fortschritt** (erledigte / gesamte Stationen) und **nächste offene Station**.
- **Suche** nach Nummer, Kunde, Produkt. **Sortierung** Standard = neueste zuerst.
- Tabelle: **Nummer · Kunde · Produkt · Menge · Fortschritt · Nächste Station · Status** (offen / läuft / fertig).
- Klick öffnet den Produktionsauftrag (`?p=produktionsauftrag&id=...`).

## Sammel-Umstellung Eigen-/Fremdproduktion
Checkbox-Spalte + Spalte „Art" (Eigen/Fremd). Unten „alle markieren" und zwei Buttons
„Markierte auf Eigenproduktion / auf Fremdproduktion (Zukauf)" (`aktion=art_bulk`).
Ruft je Auftrag `produktionsauftrag_art_setzen()` (setzt produktionsart + regeneriert die
Schritte: eigen=voller Weg, fremd=verkürzter Zukauf-Weg). Bereits begonnene Aufträge
(ein Schritt erledigt) werden übersprungen und gemeldet. Nötig, weil importierte
Produktionsaufträge per Default auf „fremd" stehen.

## Spalte „Kapsel/Tablette"
Zeigt je Auftrag direkt die Größe (`produktion_groesse_label()` in `core/schema.php`): bei
Kapsel/Softgel die gepflegte Kapselgröße, sonst die **kleinste passende** aus dem Füllgewicht
der Rezeptur berechnet (Zusatz „(berechnet)"); bei Tablette das Füllgewicht in mg. Bei
Pulver/Stick/Flüssig steht „–".
