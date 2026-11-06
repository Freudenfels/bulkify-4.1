# pdf_vertrag.php – Jahresabnahmevertrag-Generator

`build_jahresvertrag_pdf(int $angebot_id): ?string` erzeugt den Rahmen-/Jahresabnahmevertrag im bulkify-Design (nutzt die Bausteine aus `core/pdf_spec.php`). Nur für Angebote mit `jahresvertrag=1` + Kunde + Produkt.

Inhalt: Vertragsparteien (Auftragnehmer Maniso / Besteller = Kunde), Vertragsgegenstand (Vertrags-Nr., Laufzeit von–bis, Produkt, **Kapsel-/Tablettengröße**, **Stück je Packung**, **Gesamt-Abnahmemenge** in Packungen und Einheiten, **Festpreis**, Gesamtwert), Abschnitt **Inhaltsstoffe/Rezeptur** (aus `rezeptur_zutat`, verbindlich festgeschrieben), die **Vereinbarung** (Abnahmeverpflichtung, Preisbindung, Abruf übers Portal, Restmengen, AGB) und Unterschriftsfelder (Besteller links; Maniso/Signatur+Stempel rechts). Fußzeile ohne den „ohne Unterschrift gültig"-Hinweis (der Vertrag wird ja unterschrieben).

**Pflicht-Hinweis (06.11.2026):** Über `spec_fuss($p, …, false)` steht „Dieses Dokument wurde maschinell erstellt. Die Angaben beruhen auf den uns bereitgestellten Daten.“ – ohne „ohne Unterschrift gültig“, weil der Vertrag unterschrieben wird.
