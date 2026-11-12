# core/labor_auftrag.php – Proben ans Labor (Auftragsformular)

Füllt das Auftragsformular eines Labors (beschreibbares PDF) aus den bulkify-Daten. Seite: `module/lager/labor_senden.php` (`?p=labor_senden`).

## Wer steht wo auf dem Formular
- **Auftraggeber:** der Kunde, also der Produkt-Eigentümer. Er steht auf dem Untersuchungsbericht. Die Daten kommen aus den Kundenstammdaten: Firma, Ansprechpartner, Straße + Hausnummer, PLZ/Ort, Land (Code → Name), USt-IdNr., Telefon, E-Mail.
- **Rechnungsempfänger und Einsender/Probenehmer:** bulkify. Die Daten kommen aus den Firmenstammdaten (`beleg_firma()`, meta `firma_telefon`), nicht hartkodiert. Ansprechpartner ist, wer das Formular erstellt.
- **Berichtsempfänger:** bulkify (Standard) oder direkt der Kunde. Das stellt man am Labor ein (`labor_bericht_an`).
- **Datum:** heute, auf beiden Seiten.
- **Standort:** die Auswahl beim Senden.
- **Ansprechpartner beim Labor:** das Feld Ansprechpartner des Labor-Lieferanten.
- **Angebotsnummer:** `labor_angebotsnr`.
- **„Erste Proben?“:** Standard nein, am Labor umstellbar (`labor_erste_proben`).
- **Seite 2 „Firma“:** der Auftraggeber.
- **Bemerkung:** Freitext beim Senden.

## Je Probe (Zeile)
- **Proben-Nr.:** 1, 2, …
- **Material/Produkt:** der kundenspezifische Produktname, sonst Kundenname bzw. Produktname.
- **Rückverfolgbarkeit:** Charge + MHD der Fertigware des Auftrags (bis zu zwei Chargen), dazu die Auftragsnummer.
- **Untersuchungsumfang:** drei Zeilen, vorbelegt aus `labor_umfang` am Labor.
- **Beurteilung, Express:** je ein Haken.
- Alles ist vor dem Erzeugen je Probe änderbar.

## Labore
Lieferanten mit Kategorie `labor` und `labor_formular` (Schlüssel aus `labor_formulare()`). Weitere Spalten an `lieferanten`: `labor_standorte` (kommagetrennt), `labor_angebotsnr`, `labor_erste_proben`, `labor_bericht_an`, `labor_umfang`.

`labor_formulare()` enthält je Vorlage die Datei und die Feldzuordnung (AcroForm-Feldnamen). Umgesetzt ist `gba_food`: Vorlage `core/labor_vorlagen/gba_food.pdf`, das Formular „Analyseauftrag für GBA Food“ mit 5 Proben je Formular. Ein weiteres Labor ist ein weiterer Eintrag mit Datei und Zuordnung.

## Funktionen
- `labor_lieferanten()` gibt die Labore mit Vorlage zurück, `labor_standorte($labor)` ihre Standorte.
- `labor_proben_kandidaten()` liefert Aufträge mit Labortest (`kunden.labortest_extern` oder `auftrag.laboranalyse_gebucht`), die noch keinen freigegebenen Bericht haben.
- `labor_probe_vorschlag($auftrag_id, $umfang)` belegt eine Probenzeile vor.
- `labor_block_kunde($kid)` liefert die Kundendaten mit `fehlt`. Fehlt die USt-IdNr. oder die Anschrift, warnt die Seite deutlich, weil das Labor die USt-IdNr. verlangt.
- `labor_block_bulkify()` liefert die bulkify-Firmendaten.
- `labor_formular_werte(...)` baut die Feldwerte für ein Formular.
- `labor_versand_anlegen($lieferant_id, $standort, $proben, $bemerkung, $wer)` erzeugt **je Kunde und je 5 Proben ein Formular** und erledigt dabei:
  - Datei in `data/uploads`,
  - eine Zeile in `labor_versand` (Proben als JSON),
  - je Auftrag ein internes Dokument (`typ='labor_auftrag'`, `kunde_sichtbar=0`, eigene Dateikopie),
  - `auftrag.labor_versendet_am` = heute, falls noch leer. Der Auftrag steht damit auf „Probe beim Labor“, im Portal „läuft“.
  - einen Eintrag im Aktivitätsverlauf.
- `labor_versand_liste()` liefert die zuletzt erstellten Formulare.

**Grundcharge (13.11.2026, Fable-3 T3.2):** Die Probenbezeichnung auf dem Laborauftrag nennt nur die Grundcharge.
