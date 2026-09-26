# system/fastaction.php – Fastaction (KI-Schnell-Posteingang)

**Zweck:** Kurze, wichtige Nachricht (oft eine Kundenanfrage, z. B. „Kunde X will 1.000 Dosen von Rezeptur Y nachbestellen – bitte Angebot") plus optional Datei/Bild reinwerfen. Das System (1) legt **automatisch eine Aufgabe** an (damit nichts verloren geht) und (2) lässt **Claude** die Nachricht auswerten und **konkrete nächste Schritte** vorschlagen. Route `?p=fastaction`, Menü **Assistent → Fastaction** (ganz unten), nur **admin**.

**Ablauf (POST `analysieren`):**
1. Optionale Datei wird gespeichert (für die KI und als Anhang der Aufgabe, `dokument objekt_typ='aufgabe'`).
2. `fastaction_analyse($text,$pfad)` (core/fastaction.php) ruft die KI (`ki_json` bzw. `ki_datei_frage`, JSON) und liefert: `zusammenfassung`, `aufgabe`, `dringlichkeit`, `erkannt` (kunde/rezeptur/produkt/menge/einheit), `vorschlaege[]` (Text + Typ).
3. **Aufgabe** wird angelegt (`aufgabe_neu`, Titel = KI-„aufgabe" bzw. gekürzter Text, Priorität aus Dringlichkeit, `ref_typ='fastaction'`) – **auch wenn die KI nicht verfügbar ist** (lokal): dann nur der Text, ohne Vorschläge.
4. Anzeige: Auswertung (Worum es geht / Zu tun + Dringlichkeit-Badge), **erkannte Entitäten** als Links (`fastaction_aufloesen` matcht Namen → Kunde/Rezeptur/Produkt) und die **Vorschläge** mit Sprungzielen (Kunde/Rezeptur öffnen). Der Mensch entscheidet und legt an – es wird nichts automatisch verschickt.

**KI nur auf beta** (Anthropic-Schlüssel serverseitig): lokal erscheint ein Hinweis; die Aufgabe wird trotzdem erfasst. Kernlogik + Prompt in `core/fastaction.php`.

## Notepad (persistente ToDo-Listen) + Rezeptur-Entwurf
Jede ausgewertete Fastaction-Anfrage legt zusätzlich eine **Fastaction-Notiz** (`fastaction_notiz`) mit
abhakbaren **ToDo-Items** (`fastaction_item`, je Vorschlag eins) an – so geht nach dem Auswerten nichts
verloren. Der Bereich „Notepad" listet offene Notizen (mit `&alle=1` auch erledigte); Items einzeln abhaken
(`item_toggle`, Notiz wird automatisch „erledigt", wenn alle Punkte erledigt sind), eigene Punkte hinzufügen
(`item_add`), Notiz erledigen/wieder öffnen (`notiz_status`) oder löschen (`notiz_del`).

Erkennt die KI eine **Rezeptur**, die es noch nicht gibt (`rezepturen[]` aus dem Prompt), bietet die Seite
„Rezeptur anlegen" an: `rezeptur_entwurf` legt eine Rezeptur als **Entwurf** an (Name + Darreichungsform, die
Zutaten stehen als Text in der Notiz) und öffnet sie – die Zutaten-Zeilen baut ein Mensch fertig.

## Rezeptur-Entwurf: vorausgefüllte Zutaten-Zeilen (Update)
`rezeptur_entwurf` legt die Rezeptur jetzt mit **echten Zutaten-Zeilen** an (nicht mehr nur Text): Die KI liefert
je Rezeptur `zutaten[]` (bezeichnung + menge_mg); je Zeile wird per `rezeptur_ki_item_finden()` ein **Rohstoff
vorgeschlagen** (item_id), sonst bleibt die Zeile mit Bezeichnung/Menge zum manuellen Zuordnen. Der Editor öffnet
mit Banner „X von Y Zutaten automatisch zugeordnet – bitte prüfen". Zutaten-Fliesstext bleibt zusätzlich in der Notiz.
