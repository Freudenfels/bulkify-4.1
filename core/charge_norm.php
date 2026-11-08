<?php
// Chargennummern tolerant vergleichen (Soll-Handbuch 4.5 / 4.12): Punkte, Leerzeichen, Bindestriche, Unterstriche, Schrägstriche
// und Groß-/Kleinschreibung zählen nicht – „2799.A" = „2799A" = „2799 a" = „2799-a".
// EINE Stelle für alle Programme: ohne Abhängigkeiten, damit Dashboard (über core/schema.php) und später die Lager-App
// (Wareneingang, Schritt 6) dieselbe Datei laden können, ohne core/schema.php zu brauchen.

if (!function_exists('charge_norm')) {
    // Normalform einer Chargennummer (für PHP-Vergleiche). Leere Eingabe -> ''.
    function charge_norm(?string $nr): string {
        $s = mb_strtolower(trim((string)$nr), 'UTF-8');
        return (string) preg_replace('/[\s.\-_\/]+/u', '', $s);
    }

    // Gleiche Normalform als SQL-Ausdruck für eine Spalte, z. B. charge_norm_sql('c.charge_nr') = ? mit charge_norm($eingabe).
    // $spalte ist immer ein fester Spaltenname aus dem Code, nie Nutzereingabe.
    function charge_norm_sql(string $spalte): string {
        $e = "LOWER(COALESCE($spalte,''))";
        foreach (['.', '-', ' ', '_', '/'] as $z) $e = "REPLACE($e,'$z','')";
        return $e;
    }

    // Gleich nach Normalform? (beide nicht leer)
    function charge_gleich(?string $a, ?string $b): bool {
        $x = charge_norm($a); $y = charge_norm($b);
        return $x !== '' && $x === $y;
    }
}
