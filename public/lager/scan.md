# public/lager/scan.php – Lager-Scan-Endpunkt (Smartglass / Handscanner)

Ein kleiner, **read-only** HTTP-Endpunkt. Eine Brille (Rokid) oder ein Handscanner scannt den
QR-Code auf dem **Karton-Etikett** (Inhalt: `…/lager/?p=charge&id=<id>`), liest daraus die
**Charge-ID** und ruft diesen Endpunkt auf. Antwort ist JSON zur Charge.

## Warum eigener Endpunkt
Das normale Lager/Dashboard braucht einen Login (Session). Eine Brille kann sich nicht einloggen.
Darum gibt es – wie bei `bruecke.php` – einen headless Endpunkt mit eigenem Lager-Bootstrap und
**Token** statt Login. Er **liest nur**, verändert nie Bestand.

## Aufruf
```
GET /lager/scan.php?token=<TOKEN>&id=<charge_id>
GET /lager/scan.php?token=<TOKEN>&code=<komplette gescannte QR-URL>
```
Der Token kann auch als Header `X-Scan-Token` kommen. `code=` zieht die Charge-ID selbst aus der URL.

## Antwort (JSON)
```json
{ "ok": true, "found": true, "id": 2,
  "produkt": "…", "charge": "…", "menge": 1000, "einheit": "Stück",
  "mhd": "", "status": "frei", "kategorie": "fertig", "lieferant": "…",
  "ort": "Kiste A3, Fach …", "orders": [] }
```
Fehler: `{ "ok": false, "error": "…" }` mit HTTP 401 (Token), 400 (keine ID), 404 (Charge weg), 500.

## Token
Liegt im Dashboard unter `app_meta['lager_scan_token']`. Angezeigt/erzeugt wird er in den
**Dashboard-Einstellungen → Reiter „Lager-Scan (Brille)"**. Der Endpunkt liest ihn über die Naht
`erp_scan_token()` (in `lager/core/erp.php`).

## Datenquellen
- `erp_charge_voll($id)` (Naht `lager/core/erp.php`) → Produkt (`item_name`), `charge_nr`,
  `menge_verfuegbar`, `einheit`, `mhd`, `status`, `kategorie`, `lieferant`.
- `blinker_fuer_charge($id)` (`lager/core/kiste.php`) → Ort als Text (Kiste/Fach) oder Blinker-Code.

## Offen (Stufe 2)
`orders[]` ist reserviert für „offene Aufträge, die diesen Rohstoff brauchen" – kommt über eine
gezielte Naht-Query (Kette `rezeptur_zutat → produkt/rezeptur → produktionsauftrag → auftrag`).
