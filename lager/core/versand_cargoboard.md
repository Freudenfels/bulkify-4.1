# core/versand_cargoboard.php – Cargoboard (Palette / Fracht)

`cargoboard_label_erstellen($v,$abs,$cfg)` legt eine Cargoboard-Order an und holt das Label (PDF).

- Order: `POST {base}/v1/orders` (`$base` = `api.cargoboard.com`, Sandbox `api-sandbox.cargoboard.com`).
- Label: `GET {base}/v1/orders/{id}/print-shipment-labels?format=A4` → PDF.
- Auth: Header `X-API-KEY` (= `cargoboard_api_key`).
- Request: `product=FIX`, `shipper`/`consignee` mit `contactPerson` + `address` (street/city/postCode/countryCode),
  `pickupOn` (nächster Werktag), `lines[0]` als Packstück (`unitQuantity`=Pakete, `unitPackageType=PA`,
  `unitLength/Width/Height` in cm – aus den Maßen am Versand, Standard Europalette 120×80×100,
  `unitWeight` = Gesamtgewicht/Pakete, `isStackable`), `customerOrderCode` (Versand-Nr.), `incoterm=STANDARD`.
- Antwort: `data.id` (Order) + `data.reference` (Tracking) + `data.price`.

Zugang über Einstellungen → Zugänge. Braucht `versand_http()` aus `versand.php`.
