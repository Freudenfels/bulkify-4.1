# spec_ki.php – Lieferantenunterlagen mit der KI auslesen

## Wozu
Liest eine **Spezifikation** oder ein **Analysenzertifikat (CoA)** eines Lieferanten per Claude-API aus und **schlägt** die Werte vor. Alternative zu `core/coa_lesen.php`: Jenes sucht im PDF-Text nach Stichworten und scheitert an Scans und ungewöhnlichen Layouts. Die API bekommt das PDF als Dokument – sie liest auch eingescannte Unterlagen und versteht Tabellen. Braucht `core/ki.php` (Einstellungen → KI).

> **Grundsatz:** Diese Funktionen **schlagen nur vor**. Gespeichert wird erst, wenn ein Mensch geprüft und freigegeben hat – ein falscher Wert auf einem Dokument, das an den Kunden geht, wäre schlimmer als ein leeres Feld.

## Ablauf
1. **`spec_ki_lesen($pfad)`** schickt das PDF mit `spec_ki_anweisung()` an die KI (`ki_datei_frage()`, JSON-Modus, mit „Denken", bis 8000 Tokens). Die Anweisung ist bewusst streng: nur übernehmen, was dasteht, nichts ableiten, nichts raten; fehlende Felder werden weggelassen. Rückgabe: `typ` (spec/coa/beides/unklar), `sicherheit`, `stamm`, `charge`, `werte`, `hinweise`, plus `usage`/`modell`.
2. **`spec_ki_nach_upload($dokument_id)`** liest direkt nach einem Upload aus (nur Typ coa/spec/analyse) und merkt den Vorschlag.
3. **`spec_ki_merken()` / `spec_ki_vorschlag()`** speichern den Roh-Vorschlag als JSON an `dokument.ki_daten` (+ `ki_stand`) bzw. holen ihn zurück – so kann das Team ihn später prüfen, auch wenn der Lieferant die Datei hochgeladen hat. An den Stammdaten wird dabei nichts geändert.
4. **`spec_ki_uebernehmen($item_id, $stamm, $felder, $ueberschreiben)`** übernimmt die vom Menschen freigegebenen Felder in `item`. Leere Felder werden gefüllt; vorhandene nur mit `$ueberschreiben=true`. Gibt die Zahl der geänderten Felder zurück und protokolliert.
5. **`spec_ki_werte_speichern($charge_id, $werte)`** schreibt die Analysewerte eines CoA an eine Charge (`charge_analyse`, ersetzt die bisherigen Zeilen).

## Felder
`spec_ki_felder()` bildet die Stammdatenspalten von `item` auf Klartext-Beschreibungen für die KI ab (Name, botanische Quelle, CAS/EC, Herkunft, Haltbarkeit, Lagerung, Zusätze, Allergene, Zertifikate, Spec-Nr./Version/gültig-ab, vegan/GVO/bestrahlt/TSE-BSE, Dichte) mit Typ (text/datum/janein/zahl). `spec_ki_wert()` bringt einen Rohwert in die Form, die die Spalte erwartet.

## Verwandt
- `core/ki.md` – die API-Anbindung und die KI-Einstellungen.
- `core/coa_lesen.md` – die textbasierte Variante ohne KI (Fallback, kostenlos, aber nur bei echtem PDF-Text).
- `core/pdf_spec.md` – daraus entstehen die eigenen Spezifikations- und CoA-PDFs.
