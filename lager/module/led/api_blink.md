# lager/module/led/api_blink.php
Interner Blink-Auslöser für **andere Programme** (aktuell: Produktion „Pick-to-Light"). Route `?p=api_blink`, läuft vor dem Lager-Login-Gate und macht eigene Auth.

- **Auth:** gemeinsamer Token `LG_BLINK_TOKEN` (aus `secrets.php`, per `define()`) **oder** Loopback (`REMOTE_ADDR` = 127.0.0.1/::1, also Server-zu-Server). Ohne beides: 403.
- **Eingang:** entweder `leiste` (Blinker-Code direkt, auch Barcode mit „XD") **oder** `charge_id`/`charge_nr`; optional `aktion=an|aus`, `farbe`, `sek`.
- **Wirkung:** `leiste` → `led_leiste_normalisieren()`+`leiste_per_code()` (direkter Blinker, z. B. Hardware-Test). Sonst über die Charge: zur Charge gebundene Leiste (`leiste_fuer_charge`) bzw. Kisten-Blinker (`kiste_fuer_charge`→`kiste_blinker`). Dann `leiste_finden()`/`leiste_aus()`. Antwort JSON `{ok, meldung}`.

**Deploy-Hinweis:** Für den Live-Server `LG_BLINK_TOKEN` in `secrets.php` setzen (ein Wert, gilt für alle Programme). Lokal reicht Loopback, dort ist kein Token nötig. Die Produktion ruft diesen Endpunkt über `pr_lager_blink()` (produktion/core/erp.php) serverseitig auf.
