# public/lager/bruecke.php – Schnittstelle für die Brücke

- `GET ?token=...` liefert offene Leuchtbefehle als fertige URLs: `{"befehle":[{"id":..,"url":"http://ip/light?code=..."}]}`. Jeder Befehl wird dabei als `abgeholt` markiert. Befehle, die älter als 30 Sekunden sind, werden verworfen.
- `POST ?token=...` mit `id`, `ok` und `antwort` meldet das Ergebnis zurück.

Jeder Aufruf gilt als Lebenszeichen der Brücke. Geschützt ist das Ganze durch den Schlüssel in `lg_meta.bruecke_token`.
