# import_bulk.php — Bulk-Import-Assistent (UI)

Route `import_bulk` (finance). Schritt-Seite (?batch=&step=):
- Start: mehrere PDFs hochladen (Rechnungen+Angebote gemischt) -> KI liest aus; Liste offener Stapel.
- Pruefen: je Beleg Art/Kunde(Dropdown, KI-Vorschlag, "+ neu")/Nummer/Datum/Netto/USt/Brutto/Angebots-Ref editieren; Datei ansehen; verwerfen.
- Verknuepfen: Rechnung -> Angebot zuordnen (Auto ueber Referenz, manuell korrigierbar).
- Zahlungen: je Rechnung Teilzahlungen (Datum+Betrag) erfassen; Status leitet sich ab.
- Uebernehmen/Fertig: erzeugt bleibende Belege (kundensichtbar) + archivierte Angebote; Staging danach loeschbar.
Logik in core/importer.php.
