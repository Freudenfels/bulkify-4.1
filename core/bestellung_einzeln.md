# core/bestellung_einzeln.php – Einkauf: jede Bestellung einzeln (Paket 13.11.2026, Teil 6)

Regel Nico: nie eine Bestellung mit mehreren Positionen, Lieferanten oder Arten. Dann gehören jede CoA, jede Lieferung und jedes fehlende Papier genau einer Bestellung.

- `bestellung_aufteilen_je_position($bestellung_id, $vermerk, $wer)`: Die erste Position bleibt, jede weitere bekommt eine eigene BE-Nummer.
  - Positionen werden **verschoben**, nicht kopiert. So bleiben Liefermengen, stornierte Positionen, Chargen (`charge.bestellung_position_id`), `bestellung_charge`, Reservierungen, Dokumente je Position und Lieferanten-Aufkleber gültig.
  - Kopfdaten (Lieferant, Status, Daten, Versand) werden übernommen. Es wird kein Lieferant gesetzt und keine Nachricht verschickt. Dazu kommt ein Verlaufseintrag.
- `bestellung_einzeln_status()`: Status jeder Teil-Bestellung aus den gespeicherten Positionen, ohne Liefermengen neu zu rechnen.
  - Alles geliefert bzw. Rest storniert → geliefert.
  - Nur stornierte Positionen ohne Lieferung → storniert.
  - Sonst offen wie der Kopf.

**Versand und Sendung (Teil 6.4):**
- `bestellung_sendung_schema()`: Spalten sendung_art, sendung_anzahl, palettenart, kisten_anzahl, gewicht_kg, masse, stellplaetze.
- `bestellung_sendung_text()`: Kurztext, z. B. „2 × Palette (Euro-Palette), 12 Kartons, 840 kg, 2 Stellplätze“.
- `bestellung_tracking_setzen()` / `bestellung_tracking_liste()`: mehrere Tracking-Nummern in `lieferung_paket`.
- `bestellung_fehlt($zeile)`: was für „Offen je Lieferant“ noch fehlt – Bestätigung, CoA (bei Rohstoff/Bulk), Tracking, Lieferung.

**Alte Mehrfach-Wege (13.11.2026):** `bestellung_aus_bedarf()` (Produktionsauftrag „Bedarf bestellen“, je Hauptlieferant) und `auftrag_express_bestellung()` (Express am Auftrag) legen weiter einen Entwurf an. Er wird sofort mit `bestellung_aufteilen_je_position()` in Einzelbestellungen je Position aufgeteilt. In einer äußeren Transaktion öffnet die Funktion keine eigene.
