# Buchhaltung – Finanz-Hub (`hub.php`)

Landeseite des Buchhaltungs-Bereichs. Route `buchhaltung` (Menü „Buchhaltung → Belege"),
nur Rolle `finance`. Gegliedert in Reiter (`?p=buchhaltung&tab=…`).

## Reiter
- **Übersicht** (`uebersicht`, Default) – Kennzahl-Kacheln: Forderungen (offen + überfällig),
  Verbindlichkeiten (offen + überfällig), Saldo (Forderungen − Verbindlichkeiten), Umsatz Jahr.
  Panels „Überfällige Rechnungen" und „Zuletzt bezahlt", Schnellaktionen.
- **Offene Posten** (`op`) – Forderungen (Debitoren): OP je Kunde (Summe offen + überfälliger Anteil), CSV-Export.
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
