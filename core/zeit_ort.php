<?php
// Datum in Ortszeit (Nachbesserung 13.11.2026). PHP und Datenbank laufen in UTC - zwischen 00:00 und 01:00 (Sommer 02:00) ist
// „heute“ in UTC noch der Vortag. Für Vorbelegungen und Tagesdaten (Produktionsdatum, MHD-Vorgabe, Wareneingang einer Charge)
// gilt der Tag in Europe/Berlin. Gespeichert wird weiter UTC, wo es um Zeitpunkte geht (angelegt, zeit).
// Ohne Abhängigkeiten - wird vom Dashboard (core/schema.php), dem Produktionskern (core/pa_ablauf.php) und den Sub-Apps geladen.
if (!function_exists('heute_ort')) {
    function heute_ort(): string { return (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d'); }
    // Ortsdatum plus Abstand, z. B. heute_ort_plus('+18 months').
    function heute_ort_plus(string $abstand): string { return (new DateTime(heute_ort(), new DateTimeZone('Europe/Berlin')))->modify($abstand)->format('Y-m-d'); }
}
