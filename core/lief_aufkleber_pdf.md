# lief_aufkleber_pdf.php – PDF der Gebindeaufkleber

`la_pdf($ids, $format)` mit MiniPDF: `100x150` (Etikettendrucker, je Seite einer), `a4_1` (einer groß je A4), `a4_2`
(zwei je A4). `la_aufkleber_zeichnen()` zeichnet einen Aufkleber (Grundmaß 100 x 150 mm, skaliert): Produktname, QR mit Code,
Batch, Production, Expiry, Net/Gross, Quantity, PO No., Specifications, Formula (nur Bulk/Premix, mg und %), Shelf life/Storage,
Manufacturer (Text, kein Logo) mit Origin, Ship to. Englisch; keine Bag-Nummer, kein „x of n“.


**Lieferantennummer statt Name (Regel Nico 13.11.2026):** Der Aufkleber klebt bei uns im Lager auf dem Gebinde. Statt „Manufacturer“ mit Firmenname und Adresse steht dort nur „Supplier no.: L-xxxx“ (`lieferant_nr` in `la_daten`). „Origin“ (Herkunftsland) bleibt.
