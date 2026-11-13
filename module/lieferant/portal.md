# lieferant/portal.php – Übersicht im Lieferantenportal

Route: `?p=lieferant_portal`

Drei Kennzahlen (offene Bestellungen, davon **noch nicht bestätigt**, offene Anfragen), darunter zuerst die **unbestätigten Bestellungen** – dafür ist der Lieferant hier – und dann alle laufenden mit zugesagtem Termin und Fortschritt.

## Kachel Rückfragen
Eine vierte Kachel zeigt, wie viele Nachrichten von bulkify noch ungelesen sind (`nachrichten_ungelesen()`), und führt zu `?p=lieferant_nachrichten`.

## Paket 12.11.2026 Teil 4 (Unterlagen-Regel)
Hinweis „Es fehlen noch Unterlagen (n)“ mit Link auf die Aufgabenliste.

**Bestätigen mit Unterlagen-Sperre, Portal ohne Entwürfe (13.11.2026, Fable-3 T2.2):** Die Übersicht zählt keine Entwürfe (`bestellung_portal_sichtbar_sql()`).

## Sofortfix 13.11.2026: Unterlagen-Sperre wird Quarantäne-Sperre
Regel Nico: Unterlagen fehlen = Ware bleibt in Quarantäne. Bestellen und Einbuchen gehen immer. Artikel ohne Unterlagenpflicht: „kein Dokument nötig“ (Details `core/lieferant_unterlagen.md`).

Startseite: Hinweis „Es fehlen noch Unterlagen (n). Bitte fehlende Unterlagen hochladen, sonst bleibt die Ware bei uns gesperrt.“ (statt „Ohne sie können wir nicht bestellen“).
