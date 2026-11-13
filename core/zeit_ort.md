# zeit_ort.php - Datum in Ortszeit

`heute_ort()` gibt den heutigen Tag in Europe/Berlin (Y-m-d), `heute_ort_plus('+18 months')` ein Ortsdatum mit Abstand.
PHP und Datenbank laufen in UTC; kurz nach Mitternacht wäre `date('Y-m-d')` bzw. `CURDATE()` noch der Vortag.
Genutzt für Vorbelegungen und Tagesdaten: Produktionsdatum (Teilmenge nachtragen), MHD-Vorgabe, Wareneingang der Fertigcharge,
Produktionstag der Unter-Charge (`pa_tag_heute()`). Geladen von `core/schema.php`, `core/pa_ablauf.php` (auch in der Werk-App).

**Fable-6 T5.3 (13.11.2026):** Kein `CURDATE()` mehr im Code (außer `tools/`). Alle 93 Stellen in 28 Dateien nutzen `heute_ort()` als Datums-Literal im SQL. Davor kam zwischen 0 und 1 bzw. 2 Uhr das Datum von gestern heraus, weil die DB-Sitzung in UTC läuft. Die Sub-Apps Produktion und Buchhaltung laden dafür `core/zeit_ort.php`. Die Umstellung lief über den PHP-Tokenizer (nur String-Literale); die Liste steht in der Rückmeldung Fable-6.
