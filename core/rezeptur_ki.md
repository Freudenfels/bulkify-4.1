# rezeptur_ki.php – aus einer Kundenidee ein Rezepturvorschlag

## Wozu
Der Kunde schreibt im Portal nur, was er will („etwas für besseren Schlaf, vegan, Kapseln, 2 pro Tag"). Daraus entsteht ein Entwurf mit Zutaten und Mengen, dazu eine **Novel-Food-Einschätzung**, ein Blick auf die **Höchstmengen**, die zulässigen **Health Claims** und die **Machbarkeit**.

**Das ist ein Entwurf für das Team, keine Freigabe.** Gespeichert wird nichts automatisch: die Zutaten landen erst auf Knopfdruck als Wunschzeilen in der Anfrage, und daraus baut das vorhandene Formular die Rezeptur. Die rechtliche Bewertung prüft ein Mensch.

## Ablauf
1. Kunde schickt seine Idee (`?p=portal`, Rezepturanfrage). Ist die KI eingerichtet, wird **sofort** ein Entwurf entwickelt und an der Anfrage gemerkt (`rezeptur_anfrage.ki_daten`).
2. Das Team öffnet die Anfrage (`?p=anfrage&id=`) und sieht den Vorschlag oben. Über **Vorschlag entwickeln** / **Neu entwickeln** lässt er sich jederzeit (neu) erzeugen.
3. **Zutaten in die Wunschzeilen übernehmen** schreibt sie nach `rezeptur_anfrage_wunsch` – mit Zuordnung zu unserem Rohstoff, wo der Name passt.
4. Team prüft, ergänzt fehlende Rohstoffe, speichert und sendet den Vorschlag wie bisher (`aktion=rezeptur_erstellen`).

## Funktionen
- `rezeptur_ki_katalog()` – unsere Rohstoffe als Liste für die KI. Sie soll bevorzugt vorschlagen, was wir einkaufen können.
- `rezeptur_ki_anweisung($form, $katalog)` – der Auftrag: Novel Food nach VO 2015/2283, Höchstmengen nach BfR/NRV, Claims nur aus der EU-Liste (VO 432/2012), im Zweifel „prüfen" statt raten.
- `rezeptur_ki_entwickeln($anfrage_id)` – entwickelt den Vorschlag; nutzt Idee, Produktname und die vom Kunden genannten Wunschzutaten.
- `rezeptur_ki_item_finden($bezeichnung)` – Zuordnung zu unserem Rohstoff (exakt, dann enthalten).
- `rezeptur_ki_merken()` / `rezeptur_ki_vorschlag()` – Entwurf an der Anfrage.
- `rezeptur_ki_zeilen_uebernehmen()` – Zutaten als Wunschzeilen schreiben (ersetzt die bisherigen).

## Was die KI NICHT entscheidet
Das **Füllgewicht** rechnen wir selbst nach und vergleichen es mit unseren Kapselgrößen (`kapselgroesse`) – das ist eine Tatsache, keine Meinung. Steht im Vorschlag „passt in Größe 2", kommt diese Zeile aus unserer eigenen Rechnung.
Damit sich nichts widerspricht, ist der KI ausdrücklich verboten, in der Machbarkeit selbst eine Kapselgröße zu nennen; Hinweise zur Schüttdichte darf sie geben.

## Grenzen
Novel-Food- und Höchstmengen-Bewertung sind eine **Einschätzung**. Sie ersetzt keine rechtliche Prüfung und keine Rücksprache mit der Behörde. Deshalb steht an jeder Stelle im Panel, dass es ein Entwurf ist.
