# Preis-Gültigkeit (`core/preis_gueltig.php`)

Stand 11.11.2026, Auftrag von Nico. Anlass: Manche Rohstoffpreise halten nur kurz, z. B. Whey. Ein alter Preis darf nicht still weiter in Kalkulation und Angeboten landen.

## Daten
- **`gueltig_bis`** (DATE, leer = unbegrenzt) an:
  - `lieferant_preis` (EK-Staffeln am Artikel)
  - `lieferant_angebot` (Angebot auf eine Anfrage; gilt für alle Staffeln des Angebots)
  - `rezeptur_lief_angebot` (Fertigprodukt-/Fremdfertigungspreise)
  - `produkt_lieferant_preis` (Zukauf am Produkt)
  - `lieferant_preisliste` (Altformat)
  - `lieferant_katalog` (Preis-Aktualisierung bzw. KI-Liste, wartet auf Prüfung)
- **Weitergabe beim Übernehmen:**
  - Angebot annehmen: wird zu `lieferant_preis` bzw. `produkt_lieferant_preis`.
  - Premix-Antwort: wird zu `lieferant_preis` am Premix-Artikel.
  - Fremdfertigungs-Antwort: wird zu `rezeptur_lief_angebot`.
  - Katalogzeile übernehmen: wird zu `lieferant_preis`.
- **Am Rohstoff:** `item.preis_schwankt` und `item.preis_gueltig_tage` (Teil 2).

## Funktionen
- `pg_heute()`, `pg_plus_tage(n)`: Datum in Deutschland.
- `pg_lesen($roh)`: liest JJJJ-MM-TT oder TT.MM.JJJJ, sonst null.
- `pg_status($bis)`: `unbegrenzt`, `gueltig`, `bald` (höchstens 3 Tage) oder `abgelaufen`.
- `pg_text()` bzw. `pg_anzeige()`: „gültig bis 25.11.2026“, „unbegrenzt“, „abgelaufen seit …“, „(läuft bald ab)“, auch auf Englisch und Chinesisch.
- `pg_eingabe($name, $wert, $opt)`: Datumsfeld mit Schnellwahl 7, 14, 30 und 90 Tage. Optionen: `pflicht`, `sprache`, `label`, `hinweis`.
- `pg_item_tage()` und `pg_vorbelegung($item_id)`: heute plus Standard-Gültigkeit bei „Preis schwankt“.
- `pg_auswahl($rows)`: wählt die Preiszeilen für die Kalkulation aus.
  - Gibt es gültige Zeilen, zählen nur diese. Dabei ist `abgelaufen_ignoriert` gesetzt, wenn es daneben abgelaufene gab.
  - Gibt es nur abgelaufene, zählen die mit dem letzten Ablaufdatum, und `abgelaufen` ist gesetzt.
- `pg_fruehestes($daten)`: frühestes Datum ab heute, für die Angebotsgültigkeit.

## Eingabe (Teil 1)
- **Lieferantenportal:**
  - Anfrage beantworten: „Angebot abgeben“ bzw. „Angebot anpassen“
  - Rohstoff-Preise: Dialog „Preis aktualisieren“
  - Fertigprodukt-Preise: je Zeile ein Datumsfeld, dazu das Feld beim Hinzufügen
- **Intern:**
  - Rohstoffseite, Lieferantenpreis hinzufügen
  - Pop-up „Preis & Unterlagen“
  - Produktseite, Zukaufpreis
- **KI-Erfassung einer Lieferanten-Preisliste** (`core/lieferant_katalog.php`): Die KI liest „gültig bis“ mit aus, wenn es im Dokument steht.
- **Anzeige:**
  - Rohstoffseite (Spalte „Gültig“)
  - Lieferanten-Anfragen (Liste und Detail)
  - Portal (Zusammenfassung des Angebots, Rohstoff-Preise, Fertigprodukt-Preise)
