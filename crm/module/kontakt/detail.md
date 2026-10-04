# detail.php – ein Kontakt

Route `?p=kontakt&id=…`. Von oben nach unten: offene Wiedervorlagen, eine Notiz hinzufügen, **Antwort vorschlagen**, der Verlauf, die Stammdaten, und der Knopf **Zum Kunden machen**.

Die Reihenfolge ist Absicht: Beim Öffnen will man zuerst wissen, was zuletzt war und was ansteht – nicht die Adresse pflegen.

**KI-Auswertung der Anfrage** (`tun=ki_auswerten` → `core/lead_ki.php`) ordnet die Anfrage aus der Notiz ein: Zusammenfassung, Produktform, Menge, grober Wert, nächster Schritt. Setzt den geschätzten Wert (falls leer) und eine Wiedervorlage, Ergebnis landet im Verlauf. Website-Leads werden beim Eingang automatisch ausgewertet; der Knopf dient zum Wiederholen oder für Kontakte, die ohne KI hereinkamen. Nur sichtbar, wenn die KI eingerichtet ist und eine Notiz vorhanden ist.

**Antwort vorschlagen** erzeugt über `core/antwort_ki.php` einen Entwurf aus Notiz und Verlauf. Er steht in der Sitzung, nicht in der Datenbank; verschickt wird nichts. Wer ihn wirklich abgeschickt hat, drückt auf „Als gesendet im Verlauf vermerken“.

**Zum Kunden machen** ist die einzige Stelle im ganzen CRM, die ins Dashboard schreibt. Deshalb steht eine Rückfrage davor.
