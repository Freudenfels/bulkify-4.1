# bank_import.php — Kontoauszug-Import + Zuordnung

Liest einen Kontoauszug (CSV) ein und ordnet die Buchungen offenen Rechnungen zu. Trennung nach Vorzeichen:
positiver Betrag = Eingang (Kundenzahlung -> Debitoren/beleg), negativer = Ausgang (unsere Zahlung ->
Kreditoren/lieferant_rechnung). Eigene Tabelle `bank_import` (eine Zeile je Auszugsposten). Gebucht wird über
die vorhandenen Funktionen `zahlung_erfassen` (Debitoren) bzw. `kr_zahlung_buchen` (Kreditoren) – kein
eigener Zahlungsweg.

- CSV: `bank_delimiter` (Semikolon/Komma/Tab erkennen), `bank_rohzeilen` (str_getcsv), `bank_num`
  (deutsche/engl. Beträge inkl. Vorzeichen, Klammern, nachgestelltes Minus), `bank_datum`, `bank_spalten_erkennen`
  (Spalten je Feld raten), `bank_parse` (Content + Mapping -> Posten).
- `bank_import_speichern`: Posten in einen Batch (Zeitstempel) schreiben, Richtung aus Vorzeichen, Betrag positiv.
- Matching: `bank_open_debitoren` / `bank_open_kreditoren` (offene Rechnungen einmal laden) + `bank_match`
  (Score: Belegnummer im Verwendungszweck 100 + Betrag == Rest 40 + Namenstreffer 30; Vorschlag ab 40).
- Buchen: `bank_buchen_eingang` / `bank_buchen_ausgang` (bucht Zahlung + markiert Zeile gebucht + verknüpft),
  `bank_ignorieren`.
- Listen: `bank_batches`, `bank_zeilen`, `bank_batch_loeschen` (entfernt nur NICHT gebuchte Posten).

Seite: `?p=buchen`.
