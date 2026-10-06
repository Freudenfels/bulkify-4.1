# lager/lager2_import.php – Lager-2 Bestände importieren

**Route:** `?p=lager2_import` · **Menü:** Import → „Lager-2 Bestände" · **Rechte:** wie `lager2` (production/fulfillment/einkauf/labor + admin).

**Zweck:** Für einen **Fulfillment-Kunden** die Produkte **aus der Auftragshistorie** rauslesen und die aktuellen Fremdlager-Bestände (Lager 2) **in einem Rutsch** einbuchen – statt jedes Produkt einzeln. Spart die manuelle Einzel-Einbuchung beim Start.

## Ablauf (3 Schritte, mit Bestätigung)
1. **Kunde wählen** (Dropdown der Fulfillment-Kunden, `nutzt_fulfillment=1`).
2. **Erfassen:** Tabelle aller Produkte aus `auftrag WHERE kunde_id=<Kunde> AND produkt_id IS NOT NULL` (DISTINCT, mit Rezeptur + aktuellem Lager-2-Bestand, read-only – legt nichts an). Je Zeile Felder **Menge** (Pflicht für Buchung), **Charge**, **MHD** (optional). Leere Menge = übersprungen. → `aktion=vorschau`.
3. **Vorschau / Bestätigen:** zeigt genau, was gebucht wird (Produkt · Rezeptur · Menge · Charge/MHD · Hinweis „neue BSKU" / „Kunde wird zugeordnet"). Erst **„Jetzt eintragen"** (`aktion=eintragen`) bucht. „Zurück / ändern" springt zur Erfassung.

## Buchung (`aktion=eintragen`)
Je Zeile mit Menge > 0: prüft, dass das Produkt wirklich in der Historie des Kunden liegt; setzt `produkt.kunde_id` auf den Kunden, **falls leer** (Katalog-/Rezeptur-Produkte ohne Kunde); dann `lager2_einbuchen($pid,$menge,$charge,$mhd,'Anfangsbestand-Import (Lager 2)')` → legt (lazy) das Verkaufsfertig-Item + **BSKU** an (`produkt_lageritem`/`bsku_ensure`) und bucht die Charge. Danach Flash „N Produkt(e) eingebucht".

**Lesen statt Filtern nach kunde_id:** Über die Aufträge werden auch Produkte gefunden, die am Produkt selbst **keinen** Kunden gesetzt haben (würden sonst im Einbuch-Dropdown fehlen). Siehe `lager2.php` (manuelle Einzel-Einbuchung) und `core/schema.php` (`lager2_einbuchen`, `lager2_bestand`).
