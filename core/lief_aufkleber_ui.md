# lief_aufkleber_ui.php – Bereich „Labels / Aufkleber“

Ein Baustein für das Lieferantenportal (`module/lieferant/bestellung.php`, von=lieferant, Sprache des Lieferanten) und die
Bestellseite im Dashboard (`module/einkauf/detail.php`, von=team). Sichtbar ab bestätigter Bestellung.
- `la_panel($b, $ziel, $von, $sprache)`: je Position/Charge Herstelldatum, Formular „Aufkleber drucken“ (Netto Pflicht,
  Brutto/Stück optional, Anzahl, Format), Liste mit Nachdruck und Storno.
- `la_post()` (aktion `la_herstell`, `la_drucken`, `la_storno`) + `la_post_ausgeben()` (neue Aufkleber gleich als PDF).
- `la_pdf_get($b)`: `&la_pdf=<ids>&la_fmt=<format>` liefert das PDF (nur Aufkleber dieser Bestellung).

## Zahlen lesen (13.11.2026)
`la_zahl($s, $sprache, $stueck)`: Mengenfeld-Lesart in der Sprache des Lieferanten. Stück je Gebinde: „12.000“ und „12,000“ = 12000. Netto/Brutto kg: „12,5“ = 12,5.
