# core/versand_dhl.php – DHL Paket DE „Versenden" v2

`dhl_label_erstellen($v,$abs,$cfg)` legt eine DHL-Sendung an und gibt Label (PDF) + Sendungsnummer zurück.

- Endpunkt: `POST {base}/parcel/de/shipping/v2/orders?includeDocs=include`
  (`$base` = `api-eu.dhl.com`, Sandbox `api-sandbox.dhl.com`).
- Auth: Header `dhl-api-key` + HTTP-Basic (`dhl_api_user:dhl_api_secret`).
- Produkt je Zielland: **V01PAK** (DE), **V54EPAK** (EU, `dhl_eu_laender()`), **V53WPAK** (Welt) → weltweite Sendungen.
- Request: `profile=STANDARD_GRUPPENPROFIL`, `shipments[0]` mit `product`, `billingNumber`
  (= `dhl_abrechnungsnummer`), `refNo` (Versand-Nr.), `shipper`/`consignee` (Name, Straße, PLZ, Ort,
  `country` als 3-stelliger ISO via `dhl_iso3()`), `details.weight` (kg).
- Antwort: `items[0].shipmentNo` (Tracking) + `items[0].label.b64` (Base64-PDF) bzw. `.url`.
- Fehler: Meldung aus `items[0].message`/`validationMessages`/`detail`.

Zugang über Einstellungen → Zugänge. Braucht `versand_http()` aus `versand.php`.
