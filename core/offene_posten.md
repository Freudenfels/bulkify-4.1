# core/offene_posten.php – Offene Posten (eine Rechnung für alle)

Seit 13.11.2026 rechnen Dashboard, Kundenseite und Buchhaltung die offenen Posten an genau einer Stelle.

- **Was ist offen?** Rechnungen mit Status „offen“ oder „teilbezahlt“. Je Rechnung zählt der **offene Rest** = Brutto minus alle Zahlungen.
  Vorher zählte das Dashboard nur „offen“ und das volle Brutto – teilbezahlte Rechnungen (z. B. 50 % Anzahlung) fehlten, Zahlungen wurden nicht abgezogen.
- `op_rechnungen($filter, $kunde_id)` – Liste der offenen Rechnungen mit `bezahlt`, `rest`, `tage_ueberfaellig`, `kunde_firma`. `$filter = 'ueberfaellig'` nur fällige vor heute; `$kunde_id` 0 = alle.
- `op_summe($kunde_id, $filter)` – Summe der Reste.

Wer ruft es?
- Dashboard (`module/intern/dashboard.php`): Kachel „Offene Posten“ und Liste „Offene Rechnungen“ (zeigt den Rest, teilbezahlte mit Hinweis).
- Kundenseite (`module/kunde/detail.php`): Kachel „Offene Posten“.
- Buchhaltung (`buchhaltung/core/buchhaltung.php` → `bh_op_rechnungen()` ruft `op_rechnungen()`): Offene Posten Debitoren, Mahnlauf.

Die Datei hängt nur an `all()` und kann deshalb auch vom eigenen Programm Buchhaltung eingebunden werden.
