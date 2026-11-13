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

## Upload nur an eigene offene Unterlagen (13.11.2026)
`lu_upload_erlaubt()`: Ein Lieferant darf nur zu Artikeln/Rezepturen hochladen, die in seiner offenen Unterlagen-Liste stehen (Aufgabenliste + fehlende Unterlagen seiner laufenden Bestellungen). Sonst abgelehnt und im Verlauf des Lieferanten protokolliert. Uploads des Teams (intern) bleiben frei.

## Unterlagen-Sperre zentral (13.11.2026)
`lu_bestellen_pruefen($bid, $ausnahme_grund, $wer)`: eine Prüfung für alle Bestellwege. Genutzt von `bestellung_als_bestellt_markieren()` (Bestellseite, Einkauf mobil) und `bestellung_direkt_abschliessen()` (Einkaufsliste, Beschaffungsweg am Auftrag, Produktionsauftrag). Fehlen Unterlagen: gesperrt, Bestellung bleibt Entwurf; Admin-Ausnahme mit Pflicht-Grund. Online-Bestellungen ausgenommen.

**Stornierte Positionen (13.11.2026, Fable-3 T2.5):** `lu_bestellung_fehlt()` zählt Positionen mit `rest_storniert_am` (Position storniert bzw. Rest storniert) nicht mit – eine stornierte Position sperrt das Bestellen nicht mehr.

**Sperr-Entwurf (Fable-4 T3.1, 13.11.2026):** `lu_entwurf_markieren($bid)` setzt `bestellung.unterlagen_wartet` = 1, solange ein Entwurf (status offen) wegen fehlender Unterlagen nicht gesendet werden darf (aus `lu_bestellen_pruefen()` und `bestellung_als_entwurf()`), sonst 0; beim ersten Setzen Vermerk an den Aufträgen. `bp_rest_sql($a, $b)` zählt solche Entwürfe nicht als unterwegs. `lu_entwuerfe_pruefen()` löscht das Kennzeichen, wenn Unterlagen nachgereicht sind; `lu_sperr_entwuerfe()` liefert die Zeilen für „Zu bestellen“.

**Storniert zählt nirgends (Fable-4 T3.7, 13.11.2026):** Stornierte Positionen (`rest_storniert_am`) sind keine Quelle mehr für „Material zuordnen“, „Beschaffungsweg koppeln“ und Bulk reservieren; Reservierungen auf stornierten/gelöschten Positionen zählen nicht als Deckung oder bestellt; „Position stornieren“ setzt die Reservierungen der Position auf storniert. Fortschritt „Etiketten bestellt“ nur ab gesendet/bestellt und ohne Storno.
