# pdf_text.php – Text aus einem PDF lesen

## Wozu
Lieferanten schicken CoA und Spezifikation als PDF. Statt alles abzutippen, lesen wir heraus, was maschinell lesbar ist. Ohne externe Programme – nur PHP und zlib, damit es auf dem Server genauso läuft wie lokal.

## Die Grenze, die man kennen muss
Das funktioniert **nur bei PDFs, in denen der Text auch wirklich Text ist**. Ist das PDF ein **Scan** (also ein Bild), steht dort kein Text – dann kommt nichts zurück. Dafür bräuchte es Texterkennung (OCR), die hier nicht eingebaut ist. Die Funktion liefert in dem Fall lieber **nichts** als geratene Werte.

## Funktionen
- `pdf_text_extrahieren($pfad): string` – der ganze Text; leerer String heißt „nicht lesbar".
- `pdf_text_aus_stream($s)` – wertet die Textoperatoren **mit Position** aus. Das ist der Kern: In einem PDF ist eine Tabellenzeile kein Text, sondern mehrere Textstücke an verschiedenen Stellen. Ohne die y-Koordinate zerfällt „Blei · max. 0,5 · 0,05 · ICP-MS" in vier Zeilen und die Werte lassen sich dem Parameter nicht mehr zuordnen. Stücke gleicher Höhe werden zu einer Zeile zusammengesetzt, Spalten mit drei Leerzeichen getrennt.
- `pdf_string_lesen()`, `pdf_zeichen()` – Escapes, Hex-Strings, WinAnsi/UTF-16.

## Verwandt
`core/coa_lesen.md` – was aus dem Text herausgelesen wird.
