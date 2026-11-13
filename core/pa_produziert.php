<?php
// „produziert“ eines Produktionsauftrags = Fertigware des PA in Stück (Nachbesserung Fable-5 T2.2, 13.11.2026).
// EINE Regel für Dashboard (produktion_gebucht, pa_ablauf) und Produktions-App (erp_produktion_gebucht, Werk-App-Liste).
// Gezählt werden Chargen mit pa_id, aber
//  - keine Mengen in kg/g/mg/t/l/ml (Restmischung, Bulk-Reste – die sind keine fertigen Packungen),
//  - je Chargennummer nur die erste Charge des PA: Teilmengen-Kopien (charge_auftrag_zuweisen, Übergabe ins Fremdlager) tragen dieselbe
//    Nummer und pa_id, die Quelle behält ihre Menge – sie würden sonst doppelt zählen.
// Ohne Abhängigkeiten (nur scalar()), damit auch die Sub-Apps sie laden dürfen.
if (!function_exists('pa_produziert_sql')) {
    // SQL-Ausdruck (Unterabfrage) für einen PA; $pa = Spalte oder '?' (Platzhalter).
    function pa_produziert_sql(string $pa = 'pa.id'): string {
        return "(SELECT COALESCE(SUM(cpz.menge),0) FROM charge cpz WHERE cpz.pa_id=$pa
                   AND LOWER(COALESCE(NULLIF(TRIM(cpz.einheit),''),'stk')) NOT IN ('kg','g','mg','t','l','ml')
                   AND NOT (cpz.charge_nr IS NOT NULL AND cpz.charge_nr<>'' AND EXISTS (SELECT 1 FROM charge cpz2 WHERE cpz2.pa_id=cpz.pa_id AND cpz2.charge_nr=cpz.charge_nr AND cpz2.id<cpz.id)))";
    }
    function pa_produziert(int $pa_id): float { return (float) scalar("SELECT " . pa_produziert_sql('?'), [$pa_id]); }
}
