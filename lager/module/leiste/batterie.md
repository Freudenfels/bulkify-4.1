# leiste/batterie.php – Batterie prüfen (`?p=batterie`)

Liste der Blinker, die (geschätzt) eine neue Batterie brauchen, am meisten genutzt zuerst. Je Zeile:

- **Blinken** – lässt den Blinker grün blinken und piepen, damit man ihn im Lager findet.
- **Aus** – schaltet ihn ab.
- **Batterie neu** – nach dem Wechsel: setzt Auslösungen und Leuchtzeit auf 0 (`leiste_batterie_neu`).

Erreichbar über den **Warnbalken** oben (erscheint, sobald mindestens ein Blinker über der Grenze liegt).

## Wichtig
Das ist eine **Schätzung** anhand der aufsummierten Leuchtzeit (`lg_leiste.verbrauch_sek`), kein echter Akkustand – die Hardware meldet keinen. Die Grenzen stehen in `core/config.php` (`LG_BATT_HOCH`, `LG_BATT_TAUSCH`) und werden mit der Erfahrung nachjustiert. Gezählt wird zentral in `led_befehl()` (jedes Leuchten, kein Ausschalten).
