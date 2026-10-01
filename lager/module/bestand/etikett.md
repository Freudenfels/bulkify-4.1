# bestand/etikett.php – Charge-Etikett (QR) als PDF

Erzeugt das **Aufkleber-Etikett** einer Charge als **PDF, 100 mm breit × 70 mm hoch** (exakt die Aufkleber-Größe). Route `?p=etikett`.

- **Einzeln:** `?p=etikett&id=<charge_id>`
- **Stapel:** `?p=etikett&ids=1,2,3` → eine Seite je Charge (zum Blockdruck).

**Inhalt je Etikett:** „bulkify · Lager", Produkt-/Rohstoffname (bis 3 Zeilen), Artikelnummer, **Charge**, **Menge** (verfügbar), **MHD** und rechts der **QR-Code** mit der Charge-Nr darunter. Dünner Rahmen als Schnitt-/Klebehilfe.

**QR-Inhalt:** die Charge-Detail-URL im Lager (`<host>/lager/?p=charge&id=…`) – Scan mit dem Handy öffnet direkt alles zur Charge. Erzeugt über `qr_matrix()` ([../../core/qr.php](../../core/qr.php)), gezeichnet als Rechtecke mit **MiniPDF** (`core/lib/minipdf.php`, Seitengröße via `$pdf->w/$pdf->h`).

**Aufruf:** Knopf **„Etikett drucken"** auf der Charge-Detailseite (direkt nach dem Wareneingang sichtbar) und ein **„Etikett"**-Link je Zeile im Bestand. `erp_charge_voll()` liefert die Daten (Naht).

**Geprüft:** PDF mit pdf.js gerendert + QR mit jsQR zurückgelesen → korrekt lesbar, nicht gespiegelt.
