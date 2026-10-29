# rezeptur/dubletten.php – Doppelte Rezepturen (Report, Teil 3)

**Zweck:** Read-only-Liste inhaltlich **identischer** Rezepturen, damit Nico entscheiden kann, welche er zu einer gemeinsamen Stammrezeptur zusammenlegt. **Es wird nichts automatisch zusammengelegt.**

**Identisch =** gleicher `rezeptur_fingerprint()` (core/schema.php): Zutaten (`rezeptur_zutat`: Rohstoff-`item_id` bzw. Freitext + `menge_mg`), `kapselgroesse_id`, `einheit_fuellmenge`, `darreichungsform` – Reihenfolge egal. Leere Rezepturen (keine Zutaten) fallen raus.

**Anzeige:** Gruppen mit ≥ 2 Rezepturen, je Rezeptur Nummer/Name (Link ins Detail), Kunde, Status, aktuelle Stammrezeptur. Eine Gruppe gilt als **„bereits verknüpft"**, wenn alle Rezepturen auf dieselbe Stammrezeptur zeigen (bzw. eine davon die Stammrezeptur ist). Button je Rezeptur führt direkt zum Panel „Interne Stammrezeptur" (`rezeptur_detail#stamm`).

**Zusammenlegen (durch Nico, später):** über das bestehende Panel „Interne Stammrezeptur" (`rezeptur/detail.php`, `stamm_rezeptur_id`). `rezeptur_bulkitem()` löst über die Stammrezeptur auf → alle verknüpften Rezepturen teilen **einen** Bulk-Artikel/-Bestand. Der kundenbezogene Name bleibt an der jeweiligen Kundenrezeptur bzw. am `produkt.kundenname`.

**Route/Nav:** `?p=rezeptur_dubletten` (admin-only per Default-Route), Menü „Produkt → Doppelte Rezepturen".
