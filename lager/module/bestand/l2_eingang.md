# bestand/l2_eingang.php – Lager 2: Kundenware einbuchen (`?p=l2_eingang`)

Eigener **Menüpunkt** unter Lager 2 (nicht mehr nur Knopf im Bestand). Pflicht: **Kunde** (wem gehört die
Ware) + **Typ** (`erp_l2_typ_defs()`: Verkaufsprodukt · Rohstoff · Etikett · Beipackzettel · Karton ·
Sonstiges). Der Typ **filtert die Artikelliste** (`erp_items_l2()` liefert Kategorie + Verpackungs-Rolle)
und bestimmt beim **Neu-Anlegen** die `item.kategorie` (+ `verpackung_rolle`):

| Typ | kategorie | Rolle | neu anlegen? |
|---|---|---|---|
| Verkaufsprodukt | verkaufsfertig | – | nein (nur bestehende; gehören zum Produkt-Lebenszyklus) |
| Rohstoff | rohstoff | – | ja |
| Etikett | verpackung | etikett | ja |
| Beipackzettel | verpackung | beipack | ja |
| Karton | karton | – | ja |
| Sonstiges | sonstiges | – | ja |

Bucht dann eine Charge für den Kunden (`erp_wareneingang_buchen_fremd()`, Status **frei**, keine
Quarantäne), setzt Paketzahl, schreibt die Bewegung (mit Typ-Label) und bindet den **Blinker** (Pflicht,
leuchtet grün). Kunden = `erp_fulfillment_kunden()` (`kunden.nutzt_fulfillment`).
