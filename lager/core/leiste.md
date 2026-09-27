# lager/core/leiste.php – Blinker im Chaos-Modell (großes Lager)

Im großen Lager hängt eine Blinker an einer **Charge** (Palette), nicht an einem festen Platz. Die Palette darf frei umgestellt werden, die Blinker wandert mit. Gefunden wird sie, indem man sie klingeln lässt.

- `leiste_binden($code, $charge_id)` – Blinker an eine Charge binden. Hängt die Blinker schon an einer anderen Charge oder die Charge schon an einer anderen Blinker, gibt es eine Fehlermeldung statt eines stillen Umhängens.
- `leiste_loesen($id)` – Charge leer oder raus: Blinker wird frei, wandert nach vorn und kann neu vergeben werden.
- `leiste_finden($id)` / `leiste_aus($id)` – Blinker klingeln lassen (Licht + Ton) bzw. ausschalten. Nutzt `led_befehl()` aus `led.php`.
- `leiste_fuer_charge($charge_id)` – welche Blinker hängt an dieser Charge.
- `leiste_sicherstellen($code)` – nimmt eine noch unbekannte Blinker beim ersten Binden automatisch auf.
- `charge_text($c)` – Kurztext: Rohstoff, Chargennummer, Restmenge.

Die Chargen kommen aus dem Dashboard über `erp.php` (`erp_charge`, `erp_chargen_suche`), nur eigener Bestand, kein Fremdlager.
