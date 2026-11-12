# zeit_ort.php - Datum in Ortszeit

`heute_ort()` gibt den heutigen Tag in Europe/Berlin (Y-m-d), `heute_ort_plus('+18 months')` ein Ortsdatum mit Abstand.
PHP und Datenbank laufen in UTC; kurz nach Mitternacht wäre `date('Y-m-d')` bzw. `CURDATE()` noch der Vortag.
Genutzt für Vorbelegungen und Tagesdaten: Produktionsdatum (Teilmenge nachtragen), MHD-Vorgabe, Wareneingang der Fertigcharge,
Produktionstag der Unter-Charge (`pa_tag_heute()`). Geladen von `core/schema.php`, `core/pa_ablauf.php` (auch in der Werk-App).
