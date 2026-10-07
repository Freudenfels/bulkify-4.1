# bestand/l2_eingang.php – Lager 2: Kundenware einbuchen (`?p=l2_eingang`)

Eigener **Menüpunkt** unter Lager 2 (nicht mehr nur Knopf im Bestand). Pflicht: **Kunde** (wem gehört die
Ware) + **Typ** (`erp_l2_typ_defs()`: Verkaufsprodukt · Rohstoff · Etikett · Beipackzettel · Pouchbag ·
Rollenware (Stick) · Karton · Sonstiges). Der Typ **filtert die Artikelliste** (`erp_items_l2()` liefert
Kategorie + Verpackungs-Rolle + Verpackungsart) und bestimmt beim **Neu-Anlegen** die `item.kategorie`
(+ `verpackung_rolle` + `verpackungsart`):

| Typ | kategorie | Rolle | Art | neu anlegen? |
|---|---|---|---|---|
| Verkaufsprodukt | verkaufsfertig | – | – | nein (nur bestehende; gehören zum Produkt-Lebenszyklus) |
| Rohstoff | rohstoff | – | – | ja |
| Etikett | verpackung | etikett | – | ja |
| Beipackzettel | verpackung | beipack | – | ja |
| Pouchbag | verpackung | primaer | beutel | ja |
| Rollenware (Stick) | verpackung | primaer | stick | ja |
| Karton | karton | – | – | ja |
| Sonstiges | sonstiges | – | – | ja |

Pouchbag und Rollenware sind Primärverpackung (`verpackung_rolle=primaer`) und werden über die
**Verpackungsart** (`beutel` bzw. `stick`) getrennt – sonst würden beide dieselbe Liste zeigen.

Bucht dann eine Charge für den Kunden (`erp_wareneingang_buchen_fremd()`, Status **frei**, keine
Quarantäne), schreibt die Bewegung (mit Typ-Label) und bindet – **falls angegeben** – den Blinker
(leuchtet grün). Kunden = `erp_fulfillment_kunden()` (`kunden.nutzt_fulfillment`).

**Regeln Nico:**
- **Blinker ist optional** – Feld darf leer bleiben; dann wird keine Leiste gebunden. Ein eingegebener
  Code muss gültig sein (6 Zeichen), sonst Hinweis.
- **Verkaufsfertige Produkte haben keine Pakete/Kartons** – bei Typ „Verkaufsprodukt" wird das Feld
  „Anzahl Pakete / Kartons" ausgeblendet und die Paketzahl fest auf 1 gesetzt. Für die anderen Typen
  (Rohstoff, Etikett, …) bleibt die Paketzahl wählbar.
