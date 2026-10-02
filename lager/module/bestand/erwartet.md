# bestand/erwartet.php – Erwartete Lieferungen („Waren, auf die wir warten")

**Zweck:** Erster Menüpunkt im Lager. Zeigt die Lieferungen, die beim Lieferanten **bestellt**, aber
**noch nicht angekommen** sind – mit erwartetem Termin und Sendungsnummer. Der Mitarbeiter sieht sofort,
was reinkommt, und bucht es bei Ankunft direkt ein.

**Woher die Daten:** `erp_erwartete_lieferungen()` (die Naht `lager/core/erp.php`) – Dashboard-Tabelle
`bestellung` mit `status='bestellt'` und `angekommen_am IS NULL`, sortiert nach erwartetem Termin
(`eta_geplant`). Je Lieferung die Positionen aus `bestellung_position` (Artikel + Menge).

**Pro Lieferung:** Lieferant · Bestellnummer · bestellt am · erwartet am (überfällig = Badge) ·
Sendungsnummer (mit Versandanbieter). Pro Position ein Knopf **„Einbuchen"** → öffnet den
**Wareneingang vorbefüllt** (`?p=eingang&item=…&menge=…&lieferant=…&charge=<Bestellnr>`), wo nur noch
MHD/Charge geprüft und gebucht werden. Positionen ohne hinterlegten Artikel zeigen einen Hinweis.

Leerzustand: Hinweis, dass nichts unterwegs ist (erscheint, sobald im Dashboard eine Bestellung als
„bestellt" markiert wird). Route `?p=erwartet`. Siehe auch [eingang.md](eingang.md).
