# tools/ek_import.php – EK-Preislisten aus CSV einlesen

Liest zwei CSV-Formen in die Staging-Tabelle `ek_import`:

- **Rohstoff/Bulk** (`--roh=`): Spalte 0 Name, Spalte 3 EUR/kg, Spalte 4 Lieferant.
- **Fertigprodukt** (`--fertig=`): Spalte 1 Produkt, 2 Formulierung, 3 Größe,
  4 Kapselpreis (EUR/Kapsel), 5 Menge, 6 Lieferant.

Deutsche Zahlen werden korrekt geparst (`0,0362 €` → 0.0362, `4.536,00 €` → 4536).
Idempotent über `zeile_hash` (typ|name|lieferant|groesse|preis); doppelte CSV-Zeilen
werden übersprungen. Lieferant/Größe werden auf die Spaltenbreite gekappt (manche
CSV-Lieferanten sind ganze Alibaba-URLs).

## Aufruf
```
php tools/ek_import.php --roh="…/EK Preise.csv" --fertig="…/Fertigprodukte.csv"          # Trockenlauf
php tools/ek_import.php --roh="…" --fertig="…" --write                                    # schreiben
php tools/ek_import.php --reset --write                                                    # Staging leeren
```

## Danach
Die Rohnamen sind noch NICHT zugeordnet (`item_id`/`produkt_id` NULL). Die Zuordnung
passiert nachgelagert (manuell oder KI-gestützt auf beta). Erst bestätigte
Rohstoff-Zeilen werden zu `lieferant_preis` (id-verknüpft) und füllen dann „Preis ab"
und den Lieferanten-Marker in der Rohstoffliste. Fertigprodukt-Zeilen bilden die
interne Fertigprodukt-Preisliste (Zukauf, nie Kundensicht).

Lokal eingelesen: 646 Rohstoff-EK + 179 Fertigprodukte (825 gesamt).
