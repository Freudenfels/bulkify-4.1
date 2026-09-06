# module/produkt/novelfood.php – Novel-Food-Schnellsuche

Seite unter `?p=novelfood` (Nav: Produkt → „Novel Food"; im Werk-Menü unter
Entwicklung). Man tippt einen oder mehrere Stoffnamen ein (einer pro Zeile, z. B.
eine ganze Zutatenliste) und sieht sofort, ob es Novel Food ist.

## Bewertung (Ampel)
Gleiche Semantik wie `produkt_novelfood_pruefen()`:
- **rot** – `NOT_YET_AUTHORISED_NOVEL_FOOD`: Novel Food ohne Zulassung, nicht verkehrsfähig.
- **gelb** – `AUTHORISED_NOVEL_FOOD` / `SUBJECT_TO_A_CONSULTATION_REQUEST` / ohne Code:
  zugelassenes bzw. offenes Novel Food, Bedingungen prüfen.
- **grün** – `NOT_NOVEL_IN_FOOD(_SUPPLEMENTS)`: kein Novel Food.

## Starke vs. schwache Treffer
- **stark**: Eingabe = Katalogbegriff, oder der Katalogbegriff steht ganz in der
  Eingabe, oder die Eingabe steht als ganzes Wort im Katalogbegriff (Letzteres nur,
  wenn die Eingabe kein einzelnes Allerweltswort ist).
- **schwach**: Eingabe ist nur ein Teilwort eines längeren Katalognamens
  (z. B. „Magnesium" in „Magnesium-L-Threonat").

Nur **starke** Treffer bestimmen die Gesamt-Ampel. Schwache Treffer werden separat
als aufklappbare „ähnliche Einträge" gezeigt – so wird „Magnesium" nicht fälschlich
rot. Dazu dient dieselbe Stopwort-Liste wie in der Auto-Prüfung (nur für die
Abwertung EINZELNER generischer Wörter, nicht zum Ausblenden von Treffern).

## Grenzen
Der EU-Katalog ist englischsprachig. Deutsche Schreibweisen, die minimal abweichen
(z. B. „Nicotinamid-Ribosid" vs. „Nicotinamide riboside"), werden ggf. nicht
gefunden – dann Schreibweise/Synonym anders eintippen. Keine Rechtsberatung.

## Rollen
`novelfood` in `route_rollen_map()` = `production, sales, labor` (Admin sowieso).
Ist der Katalog leer, weist die Seite auf `tools/novelfood_import.php` hin.
