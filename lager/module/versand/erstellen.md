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

Erreichbar über Warenausgang → „+ Lieferschein erstellen".
