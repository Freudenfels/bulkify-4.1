# lager/dokument_download.php – Dokument ausliefern

Route: `?p=dokument&id=<dokument-id>` (interne Rollen)

Liefert eine Datei aus der generischen Dokumentenablage (`dokument`, Typ CoA/Spec/Analyse) aus `data/uploads` (außerhalb `public`). 404, wenn es den Eintrag oder die Datei nicht gibt.

Für die Kundensicht mit Freigabeprüfung siehe `module/portal/dokument_download.md`.
