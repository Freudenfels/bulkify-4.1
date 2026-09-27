# led/leuchten.php – Leuchten per fetch (`?p=leuchten`)

Nimmt nur POST an, mit `platz_id`, `farbe`, `sek`, `piep` und `aktion` (`an` oder `aus`). Die Antwort ist immer JSON: `{ok, meldung}`. Aufgerufen wird es von `public/lager/assets/lager.js`.
