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

## Fremdwährung (z. B. USD bei China-Lieferanten)
Je Eingangsrechnung `waehrung` + `fx_kurs` (1 Fremdwährung = X EUR) + `fw_netto` (Originalbetrag).
`netto/ust_betrag/brutto` werden daraus in **EUR** gespeichert (`netto = fw_netto × fx_kurs`), damit OP, Saldo,
DATEV und CSV durchgängig in EUR laufen. Währung kommt vom Lieferanten (`lieferanten.waehrung`, im Formular
vorbelegt – China = USD, Rest EUR), Kurs je Rechnung überschreibbar. Originalbetrag + Kurs in Detail, Liste, CSV.

### Automatische Wechselkurse
- `kr_kurs_fetch_avg($cur)` – holt den **30-Tage-Durchschnitt** (EUR je 1 Fremdwährung) von der EZB über die
  Frankfurter-API (`api.frankfurter.app`, kein Key); tagesweise 1/Kurs gemittelt. `null` bei jedem Fehler.
- `kr_kurs_aktuell($cur)` – Vorschlagskurs mit Reihenfolge: **manueller Fixkurs** (`app_meta kurs_<cur>_fix`) >
  **Live-Ø** (gecacht 24 h in `app_meta kurs_<cur>_auto` + `_ts` + `_stand`) > alter Cache > **Offline-Fallback**
  (`kr_kurs_fallback`). Darf Netz nutzen (nur beim Prefill der Lieferanten-Währung).
- `kr_kurs_cached($cur)` – reiner Cache-Read (kein Netz), für Anzeige/JS; gibt `wert`/`stand`/`quelle`
  (`auto|manuell|standard|eur`). `kr_waehrungen()` listet EUR/USD/CNY/GBP/CHF.
- Lazy: der Live-Kurs wird beim Öffnen der Erfassung für die Lieferanten-Währung gezogen und 24 h gecacht –
  kein Cron nötig. Fallback-Kurse (USD 0,92 / CNY 0,127 …) greifen, falls das Netz mal nicht erreichbar ist.

## Grenzen / Ausbau
Kurs wird je Rechnung fest gespeichert (Snapshot). Kein BMF-Monatsdurchschnitt (steuerlich alternativ), keine
Settings-UI für Fixkurse, kein PDF-Upload der Original-Eingangsrechnung, keine Auto-Verknüpfung
Wareneingang→Rechnung. Robust gegen Schema-Drift: Lieferanten-Prefill liest per `SELECT *` + Coalescing. Siehe `BUCHHALTUNG.md`.
