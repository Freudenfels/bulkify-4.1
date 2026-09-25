# system/fastaction.php – Fastaction (KI-Schnell-Posteingang)

**Zweck:** Kurze, wichtige Nachricht (oft eine Kundenanfrage, z. B. „Kunde X will 1.000 Dosen von Rezeptur Y nachbestellen – bitte Angebot") plus optional Datei/Bild reinwerfen. Das System (1) legt **automatisch eine Aufgabe** an (damit nichts verloren geht) und (2) lässt **Claude** die Nachricht auswerten und **konkrete nächste Schritte** vorschlagen. Route `?p=fastaction`, Menü **Assistent → Fastaction** (ganz unten), nur **admin**.

**Ablauf (POST `analysieren`):**
1. Optionale Datei wird gespeichert (für die KI und als Anhang der Aufgabe, `dokument objekt_typ='aufgabe'`).
2. `fastaction_analyse($text,$pfad)` (core/fastaction.php) ruft die KI (`ki_json` bzw. `ki_datei_frage`, JSON) und liefert: `zusammenfassung`, `aufgabe`, `dringlichkeit`, `erkannt` (kunde/rezeptur/produkt/menge/einheit), `vorschlaege[]` (Text + Typ).
3. **Aufgabe** wird angelegt (`aufgabe_neu`, Titel = KI-„aufgabe" bzw. gekürzter Text, Priorität aus Dringlichkeit, `ref_typ='fastaction'`) – **auch wenn die KI nicht verfügbar ist** (lokal): dann nur der Text, ohne Vorschläge.
4. Anzeige: Auswertung (Worum es geht / Zu tun + Dringlichkeit-Badge), **erkannte Entitäten** als Links (`fastaction_aufloesen` matcht Namen → Kunde/Rezeptur/Produkt) und die **Vorschläge** mit Sprungzielen (Kunde/Rezeptur öffnen). Der Mensch entscheidet und legt an – es wird nichts automatisch verschickt.

**KI nur auf beta** (Anthropic-Schlüssel serverseitig): lokal erscheint ein Hinweis; die Aufgabe wird trotzdem erfasst. Kernlogik + Prompt in `core/fastaction.php`.
