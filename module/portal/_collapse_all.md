# portal/_collapse_all.php – „Alle ein-/ausklappen" (Kundenportal)

Kleiner Include: ein rechtsbündiger Button, der **alle Angebotskarten** (`details.pt-ang`) auf einen Schlag ein- oder ausklappt.

- Ist mindestens eine Karte offen → Label **„Alle einklappen"** (Klick schließt alle). Sind alle zu → **„Alle ausklappen"** (Klick öffnet alle).
- Das Label zieht mit, wenn einzelne Karten auf-/zugeklappt werden (Listener im Capture, weil das `toggle`-Event nicht bubbelt).
- Zielt nur auf `details.pt-ang` (die Angebotskarten selbst), nicht auf verschachtelte `<details>` wie „Rezeptur ansehen" oder „Ablehnen".
- Der Balken blendet sich aus (`hidden`), wenn keine Karten auf der Seite sind.

**Eingebunden in** `module/portal/kunde.php` in den Ansichten **Angebote** (`?p=portal&v=angebote`) und **Meine Anfragen** (`v=meine_anfragen`), jeweils oberhalb der Kartenliste. Die Karten selbst kommen aus `_angebot_karte.php` (dort `<details class="bx-panel pt-ang">`).
