# Zählen (`?p=inventur_zaehlen&id=`)

Seit 11.11.2026. Für Handy und Tablet, mit großen Knöpfen. Gescannt wird mit dem Handscanner, eingetippt oder mit der Kamera (BarcodeDetector, z. B. Chrome auf Android).

Der Zähler sieht **keine Soll-Mengen**.

Ablauf:
1. Platz scannen oder aus der Liste wählen.
2. Jedes Gebinde scannen. Angezeigt werden Artikel, Charge, MHD und die Einheit groß.
3. Menge eingeben:
   - Stk eingeben oder „voll, original verschlossen“
   - wiegen: Brutto, Gebindetyp/Tara, Netto wird live gerechnet. Die Auswahl sendet die ID des Gebindetyps; ein von Hand geändertes Tara-Feld wird als Zahl mitgeschickt.
   - Bulk-Sack: Kapselgewicht, Kapseln werden live gerechnet
   - „Gebinde leer“
4. Ware ohne Etikett: „Gefunden ohne Etikett“. Erfasst werden Artikelsuche mit Synonymen und Kapselgröße, Charge, MHD, Menge und ein Pflichtfoto. Danach wird das Etikett gedruckt.
5. „Platz fertig“.

Die Liste „Auf diesem Platz erfasst“ zeigt die eigenen Ist-Werte. Offene Nachzählungen erscheinen für eine zweite Person.

Nach einer Unterbrechung merkt sich das Gerät den zuletzt offenen Platz. Alle Daten liegen sofort auf dem Server.

**Gewichte (13.11.2026, Fable-3 T1.2):** Gewichts- und Wiegefelder lesen über `gewicht_lesen()` (core/gewicht.php): Punkt und Komma sind Dezimaltrenner („25.125“ kg = 25,125; „6.482“ g = 6,482; „25,000“ = 25), nur wenn beide vorkommen, gilt das letzte. Vorbelegungen ohne Tausenderpunkt (`gewicht_feld()`). Stückzahlen bleiben bei der Mengen-Lesart („12.000“ Stk = 12000). Die Netto-Anzeige rechnet im Browser mit derselben Regel (`gew()`), Tara und mg je Kapsel sind ohne Tausenderpunkt vorbelegt.
