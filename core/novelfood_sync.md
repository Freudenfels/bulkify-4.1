# core/novelfood_sync.php – EU-Novel-Food-Abgleich (automatisch)

Holt den EU-Novel-Food-Katalog **direkt** aus der offenen JSON-API der Kommission (keine Anmeldung),
übersetzt neue/geänderte Beschreibungen per KI ins Deutsche (nur das Delta) und schreibt die Lauf-Historie.
Derselbe Job steckt hinter dem Admin-Button (`module/produkt/novelfood_import.php`, Aktion `eu_sync`)
und hinter der Monatsroutine (`tools/novelfood_sync.php`).

## Wichtige Funktionen
- `novelfood_api_url()` – die EU-API-URL (überschreibbar via `app_meta['novelfood_api_url']`).
- `novelfood_status_de($code)` – Statuscode → deutsches Label (muss zur Ampel/Farbe im Modul passen).
- `novelfood_flatten($item)` – den verschachtelten `policyItemObject`-Baum flachklopfen (nur Blätter).
- `novelfood_api_map($f)` – geflachten Eintrag auf unser Rohformat abbilden. **Interne EU-Felder
  (`internalComments`, `createdBy`, `lastModifiedBy`, `displayName`) werden bewusst NICHT übernommen.**
- `novelfood_api_laden($url?)` – Katalog laden (curl, TLS-Verify an). Rückgabe `['ok','eintraege','stand','fehler']`.
- `novelfood_delta_uebersetzen(&$norm,$indices)` – nur die fehlenden Beschreibungen übersetzen
  (`core/ki.php`, schnelles Modell, chunkweise). Füllt `beschreibung_de` in `$norm`.
- `novelfood_export_schreiben($norm,$stand)` – legt `data/novelfood_export.json` im **Website-Schema** ab
  (dieselbe Datei, die `novel-food.html` lädt).
- `novelfood_sync_lauf($ausgeloest_von='manuell', $benutzer=null)` – **der Job**: Lauf anlegen → API laden →
  normalisieren + vorhandene Übersetzungen wiederverwenden → Delta übersetzen → Diff gegen DB → Änderungen
  protokollieren (`novelfood_change`) → Upsert (`novelfood_uebernehmen`) → Website-Export → Lauf abschließen.
  Idempotent: ein zweiter Lauf direkt danach meldet 0 neu / 0 geändert.

## Delta-Logik (hält die Übersetzung billig)
Für jeden Eintrag: existiert er schon (per `code`/Name) und ist die englische Beschreibung unverändert,
wird das vorhandene `beschreibung_de` **wiederverwendet** – keine Neuübersetzung. Nur neue Einträge und
Einträge mit geänderter englischer Beschreibung landen auf der Übersetzungsliste.

## Hinweise
- TLS-Verify bleibt an (Sicherheit). Lokal ohne CA-Bundle: `php -d curl.cainfo=<ca-bundle.crt> …`.
- Ohne KI-Schlüssel (`secrets.php` ANTHROPIC_API_KEY) wird nicht übersetzt; der Lauf läuft trotzdem durch,
  betroffene Einträge bleiben ohne Deutsch (später per erneutem Lauf nachholbar).
- Marker in `app_meta`: `novelfood_last_run`, `novelfood_last_lauf_id`, `novelfood_next_run`.
