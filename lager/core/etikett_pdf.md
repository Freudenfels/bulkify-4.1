# core/etikett_pdf.php – Karton-Etikett als PDF (zentral)

`lg_etikett_pdf(array $ids, string $format='klein', int $override=0): ?string` baut die Etiketten
und gibt die PDF-Bytes zurück (oder null). Formate: `klein` 100×70 quer, `gross` 100×150 hoch
(`lg_karton_etikett` / `lg_karton_etikett_hoch`). Inhalt: Name, Lieferant (=Lieferantennummer),
Eingangsdatum, Charge, MHD, Menge, QR zur Charge, „Karton X / N" je Paket.

Genutzt von [../module/bestand/etikett.md](../module/bestand/etikett.md) (Anzeige/Download) und der
Druck-Brücke `public/lager/bruecke.php` (lautloser Druck). Braucht `erp_charge_voll`, `lg_pakete`,
`menge_txt`, `qr_matrix`, MiniPDF.
