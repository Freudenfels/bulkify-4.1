# gebinde_etikett.php – Gebinde-Aufkleber als PDF (Spec 5.6)

Gibt die Gebinde-/Karton-Aufkleber einer Charge als **PDF** aus – je Gebinde ein Aufkleber mit
**eigenem QR-Code** (führt auf `?p=gebinde&nr=GB-…`) und der **eigenen Nummer**, dazu Produktname,
Lieferanten-Nummer und Wareneingangsdatum.

- Aufruf: `?p=gebinde_etikett&charge=<id>` (Format `gross` = 100×150 hoch; `&format=klein` = 100×70).
- Erzeugung zentral in `lager/core/etikett_pdf.php` → `lg_gebinde_etikett_pdf()`.
- Liefert 404, wenn es für die Charge noch keine Gebinde-Aufkleber gibt (erst unter `?p=gebinde` erzeugen).
