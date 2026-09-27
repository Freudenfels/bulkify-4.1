# bestand/liste.php – Bestand (`?p=bestand`)

Startseite des Lagers. Zeigt alle eigenen Chargen (kein Fremdlager), nach Kategorie getrennt:

- **Reiter:** Alle · Rohstoffe · Kapseln · Verpackung · Verbrauch · Fertigware, jeweils mit Anzahl. Kapseln = Rohstoff mit Form `kapselhuelle`.
- **Spalten:** Rohstoff/Produkt (+ Artikelnummer), Kategorie (nur im Reiter „Alle“), Charge, **MHD** (Ampel: rot abgelaufen, orange unter 60 Tagen), Bestand, Status (frei/Quarantäne/gesperrt), Blinker.
- **Live-Suche** über Rohstoff, Artikelnummer, Charge.
- Häkchen **auch leere zeigen** blendet ausgebuchte Chargen ein.
- **Jede Zeile ist anklickbar** und führt zur Detailansicht (`?p=charge&id=`).

Der Bestand kommt über `erp_bestand()` aus dem geteilten Dashboard-Bestand (`charge`/`item`).
