# Eingangsrechnung erfassen (`lief_rechnung_neu.php`)

Route `lief_rechnung_neu` (Rolle `finance`). Formular zum Erfassen einer Lieferanten-Rechnung
als Verbindlichkeit. Felder: Lieferant (Pflicht), Bestellung (optional), Lieferanten-Rechnungsnr,
Rechnungsdatum, Eingang, Netto, Vorsteuer %, Zahlungsziel, Notiz. USt/Brutto und Fälligkeit werden
berechnet (`kr_rechnung_anlegen()` in `core/kreditor.php`).

Vorbefüllung: `?bestellung=ID` setzt Lieferant + Netto (Summe menge×EK aus `bestellung_position`);
`?lieferant=ID` setzt Lieferant + Zahlungsziel aus `lieferanten.zahlungsziel_lief`.
Nach dem Speichern Weiterleitung auf `?p=lief_rechnung&id=…`.

**Währung:** aus `lieferanten.waehrung` vorbelegt (China-Lieferanten = USD, sonst EUR). Bei Fremdwährung
erscheint ein Kurs-Feld (1 Währung = X EUR, Standard aus `app_meta kurs_<cur>`, je Rechnung änderbar); der
Netto-Betrag wird in Rechnungswährung eingegeben und in EUR umgerechnet gespeichert. Vorsteuer wird bei
Fremdwährung/Ausland automatisch auf 0 vorbelegt. Kleine JS-Logik schaltet Kurs-Feld + Labels live um.
