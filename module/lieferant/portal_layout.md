# lieferant/portal_layout.php – Rahmen des Lieferantenportals

## Wozu
Kopf, Menü, Fuß und die **Übersetzung** für alle Portalseiten. Zwei Sprachen: Deutsch und Englisch. Welche gilt, steht am Lieferanten (`lieferanten.sprache`) – alles außer `de` läuft auf Englisch, weil die meisten Lieferanten im Ausland sitzen.

## Funktionen
- `lp_t($key, $sprache='')` – Textbaustein. Fehlt ein Schlüssel, kommt der Schlüssel zurück; dann fällt es auf, statt leer zu bleiben.
- `lp_head($titel)` / `lp_foot()` – HTML-Rahmen inkl. Theme-Umschalter.
- `lp_shell_start($aktiv)` / `lp_shell_ende()` – Seitenleiste mit Übersicht · Bestellungen · Anfragen · Meine Daten · Abmelden.
- `lp_sprache()` / `lp_sprache_setzen()` / `lp_sprachwahl()` – der Umschalter **Deutsch | English**. Die Wahl liegt in der Session und schlägt die Stammdaten; das ist wichtig für Login und Einladung, wo noch niemand angemeldet ist. `?lang=de|en` gilt auf jeder Portalseite und wird beim Einbinden ausgewertet, damit auch der Seitentitel sofort stimmt.
- Die Markenleiste zeigt das **bulkify-Logo** (`assets/bulkify-logo-white.png`), nicht mehr den Schriftzug – wie im internen Bereich.
- **Drei Sprachen: Deutsch, English, 中文.** Die chinesischen Texte für Login, Einladung und Bestellablauf sind aus v3 übernommen, die neuen Bausteine (Firmendaten, Logo, Staffeln) sind neu übersetzt und gehören von jemandem gegengelesen, der Chinesisch spricht.
- **Grenze:** Die PDFs (Bestellung, Angebot, Spezifikation, CoA) bleiben deutsch/englisch. Der PDF-Baukasten bringt nur westliche Schriften mit; für chinesische Zeichen müsste erst eine CJK-Schrift eingebettet werden.

## Verwandt
`core/auth.php` (`ist_lieferant()`, `aktueller_lieferant()`, `lieferant_sprache()`).

## Menü: Rückfragen und Dateien
Das Menü hat zwei weitere Punkte: **Rückfragen** (`lieferant_nachrichten`, mit Zahl ungelesener Nachrichten von bulkify) und **Dateien** (`lieferant_dateien`). Dafür lädt das Layout `core/nachricht.php`.
