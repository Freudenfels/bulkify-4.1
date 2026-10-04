# public/crm/kontakt_doc.php – Download eines Kontakt-Dokuments

Liefert eine am Kontakt abgelegte Datei aus (`crm_kontakt_datei`, gespeichert in `data/kontakt_datei`,
außerhalb des Webroots). **Nur für angemeldete Mitarbeiter** (`crm_angemeldet()`, sonst 403).

Aufruf: `kontakt_doc.php?id=<datei-id>`. PDF/Bilder/Text werden inline angezeigt, alles andere als
Download. Es wird nichts geschrieben – reine Ausgabe. Upload/Löschen laufen über die Kontaktseite
(`crm/module/kontakt/detail.php`, `kontakt_datei_speichern()` / `kontakt_datei_loeschen()`).
