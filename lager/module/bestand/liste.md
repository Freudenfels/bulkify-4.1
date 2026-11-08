# bestand/liste.php – Bestand (`?p=bestand`)

Startseite des Lagers. Zeigt alle eigenen Chargen (kein Fremdlager), nach Kategorie getrennt:

- **Reiter:** Alle · Rohstoffe · Verpackung · Verbrauch · Bulk / lose · Fertige Produkte, jeweils mit Anzahl. **Leerkapseln** haben keinen eigenen Reiter – sie sind ein Rohstoff (Form `kapselhuelle`, nur in Stück) und laufen unter „Rohstoffe". **Bulk / lose** = lose fertige Kapseln/Tabletten (`kategorie=fertig`, auch zugekauft); **Fertige Produkte** = fertig verpackt (`kategorie=verkaufsfertig`).
- **Spalten (schmal, Nico 08.11.2026):** **Finden** zuerst (Knopf, lässt den Blinker blinken; sonst "kein Blinker"), dann **Produkt** (darunter klein: Artikelnummer · Charge · Bezug), **Menge**, **Status** (frei/Quarantäne/gesperrt, ggf. "in Produktion" bei `charge.standort='produktion'`), **Kategorie** (nur im Reiter "Alle"). MHD, Eingang, Ort und Etikett stehen auf der Chargen-Seite (Zeile antippen). Lange Namen brechen um, damit die Tabelle nicht breiter als der Bildschirm wird. Auf dem Handy (Karten): Name oben, Finden-Knopf unten über die volle Breite.
- **Live-Suche** über Rohstoff, Artikelnummer, Charge.
- Häkchen **auch leere zeigen** blendet ausgebuchte Chargen ein.
- **Jede Zeile ist anklickbar** und führt zur Detailansicht (`?p=charge&id=`).

Der Bestand kommt über `erp_bestand()` aus dem geteilten Dashboard-Bestand (`charge`/`item`).

**Kisten-Ansicht:** Umschalter **„Nur Kisten"** (`?p=bestand&nur_kisten=1`) zeigt statt der Chargenliste
die **Kisten** (`kiste_alle()`) als ausklappbare `<details>`-Blöcke mit Inhalt (`kiste_inhalt()`:
Produkt · Charge · MHD · Menge · Fach) und je Kiste einen **„Kiste finden"**-Knopf (`data-klingeln`).

Unter dem Artikel eine Zeile „Größe · für AB-… · Kunde · reserviert: AB-…“ (Soll-Kette 3, 04.11.2026) – das Lager sieht, wofür die Ware ist.
