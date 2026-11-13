## Nachbesserung Fable-5, Teil 4 (13.11.2026) – Design
Block am Ende von `app.css`:
- **Zwei Knopfgrößen:** `.btn` 36 px, `.btn-sm` 30 px; am Handy (bis 860 px) Knöpfe, Menüzeilen, Glocke, Burger, Menü-Fuß mindestens 44 px. PDF-Knopf (`.pdfbtn`) gleich hoch.
- **Kacheln am Handy:** `.bx-cards` mit Kacheln als Raster 2 je Zeile über die volle Breite; Panels in `.bx-cards` volle Breite.
- **Dunkler Modus:** Badges mit dunklem Grund und farbiger Schrift (ok/warn/err/info), hellere Status-Farben, fest eingetragene Hinweisfarben (#8f231b, #b7791f …) werden aufgehellt, Geister-Knöpfe und Stornieren lesbar, Autofill-Farbe überschrieben. Hell: Orange #f59e0b wird dunkler (#b45309).
- **Handy ohne Seiten-Scroll:** Tabellen außerhalb von `.bx-tablewrap` bekommen am Handy einen eigenen Scroll-Rahmen; Reiter-Leisten scrollen seitlich; lange Knopftexte brechen um; `pre` nie breiter als das Feld.
- **Karten je Zeile:** `table.bx-karten-handy` am Handy als Karten (Beschriftung aus `data-label`, gesetzt von `bx-datei.js`).
- **Such-/Filterleisten am Handy:** Suchfeld und Auswahl volle Breite in eigener Zeile.
- **Datei-Knopf:** `.bx-datei` (von `bx-datei.js`) und `::file-selector-button` im bulkify-Stil.
- **Marken** `.bx-mark-ja` / `.bx-mark-nein` (Rohstoffliste), `.bx-zusatz` (Zusatzzeile unter Mengen), `.bx-sym` (SVG-Symbole).

## Etikett-Pop-up (13.11.2026)
Block `.bx-et-*` am Ende: Schrittanzeige, Ablagefläche, Vorschau mit Zoom, Legende, Ampel-Zeilen (SVG-Symbole, grün/gelb/rot/info), KI-Hinweis, Freigabe-Haken, lime Hauptknopf, Danke-Haken, Auswahl-Liste; am Handy einspaltig, Vorschau oben.
