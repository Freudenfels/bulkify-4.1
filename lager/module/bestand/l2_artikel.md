# bestand/l2_artikel.php – Lager-2-Artikelkatalog (Liste)

Route `?p=l2_artikel` (Nav Lager 2). Stammdaten-Katalog je Fulfillment-Kunde: Liste mit **Kunden-Reitern**
(`erp_fulfillment_kunden()`) + Suche (Name/EAN/Kunden-SKU, `lg_artikel_liste()`). Je Zeile: Etikett-Bild
(Thumbnail über `?p=bild`), Name (+ Kunden-SKU), Typ, Verkaufsartikel, Gewicht, Maße (L×B×H), EAN;
anklickbar → `?p=l2_artikel_edit`. „+ Neuer Artikel" oben.
