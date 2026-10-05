# Anleitung: DHL-Versand & Versandlabels im Lager aufbauen

**Ziel:** Das Lager bekommt dieselbe DHL-Schnittstelle wie das Fulfillment – Versandlabel (groß
**und** klein) per Knopfdruck erzeugen, Sendungsnummer zurückbekommen, drucken. Diese Anleitung
beschreibt, wie man das 1:1 nachbaut.

**Referenz-Implementierung (abgucken/kopieren):** `fulfillment-web/src/dhl.php` – alle unten
genannten Funktionen existieren dort fertig. Nachbau im Lager am besten als `lager/core/dhl.php`.

---

## 1. Welche DHL-API?

**DHL Parcel DE – Shipping (Post & Parcel Germany) API v2.** Eine REST/JSON-API. Ein Aufruf
erzeugt die Sendung **und** liefert das Label (PDF, base64) in einem Rutsch zurück.

Basis-URLs (je Umgebung):
- Sandbox/Test: `https://api-sandbox.dhl.com/parcel/de/shipping/v2`
- Produktion:   `https://api-eu.dhl.com/parcel/de/shipping/v2`

## 2. Was man an Zugangsdaten braucht

Drei Dinge – **gehören in die Einstellungen/DB, nie in den Code** (im Fulfillment liegen sie je Shop in der DB):

1. **API-Key** (`dhl-api-key`): identifiziert die App. Kommt aus dem **DHL Developer Portal**
   (developer.dhl.com) – je Umgebung ein eigener Key (Sandbox-Key ≠ Prod-Key).
2. **Geschäftskunden-Login (GKP):** `user` + `passwort` des DHL-Geschäftskundenportals.
   (Sandbox-Fallback von DHL: `user-valid` / `SandboxPasswort2023!`.)
3. **Abrechnungsnummer (billingNumber), 14-stellig** – setzt sich zusammen aus:
   - **EKP** (10-stellige DHL-Kundennummer) +
   - **Verfahrens-Code** (2-stellig, je Produkt – s. u.) +
   - **Teilnahmenummer** (4-stellig, je Produkt).
   Beispiel Paket (V01PAK): `EKP(10) + "01" + TN(4)`.

→ Im Lager also einmalig hinterlegen: Umgebung (sandbox/prod), API-Key, GK-User, GK-Pass,
EKP, und je genutztem Produkt die Teilnahmenummer.

## 3. Produkte (groß = Paket, klein = Warenpost/Kleinpaket)

| Code | Produkt | Verfahren | Teilnahmenummer-Feld (Fulfillment) |
|------|---------|-----------|-------------------------------------|
| `V01PAK`  | DHL Paket (national, **groß**)            | 01 | `dhl_tn_v01pak` |
| `V62KP`   | DHL Kleinpaket / Warenpost (**klein**)    | 62 | `dhl_tn_v62wp` |
| `V53WPAK` | DHL Paket International                    | 53 | `dhl_tn_v53wpak` |
| `V66WPI`  | Warenpost International (klein)            | 66 | `dhl_tn_v66wpi` |

Die Wahl „groß/klein" ist also primär die **Produktwahl** (Paket vs. Kleinpaket/Warenpost).
Das **Papierformat** des Labels steuert man zusätzlich über `printFormat` (nächster Punkt).

## 4. Label-Formate (printFormat) – groß/klein auf dem Papier

Beim Erzeugen wird `printFormat` mitgegeben. Die im Fulfillment hinterlegten Standard-Formate:

| printFormat | Beschreibung | mm |
|-------------|--------------|-----|
| `910-300-400`      | **Standard groß** (A5-Label, mit Zusatzetiketten) | 103 × 199 |
| `910-300-400-oz`   | wie oben, **ohne** Zusatzetiketten | 103 × 199 |
| `910-300-700` / `-700-oz` | Thermo 105 × 205 | 105 × 205 |
| `910-300-710`      | Thermo 105 × 208 | 105 × 208 |
| `910-300-600`      | Thermo 103 × 199 | 103 × 199 |
| `100x70mm`         | **kleines Label** 100 × 70 | 100 × 70 |
| `A4`               | DIN A4 (Bürodrucker) – auch für **Zollpapier CN23** | 210 × 297 |

Faustregel: großes Paket-Label = `910-300-400` (oder `-700` für Thermo), kleines = `100x70mm`.
`-oz` = ohne die DHL-Zusatzetiketten.

## 5. Authentifizierung (Header je Request)

```
dhl-api-key: <API-Key>
Authorization: Basic base64("<GK-User>:<GK-Pass>")
Accept: application/json
Accept-Language: de-DE
Content-Type: application/json      (nur bei POST/DELETE mit Body)
```
(curl: auf Windows `CURLSSLOPT_NATIVE_CA` setzen, dann braucht man kein cacert.pem.)

## 6. Label erzeugen (der eine Aufruf)

**Request:** `POST /orders?includeDocs=include&printFormat=<format>&docFormat=PDF`

**Body:**
```json
{
  "profile": "STANDARD_GRUPPENPROFIL",
  "shipments": [ { …Shipment… } ]
}
```

**Shipment-Objekt** (`dhl_build_shipment`):
```json
{
  "product": "V01PAK",
  "billingNumber": "<14-stellig>",
  "refNo": "#1234 2x…",                       // max 35 Zeichen, erscheint auf dem Label (Kostenstelle)
  "shipper":  { "name1","addressStreet","addressHouse","postalCode","city","country":"DEU","email?" },
  "consignee":{ "name1","addressStreet","addressHouse","postalCode","city","country","email?" },
  "details":  { "weight": { "uom":"g", "value": 500 } },
  "services": { … optional … }
}
```
Wichtige Details:
- **Länder** als 3-Buchstaben-ISO (DE→DEU, AT→AUT …).
- **Packstation (DHL Locker):** statt der normalen Adresse ein `consignee` mit
  `name` (NICHT `name1`!), `lockerID` (Packstationsnummer, Zahl), `postNumber` (Post-Nummer),
  `postalCode`, `city`, `country`.
- **services:** bei Paketen `endorsement:"RETURN"` (Rücksendung bei Unzustellbarkeit; international Pflicht),
  international zusätzlich `premium:true`.
- **Zoll (Nicht-EU, z. B. CH):** ein `customs`-Block (CN23) mit HS-Code, Ursprungsland, Warenwert,
  Mengen – im Fulfillment baut das `dhl_customs_block()` aus den Artikel-Zolldaten.

**Antwort auswerten** (`dhl_create_label`): HTTP **200 oder 207** = ok. Aus `items[0]`:
- `shipmentNo` → **Sendungsnummer** (an den Auftrag/Charge speichern + ggf. an Shopify/Kunde zurück).
- `label.b64` → **Label als PDF (base64)** → dekodieren, speichern, drucken.
- `customsDoc.b64` → **Zollpapier** (nur Nicht-EU), separat auf A4 drucken.
- `validationMessages` / `status.detail` → Fehlermeldungen anzeigen.

## 7. Label stornieren

`DELETE /orders?profile=STANDARD_GRUPPENPROFIL&shipment=<Sendungsnummer>` → HTTP 200 = storniert.
(Nur möglich, solange die Sendung noch nicht in den DHL-Transport übergeben wurde.)

## 8. Zugangsdaten prüfen (ohne Sendung zu erzeugen)

`dhl_validate()` im Fulfillment macht einen Test-Aufruf mit einer Dummy-Adresse → zeigt, ob
Login + Abrechnungsnummer stimmen, ohne ein echtes Label (und Kosten) zu erzeugen. Sehr nützlich
als „Verbindung testen"-Knopf in den Einstellungen.

## 9. Drucken (groß/klein + Zoll)

Das Label kommt als **PDF (base64)** zurück. Ablauf:
1. base64 dekodieren → PDF-Datei speichern (z. B. unter `data/labels/`).
2. An den passenden Drucker schicken:
   - **großes Label** (`910-300-*`) → Etiketten-/Thermodrucker,
   - **kleines Label** (`100x70mm`) → kleiner Etikettendrucker,
   - **Zollpapier** (`A4`) → Bürodrucker.
3. Im bulkify-Lager gibt es dafür schon die **Lager-PC-Brücke** (SumatraPDF-Druck, LED/Blinker).
   Das Label kann wie die Charge-Etiketten über einen Druckjob laufen (siehe Lager-Brücke).

## 10. Konkreter Bauplan fürs Lager (bulkify-4.1)

1. **`lager/core/dhl.php`** anlegen – die Funktionen aus `fulfillment-web/src/dhl.php` übernehmen:
   `dhl_base/dhl_request/dhl_build_shipment/dhl_create_label/dhl_cancel_label/dhl_validate`.
2. **Einstellungen** (Umgebung, API-Key, GK-User/Pass, EKP, TN je Produkt) in den Lager-Settings
   speichern (z. B. `app_meta` bzw. eine kleine Settings-Tabelle) – **nicht** in den Code.
3. **Versand-Maske** im Lager: Empfängeradresse (aus dem Auftrag/Kunden), Gewicht, Produktwahl
   (Paket/Kleinpaket), Format (groß/klein) → Button „DHL-Label erzeugen".
4. Antwort: **Sendungsnummer** am Auftrag/der Lieferung speichern, **PDF** speichern + über die
   Lager-Brücke drucken; bei Nicht-EU das **Zollpapier** mitdrucken.
5. Optional „Storno"-Knopf (solange nicht übergeben) + „Verbindung testen" (`dhl_validate`).

---

### Hinweise / Fallstricke (aus dem Fulfillment gelernt)
- Zugangsdaten gehören in die DB/Settings, **nie** ins Repo (im Fulfillment: `data/app.sqlite`).
- **Sandbox zuerst** testen (eigene Keys/URL), dann auf Produktion umstellen.
- `refNo` ist die einzige frei beschriftbare Stelle auf dem Label (max. 35 Zeichen) – dort
  Auftrags-/Inhaltskürzel reinschreiben, damit der Packer sieht, was ins Paket gehört.
- Packstation braucht `name` + `lockerID` + `postNumber` (häufige Fehlerquelle).
- Nicht-EU ohne Zollblock = Validierungsfehler.
