# module/auftrag/pdf.php – Route für das Auftragsbestätigungs-PDF

Route `?p=auftrag_pdf&id=<ID>`. Lädt den Auftrag, ruft `auftrag_pdf_ausliefern()` aus
`core/pdf_auftrag.php` (inline-PDF). 404 wenn kein Auftrag, 409 wenn kein Kunde hinterlegt.
Verlinkt über den „PDF / Drucken"-Knopf auf der Auftragsseite. Rollen: sales/finance/production/fulfillment.
