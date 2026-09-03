# lieferant/portal_layout.php – Rahmen des Lieferantenportals

## Wozu
Kopf, Menü, Fuß und die **Übersetzung** für alle Portalseiten. Zwei Sprachen: Deutsch und Englisch. Welche gilt, steht am Lieferanten (`lieferanten.sprache`) – alles außer `de` läuft auf Englisch, weil die meisten Lieferanten im Ausland sitzen.

## Funktionen
- `lp_t($key, $sprache='')` – Textbaustein. Fehlt ein Schlüssel, kommt der Schlüssel zurück; dann fällt es auf, statt leer zu bleiben.
- `lp_head($titel)` / `lp_foot()` – HTML-Rahmen inkl. Theme-Umschalter.
- `lp_shell_start($aktiv)` / `lp_shell_ende()` – Seitenleiste mit Übersicht · Bestellungen · Anfragen · Meine Daten · Abmelden.
- `lp_sprache()` / `lp_sprache_setzen()` / `lp_sprachwahl()` – der Umschalter **Deutsch | English | 中文**. `?lang=de|en|zh` gilt auf jeder Portalseite und wird beim Einbinden ausgewertet, damit auch der Seitentitel sofort stimmt. Der Umschalter darf umbrechen, sonst fiele die dritte Sprache aus der schmalen Seitenleiste heraus.
  **Wessen Wahl gilt:** Eine Sprachwahl wird zusammen mit dem Benutzer gemerkt (`lp_lang`, `lp_lang_uid`). Vor dem Login ist das der anonyme Besucher – meldet er sich an, zählt zuerst die am Lieferanten hinterlegte Sprache (`lieferanten.sprache`). Sonst bliebe das Portal in der Sprache hängen, die jemand auf der Anmeldeseite angeklickt hat. Schaltet der angemeldete Lieferant selbst um, bleibt seine Wahl.
- Die Markenleiste zeigt das **bulkify-Logo** (`assets/bulkify-logo-white.png`), nicht mehr den Schriftzug – wie im internen Bereich.
- **Drei Sprachen: Deutsch, English, 中文.** Die chinesischen Texte für Login, Einladung und Bestellablauf sind aus v3 übernommen, die neuen Bausteine (Firmendaten, Logo, Staffeln) sind neu übersetzt und gehören von jemandem gegengelesen, der Chinesisch spricht.
- **Grenze:** Die PDFs (Bestellung, Angebot, Spezifikation, CoA) bleiben deutsch/englisch. Der PDF-Baukasten bringt nur westliche Schriften mit; für chinesische Zeichen müsste erst eine CJK-Schrift eingebettet werden.

## Verwandt
`core/auth.php` (`ist_lieferant()`, `aktueller_lieferant()`, `lieferant_sprache()`).

## Menü: Rückfragen und Dateien
Das Menü hat zwei weitere Punkte: **Rückfragen** (`lieferant_nachrichten`, mit Zahl ungelesener Nachrichten von bulkify) und **Dateien** (`lieferant_dateien`). Dafür lädt das Layout `core/nachricht.php`.
## Zahlen und Einheiten
`lp_num($wert, $dez)` schreibt Zahlen in der Schreibweise des Lesers (Deutsch 1.234,5 – English und Chinesisch 1,234.5). `lp_einheit($e)` übersetzt die Einheiten, die als deutsches Stammdatum in der Datenbank stehen (Stück, Kapsel, Tablette, Softgel, Stick, Packung, Liter); kg, g, L und ml bleiben, wie sie sind.
## Sprachumschalter unten
Die Sprache stellt man einmal ein, deshalb steht der Umschalter klein (11px, Kürzel **DE · EN · 中文**) ganz unten in der Seitenleiste, unter Abmelden und dem Farbmodus. Die Links setzen `display:inline;padding:0`, sonst würde die Menü-Regel `.bx-side nav a` sie breit machen und der Umschalter bräche um.
## Menüpunkt Mein Katalog
`lieferant_katalog` – der Lieferant pflegt dort, was er anbietet. Siehe `core/lieferant_katalog.md`.

## Zähler-Badges im Menü
`lp_shell_start()` zeigt am Menüpunkt **Anfragen** einen runden Zähler (Kreis mit Zahl), wenn offene Preisanfragen vorliegen (`lieferant_anfrage.status='offen'`), und an **Rückfragen** die Zahl ungelesener Nachrichten. Der Badge ist inline gestylt (lime, `border-radius:999px`, rechtsbündig), damit er ohne zusätzliches Portal-CSS funktioniert.
