# produktion/liste.php – Produktions-Liste

**Zweck:** Übersicht aller Produktionsaufträge (PR-). Entstehen normalerweise **automatisch** mit dem Auftrag; zusätzlich lassen sich jetzt **Produktionsaufträge ohne Kundenbezug** von Hand anlegen (Lager-/Vorratsproduktion).

## Neuer Produktionsauftrag (Lagerproduktion)
Button oben rechts **„+ Neuer Produktionsauftrag"** (`?p=produktion&neu=1`) blendet ein Formular ein: **Produkt** (tippbares Feld mit Live-Filter, Label „Name · Nummer · Form"; verstecktes `produkt_id`), **Menge (Packungen)**, **Produktionsart** (Eigen/Fremd, Standard Eigen), **Priorität**. `aktion=neu` legt über `produktionsauftrag_lager_erstellen()` (core/schema.php) einen PR **ohne Kunde/Auftrag** an (`kunde_id`/`auftrag_id` = NULL), erzeugt die passenden Stationen (`produktionsschritte_fuer` je Darreichungsform + Weg) und leitet zum PR-Detail. Der Materialbedarf skaliert wie bei Kundenaufträgen über die Einheiten je Packung des Produkts; die Kunde-Spalte zeigt „–". Die fertige Ware geht als eigener Lagerbestand ein.

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
