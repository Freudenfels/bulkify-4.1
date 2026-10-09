# bestand/erwartet.php – Erwartete Lieferungen („Waren, auf die wir warten")

**Zweck:** Erster Menüpunkt im Lager. Zeigt die Lieferungen, die beim Lieferanten **bestellt**, aber
**noch nicht angekommen** sind – mit erwartetem Termin und Sendungsnummer. Der Mitarbeiter sieht sofort,
was reinkommt, und bucht es bei Ankunft direkt ein.

**Woher die Daten:** `erp_erwartete_lieferungen()` (die Naht `lager/core/erp.php`) – Dashboard-Tabelle
`bestellung` mit `status='bestellt'` und `angekommen_am IS NULL`, sortiert nach erwartetem Termin
(`eta_geplant`). Je Lieferung die Positionen aus `bestellung_position` (Artikel + Menge). Jede Position
bekommt eine **`warenart`** für die Reiter: 1) `item.kategorie`, 2) auftragsgebundener Zukauf = `fertig`,
3) sonst per `erp_warenart_raten()` aus dem Freitext-Namen (Verpackungs-/Darreichungs-Begriffe) – bewusst
grob, die echte Zuordnung passiert beim Einbuchen.

**Darstellung: eine Tabelle, Reiter nach Kategorie.** Statt Karten je Lieferung steht alles in **einer
Tabelle** – eine Zeile je Position. Die Lieferungs-Infos werden auf **jede Zeile übernommen**:
Spalten **Artikel** (+ Kapselgröße-Badge) · **Kategorie** · **Lieferant** (+ Bestellnr.) · **Erwartet**
(Datum, „überfällig"-Badge, Bestelldatum) · **Sendung** (Tracking + Anbieter) · **Menge** · **Einbuchen**.
Darüber die **Reiter** (`.lg-reiter`) „Alle" + die vorhandenen Kategorien aus `erp_kategorien()` + „Sonstiges"
(mit Zähler); Umschalten + Suche filtern die Tabelle rein clientseitig (kein Reload, Infos bleiben erhalten).

Pro Zeile der Knopf **„Einbuchen"** → öffnet den **Wareneingang vorbefüllt**
(`?p=eingang&item=…&menge=…&lieferant=…&charge=<Bestellnr>`), wo nur noch MHD/Charge geprüft und gebucht
werden. Lieferungen ohne hinterlegte Position erscheinen als eine Zeile mit **„Freies Einbuchen"** (`?p=we`).

Leerzustand: Hinweis, dass nichts unterwegs ist (erscheint, sobald im Dashboard eine Bestellung als
„bestellt" markiert wird). Route `?p=erwartet`. Siehe auch [eingang.md](eingang.md).
