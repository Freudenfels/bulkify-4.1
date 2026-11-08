# leiste/suche.php – JSON-Suche für die Sprachbedienung (`?p=suche`)

Liefert die Chargen-Treffer zu `q` als JSON, ohne Seitenwechsel, damit die Spracherkennung durchlaufen kann. Je Treffer: Rohstoffname, Artikelnummer, Chargennummer, Restmenge, und ob eine Blinker dranhängt (`leiste_id`, `leiste`). Gleiche Quelle wie die normale Suche (`erp_chargen_suche`, nur eigener Bestand). Aufgerufen von `public/lager/assets/voice.js` und der Such-Seite (`finden.php`).

Erkennt zusätzlich: (1) einen **QR-Code vom Etikett** (URL mit `…&id=<charge_id>`) → direkt diese Charge; (2) einen **Blinker-Code** (`leiste_per_code`) → das an den Blinker gebundene Produkt bzw. bei Kisten alle Chargen der Kiste. So findet man Produkte, die man nicht mit Aufklebern versehen kann, über ihren Blinker. (3) einen **Inventaraufkleber** (genau 7 Ziffern, `erp_rolle_per_inventar`) → die Etikettenrolle; der Treffer trägt `href` auf `?p=gebinde&inv=<nr>` (kein Blinker zum Klingeln, sondern Detailseite).

## 08.11.2026
Jeder Treffer trägt `lager` (`L1`/`L2`) und `kunde` (Besitzer bei Lager 2). Nach den Lager-1-Treffern (`erp_chargen_suche`) folgen Lager-2-Treffer (`erp_chargen_suche_fremd`) – Lager 1 zuerst, damit die Sprachsuche weiter den ersten Treffer nimmt.
