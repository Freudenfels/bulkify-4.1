# Eingangsrechnung erfassen (`lief_rechnung_neu.php`)

Route `lief_rechnung_neu` (Rolle `finance`). Formular zum Erfassen einer Lieferanten-Rechnung
als Verbindlichkeit. Felder: Lieferant (Pflicht), Bestellung (optional), Lieferanten-Rechnungsnr,
Rechnungsdatum, Eingang, Netto, Vorsteuer %, Zahlungsziel, Notiz. USt/Brutto und Fälligkeit werden
berechnet (`kr_rechnung_anlegen()` in `core/kreditor.php`).

Vorbefüllung: `?bestellung=ID` setzt Lieferant + Netto (Summe menge×EK aus `bestellung_position`);
`?lieferant=ID` setzt Lieferant + Zahlungsziel aus `lieferanten.zahlungsziel_lief`.
Nach dem Speichern Weiterleitung auf `?p=lief_rechnung&id=…`.

**Währung:** aus `lieferanten.waehrung` vorbelegt (China-Lieferanten = USD, sonst EUR). Bei Fremdwährung
erscheint ein Kurs-Feld; der Kurs wird automatisch als **30-Tage-Durchschnitt (EZB)** vorgeschlagen
(`kr_kurs_aktuell()`), ist aber je Rechnung überschreibbar. Der Netto-Betrag wird in Rechnungswährung
eingegeben und in EUR umgerechnet gespeichert. Vorsteuer wird bei Fremdwährung/Ausland automatisch auf 0
vorbelegt. Kleine JS-Logik schaltet Währung/Kurs-Feld/Labels live um (Kurs-Vorschläge aus dem Cache, ohne Netz).

## Upload alter/neuer Rechnungen (neu)
Original-Lieferantenrechnung (PDF/Foto) hochladbar: „KI auslesen & vorbefüllen" speichert die Datei, liest sie per KI aus (be_ki_auslesen) und befüllt Lieferant (Namensabgleich), Rechnungsnr, Datum, Netto, USt, Währung vor. Beim Speichern wird die Datei an die Eingangsrechnung gehängt (lieferant_rechnung.datei). KI nicht eingerichtet -> Datei wird trotzdem gespeichert, Felder manuell.

**Gesperrt/inaktiv (Block C, 11.11.2026):** Gesperrte Lieferanten/Kunden bzw. inaktive Produkte/Rezepturen stehen nicht mehr in den Auswahlfeldern. Ein schon gewählter Eintrag bleibt am Beleg sichtbar.

**Zahlen-Lesart (13.11.2026, Fable-3 T2.9):** Betragsfelder lesen mit `betrag_lesen()`, Preise/Prozente/Kurse mit `zahl_lesen()`, Mengen mit `zahl_lesen(…, true)` – alle aus core/zahl_lesen.php (eine Regel mit dem Dashboard). „21.687,75“, „21687,75“ und „21687.75“ ergeben 21687,75.
