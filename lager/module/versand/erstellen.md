# versand/erstellen.php – Lieferschein-Generator

Route `?p=lieferschein_neu`. Einfache Erfassung wie beim Angebot: **Kunde** wählen → Lieferadresse wird
vorgeschlagen (per AJAX `aktion=laden`, aus `erp_kunde_adressen`), Felder frei änderbar. **Produkte** aus dem
Katalog des Kunden (`erp_versandkatalog`) je Zeile mit **Kartons × Menge/Karton**; Menge = Kartons × Menge/Karton.
**Paket oder Palette**.

- **Versicherungswert** je Zeile: Stückwert = **EK**, fehlt er, **VK/2** (vorbelegt, überschreibbar). Summe =
  Σ Stückwert × Menge → Feld „Versicherungswert" (überschreibbar).
- **Gewicht**: Σ (Gewicht je Stück × Menge) → „Gewicht gesamt" + „≈ kg je Paket" (überschreibbar).
- **Pakete** = Summe der Kartons (überschreibbar).

Speichern (`aktion=erstellen`) → `lg_lieferschein_erstellen()` legt `lg_versand` + `lg_versand_pos` an und
springt ins **Versand-Detail** (`?p=versand_detail`). Dort: **Lieferschein öffnen (PDF-Download)**,
**Lieferschein drucken** (Netzwerkdrucker über die Brücke, `drucker_lieferschein`), und später das
**Versand-Label** über **DHL**/**Cargoboard** (vorhandene Infrastruktur, `versand_dhl.php`/`versand_cargoboard.php`).

Erreichbar über den eigenen Menüpunkt **„Lieferschein erstellen“** (Lager 1, seit 08.11.2026) und über Warenausgang → „+ Lieferschein erstellen".

**Zahlen (Block D, 11.11.2026):** Mengen-, Gewichts- und Wertfelder lesen deutsch (`erp_menge_parse` bzw. `pr_menge_parse`): 135.000 = 135000, 12,5 = 12,5, 0.045 = 0,045.

## Mengen-Parser (13.11.2026)
Alle Mengen-, Gewichts- und Maßfelder lesen über den gemeinsamen Parser (`pr_menge_parse()` bzw. `erp_menge_parse()`/`lg_zahl()`): „1.500“ = 1500, „1,5“ = 1,5, „1.5“ = 1,5.
