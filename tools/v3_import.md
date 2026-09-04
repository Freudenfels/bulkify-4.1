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
