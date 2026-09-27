<?php
// JSON-Suche fuer die Sprachbedienung (assets/voice.js). Kein Seitenwechsel, damit die
// Spracherkennung durchlaufen kann. Rueckgabe: {treffer:[{charge_id,name,...,leiste_id,leiste}]}
$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$out = [];
foreach (erp_chargen_suche($q, 12) as $c) {
    $l = leiste_fuer_charge((int)$c['id']);
    $out[] = [
        'charge_id'    => (int)$c['id'],
        'name'         => (string)$c['item_name'],
        'artikelnummer'=> (string)($c['artikelnummer'] ?? ''),
        'charge_nr'    => (string)($c['charge_nr'] ?? ''),
        'menge'        => rtrim(rtrim(number_format((float)$c['menge_verfuegbar'], 3, ',', '.'), '0'), ','),
        'einheit'      => (string)($c['einheit'] ?? ''),
        'leiste_id'    => $l ? (int)$l['id'] : null,
        'leiste'       => $l ? (string)$l['code'] : null,
    ];
}
json_antwort(['q' => $q, 'treffer' => $out]);
