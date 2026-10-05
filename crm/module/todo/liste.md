# liste.php – To-Do-Liste

Route `?p=todos`, Menüpunkt „To-Dos" (mit Badge offener Aufgaben). Zeigt offene bzw. erledigte To-Dos,
**gruppiert nach Kunde/Kontakt** (plus „Ohne Bezug"), filterbar nach Kategorie. Jede Zeile hat ein
Häkchen zum **Abhaken** (bzw. Wieder-Öffnen im Reiter „Erledigt").

Enthält To-Dos (`crm_todo`) **und** offene Wiedervorlagen mit Kontakt/Kunde-Bezug gemeinsam (Kategorie
„Wiedervorlage"). Überfällige Aufgaben (Fälligkeit in der Vergangenheit) sind rot.

Oben eine Schnell-Erfassung für eine eigenständige Aufgabe; To-Dos **am** Kunden/Kontakt legt man direkt
auf dessen Seite an (Karte „To-Dos"). Logik: `crm/core/todo.php`.
