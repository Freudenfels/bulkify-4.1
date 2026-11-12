# core/lager_ort.php – Lagerort je Charge im Dashboard

**Anlass (Nico 12.11.2026):** „Wenn ich im Warenlager auf das Produkt klicke, sehe ich keine Info, nicht die Blinker-Nummer, keinen Bestand und kann den Bestand nicht anpassen.“

**Was es macht:** Liest aus den Tabellen der Lager-App, wo eine Charge liegt, und zwar **nur lesend**:
- **Blinker** (`lg_leiste.code`, an der Charge gebunden)
- **Kiste** mit Fach (`lg_kiste_inhalt` + `lg_kiste`)
- **Lagerplatz** (`lg_platz_inhalt` + `lg_platz`, Anzeige wie `A-01-2 (LP-00012)`)

Fehlt eine Tabelle, bleibt der Ort leer (best effort, nie Fehler).

**Funktionen:**
- `lager_ort_je_charge($ids)` – je Charge Blinker-Liste, Kiste, Platz und ein fertiger Text.
- `lager_blinker_fuer_item($item_id)` – alle Blinker-Codes eines Artikels (Kachel „Blinker“).
- `lager_ort_zelle($ort, $charge_id)` – Tabellenzelle „Ort“ mit Link „Lager-App“ (`/lager/?p=charge&id=…`: dort klingeln, umlagern).

**Wo benutzt:** Reiter „Lager“ der Rohstoffseite (`module/lager/rohstoff_detail.php`) und der Verpackungsseite (`module/lager/verpackung_detail.php`).
