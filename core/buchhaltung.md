# `core/buchhaltung.php` – Auswertungen, GoBD-Prüfung, Exporte

Reine Lese-/Aufbereitungslogik für den Buchhaltungs-Hub. Keine schreibenden Aktionen.

## Kennzahlen / Listen
- `bh_op_je_kunde()` – offene Posten je Kunde (offen + überfälliger Anteil).
- `bh_jahre()` – Jahre mit Belegen (neueste zuerst).
- `bh_umsatz_monate(int $jahr)` – Umsatz Netto/USt/Brutto je Monat (Rechnung − Gutschrift, ohne Storno).
- `bh_umsatz_steuersatz(int $jahr)` – Umsatz je USt-Satz.

## GoBD-Prüfung
- `bh_nummernkreis_pruefung(['RE','GS'])` – prüft fortlaufende Belegnummern je Präfix:
  Lücken, Dubletten, Min/Max, aktueller Zähler aus `nummernkreis`; Chronologie
  (spätere Nummer mit früherem Datum = mögliche Rückdatierung); Storno-Bezug
  (stornierte Rechnungen + verweisende Gutschriften). Rein lesend.

## Exporte (CSV/DATEV)
- `bh_export_op_csv()` – OP-Liste, CSV UTF-8 + BOM.
- `bh_export_belege_csv($von,$bis)` – Belege/Umsatz eines Zeitraums, CSV UTF-8 + BOM.
- `bh_export_datev($von,$bis)` – DATEV-EXTF-Buchungsstapel (Format 700, 125 Feldspalten),
  CP1252. Konten SKR03-Default, per `meta_get`/`app_meta` konfigurierbar (siehe `export.md`).
  Buchungssatz: Sammeldebitor (Konto) an Erlöskonto (Gegenkonto); Rechnung „S", Gutschrift „H";
  Erlöskonto nach Steuersatz (19 % → 8400, EU steuerfrei → 8125, sonst 8200); Belegdatum TTMM,
  Belegfeld 1 = Belegnummer, Festschreibung gesetzt, Leistungsdatum als Ymd.

## Hinweis
DATEV-EXTF-Export ist als Entwurf gedacht (Kontenrahmen/Konten mit dem Steuerberater abstimmen).
Die EXTF-Kopfzeile (Metadaten) ist bewusst schlank gehalten; maßgeblich sind die 125 Datenfelder.
