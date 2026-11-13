# core/einmal.php – Einmal-Kennung gegen doppelt abgeschickte Formulare

Eingeführt mit Fable-6 P15/T2.6 am 13.11.2026. Vorher führte ein doppelt abgeschicktes Formular zu einer doppelten Buchung, zum Beispiel:
- zwei Chargen gleicher Nummer im Wareneingang
- eine doppelte Teilmenge in der Werk-App
- zwei Bestell-Mails

## So funktioniert es
- **Browser (`public/assets/bx-ui.js`):** Jedes normal abgeschickte POST-Formular bekommt beim Absenden das versteckte Feld `_einmal` mit einer zufälligen Kennung. Der Knopf wird nach dem ersten Klick gesperrt; nach 10 Sekunden ist er als Rückfall wieder frei, etwa bei einem Download ohne Seitenwechsel. Nicht betroffen sind:
  - abgebrochene Formulare (confirm, Pflichtfelder)
  - Formulare, die ein Skript per fetch sendet
  - `target="_blank"`
  - Formulare mit `data-kein-einmal`
- **Server (`einmal_pruefen()`):** Er läuft in allen Front-Controllern (Dashboard inkl. Portale, Lager, Produktion/Werk-App, Buchhaltung, CRM) vor jeder Verarbeitung. Jede Kennung wird nur einmal angenommen (Tabelle `formular_einmal`, Primärschlüssel `kennung`). Eine zweite Sendung mit derselben Kennung bucht nichts:
  - Seitenaufrufe gehen zurück auf die Seite (`?einmal=doppelt`). bx-ui.js zeigt dann kurz „Schon abgeschickt – wurde nur einmal gebucht.“
  - JSON-Anfragen bekommen HTTP 409.
- Ohne Kennung (alte Skripte, Cron, API) passiert nichts. Hakt die Datenbank, wird nie blockiert.

## Regel
Neue buchende Formulare brauchen nichts extra: Sie bekommen die Kennung automatisch. Wer per JavaScript (fetch) bucht, sperrt den Knopf selbst oder sendet `_einmal` mit.
