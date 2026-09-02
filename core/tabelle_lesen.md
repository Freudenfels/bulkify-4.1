# tabelle_lesen.php – CSV, TXT und Excel als Text lesen

## Wozu
Die Anthropic-API nimmt PDFs und Bilder als Datei entgegen, **Tabellen aber nicht**. Damit ein Lieferant seine Preisliste trotzdem als Excel oder CSV schicken kann, wird sie hier ausgelesen und als Text mitgeschickt. Für die KI ist das sogar die klarere Form als ein Bild einer Tabelle.

Gebaut mit Bordmitteln (`zip` + `simplexml`), passend zur Projektregel **ohne Composer** – eine `.xlsx` ist nur ein ZIP mit XML darin.

## Funktionen
- `tabelle_endungen()` – csv, txt, tsv, xlsx, xlsm.
- `ist_tabelle($pfad)` – Weiche in `ki_datei_frage()`.
- `tabelle_text($pfad, $max_zeilen = 2000)` – der Inhalt als Text, Spalten mit Tabulator. `null`, wenn nichts zu holen ist.
- `csv_text()` – erkennt den Zeichensatz (Excel schreibt CSV gern in Windows-1252, sonst werden Umlaute zu Fragezeichen), entfernt das BOM und rät das Trennzeichen (`;`, `,` oder Tabulator) aus den ersten Zeilen.
- `xlsx_text()` – liest `sharedStrings.xml` (Excel legt jeden Text nur einmal ab), die Blattnamen aus `workbook.xml` und alle `worksheets/sheetN.xml`. Beherrscht mehrteilige Texte (`<r><t>`), Wahrheitswerte, Lücken in den Spalten und mehrere Blätter; leere Zeilen fallen weg.
- `xlsx_spalte('AB12')` – Zellbezug zu Spaltenindex.

## Grenzen
- **Kein `.xls`** (Binärformat vor Excel 2007) und kein `.ods`. Der Hinweis in der Oberfläche sagt, dass vorher als `.xlsx` oder `.csv` gespeichert werden soll.
- Datumswerte kommen als Excel-Zahl (z. B. `45678`), weil die Formatierung nicht ausgewertet wird. Für Preislisten ist das ohne Belang.
- Formeln liefern den zuletzt gespeicherten Wert, nicht die Formel.
- Ab 2000 Zeilen wird abgeschnitten, mit einem sichtbaren Hinweis am Ende.
