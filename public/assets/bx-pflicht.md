# bx-pflicht.js – Pflichtfelder

Seit 13.11.2026 (Sofortfix Wareneingang, Teil 0b). Geladen von `bx-ui.js`, sobald eine Seite ein Feld mit `data-pflicht` (oder ein vom Server markiertes Feld `.bx-pflicht-fehlt`) hat.

- `data-pflicht` (leer) = immer Pflicht; `data-pflicht="#weGrund=alt_bestellung"` = nur bei diesem Wert (mehrere mit Komma); `data-pflicht="#weOhne:checked"` = nur bei Haken. Das steuernde Feld muss sichtbar sein.
- Optional `data-pflicht-text="Lieferant fehlt"`, `data-pflicht-zeige="#feld"` (sichtbares Gegenstück eines versteckten Felds). Bei einer Auswahl mit Suche (rs-combo) wird automatisch das Suchfeld markiert.
- Sternchen an der Beschriftung, wechselt sofort mit der Bedingung.
- Vor dem Absenden: fehlt ein (sichtbares) Pflichtfeld, wird nicht gesendet; das Feld wird rot, darunter „… fehlt“, Cursor springt hinein.
- Server-Fehler: Seite mit allen Eingaben zurückgeben und das Feld mit `class="bx-pflicht-fehlt" data-pflicht-msg="…"` ausgeben – der Baustein zeigt die Meldung beim Laden.
- `bxPflichtPruefen(form)` für Skripte ohne normales Absenden.
