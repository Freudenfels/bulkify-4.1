# produkt/novelfood_export.php – Öffentlicher JSON-Export des Novel-Food-Katalogs

Route `?p=novelfood_export` (in `$PUBLIC`, **kein Login**). Liefert den aktuellen `novelfood_katalog`
**frisch aus der DB** als JSON im Website-Schema (identisch zu `novelfood_export_schreiben()` in
`core/novelfood_sync.php`): `{stand, anzahl, quelle, sprachen, eintraege:[{name,code,pub,teil,status,
status_code,trivial,syn,beschreibung,beschreibung_de,erstellt,geaendert}]}`.

- `stand` = `app_meta['novelfood_last_run']` (Datum) bzw. heute.
- `Content-Type: application/json`, `Cache-Control: max-age=3600`.
- Zweck: Die (statische) Website zieht den übersetzten Katalog monatlich; sie kann selbst nicht übersetzen.
- Daten sind öffentlich (EU-Katalog) → kein Token nötig (Muster wie `rohstoffe_public` / `ki_job`).
