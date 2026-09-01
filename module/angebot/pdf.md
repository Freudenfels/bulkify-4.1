# angebot/pdf.php – Angebots-PDF für das Team

Route: `?p=angebot_pdf&id=<ID>` (Rollen: sales, finance, admin)

## Wozu
Liefert das Angebots-PDF direkt im internen Bereich – **ohne Umweg über den Kunden-Portallink**.
Erreichbar über den Knopf **⇩ PDF** in der Kopfzeile des Angebots-Editors und über das
Download-Icon in der Angebotsliste (letzte Spalte).

Der Inhalt kommt aus `core/pdf_angebot.php`, also aus derselben Funktion wie beim Kunden:
was hier zu sehen ist, ist genau das, was der Kunde bekommt.

## Sonderfall ohne Kunde
Hat das Angebot noch keinen Kunden in den Kopfdaten, gibt es keinen Empfänger und damit keinen
Beleg. Dann kommt Status 409 mit einem Klartext-Hinweis statt eines leeren PDFs; in der Liste
steht in der Icon-Spalte ein „–".

## Verwandt
- `core/pdf_angebot.md` – was ins PDF kommt.
- `module/angebot/detail.md` – der Editor mit dem PDF-Knopf.
