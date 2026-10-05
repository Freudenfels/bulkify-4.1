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
`dienstleistung_schema()` wird aus `init_schema()` (core/schema.php) per **einer** guarded Zeile aufgerufen: `if (function_exists('dienstleistung_schema')) dienstleistung_schema();`. So brechen andere Einstiegspunkte (`public/ds_api.php`, `tools/*`) ohne diese Datei nicht ab. Legt additiv an: `angebot_position.dienstleistung_id` sowie den Marker `kategorie` (Default `produkt`) auf `angebot`, `auftrag`, `beleg`.

**Wichtig – Schema-Cache:** `init_schema()` überspringt die Migrationen per Schnell-Pfad, solange sich die mtime ändert-Marke nicht ändert. Der Marker bezieht **auch `core/dienstleistung.php`** ein (`$schemaBuild = mtime(schema.php) + mtime(dienstleistung.php)`). Heißt: Änderungen an `dienstleistung_schema()` lösen die Migration beim nächsten Deploy automatisch genau einmal aus. (Ohne das liefen neue Spalten aus dieser Datei nie.)

## DL-Vorgangskette: Angebot (DA-) -> Auftrag (DB-) -> Rechnung (DR-)
Spiegelt den Produkt-Weg (`auftrag_aus_angebot` / `rechnung_aus_auftrag`), aber mit eigenen Nummernkreisen und `kategorie='dienstleistung'`. Dieselben Tabellen (`angebot`/`auftrag`/`beleg`) → PDF, E-Rechnung, DATEV, USt, Zahlungen laufen mit; die DL-Rechnung ist ein normaler Beleg und erscheint im zentralen Kassenbuch der Buchhaltung. Getrennte Listen gibt es im DL-Modul.
- `dl_subtabs($aktiv)` – Untermenue (Katalog/Angebote/Aufträge/Rechnungen)
- `dl_angebote_alle()` / `dl_angebot_laden()` / `dl_positionen()` / `dl_angebot_summe()`
- `dl_angebot_neu()` / `dl_position_add()` / `dl_position_update()` / `dl_position_del()` / `dl_angebot_status()`
- `dl_auftrag_aus_angebot()` – DB- aus bestätigtem DA- (idempotent)
- `dl_rechnung_aus_auftrag()` – DR- aus DB-, kopiert die Positionen (idempotent, verweigert ohne Betrag)
- `dl_auftraege_alle()` / `dl_auftrag_laden()` / `dl_rechnungen_alle()`

Produkt-Listen (`module/angebot/liste.php`, `module/auftrag/liste.php`) blenden DL-Vorgänge per `COALESCE(kategorie,'produkt')<>'dienstleistung'` aus. Das Kundenportal zeigt DL-Angebote ohnehin nicht (kein `produkt_id`, keine Rezeptur-Position, `preise_kunde=0`).

## Wichtige Funktionen
- `dienstleistung_kategorien() / _preismodelle() / _arten() / _wiederkehr() / _bausteine()` – Dropdown-Quellen
- `dienstleistung_label($liste,$key)` – Label mit sicherem Fallback
- `dienstleistungen_alle($nur_aktiv)` / `dienstleistung_laden($id)`
- `dienstleistung_preis_text($d)` – lesbarer Preis für Listen
- `dienstleistung_startseed()` – legt die 4 Start-Dienstleistungen an (idempotent, nur per Knopf – kein Auto-Seed beim Seitenaufruf, Regel `seed_demo_off`)

## Stand / nächste Schritte
- Phase 1 (fertig): Katalog anlegen/pflegen.
- Phase 2 (fertig): eigenständige DL-Kette Angebot (DA-) → Auftrag (DB-) → Rechnung (DR-) mit eigenen Nummernkreisen; DL-Rechnung = normaler Beleg → Buchhaltung.
- offen: DL-Angebot-PDF (nutzt derzeit `?p=angebot_pdf`, Positionen haben Vorrang), DL-Angebote im Kundenportal sichtbar/bestätigbar, Anfrage (`portal_anfrage.typ='dienstleistung'`) → DL-Angebot, Add-on-DL auf Produktangebot.
- Phase 3: wiederkehrende (monatliche) Abrechnung (Lagerung/Fulfillment).
