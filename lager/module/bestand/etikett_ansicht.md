# bestand/etikett_ansicht.php – Etikett-Ansicht mit Zurück (`?p=etikett_ansicht`)

Normale In-App-Seite statt rohem Vollbild-PDF (in der PWA gab es sonst kein Zurück). Zeigt das Etikett
als eingebettete Vorschau + Knöpfe: **Zurück** (Ziel via `&zurueck=`), Größe 100×70/100×150 (schaltet
die Vorschau in-place + merkt Standard), **Öffnen** (neuer Tab), **Direkt drucken** (`?p=druck_job`).
`&id=` oder `&ids=`. Die „Etikett"-Links in Bestand/Lager-2 zeigen hierher.
