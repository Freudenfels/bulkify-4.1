# vertrag_pdf.php – Jahresabnahmevertrag (PDF)

Liefert den Jahresabnahmevertrag zu einem Angebot als PDF: `?p=vertrag_pdf&id=<angebot_id>` (Rollen sales/finance/admin), im Kundenportal über `?v=vertrag_pdf`. Nur für Angebote mit `jahresvertrag=1`. Erzeugt via `build_jahresvertrag_pdf()` (siehe `core/pdf_vertrag.md`): bulkify-Design, gefüllt mit Kundendaten, Produkt, Kapselgröße, Stück je Packung, Gesamtmenge, Festpreis, Gesamtwert, Laufzeit, den **verbindlich festgeschriebenen Inhaltsstoffen** und Unterschriftsfeldern.
