# Gebindetypen / Tara (`?p=tara`, nur Admin)
Leergewicht je Gebindetyp (Sack klein/groß, Karton, Eimer, Fass …). Beim Wiegen in der Inventur ist der Wert vorbelegt und änderbar. Netto = Brutto − Tara. Bei Bulk-Kapseln gilt: Kapseln = Netto ÷ Kapselgewicht.

**Tara als Gewicht (Fable-4 T1.6, 13.11.2026):** Tara-Werte werden mit `gewicht_lesen()` gelesen („1.250“ = 1,25 kg, mit Rückfrage über `data-gewicht`) und ohne Tausenderpunkt vorbelegt (`gewicht_feld()`).
