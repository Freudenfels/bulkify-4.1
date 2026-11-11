# bestand/bewegungen.php – Bewegungen (Warenlager-Manager)

**Die Historie: was kam rein, was ging raus.** Liste aus `lg_bewegung` (neueste zuerst, bis 300), Reiter **Alle / Eingang / Ausgang** mit Zählern. Route `?p=bewegungen`.

Je Zeile: Zeit, Richtung (Eingang grün / Ausgang orange), Artikel (verlinkt zur Charge, falls noch vorhanden), Menge, Grund. Gespeist von `lg_bewegung_log()` aus Wareneingang (`eingang.php`) und Warenausgang (`ausgang.php`). `item_name` wird in der Bewegung als Momentaufnahme gehalten, damit die Liste auch lesbar bleibt, wenn die Charge längst leer/weg ist.

**Inventur (11.11.2026):** Korrekturen der Inventur haben den Typ `inventur` mit Badge „Inventur“ und eigenem Reiter. Die Menge hat ein Vorzeichen (+/−). Der Grund lautet „Inventur <Name> <Datum> · <Ursache> …“. Das Badge kommt aus `lg_bewegung_badge()` in `core/schema.php`.
