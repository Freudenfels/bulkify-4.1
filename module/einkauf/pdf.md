# einkauf/pdf.php – Bestell-PDF

Route: `?p=bestellung_pdf&id=<ID>` (Rolle: einkauf, admin)

Liefert den Bestellbeleg als PDF – erreichbar über **⇩ PDF** in der Kopfzeile der Bestellung und über das Download-Icon in der Bestellliste. Ohne Lieferant an der Bestellung gibt es keinen Empfänger: dann Status 409 mit Klartext-Hinweis statt eines leeren Belegs.

Inhalt und Sprache: siehe `core/pdf_bestellung.md`.
