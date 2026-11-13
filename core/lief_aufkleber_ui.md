# lief_aufkleber_ui.php – Bereich „Labels / Aufkleber“

Ein Baustein für das Lieferantenportal (`module/lieferant/bestellung.php`, von=lieferant, Sprache des Lieferanten) und die
Bestellseite im Dashboard (`module/einkauf/detail.php`, von=team). Sichtbar ab bestätigter Bestellung.
- `la_panel($b, $ziel, $von, $sprache)`: je Position/Charge Herstelldatum, Formular „Aufkleber drucken“ (Netto Pflicht,
  Brutto/Stück optional, Anzahl, Format), Liste mit Nachdruck und Storno.
- `la_post()` (aktion `la_herstell`, `la_drucken`, `la_storno`) + `la_post_ausgeben()` (neue Aufkleber gleich als PDF).
- `la_pdf_get($b)`: `&la_pdf=<ids>&la_fmt=<format>` liefert das PDF (nur Aufkleber dieser Bestellung).

## Zahlen lesen (13.11.2026)
`la_zahl($s, $sprache, $stueck)`: Mengenfeld-Lesart in der Sprache des Lieferanten. Stück je Gebinde: „12.000“ und „12,000“ = 12000. Netto/Brutto kg: „12,5“ = 12,5.

**Gewichte (13.11.2026, Fable-3 T1.2):** Gewichts- und Wiegefelder lesen über `gewicht_lesen()` (core/gewicht.php): Punkt und Komma sind Dezimaltrenner („25.125“ kg = 25,125; „6.482“ g = 6,482; „25,000“ = 25), nur wenn beide vorkommen, gilt das letzte. Vorbelegungen ohne Tausenderpunkt (`gewicht_feld()`). Stückzahlen bleiben bei der Mengen-Lesart („12.000“ Stk = 12000). Hier: `la_zahl()` liest Netto/Brutto je Gebinde mit `gewicht_lesen()` in jeder Sprache („25.000“ und „25,000“ kg = 25); Stück je Gebinde bleibt Mengen-Lesart.

13.11.2026: 中文 einheitlich „保质期“ für MHD, „每件件数（可选）“ und „标签张数“ statt doppeltem „数量“.
