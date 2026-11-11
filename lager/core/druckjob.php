<?php
// PDF zu einem Druckauftrag (lg_druckjob) erzeugen – eine Stelle für die Brücke (Drucken in Barßel) und die
// Druckvorschau der Testumgebung (Paket 12.11.2026). Rückgabe ['pdf'=>string|null, 'drucker'=>string, 'fehlt'=>string].
function lg_druckjob_pdf(array $j): array {
    $druckerEtikett      = lg_meta_lesen('drucker_name', '');          // leer = Standarddrucker
    $druckerLieferschein = lg_meta_lesen('drucker_lieferschein', '');
    $druckerLabel        = lg_meta_lesen('drucker_versandlabel', '');
    $druckerProbe        = lg_meta_lesen('drucker_probe', '');
    $druckerGebinde      = lg_meta_lesen('drucker_gebinde', '');
    $druckerKarton       = lg_meta_lesen('drucker_karton', '');            // Soll-Kette 6: Produktionsdrucker (WLAN) für Kartonetiketten
    $typ = (string)($j['typ'] ?? 'etikett');
    $pdf = null; $drucker = $druckerEtikett; $fehlt = 'nicht gefunden';
    if ($typ === 'lieferschein') {
        $drucker = $druckerLieferschein ?: $druckerEtikett;
        $pdf = function_exists('lg_lieferschein_pdf') ? lg_lieferschein_pdf((int)$j['ids']) : null;
        $fehlt = 'Sendung nicht gefunden';
    } elseif ($typ === 'label') {
        $drucker = $druckerLabel ?: $druckerEtikett;
        $lab = function_exists('lg_versand_label') ? lg_versand_label((int)$j['ids']) : null;
        $pdf = $lab && (string)($lab['pdf'] ?? '') !== '' ? (string)$lab['pdf'] : null;
        $fehlt = 'Kein Versand-Label';
    } elseif ($typ === 'zoll') {
        $drucker = $druckerLieferschein ?: $druckerEtikett;   // A4-Zollpapier auf den A4-/Lieferschein-Drucker
        $lab = function_exists('lg_versand_label') ? lg_versand_label((int)$j['ids']) : null;
        $pdf = $lab && (string)($lab['zoll_pdf'] ?? '') !== '' ? (string)$lab['zoll_pdf'] : null;
        $fehlt = 'Kein Zollpapier';
    } elseif ($typ === 'probe') {
        $drucker = $druckerProbe ?: $druckerEtikett;          // Proben-Etikett auf den (festgelegten) Proben-Drucker
        $pdf = function_exists('lg_probe_etikett_pdf') ? lg_probe_etikett_pdf((int)$j['ids']) : null;
        $fehlt = 'Probe nicht gefunden';
    } elseif ($typ === 'gebinde') {
        $drucker = $druckerGebinde ?: $druckerEtikett;        // Eimer-/Gebinde-Etikett auf den (festgelegten) Gebinde-Drucker
        $pdf = function_exists('lg_mischgebinde_etikett_pdf') ? lg_mischgebinde_etikett_pdf((int)$j['ids']) : null;
        $fehlt = 'Gebinde nicht gefunden';
    } elseif ($typ === 'palette') {
        $pdf = function_exists('lg_paletten_etikett_pdf') ? lg_paletten_etikett_pdf((int)$j['ids']) : null;   // 100 x 150 auf den Etikettendrucker
        $fehlt = 'Keine Paletten in der Sendung';
    } elseif ($typ === 'platz') {
        $pdf = function_exists('inv_platz_etikett_pdf') ? inv_platz_etikett_pdf(explode(',', (string)$j['ids'])) : null;   // Platz-Etiketten 100 x 70
        $fehlt = 'Platz nicht gefunden';
    } elseif ($typ === 'inventur') {
        $drucker = $druckerGebinde ?: $druckerEtikett;
        $pdf = function_exists('inv_klaerfall_etikett_pdf') ? inv_klaerfall_etikett_pdf((int)$j['ids']) : null;   // Gebinde ohne Etikett (Klärfall)
        $fehlt = 'Klärfall nicht gefunden';
    } elseif ($typ === 'pakarton') {   // ein Karton aus der Produktion (pa_karton): Nummer, Unter-Charge, Menge – kein „von n"
        $drucker = $druckerKarton ?: $druckerEtikett;
        $pdf = function_exists('lg_pa_karton_pdf') ? lg_pa_karton_pdf((int)$j['ids']) : null;
        $fehlt = 'Karton nicht gefunden';
    } elseif ($typ === 'karton') {
        $drucker = $druckerKarton ?: $druckerEtikett;         // Kartonetiketten der Produktion (100 x 150) auf den Produktionsdrucker
        $pdf = function_exists('lg_produktion_karton_pdf') ? lg_produktion_karton_pdf((int)$j['ids']) : null;
        $fehlt = 'Kartondaten nicht gefunden';
    } else {
        $pdf = lg_etikett_pdf(explode(',', (string)$j['ids']), (string)$j['format']);
    }
    return ['pdf' => $pdf, 'drucker' => $drucker, 'fehlt' => $fehlt];
}
