# led/klingeln.php – Leiste klingeln lassen per fetch (`?p=klingeln`)

Nimmt nur POST an, mit `leiste_id`, `farbe`, `sek` und `aktion` (`an` oder `aus`). Antwort immer JSON `{ok, meldung}`. Das Gegenstück fürs Chaos-Modell, so wie `leuchten.php` fürs feste Platz-Modell. Aufgerufen von `public/lager/assets/lager.js` (`data-klingeln`).
