# bestand/l2_artikel_edit.php – Lager-2-Artikel anlegen/bearbeiten

Route `?p=l2_artikel_edit&id=` (bzw. `&kunde=` für neu). Formular für alle Stammdaten eines Katalog-Artikels:
**Kunde** (Pflicht), **Typ** (`lg_artikel_typen()`: Verkaufsprodukt/Rohstoff/Etikett/Beipackzettel/Karton/Sonstiges), **Name**, **Verkaufsartikel**-Haken,
optionale **Bestand-Verknüpfung** (Verkaufsfertig-Item des Kunden, `erp_kunde_verkaufsfertig()`), **Gewicht (g)**,
**Maße** L/B/H (mm), **EAN**, **Kunden-SKU**, **Mindestbestand**, **Produktionszeit (Tage, Override)**,
**Etikett-Bild** (Upload, JPG/PNG/GIF/WEBP → `data/uploads`, Anzeige über `?p=bild`), **Notiz**.

`aktion=speichern` → `lg_artikel_anlegen`/`lg_artikel_speichern`, danach Bild verschieben + `lg_artikel_bild_set`
(altes Bild wird ersetzt). `aktion=loeschen` → `lg_artikel_del` (+ Bilddatei entfernen). Zahlen deutsch
(Komma), leer = NULL.
