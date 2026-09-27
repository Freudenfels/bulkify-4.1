# bestand/dok.php – Dokument anzeigen (`?p=dok&id=`)

Liefert ein Dokument (Lieferschein, CoA, Spec …) aus der Dashboard-Ablage `data/uploads` (Konstante `BX_UPLOADS`) direkt im Browser aus. Nur für Angemeldete, nur Dateien aus dem Upload-Ordner (kein `../`). PDF und Bilder werden inline angezeigt. Verlinkt aus der Charge-Detailseite.
