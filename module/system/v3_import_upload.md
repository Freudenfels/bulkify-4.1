# v3 neu einlesen (Upload) — `v3_import_upload.php`

Einmalige Migration: v3-Daten in v4 übernehmen, ohne etwas zu löschen. Nur für Admins.
Route: `?p=v3_import_upload` (Einstellungen).

## Zwei Upload-Wege
Das Tool erkennt automatisch, was hochgeladen wird:

- **SQLite (`board.sqlite`)** — v3 lief lange auf SQLite. Die Datei wird nativ abgelegt
  (`data/v3import.sqlite`, gemerkt in `app_meta['v3imp_sqlite']`) und der Importer liest sie
  direkt. Braucht `pdo_sqlite` auf dem Server (v3 lief darauf, also vorhanden).
- **MySQL-Dump (`.sql` / `.sql.gz`)** — phpMyAdmin-Export der v3-DB. Wird intern in Tabellen mit
  Prefix `v3imp_` in die App-DB geladen (kollidiert NICHT mit den echten Tabellen) und über eine
  `V3PrefixPDO` gelesen.

Danach: **Trockenlauf** (nur anzeigen) → **Import schreiben**. Beides ruft `tools/v3_import.php`.

## Wichtig: idempotent, nicht destruktiv
Der Import läuft ohne `--reset`: Bestehendes wird über `v3_id` **aktualisiert**, Fehlendes neu
angelegt. Nichts wird gelöscht. Beliebig wiederholbar. v4-eigene Daten (ohne `v3_id`) bleiben
unberührt. Empfehlung trotzdem: vor dem Schreiben App-DB sichern.

## Abgleich-Panel
Zeigt v3-Quelle ↔ v4 (Rezepturen/Aufträge/Angebote), damit man sieht, dass alles drin ist.
Bewusst NICHT importiert: der interne Kunde (Lagerproduktion) und reine Anfragen ohne Preis —
darum sind es bei „Angebote" weniger als „Anfragen", das ist korrekt.

## Stolperfalle (gelöst)
`V3PrefixPDO` schreibt v3-Tabellennamen per Regex auf `v3imp_` um. Die Namensliste MUSS jede
v3-Tabelle enthalten, die `tools/v3_import.php` liest — **auch die `_staffel`-Kindtabellen**
(z. B. `lieferant_angebot_staffel`, `produktanfrage_staffel`). Fehlt eine, liest der Importer die
falsche (v4-)Tabelle → Absturz mitten im Lauf → unvollständiger Import. Längere Namen stehen in
der Liste vor kürzeren (`lieferant_angebot_staffel` vor `lieferant_angebot`).

## Nach der Migration
Tool wieder entfernen (temporär). „v3-Zwischenstand entfernen" räumt `v3imp_*`-Tabellen und die
abgelegte SQLite weg.
