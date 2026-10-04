# bestand/liste.php – Bestand (`?p=bestand`)

Startseite des Lagers. Zeigt alle eigenen Chargen (kein Fremdlager), nach Kategorie getrennt:

- **Reiter:** Alle · Rohstoffe · Verpackung · Verbrauch · Bulk / lose · Fertige Produkte, jeweils mit Anzahl. **Leerkapseln** haben keinen eigenen Reiter – sie sind ein Rohstoff (Form `kapselhuelle`, nur in Stück) und laufen unter „Rohstoffe". **Bulk / lose** = lose fertige Kapseln/Tabletten (`kategorie=fertig`, auch zugekauft); **Fertige Produkte** = fertig verpackt (`kategorie=verkaufsfertig`).
- **Spalten:** Rohstoff/Produkt (+ Artikelnummer), Kategorie (nur im Reiter „Alle“), Charge, **MHD** (Ampel: rot abgelaufen, orange unter 60 Tagen), Bestand, Status (frei/Quarantäne/gesperrt), Blinker.
- **Live-Suche** über Rohstoff, Artikelnummer, Charge.
- Häkchen **auch leere zeigen** blendet ausgebuchte Chargen ein.
- **Jede Zeile ist anklickbar** und führt zur Detailansicht (`?p=charge&id=`).

Der Bestand kommt über `erp_bestand()` aus dem geteilten Dashboard-Bestand (`charge`/`item`).

**Kisten-Ansicht:** Umschalter **„Nur Kisten"** (`?p=bestand&nur_kisten=1`) zeigt statt der Chargenliste
die **Kisten** (`kiste_alle()`) als ausklappbare `<details>`-Blöcke mit Inhalt (`kiste_inhalt()`:
Produkt · Charge · MHD · Menge · Fach) und je Kiste einen **„Kiste finden"**-Knopf (`data-klingeln`).
