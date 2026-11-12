<?php
// Charge beim Kunden (Nico 12.11.2026, Nachbesserung 13.11.2026): der Kunde sieht nur die GRUNDCHARGE (2775). Die Buchstaben
// (2775.A, 2775.B) stehen für unsere Produktionstage und bleiben intern. EINE Stelle für alle Kundenstellen: Lieferschein,
// Palettenetikett, Informationsdokument (CoA-Übersicht), Portal (Bestellung, Laborprüfung, Lager 2), Echtheitsseite.
// Ohne Abhängigkeiten - auch aus der Lager-App ladbar.
if (!function_exists('kunde_charge_anzeige')) {
    function kunde_charge_anzeige(?string $charge_nr): string {
        return (string) preg_replace('/\.[A-Z]{1,2}$/', '', trim((string)$charge_nr));
    }
    // Gehören zwei Chargen-Nummern beim Kunden zur selben Grundcharge?
    function kunde_charge_gleich(?string $a, ?string $b): bool {
        return kunde_charge_anzeige($a) === kunde_charge_anzeige($b);
    }
}
