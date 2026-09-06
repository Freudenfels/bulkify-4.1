# index.php – der einzige Einstieg

Front Controller mit Whitelist (`?p=…`). Was nicht in der Liste steht, lässt sich nicht aufrufen – ein direkter Zugriff auf Dateien in `core/` oder `module/` ist damit ausgeschlossen.

## Die Seiten
| Route | Was |
|---|---|
| `wartet` | Startseite: wer wartet auf mich |
| `kontakte`, `kontakt` | Leute ohne Kundenkonto |
| `erfassen` | schnell erfassen, auch Ziel des Teilen-Menüs |
| `termine` | Rückruf, Messe, Besuch |
| `kunden`, `kunde` | Kunden des Dashboards mit Verlauf |
| `mehr` | Einstellungen, dunkler Modus, Abmelden |

Vor jeder Seite laufen zwei Dinge: `crm_schema()` (eigene Tabellen sicherstellen) und die Prüfung, ob jemand angemeldet ist.

`?p=autologin&token=…` gibt es nur auf dem eigenen Rechner (`ist_lokal()`) – zum Entwickeln. Auf dem Server führt der Aufruf zur Anmeldemaske.
