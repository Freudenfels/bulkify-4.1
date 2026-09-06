# layout.php – der Rahmen jeder Seite

## Wozu
Handy zuerst: oben eine schmale Kopfleiste, unten eine Leiste mit den vier Bereichen – so, wie man eine App bedient. Ab 861 px wandert dieselbe Navigation nach oben in die Kopfleiste, die untere verschwindet.

## Bausteine
- `kopf($titel, $aktiv)` – öffnet die Seite, setzt Manifest und Theme-Farbe (App auf dem Startbildschirm).
- `fuss($aktiv)` – untere Leiste und Service Worker.
- `seitenkopf($titel, $unter, $aktion)` – Überschrift mit optionalem Knopf rechts.
- `hinweis($text, $art)` – grüner oder roter Kasten.

Die Zeichen in der unteren Leiste sind schlichte SVG-Linien, **keine Emojis**.
