# `core/kreditor.php` – Kreditoren / Verbindlichkeiten

Spiegelbild zur Debitorenseite: Eingangsrechnungen von Lieferanten + unsere Zahlungen darauf.
Eigene Tabellen, **kein Eingriff in `core/schema.php`** (kollisionsarm). Geld in EUR (DECIMAL).

## Tabellen (angelegt per `kreditor_init()`, idempotent – am Anfang jeder Kreditor-Seite aufrufen)
- `lieferant_rechnung` – Eingangsrechnung: `nummer` (intern ER-xxxx), `lieferant_id`, `bestellung_id?`,
  `lief_nummer` (Lieferanten-Rechnungsnr), `datum`, `eingang_am`, `netto/ust_prozent/ust_betrag/brutto`,
  `status` (offen|teilbezahlt|bezahlt|storniert), `zahlungsziel_tage`, `faellig`, `notiz`, `erfasst_von`.
- `lieferant_zahlung` – unsere Zahlung: `lief_rechnung_id`, `betrag`, `datum`, `art`, `notiz`, `akteur`.

## Funktionen
- `kr_rechnung_anlegen($d)` / `kr_rechnung_update($id,$d)` – USt/Brutto aus Netto×Satz, Fälligkeit aus Datum+Ziel.
- `kr_zahlung_buchen($id,$betrag,$datum,…)` + `kr_status_fortschreiben($id)` – Status wie Debitor (offen→teilbezahlt→bezahlt).
- `kr_rechnung_stornieren($id,$grund)`.
- Kennzahlen: `kr_op_summe()`, `kr_op_ueberfaellig_summe()`, `kr_anz_offen()`, `kr_op_je_lieferant()`.
- Listen/Detail: `kr_liste($status,$q)`, `kr_rechnung_get($id)`, `kr_zahlungen($id)`, `kr_bestellung_netto($id)`.
- Exporte: `kr_export_vop_csv()` (offene Verbindlichkeiten), `kr_export_belege_csv($von,$bis)` (Eingangsrechnungen),
  `kr_export_datev($von,$bis)` (DATEV-EXTF „Rechnungseingang": Wareneingang/Aufwand an Kreditor).
  DATEV-Bausteine (`bh_datev_felder()`/`bh_datev_kopf()`) kommen aus `core/buchhaltung.php`.

## DATEV-Konten (SKR03-Default, per `app_meta` überschreibbar)
`datev_kreditor_sammel` (1600), `datev_aufwand_19` (3400), `datev_aufwand_eu` (3425), `datev_aufwand_0` (3300).
Vor Produktiv-Import mit dem Steuerberater abstimmen.

## Grenzen / Ausbau
Fremdwährung wird als Feld geführt (`waehrung`), aber nicht umgerechnet (EUR angenommen). Kein PDF-Upload
der Original-Eingangsrechnung, keine Auto-Verknüpfung Wareneingang→Rechnung. Siehe `BUCHHALTUNG.md`.
