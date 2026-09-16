# fragenkatalog_ki.php – Fragenkatalog fürs Erstgespräch

## Wozu
**Übernommen aus dem v3-CRM.** Aus einer eingegangenen Kundenanfrage entsteht die Vorbereitung für
den Erstkontakt – sechs Blöcke:

1. **Kurzbriefing für den Mitarbeiter** – was ist das Produkt, wer kauft es
2. **Der fachliche Knackpunkt** – mit gerechneten Zahlen (Tagesdosis, Kapseln/Tag, Chargengröße)
3. **Fragen an den Kunden** – gruppiert, Angebotsblocker markiert
4. **Das müssen wir dem Kunden aktiv sagen** – nicht fragen, sagen
5. **Hausaufgaben Kunde**
6. **Hausaufgaben bulkify**

Es ist **kein Text für den Kunden**, sondern die Vorbereitung für den Kollegen, der gleich anruft.
Für eine kurze Nachricht an den Kunden gibt es `antwort_ki.php` – zwei verschiedene Werkzeuge.

## Das Wissen steckt in der Prompt-Datei
`crm/prompts/fragenkatalog.md` (259 Zeilen, aus v3 übernommen). Dort stehen die Regeln, die das
Werkzeug gut machen: **kein Preis im Erstgespräch**, nicht nach vegan/bio/halal fragen (treibt den
Preis und bringt Themen ins Spiel, die der Kunde nicht auf dem Schirm hatte), keine Fragen stellen,
die der Kunde nicht beantworten kann.

**Die Datei ist von Hand pflegbar.** Wer eine Regel ändern will, ändert dort einen Satz und muss
keinen Code anfassen. Genau so war es in v3, und das ist der Grund, warum das Werkzeug taugt.

## Warum das grosse Modell
Hier wird gerechnet und der Knackpunkt gesucht – das ist die Arbeit eines erfahrenen Kollegen, keine
Formatierungsaufgabe. Deshalb `KI_MODELL` mit Nachdenken und hohem Aufwand, Zeitbudget 300 s.
Dauert etwa eine Minute; der Knopf zeigt so lange einen Spinner.

## Gespeichert wird in `crm_briefing`
Eigene Tabelle statt einer Spalte am Kontakt: So hängt derselbe Katalog wahlweise an einem **Kontakt**
oder an einem **Kunden** des Dashboards – und an dessen Tabellen fassen wir nichts an. Je Bezug genau
einer; „Neu erstellen" überschreibt.
