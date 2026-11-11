# lager/core/erp_inventur.php – Naht der Inventur zum Dashboard

Seit 11.11.2026. Wie `erp.php`: Alle Lese-Zugriffe der Inventur auf Dashboard-Tabellen stehen hier. Das sind `charge`, `charge_gebinde`, `item`, `rezeptur`, `kapselgroesse`, `kunden` und `benutzer`.

- `erp_inv_objekte($inv)` liefert alle Zählobjekte im Umfang mit dem aktuellen Buchbestand.
  - Je Gebinde mit Bestand ist es ein Objekt. Chargen ohne aktives Gebinde sind ebenfalls ein Objekt.
  - Ware in der Produktion zählt nicht mit.
  - Gefiltert wird nach Lager 1 bzw. Lager 2 (je Kunde) und nach Warenarten. Etiketten sind dabei Verpackung mit der Rolle „etikett“.
- `erp_inv_objekt()` liefert ein einzelnes Objekt. `erp_inv_gebinde_der_charge()` liefert die Gebinde zur Auswahl, ohne Mengen.
- `erp_inv_scan()` löst einen Code auf:
  - Gebinde-QR `bg=`
  - Charge-QR `?p=charge&id=`
  - Inventaraufkleber mit 7 oder 8 Ziffern
  - `GB-…`
  - Blinker-Code
- `erp_inv_kapsel_mg()` liefert das Gewicht einer Kapsel: Füllgewicht der Rezeptur plus Leerkapsel. Gerechnet wird wie in der Produktion (Handbuch 4.8).
- `erp_inv_wert()` rechnet den Wert in € zum EK, mit `item.ek_preis` je `preis_bezug`.
- `erp_inv_inventar_reservieren()` vergibt die nächste freie 8-stellige Inventarnummer für ein Gebinde ohne Etikett. Es gilt dieselbe Sperre wie beim Wareneingang.
