# ki_job.php – KI-Arbeit im Hintergrund

## Wozu
Eine KI-Anfrage dauert leicht eine Minute. Schickt ein Kunde im Portal seine Idee ab, darf er nicht so lange auf die Bestätigung warten – und der Webserver bricht die Verbindung ohnehin irgendwann ab, obwohl die Anfrage längst gespeichert ist.

Der sichtbare Aufruf speichert deshalb nur und **klopft** an einer zweiten Adresse an (`?p=ki_job`). Der zweite Aufruf rechnet weiter, während der Kunde schon seine Seite sieht. Kein Cron, keine Warteschlange, keine zusätzliche Software.

## Ablauf
1. Kunde sendet die Rezepturanfrage → sie wird gespeichert.
2. `ki_job_starten('rezeptur', $id)` ruft `?p=ki_job&art=rezeptur&id=…&s=…` mit **0,4 s Timeout** auf. Der Timeout ist der Normalfall: Wir wollen nur, dass der Server den Aufruf annimmt.
3. Der Kunde bekommt sofort seine Bestätigung.
4. Im zweiten Aufruf sendet `module/system/ki_job.php` sofort „ok", schließt die Antwort ab (`ki_antwort_abschliessen()`) und entwickelt danach den Vorschlag. Das Team findet ihn beim Öffnen der Anfrage vor.

## Sicherheit
Die Route ist **ohne Login** erreichbar (sie kommt vom Server selbst). Dafür braucht sie den Schlüssel `s` = HMAC aus Art + ID + unserem API-Schlüssel. Von außen nicht zu erraten, und er passt immer nur auf genau **einen** Vorgang. Ohne KI-Schlüssel im System passiert gar nichts (`ki_bereit()`).

## Grenzen
- Geht der zweite Aufruf verloren (Server neu gestartet, Netz weg), fehlt der Entwurf. Das Team drückt dann in der Anfrage auf **Vorschlag entwickeln** – gleiches Ergebnis.
- Nur für Arbeiten, deren Ergebnis niemand sofort sehen will. Wo der Benutzer wartet und zusieht (Spec auslesen, „Vorschlag entwickeln"), wird bewusst direkt gerechnet.

## Erweitern
Neue Art in `ki_job_ausfuehren()` ergänzen und mit `ki_job_starten('…', $id)` anstoßen. Mehr ist nicht nötig.
