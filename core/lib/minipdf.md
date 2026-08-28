# MiniPDF (core/lib/minipdf.php)

## Wozu
Sehr schlanker, **abhängigkeitsfreier** PDF-Generator (kein Composer, keine PHP-Extension).
Nutzt die Standard-Kernfonts Helvetica / Helvetica-Bold. Reicht für ein- und mehrseitige
Dokumente mit Text, Linien, Flächen und JPEG-Bildern.

Übernommen 1:1 aus dem v3-Dashboard (`bulkify-dashboard/lib/minipdf.php`), damit 4.1
dieselben sauberen PDFs bauen kann.

## API (Klasse MiniPDF)
Koordinaten sind „von oben" (y=0 = Seitenoberkante), A4 (595×842 pt).
- `text($x,$y,$s,$size,$bold,$color)` / `textRight(...)` / `textCenter(...)`
- `line($x1,$y1,$x2,$y2,$lw,$color)`
- `rect($x,$y,$w,$h,$color)` (gefüllt) / `rectStroke($x,$y,$w,$h,$lw,$color)` (nur Rand)
- `fit($s,$maxw,$size,$bold)` – kürzt mit „…" / `wrap(...)` – Umbruch in Zeilen-Array
- `strwidth($s,$size,$bold)` – Textbreite in pt
- `registerJpeg($data,$w,$h)` + `drawImage($id,$x,$topY,$w,$h)` – Bilder (nur JPEG)
- `addPage()` – neue Seite / `output()` – liefert die PDF-Bytes

Farben als `[r,g,b]` (0–255). Sonderzeichen werden nach Windows-1252 übersetzt
(„–", „…", typografische Anführungszeichen werden vereinfacht).

## Genutzt von
- `core/pdf_beleg.php` (Angebots-/Beleg-PDF im bulkify-Layout).

Bei Bedarf können weitere PDF-Builder (z. B. Auftragsbestätigung, Produktionsauftrag)
denselben Generator verwenden.
