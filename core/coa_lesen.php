<?php
// Aus dem Text eines Lieferanten-CoA/einer Spezifikation Werte VORSCHLAGEN.
//
// Bewusst „vorschlagen", nicht „übernehmen": Was hier herauskommt, wird in das Formular
// geschrieben und muss geprüft werden, bevor es gespeichert wird. Ein falscher Wert auf
// einem Analysenzertifikat, das an den Kunden geht, ist schlimmer als ein leeres Feld.
require_once __DIR__ . '/pdf_text.php';

// Parameter, die auf fast jedem CoA stehen – je Eintrag deutsche und englische Schreibweisen.
function coa_parameter(): array {
    return [
        'Aussehen'          => ['aussehen', 'appearance', 'description', 'erscheinung'],
        'Identität'         => ['identität', 'identitaet', 'identity', 'identification'],
        'Gehalt'            => ['gehalt', 'assay', 'content', 'purity', 'reinheit'],
        'Trocknungsverlust' => ['trocknungsverlust', 'loss on drying', 'lod', 'wassergehalt', 'water content', 'moisture'],
        'pH-Wert'           => ['ph-wert', 'ph value', 'ph '],
        'Sulfatasche'       => ['sulfatasche', 'sulphated ash', 'residue on ignition', 'asche', 'ash'],
        'Partikelgröße'     => ['partikelgröße', 'partikelgroesse', 'particle size', 'sieve', 'siebanalyse', 'mesh'],
        'Schüttdichte'      => ['schüttdichte', 'schuettdichte', 'bulk density'],
        'Blei'              => ['blei', 'lead', ' pb '],
        'Cadmium'           => ['cadmium', ' cd '],
        'Quecksilber'       => ['quecksilber', 'mercury', ' hg '],
        'Arsen'             => ['arsen', 'arsenic', ' as '],
        'Schwermetalle'     => ['schwermetalle', 'heavy metals'],
        'Gesamtkeimzahl'    => ['gesamtkeimzahl', 'total plate count', 'total aerobic', 'tamc', 'keimzahl'],
        'Hefen und Schimmel'=> ['hefen', 'schimmel', 'yeast', 'mould', 'mold', 'tymc'],
        'E. coli'           => ['e. coli', 'e.coli', 'escherichia'],
        'Salmonellen'       => ['salmonell'],
        'Staphylococcus'    => ['staphylococcus'],
        'Pseudomonas'       => ['pseudomonas'],
    ];
}

// Methoden, die im Text auftauchen können.
function coa_methoden(): array {
    return ['HPLC', 'UHPLC', 'GC', 'GC-MS', 'ICP-MS', 'ICP-OES', 'AAS', 'FT-IR', 'IR', 'UV', 'Titration',
            'Ph. Eur.', 'Ph.Eur', 'USP', 'AOAC', 'ISO', 'DIN', 'visuell', 'visual', 'organoleptisch'];
}

// Text eines PDFs in Vorschlagszeilen zerlegen.
// Rückgabe: ['zeilen' => [[parameter, spezifikation, ergebnis, methode], …], 'kopf' => [feld => wert], 'text' => roh]
function coa_werte_lesen(string $pfad): array {
    $text = pdf_text_extrahieren($pfad);
    if ($text === '') return ['zeilen' => [], 'kopf' => [], 'text' => '', 'lesbar' => false];

    $zeilen = preg_split('/\r?\n/', $text);
    $treffer = [];
    foreach (coa_parameter() as $name => $muster) {
        foreach ($zeilen as $z) {
            $klein = ' ' . mb_strtolower(preg_replace('/\s+/u', ' ', $z)) . ' ';
            $passt = false;
            foreach ($muster as $mu) if (mb_strpos($klein, $mu) !== false) { $passt = true; break; }
            if (!$passt) continue;
            // Zuerst spaltenweise: pdf_text_extrahieren() setzt Tabellenzeilen mit drei
            // Leerzeichen zwischen den Spalten zusammen. Wo das greift, ist die Zuordnung
            // exakt – geraten wird erst, wenn es keine Spalten gibt.
            $spalten = preg_split('/\s{3,}/u', trim($z));
            $spalten = array_values(array_filter(array_map('trim', $spalten), fn($x) => $x !== ''));
            if (count($spalten) >= 3) {
                $spec = $spalten[1]; $erg = $spalten[2];
                $methode = count($spalten) >= 4 ? $spalten[3] : '';
                foreach (coa_methoden() as $me) if (stripos($z, $me) !== false) { $methode = $me; break; }
                $treffer[$name] = [$name, $spec, $erg, $methode];
                break;
            }
            $rest = trim(preg_replace('/^\s*[^:]{0,40}:\s*/u', '', $z));
            [$spec, $erg] = coa_werte_trennen($rest);
            $methode = '';
            foreach (coa_methoden() as $me) if (stripos($z, $me) !== false) { $methode = $me; break; }
            $treffer[$name] = [$name, $spec, $erg, $methode];
            break;                                            // erste passende Zeile genügt
        }
    }

    // Kopfangaben, die häufig vorkommen und uns Tipparbeit sparen.
    $kopf = [];
    $felder = [
        'charge'  => ['batch no', 'batch number', 'lot no', 'lot number', 'chargennummer', 'charge nr', 'charge-nr', 'batch'],
        'mhd'     => ['best before', 'expiry date', 'expiration', 'mindesthaltbar', 'mhd', 'retest date'],
        'herst'   => ['manufacturing date', 'production date', 'herstelldatum', 'herstellungsdatum', 'date of manufacture'],
        'menge'   => ['quantity', 'batch size', 'chargengröße', 'chargengroesse', 'menge'],
    ];
    foreach ($felder as $key => $muster) {
        foreach ($zeilen as $z) {
            $klein = mb_strtolower($z);
            foreach ($muster as $mu) {
                if (mb_strpos($klein, $mu) === false) continue;
                $wert = trim(preg_replace('/^.*?(:|\s{2,})/u', '', $z));
                if ($wert !== '' && mb_strlen($wert) < 60) { $kopf[$key] = $wert; }
                break 2;
            }
        }
    }
    return ['zeilen' => array_values($treffer), 'kopf' => $kopf, 'text' => $text, 'lesbar' => true];
}

// Aus dem Rest einer Zeile Spezifikation und Ergebnis herauslösen.
// Faustregel: Was eine Grenze nennt (min./max./≤/≥/NMT/NLT/Bereich), ist die Spezifikation;
// der letzte freistehende Wert ist das Ergebnis. Passt das nicht, bleibt das Feld leer –
// dann trägt man es von Hand ein, statt sich auf eine Fehlzuordnung zu verlassen.
function coa_werte_trennen(string $rest): array {
    $rest = trim(preg_replace('/\s+/u', ' ', $rest));
    if ($rest === '') return ['', ''];
    $grenze = '/((?:≤|≥|<=|>=|<|>|max\.?|min\.?|nmt|nlt|not more than|not less than)\s*[\d.,]+\s*[^\s,;|]*|[\d.,]+\s*[-–]\s*[\d.,]+\s*[^\s,;|]*)/iu';
    $spec = '';
    if (preg_match($grenze, $rest, $m)) {
        $spec = trim($m[1]);
        $rest = trim(str_replace($m[1], ' ', $rest));
    }
    // Ergebnis: letzter Zahlenwert mit optionaler Einheit, sonst ein Wort wie „entspricht"/„conforms".
    $erg = '';
    if (preg_match_all('/([\d.,]+\s*(?:%|ppm|ppb|mg\/kg|µg\/kg|g\/l|cfu\/g|KBE\/g)?)/iu', $rest, $mm) && $mm[1]) {
        $kand = array_values(array_filter(array_map('trim', $mm[1]), fn($x) => $x !== '' && preg_match('/\d/', $x)));
        if ($kand) $erg = end($kand);
    }
    if ($erg === '' && preg_match('/(entspricht|conforms|complies|passes|negativ|negative|nachgewiesen|abwesend|absent)/iu', $rest, $m2)) {
        $erg = trim($m2[1]);
    }
    return [$spec, $erg];
}
