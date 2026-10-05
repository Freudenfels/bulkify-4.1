# detail.php – ein Kunde

Route `?p=kunde&id=…`. Zeigt, was das Dashboard über den Kunden weiß (nur lesend), und was das CRM ergänzt: **Verlauf**, **Wiedervorlage**, **Fragenkatalog**, **KI-Rezepturvorschlag** (Freitext-Idee → Vorschlag, `core/rezeptur_ki.php`, gespeichert in `crm_rezeptur_ki`) und **Antwortvorschlag**.

Dazu eine **To-Do-Karte** (`core/todo.php`): offene Aufgaben des Kunden anlegen/abhaken; sie erscheinen auch in der zentralen To-Do-Liste.

Das ist der Grund, warum es diese Seite gibt: Im Dashboard steht, was bestellt wurde – aber nirgends, was zuletzt besprochen wurde. Genau das steht hier.
