# bx-datei.js – Datei-Knopf und Karten am Handy (13.11.2026)

Teil des Oberflächen-Standards (Nachbesserung Fable-5, Teil 4). Wird automatisch von `bx-ui.js` geladen.

1. **Datei-Knopf im bulkify-Stil:** Jedes `<input type="file">` bekommt einen Knopf „Datei wählen“ (bei `multiple`
   „Dateien wählen“) und daneben den Namen der gewählten Datei. Das echte Feld bleibt im Formular (unsichtbar), damit
   `required`, `accept`, `multiple` und die Pflichtfelder (`data-pflicht`) weiter gelten. Ausnahme: `data-roh="1"` am Feld.
2. **Karten je Zeile am Handy:** Tabellen mit der Klasse `bx-karten-handy` werden am Handy (bis 860 px) als Karten gezeigt
   (CSS in `app.css`). Das Skript schreibt die Spaltenüberschrift als `data-label` an jede Zelle.

Läuft auch auf später eingefügten Inhalten (Pop-ups) über einen MutationObserver.
