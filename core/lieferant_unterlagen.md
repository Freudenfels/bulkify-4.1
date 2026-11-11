# lieferant_unterlagen.php – Unterlagen-Regel Lieferanten (Paket 12.11.2026 Teil 4)

Entscheidung Nico 11.11.2026:
1. **Preis abgeben ohne Unterlagen geht.** Der Preis trägt dann das Kennzeichen „Unterlagen fehlen“ (`lu_badge`).
2. **Aufgabenliste** „Diese Unterlagen fehlen noch“ (`lu_panel`, Portal `?p=lieferant_unterlagen`, an der Anfrage, intern an der Bestellung) mit Upload in der Zeile (`lu_upload_post`).
3. **Kalkulieren ja, bestellen erst mit Unterlagen:** `lu_bestellen_sperre($bestellung_id)` sperrt „als bestellt markieren“, das Senden aus der Bestellliste (bleibt Entwurf) und mobil. Ausnahme nur Admin mit Pflicht-Grund (`lu_ausnahme_setzen`, Spalte `bestellung.unterlagen_ausnahme`, Verlauf).
4. „versendet“ nur mit CoA je Charge: `bestellung_versand_coa_fehlt()` in `bestellung_chargen.php`.

## Was gebraucht wird (`lu_fehlt`)
- je Rohstoff (Katalog-Artikel) der Rezeptur bzw. je angefragtem Rohstoff: **CoA oder Spezifikation** am Rohstoff (egal von wem; liegt sie vor, zählt sie);
- bei Fremdfertigung, Premix und Bulk zusätzlich die **Spezifikation des Gesamtprodukts von diesem Lieferanten** (`dokument` objekt_typ `rezeptur`, lieferant_id);
- Verpackung, Etiketten, Verbrauchsmaterial: nichts.
Der Stand wird immer frisch berechnet – erledigte Punkte verschwinden sofort.

Funktionen: `lu_fehlt`, `lu_position_fehlt`, `lu_bestellung_fehlt`, `lu_bestellen_sperre`, `lu_ausnahme`, `lu_ausnahme_setzen`, `lu_badge`, `lu_offen_lieferant`, `lu_panel`, `lu_upload`, `lu_upload_post`, `lu_upload_nachlauf`.
