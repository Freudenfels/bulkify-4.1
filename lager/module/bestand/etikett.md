# bestand/etikett.php – Karton-Etikett ausgeben (`?p=etikett`)

Liefert das Karton-Etikett als PDF (inline). Erzeugung zentral in
[../../core/etikett_pdf.md](../../core/etikett_pdf.md).

- `&id=` einzeln, `&ids=1,2,3` Stapel, `&pakete=` Kartonzahl erzwingen.
- `&format=klein` (100×70 quer) oder `gross` (100×150 hoch); ohne Parameter gilt der gespeicherte
  Standard (lg_meta `etikett_format`). `&merken=1` speichert die gewählte Größe als Standard.

Für lautlosen Direktdruck → `?p=druck_job` (Warteschlange) + kombinierte Brücke (SumatraPDF).
