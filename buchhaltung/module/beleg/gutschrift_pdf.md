# gutschrift_pdf.php – Gutschrift/Storno als PDF

Route `?p=gutschrift_pdf&id=<Beleg-ID>` (Rolle finance). Liefert das PDF einer Gutschrift (Beleg typ=gutschrift)
inline aus, über `gutschrift_pdf_bauen()` in core/pdf_gutschrift.php (gleiche Vorlage wie Angebot/Rechnung, `build_beleg_pdf`).
