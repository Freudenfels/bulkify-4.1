# Lager 2 – Fremdlager (lager2.php)

Fertigwaren-Bestand der **Fulfillment-Kunden** (Route `?p=lager2`). Fremdlager = gehört dem Kunden,
nur für Kunden mit `kunden.nutzt_fulfillment=1`. Andere Kunden bekommen ihre Ware geliefert und haben kein Lager 2.

## Modell (baut auf bestehendem Fertigwaren-Modell auf)
- Ein Fertigprodukt = `produkt` (Rezeptur+Verpackung+kunde_id+kundenname).
- Bestand liegt als `charge` auf dem **Verkaufsfertig-Item** (`item.kategorie='verkaufsfertig'`, `item.produkt_id`),
  geholt/angelegt über `produkt_lageritem()`.
- Brücken-Felder am Verkaufsfertig-Item: `bsku` (interne 5-stellige Nummer ab 10000) + `shopify_inventory_item_id`
  (führender Schlüssel zum Versandsystem).

## Helfer (core/schema.php)
- `bsku_next()` / `bsku_ensure($item_id)` – BSKU vergeben (meta `bsku_seq`).
- `lager2_bestand($item_id)` – freier Bestand (Summe freier Chargen).
- `lager2_produkte($kunde_id=null)` – alle Lager-2-Produkte der Fulfillment-Kunden + Bestand + Brückenfelder.
- `lager2_einbuchen($produkt_id,$menge,$charge,$mhd,$notiz)` – manuelle Einbuchung (neue Charge).
- `auftrag_ist_fulfillment($auftrag_id)` – Kunde des Auftrags nutzt Fulfillment?
- `produktion_fertigware_einbuchen()` vergibt bei Fulfillment-Kunden automatisch die BSKU.

## Seite
Übersicht je Kunde: Produkt, BSKU (vergeben-Button), Bestand, Shopify-Verknüpfung (inventory_item_id speicherbar)
+ Formular „Fertigware einbuchen". Nav-Gruppe Lager (admin) und Warenwirtschaft (Werk).

## Offen (nächste Schritte)
- **ds_api.php** (Token `X-DS-Token`): `GET action=lager2` (Feed: verfuegbar, shopify_inventory_item_id, bsku),
  `POST verbrauch_sku` (iid/bsku, menge, ref → abbuchen, idempotent), `POST retoure_sku` (+ Bestand),
  `POST retoure_defekt` (nur Doku). Muss exakt zum Fulfillment-Vertrag passen (`fulfillment-web/src/bulkify_dash.php`).
- Reverse-Feed vom Fulfillment (`bulkify_feed.php`) ziehen, um Produkt per inventory_item_id zu verknüpfen.
- Kisten-Etikett mit BSKU-Barcode (Code128).
