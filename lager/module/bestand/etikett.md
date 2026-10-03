# bestand/etikett.php – Karton-Etikett ausgeben (`?p=etikett`)

Liefert das Karton-Etikett als PDF (inline). Erzeugung zentral in
[../../core/etikett_pdf.md](../../core/etikett_pdf.md).

- `&id=` einzeln, `&ids=1,2,3` Stapel, `&pakete=` Kartonzahl erzwingen.
- Format ist **fest 100×150 (hoch)** – 100×70 wurde entfernt.
- Menge je Karton: Wird beim Einbuchen „Menge auf Kartons aufteilen" angehakt (lg_charge_info.aufteilen),
  zeigt jedes Karton-Etikett `Menge/Karton` = Gesamt ÷ Kartons (letzter Karton bekommt den Rest), sonst die Gesamtmenge.

Für lautlosen Direktdruck → `?p=druck_job` (Warteschlange) + kombinierte Brücke (SumatraPDF).
