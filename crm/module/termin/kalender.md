# kalender.php – Monatsansicht

Route `?p=kalender`. Aufbau und Klassen wie der **Produktions-Kalender des Dashboards**
(`bx-cal`, `bx-cal-cell`, `bx-cal-item`) – damit beide gleich aussehen und man sich nicht
umgewöhnen muss.

## Zwei Sorten Einträge
| | |
|---|---|
| **Termin** (grüner Punkt) | hat eine Uhrzeit – da muss man irgendwo sein |
| **Wiedervorlage** (grauer Punkt) | hat nur einen Tag – da muss man an jemanden denken |
| **überfällig** (roter Punkt) | offene Wiedervorlage, deren Tag vorbei ist |

Erledigtes bleibt stehen, nur blasser – damit man sieht, dass an dem Tag etwas war.

## Höchstens vier je Tag
Danach steht **+N weitere**. Ohne diese Grenze sprengt ein einziger Tag die Zelle: Die
automatischen Wiedervorlagen für gesendete Angebote werden alle am selben Tag fällig, und beim
ersten Start sind das schnell zwanzig.

## Zeitzonen
Termine stehen in UTC in der Datenbank, einsortiert wird nach dem **Berliner** Tag
(`fmt_zeit(...)`). Sonst rutscht ein Termin um 00:30 auf den Vortag. Wiedervorlagen haben von
vornherein nur ein Datum.