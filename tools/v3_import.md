# tools/v3_import.php – gezielter v3 → v4 Datenimport

Übernimmt aus der **v3-SQLite** (`board.sqlite`) nur die **Kunden mit Aktivität** (Rezeptur, Produktanfrage oder Auftrag) samt ihrer Rezepturen. Der interne „Kunde" (v3 `kunden.intern=1`) wird übersprungen.

## Aufruf
Die lokale PHP braucht den SQLite-Treiber (nur per Flag geladen):
```
php -d extension_dir=C:\php\ext -d extension=php_pdo_sqlite.dll tools/v3_import.php "PFAD/board.sqlite" [--write]
```
- **ohne `--write`** = Trockenlauf: liest nur, schreibt nichts, zeigt pro Kunde was reinkäme + Nacharbeitsliste.
- **mit `--write`** = Stufe 1 wird geschrieben.

## Grundsätze
- **`v3_id`** an jeder Zieltabelle (`kunden`, `rezeptur`) → **idempotent**, beliebig oft wiederholbar ohne Dubletten (Re-Run = Update).
- **Trockenlauf zuerst**, dann schreiben. Zuerst gegen die **lokale** v4-DB, danach mit demselben Skript gegen **beta**.
- Textfelder werden auf die v4-Spaltenbreite gekappt (`cut()`), NULL bleibt NULL.

## Was Stufe 1 macht
- **Kunde** → `kunden` (firma, email, Portal an; v3-`lieferadresse` als Notiz + Nacharbeit „Adresse aufteilen"). `v3_id` gesetzt.
- **Rezeptur** (`rezepte`) → `rezeptur`: Name, Darreichungsform (Kapsel/Pulver/Flüssig…), Kapselgröße (v3 „0"/„00" → „Größe 0/00"), **`kunde_id` = Herkunft, `exklusiv=0`, `status='freigegeben'`** → „eigene Rezeptur beim Kunden, aber für alle frei". Bestätigung als Info: `freigabe_name`/`freigabe_am` aus `rezept_kunde` (nur wenn wirklich bestätigt, sonst NULL).
- **Zutaten** (`rezept_zutaten`) → `rezeptur_zutat`: Bezeichnung + Menge (mg). Versuchte Verknüpfung zu einem v4-Rohstoff per Name; kein Treffer → Freitext-Zutat (Nacharbeit: später verknüpfen).

## Stufe 2 (Produkte + Kundenpreise)
Aus `produktanfrage`: je referenzierter Rezeptur ein **Produkt-Anker** (`produkt`, `v3_id` = v3-Rezept) und je Anfrage eine Zeile in **`produkt_kundenpreis`** (Kunde · Menge je VPE · Anzahl VPE · Verpackung(Freitext) · Preis). Preis wird aus `angebot_preis` geparst (`"10,63"`, `"4,41€"`). Idempotent über `produkt_kundenpreis.v3_id` = v3 `produktanfrage.id`. Anzeige: Panel **„Kundenpreise"** auf der Produkt-Detailseite (`module/produkt/detail.php`) – schneller Überblick, welcher Kunde welchen Preis hat. Anfragen, deren Rezept nicht importiert wurde (z. B. interner Kunde), werden übersprungen (`ohne_produkt`).

## Modell-Ergänzung
Nutzt `rezeptur.exklusiv` (analog `produkt.exklusiv`): `exklusiv=0 + freigegeben` = Katalog/für alle; `exklusiv=1 + kunde_id` = nur dieser Kunde. Einmaliger Backfill in `init_schema()` setzt bestehende Kunden-Rezepturen auf `exklusiv=1` (Verhalten unverändert). Sichtbarkeit im Kundenportal entsprechend angepasst.

## Stufe 3 (Lieferantenseite)
- **Lieferanten** (`lieferanten`, 3) → v4 `lieferanten` (Firma, Kontakt, Land als ISO-2, Kategorien, Sprache). `v3_id`.
- **EK-Preisliste** (`preisliste`, 635) → neue Referenztabelle **`lieferant_preisliste`** (Rohstoffname · Lieferant · EUR/kg · Stand). Bewusst OHNE Lager-Verknüpfung. Seite: `?p=lief_preisliste` (Menü Einkauf → EK-Preisliste), durchsuchbar.
- **Bestellungen** (`bestellungen`, alle 13) → v4 `bestellung` + eine `bestellung_position` (Artikel als Freitext, `item_id` NULL). Status gemappt (offen/bestellt/geliefert), v3-Status in der Notiz, Tracking übernommen. `v3_id`.
- **Nicht übernommen:** v3 `lieferant_angebot` (Angebote je Rezeptur) und `rohstoff_chargen` – beide setzen die v3-Rohstoffe voraus (Chargen brauchen in v4 zwingend einen Lagerartikel), die bewusst nicht importiert werden.

## Stufe 0 (Rohstoffe) – mit Dublettenschutz
Übernimmt die v3-`rohstoffe` (Kategorie rohstoff, 1.161) nach v4 `item` (Name de/en/lat, Form aus `art`, EK aus `kilo_preis`, Herkunft). Läuft VOR den Rezepturen, damit sich die Zutaten gleich verknüpfen. **Keine Dubletten:** (1) idempotent über `item.v3_id`; (2) bei gleichem normalisiertem Namen wird der bestehende v4-Rohstoff verknüpft statt neu angelegt. Verifiziert: 0 doppelte Namen, 0 doppelte v3_id, 2. Lauf legt nichts Neues an. Qualitätsfelder (CAS/EC/…) bleiben leer (Nacharbeit/Spec-KI). 136 Slash-Bündelnamen kommen als ein Eintrag.

## Stufe 4 (Lieferanten-Angebote pro Rezeptur)
Aus `lieferant_angebot` → neue Tabelle `rezeptur_lief_angebot` (rezeptur_id, lieferant_id, preis, einheit, menge, status, angenommen_am, v3_id). Nur Angebote, deren Rezeptur importiert wurde (sonst `ohne_rezeptur`). Anzeige: Panel „Lieferanten-Angebote (Fremdfertigung)" auf der Rezeptur-Detailseite. Idempotent über v3_id. Hinweis: v3 hatte bei den meisten Angeboten keinen echten Preis (0/leer) – nur die echten Preise werden hervorgehoben.

**Nicht möglich:** Rohstoff↔Lieferant↔Preis in `lieferant_preis` – v3 hat keine solche Quelle (rohstoffe.kilo_preis leer, preisliste ohne Lieferant + andere Schreibweise). Die 635 EK-Preise bleiben die Referenzliste.

## Stufe 5 (Aufträge) + Hausrezepturen
- **Hausrezepturen** (v3 `kunde_id` NULL/0, 16) werden in Stufe 1 als Katalog-Rezepturen (kunde_id NULL, exklusiv=0, freigegeben) mitimportiert – Aufträge/Produktanfragen referenzieren sie.
- **Aufträge** (`auftraege`, 27; interner Kunde übersprungen) → v4 `auftrag`: Kunde per Firmenname, Produkt über `rezept_id`→Produkt-Anker (fehlend? wird angelegt), Menge=anzahl_vpe × Stück=menge_pro_vpe, Verpackung per Name (sonst NULL), Status aus v3-Stufen-Flags abgeleitet (versand→versendet, verpackt→erledigt, produziert→in_produktion, sonst offen). Idempotent über `v3_id`. Anzeige im Kunden-Reiter „Bestellungen". Ohne Produkt bleiben nur Aufträge, deren Rezept in v3 fehlt (Nacharbeit).

## Stufe 6 (Angebote)
Aus `produktanfrage` → v4 `angebot` (+ `angebot_position`): Kunde · Produkt/Rezeptur · Menge/Stück · Preis (aus angebot_preis). Status: bestätigt (angenommen), gesendet (Angebot liegt vor), abgelehnt. Reine Anfragen ohne Angebot (status offen, kein Preis) werden übersprungen. Angenommene Angebote werden mit ihrem Auftrag verknüpft (v3 auftraege.anfrage_id → angebot.v3_id → auftrag.angebot_id). Idempotent über v3_id. So sieht man im Kunden-Reiter „Angebote", was der Kunde vorliegen hat (gesendet) und hatte (bestätigt/abgelehnt).

## Wichtig: aktueller Export nötig + Mehr-Staffel-Angebote
Die zuerst gelieferte `board.sqlite` war ein **älterer Stand** (neueste Daten ~08.07.2026) ohne die Tabelle `produktanfrage_staffel`. Die Live-v3 (app.bulkify.pro) hat neuere Angebote **mit mehreren Preis-Staffeln** (z. B. 1.500/2.500/3.500 Pkg. zu unterschiedlichen Preisen). Für die echte Migration daher einen **frischen Export** der Live-DB verwenden.
Der Importer ist darauf vorbereitet: existiert `produktanfrage_staffel`, werden in Stufe 6 **alle** Preis-Stufen als `angebot_staffel` übernommen (Menge=anzahl_vpe, Preis, gewählt→bestätigt); sonst Fallback auf die eine Konfiguration.

## Quelle: SQLite ODER MySQL (aktueller Stand)
Die Live-v3 (app.bulkify.pro) läuft auf **MySQL** (die alte `board.sqlite` war eingefroren, Stand ~09.07.2026). Für den aktuellen Stand den **MySQL-Dump** aus phpMyAdmin (Export → SQL) lokal einspielen und den DB-Namen als Quelle übergeben:
```
# Dump lokal einspielen (legt seine DB per CREATE DATABASE/USE selbst an, z. B. dbs15879489):
"C:/Program Files/MariaDB 12.3/bin/mysql.exe" -u root -p<pass> < dump.sql
# dann dem App-DB-User Rechte geben und importieren:
php tools/v3_import.php dbs15879489 --write
```
Ist die Quelle eine Datei → SQLite; sonst → MySQL-DB-Name (`mysql:host=…;dbname=…`, App-Zugangsdaten). `v3_preis()` parst „10,63", „4,41€" und „10,95 € / Pkg.". Mehr-Staffel-Angebote aus `produktanfrage_staffel` werden mitgenommen (getestet: AP Baobab 1.500/2.500/3.500 → 10,95/10,59/10,38 €).

## Kunden-Bündelung (Marke vs. Firma)
Manche v3-„Kunden" sind in Wahrheit **eine** Firma unter verschiedenen Namen/Marken. Der Importer führt sie zusammen: `$KUNDE_KANON` legt pro kanonischer v3-Id `firma` (echte Firma) und `marke` (Markenname) fest, `$KUNDE_ALIAS` mappt weitere v3-Ids auf den Kanon. Rezepturen, Angebote, Aufträge und Kundenpreise der Alias-Kunden landen beim Kanon-Kunden; die Marke wird als White-Label-Eintrag in `kunde_marke` gepflegt (idempotent). Ein Alias-Datensatz aus einem früheren Lauf wird automatisch umgehängt und gelöscht. Aufträge werden per Firmenname gematcht – die Map wird darum aus **allen** v3-Firmennamen gebaut und auf den Kanon gezeigt, damit auch alte Marken-/Zweigstellen-Namen den gemergten Kunden treffen.
Aktuell konfiguriert: v3 #3 Annapurna + #603/#656 (PURE HEALTH ALLIANCE INC. / Zweigniederlassung) → **Firma „Pure Health", Marke „Annapurna"**. Marke ist im Kundenkopf, in der „Marken & Webseiten"-Kachel und in der Kundenliste (Spalte + Suche) sichtbar. Angebote gehen immer an die Firma.
