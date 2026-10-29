# rezeptur/dubletten.php – Doppelte Rezepturen (Report, Teil 3)

**Zweck:** Read-only-Liste inhaltlich **identischer** Rezepturen, damit Nico entscheiden kann, welche er zu einer gemeinsamen Stammrezeptur zusammenlegt. **Es wird nichts automatisch zusammengelegt.**

**Identisch =** gleicher `rezeptur_fingerprint()` (core/schema.php): Zutaten (`rezeptur_zutat`: Rohstoff-`item_id` bzw. Freitext + `menge_mg`), `kapselgroesse_id`, `einheit_fuellmenge`, `darreichungsform` **und gleicher Geschmack** – Reihenfolge egal. Leere Rezepturen (keine Zutaten) fallen raus.

**Geschmack zählt mit:** `rezeptur_geschmack()` ermittelt ein Geschmacks-Token (`geschmack_aus_text()` + `geschmack_synonyme()`, DE/EN) aus – in dieser Reihenfolge – Aroma-/Geschmacks-Zutaten (Bezeichnung enthält aroma/flavor/geschmack), Rezepturname und zugehörigen Produktnamen. Match am Wortanfang (`\b<syn>`), damit Komposita greifen („Schokogeschmack") und „chocolate" nicht „cola" triggert. Das Token geht als `|g…` in den Fingerabdruck → **unterschiedlicher Geschmack ⇒ keine Dublette** (Schoko/Vanille werden nicht mehr fälschlich zusammengeworfen). Steht das Aroma als eigene Zutat, differenziert es ohnehin schon.

**Anzeige:** Gruppen mit ≥ 2 Rezepturen, je Rezeptur Nummer/Name, Geschmack, Kunde, Status, aktuelle Stammrezeptur (als Text, kein Direkt-Link). **Klick auf die Rezeptur öffnet ein Popup** (kein Seitensprung): Zutaten mit mg je Einheit, Form, Kapselgröße, Füllgewicht, Geschmack, zugehörige Kunden/Produkte – darin die einzigen Links zur Rezeptur-Seite („Rezeptur öffnen" + „Stammrezeptur setzen"). Je Gruppe ein Button **„Vergleichen"**: alle Rezepturen der Gruppe nebeneinander als Tabelle (Merkmale + Zutaten-Union), Abweichungen von der ersten Spalte rot markiert. Alle Detaildaten als JSON eingebettet, Modal rein clientseitig (read-only, kein Backend-Call). Eine Gruppe gilt als **„bereits verknüpft"**, wenn alle Rezepturen auf dieselbe Stammrezeptur zeigen (bzw. eine davon die Stammrezeptur ist).

**Zusammenlegen (durch Nico, später):** über das bestehende Panel „Interne Stammrezeptur" (`rezeptur/detail.php`, `stamm_rezeptur_id`). `rezeptur_bulkitem()` löst über die Stammrezeptur auf → alle verknüpften Rezepturen teilen **einen** Bulk-Artikel/-Bestand. Der kundenbezogene Name bleibt an der jeweiligen Kundenrezeptur bzw. am `produkt.kundenname`.

**Route/Nav:** `?p=rezeptur_dubletten` (admin-only per Default-Route), Menü „Produkt → Doppelte Rezepturen".

## Bulk-Bestand (Requirement 1)
Popup, Tabelle („Bulk-Bestand"-Spalte) und Vergleich zeigen je Rezeptur den Bestand ihres EIGENEN Bulk-Artikels (`item WHERE rezeptur_id=? AND kategorie='fertig' AND gesperrt=0`, ohne Stamm-Auflösung, legt nichts an): verfügbare Menge, Chargen (Nr/Menge/Status/MHD) und aktive Reservierungen (Auftrag/Kunde). Zusammengeführte Rezepturen haben keinen eigenen Bulk → „– / via Stamm".
