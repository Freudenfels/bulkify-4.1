# core/novelfood.php – Novel-Food-Katalog einlesen, diffen, übernehmen

Gemeinsame Helfer für den Novel-Food-Katalog (`novelfood_katalog`). Genutzt vom Datei-Import
(`module/produkt/novelfood_import.php`, `tools/novelfood_import.php`) **und** vom EU-Direktabgleich
(`core/novelfood_sync.php`). Idempotent über `code` (sonst Name).

## Funktionen
- `novelfood_clean($s)` – HTML strippen, Entities dekodieren, Whitespace normalisieren; leer → null.
- `novelfood_normalisieren($e)` – Rohdatensatz auf die DB-Felder bringen. Liefert null ohne Name.
  Felder: code, name, trivial, syn, status, status_code, teil, **beschreibung_de** (Deutsch) und –
  getrennt davon – **beschreibung** (englisches Original), **pub**, **erstellt**, **geaendert**
  (die vier Zusatzfelder kommen aus dem EU-Abgleich; der Datei-Import lässt sie i. d. R. leer).
  Deutsch und Englisch werden NICHT mehr vermischt.
- `novelfood_aus_datei($pfad)` – JSON (`{eintraege:[…]}` oder Array) oder CSV mit Kopfzeile lesen.
- `novelfood_finden($d)` – bestehende DB-Zeile zu einem Datensatz finden (code, sonst name).
- `novelfood_diff($eintraege)` – neu / geaendert (mit `felder` + `status_neu`) / gleich / ungueltig / gesamt.
- `novelfood_uebernehmen($eintraege)` – Upsert; schreibt auch beschreibung/pub/erstellt/geaendert mit.

## Tabelle novelfood_katalog
Grundfelder in `core/schema.php init_schema()`; die Zusatzspalten (beschreibung, pub, erstellt, geaendert)
werden dort additiv per `ensure_column()` ergänzt.
