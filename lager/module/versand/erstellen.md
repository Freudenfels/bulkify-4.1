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
Alle Mengen- und Maßfelder (Gewichte siehe unten) lesen über den gemeinsamen Parser (`pr_menge_parse()` bzw. `erp_menge_parse()`/`lg_zahl()`): „1.500“ = 1500, „1,5“ = 1,5, „1.5“ = 1,5.

**Gewichte (13.11.2026, Fable-3 T1.2):** Gewichts- und Wiegefelder lesen über `gewicht_lesen()` (core/gewicht.php): Punkt und Komma sind Dezimaltrenner („25.125“ kg = 25,125; „6.482“ g = 6,482; „25,000“ = 25), nur wenn beide vorkommen, gilt das letzte. Vorbelegungen ohne Tausenderpunkt (`gewicht_feld()`). Stückzahlen bleiben bei der Mengen-Lesart („12.000“ Stk = 12000). Hier: Gewicht gesamt (kg, automatische Vorbelegung ohne Tausenderpunkt) und Gewicht je Position (g).
