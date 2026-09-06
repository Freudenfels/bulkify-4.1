# auth.php – Anmeldung

## Wozu
Es sind **dieselben Zugangsdaten wie im Dashboard**: geprüft wird gegen dessen Tabelle `benutzer` (über `core/erp.php`).

## Eigene Sitzung
Der Sitzungsname ist `BXCRM` (`core/config.php`). Beide Programme laufen auf derselben Domain – ohne eigenen Namen würden sich die Anmeldungen gegenseitig überschreiben. Man meldet sich also zweimal an; dafür kann keins das andere aussperren.

## Was nicht passiert
Beim Anmelden wird **nichts** ins Dashboard geschrieben, auch kein `letzter_login`. Sonst stünde dort ein Login, den es dort nie gab.

Lieferanten-Zugänge (Rolle `lieferant`) werden abgewiesen – das CRM ist für das Team.
