# kontakt_ki.php – aus einer Nachricht oder einer Karte einen Kontakt machen

## Wozu
Zwei Wege, dasselbe Ziel: statt sechs Felder zu tippen, liest die KI heraus, wer sich meldet und was er will.

- **Text** (`kontakt_ki_lesen`): eine geteilte WhatsApp-Nachricht oder eine getippte Notiz.
- **Bild** (`kontakt_ki_bild`): eine abfotografierte **Visitenkarte** oder ein Foto einer Nachricht. Auf der Messe der schnellste Weg.

Herausgelesen werden Name, Firma, E-Mail, Telefon, worum es geht, ein grober Wert und – das Wichtigste – **in wie vielen Tagen nachgefasst werden soll**. Steht in der Nachricht „bis Freitag“, rechnet sie das in Tage um.

## Die eine Regel
**Gespeichert wird nichts automatisch.** Die KI füllt nur das Formular; du prüfst und drückst auf Speichern. Bei einer falsch verstandenen Nachricht stünde sonst Unsinn in den Daten, und niemand würde es merken.

Selbst Getipptes wird nie überschrieben, außer das Feld war leer (`erfassen_uebernehmen`).

## Was mit dem Bild passiert
Es wird kurz in `data/` abgelegt, ausgelesen und **sofort wieder gelöscht**. Es geht um die Daten auf der Karte, nicht um das Bild.

## Warum das schnelle Modell
Eine kurze Nachricht auseinanderzunehmen ist keine schwere Aufgabe. Deshalb `KI_MODELL_SCHNELL` mit knappem Zeitbudget – das hält die Sache billig und schnell.

## Was sie nicht darf
Nichts erfinden. Steht kein Name da, bleibt das Feld leer und die Seite sagt es. Lieber ein leeres Feld als ein erfundener Ansprechpartner.
