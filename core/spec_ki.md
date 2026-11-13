# spec_ki.php – Lieferantenunterlagen mit der KI auslesen

## Wozu
Liest eine **Spezifikation** oder ein **Analysenzertifikat (CoA)** eines Lieferanten per Claude-API aus und **schlägt** die Werte vor. Alternative zu `core/coa_lesen.php`: Jenes sucht im PDF-Text nach Stichworten und scheitert an Scans und ungewöhnlichen Layouts. Die API bekommt das PDF als Dokument – sie liest auch eingescannte Unterlagen und versteht Tabellen. Braucht `core/ki.php` (Einstellungen → KI).

> **Grundsatz:** Diese Funktionen **schlagen nur vor**. Gespeichert wird erst, wenn ein Mensch geprüft und freigegeben hat – ein falscher Wert auf einem Dokument, das an den Kunden geht, wäre schlimmer als ein leeres Feld.

> **Sprache:** Zielsprache aller Textfelder ist **Deutsch** – fremdsprachige Unterlagen (auch Chinesisch) werden übersetzt, in keinem Feld bleibt fremde Schrift stehen. Unverändert bleiben nur der lateinische/botanische Name, Codes (CAS/EC/Spec-Nr.) sowie Zahlen/Einheiten/Grenzzeichen. `name_en` ist immer Englisch. Wirkstoff- und Kennwert-Namen werden ebenfalls eingedeutscht; Wirkstoffe treffen dabei exakt die offiziellen NRV-Nährstoffnamen, damit die NRV-Berechnung greift.

## Ablauf
1. **`spec_ki_lesen($pfad)`** schickt das PDF mit `spec_ki_anweisung()` an die KI (`ki_datei_frage()`, JSON-Modus, mit „Denken", bis 8000 Tokens). Die Anweisung ist bewusst streng: nur übernehmen, was dasteht, nichts ableiten, nichts raten; fehlende Felder werden weggelassen. Rückgabe: `typ` (spec/coa/beides/unklar), `sicherheit`, `stamm`, **`wirkstoffe`**, **`kennwerte`**, **`cas_vorschlag`**, `charge`, `werte`, `hinweise`, plus `usage`/`modell`.
   - **`wirkstoffe`** = die standardisierten Wirk-/Leitsubstanzen mit Gehalt (der **Assay/Standardisierung**, z. B. „Content of Iron NLT 3.0%" → `{name:"Iron", gehalt_prozent:3.0}`). Beim Neuanlegen aus einer Spec füllen sie die Wirkstoff-Zeilen (`item_wirkstoff`); unbekannte Namen legt `naehrstoff_id_by_name()` an, der Prüfer kann per Datalist auf den deutschen Namen wechseln.
   - **`kennwerte`** = charakteristische Kennwerte als Parameter+Wert (Assay-Spanne, Extraktverhältnis, pH, Dichte, Partikelgröße …) – **ohne** die reinen Sicherheits-Grenzwerte (Schwermetalle/Mikro/Mykotoxine, die bleiben im PDF). Füllen beim Neuanlegen die Kennwert-Zeilen (`item_kennwert`).
   - **`lieferant_vorschlag`** = der Aussteller/Hersteller des Dokuments (Firmenname im Briefkopf/Fußzeile, unverändert, ohne Adresse). Dient dem Abgleich mit `lieferanten.firma` (`dokimport_lieferant_finden()`, streng) – im Massen-Import wird der passende Lieferant vorbelegt. Kein klarer Aussteller → null.
   - **`cas_vorschlag`** = einzige erlaubte Ableitung aus Fachwissen: nur wenn im Dokument keine CAS steht (oder „TBA") **und** der Stoff ein eindeutiger Reinstoff mit bekannter CAS ist (Vitamine, definierte Salze). Pflanzenextrakte/Mischungen → leer. Wird beim Neuanlegen ins CAS-Feld gesetzt und mit einem gelben Hinweis „aus Fachwissen, bitte prüfen" markiert.
2. **`spec_ki_nach_upload($dokument_id)`** liest direkt nach einem Upload aus (nur Typ coa/spec/analyse) und merkt den Vorschlag.
3. **`spec_ki_merken()` / `spec_ki_vorschlag()`** speichern den Roh-Vorschlag als JSON an `dokument.ki_daten` (+ `ki_stand`) bzw. holen ihn zurück – so kann das Team ihn später prüfen, auch wenn der Lieferant die Datei hochgeladen hat. An den Stammdaten wird dabei nichts geändert.
4. **`spec_ki_uebernehmen($item_id, $stamm, $felder, $ueberschreiben)`** übernimmt die vom Menschen freigegebenen Felder in `item`. Leere Felder werden gefüllt; vorhandene nur mit `$ueberschreiben=true`. Gibt die Zahl der geänderten Felder zurück und protokolliert.
5. **`spec_ki_werte_speichern($charge_id, $werte)`** schreibt die Analysewerte eines CoA an eine Charge (`charge_analyse`, ersetzt die bisherigen Zeilen).
6. **`spec_ki_coa_charge($item_id, $ergebnis, $lieferant_id=null)`** legt bei einer CoA **sofort eine (Vorab-)Charge** an – auch vor dem Wareneingang, damit die Unterlage nicht verloren geht (Menge 0, `wareneingang` NULL, Status quarantaene). Chargen-Nr./MHD kommen aus der CoA, die gemessenen Werte gehen in `charge_analyse`. Idempotent: gleiche Chargen-Nr. wird wiederverwendet. Nur bei CoA-Charakter (typ coa/beides oder vorhandene Chargen-Nr.); eine reine Spezifikation legt **keine** Charge an. Bei der Warenannahme gleicht `wareneingang_buchen()` die Charge über die Nummer ab und bucht sie mit Menge/Datum ein (keine Dublette).
7. **`spec_ki_grenzwerte($item_id, $ergebnis, $ueberschreiben=false)`** speichert die Reinheits-/Sicherheits-**Grenzwerte dauerhaft am Rohstoff** (`item_grenzwert`, aus den `spezifikation`-Sollwerten der Analysewerte: Schwermetalle, Keimbelastung, Mykotoxine …). So kann jede neue Charge dagegen geprüft werden. Ergänzt fehlende; `$ueberschreiben=true` ersetzt alle.

## Felder
`spec_ki_felder()` bildet die Stammdatenspalten von `item` auf Klartext-Beschreibungen für die KI ab (Name, botanische Quelle, CAS/EC, Herkunft, Haltbarkeit, Lagerung, Zusätze, Allergene, Zertifikate, Spec-Nr./Version/gültig-ab, vegan/GVO/bestrahlt/TSE-BSE, Dichte) mit Typ (text/datum/janein/zahl). `spec_ki_wert()` bringt einen Rohwert in die Form, die die Spalte erwartet.

## Charakteristische Kennwerte sauber halten
Der ENTSCHEIDENDE Wert eines Extrakts ist der Gehalt/Assay der Leitsubstanz (z. B. Withanolide bei
Ashwagandha) – der steht bei den **Wirkstoffen**. In die **Kennwerte** gehören nur identifizierende/
standardisierende Größen (Assay-Spanne, DEV/Extraktverhältnis, pH, Mesh, LOD, Löslichkeit, Aussehen).
Schwermetalle, Mikrobiologie, Mykotoxine, Pestizide, Lösungsmittel und Nährstoff-/Mineralgehalte
(Magnesium, Calcium, Eisen …) sind **keine** Kennwerte.

- `spec_kennwert_relevant($parameter)` – zentraler Filter (Blocklist). `false` = kein Kennwert.
- Wirkt im **Writer** (`spec_ki_kennwerte` speichert solche gar nicht erst) und beim **Anzeigen** über
  `item_kennwerte_relevant($item_id)` – genutzt in `core/pdf_spec.php` (Kunden-Spec), `module/lager/rohstoff_detail.php`
  und `module/portal/kunde.php`. So wird der **Altbestand live gefiltert, ohne etwas zu löschen** (kein
  Neu-Upload nötig); beim Speichern eines Rohstoffs fallen ausgefilterte Alt-Zeilen automatisch weg.
- Die KI-Anweisung (`spec_ki_anweisung`) weist zusätzlich an: Kennwerte knapp halten, Assay → Wirkstoffe,
  Mineralien/Sicherheitswerte raus.

## Freigabe als Version (Snapshot) – Erst-Upload automatisch, Änderung mit Diff
Der Kunde sieht **nie die Live-Tabellen**, sondern eine **eingefrorene, freigegebene Version** (`item.spec_freigabe_daten`, JSON mit wirkstoffe/kennwerte/grenzwerte). Politik nach jedem Schreiben der Spec-Daten (Import, Upload, manuelle Speicherung):
- **`spec_nach_datenaenderung($item_id, $wer)`** – zentraler Hook. Kein Snapshot vorhanden → **automatisch freigeben** (Erst-Upload braucht keine manuelle Bestätigung; bereits freigegebene Alt-Rohstoffe bekommen den aktuellen Stand als Basis). Snapshot vorhanden und Live weicht ab → `item.spec_aenderung_offen=1`, **alte Version bleibt beim Kunden sichtbar**, Team muss erneut freigeben. Identisch → nichts.
- **`spec_snapshot_setzen($item_id, $wer, $log=true)`** – friert den Live-Stand als neue freigegebene Version ein (`spec_freigegeben=1`, `spec_aenderung_offen=0`, Snapshot=jetzt). Wird von der Team-Freigabe (`rohstoff_detail.php` Aktion `spec_freigabe`, Sammel-Freigabe in `freigaben.php`) genutzt.
- **`spec_payload_live($item_id)`** / **`spec_anzeige_payload($item_id)`** – Live-Daten bzw. freigegebener Snapshot (leeres Payload, wenn nichts freigegeben). **`spec_anzeige_kennwerte()`** = Snapshot-Kennwerte mit `spec_kennwert_relevant`-Filter. **`spec_hat_freigabe()`** = freigegebene Version mit Inhalt vorhanden.
- **`spec_diff($item_id)`** – Diff freigegebener Snapshot → Live je Kategorie (`neu`/`weg`/`geaendert` + Flag `hat`); zeigt das Team im Rohstoff-Detail, was sich geändert hat.
- Hook-Aufrufe: `spec_ki_anwenden()` (Ende), manueller Spec-Speichern-Handler in `rohstoff_detail.php`. **CoA**: `spec_ki_coa_charge()` gibt ein **neu** erfasstes CoA mit echten Analysewerten direkt frei (`charge.coa_freigegeben=1`); ein Re-Upload derselben Charge lässt die Freigabe unangetastet.
- Kundenseite (`module/portal/kunde.php`) und `build_spec_pdf($id, true)` lesen ausschließlich den Snapshot. Team-Vorschau (`?p=spec_bulkify`) und interne Sichten lesen weiter live.
- Nachziehung beim ersten Request nach dem Umbau: `core/schema.php` schreibt für bereits freigegebene Rohstoffe ohne Snapshot den aktuellen Stand als Basis (best-effort, inline).

## Verwandt
- `core/ki.md` – die API-Anbindung und die KI-Einstellungen.
- `core/coa_lesen.md` – die textbasierte Variante ohne KI (Fallback, kostenlos, aber nur bei echtem PDF-Text).
- `core/pdf_spec.md` – daraus entstehen die eigenen Spezifikations- und CoA-PDFs.

## Rückfall: Wirkstoff aus der Analysetabelle (12.11.2026)
Anlass: Bei der ActiHyal-CoA (Natriumhyaluronat) hat die KI „Assay [Sodium Hyaluronate] NLT 95,0 %“ nur als Analysewert („Gehalt (Natriumhyaluronat)“) geliefert, die Liste `wirkstoffe` blieb leer. Am Rohstoff kam deshalb kein Wirkstoffgehalt an.

`spec_ki_wirkstoffe_aus_werte($werte)` leitet in diesem Fall die Wirkstoffe aus Zeilen wie `Gehalt (X)`, `Assay [X]`, `Content of X` oder `Reinheit (X)` ab:
- Name = X, Gehalt = unterer Spezifikationswert in % (NLT/≥/min bzw. Anfang einer Spanne).
- Ohne Spezifikation zählt das Ergebnis.
- Reine Höchstwerte (NMT/≤) sind keine Gehalte und werden übersprungen.

Der Rückfall greift in `spec_ki_lesen()` (neue Auslesungen) und in `spec_ki_wirkstoffe()` (auch für schon gespeicherte KI-Ergebnisse, z. B. „Angehakte Felder übernehmen“).

**13.11.2026 (Paket Teil 5):** `spec_ki_anwenden()` schreibt die bulkify-Spec nicht mehr still.
- CoA: Die Werte gehen an die (Vorab-)Charge; liegen sie außerhalb der Spec, greift `spec_coa_charge_pruefen()` (Quarantäne + Aufgabe).
- Spezifikation: Nur wenn der Rohstoff noch keine Spec-Daten hat, entsteht daraus die bulkify-Spec. Sonst entscheidet das Team im Pop-up „Vergleich mit der bulkify-Spec“ (`core/spec_vergleich.php`).
- Vorher wurden Grenzwerte und Kennwerte additiv ergänzt und Wirkstoffgehalte bei jedem Upload überschrieben.
- Gilt für Rohstoff-Upload, Lieferantenportal, Lieferanten-Unterlagen und Massen-Import.
