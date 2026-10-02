# bestand/l2_bestand.php – Lager 2 (Fremdlager) Bestand

Zeigt die Chargen, die **einem Kunden gehören** (`charge.fremd_kunde_id` gesetzt) = Lager 2. Reiter je
Kunde (`erp_bestand_fremd_kunden()`), Suche, „auch leere zeigen". Spalten: Kunde · Produkt · Charge ·
MHD · Bestand · Status · Ort (Blinker/Kiste) · Etikett. Zeile klickbar → Charge-Detail. Daten:
`erp_bestand_fremd($kunde_id,$q,$mit_leer)`. Gegenstück zu Lager 1 ([liste.md](liste.md)).
