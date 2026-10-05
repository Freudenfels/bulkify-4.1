# core/versand_dhl.php – DHL Paket DE „Versenden" v2

Portiert aus `fulfillment-web/src/dhl.php` (siehe `ANLEITUNG-DHL-VERSAND.md`). Ein Aufruf erzeugt Sendung
+ Label (PDF, base64) + Sendungsnummer.

- Endpunkt: `POST {base}/parcel/de/shipping/v2/orders?includeDocs=include&printFormat=<fmt>&docFormat=PDF`
  (`{base}` = `api-sandbox.dhl.com` bzw. `api-eu.dhl.com`).
- Auth: Header `dhl-api-key` + HTTP-Basic (GK-User:Passwort); Sandbox-Fallback `user-valid`/`SandboxPasswort2023!`.
- **Produkt** (`dhl_produkt`): national/international × groß/klein → **V01PAK** (Paket DE), **V62KP**
  (Kleinpaket/Warenpost), **V53WPAK** (Paket International), **V66WPI** (Warenpost International).
  Größe kommt aus `lg_versand.dhl_groesse` (gross/klein).
- **billingNumber** (`dhl_billing`): EKP(10) + Teilnahmenummer(4) je Produkt; Sandbox = DHL-Testnummer
  (`3333333333`+Verfahren+`02`); Fallback: 14-stellige Alt-Nummer mit getauschtem Verfahrens-Code.
- **printFormat**: groß = `dhl_format_gross` (Standard `910-300-400`), klein = `dhl_format_klein` (`100x70mm`).
- Shipper = strukturierter Absender (Einstellungen → Formate); consignee = Empfänger-Snapshot; Land als
  3-stelliger ISO (`dhl_land3`); Gewicht in Gramm. `services`: `endorsement=RETURN` (V01PAK/V53WPAK),
  `premium` (international). refNo = Versand-Nr. (+ Firma), max 35 Zeichen.
- **Nicht-EU** (`!dhl_ist_eu`): braucht CN23-Zolldaten (HS-Code/Warenwert) – **im Lager noch nicht erfasst**,
  deshalb aktuell klare Meldung (Follow-up). EU/DE läuft voll.

Funktionen: `dhl_label_erstellen($v,$abs,$cfg)` (Einstieg, vom Dispatcher), `dhl_validate($cfg)`
(Verbindung testen, `?validate=true`, ohne echte Sendung), `dhl_cancel($cfg,$sendungsnr)`
(`DELETE /orders?...&shipment=`). Braucht `versand_http()` aus `versand.php`.
