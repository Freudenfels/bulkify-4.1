# Eingangsrechnung erfassen (`lief_rechnung_neu.php`)

Route `lief_rechnung_neu` (Rolle `finance`). Formular zum Erfassen einer Lieferanten-Rechnung
als Verbindlichkeit. Felder: Lieferant (Pflicht), Bestellung (optional), Lieferanten-Rechnungsnr,
Rechnungsdatum, Eingang, Netto, Vorsteuer %, Zahlungsziel, Notiz. USt/Brutto und Fälligkeit werden
berechnet (`kr_rechnung_anlegen()` in `core/kreditor.php`).

Vorbefüllung: `?bestellung=ID` setzt Lieferant + Netto (Summe menge×EK aus `bestellung_position`);
`?lieferant=ID` setzt Lieferant + Zahlungsziel aus `lieferanten.zahlungsziel_lief`.
Nach dem Speichern Weiterleitung auf `?p=lief_rechnung&id=…`.
