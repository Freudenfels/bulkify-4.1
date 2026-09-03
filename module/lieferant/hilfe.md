# hilfe.php – Anleitung im Lieferantenportal (Route `?p=lieferant_hilfe`)

## Wozu
Ein neuer Lieferant soll ohne Rueckfrage loslegen koennen. Die Seite geht genau das Menue von oben nach unten durch: Anmelden, Uebersicht, Anfragen beantworten, Bestellungen, Mein Katalog, Dateien, Rueckfragen, Meine Daten, App aufs Handy.

## Sprachen
**Deutsch, English, 中文** – vollstaendig, nicht nur die Ueberschriften. Die Texte stehen direkt in der Datei (Array `$T`), nicht in `lp_t()`, weil sie nur hier vorkommen und sonst das Woerterbuch aufblaehen wuerden. Gewaehlt wird die Sprache wie ueberall im Portal ueber `lp_sprache()`.

## Aufbau
Je Abschnitt: Ueberschrift, ein Satz Einleitung, danach die Schritte als Liste. Nummeriert, damit man am Telefon sagen kann „Punkt 3".

## Pflegen
Aendert sich etwas am Portal, gehoert es hier in **allen drei** Sprachen nachgezogen. Fehlt eine Sprache, faellt die Seite auf Deutsch zurueck – das faellt niemandem auf und ist deshalb gefaehrlich.
