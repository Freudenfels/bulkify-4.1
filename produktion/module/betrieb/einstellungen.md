# produktion/module/betrieb/einstellungen.php
Einstellungen (`?p=einstellungen`): Stammdaten **Maschinen** und **Räume** je in einem Reiter (.settabs), jeweils mit **Reinigungsintervall** (kein/je Produktion/täglich/wöchentlich/monatlich/vierteljährlich), Raumzuordnung je Maschine, Hinweis. Anlegen/Entfernen. Eigene Tabellen `pr_raum`, `pr_maschine`. Basis der generierten Reinigungspläne (`?p=reinigung`).

**Maschinenfuhrpark (Spec 9.3):** Jede Maschine hat einen **Typ** (koppelt sie an den Produktionsschritt – Mischer beim Mischen, Kapselmaschine beim Verkapseln usw.) und einen **QR-Code** (leer = automatisch `MA-<id>`), der im Produktionsmodus je Schritt gescannt/gewählt wird. In der Maschinenliste lassen sich Typ und QR je Zeile direkt speichern (`maschine_typ`).
