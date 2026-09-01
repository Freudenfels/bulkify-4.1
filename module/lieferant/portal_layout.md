# lieferant/portal_layout.php – Rahmen des Lieferantenportals

## Wozu
Kopf, Menü, Fuß und die **Übersetzung** für alle Portalseiten. Zwei Sprachen: Deutsch und Englisch. Welche gilt, steht am Lieferanten (`lieferanten.sprache`) – alles außer `de` läuft auf Englisch, weil die meisten Lieferanten im Ausland sitzen.

## Funktionen
- `lp_t($key, $sprache='')` – Textbaustein. Fehlt ein Schlüssel, kommt der Schlüssel zurück; dann fällt es auf, statt leer zu bleiben.
- `lp_head($titel)` / `lp_foot()` – HTML-Rahmen inkl. Theme-Umschalter.
- `lp_shell_start($aktiv)` / `lp_shell_ende()` – Seitenleiste mit Übersicht · Bestellungen · Anfragen · Meine Daten · Abmelden.

## Verwandt
`core/auth.php` (`ist_lieferant()`, `aktueller_lieferant()`, `lieferant_sprache()`).
