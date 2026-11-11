# rohstoff_steckbrief.php - Steckbrief erstellen oder bearbeiten

Route `?p=rohstoff_steckbrief`, nimmt nur POST an. Erlaubte Rollen: production, einkauf, labor, sales, finance und admin. Sie kommt nie im Portal vor.

- `aktion=erstellen`: Die KI erzeugt den Steckbrief (`rohstoff_steckbrief_erzeugen()`).
- `aktion=speichern`: Die eigene Bearbeitung aus dem Stift-Pop-up wird gespeichert.

Danach geht es zurück auf die Seite aus `back`, zum Beispiel Rohstoffseite, Kundenanfrage oder Lieferanten-Anfrage. Erlaubt sind nur interne Ziele, die mit `?p=` beginnen. Angehängt wird eine Meldung (`sb`, `sbok` bzw. `sberr`). Siehe `core/rohstoff_steckbrief.md`.
