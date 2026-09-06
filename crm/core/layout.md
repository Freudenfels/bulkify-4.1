# layout.php – der Rahmen jeder Seite

## Wozu
Das CRM sieht aus wie das Dashboard. Kein eigenes Aussehen: Es lädt **dasselbe Stylesheet**
(`/assets/app.css`) und benutzt dieselben Klassen – `bx-shell`, `bx-side`, `bx-panel`, `btn`,
`bx-field`, `bx-grid`. Wer dort an Farben oder Abständen etwas ändert, ändert es hier mit.

Danach kommt `assets/crm.css` mit dem Wenigen, das es dort nicht gibt: die **Wartezeilen**
(`crm-zeile`, `crm-alter`, `crm-mitte`) und die Reiter. Alles mit `crm-` davor – so sieht man auf
einen Blick, was NICHT aus dem Dashboard kommt.

## Menü
Links die dunkelgrüne Leiste wie im Dashboard, mit Gruppen (Start, Kontakte, Kunden, System). Am
Punkt **Wer wartet auf mich** steht die Zahl der offenen Fälle als `bx-navbadge` – der einzige
Grund, die App zu öffnen. Unten der Benutzerkasten mit Abmelden und dunklem Modus.

Gibt es unter Einstellungen eine Adresse fürs Dashboard, steht dort zusätzlich **Zum Dashboard**.

## Handy
Bis 860 px liegt das Menü als Schublade über dem Inhalt, aufgeklappt über den Burger oben links –
dasselbe Markup und dasselbe Verhalten wie im Dashboard (`bx-mobilbar`, `bx-menuescrim`,
`data-menue="auf"`). Das CSS dafür kommt aus dessen `app.css`.

## Dunkler Modus
Dieselbe Speicherstelle wie im Dashboard (`localStorage` `bx-theme`). Wer dort umschaltet, hat es
hier auch dunkel – es ist dieselbe Domain und dieselbe Einstellung.

## Warum nicht einfach die layout.php des Dashboards einbinden?
Weil sie `h()` und `fmt_zeit()` definiert, die das CRM auch hat – das gäbe einen Fehler wegen
doppelter Funktionen. Deshalb sind Burger und Theme-Umschalter hier noch einmal ausgeschrieben,
zwei kurze Blöcke. Der Rest, und das ist das meiste, kommt wirklich aus dem gemeinsamen Stylesheet.