# lager/chargen.php – Chargenverfolgung

**Zweck:** Übersicht aller Chargen + **Rückverfolgung Rohstoff ↔ Produkt**. Route `?p=chargen` (Rollen production/labor/fulfillment/einkauf, admin sowieso).

**Liste:** alle `charge` (Rohstoff/Verpackung/Fertigware) mit Artikel, Art, verfügbarer Menge, MHD, Lieferant, Status (frei/Quarantäne/leer). Suche über Charge-Nr. oder Artikelname. Zeile klickt ins Detail.

**Detail (`&id=`):**
- Kacheln: Status, verfügbare/Ursprungs-Menge, MHD.
- **Herkunft:** Artikel, Lieferant, Wareneingangsdatum, Notiz.
- **Zusammensetzung (eingesetzte Chargen):** nur bei Fertigwaren-Chargen – findet den Produktionsauftrag über `charge.pa_id` (Fertigware wird als `PR-Basis.A/.B/.C` eingebucht, je Teilproduktion eine Charge; der Vergleich `produktionsauftrag.nummer = charge.charge_nr` bleibt nur als Fallback für Altchargen) und listet aus `produktion_verbrauch` alle eingesetzten Rohstoff-Chargen (mit Link).
- **Verwendet in Produktionen:** aus `produktion_verbrauch` (charge_id) – in welchen Produktionsaufträgen diese Charge verbraucht wurde (Produkt, Kunde, Menge), Link zum Produktionsauftrag.

**Datenbasis:** Verknüpfung über `produktion_verbrauch (pa_id, item_id, charge_id, menge, einheit)` – wird bei der Station „Rohstoffe bereitstellen"/„Verkapselung" beim FEFO-Abbuchen geschrieben.

**Für Auftrag (Baustein 4):** Charge kann einem Kundenauftrag zugeordnet sein (`charge.auftrag_id`, aus der Bestellung geerbt oder beim manuellen Wareneingang gewählt). Detail zeigt „Für Auftrag" (verlinkt); Produktionsauftrag-Detail listet „Wareneingänge für diesen Auftrag"; Werk-Cockpit „Neu eingetroffen – für deine Aufträge".

## Warenart-Labels (Teil 3 Block K)
`$katLbl` nutzt `kategorie_labels()` (`core/schema.php`). Damit wird `fertig` korrekt als **Bulkware** und `verkaufsfertig` als **Fertigprodukt** angezeigt (vorher fehlte `fertig` und `verkaufsfertig` hieß fälschlich „Fertigware").

## Bulk-Charge → Kundencharge (2026-10-30)
Die Detailseite einer Charge zeigt unter „Verwendet in Produktionen" zusätzlich die Spalte **Kundencharge**: die aus diesem PA entstandene Fertigware-/Kundencharge (`charge.pa_id` = PA, `item.kategorie IN ('fertig','verkaufsfertig')`), verlinkt. Damit ist von der Bulk-Charge aus direkt sichtbar, in welche Kundencharge(n) sie geflossen ist (Gegenrichtung zur „Zusammensetzung" der Kundencharge). Gespeist aus Easy Production / `produktion_verbrauch`.
