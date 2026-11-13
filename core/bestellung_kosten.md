# core/bestellung_kosten.php – Reiter „Kosten“ an der Bestellung

Seit 13.11.2026 (Nico: „Reiter mit Preisen, dass ich sehe, was die Gesamtkosten sind“).

## Was zeigt der Reiter?
- Vier Kacheln: Warenwert (oder Online-Preis), Rechnung, Nebenkosten, **Gesamtkosten**.
- Tabelle je Position: Menge, EK je Einheit, Warenwert, davon geliefert, **Einstandspreis** (EK plus anteilige Nebenkosten).
- Nebenkosten-Liste (Fracht, Zoll / Einfuhrumsatzsteuer, Bank-/Zahlungsgebühr, Sonstiges) mit Knopf „Nebenkosten erfassen“ (Pop-up).

## Wie wird gerechnet?
- Gesamtkosten = Rechnungssumme in EUR (wenn erfasst), sonst Bestellwert der Positionen (Online-Einkauf: Online-Preis) + Nebenkosten.
- Rechnung in Fremdwährung: gelber Hinweis, es wird mit dem Bestellwert gerechnet.
- Weicht die Rechnung vom Bestellwert ab: gelber Hinweis mit dem Unterschied.
- Position mit „Rest storniert“ zählt nur mit der gelieferten Menge, stornierte Positionen gar nicht.
- Einstandspreis: Gesamtkosten nach Warenwert auf die Positionen verteilt, geteilt durch die Menge.

## Daten
- Tabelle `bestellung_kosten` (legt `bk_schema()` selbst an): bestellung_id, art, betrag (EUR), notiz, angelegt (UTC), angelegt_von, storniert_am.
- Nebenkosten werden nie gelöscht, nur storniert. Erfassen und Stornieren stehen im Verlauf der Bestellung.

## Funktionen
- `bk_daten($b)` – alle Zahlen.
- `bk_post($b, $von)` – Aktionen `bk_neu` / `bk_storno` (eingebunden in module/einkauf/detail.php, Rücksprung `#kosten`).
- `bk_panel_html($b)` – Inhalt des Reiters.
