# ds_api.php – Fulfillment-Schnittstelle

Token-gesicherter JSON-Endpunkt (Header `X-DS-Token`, Token in app_meta `ds_api_token`,
Anzeige/Neuerzeugung in Einstellungen → Fulfillment-Schnittstelle). Umgeht bewusst den Front-Controller,
liegt direkt im Webroot (`/ds_api.php`). Antwortet immer JSON (Exception-Handler → 500 JSON).

Vertrag muss exakt zu `fulfillment-web/src/bulkify_dash.php` passen:
- `GET  ?action=lager2` → `{ok:true, products:[{bsku, shopify_inventory_item_id, verfuegbar, name, kunde, produkt_nr}]}`
  (nur Fulfillment-Kunden, aus `lager2_produkte()`).
- `POST ?action=verbrauch_sku` (`iid`|`bsku`, `menge`, `ref`) → Lager 2 FEFO abbuchen. Idempotent per `ref`.
- `POST ?action=retoure_sku` (…) → wiederverkäuflich zurück (neue Charge).
- `POST ?action=retoure_defekt` (…, `zustand`) → nur im Ledger dokumentieren.

Fehler: 401 (Token), 400 (ref/iid fehlt), 404 (kein Lager-2-Artikel → Fulfillment überspringt).
Helfer + Ledger (`lager2_bewegung`, unique ref+typ) in `core/schema.php`.
