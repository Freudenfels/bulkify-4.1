# leiste/suche.php – JSON-Suche für die Sprachbedienung (`?p=suche`)

Liefert die Chargen-Treffer zu `q` als JSON, ohne Seitenwechsel, damit die Spracherkennung durchlaufen kann. Je Treffer: Rohstoffname, Artikelnummer, Chargennummer, Restmenge, und ob eine Leiste dranhängt (`leiste_id`, `leiste`). Gleiche Quelle wie die normale Suche (`erp_chargen_suche`, nur eigener Bestand). Aufgerufen von `public/lager/assets/voice.js`.
