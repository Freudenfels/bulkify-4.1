# module/produktion/pdf.php – Route für den Produktions-Laufzettel

Route `?p=produktionsauftrag_pdf&id=<ID>` → `produktionsauftrag_pdf_ausliefern()` aus
`core/pdf_produktionsauftrag.php` (inline-PDF). 404 wenn kein PA. Verlinkt über „Laufzettel drucken"
im PA-Kopf. Rollen: production/labor/fulfillment.
