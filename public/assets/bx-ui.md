# public/assets/bx-ui.js – Oberflächen-Standard (Regel Nico 13.11.2026)

Ein gemeinsamer Baustein für alle Programme (Dashboard, Kunden- und Lieferantenportal, Lager, Produktion, CRM, Buchhaltung). Jedes Layout bindet die Datei im Kopf ein (`defer`).

1. **Pop-up:** Jedes `<dialog class="bx-dialog">` – auch nachträglich eingefügte – bekommt automatisch:
   - Kopf mit Titel (erste Überschrift bzw. `data-titel`) und Schließen-X, bleibt beim Scrollen oben.
   - Knopfzeile (`.bx-pop-fuss`, sonst die letzte Zeile mit Absenden-Knopf eines Formulars), bleibt unten, Hauptknopf rechts.
   - Escape und Klick neben das Pop-up schließen es; mit ungespeicherten Eingaben erst nach Rückfrage.
   - `form[data-pop-ajax]` speichert per fetch ohne Seitenwechsel; danach werden die Elemente aus `data-pop-refresh` (CSS-Selektoren) aus der neuen Seite ersetzt, sonst lädt die Seite neu.
   - `bxPopup.open(id)`, `bxPopup.close(id)`, `bxPopup.url(adresse, titel, refresh)` (zeigt `[data-pop-inhalt]` bzw. `.bx-main` der Seite im Pop-up; ihre POST-Formulare speichern ohne Seitenwechsel). Deklarativ: `<a data-pop-url="…" data-pop-titel="…" data-pop-refresh="#zeile12">`.
   - Ein als `<dialog open>` ausgeliefertes Pop-up wird modal geöffnet (Hintergrund abgedunkelt).
2. **Auswahl mit Suche:** lädt `bx-rscombo.js` nach, wo es noch fehlt (Portale, Sub-Apps). Das Dashboard bindet es über `bx_rscombo_script()` ein.
3. **Lauftext:** Elemente mit `text-overflow: ellipsis` (z. B. `.bx-lauf`), deren Text nicht passt, laufen beim Darüberfahren (Handy: Antippen) langsam durch (~45 px/s).

`public/assets/bx-rscombo.js` – die durchsuchbare Auswahl (vorher Inline-Skript in `core/layout.php`): jedes `<select>` mit mehr als 10 Einträgen (oder `class="rscombo"`) wird ein Suchfeld mit Trefferliste; `data-such` an der Option = zusätzliche Suchbegriffe (Synonyme, Nummern); Pfeiltasten + Enter; Ausnahme `data-norscombo`.

**Gewichte (13.11.2026):** `start()` lädt `bx-gewicht.js` nach (Rückfrage bei „1.250“ in Feldern mit `data-gewicht`).
