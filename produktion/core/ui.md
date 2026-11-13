# produktion/core/ui.php
Anzeige-Helfer: `h()`, `fmt_zeit()` (UTC→Europe/Berlin), `menge_txt()`, `weiter()`, `json_antwort()`, `flash()/flash_zeigen()`, `hinweis()`, `seitenkopf()`, `pa_badge()` (Status eines Produktionsauftrags; Werte wie im Dashboard: `offen`/`laufend`/`erledigt`, Alt-Varianten `in_arbeit`/`fertig` bleiben abgedeckt), `bereit_badge()` (Produktionsbereitschaft `bereit`/`wartet`/`laeuft`/`fertig` → „produzierbar/wartet auf Material/…").

## menge_einh_txt($menge, $einheit)
Menge mit Einheit, Schüttgut lesbar: `g` ab 1.000 als kg (ab 1.000.000 als t), `ml` ab 1.000 als L, sonst `menge_txt()` + Einheit. Genutzt in Werk-App, Lauf-Ansicht und PA-Detail für Material-, Bestands- und Fehlmengen (vorher „600.000 Stk" bzw. „600.000 g").

`pr_produkt_zelle($pa)` (08.11.2026): Produkt-Zelle der schmalen Tabellen – Produktname fett (+ Form), darunter klein PA-Nr · Auftrag · Kunde. Ersetzt die Spalten Nr. und Kunde in Aufträge, Produzieren, Übersicht, Archiv, Kalender.

## Pflichtschritte der Werk-App (13.11.2026)
`werk_pflicht($nr, $art, $name, $text, $T, $o)` = EIN Baustein für alle Pflichtschritte: große Kachel über die volle Breite, Nummer links, Text, rechts grauer Kreis (offen) bzw. grüner Haken (erledigt, Kachel grün umrandet).
- `bestaetigen`: ganze Kachel ist die Tippfläche (nochmal tippen = zurück), sendet `$name=1`.
- `foto`: großer Knopf „Foto aufnehmen“ mit Kamera-Symbol (`capture="environment"`), Datei-Feld unsichtbar; danach Vorschaubild, grüner Haken, Knopf „Neu aufnehmen“.
- `feld`: Kachel mit eigenem Feld (`$o['feld']`), erledigt sobald es einen Wert hat. `$o['zusatz']` = HTML unter dem Text.
`werk_pflicht_assets($T)` gibt CSS + Skript einmal je Seite aus: der Knopf mit `data-wp-haupt` bleibt grau und gesperrt, bis alle Kacheln erledigt sind, darunter „Noch offen: 2, 3“ (DE/EN/UK). Tippflächen mindestens 56 px, am Handy ohne seitliches Scrollen. Der Server prüft unabhängig davon selbst.
