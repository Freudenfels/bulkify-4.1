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

## Teil 2: Schwankende Rohstoffe markieren
- **Am Rohstoff:** In den Stammdaten gibt es den Haken „Preis schwankt“ und die Standard-Gültigkeit in Tagen (Standard 14, z. B. Whey). Daraus entsteht die Kachel „Preis schwankt - 14 Tage gültig“. Die Felder sind `item.preis_schwankt` und `item.preis_gueltig_tage`.
- **Vorbelegung:** „gültig bis“ ist mit heute plus Standard-Gültigkeit vorbelegt (`pg_vorbelegung()`). Das gilt bei:
  - Rohstoffseite, Preis hinzufügen
  - Pop-up „Preis & Unterlagen“
  - Portal, Rohstoff-Preise
  - Portal, Anfrage beantworten
- **Pflicht im Lieferantenportal:** Ohne Gültigkeit wird nichts gespeichert, mit Meldung auf DE, EN oder ZH. Das gilt bei „Anfrage beantworten“, wenn der angefragte Artikel schwankt, und beim Dialog Rohstoff-Preise.
- **Hinweis in der Anfrage:** Oben steht „Preis schwankt - bitte Gültigkeit angeben“.
- **Premix, Bulk und Fremdfertigung (Anfrage an einer Rezeptur):** Enthält die Rezeptur einen schwankenden Rohstoff, kommt nur ein Hinweis mit dessen Namen, keine Pflicht. Die Vorbelegung nimmt die kürzeste Gültigkeit. Die Logik steckt in `pg_anfrage_schwankung($anfrage)`.

## Teil 3: Abgelaufene Preise in der Kalkulation
- **Nie still verwenden:** Alle Preis-Abfragen laufen über `lp_gueltig_auswahl()` in core/schema.php, also `pg_auswahl()`.
  - Gibt es einen gültigen Preis, gilt nur dieser, auch bei der Wahl des günstigsten.
  - Gibt es nur abgelaufene, gilt der letzte. Er ist markiert („Preis abgelaufen seit … - neu anfragen“), und die Summe ist „unsicher“.
- **Betroffene Funktionen:**
  - `rohstoff_bester_lieferant()` und `rohstoff_ek_detail()`, beide mit den neuen Schlüsseln `abgelaufen`, `abgelaufen_ignoriert`, `gueltig_bis` und `lieferant_id`
  - `item_ek_aus_lieferantenpreis()`
  - `angebot_rohstoffkosten()`, mit dem neuen Zähler `unsicher`
  - `auftrag_beschaffungswege()`: Fremdfertigung, Premix und Einzelrohstoffe, jede Position mit `abgelaufen`, der Weg mit `unsicher`
  - `produkt_lieferant_preise()` und `_pkg()`
  - `core/produkt_kalkulation.php`: Zukauf-Kandidaten, Premix und Einzelrohstoffe. Ein abgelaufener Preis gibt `unsicher` und „vollständig = nein“.
- **Anzeige:**
  - Angebot, Tabelle „Rohstoffkosten je Lieferant“: Status „Preis abgelaufen seit …“, Knopf „neu anfragen“, darunter „Summe unsicher“. Bei gültigen Preisen steht „gültig bis“ und gegebenenfalls „abgelaufene Preise anderer Lieferanten nicht genutzt“.
  - Pop-up „Weg festlegen“ (Beschaffungsweg): Hinweis je Position und „(unsicher: Preis abgelaufen)“.
  - Produktseite, Kalkulation: „unsicher: Preis abgelaufen“.
  - Rohstoffseite: abgelaufene Preise grau, „günstigster“ nur unter gültigen Preisen.
- **„neu anfragen“:** öffnet das Anfrage-Pop-up mit demselben Lieferanten vorausgewählt (`data-lieferant`) und denselben Staffelmengen (`data-staffel`). Weitere Lieferanten lassen sich dazuwählen.
- **Preisverlauf:** Neue Tabelle `lieferant_preis_verlauf`. `lp_verlauf_sichern()` schreibt alte Preise hinein, bevor sie ersetzt oder gelöscht werden. Das gilt bei Angebot annehmen, Premix-Antwort und Löschen am Rohstoff. Auf der Rohstoffseite (Reiter Einkauf) steht der klappbare „Preisverlauf“ mit aktuellen und ersetzten Preisen (Datum, Lieferant, ab Menge, Preis, gültig bis).
- **Bald ablaufend:** Läuft ein Preis in höchstens 3 Tagen ab, erscheint „läuft bald ab“. `pg_bald_pruefen()` läuft einmal am Tag beim Aufruf des Dashboards. Steckt ein solcher Preis in einem offenen Angebot (Entwurf oder gesendet), geht eine Benachrichtigung „Preis läuft bald ab“ an das Team, einmal je Rohstoff und Datum.

## Teil 4: Angebote an Kunden
- `angebot_ek_gueltig_bis($angebot_id)` liefert das früheste „gültig bis“ ab heute aller EK-Preise im Angebot (Rezeptur-Zutaten wie bei den Rohstoffkosten, dazu Rohstoff-Positionen) mit Rohstoffname.
- `angebot_gueltig_bis_default($angebot_id)` liefert das Minimum aus dem Standard (`angebot_gueltig_tage`) und diesem Datum. Ohne begrenzte Preise gilt der bisherige Standard. Verwendet wird es beim Senden, im Kopfformular und beim Verlängern.
- **Intern:**
  - Am Feld „Gültig bis“ steht „Kürzester EK-Preis: … bis …“. Liegt die Angebotsgültigkeit später, steht dort „Angebot gilt länger als der Whey-Preis (bis …)“. Das ist nur ein Hinweis, keine Sperre.
  - Im gesendeten Angebot gibt es zusätzlich den Knopf „Gültigkeit auf … setzen“.
  - Bei einem abgelaufenen Angebot stehen das Badge „abgelaufen“ und der Knopf „Verlängern bis …“. Mit neuen Preisen: Positionen neu berechnen und erneut senden.
- **Kundenportal:**
  - `angebot_abgelaufen()` heißt: gesendet und „gültig bis“ liegt vor heute. Dann steht auf der Karte „abgelaufen - bitte neues Angebot anfordern“. Alle Annahme-Knöpfe fehlen, und es gibt den Knopf „Aktualisierung anfragen“.
  - Dieser Knopf erzeugt eine Benachrichtigung ans Team und einen Eintrag im Kundenverlauf.
  - Der Server sperrt die Annahme zusätzlich für `angebot_annehmen`, `bestaetigen` und `zelle_annehmen`.
- **Nach der Annahme (Auftrag)** gilt der Preis des Auftrags. Ablaufende EK-Preise ändern nur die Hinweise in der Beschaffung.
