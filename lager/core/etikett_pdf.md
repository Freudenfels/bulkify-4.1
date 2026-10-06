# core/etikett_pdf.php – Karton-Etikett als PDF (zentral)

`lg_etikett_pdf(array $ids, string $format='klein', int $override=0): ?string` baut die Etiketten
und gibt die PDF-Bytes zurück (oder null). Formate: `klein` 100×70 quer, `gross` 100×150 hoch
(`lg_karton_etikett` / `lg_karton_etikett_hoch`). Inhalt: Name, **Lieferant = NUR die Lieferantennummer**
(`lg_lieferant_txt()`, nie der Name – Regel Nico; fehlt die Nummer, bleibt das Feld „–"), Eingangsdatum, Charge,
MHD, Menge, QR zur Charge, „Karton X / N" je Paket. Bei **Aufteilen** wird die Menge je Karton verteilt;
**Stück/Tabletten/Kapseln ganzzahlig** (floor je Karton, letzter bekommt den Rest – keine Nachkommastellen),
Gewicht/Volumen auf 3 Nachkommastellen. Hinweis: `etikett.php` druckt aktuell nur `gross` (100×150).

Genutzt von [../module/bestand/etikett.md](../module/bestand/etikett.md) (Anzeige/Download) und der
Druck-Brücke `public/lager/bruecke.php` (lautloser Druck). Braucht `erp_charge_voll`, `lg_pakete`,
`menge_txt`, `qr_matrix`, MiniPDF.
