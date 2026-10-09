# charge/liste.php – Produktionschargen (CH/CHE)

Durchsuchbare Entität der **Produktions-/Misch-Chargen** (Spec 16), analog R…/AN…. Route `?p=produktionschargen`.

- Zeigt die **Haupt-Chargen** (`prod_charge.parent_id IS NULL`); Unterchargen (-A/-B) stehen im Detail.
- **CH** = intern gemischt/produziert, **CHE** = extern zugekaufte Bulkware (`prod_charge.typ`).
- Suche über Charge-Nr., Produktionsauftrag, Rezeptur, Produkt **und Rohstoff-Batchnummer** (`prod_charge_rohstoff.batch_nr`). Filter nach Typ.
- Spalten: Charge-Nr · Typ · Rezeptur/Produkt (+PA) · Menge · Unterchargen-Anzahl · Rohstoff-Anzahl · Status · Tag.
- Read-only; Produktionschargen entstehen in der Produktion (Sub-App) über die Helfer in `core/schema.php` (`prod_charge_anlegen`/`prod_charge_sub_anlegen`/`prod_charge_rohstoff_verknuepfen`).

Rechte: production/labor/fulfillment/einkauf (+admin), wie `chargen`. Nav: Warenwirtschaft.
