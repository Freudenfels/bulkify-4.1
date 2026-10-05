# core/dienstleistung.php – Dienstleistungen-Modul (Logik + Schema)

Eigene Datei, damit die geteilten Dateien (`schema.php`, `index.php`, `layout.php`, `auth.php`) ruhig bleiben und parallele Code-Chats sich nicht in die Quere kommen.

## Was ist eine Dienstleistung?
Alles Verkaufbare, das **nicht** „Produkt/Rezeptur herstellen + ausliefern" ist – eigenständig ODER als Zusatz zum Produkt. Der Katalog ist (wie beim Produkt) die **einzige Preisquelle**.

## Tabelle `dienstleistung` (Katalog, Stammdaten)
- `nummer` – automatisch `DL-…` (`naechste_nummer('DL')`)
- `name` (Pflicht), `kategorie`, `beschreibung` (kundensichtbar)
- `preismodell` – `pauschale | pro_einheit | pro_stunde | monatlich | auf_anfrage`
- `einheit` – nur bei pro_einheit/pro_stunde/monatlich (Stück, Probe, Stunde, Monat …)
- `ek_cent` (intern, nur Marge), `vk_cent` (VK netto), `mwst_satz` (0/7/19)
- `art` – `addon | standalone | beides` (eigenständig verkaufbar vs. nur Zusatz)
- `wiederkehrend` – `einmalig | monatlich` (monatliche Abrechnung erst Phase 3)
- `baustein` – Verweis auf einen schon vorhandenen Service-Baustein (rezepturbewertung, labortest, energetisierung, fulfillment, etikettcheck), damit Logik **nicht dupliziert** wird
- `aktiv`, `sort`, `notiz`

Preise werden **in Cent** gespeichert (wie überall). Eingabe-Umrechnung: `dienstleistung_cent()`, Anzeige: `dienstleistung_eur()`.

## Schema-Einbindung
`dienstleistung_schema()` wird aus `init_schema()` (core/schema.php) per **einer** guarded Zeile aufgerufen: `if (function_exists('dienstleistung_schema')) dienstleistung_schema();`. So brechen andere Einstiegspunkte (`public/ds_api.php`, `tools/*`) ohne diese Datei nicht ab. Legt zusätzlich additiv die Spalte `angebot_position.dienstleistung_id` an (Vorbereitung Phase 1b).

## Wichtige Funktionen
- `dienstleistung_kategorien() / _preismodelle() / _arten() / _wiederkehr() / _bausteine()` – Dropdown-Quellen
- `dienstleistung_label($liste,$key)` – Label mit sicherem Fallback
- `dienstleistungen_alle($nur_aktiv)` / `dienstleistung_laden($id)`
- `dienstleistung_preis_text($d)` – lesbarer Preis für Listen
- `dienstleistung_startseed()` – legt die 4 Start-Dienstleistungen an (idempotent, nur per Knopf – kein Auto-Seed beim Seitenaufruf, Regel `seed_demo_off`)

## Stand / nächste Schritte
- Phase 1 (fertig): Katalog anlegen/pflegen.
- Phase 1b (offen): Dienstleistung als Angebotsposition (`quelle='dienstleistung'`, `dienstleistung_id`) im Angebots-Editor.
- Phase 2: eigenständiges DL-Angebot + Portal + Anfrage→Angebot.
- Phase 3: wiederkehrende (monatliche) Abrechnung (Lagerung/Fulfillment).
