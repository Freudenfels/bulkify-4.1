# detail.php – ein Kontakt

Route `?p=kontakt&id=…`. Von oben nach unten: offene Wiedervorlagen, eine Notiz hinzufügen, **Antwort vorschlagen**, der Verlauf, die Stammdaten, und der Knopf **Zum Kunden machen**.

Die Reihenfolge ist Absicht: Beim Öffnen will man zuerst wissen, was zuletzt war und was ansteht – nicht die Adresse pflegen.

**Antwort vorschlagen** erzeugt über `core/antwort_ki.php` einen Entwurf aus Notiz und Verlauf. Er steht in der Sitzung, nicht in der Datenbank; verschickt wird nichts. Wer ihn wirklich abgeschickt hat, drückt auf „Als gesendet im Verlauf vermerken“.

**Zum Kunden machen** ist die einzige Stelle im ganzen CRM, die ins Dashboard schreibt. Deshalb steht eine Rückfrage davor.
