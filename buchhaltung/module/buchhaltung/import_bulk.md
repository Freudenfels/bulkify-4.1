# import_bulk.php — Bulk-Import-Assistent (UI)

Route `import_bulk` (finance). Schritt-Seite (?batch=&step=):
- Start: mehrere PDFs hochladen (Rechnungen+Angebote gemischt) -> KI liest aus; Liste offener Stapel.
- Pruefen: je Beleg Art/Kunde(Dropdown, KI-Vorschlag, "+ neu")/Nummer/Datum/Netto/USt/Brutto/Angebots-Ref editieren; Datei ansehen; verwerfen.
- Verknuepfen: Rechnung -> Angebot zuordnen (Auto ueber Referenz, manuell korrigierbar).
- Zahlungen: je Rechnung Teilzahlungen (Datum+Betrag) erfassen; Status leitet sich ab.
- Uebernehmen/Fertig: erzeugt bleibende Belege (kundensichtbar) + archivierte Angebote; Staging danach loeschbar.
Logik in core/importer.php.
- Kunde in Tabelle zeigt internes Kürzel (kunde_link/kunde_anzeige), 08.11.2026

**Zahlen-Lesart (13.11.2026, Fable-3 T2.9):** Betragsfelder lesen mit `betrag_lesen()`, Preise/Prozente/Kurse mit `zahl_lesen()`, Mengen mit `zahl_lesen(…, true)` – alle aus core/zahl_lesen.php (eine Regel mit dem Dashboard). „21.687,75“, „21687,75“ und „21687.75“ ergeben 21687,75.
