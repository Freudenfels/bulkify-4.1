# bestand/etikett.php – Karton-Etikett (Wareneingang) als PDF

Erzeugt die **Aufkleber für die Kartons** einer Wareneingangs-Charge als **PDF, 100 mm breit × 70 mm hoch**. Route `?p=etikett`.

**Inhalt je Etikett:** „bulkify · Wareneingang", **Name** (Rohstoff/Rezeptur, bis 2 Zeilen), Artikelnummer, **Lieferant**, **Charge (Lieferant)**, **MHD** und **Menge** (nebeneinander), rechts der **QR-Code** und darunter prominent **„Karton X / N"**. Dünner Rahmen als Schnitt-/Klebehilfe.

**Pakete:** Eine Charge kommt in N Kartons. N wird beim **Wareneingang** erfasst (`eingang.php` → `lg_pakete_set`) und hier gelesen (`lg_pakete`); es wird **eine Seite je Karton** gedruckt und durchnummeriert („Karton 1 / N" … „N / N").

- **Einzeln:** `?p=etikett&id=<charge_id>` → so viele Seiten wie Kartons.
- **Stapel:** `?p=etikett&ids=1,2,3` → je Charge deren Kartonzahl.
- **Override:** `&pakete=<n>` erzwingt die Kartonzahl (z. B. Nachdruck).

**QR-Inhalt:** die Charge-Detail-URL im Lager (`<host>/lager/?p=charge&id=…`) – Scan öffnet alles zur Charge. Erzeugt über `qr_matrix()` ([../../core/qr.php](../../core/qr.php)), gezeichnet als Rechtecke mit **MiniPDF** (`core/lib/minipdf.php`, Seitengröße via `$pdf->w/$pdf->h`). `erp_charge_voll()` liefert die Daten inkl. Lieferant (Naht).

**Aufruf:** Knopf **„Etikett drucken"** auf der Charge-Detailseite (direkt nach dem Wareneingang) und ein **„Etikett"**-Link je Bestandszeile.

**Geprüft:** PDF via pdf.js gerendert + QR mit jsQR zurückgelesen → korrekt lesbar, nicht gespiegelt; Layout mit allen Pflichtfeldern passt aufs 100×70-Format.
