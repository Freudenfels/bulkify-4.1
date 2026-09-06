# lesen.php – E-Mail einlesen

Route `?p=mail`. Drei Schritte, bewusst nicht einer:

1. **Einfügen** und auf *Auslesen* drücken. Gespeichert wird noch nichts.
2. **Vorschau:** Was für eine Mail ist das, wer schreibt, was will er – und darunter, was passieren
   würde: an wen die Notiz geht, wie sie lautet, wann erinnert wird. Alle drei sind änderbar.
3. **Übernehmen** – ein Klick, dann steht die Notiz am Kunden oder Kontakt und die Wiedervorlage
   in der Liste.

Das Ergebnis liegt zwischen den Schritten in der Sitzung, nicht in der Datenbank – *Übernehmen*
fragt die KI nicht noch einmal.

## Auswahlfeld „Gehört zu"
Vorbelegt mit dem besten Treffer, samt Begründung („gleiche E-Mail", „gleiche Firma"). Daneben immer
wählbar: **neuer Kontakt** oder **nichts anlegen**. Bei Newsletter und Rechnungen steht von vornherein
*nichts anlegen*.

## Ohne KI
Ohne Schlüssel ist der Knopf ausgegraut und ein Hinweis sagt warum. Lokal ist das der Normalfall –
die Seite lässt sich also erst auf dem Server wirklich benutzen.
