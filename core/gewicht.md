# core/gewicht.php

Eine Regel für Gewichte (Nachbesserung Fable-Prüfung 3, 13.11.2026). Ohne Abhängigkeiten, damit Dashboard, Lager-App, Produktions-/Werk-App und Lieferantenportal dieselbe Datei per `require_once` laden.

- **`gewicht_lesen($roh)`**: liest ein Gewicht oder Volumen.
  - Nur eine Art Trennzeichen: Punkt und Komma sind immer Dezimaltrenner („6.482“ g = 6,482; „25.125“ kg = 25,125; „25,000“ = 25).
  - Punkt und Komma zusammen: das letzte ist der Dezimaltrenner („1.234,5“ = 1234,5; „1,234.5“ = 1234,5).
  - Dasselbe Zeichen mehrfach („1.234.567“): Tausender.
  - Vollbreiten-Ziffern und chinesische Satzzeichen werden vorher umgewandelt, Einheiten („kg“) ignoriert.
- **`einheit_ist_gewicht($einheit)`**: kg, g, mg, µg, mcg, t, l, ml = Gewicht/Volumen. Für Felder, deren Einheit je Zeile wechselt (z. B. Rückgabe je Charge): Gewicht → `gewicht_lesen()`, sonst Stück-Lesart.
- **`gewicht_feld($wert)`**: Vorbelegung eines Gewichtsfeldes mit Komma und ohne Tausenderpunkt (sonst läse `gewicht_lesen()` „1.234“ als 1,234).
- **`tara_aus_id($id)`**: Gebindetyp aus `lg_tara` (`id`, `name`, `kg`). Formulare senden nur die ID, nie die Tara als Zahl.

Stückzahlen lesen weiter `erp_menge_parse()` / `pr_menge_parse()` / `la_zahl(…, true)` / `zahl_lesen(…, true)` („12.000“ Stk = 12000).

**Mehrdeutige Gewichte (Fable-4 T1.3, 13.11.2026):** `gewicht_mehrdeutig($roh)` = genau „1-3 Ziffern, Punkt, 3 Ziffern“ („1.250“). Gewichtsfelder tragen `data-gewicht`, `public/assets/bx-gewicht.js` fragt vor dem Absenden nach. `menge_in_einheit_lesen($roh, $einheit)`: kg/g/l = `gewicht_lesen()`, sonst Stückzahl mit Tausenderpunkt (`zahl_lesen(…, true)`) – genutzt bei „Material zuordnen“ am Auftrag.

**Zahl im Eingabefeld (Fable-4 T2.4, 13.11.2026):** `zahl_feld($wert, $stellen)` – wie `gewicht_feld()`, für alle Mengen- und Preisfelder (Komma, kein Tausenderpunkt).
