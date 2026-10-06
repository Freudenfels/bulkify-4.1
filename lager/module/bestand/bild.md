# bestand/bild.php – Bild aus data/uploads ausgeben (`?p=bild&f=`)

Gibt eine Bilddatei aus dem geteilten Upload-Ordner (`BX_UPLOADS` = `data/uploads`) inline aus – nur für
Angemeldete (Login-Gate in `index.php`), nur Bildtypen (jpg/png/gif/webp), nur `basename` (kein `../`).
Genutzt für die Etikett-Bilder der Lager-2-Artikel (`lg_artikel.etikett_bild`).
