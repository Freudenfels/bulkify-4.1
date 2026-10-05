<?php
// JSON-Suche fuer die Sprachbedienung (assets/voice.js). Kein Seitenwechsel, damit die
// Spracherkennung durchlaufen kann. Rueckgabe: {treffer:[{charge_id,name,...,leiste_id,leiste}]}
$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$out = [];

// Eine Zeile fuer die Rueckgabe bauen (loest den Blinker der Charge/Kiste auf).
$zeile = function (array $c): array {
    $b = blinker_fuer_charge((int)$c['id']);
    $l = $b['leiste'];
    return [
        'charge_id'    => (int)$c['id'],
        'name'         => (string)$c['item_name'],
        'artikelnummer'=> (string)($c['artikelnummer'] ?? ''),
        'charge_nr'    => (string)($c['charge_nr'] ?? ''),
        'menge'        => rtrim(rtrim(number_format((float)($c['menge_verfuegbar'] ?? 0), 3, ',', '.'), '0'), ','),
        'einheit'      => (string)($c['einheit'] ?? ''),
        'leiste_id'    => $l ? (int)$l['id'] : null,
        'leiste'       => $l ? (string)$l['code'] : null,
        'ort'          => (string)$b['ort'],
    ];
};

// Gescannter QR-Code vom Etikett ist eine URL mit ...&id=<charge_id> -> direkt diese Charge finden.
$cid = 0;
if (preg_match('/[?&]id=(\d+)/i', $q, $mm) || preg_match('~charge[\/=](\d+)~i', $q, $mm)) $cid = (int)$mm[1];
if ($cid > 0 && function_exists('erp_charge_voll')) {
    $c = erp_charge_voll($cid);
    if ($c) { $c['id'] = $cid; $out[] = $zeile($c); }
}

// Blinker-Code eingegeben/gescannt (z. B. CF64B6XD) -> das daran gebundene Produkt zeigen.
// (Manche Produkte kann man nicht mit Aufklebern vollpacken – der Blinker ist dann die Kennung.)
if (!$out && function_exists('leiste_per_code') && function_exists('erp_charge_voll')) {
    $code = function_exists('led_leiste_normalisieren') ? led_leiste_normalisieren($q) : trim($q);
    if ($code) {
        $l = leiste_per_code($code);
        if ($l && !empty($l['charge_id'])) {
            $c = erp_charge_voll((int)$l['charge_id']);
            if ($c) { $c['id'] = (int)$l['charge_id']; $out[] = $zeile($c); }
        } elseif ($l && !empty($l['kiste_id']) && function_exists('kiste_inhalt')) {
            foreach (kiste_inhalt((int)$l['kiste_id']) as $it) {
                $c = erp_charge_voll((int)$it['charge_id']);
                if ($c) { $c['id'] = (int)$it['charge_id']; $out[] = $zeile($c); }
            }
        }
    }
}

if (!$out) {
    foreach (erp_chargen_suche($q, 12) as $c) { $out[] = $zeile($c); }
}
json_antwort(['q' => $q, 'treffer' => $out]);
