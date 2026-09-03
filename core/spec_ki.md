# spec_ki.php – Lieferantenunterlagen mit der KI auslesen

## Wozu
Liest eine **Spezifikation** oder ein **Analysenzertifikat (CoA)** eines Lieferanten per Claude-API aus und **schlägt** die Werte vor. Alternative zu `core/coa_lesen.php`: Jenes sucht im PDF-Text nach Stichworten und scheitert an Scans und ungewöhnlichen Layouts. Die API bekommt das PDF als Dokument – sie liest auch eingescannte Unterlagen und versteht Tabellen. Braucht `core/ki.php` (Einstellungen → KI).

> **Grundsatz:** Diese Funktionen **schlagen nur vor**. Gespeichert wird erst, wenn ein Mensch geprüft und freigegeben hat – ein falscher Wert auf einem Dokument, das an den Kunden geht, wäre schlimmer als ein leeres Feld.

> **Sprache:** Zielsprache aller Textfelder ist **Deutsch** – fremdsprachige Unterlagen (auch Chinesisch) werden übersetzt, in keinem Feld bleibt fremde Schrift stehen. Unverändert bleiben nur der lateinische/botanische Name, Codes (CAS/EC/Spec-Nr.) sowie Zahlen/Einheiten/Grenzzeichen. `name_en` ist immer Englisch. Wirkstoff- und Kennwert-Namen werden ebenfalls eingedeutscht; Wirkstoffe treffen dabei exakt die offiziellen NRV-Nährstoffnamen, damit die NRV-Berechnung greift.

## Ablauf
1. **`spec_ki_lesen($pfad)`** schickt das PDF mit `spec_ki_anweisung()` an die KI (`ki_datei_frage()`, JSON-Modus, mit „Denken", bis 8000 Tokens). Die Anweisung ist bewusst streng: nur übernehmen, was dasteht, nichts ableiten, nichts raten; fehlende Felder werden weggelassen. Rückgabe: `typ` (spec/coa/beides/unklar), `sicherheit`, `stamm`, **`wirkstoffe`**, **`kennwerte`**, **`cas_vorschlag`**, `charge`, `werte`, `hinweise`, plus `usage`/`modell`.
   - **`wirkstoffe`** = die standardisierten Wirk-/Leitsubstanzen mit Gehalt (der **Assay/Standardisierung**, z. B. „Content of Iron NLT 3.0%" → `{name:"Iron", gehalt_prozent:3.0}`). Beim Neuanlegen aus einer Spec füllen sie die Wirkstoff-Zeilen (`item_wirkstoff`); unbekannte Namen legt `naehrstoff_id_by_name()` an, der Prüfer kann per Datalist auf den deutschen Namen wechseln.
   - **`kennwerte`** = charakteristische Kennwerte als Parameter+Wert (Assay-Spanne, Extraktverhältnis, pH, Dichte, Partikelgröße …) – **ohne** die reinen Sicherheits-Grenzwerte (Schwermetalle/Mikro/Mykotoxine, die bleiben im PDF). Füllen beim Neuanlegen die Kennwert-Zeilen (`item_kennwert`).
   - **`cas_vorschlag`** = einzige erlaubte Ableitung aus Fachwissen: nur wenn im Dokument keine CAS steht (oder „TBA") **und** der Stoff ein eindeutiger Reinstoff mit bekannter CAS ist (Vitamine, definierte Salze). Pflanzenextrakte/Mischungen → leer. Wird beim Neuanlegen ins CAS-Feld gesetzt und mit einem gelben Hinweis „aus Fachwissen, bitte prüfen" markiert.
2. **`spec_ki_nach_upload($dokument_id)`** liest direkt nach einem Upload aus (nur Typ coa/spec/analyse) und merkt den Vorschlag.
3. **`spec_ki_merken()` / `spec_ki_vorschlag()`** speichern den Roh-Vorschlag als JSON an `dokument.ki_daten` (+ `ki_stand`) bzw. holen ihn zurück – so kann das Team ihn später prüfen, auch wenn der Lieferant die Datei hochgeladen hat. An den Stammdaten wird dabei nichts geändert.
4. **`spec_ki_uebernehmen($item_id, $stamm, $felder, $ueberschreiben)`** übernimmt die vom Menschen freigegebenen Felder in `item`. Leere Felder werden gefüllt; vorhandene nur mit `$ueberschreiben=true`. Gibt die Zahl der geänderten Felder zurück und protokolliert.
5. **`spec_ki_werte_speichern($charge_id, $werte)`** schreibt die Analysewerte eines CoA an eine Charge (`charge_analyse`, ersetzt die bisherigen Zeilen).
6. **`spec_ki_coa_charge($item_id, $ergebnis, $lieferant_id=null)`** legt bei einer CoA **sofort eine (Vorab-)Charge** an – auch vor dem Wareneingang, damit die Unterlage nicht verloren geht (Menge 0, `wareneingang` NULL, Status quarantaene). Chargen-Nr./MHD kommen aus der CoA, die gemessenen Werte gehen in `charge_analyse`. Idempotent: gleiche Chargen-Nr. wird wiederverwendet. Nur bei CoA-Charakter (typ coa/beides oder vorhandene Chargen-Nr.); eine reine Spezifikation legt **keine** Charge an. Bei der Warenannahme gleicht `wareneingang_buchen()` die Charge über die Nummer ab und bucht sie mit Menge/Datum ein (keine Dublette).
7. **`spec_ki_grenzwerte($item_id, $ergebnis, $ueberschreiben=false)`** speichert die Reinheits-/Sicherheits-**Grenzwerte dauerhaft am Rohstoff** (`item_grenzwert`, aus den `spezifikation`-Sollwerten der Analysewerte: Schwermetalle, Keimbelastung, Mykotoxine …). So kann jede neue Charge dagegen geprüft werden. Ergänzt fehlende; `$ueberschreiben=true` ersetzt alle.

## Felder
`spec_ki_felder()` bildet die Stammdatenspalten von `item` auf Klartext-Beschreibungen für die KI ab (Name, botanische Quelle, CAS/EC, Herkunft, Haltbarkeit, Lagerung, Zusätze, Allergene, Zertifikate, Spec-Nr./Version/gültig-ab, vegan/GVO/bestrahlt/TSE-BSE, Dichte) mit Typ (text/datum/janein/zahl). `spec_ki_wert()` bringt einen Rohwert in die Form, die die Spalte erwartet.

## Verwandt
- `core/ki.md` – die API-Anbindung und die KI-Einstellungen.
- `core/coa_lesen.md` – die textbasierte Variante ohne KI (Fallback, kostenlos, aber nur bei echtem PDF-Text).
- `core/pdf_spec.md` – daraus entstehen die eigenen Spezifikations- und CoA-PDFs.
