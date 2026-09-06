# ki.php – Anbindung an die Anthropic-API

## Wozu
**Unveränderte Kopie aus dem bulkify Dashboard.** Bewusst nicht neu geschrieben: Die Anbindung ist dort erprobt, und beide Programme sollen sich gleich verhalten. Ruft die API direkt über curl auf – ohne Composer.

## Schlüssel
Steht in der `secrets.php` **dieses** Projekts als `ANTHROPIC_API_KEY` (oder als Umgebungsvariable). Das CRM greift **nicht** auf die secrets.php des Dashboards zu – beide sind eigenständig.

Lokal ist kein Schlüssel hinterlegt, `ki_bereit()` liefert dort also `false`. Die Oberfläche blendet KI-Knöpfe dann einfach aus; nichts bricht.

## Zeitbudget
Jeder Aufruf hat eine Obergrenze (Standard 300 s, Wiederholungen eingerechnet). Ohne die könnte eine hängende Anfrage einen PHP-Arbeiter minutenlang belegen – auf einem kleinen Server steht dann alles.

## Log
Jede Anfrage landet in `data/ki.log` (Modell, Dauer, Tokens, gekürzte Antwort). Ohne Schlüssel, versteht sich.
