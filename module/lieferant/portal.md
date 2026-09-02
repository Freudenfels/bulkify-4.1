# lieferant/portal.php – Übersicht im Lieferantenportal

Route: `?p=lieferant_portal`

Drei Kennzahlen (offene Bestellungen, davon **noch nicht bestätigt**, offene Anfragen), darunter zuerst die **unbestätigten Bestellungen** – dafür ist der Lieferant hier – und dann alle laufenden mit zugesagtem Termin und Fortschritt.

## Kachel Rückfragen
Eine vierte Kachel zeigt, wie viele Nachrichten von bulkify noch ungelesen sind (`nachrichten_ungelesen()`), und führt zu `?p=lieferant_nachrichten`.
