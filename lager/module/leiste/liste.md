# leiste/liste.php – Blinker-Übersicht (`?p=leisten`)

Alle Blinker im Umlauf mit Status (frei oder belegt) und der Charge, an der sie hängen. Live-Suche über Blinker, Rohstoff und Charge. Bei belegten Blinker gibt es die Knöpfe Finden und Aus.

**Blinker testen:** oben ein Feld, in das man einen Blinker-Code eingibt/scannt → der Blinker leuchtet kurz grün (Knöpfe „Leuchten lassen" / „Aus"). Funktioniert auch für noch nicht registrierte Codes (werden via `leiste_sicherstellen` aufgenommen). AJAX-Aktion `testcode` (POST `?p=leisten`), normalisiert den Code mit `led_leiste_normalisieren` und löst über `leiste_finden`/`leiste_aus` aus.
