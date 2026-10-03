# lager/module/led/api_blink.php
Interner Blink-Auslöser für **andere Programme** (aktuell: Produktion „Pick-to-Light"). Route `?p=api_blink`, läuft vor dem Lager-Login-Gate und macht eigene Auth.

- **Auth:** gemeinsamer Token `LG_BLINK_TOKEN` (aus `secrets.php`, per `define()`) **oder** Loopback (`REMOTE_ADDR` = 127.0.0.1/::1, also Server-zu-Server). Ohne beides: 403.
- **Eingang:** `charge_id` (Dashboard-Charge) oder `charge_nr`; optional `aktion=an|aus`, `farbe`, `sek`.
- **Wirkung:** findet die zur Charge gebundene Leiste (`leiste_fuer_charge`) bzw. den Kisten-Blinker (`kiste_fuer_charge`→`kiste_blinker`) und löst `leiste_finden()`/`leiste_aus()` aus. Antwort JSON `{ok, meldung}`.

**Deploy-Hinweis:** Für den Live-Server `LG_BLINK_TOKEN` in `secrets.php` setzen (ein Wert, gilt für alle Programme). Lokal reicht Loopback, dort ist kein Token nötig. Die Produktion ruft diesen Endpunkt über `pr_lager_blink()` (produktion/core/erp.php) serverseitig auf.
