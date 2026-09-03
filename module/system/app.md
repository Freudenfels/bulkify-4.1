# app.php – „bulkify aufs Handy" (Route `?p=app`)

## Wozu
Eine Seite, die Schritt fuer Schritt zeigt, wie man bulkify auf den Startbildschirm legt – Android und iPhone getrennt, weil der Weg unterschiedlich ist. Steht im Menue unter **System**, ist fuer **jede Rolle** freigegeben.

## Besonderheiten
- Bietet Chrome die Installation gerade selbst an (`beforeinstallprompt`), erscheint oben ein Knopf **Auf dem Startbildschirm ablegen** – ein Klick statt Menue suchen.
- Laeuft die Seite bereits als installierte App, verschwindet dieser Kasten (`pwa_hinweis_script()`, Merkmal `data-nur-browser`).
- Laeuft die Adresse ohne HTTPS, steht ein ehrlicher Hinweis da: Installieren geht dann nicht.
- Der Abschnitt fuer Lieferanten nennt die Adresse und verlinkt deren Anleitung (`?p=lieferant_hilfe`).

Technik dahinter: `core/pwa.md`.
