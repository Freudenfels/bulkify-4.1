# system/test_abzug.php – Datenstand von live holen (`?p=test_abzug`)

Nur Admin, nur in der Testumgebung (Link im roten Band). Ein Knopf „Abzug starten“: kopiert erst fehlende Dateien,
dann Tabelle für Tabelle (per fetch, kein Zeitlimit). Was in der Testumgebung geändert wurde, ist danach weg.
Auf live zeigt die Seite nur, dass es sie dort nicht gibt. Logik in `core/test_abzug.php`.
