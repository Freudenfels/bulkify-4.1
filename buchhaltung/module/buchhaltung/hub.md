# Buchhaltung – Finanz-Hub (`hub.php`)

Landeseite des Buchhaltungs-Bereichs. Route `buchhaltung` (Menü „Buchhaltung → Belege"),
nur Rolle `finance`. Gegliedert in Reiter (`?p=buchhaltung&tab=…`).

## Reiter
- **Übersicht** (`uebersicht`, Default) – Kennzahl-Kacheln: Forderungen (offen + überfällig),
  Verbindlichkeiten (offen + überfällig), Saldo (Forderungen − Verbindlichkeiten), Umsatz Jahr.
  Panels „Überfällige Rechnungen" und „Zuletzt bezahlt", Schnellaktionen.
- **Offene Posten** (`op`) – Forderungen (Debitoren): OP je Kunde (Summe offen + überfälliger Anteil), CSV-Export.
  Die Kacheln „Forderungen (offen)“/„davon überfällig“ und die Liste „Überfällig“ kommen seit 13.11.2026 aus `bh_op_rechnungen()` (= `core/offene_posten.php`).
- **Verbindlichkeiten** (`verbindlichkeiten`) – Kreditoren: offene Beträge je Lieferant + offene
  Eingangsrechnungen; „+ Eingangsrechnung erfassen" und CSV. Daten aus `core/kreditor.php`.
- **Auswertung** (`auswertung`) – Umsatz je Monat (Netto/USt/Brutto, Mini-Balken) mit Jahr-Auswahl,
  plus Aufstellung nach Steuersatz. Daten aus `bh_umsatz_monate()` / `bh_umsatz_steuersatz()`.
- **Prüfung** (`pruefung`) – GoBD-Kurzprüfung der Nummernkreise (`bh_nummernkreis_pruefung()`):
  Lücken, Dubletten, Chronologie (Rückdatierung) und Storno-Bezug. Rein lesend.
- **Export** (`export`) – Download-Kacheln für OP-Liste, Belege/Umsatz (Zeitraum) und
  DATEV-Buchungsstapel, dazu Hinweis auf die E-Rechnung je Beleg.

## Datenquellen / Helfer
Alle Aufbereitung in `core/buchhaltung.php` (Kennzahlen, Prüfung, Exporte). Geld in `beleg`
ist Euro (DECIMAL), direkt formatiert. `beleg.status` wird bei Zahlung fortgeschrieben.

## Grenzen
Read-only Auswertung. Export-Downloads laufen über die eigenen Endpunkte
`beleg_export` (`export.php`) und `rechnung_xml` (`rechnung_xml.php`).

## Reiter Steuern (neu)
Reiter „Steuern": vereinnahmte Umsatzsteuer je Monat + Quartalssumme. Auswahl Jahr + Quartal (Default = laufendes
Quartal) + Basis: „Rechnungsdatum (Soll)" oder „Zahlungseingang (Ist, anteilig)". Spalten: USt eingenommen
(Ausgangsrechnungen, Rechnung minus Gutschrift), Vorsteuer (Eingangsrechnungen), Zahllast (= USt − Vorsteuer).
CSV-Link für die Belege des Quartals. Daten: bh_ust_monate($jahr,$basis) in core/buchhaltung.php. Keine Steuerberatung.

Seit 07.11.2026 stehen **beide USt-Sichten immer nebeneinander** (Spalten + Kacheln):
- **USt laut Rechnung** – nach Rechnungsdatum, offene/teilbezahlte Rechnungen voll (Soll).
- **USt eingenommen** – nur wirklich bezahlt: je Zahlung anteilig Zahlung / Brutto × USt, im Monat der Zahlung (Ist).
Der Umschalter heißt jetzt „Zahllast nach" und steuert nur noch Vorsteuer + Zahllast.
