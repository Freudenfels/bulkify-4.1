# Buchhaltung – Export-Endpunkt (`export.php`)

Route `beleg_export` (Rolle `finance`). Liefert Dateien als Download, kein HTML.
Parameter: `art=op|belege|datev|vop|lief_belege|datev_ek`, optional `von`/`bis` (`YYYY-MM-DD`).

Debitoren: `op`, `belege`, `datev`. Kreditoren: `vop` (offene Verbindlichkeiten),
`lief_belege` (Eingangsrechnungen), `datev_ek` (DATEV-Rechnungseingang). Kreditoren-Builder
in `core/kreditor.php`.

- `art=op` – Offene-Posten-Liste als CSV (UTF-8 mit BOM, Semikolon). Alle offenen/teilbezahlten
  Rechnungen mit Brutto, bereits bezahlt, Restbetrag, Tage überfällig.
- `art=belege` – Rechnungen + Gutschriften eines Zeitraums als CSV (Netto/USt/Brutto, Kunde, USt-IdNr,
  Land, Status). Gutschriften mit negativem Vorzeichen.
- `art=datev` – DATEV-EXTF-Buchungsstapel (Format 700), CP1252-kodiert. Je Beleg eine Buchung
  (Sammeldebitor an Erlöskonto). **Konten SKR03-Standard**, per `app_meta` überschreibbar:
  `datev_berater`, `datev_mandant`, `datev_debitor_sammel` (1400), `datev_erloes_19` (8400),
  `datev_erloes_eu` (8125), `datev_erloes_0` (8200), `datev_sachkontenlaenge` (4).
  Vor Produktiv-Import mit dem Steuerberater abstimmen.

Logik in `core/buchhaltung.php` (`bh_export_op_csv`, `bh_export_belege_csv`, `bh_export_datev`).

## Steuerberater-Paket (neu)
- `art=stb_csv` – Beleg-Posteingang als Excel-CSV (UTF-8+BOM).
- `art=stb_zip` – ZIP mit `belege.csv` + allen Belegdateien (eigener ZIP-Writer `bu_zip`, da ZipArchive hier fehlt). Logik in `core/belegeingang.php`.
