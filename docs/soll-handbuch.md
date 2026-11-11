# bulkify V4 - Soll-Handbuch (Ablauf ab Auftrag)

**Stand:** 08.11.2026 (Schritt 2) · **Verantwortlich:** Nico Thomalla (Entscheidungen), QM: Tabea Albers
**Repo:** github.com/Freudenfels/bulkify-4.1 · live app.bulkify.pro + beta.bulkify.pro

---

## 0. Wozu dieses Handbuch da ist und wie es benutzt wird

Dieses Handbuch ist das **gemeinsame Gedächtnis** für bulkify V4. Es beschreibt, **wie das Programm ab dem Auftrag laufen soll** - in Nicos Worten, mit den Entscheidungen, die in vielen Gesprächen getroffen wurden. Bisher war dieses Wissen über Dutzende Arbeitsanweisungen (MDs) und Rückmeldungen verstreut, und vieles stand nirgends. Das Ergebnis: Funktionen wurden nachträglich dazu-, um- und doppelt gebaut, und manches widerspricht sich.

**Regeln für Claude Code:**
1. **Ablage:** Dieses Dokument als `docs/soll-handbuch.md` ins Repo legen und in `CLAUDE.md` einen Verweis ergänzen: "Bei größeren Aufgaben (neue Abläufe, Umbauten über mehrere Seiten, Datenmodell, Menü) zuerst `docs/soll-handbuch.md` lesen." Eine Kopie bleibt im Drive-Ordner "bulkify v4 md".
2. **Wann lesen:** Bei **größeren Aufgaben** immer vorher lesen - also bei allem, was einen Ablauf, mehrere Seiten, das Datenmodell, Rechte oder das Menü betrifft. Bei Kleinkram (Knopf umbenennen, Abstand, Tippfehler, einzelne Anzeige) nicht nötig.
3. **Vorrang:** Das Handbuch hat **Vorrang vor älteren MDs**. Widerspricht eine ältere Anweisung dem Handbuch, gilt das Handbuch. Widerspricht eine **neue** Anweisung von Nico dem Handbuch, gilt die neue Anweisung - und das Handbuch wird danach angepasst (Abschnitt 14).
4. **Unklar = fragen:** Ist etwas hier nicht geregelt oder offen markiert (Abschnitt 12), nicht raten, sondern kurz zurückmelden.
5. **Nichts doppelt bauen:** Bevor etwas Neues entsteht, prüfen, ob es das schon gibt. Für jede Sache gibt es **genau eine Stelle** (Abschnitt 1).

**Für Nico und Claude (Chat):** Neue Entscheidungen werden hier nachgetragen, nicht nur in einer einzelnen MD. Jede neue Arbeitsanweisung verweist auf den passenden Abschnitt dieses Handbuchs.

---

## 1. Grundprinzipien

1. **Der Auftrag steuert alles.** Ab der Auftragsbestätigung hängt jede Bestellung, jede Charge, jeder Produktionsschritt, jedes Dokument und jeder Versand an einem Auftrag. Vom Auftrag aus muss man alles erreichen können.
2. **Eine Stelle je Sache.** Ändern, Zuordnen, Freigeben, Prüfen - jeweils genau ein Ort. Keine zweiten Wege "der Vollständigkeit halber". Wenn heute zwei Wege existieren, wird zusammengeführt, nicht ergänzt.
3. **Geführt statt suchen.** Das System fragt den Nutzer zur richtigen Zeit das Richtige ("Wie machen wir diesen Auftrag?", "Bei wem bestellen?") und schlägt Antworten vor (Lieferant, Preis, Menge). Der Nutzer bestätigt oder ändert.
4. **Hintergrund koppelt automatisch.** Was das System selbst wissen kann (Bedarf, Abzug vorhandener Bestand, Zuordnung beim Wareneingang gegen Bestellung, Kapselzahl aus Gewicht, Chargenkette), rechnet es selbst. Der Nutzer sieht nur, was eine Entscheidung braucht.
5. **Hinweis statt Blockade - Sperren nur an festen Punkten.** Fehlende Unterlagen oder Abweichungen erzeugen einen **Hinweis + eine Aufgabe** in der Liste "Zu erledigen" (Abschnitt 6). Harte Sperren gibt es nur dort, wo dieses Handbuch es ausdrücklich sagt (Etikettieren ohne Freigabe, Abfüllen beim Chargenwechsel ohne Bestätigung, falscher Eimer, Charge passt gar nicht, Versand/Lager-2-Freischaltung ohne grüne Prüfliste, fehlender Karton beim Versand).
6. **Jeder Schritt braucht nur sein eigenes Material.** Ein Auftrag ist so weit produzierbar, wie das Material für den jeweiligen Schritt reicht (Abschnitt 4.7).
7. **Rückverfolgbarkeit ohne Mehrarbeit.** Chargen, Unter-Chargen und Lieferungen werden lückenlos mitgeschrieben - durch Scannen und Vorbelegen, nicht durch Abtippen.
8. **Die Daten bleiben.** Umbauten ändern Abläufe und Oberfläche, nicht die vorhandenen Daten. Bestehende Zuordnungen, Chargen, Belege müssen erhalten bleiben. Migrationen vorher melden.
9. **Konventionen:** keine Emojis; "Stk" statt "Stück"; Tausenderpunkt in Anzeigen, deutsch-sicheres Zahlenparsing bei Eingaben (`erp_menge_parse()`/`zahl_lesen()`, nie `intval` auf Mengen, keine `type=number`-Felder für Mengen); Dropdowns mit mehr als 10 Einträgen als Suchfeld; Zeiten in UTC speichern, in Ortszeit zeigen; MySQL Strict-Mode; erst Testkopie, dann live; ein Fix = ein Commit.

---

## 2. Begriffe (damit alle dasselbe meinen)

- **Rezeptur:** Wirkstoffe in mg + Darreichungsform (Kapsel, Pulver, Tablette …). **Je Kapselgröße eine eigene Rezeptur.** Rezepturen sind für alle Kunden sichtbar, außer sie sind ausdrücklich **exklusiv**.
- **Produkt:** Rezeptur × Menge je Packung × **neutraler** Behälter (z. B. "Ashwagandha · 120 Kapseln · 150 ml Weithalsglas"). Ein Produkt ist kundenneutral und wird einmal angelegt.
- **Kundenschicht:** Was einem Kunden gehört: Name beim Kunden, Kundenpreise, **Etikett-Design** (je Produkt + Kunde, mit Versionen v1, v2 …), bedruckter Beutel (je Produkt + Kunde). Beipackzettel und Umkarton sind **optionale Positionen am Angebot**, nicht am Produkt.
- **Charge (Rohstoff/Bulk):** Lieferanten-Charge eines Rohstoffs oder zugekaufter Bulkware, mit MHD und CoA.
- **Lieferung (Verpackung):** Ein Wareneingang von Gläsern, Dosen, Deckeln, Kartons, Etiketten. Wird für die Rückverfolgbarkeit festgehalten (siehe Abschnitt 4.5 und 8).
- **Fertigware-Charge / Unter-Charge:** Die Charge des fertigen Produkts, z. B. **2799**. Werden Rohstoff- oder Bulk-Chargen gewechselt, entstehen **Unter-Chargen 2799.A, 2799.B …** (Abschnitt 4.8). Jede Unter-Charge wird getrennt gemischt, verkapselt, abgefüllt und verpackt.
- **Eimer:** Eine Mischung als geschlossene Einheit, mit eigenem Etikett und Unter-Charge-Buchstaben.
- **Gebinde:** Ein einzelner Karton, Sack, Eimer, Kanister im Lager, mit eigener ID hinter dem Barcode und eigenem Bestand (z. B. echtes Gewicht je Karton).
- **Lager 1:** eigener Bestand. **Lager 2:** Kundenware für das Fulfillment (Besitzer = Kunde).
- **Zu erledigen:** die eine zentrale Aufgabenliste im Dashboard (Abschnitt 6).
- **Prüfliste vor Versand:** automatische Liste von Bedingungen, die erfüllt sein müssen, bevor Ware das Haus verlässt oder in Lager 2 verkaufbar wird (Abschnitt 4.12).

---

## 3. Menü: "Einfach" und "Ausführlich"

**Problem heute:** Zu viele Menüpunkte und Knöpfe, vieles davon im Alltag unnötig oder noch nicht im Einsatz (Kalender, Planung, Maschinen).

**Soll:** Ein Umschalter **Einfach / Ausführlich** pro Nutzer (gespeichert am Nutzer). Das Menü ist bereits datengetrieben (Menü-Editor vorhanden) - der Umschalter blendet nur aus, er löscht keine Seiten.

**Einfach (Alltag)** ist **eine flache Liste ohne Gruppen** (keine Zwischenüberschriften, kein Auf-/Zuklappen), etwas größer, jeder Punkt mit Linien-Icon, in dieser festen Reihenfolge (Nico 08.11.2026):
- **Zu erledigen** (die zentrale Aufgabenliste, Abschnitt 6) - ganz oben, mit Zähler *(kommt mit Schritt 4, bis dahin nicht angezeigt)*
- **Benachrichtigungen** - Glocke mit Zähler der ungelesenen; darunter bzw. bis Schritt 4 ganz oben. In "Einfach" entfällt dafür der kleine Eintrag unten in der Seitenleiste. *(Heute ein gemeinsames Team-Postfach, das nur Admins öffnen dürfen - Freigabe für weitere Rollen offen, Abschnitt 12.)*
1. **Anfragen** - **eine** Tabelle mit allen Anfragearten (Rezeptur-, Produkt-, Rohstoff-, Dienstleistungsanfragen), Spalte "Art", Filter nach Art, Markierung "neu"
2. **Angebote**
3. **Aufträge**
4. **Einkauf** - Einkaufsliste, von dort zu den Bestellungen (Entscheidung 12.1)
   - **Bestellt** - Liste der Bestellungen: was bestellt ist und was noch unterwegs ist (Nico 08.11.2026, direkt unter Einkauf)
5. **Produktion** (heute die Produktionsliste, später die Reihenfolge-Liste, Abschnitt 4.6)
6. **Lager** - **ein** Punkt auf das Warenlager; Lager 1 / Lager 2 sind Filter-Reiter auf der Seite (Entscheidung 12.1)
7. **Produkte**
8. **Rezepturen**
9. **Rohstoffe**
10. **Buchhaltung** - nur für Nutzer mit der Rolle Finanz (finance)

Die Reihenfolge ist fest im Programm und hängt nicht am Menü-Editor (der steuert nur "Ausführlich"). Was eine Rolle nicht darf, fehlt einfach.

**Ausführlich:** alles aus "Einfach" plus alles Übrige (Kunden, Partner, Kundenpreise, Kalender, Planung, Maschinen, Geführte Produktion, Easy Production, Kontingente, Dienstleistungen, Novel Food, Doppelte Rezepturen, Lager- und Einkauf-Unterpunkte, Datenprüfung, Einstellungen, Unterprogramme /produktion/, /lager/, /crm/, /buchhaltung/ …). Gruppen und Reihenfolge bestimmt weiter der Menü-Editor.

**Umsetzung (Schritt 2, live 08.11.2026):** Umschalter "Menü: Einfach | Ausführlich" unten in der Seitenleiste, gespeichert am Nutzer (`benutzer.menu_modus`, Standard "einfach"). Werk-Nutzer behalten ihr eigenes Werk-Menü ohne Umschalter. Das Logo führt zur Startseite. Anfragen und Lager sind in **beiden** Modi je ein Punkt. Keine Rechte geändert, jede ausgeblendete Seite bleibt per Link erreichbar. Was laut Abgleich überflüssig ist (`produktion_neu`, `produktion_run`, `produktion_vorbereitung`, `einkauf_mobil` usw.), steht vorerst unter "Ausführlich" und wird einzeln entschieden.

---

## 4. Die Kette Schritt für Schritt

### 4.1 Anfrage → Angebot → Auftrag
- Anfragen kommen über das Portal oder werden angelegt. Aus der Anfrage wird ein Angebot; nimmt der Kunde an, entstehen **Auftrag, Produktionsauftrag und Rechnung**. Die Rechnung gibt es also ab Annahme (bei Vorkasse/Teilzahlung entsprechend teilbezahlt).
- Der Auftrag enthält alle Informationen (Menge, Produkt, Verpackung, Füllmenge, Preis). Dieser Teil gilt als **fertig**; Änderungen an Stammdaten laufen über den **Stift an den Kacheln** im Reiter Details (Abschnitt 5).

### 4.2 Weg festlegen - "Wie machen wir diesen Auftrag?"
Sobald ein Auftrag neu ist, fragt das System aktiv (Auftrag erscheint in "Zu erledigen": "Weg festlegen"). Im Reiter **Beschaffungsweg** stehen die Wege **nebeneinander**, mit Kosten für **genau diesen Auftrag**:
1. **Zukauf fertig** - fertige Kapseln/Tabletten (bei Pulver: fertige Mischung)
2. **Premix** - fertige Mischung, die selbst verkapselt/abgefüllt wird
3. **Einzelrohstoffe** - Eigenproduktion aus Rohstoffen
4. **"Haben wir schon im Lager"** - eine **Suche über den gesamten Bestand** (nicht nur über das, was das System für passend hält). So findet man auch Ware, die unter anderem Namen eingebucht ist (z. B. loser "Bulk #0"). Gewählte Charge wird dem Auftrag fest zugeordnet; das System prüft nur noch, ob die Menge reicht, und fragt beim Rest, wie er beschafft wird.
- Gilt für das **Hauptmaterial** (Bulk-Kapseln, Premix, Rohstoffe). Nebenmaterial (Gläser, Deckel, Etiketten, Kartons) wird über **"Zuordnen" in der Material-Tabelle** aus dem Lager genommen - ebenfalls mit Suche über den ganzen Bestand.
- **Preise:** aus hinterlegten Lieferantenpreisen. **Staffel nach Menge:** es zählt nur eine Staffel, deren Mindestmenge der Bedarf erreicht (225 kg → Staffel "ab 200", nie "ab 300"). Ist eine echte Bestellung verknüpft, gilt deren Preis. Ohne Preis: nur die benötigte Menge zeigen, keinen Preis erfinden.
- Vorhandenes zieht das System **automatisch ab**: was schon im Lager liegt oder schon bestellt/unterwegs ist und frei ist. Gerechnet und bestellt wird nur der **Rest**.
- **Handelsware** (zugekauft, kein Produktionsauftrag) hat ebenfalls einen Weg ("Zukauf · Handelsware").
- **Eine Oberfläche (gebaut 10.11.2026):** Festgelegt wird im Reiter Beschaffungsweg bzw. im **Pop-up "Weg festlegen"** - beide zeigen dieselben vier Karten (Haben wir schon im Lager · Zukauf fertig · Premix · Einzelrohstoffe) mit Kosten, günstigstem Lieferanten und Rest. Kopf im Pop-up: Auftrag, Angebot, Kunde, Produkt als Links (neuer Tab), Menge, Kapselgröße, Glas, "Letzte Festlegung"; darunter die **Rezeptur für diesen Auftrag** (mg je Einheit, kg für den Auftrag, frei, unterwegs, Rest; Leerkapseln in Stk; "Rezeptur öffnen"). Das Pop-up steht auf der Bestellen-Seite (Block "warten auf Festlegung"), unter "Beschaffungsweg offen", auf der PA-Seite und der alten Seite Bedarf; jede Zeile hat Links zu Auftrag, Angebot und Rezeptur. **Entfallen:** die Knöpfe "Eigenproduktion / Fremdproduktion (zukaufen)" auf der Bestellen-Seite, "Herstellen / Einkaufen" im Reiter Produktion (nur noch "Produktionsauftrag anlegen"), die Sammel-Umstellung Eigen/Fremd in der Produktionsliste und die Eigen/Fremd-Auswahl auf der Seite Bedarf. Bleibt als Sonderfall: auf der PA-Seite nach Produktionsbeginn "auf fertige Kapseln umstellen" sowie die Rückabwicklung.

### 4.3 Beschaffung - "Bei wem bestellen?"
Nach der Wahl geht es direkt weiter: "Für Eigenproduktion fehlen 225 kg L-Carnosin und 3.000 Etiketten - bei wem bestellen?" Lieferant und Preis sind vorgeschlagen.
- **Kapsel und Premix:** direkter Knopf "Bestellen" je Lieferant (mit Gesamtkosten für den Auftrag).
- **Einzelrohstoffe:** über die **Bedarfsliste** (oft mehrere Lieferanten).
- **Drei Bestellwege** an jeder Bestellung:
  1. **Über Portal** - Lieferant bekommt die Bestellung im Lieferantenportal + Mail und bestätigt dort.
  2. **Online** - Bestellung im Shop des Lieferanten, Eingabe der Bestellnummer.
  3. **Direkt bestellt** (telefonisch/per Mail, z. B. VitaActives) - Bestellung steht sofort auf "bestellt", **keine** Mail an den Lieferanten, **keine** Bestätigung nötig. Der Lieferant sieht sie trotzdem in seinem Portal (Tracking, Rechnung möglich). Für schon übers Portal verschickte Bestellungen gibt es "Telefonisch bestätigt". (Eigene Anleitung "Bestellweg Direkt bestellt".)
- **"Rohstoff bestellt"** (Kunden-Fortschritt) wird nur gesetzt, wenn es eine **echte** Bestellung zum Auftrag gibt (Status gesendet/bestellt/geliefert) - nicht durch "Weg festlegen", nicht durch Entwürfe, nicht durch Bestellungen anderer Aufträge.
- Ist alles bestellt bzw. zugeordnet, verschwindet der Auftrag aus der Arbeitsliste, bis Ware ankommt.
- **Premix anfragen (gebaut 10.11.2026):** Neben "Fertigprodukt anfragen" (Bulk-Kapseln) und "Preis anfragen" (Einzelrohstoff) gibt es "Premix anfragen" - auf der Rezepturseite, auf der Produktseite (Reiter Beschaffung, Auswahl Kapsel/Tablette · Premix · Rohstoffe) und direkt aus der Premix-Karte im Beschaffungsweg. Die Anfrage enthält die Zusammensetzung (mg je Einheit und % der Mischung), Mengen-Staffel in kg, CoA/Spezifikation, Incoterm, Versandart, Notiz; mehrere Lieferanten wählbar. Bei der ersten Anfrage wird der Premix-Artikel "Premix · <Rezeptur>" angelegt (je Rezeptur). Der Lieferant gibt im Portal einen Preis je kg (Staffel) ab, mit Spec oder CoA je Rohstoff (4.4). Der Preis wird als **Lieferantenpreis am Premix-Artikel** gespeichert; danach zeigt die Premix-Karte den Preis, "Bestellen" geht wie bei Kapsel/Bulk, die Kalkulation rechnet Premix je kg + Leerkapseln.
- **Netto auch für Kundenetiketten und bedruckte Beutel (08.11.2026):** Die Bestellliste rechnet **jede** Position netto - Bedarf minus freier Bestand minus Quarantäne minus unterwegs (Entwurf, gesendet, bestellt). Das gilt auch für Kundenetiketten und bedruckte Beutel; nur der Rest erscheint, Bedarf gedeckt = Zeile weg. Bestellliste, Reiter Material am Auftrag und der Etiketten-Stand im Kunden-Fortschritt nutzen **dieselbe Rechnung** ("Etiketten auf Lager", sobald der Bestand reicht - ohne Bestellung, nichts von Hand setzen).

### 4.4 CoA zur Lieferung (Eingangsunterlagen)
- **Portal-Lieferanten** müssen beim Melden "versendet" die **Chargennummer** eintragen **und das CoA hochladen** - sonst können sie nicht abschließen.
- **Direkt bestellt/telefonisch:** Nico (oder Einkauf) lädt das CoA aus der Mail an der Bestellung hoch und trägt die Charge ein.
- **Ein Platz für die Unterlagen (gebaut 08.11.2026):** Charge, MHD, Menge, CoA und Spezifikation liegen **an der Bestellposition** (Block "Chargen & Dokumente" auf der Bestellseite), die Rechnung im Block "Zahlung". Hochladen können **Team und Lieferant** (Portal) - dieselbe Ablage, zwei Oberflächen, nichts doppelt (nur eine Rechnung je Bestellung). Mehrere Chargen je Position möglich (Teillieferung, zwei Chargen). Liegt am Rohstoff schon eine gültige Spezifikation, wird sie nur angezeigt. Beim **Wareneingang gegen die Bestellung** (Lager-App und Dashboard) bekommt die Charge die Bestellposition; CoA und Spezifikation hängen damit **automatisch an der Charge** - auch wenn das CoA erst später hochgeladen wird. Fehlt das CoA beim Wareneingang: nur Hinweis, blockiert nichts. Die eingetragene Charge ist die Vergleichsbasis für den blinden Chargen-Abgleich (4.5, kommt mit Schritt 6).
- **Bei jeder Preisabgabe** im Lieferantenportal ist mindestens **eine Spezifikation oder ein CoA** Pflicht.
- **Prüfung durch QM (Tabea)** - geht schon **vor** der Lieferung, sobald das CoA da ist. "Geprüft" heißt:
  1. Passt es zur Ware? (Chargennummer CoA = Charge der Lieferung)
  2. Stimmen die Werte? (Gehalt, Schwermetalle, Mikrobiologie, je nach Rohstoff Pestizide/Allergene - innerhalb der Spezifikation)
  3. Ist es gültig? (MHD passt, CoA ist für genau diese Lieferung ausgestellt)
- **KI-Hilfe:** Das System liest das CoA aus, vergleicht Charge und Werte mit der Spezifikation und **markiert nur Abweichungen**. Tabea bestätigt mit einem Klick oder schaut sich die markierten Fälle an.
  *(Gebaut 08.11.2026 an der Bestellung: CoA hochladen → KI liest im Hintergrund Charge, MHD, Menge und Analysewerte aus → Prüf-Popup mit Datei, Werten und markierten Abweichungen gegen unsere Spezifikation → "Geprüft – übernehmen". Bei Abweichung wichtige Benachrichtigung; die Werte gehen beim Wareneingang an die Charge.)*
  *(Rohstoff: Ein Bestell-CoA überschreibt die Spezifikation des Rohstoffs nie. Es erscheint in den Dokumenten des Rohstoffs; beim Prüfen wird nur Fehlendes ergänzt (Grenzwerte, wenn noch keine da sind; fehlender Wirkstoffgehalt). Unterschiede zum Rohstoff zeigt das Popup als Hinweis - angepasst wird von Hand.)*
- **Fehlendes oder ungeprüftes CoA blockiert NICHT** Wareneingang oder Produktion - es erzeugt einen Hinweis und die Aufgabe "CoA fehlt / CoA prüfen" für Tabea. **Gesperrt wird erst vor dem Versand** bzw. vor der Freischaltung in Lager 2 (Prüfliste, 4.12).

### 4.5 Wareneingang
- **Wareneingang immer gegen eine Bestellung (gebaut 10.11.2026):** Alle Einbuchwege (Dashboard-Wareneingang, Lager-App mit Liste, Lieferschein, Sendungsnummer, Rezeptur-Aufkleber, Etiketten; später Scan des Lieferanten-Aufklebers) nutzen **eine** gemeinsame Funktion. Erster Schritt: Bestellung wählen (Liste der erwarteten Lieferungen, Suche nach BE-Nr./Lieferant/Artikel, Scan). Artikel und Einheit kommen fest aus der Bestellposition; Kapselgröße/Füllgewicht stehen dabei. Die Charge trägt die Bestellposition (mehrere Chargen je Position möglich). Easy Production bucht eigene Produktion und braucht keine Bestellung.
- **Gelieferte Menge und Abschluss automatisch:** gelieferte Menge je Position = Summe der verknüpften Chargen (in der Einheit der Bestellung). Alles da -> die Bestellung schließt sich selbst (geliefert, Archiv) - egal, was der Lieferant im Portal gesetzt hat. Teillieferung -> bleibt offen, der Rest zählt als unterwegs; Knopf **"Rest storniert"** schließt sie. Mehrlieferung -> nur Hinweis, Überschuss ist freier Bestand. Bestellliste, Material-Reiter und Beschaffungsweg rechnen "unterwegs" nur aus dem offenen Rest. Beispiel: 7.000 Tüten bestellt und eingebucht, 6.000 verbraucht -> die nächste Bestellliste zeigt 6.000 Bedarf minus 1.000 frei = 5.000.
- **Ohne Bestellung (Übergangsphase, Entscheidung Nico):** nur mit Grund. "Alte Bestellung, nicht im System": Artikel zuordnen + Lieferant -> das System legt eine nachträgliche Bestellung an (direkt bestellt, geliefert, Menge = Eingang) und verknüpft die Charge; Nico prüft Preis und Daten (Benachrichtigung + Datenprüfung). "Muster", "Kundenware" (Lager 2), "Sonstiges": Artikel zuordnen + Pflichtnotiz + Meldung. Ware ohne Artikel gibt es nie.
- **Altlasten:** Datenprüfung "offene Bestellung ohne Wareneingang" / "eingegangen ohne Charge" mit Vorschlägen vorhandener Chargen; bearbeitet an der Bestellung im Block "Wareneingang zuordnen" (verknüpfen, als geliefert schließen, stornieren) - nur auf Klick.
- **Rohstoff-Dubletten (Regel Nico 10.11.2026):** gleiche Qualität/Spezifikation von verschiedenen Lieferanten = **ein** Artikel mit mehreren Lieferantenpreisen. Andere Spezifikation (13 % vs. 20 % Mg, Bio vs. konventionell, anderer Extraktgehalt) = eigener Artikel. **Bulk-Kapseln, Premixe und Fertigware mit anderer Kapselgröße (z. B. 0 vs. 00), anderem Füllgewicht oder Kapselmaterial sind immer eigene Artikel** - Zusammenführen ist dort gesperrt. Seite "Artikel zusammenführen" (Gruppen je Warenart mit Vergleich, Nico wählt den Hauptartikel; Chargen, Bestellpositionen, Rezepturzeilen, Lieferantenpreise, Dokumente ziehen um; der alte Artikel wird gesperrt, sein Name bleibt Synonym). Beim Anlegen warnt "Meintest du …?". Artikelsuche überall mit Synonymen und sichtbarer Kapselgröße.
- Dustin sieht in der Lager-App die Liste **"Erwartete Lieferungen"** (offene Bestellungen, "unterwegs"). Er tippt die Lieferung an; Artikel, Menge, Auftrag, Rezeptur, Kapselgröße sind übernommen. Freie Anlage ist möglich (bevorzugt aus vorhandenen Artikeln; "Neuer Artikel" nur über eigenen Knopf mit Pflichtangaben, jede Neuanlage meldet sich als Aufgabe zum Prüfen/Zusammenführen).
- **Charge blind eintippen:** Dustin tippt die Charge **vom Gebinde ab, ohne Vorschlag**. Das System gleicht mit der Charge aus der Bestellung/dem CoA ab:
  1. **Gleich** (tolerant: Punkte, Leerzeichen, Bindestriche, Groß-/Kleinschreibung egal - "2799.A" = "2799A" = "2799 a") → Ware ist sofort frei.
  2. **Ähnlich** (z. B. Zahlendreher 69/96, ein Zeichen anders) → Rückfrage: "Bitte noch einmal aufs Gebinde schauen - auf dem CoA steht …". Dustin korrigiert.
  3. **Passt gar nicht** → Ware geht in **Quarantäne**, Nico und Tabea bekommen sofort eine Benachrichtigung + Aufgabe.
  - Liegt noch keine Charge aus CoA/Bestellung vor: Eingabe wird übernommen, Abgleich passiert, sobald das CoA kommt; bis dahin Aufgabe "CoA fehlt".
- **Ware ist standardmäßig frei.** Quarantäne nur bei bewusst gewähltem "Problem" (Beschädigung, Abweichung) oder bei Chargen-Mismatch (oben).
- **Gewicht je Karton** bei wiegbarer Ware (Rohstoffe, Bulk, Pulver): Zeile je Karton, gleichmäßig vorbelegt, jede änderbar, Summe/Differenz laufend, **bei Differenz gesperrt**. Echtes Gewicht am Gebinde hinter dem Barcode; Scan zeigt den echten Inhalt; Entnahme und Rückgabe (neu wiegen, "ordentlich verschlossen") je Gebinde. (Gebaut - Rückmeldung "Gewicht pro Gebinde".)
- **Bulkware** wird nur auf den Bulk-Artikel der Rezeptur gebucht (keine losen "Bulk #0"-Artikel mehr); die **Kapselgröße** kommt automatisch aus Auftrag/Rezeptur.
- **Zuordnung zum Auftrag:** Beim Wareneingang gegen eine verknüpfte Bestellung hängt die Ware automatisch am Auftrag, und der Auftrag springt auf "Material da".
- **Etiketten - Maß nur zur Info (Nico 08.11.2026):** Beim Einbuchen (Lager-App "Etiketten einbuchen", Dashboard-Wareneingang) werden Etikett-Maß und Glas-Format nur **zur Info** verglichen. Etiketten sind oft **2-5 mm größer** als das Glas-Endformat (Sicherheitsmaß) - das ist **kein Fehler und sperrt nie**: keine Sperre, keine Rückfrage, keine Quarantäne, keine Aufgabe, nur ein neutraler Hinweis; der Knopf heißt "Menge erfassen". Das Etikett-Maß kommt bei neuen Designs aus der Druckdatei (**TrimBox** = Endformat, sonst ArtBox, sonst MediaBox); bestehende Etiketten passt Nico mit der Zeit selbst an (Liste: Datenprüfung "Maß laut Druckdatei").
- **Verpackung (Gläser, Dosen, Deckel):** *Vorschlag, offen (Abschnitt 12):* im Lager **eine Menge je Artikel** statt sichtbarer Einzelchargen; die Lieferungen laufen im Hintergrund für die Rückverfolgbarkeit mit; die Produktion bucht die älteste Lieferung zuerst ab und der Produktionsbericht vermerkt die Lieferung. Etiketten/Kartons mit Design: maßgeblich ist die **Design-Version** (eigener Artikel je Version).

### 4.6 Produktionsreihenfolge
- **Nico oder Tabea** legen fest, was als Nächstes produziert wird: Liste unter **Produktion** im Dashboard, sortierbar per Ziehen.
- Die **Werk-App** zeigt den Mitarbeitern genau diese Reihenfolge, oben der nächste Auftrag.
- Aufträge, die gerade **nicht machbar** sind, sind **grau** mit Grund ("Etiketten fehlen", "Gläser fehlen"). **Teilweise machbare** zeigen, bis wohin ("machbar bis Verkapseln - Gläser fehlen"). Ist der oberste blockiert, rückt der nächste machbare nach vorne, mit Hinweis.
- Zuordnungen (z. B. "diese Etiketten im Lager gehören zu diesem Auftrag") macht man weiterhin im **Material-Reiter des Auftrags**; sobald etwas zugeordnet ist, wird der Auftrag automatisch von grau auf machbar umgestellt.
- Nico kann zusätzlich am Auftrag **"Teilmenge produzieren"** anweisen (Menge, Umfang, Priorität) → erscheint in der Werk-App als Aufgabe. Die Produktion darf Teilmengen auch selbst machen.

### 4.7 Material je Schritt (was jeder Schritt braucht)
| Schritt | braucht |
|---|---|
| Mischen | Rohstoffe (bzw. Premix) |
| Verkapseln | Mischung + Leerkapseln |
| Abfüllen | Kapseln + Gläser/Dosen + Deckel |
| Etikettieren | **freigegebenes** Etikett **mit Bestand** |
| Verpacken | Kartons |

- Der Auftrag ist so weit produzierbar, wie das Material des jeweiligen Schritts reicht. **Fehlende Etiketten oder Gläser blockieren nicht das Mischen und Verkapseln.**
- Zwischenstände (Kapselsäcke, "fertig, unetikettiert") gehen mit Etikett ins Lager 1 und werden für den nächsten Schritt wieder geholt.
- **Bekannter Fehler (zu beheben):** Bei AB-3289 zählt "Etikett vorrätig: fehlt 3.000 Stk" als fehlendes Material, und der Auftrag gilt als "Produktion nicht möglich". Fehlende Etiketten dürfen nur den Schritt **Etikettieren** sperren.

### 4.8 Produktion: Eimer, Unter-Chargen, Chargenwechsel
*Grundlage bleibt das Produktionsmodul-Konzept vom Oktober 2026 (Rückstellmuster mind. 5 Stk und Proben je Gebinde, Kapselwiegungen, Pausen nur an sauberen Punkten, Maschinen-Scans, Etikett je Gebinde). Hier steht, was am 08.11. ergänzt bzw. klargestellt wurde.*

**Eimerplanung (Mischen):**
- Das System **gibt die Eimer genau vor** - Größe und Reihenfolge. Der Mitarbeiter entscheidet die Eimergrößen nicht selbst.
- Regel: Bei jedem Rohstoff wird **zuerst die ältere Charge** verbraucht. **Jedes Mal, wenn bei irgendeinem Rohstoff eine Charge zu Ende geht, beginnt ein neuer Eimer** mit neuem Buchstaben. Beispiel: Rohstoff 1 hat noch 5 kg alt, Rohstoff 2 noch 30 kg alt → Eimer **A** bis die 5 kg verbraucht sind (kleiner Eimer), Eimer **B** bis die 30 kg verbraucht sind, danach normale 25-kg-Eimer mit den neuen Chargen (C …). Der Mitarbeiter bekommt die klare Anweisung "Zuerst Eimer A mit 5 kg - wegen Restcharge".
- Jeder Eimer bekommt ein Etikett mit der Unter-Charge (z. B. 2799.A).
- **Zugekaufte Bulkware** genauso: Reichen zwei Bulk-Chargen nur zusammen (z. B. 50.000 alt + 150.000 neu für 200.000), teilt das System den Auftrag automatisch in **zwei Läufe**: erst 50.000 als 2799.A, dann 150.000 als 2799.B - jeder mit eigener Nummer und genauer Menge.

**Verkapseln:**
- Eimer scannen, getrennt verkapseln; der Kapselsack bekommt ein Etikett mit **derselben Unter-Charge**.
- **Kapselzahl:** Mitarbeiter wiegt laufend Stichproben (z. B. mehrmals je 10 Kapseln) → echtes Durchschnittsgewicht. **Kapseln = (Sackgewicht - Tara des Sacktyps) / Durchschnittsgewicht.** Tara je Sacktyp ist hinterlegt. Gezählt wird nicht von Hand.

**Abfüllen:**
- Eimer für Eimer bzw. Sack für Sack. **Nicht nachkippen** über eine Unter-Charge hinweg.
- **Falscher Eimer/Sack:** Scannt der Mitarbeiter ein Gebinde einer anderen Unter-Charge, während die aktuelle nicht fertig ist → **Stopp:** "Falsche Charge - erst den angefangenen Eimer komplett abfüllen." Weiter erst mit dem richtigen Gebinde. Reihenfolge gibt das System vor (älteste Unter-Charge zuerst).
- **Chargenwechsel** (neue Unter-Charge beginnt) - Pflicht-Bestätigung, ohne die es nicht weitergeht:
  1. Linie ist **leergelaufen**,
  2. Stempel/Drucker für Charge und MHD ist **auf die neue Nummer umgestellt**,
  3. **Foto** vom ersten neuen Glas mit aufgedruckter Charge ist hochgeladen.
  Alles landet im Herstellprotokoll (Chargenwechsel mit Linienräumung).

**Rückmeldungen und Gegenprobe:**
- Nach jedem Abschnitt meldet der Mitarbeiter, was tatsächlich herauskam: Mischen → Gewicht des Eimers; Verkapseln → Sackgewicht (Kapselzahl rechnet das System); Abfüllen/Verpacken → Gläser über die Kartons (4.10) + Ausschuss.
- **Entscheidend ist die Zahl der fertigen Gläser.** Die Kapselzahl ist die **Gegenprobe**: Gläser × Kapseln je Glas muss zur Kapselzahl der Unter-Charge passen (Toleranz für üblichen Ausschuss). Passt es nicht ("1.000 Gläser à 120 = 120.000, aber nur 100.000 Kapseln") oder ist der Schwund über der Toleranz → **Hinweis an den Mitarbeiter** (kein Stopp) **und Aufgabe in "Zu erledigen"** für Nico/Tabea mit Auftrag, Unter-Charge, erwartet/gemeldet, wer, wann. Begründung: Der Mitarbeiter will schnell fertig werden; die Kontrolle liegt im Büro.
- Ebenso als Aufgabe: Chargenwechsel ohne vollständige Bestätigung (falls je möglich), auffällige Wiegungen.
- **Rückstellmuster je Unter-Charge** (A, B … jeweils eigene).
- Daraus entsteht automatisch das **Herstellprotokoll**: welche Rohstoff-Charge in welchen Eimer, welcher Eimer in welchen Kapselsack, welcher Sack in welche Gläser - mit Mengen, Personen, Zeiten, Wiegungen, Fotos.

### 4.9 Etikett
- Die **Etikett-Freigabe** (Design-Version durch Kunde bzw. im Namen des Kunden) gehört **vor das Etikettieren**. Ohne Freigabe ist **nur der Schritt Etikettieren gesperrt**; Mischen, Verkapseln, Abfüllen bis vor das Etikett laufen.
- Werk-App zeigt "Etikett nicht freigegeben - nur bis vor dem Etikettieren"; im Dashboard steht es am Auftrag und in "Zu erledigen" (freigeben oder beim Kunden nachhaken).
- Etikett hochladen/freigeben geschieht am Auftrag über den **Stift an der Kachel "Etikett"** (Reiter Details). Design liegt am Produkt + Kunde; die Freigabe der Version am Auftrag.
- Kein stiller Ersatz durch ein generisches Etikett: fehlt das Kundenetikett im Bestand, ist Etikettieren gesperrt (alte Etiketten per "Update-Etikett erzeugen" schrittweise umstellen).

### 4.10 Kartons und Kartonetiketten (in der Produktion)
- Nach dem Etikettieren wird verpackt. **Immer wenn ein Karton voll ist**, tippt der Mitarbeiter am Tablet "Karton drucken": Menge ist mit der Standard-Stückzahl je Karton vorbelegt (z. B. 68), er ändert sie nur beim Rest-Karton. Das Etikett kommt sofort.
- **Kartonetikett:** Produkt, Kunde/Auftrag, **Unter-Charge**, MHD, **Menge in diesem Karton**, **Karton-Nummer**, Barcode. **Kein "von n"** (die Gesamtzahl steht erst am Ende fest).
- **Sortenrein:** Ein Karton enthält nur eine Unter-Charge. Erst alle Kartons von A (inkl. Rest-Karton A), dann B.
- **Die Summe der Kartons ist die produzierte Menge** je Unter-Charge - es muss am Ende nichts extra gemeldet werden. Beim Abschluss der Unter-Charge Abgleich mit der Kapselzahl (4.8), Abweichungen → "Zu erledigen".
- Druck über die Lager-Druck-Brücke auf den Produktionsdrucker (100 × 150 mm).

### 4.11 Fertigware
- Eigene Fertigware ist nach der Produktion **sofort frei** (keine QM-Sperre beim Einbuchen) und liegt in Lager 1.
- **Automatisch erzeugt:** das zusammenfassende **CoA** je Charge beim Einbuchen (Übersicht aus den Rohstoff-Analysen, ehrlich gekennzeichnet "nicht am Fertigprodukt gemessen", Mikrobiologie nur als Rohstoffwert, claims-neutral; ein echtes Fertigprodukt-Labor-CoA hat Vorrang) und der **Produktionsbericht** beim Produktionsabschluss.
- Im Kundenportal sieht der Kunde an der Bestellung Charge(n), MHD, CoA, Produktionsbericht.

### 4.12 Labor (externe Analyse)
- Laboranalysen bestellt der **Kunde** (Zusatzleistung). **Ein Labortest pro Auftrag** reicht. **Rückstellmuster** gibt es trotzdem von **jeder** Unter-Charge. *(Zurückgestellt 08.11.: ein eigenes Feld "Unter-Charge der Probe" kommt erst mit der Labor-Zusammenarbeit; bis dahin steht die Charge am hochgeladenen Bericht.)*
- **Heute:** Laborberichte werden von uns **hochgeladen** (Reiter Produktion, Block "Externer Labortest": Bericht hochladen, Befund, Freigabe fürs Portal). Der Kunde sieht ihn im Portal. Das ist der aktive Weg.
- **Später (noch keine Zusammenarbeit mit einem Labor):** "Ans Labor senden" mit ausgefülltem Auftragsformular (z. B. GBA). Kein Ausbau, keine Priorität, bis Nico die Zusammenarbeit startet.
- Chargen-Abgleich beim Zuordnen des Berichts **tolerant** (wie 4.5), damit "2799.A" und "2799A" zusammenpassen. *(Gebaut 08.11.: zentrale Funktion `charge_norm()`, beim Upload am Auftrag nur Hinweis bei Abweichung.)*
- Befund "auffällig" → Sperre vor Versand + Benachrichtigung Nico/Tabea. *(Stand 08.11.: "auffällig" gilt nicht als abgeschlossen, wichtige Benachrichtigung + roter Hinweis am Auftrag; die Sperre kommt mit der Prüfliste 4.13.)*
- Als Laborbericht zählen nur Berichte **dieses Auftrags**, nie das automatisch erzeugte Übersichts-CoA (4.11) und nicht Berichte am Produkt.

### 4.13 Prüfliste vor Versand (automatisch)
Bevor Ware das Haus verlässt **oder** in Lager 2 für das Fulfillment verkaufbar wird, prüft das System selbst:
1. Alle Rohstoff-CoAs der verwendeten Chargen sind **vorhanden und geprüft**.
2. Kapselwiegungen liegen in der Toleranz.
3. **Keine offene Abweichung** aus der Produktion (4.8/4.10) mehr in "Zu erledigen".
4. Falls ein Labortest bestellt ist: Bericht liegt vor und ist **bestanden**.

- Alles grün → **versandbereit**. Fehlt etwas → Ware gesperrt, Grund steht am Auftrag und in "Zu erledigen".
- Die Etikett-Freigabe steht **nicht** auf dieser Liste (wird schon vor dem Etikettieren erzwungen).
- **Offener Rechnungsbetrag** (z. B. teilbezahlt) ist **nur ein Hinweis**, keine Sperre - das entscheidet Nico je Kunde.
- *(offen, Abschnitt 12: ob Tabea zusätzlich bewusst "Freigabe" klicken soll oder grün reicht.)*
- **Lager 2 / Fulfillment:** Dieselbe Prüfliste schaltet beim Umlagern in Lager 2 den Bestand für Fulfillment und Shopify frei. Vorher liegt die Ware fertig, ist aber nicht verkaufbar.

### 4.14 Versand
- Nach der Produktion bzw. sobald die Prüfliste grün ist: Status **versandbereit**.
- **Lieferadresse bestätigen (neu):** Sobald versandbereit, bekommt der Kunde im Portal einen neuen Fortschrittspunkt **"Lieferadresse bestätigen"**. Er wählt eine gespeicherte Adresse oder legt eine neue an - mit **Zusatzfeldern für Referenzen** (z. B. Amazon-Sendungs-/Lieferungsnummer, Abteilung, Ansprechpartner, Anlieferzeiten). Grund: Kunden lassen oft an Dritte liefern (Amazon u. a.). Erst danach kann versendet werden. Nico kann im Notfall selbst bestätigen (z. B. nach Telefonat).
  - Nicht bestätigt → Aufgabe "Lieferadresse noch nicht bestätigt" in "Zu erledigen" mit Knopf **"Kunden erinnern"** (keine automatische Erinnerung).
  - Später: Cargoboard-Anbindung übernimmt diese Adresse samt Referenzen.
- **In der Lager-App (Dustin), Reiter "Versandbereit":** Versandart wählen - **Paket, Palette, Selbstabholung, Lager 2 (Fulfillment)**. Mehrere Aufträge eines Kunden können eine Sendung bilden.
- **Palettieren:** Dustin **scannt jeden Kartonaufkleber** auf die jeweilige Palette. Das System weiß damit, welche Kartons (mit welcher Charge) auf welcher Palette stehen - auch gemischte Chargen auf einer Palette sind erlaubt, solange jeder Karton sortenrein ist.
- **Fehlender Karton:** Weil jeder Karton in der Produktion gezählt wurde, meldet das System: "Karton 17, Charge A, 25 Stk noch auf keiner Palette - bitte suchen." **Versand erst, wenn alle Kartons zugeordnet sind.**
- **Dokumente:** Paletten-Etikett je Palette (Inhalt dieser Palette), **Gesamtlieferschein** gegliedert nach Paletten und darin je Artikel/Charge zusammengefasst ("Artikel X, Charge A: 3 Kartons à 25 + 1 Karton à 3"), mit Lieferadresse und Referenzen. Lieferschein-Kopie an jede Palette. Selbstabholung: Lieferschein "Selbstabholung".
- Kunde sieht im Portal "versendet" (mit Lieferschein, Tracking/Spedition, Charge, MHD).

---

## 5. Die Auftragsseite (je Sache eine Stelle)

Stand nach dem Umbau "Auftragsseite entschlacken" (live 08.11.2026):

| Reiter | Wofür (einzige Stelle) |
|---|---|
| **Details** | Kacheln; **Ändern per Stift → Pop-up → Speichern** (Verpackung, Füllmenge/Stk je Packung, Kapselgröße/Form/Gewicht, Etikett inkl. Upload/Version/Freigabe, Name beim Kunden, Lieferung). Speichert standardmäßig ans Produkt, Haken "nur für diesen Auftrag". Menge/Preis fixiert. |
| **Rezeptur** | Zusammensetzung, Unterlagen je Rohstoff |
| **Material** | **Die eine Material-Tabelle:** benötigt · zugeordnet · frei · Quarantäne · unterwegs · Rest · Deckung · Bestellt bei · **Zuordnen** je Zeile ("Aus Lager zuordnen" / "Bestellung verknüpfen" / Lösen). Kurze Prüfliste "Bereitschaft". |
| **Beschaffungsweg** | Nur die **Wahl** des Wegs (4.2) + Bestellen / An Bedarfsliste |
| **Produktion** | Fortschritt, Teilmenge anweisen, Herstellung, Energetisierung, **Labor** komplett |
| **Lager** | Fertigware-Chargen, Wareneingänge zum Auftrag |
| **Preise & Rechnung** | Kalkulation (VK gesamt + davon Herstellung, Materialkosten nach Bedarfsmenge), Rechnung, Kontingent |
| **Dokumente** | nur Downloads (AB, PIB, Laufzettel, Produktionsbericht, CoA, Laborbericht, Lieferschein) |

- **Keine** weitere Übersicht oben über den Reitern. Die Ablauf-Leiste (Material prüfen · Beschaffungsweg · Bestellen · Wareneingang · Produktion & Versand) bleibt.
- **Eine Verknüpfung:** Alle Zuordnungen Auftrag ↔ Charge/Bestellposition laufen über einen Schreibweg je Knopf (`reservierung` mit charge_id bzw. bestellung_position_id; Bulk/Fertigware weiter `charge.auftrag_id`). Neue Funktionen nutzen diesen Weg und erfinden keinen weiteren.

---

## 5a. Die Produktseite (je Sache eine Stelle)

Stand nach dem Umbau "Produktseite aufräumen" (09.11.2026). Kopf: Name (Stift), P-Nummer, Status, Rezeptur-Link, "Beim Lieferanten anfragen", "Löschen".
**Kacheln** wie beim Auftrag, **ändern per Stift → Pop-up → Speichern**, jede Kachel speichert für sich: Rezeptur, Darreichung/Kapselgröße, Menge je Packung, Glas/Behälter (nur Primärverpackungen), Deckel (nur Verschlüsse), Leerkapsel, Verzehr je Tag, Haltbarkeit, Status, Novel Food, MwSt, Katalog/exklusiv, Art des Produkts, Kosten je Packung (→ Kalkulation).

| Reiter | Wofür (einzige Stelle) |
|---|---|
| **Rezeptur** | Zusammensetzung je Rohstoff (mg je Einheit/Tagesdosis, gelieferte Nährstoffe) + Tages-Deklaration. Nur lesen, "Rezeptur bearbeiten". Hinweis bei leerer Rezeptur, Zutat ohne Menge, Rohstoff ohne Nährstoff-Zuordnung. |
| **Kunden** | Eine Tabelle, eine Zeile je Kunde: Kunde ("Kürzel (Firma)"), Name beim Kunden, Etikett (Version, Design freigegeben, im Lager / unterwegs / Bedarf offene Aufträge), Kundenpreise, letztes Angebot, letzte Rechnung. Stift je Zeile, "Kunde hinzufügen". Klappbar: alle Angebote und Rechnungen. |
| **Beschaffung** | Bezugswege (Zukauf fertig / Premix / Einzelrohstoffe) mit Kosten je Packung – nur Anzeige, die Wahl je Auftrag bleibt im Auftrag. Lieferanten für das Produkt mit Staffeln, Bedingungen, Portal-Account, Dokumenten, "günstigster". Rohstoffe, Verpackung, letzte Bestellungen, Preisanfragen, "Preis anfragen". |
| **Produktion** | Stückliste (Umkarton + Packungen je Karton, Beipackzettel, abweichendes Etikett), Standard-Produktionsweg, Notiz. |
| **Kalkulation** | Kosten je Packung aufgeschlüsselt (Inhalt, Leerkapsel, Glas, Deckel, Etikett, Karton anteilig, Beipack, Herstellung), je Weg eine Spalte, Standard hervorgehoben, Staffel nach Menge (1.000 / 3.000 / 10.000 / frei). **Fehlt ein Preis: "Preis fehlt", Summe "unvollständig" – nie still 0.** Darunter die Preis-Matrix. |
| **Dokumente** | Ein Upload für Spec, CoA, Laboranalyse, Zertifikat, Druckvorlage (KI liest Spec/CoA), Liste aller Dokumente, PIB. |

- "Beim Lieferanten anfragen": erst Auswahl **Fertige Kapseln/Tabletten**, **Premix** (lose Mischung je kg, nur Kapsel/Tablette) oder **Rohstoffe einzeln**; Premix-Antworten stehen als Preis je kg an der Rezeptur und zählen im Beschaffungsweg Premix.
- Rechnung: `produkt_kalkulation()` (core/produkt_kalkulation.php), gleiche Bausteine wie der Beschaffungsweg am Auftrag. Herstellung (Lohn) hat noch keinen Satz und wird nicht gerechnet.
- Widersprüche am Produkt zeigt die Datenprüfung (Entwurf trotz Verwendung → Beheben auf "aktiv"; Glas ohne Deckel, Kundenpreis mit anderer Packungsgröße, starke Abweichung Kundenpreis/Rechnung nur Anzeige).

## 5b. Rohstoff-Stammdaten

- **KI-Info (kurz, für den Kunden):** wird dem Kunden im Portal gezeigt, nur auf Knopfdruck.
- **KI-Steckbrief (ausführlich, nur intern):** Knopf „KI-Steckbrief erstellen“ an Rohstoffseite, Kunden-Rohstoffanfrage und Lieferanten-Anfrage. Inhalt: was ist das, Einsatz in NEM, übliche Dosis und Darreichung, Besonderheiten, Regulatorik (Novel Food, Health Claims nur wenn zugelassen, Höchstmengen, Warnhinweise). Gespeichert am Rohstoff mit Datum und Name, per Stift ergänzbar, „neu erstellen“ nur nach Rückfrage. Hinweis „KI-generiert, intern, nicht geprüft“. Nie im Kunden- oder Lieferantenportal.
- **MOQ (unsere Mindestmenge):** Menge + Einheit (meist kg) + optionale Notiz in den Stammdaten. Unabhängig von der Lieferanten-MOQ. Anzeige: Kachel, Spalte in der Rohstoff-Liste, Kundenanfrage und Angebot („MOQ 25 kg", „unter MOQ"; bei Rezepturen die benötigte Menge für die Auftragsmenge). **Intern nur Hinweis, Nico kann abweichen. Im Kundenportal angezeigt und gesperrt**: kleinere Menge lässt sich nicht absenden, Fehlermeldung mit MOQ, Feld auf die MOQ vorbelegt. Ohne MOQ keine Sperre.

---

## 6. "Zu erledigen" - die eine Aufgabenliste

Alles, was ein Mensch im Büro anschauen oder entscheiden muss, landet hier - statt in verstreuten Freigabe-Seiten, die sich gegenseitig blockieren. Jede Zeile: **was**, **welcher Auftrag/welche Charge**, **seit wann**, **wer zuständig** (Nico / Tabea / Einkauf / Lager), **ein Knopf**, der direkt zur Stelle führt oder es erledigt.

Typische Einträge:
- Weg festlegen (neuer Auftrag)
- CoA fehlt / CoA prüfen (Tabea)
- Charge passt nicht zum CoA - Ware in Quarantäne (Nico + Tabea)
- Neuer Artikel beim Wareneingang angelegt - prüfen/zusammenführen
- Etikett nicht freigegeben (beim Kunden nachhaken / im Namen des Kunden freigeben)
- Produktionsabweichung: Gläser/Kapseln passen nicht, Schwund über Toleranz
- Labor auffällig
- Lieferadresse noch nicht bestätigt (Knopf "Kunden erinnern")
- Prüfliste vor Versand nicht grün (mit Grund)
- Überfällige Lieferung

Regeln: Eine Aufgabe verschwindet, sobald die Ursache behoben ist (automatisch) oder jemand sie bewusst abhakt (mit Name/Zeit im Protokoll). Keine Aufgabe ohne Knopf zur Lösung.

---

## 7. Lager-Sicht

- **Menü der Lager-App (gebaut 08.11.2026):** eine flache Liste ohne Gruppen, groß und mit Icons (Tablet/Handy):
  1. **Übersicht**
  2. **Suchen** - eine Suche über Lager 1 und Lager 2 (Artikel, Charge, Barcode, Blinker-Code); jeder Treffer zeigt "Lager 1" bzw. "Lager 2 · Kunde", Reiter Alle / Lager 1 / Lager 2
  3. **Einbuchen** - Kacheln: Lieferschein scannen · Sendungsnummer · Aus Liste wählen (= erwartete Lieferungen, mit Zähler) · Rezepturnummer-Aufkleber · Etiketten einbuchen · Kundenware (Lager 2) · Manuell
  4. **Umbuchen** - Kacheln: Einlagern · Nach Lager 2 · Kisten
  5. **Versand** - Kacheln: Versandbereit (Zähler) · Warenausgang · Lieferschein erstellen
  6. **Lager 1** - Kacheln: Bestand · Quarantäne; Zähler am Menüpunkt = Chargen in Quarantäne
     - Bestand-Tabelle schmal: Finden · Produkt · Menge · Status · Kategorie (Charge klein unter dem Namen; MHD, Eingang, Ort, Etikett auf der Chargen-Seite)
  7. **Lager 2** - Kacheln: Fremdlager-Bestand · Artikel

  Darunter klein: Bewegungen · Mülleimer · Einstellungen (Admin) · Zum Dashboard. Nicht mehr im Menü, aber erreichbar: Blinker (über die Suche), Erwartete Lieferungen (Kachel "Aus Liste wählen"), Kundenware einbuchen (Kachel), die frühere Lager-2-Suche (leitet auf die gemeinsame Suche).
- **Menü der Produktions-App (`/produktion/`, gebaut 08.11.2026):** genauso flach und groß mit Icons: **Übersicht · Produzieren · Aufträge · Kalender · Reinigung · Anleitungen**; darunter klein Archiv · Einstellungen · Werk-App (Mitarbeiter) · Zum Dashboard. Tabellen schmal: **Produkt** (darunter klein PA-Nr · Auftrag · Kunde) · **Menge** · **Wann dran** · **Stand** (produzierbar / wartet auf Material / gesperrt / in Produktion, darunter Schritt x/y). Auf dem Handy wandert "Wann dran" unter den Stand.
- **Gesamtbestand je Artikel** gibt es heute nur im Dashboard (Warenlager `?p=lager`). Die Lager-App zeigt nur Einzelchargen. *Vorschlag, offen (Abschnitt 12):* In der Lager-App im Reiter Bestand Umschalter **"je Artikel / je Charge"** (Artikelzeile mit Summe, unterwegs, Anzahl Chargen, aufklappbar); auf der Charge-Seite "Gesamt im Lager: x in n Chargen" und alle Chargen gelistet. Gleiche Rechnung wie im Dashboard.
- **Umlagern Lager 1 → Lager 2:** mobiler Reiter "Nach Lager 2", Menge + Haken, Charge/MHD nur zur Prüfung, Besitzer aus Auftrag (gebaut). Verkaufbar erst nach grüner Prüfliste (4.13).
- **Altbestand bereinigen:** Einheiten-/Zahlenfehler aus den Oktober-Einbuchungen (Kapsel-Bulk in kg statt Stk, "13.000 kg" statt 13 kg, "6,254 Stk", gemischte Einheiten), doppelte Artikel, lose Bulk-Artikel - über Punkte in der Datenprüfung zum Korrigieren/Zusammenführen.

---

## 8. Rechtlicher Rahmen (Kurzfassung, Details bestätigt QM)

- **Rückverfolgbarkeit Lebensmittel** (VO (EG) 178/2002, Art. 18): eine Stufe vor, eine Stufe zurück - welche Rohstoff-Charge von welchem Lieferanten in welche Fertig-Charge an welchen Kunden. → Chargenkette, Unter-Chargen, Kartonscan beim Versand.
- **Lebensmittelkontaktmaterialien** (VO (EG) 1935/2004, Art. 17): Gläser, PET-Dosen, Deckel müssen rückverfolgbar sein → Lieferung je Produktionsauftrag festhalten (muss nicht als sichtbare Charge im Alltag geführt werden).
- **Eingangskontrolle:** CoA/Spezifikation je Rohstoff-Charge, Abgleich mit Spezifikation (4.4). Stichprobenhafte Prüfung der eingehenden Rohstoffe ist bei bulkify die Grundlage; Fertigprodukt-Labortests sind Zusatzleistung für den Kunden.
- **Herstellprotokoll / Chargendokumentation:** wer, was, wann, welche Mengen, Wiegungen, Chargenwechsel mit Linienräumung (4.8).
- **Rückstellmuster** je (Unter-)Charge.
- **Kennzeichnung:** Charge und MHD auf jeder Verkaufseinheit; Etikett-Version freigegeben vor dem Etikettieren.
- **Sorgfaltspflicht vor Inverkehrbringen:** Prüfliste vor Versand (4.13).
- Kundendokumente (CoA, PIB) sind **claims-neutral**, ohne werbliche oder gesundheitsbezogene Aussagen.

---

## 9. Querverweise auf bestehende Anweisungen

Diese MDs/Rückmeldungen bleiben als Detail-Quelle gültig, soweit sie dem Handbuch nicht widersprechen:
- Kette Auftrag bis Versand (Teile 1-8) + Rückmeldungen `rueckmeldung-kette-1` … `-7`
- Gewicht & Bestand pro Gebinde (+ Rückmeldung)
- Umlagern Lager 1 → Lager 2
- Beschaffungsweg: Bestand & Bestellung koppeln
- Auftragsseite entschlacken (+ Rückmeldung 08.11.)
- Bestellweg "Direkt bestellt"
- Kundenportal: Dokumente & Versand auffindbar
- Lieferantenportal: Auffindbarkeit & Dokumente
- Laborberichte: "Ans Labor senden" (GBA-Formular)
- Etiketten: Update-Etikett erzeugen + Ersatz-Fallback raus
- Produktionsmodul-Konzept (Oktober 2026) - Grundlage für 4.8

---

## 10. Bekannte Fehler / zu prüfen (Stand 08.11.2026)

1. **Fehlende Etiketten blockieren die ganze Produktion** (AB-3289: "Produktion nicht möglich, es fehlt Material", eine Position ist das Etikett). Soll: nur Etikettieren sperren (4.7).
2. **"Rohstoff bestellt" ohne Bestellung** auf Altaufträgen (z. B. AB-3289 seit 06.11.) - Datenprüfung `rohstoff_bestellt_ohne` von Nico abzuarbeiten; Code ist korrigiert. *(08.11.: zusätzlicher Punkt für höher gesetzte Stufen, nur Anzeige.)*
3. ~~Produktion-Reiter: Hinweis "Etikett selbst hochladen → Reiter Material"~~ - **erledigt 08.11.**: Link öffnet Reiter Details und das Pop-up der Kachel Etikett.
4. ~~"Zu Kontingent machen" rechnet mit "3.000 × 9,71 €"~~ - **erledigt 08.11.**: Es gilt der volle Packungspreis (12.4); der Erklärtext zeigt jetzt diesen Preis.
5. 100 ml Weithalsglas: zweimal genau 5.376 Stk (15.10. und 05.11.) - *geprüft 08.11. (live, nur lesend):* zwei freie Einbuchungen ohne Bestellung, Lieferant Packari, Notiz der zweiten mit Lieferanten-Art.-Nr.; 5.376 Stk ist bei Packari eine Preisstaffel (Palette). Spricht für eine echte zweite Lieferung - endgültig nur über Packari-Rechnungen/Lieferscheine zu klären (Nico).
6. Altbestand mit falschen Einheiten/Zahlen (Abschnitt 7).
7. Kartonetikett heute mit "Karton i von n" und einer Charge je Druckvorgang - umstellen auf Druck je vollem Karton, ohne "von n", je Unter-Charge (4.10).
8. Eimerplanung nach Chargenwechsel, Sperre "falscher Eimer", Pflicht-Bestätigung beim Chargenwechsel (inkl. Foto), Gegenprobe Gläser/Kapseln mit Aufgabe ans Büro - gegen den heutigen Stand der Werk-App prüfen, vermutlich noch nicht (vollständig) vorhanden.

---

## 11. Was bewusst NICHT gilt

- Kein PIB vor der Bestellung auf Produkt-/Rezepturseite (Schutz vor Weitergabe der Unterlagen an andere Hersteller).
- Keine separate Produktionsfreigabe für ganze Aufträge (gesteuert über Material je Schritt + Reihenfolge).
- Keine QM-Sperre beim Einbuchen eigener Fertigware.
- Keine Druckerei im System (die Etikettendruckerei hat keinen Zugang; Druckdaten laufen außerhalb).
- Keine automatische Erinnerungsmail an Kunden (Lieferadresse) - Erinnern per Knopf durch das Team.
- Keine Versandsperre wegen offener Rechnung (nur Hinweis).
- Keine weitere Übersicht oben über den Auftragsreitern.

---

## 12. Offene Entscheidungen (Nico)

1. ~~Gehören Bestellen/Einkauf und Lager ins Menü "Einfach"?~~ - **entschieden 08.11.:** ja, Lager dort nur als **ein** Punkt (Abschnitt 3).
2. Reicht vor dem Versand die **grüne Prüfliste**, oder soll Tabea zusätzlich bewusst **"Freigabe"** klicken?
3. **Lager-App:** Ansicht "je Artikel" und Verpackung (Gläser/Dosen/Deckel) ohne sichtbare Alltagschargen - umsetzen?
4. ~~Kontingent-Preis~~ - **entschieden 08.11.:** voller Packungspreis (`gesamt_netto / menge`, bei AB-3289 10,17 €), wie die Funktion schon speichert; nur der Erklärtext wurde korrigiert.
5. ~~GBA-Formular: Berichtsempfänger~~ - **zurückgestellt** (08.11.): Labor läuft heute über unseren Upload, Zusammenarbeit mit einem Labor erst in Zukunft. "Ans Labor senden"/GBA bleiben wie sie sind (kein Ausbau, nichts entfernt); das Feld "Unter-Charge der Probe" ist ebenfalls zurückgestellt.
6. **Kundenpreise-Liste:** Mengen-Staffel je Bestellmenge nötig oder ein Preis je Verpackungsgröße genug?
7. **Benachrichtigungen für alle Rollen?** Heute ein gemeinsames Team-Postfach (mit Kunden/Preisen), nur Admin darf es öffnen. Freigeben für weitere Rollen, oder je Nutzer filtern (jeder sieht nur, was ihn betrifft)?

---

## 13. Einführung: wie Claude Code mit diesem Handbuch startet

**Schritt 1 - Abgleich (nur lesen, nichts bauen):**
Das Handbuch Abschnitt für Abschnitt gegen das Programm prüfen. Rückmeldung als `rueckmeldung-handbuch-abgleich.md`, je Abschnitt eine Tabelle:

| Soll-Punkt (Abschnitt) | Ist im Programm (Seite/Funktion) | Status: passt / fehlt / teilweise / doppelt / widerspricht | Vorschlag |

Besonders melden: Stellen, an denen es **mehrere Wege** für dieselbe Sache gibt; Seiten und Menüpunkte, die nach dem Handbuch überflüssig sind; Abschnitt 10 (bekannte Fehler) mit Ursache.

**Schritt 2 - Menü:** Nach Nicos Freigabe des Abgleichs das Menü "Einfach / Ausführlich" bauen (Abschnitt 3), vorhandene Seiten einhängen, Doppeltes aus dem Menü nehmen.

**Schritt 3 - Lücken schließen:** Die fehlenden und widersprüchlichen Punkte einzeln abarbeiten, wie bisher: eine Anweisung pro Thema, erst Testkopie, dann live, ein Fix = ein Commit, Rückmeldung je Thema. Jede Anweisung nennt den Handbuch-Abschnitt.

---

## 14. Pflege und Änderungsprotokoll

- Neue Entscheidungen werden **hier** eingetragen (im passenden Abschnitt) und unten protokolliert. Erledigte Punkte aus Abschnitt 10/12 werden dort gestrichen bzw. in den Ablauf übernommen.
- Wer ändert: Nico entscheidet; Claude (Chat) oder Claude Code tragen ein und vermerken es hier.

| Datum | Änderung | von |
|---|---|---|
| 08.11.2026 | Erstfassung: Kette ab Auftrag, Menü Einfach/Ausführlich, Zu erledigen, Eimer/Unter-Chargen, Chargen-Abgleich blind, Kartonetikett je vollem Karton, Lieferadresse bestätigen, Prüfliste vor Versand | Nico / Claude |
| 08.11.2026 | 4.12: Labor heute nur per eigenem Upload; "Ans Labor senden"/GBA zurückgestellt (12.5) | Nico / Claude |
| 08.11.2026 | Schritt 2: Menü Einfach/Ausführlich gebaut (3); Entscheidungen 12.1 (Einkauf + Lager in Einfach, Lager ein Punkt), 12.4 (voller Packungspreis), 12.5 inkl. Feld Unter-Charge der Probe zurückgestellt; Fixes 10.2/10.3/10.4, 4.9 Meldetexte, 4.11 Easy Production frei, 4.12 Labor-Befund/charge_norm, 4.5 Erwartete Lieferungen + Schnellmaske stillgelegt; 10.5 geprüft | Nico / Claude Code |
| 08.11.2026 | Sofort-Fix Etikett-Format: 4.5 Etikett-Maß vs. Glas nur Hinweis, nie Sperre; Maß neuer Etiketten aus der TrimBox; Menü Einfach zeigt Buchhaltung für Rolle finance (3) | Nico / Claude Code |
| 08.11.2026 | Sofort-Fix Bestellliste: 4.3 Kundenetiketten/bedruckte Beutel netto gegen Lager und unterwegs, eine Rechnung mit Reiter Material und Fortschritt | Nico / Claude Code |
| 08.11.2026 | 4.4: Chargen & Dokumente (Charge, MHD, CoA, Spezifikation) an der Bestellposition, Team + Lieferant, Weitergabe an die Charge beim Wareneingang; Lieferantenrechnung auch durch das Team | Nico / Claude Code |
| 08.11.2026 | 4.4: CoA an der Bestellung wird automatisch ausgelesen, Prüf-Popup mit markierten Abweichungen | Nico / Claude Code |
| 08.11.2026 | 7: Menü der Lager-App flach mit Icons, Kachel-Seiten Einbuchen/Umbuchen/Versand/Lager 1/Lager 2, eine Suche für Lager 1 + 2 | Nico / Claude Code |
| 08.11.2026 | 7: Produktions-App aufgeräumt - Menü flach mit Icons, schmale Tabellen (Produkt · Menge · Wann dran · Stand) | Nico / Claude Code |
| 08.11.2026 | 3: Menü "Einfach" flache Liste ohne Gruppen, größer, feste Reihenfolge, Linien-Icons | Nico / Claude Code |
| 08.11.2026 | 3: Benachrichtigungen als erster Punkt in "Einfach" (Glocke mit Zähler), Eintrag unten entfällt dort | Nico / Claude Code |
| 08.11.2026 | 3: Menüpunkt "Bestellt" (Bestellungen) in "Einfach" unter Einkauf | Nico / Claude Code |
| 09.11.2026 | Nachbestellungen: im Portal unter Bestellungen sichtbar, solange sie auf die Preisprüfung warten; Hinweis in der Auftragsliste; ohne Preis am Vorauftrag gilt der Kundenpreis, Freigabe nie mit 0 € | Nico / Claude Code |
| 09.11.2026 | Nachbestellungen prüfen: je Nachbestellung Info-Block mit Konfiguration (Behälter/Deckel/Etikett verlinkt, Vorauftrag, Etikett-Freigabe), Preis-Herkunft (Angebote, Vorauftrag, letzte Rechnung, Kundenpreis, EK + Marge) und früheren Aufträgen; Produkt verlinkt. |
| 09.11.2026 | Produktseite: Knopf „Beim Lieferanten anfragen“ im Seitenkopf (Fertigprodukt-Anfrage der Rezeptur, gleiches Popup wie Angebot/Rezeptur) mit Status-Badge. |
| 09.11.2026 | Produktseite aufgeräumt (neuer Abschnitt 5a): Kopf + Kacheln per Stift + Reiter Rezeptur/Kunden/Beschaffung/Produktion/Kalkulation/Dokumente; Kalkulation je Weg aufgeschlüsselt, "Preis fehlt" statt 0; Suchfelder; vier Prüfungen in der Datenprüfung. |
| 10.11.2026 | Beschaffungsweg am Auftrag: jede Karte zeigt den vollen Bedarf (kg/Stk je Rohstoff) und, wenn schon Ware zugeordnet ist, zusätzlich den Rest. Kosten weiter für den Rest. |
| 10.11.2026 | Produktseite "Beim Lieferanten anfragen": Auswahl Fertige Kapseln/Tabletten, Premix (je kg) oder Rohstoffe; Premix-Antwort wird an der Rezeptur als Preis je kg gespeichert und nie als Stückpreis gelesen. |
| 10.11.2026 | Einkauf gerade ziehen Teil 1-3: Wareneingang immer gegen eine Bestellung (eine Funktion für Dashboard + Lager-App), Bestellposition fest je Zeile, ohne Bestellung nur mit Grund (alte Bestellung -> nachträgliche Bestellung); gelieferte Menge = Summe der Chargen, Bestellung schließt sich selbst, "Rest storniert" (4.5) | Nico / Claude Code |
| 10.11.2026 | Einkauf gerade ziehen Teil 4: Altlasten - Block "Wareneingang zuordnen" an der Bestellung mit Vorschlägen, Prüfpunkte in der Datenprüfung (ohne Wareneingang, eingegangen ohne Charge, nachträgliche Bestellungen, alte Entwürfe, ohne Lieferant, ohne Position) (4.5) | Nico / Claude Code |
| 10.11.2026 | Einkauf gerade ziehen Teil 6: Pop-up "Weg festlegen" als einzige Oberfläche (Karten des Reiters Beschaffungsweg inkl. Lager, Rezeptur je Auftrag, Links); alte Eigen/Fremd-Schalter entfernt (4.2) | Nico / Claude Code |
| 10.11.2026 | Einkauf gerade ziehen Teil 7: Premix anfragen von Rezeptur, Produkt und Beschaffungsweg; Antwort als Lieferantenpreis je kg am Premix-Artikel "Premix · <Rezeptur>" (4.3) | Nico / Claude Code |
| 10.11.2026 | Einkauf gerade ziehen Teil 5/8: Regel Rohstoff-Dubletten + Kapselgröße, Seite "Artikel zusammenführen", Synonyme; Einkaufsseiten mit deutscher Zahleneingabe, Artikelsuche, drei Bestellwege, Links in "Bestellt" (4.5) | Nico / Claude Code |
| 10.11.2026 | Kundenportal: Rezeptur-Vorschläge unter "Zu bestätigen" (kein Extra-Kasten "Wartet auf Sie"); Prüf-Ansicht mit Form, Gewicht je Portion, eigener Anfrage und Nährstoffen mit % NRV; kein Löschen bei Vorschlägen. Datenprüfung: Rohstoffe ohne Wirkstoffdaten | Nico / Claude Code |
| 10.11.2026 | Anfragen: fehlende Lieferanten-Unterlagen (Spec/CoA) der Rohstoffe sichtbar - Spalte "Unterlagen" in der Anfragen-Liste, Kasten mit Sammel-Anfrage auf Anfrage- und Rezepturseite | Nico / Claude Code |
| 10.11.2026 | Menü Anfragen -> "Rohstoff-Unterlagen": Übersicht aller Rohstoffe aus laufenden Anfragen und Rezepturen (Entwurf/Vorschlag, mit Form) mit Preis fehlt, Dokument fehlt, "NRV nachtragen" (Mineralstoff/Vitamin ohne Wirkstoffdaten), Stand beim Lieferanten; Sammel-Anfrage per Häkchen | Nico / Claude Code |
| 10.11.2026 | Rohstoff-Anfrage: "Alternative anbieten" - Lieferstatus mit Hinweis, Alternativen aus dem Katalog mit Begründung/Richtpreis, Kunde wählt im Portal unter "Zu bestätigen" (Anfrage wechselt auf die Alternative, Original bleibt gemerkt) oder bleibt beim Original; Mail-Vorlage rohstoff_alternative | Nico / Claude Code |
| 10.11.2026 | Pop-up "Preis & Unterlagen" (Rohstoff-Unterlagen, Unterlagen-Kasten, Rohstoff-Anfrage): Lieferantenpreis ab Menge eintragen, CoA/Spec hochladen, KI füllt Kennwerte, Grenzwerte, Wirkstoffe und leere Stammdaten | Nico / Claude Code |
| 10.11.2026 | Produktseite: neuer Reiter "Bestellungen" - wer hat das Produkt gekauft (je Auftrag Kunde, Menge, Status, Fertigprodukt-Charge mit MHD), Suche, stornierte zuschaltbar | Nico / Claude Code |
| 10.11.2026 | Auftrag, Schritte: "Lieferant" bei Etikettiert/Verpackt/Qualitätsprüfung nur bei Zukauf fertiger Ware (zukauf_art fertig), sonst "wir"; erledigte Schritte ohne eigene Meldung zeigen "ohne Datum, mit späterem Schritt abgehakt" | Nico / Claude Code |
| 10.11.2026 | Auftrag, Schritte: "abgeschlossen von ..." je erledigtem Schritt aus vorhandenen Daten (Produktionsschritt erledigt_von, Laborbericht, Fortschritt von Hand gesetzt); unbekannt = keine Angabe | Nico / Claude Code |
| 10.11.2026 | Lieferanten: neue Liefer-Kategorie "Leerkapseln" (leerkapsel) für Lieferanten, die nur Leerkapseln verkaufen (Stammdaten, Partner, Bewerbung DE/EN/ZH, Liste mit Klartext); Preisanfrage an einer Leerkapsel zeigt Leerkapsel- und Rohstoff-Lieferanten, Rohstoff-Anfragen keine reinen Leerkapsel-Lieferanten | Nico / Claude Code |
| 11.11.2026 | Lieferanten-Portal: Einheiten (Kapsel, Tablette, Stick ...) und freigeschaltete Formen werden in der Sprache des Lieferanten angezeigt (Fertigprodukt-Preise, Preisliste, Anfragen, Katalog, Bestellung); Form-Spalte der Fertigprodukt-Preise ohne falsches "Rohstoff" | Nico / Claude Code |
| 11.11.2026 | Lieferanten-Portal, Fertigprodukt-Preise: alle eingetragenen Preise werden mit einem Klick gespeichert (vorher nur die geklickte Zeile, Rest ging verloren); "Alle Preise speichern", Warnung bei ungespeicherten Eingaben, Meldung bei nicht lesbarem Preis; Zahlen aus chinesischer Eingabe (Vollbreite, €/¥) werden gelesen; Team bekommt Benachrichtigung | Nico / Claude Code |
| 11.11.2026 | Rohstoff: KI-Steckbrief (nur intern) an Rohstoffseite, Kunden- und Lieferanten-Rohstoffanfrage; gespeichert am Rohstoff, Stift, neu erstellen mit Rückfrage; neuer Abschnitt 5b Rohstoff-Stammdaten | Nico / Claude Code |
| 11.11.2026 | Rohstoff: eigene MOQ je Rohstoff (Stammdaten, Kachel, Liste, Kundenanfrage, Angebot inkl. Rezeptur-Bedarf); intern nur Hinweis, Kundenportal zeigt und sperrt (Browser + Server); Einzelformular auf der Portal-Rohstoffseite kommt wieder an | Nico / Claude Code |
