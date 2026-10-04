# leiste/suche.php – JSON-Suche für die Sprachbedienung (`?p=suche`)

Liefert die Chargen-Treffer zu `q` als JSON, ohne Seitenwechsel, damit die Spracherkennung durchlaufen kann. Je Treffer: Rohstoffname, Artikelnummer, Chargennummer, Restmenge, und ob eine Blinker dranhängt (`leiste_id`, `leiste`). Gleiche Quelle wie die normale Suche (`erp_chargen_suche`, nur eigener Bestand). Aufgerufen von `public/lager/assets/voice.js` und der Such-Seite (`finden.php`).

Erkennt zusätzlich: (1) einen **QR-Code vom Etikett** (URL mit `…&id=<charge_id>`) → direkt diese Charge; (2) einen **Blinker-Code** (`leiste_per_code`) → das an den Blinker gebundene Produkt bzw. bei Kisten alle Chargen der Kiste. So findet man Produkte, die man nicht mit Aufklebern versehen kann, über ihren Blinker.
