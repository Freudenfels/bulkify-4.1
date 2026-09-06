# termin.php – Termine

## Wozu
Rückruf, Messe, Besuch. Bewusst klein: Titel, Zeitpunkt, Ort, optional ein Kontakt dahinter. Wer einen echten Kalender braucht, nutzt seinen Kalender – hier geht es um den Bezug zum Kontakt.

## Zeitzonen
Eingegeben wird in **Berliner Zeit** (so denkt der Mensch), gespeichert wird **UTC** (so denkt die Datenbank). Umgerechnet wird an genau einer Stelle: `termin_zeit_lesen()`. Geprüft: 14:30 eingegeben → 12:30 UTC gespeichert → 14:30 angezeigt.

## Farben
Anders als bei der Wartezeit zählt hier die **Zukunft**: heute rot, in den nächsten drei Tagen gelb, später ruhig.
