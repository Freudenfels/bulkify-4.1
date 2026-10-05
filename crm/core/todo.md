# crm/core/todo.php – To-Dos

**Zweck:** Abhakbare Aufgaben, nach Kunde/Kontakt und Kategorie sortiert.

**Eine Liste, zwei Quellen:** die neuen strukturierten Aufgaben (`crm_todo`) **und** die offenen
**Wiedervorlagen mit Bezug auf einen Kontakt/Kunden** (`crm_wiedervorlage`). So geht die Wiedervorlage
in den To-Dos auf, ohne die zentrale „Wer wartet"-Engine (`wartet.php`) oder den Kalender umzubauen –
die Wiedervorlagen laufen dort unverändert weiter und erscheinen hier zusätzlich. Beide sind abhakbar.

## Funktionen
- `todo_anlegen($d, $uid)` – neue Aufgabe (Titel, Kategorie, optional Fälligkeit, optional Bezug kontakt/kunde, quelle manuell|ki|mail).
- `todo_erledigen($quelle, $id, $uid, $zurueck=false)` – abhaken/wieder öffnen. `$quelle` = `todo` oder `wv`.
- `todo_fuer_bezug($typ, $id)` – offene To-Dos (nur `crm_todo`) eines Vorgangs, für die Kontakt-/Kundenseite.
- `todo_zahl_offen()` – Zahl offener Aufgaben (To-Dos + Kontakt/Kunde-Wiedervorlagen), fürs Menü-Badge.
- `todo_liste($modus, $kat)` – vereinte Liste (offen/erledigt, optional Kategorie `…|wiedervorlage`), nach
  Fälligkeit sortiert, je Zeile mit aufgelöstem Bezug-Namen und -Link.

Kategorien stehen in `crm_todo_kategorien()` (`ui.php`); die KI legt beim Auslesen einer Anfrage To-Dos
an (`lead_ki.php`, nur bei der ersten Auswertung). Seite: `crm/module/todo/liste.php` (`?p=todos`).
