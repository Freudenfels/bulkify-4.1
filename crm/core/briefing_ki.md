# briefing_ki.php – Tagesbriefing

## Wozu
Die Liste sagt, **was** offen ist. Das Briefing sagt, **womit man anfängt** – und das ist bei zwanzig Zeilen die eigentliche Frage. Drei bis vier Sätze über der Liste: wie die Lage ist, was am dringendsten ist, womit anzufangen wäre.

## Die Bremse
Gerechnet wird höchstens **einmal je Tag und Listenstand**. Der Stand ist ein Abdruck aus Datum plus allen offenen Zeilen mit ihrem Alter; ändert sich eine Zeile, ist das Briefing veraltet und wird neu geschrieben.

Ohne diese Bremse liefe bei jedem Seitenaufruf eine KI-Anfrage. Das wäre teuer und langsam – und der Text würde sich bei jedem Neuladen ändern, was den Eindruck macht, das Programm sei sich nicht sicher.

Über **neu schreiben** lässt sich eine neue Fassung erzwingen. Gespeichert wird beides in `crm_meta`.
