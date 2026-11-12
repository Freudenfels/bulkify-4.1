# core/zahl_lesen.php

Eine Regel zum Lesen getippter Zahlen für Dashboard, CRM und Buchhaltung (Nachbesserung Fable-Prüfung 3, 13.11.2026). Ohne Abhängigkeiten; `core/schema.php`, `crm/core/kontakt.php` und `buchhaltung/core/config.php` laden dieselbe Datei. Vorher stand `zahl_lesen()` in core/schema.php, das CRM hatte eine eigene `zahl_lesen()` mit anderer Regel („4.800“ = 4,8) und die Buchhaltung las Beträge mit `(float) str_replace(',', '.')` („21.687,75“ = 21,687).

- **`zahl_lesen($roh, $mengenfeld = false, $sprache = 'de')`** (unverändert):
  - Preis-Lesart (Standard): Komma = Dezimal, beide Trennzeichen = das letzte ist Dezimal, „1.500“ = 1,5, „1.085“ (Kurs) = 1,085.
  - Mengen-Lesart (`$mengenfeld = true`): zusätzlich Tausenderpunkt („12.000“ = 12000, „0.045“ bleibt 0,045).
- **`betrag_lesen($roh)`**: Geldbeträge (Netto, Brutto, Zahlung, Summe) = Mengen-Lesart. „21.687,75“ = „21687,75“ = „21687.75“ = 21687,75; „1.500“ = 1500; „1500.50“ = 1500,50.
- **`betrag_lesen_null($roh)`**: wie oben, leer = null (CRM „Wert in €“).

Gewichte liest `gewicht_lesen()` (core/gewicht.php).
