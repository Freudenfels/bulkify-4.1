# config.php – Grundeinstellungen

## Zugangsdaten: eine Stelle, nicht zwei
Das CRM arbeitet mit **derselben Datenbank** wie das Dashboard und darf **denselben Anthropic-Schlüssel** benutzen. Beides zweimal zu pflegen wäre unnötig – zwei Kopien laufen früher oder später auseinander, und dann sucht man den Fehler an der falschen Stelle.

Gesucht wird in dieser Reihenfolge:
1. `secrets.php` in **diesem** Projekt. Sie darf auch nur eine Zeile enthalten: `require /pfad/zum/dashboard/secrets.php;`
2. Der Pfad aus der Umgebungsvariablen `BULKIFY_SECRETS`.
3. Die üblichen **Nachbarordner** – liegt das Dashboard daneben, findet es sich von selbst.

Erst danach greifen die lokalen Vorgaben (`bulkify41` / `bulkify` / `bulkify`).

Unter **Mehr** steht, welche Datei tatsächlich gezogen wurde und ob die KI eingerichtet ist – ohne je einen Wert zu zeigen.

## Beide Schreibweisen
Das Dashboard setzt seine Werte mit `define()`, ältere Dateien mit Variablen (`$DB_HOST = …`). Hier wird beides angenommen: Nach dem Laden wird aus Variablen eine Konstante gemacht, und gesetzt wird nur, was noch nicht steht.

**Wichtig für spätere Änderungen:** Das `require` steht bewusst im äußersten Bereich der Datei, **nicht in einer Funktion**. Variablen aus der `secrets.php` wären sonst nur innerhalb der Funktion sichtbar, und die Zugangsdaten kämen nie an – ohne jede Fehlermeldung. Genau das ist beim Bauen einmal passiert.

## Die drei Zahlen
| Konstante | Bedeutung |
|---|---|
| `CRM_WARM` | ab wie vielen Tagen eine Zeile gelb wird |
| `CRM_HEISS` | ab wie vielen Tagen sie rot wird |
| `CRM_ANGEBOT_NACHFASSEN` | nach wie vielen Tagen an ein gesendetes Angebot erinnert wird |

## Zeit
`date_default_timezone_set(UTC)` – intern immer UTC, Anzeige über `fmt_zeit()` in Berliner Zeit. Genau wie im Dashboard.