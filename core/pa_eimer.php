<?php
// Eimerplanung beim Mischen (Soll-Handbuch 4.8, Nico 11.11.2026 Ergänzung 3). Gemeinsam (nur q/one/all/scalar).
// Das System gibt die Eimer vor – Größe und Reihenfolge; der Mitarbeiter tippt keine Eimergröße mehr ein.
// Regel: bei jedem Rohstoff zuerst die ältere Charge. Jedes Mal, wenn bei IRGENDEINEM Rohstoff eine Charge zu Ende geht, beginnt ein
// neuer Eimer (neuer Buchstabe). Beispiel: Rohstoff 1 hat noch 5 kg alt, Rohstoff 2 noch 30 kg alt -> Eimer A bis die 5 kg verbraucht
// sind (kleiner Eimer), Eimer B bis die 30 kg verbraucht sind, danach normale Eimer (Standard 25 kg, app_meta eimer_kg_standard).
// Grundlage sind die für diesen PA tatsächlich bereitgestellten Rohstoff-Chargen (produktion_verbrauch), sonst der freie Bestand (FEFO).
// Ist der erste Eimer fertig, ist der Plan festgeschrieben (pa_eimerplan), damit sich die Eimer nicht mehr verschieben.

function pa_eimer_schema(): void {
    static $ok = false; if ($ok) return; $ok = true;
    try { q("CREATE TABLE IF NOT EXISTS pa_eimerplan (pa_id INT PRIMARY KEY, plan MEDIUMTEXT NOT NULL, angelegt DATETIME NOT NULL, von VARCHAR(120) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (\Throwable $e) {}
}
function pa_eimer_standard_kg(): float { $v = (float) str_replace(',', '.', pa_meta('eimer_kg_standard')); return $v > 0 ? $v : 25.0; }
// Festgeschriebener Plan oder null.
function pa_eimerplan_fest(int $pa_id): ?array {
    pa_eimer_schema();
    $j = scalar("SELECT plan FROM pa_eimerplan WHERE pa_id=?", [$pa_id]);
    $p = $j ? json_decode((string)$j, true) : null;
    return is_array($p) ? $p : null;
}
function pa_eimerplan_festschreiben(int $pa_id, array $plan, string $von): void {
    pa_eimer_schema();
    q("INSERT IGNORE INTO pa_eimerplan (pa_id, plan, angelegt, von) VALUES (?,?,?,?)", [$pa_id, json_encode($plan, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s'), $von ?: null]);
}
// Eimerplan berechnen. $zutaten: [['item_id','name','kg'(gesamt für diese Mischung)]], $total_kg. Rückgabe Struktur wie erp_mischer_plan
// (gebinde: nr, buchstabe, kg, grund, zutaten: name, kg, charge).
function pa_eimerplan_berechnen(int $pa_id, array $zutaten, float $total_kg, ?float $std = null): array {
    $std = $std ?: pa_eimer_standard_kg();
    $out = ['ok' => true, 'total_kg' => $total_kg, 'anzahl' => 0, 'gebinde' => [], 'auto' => true, 'standard_kg' => $std];
    if ($total_kg <= 0 || !$zutaten) return $out;
    // je Zutat: Chargen in FEFO-Reihenfolge mit Menge in kg (bereitgestellt für den PA, sonst freier Bestand)
    $plaene = [];
    foreach ($zutaten as $zi => $z) {
        $anteil = (float)$z['kg'] / $total_kg;
        if ($anteil <= 0) continue;
        $ch = (int)($z['item_id'] ?? 0) > 0 ? all("SELECT c.id, COALESCE(c.charge_nr, CONCAT('#', c.id)) AS nr, c.mhd, SUM(v.menge) AS m, v.einheit FROM produktion_verbrauch v JOIN charge c ON c.id=v.charge_id
                       WHERE v.pa_id=? AND v.item_id=? GROUP BY c.id, v.einheit ORDER BY (c.mhd IS NULL), c.mhd, c.id", [$pa_id, (int)$z['item_id']]) : [];
        if (!$ch && (int)($z['item_id'] ?? 0) > 0)
            $ch = all("SELECT id, COALESCE(charge_nr, CONCAT('#', id)) AS nr, mhd, menge_verfuegbar AS m, einheit FROM charge WHERE item_id=? AND status='frei' AND menge_verfuegbar>0 AND fremd_kunde_id IS NULL ORDER BY (mhd IS NULL), mhd, id", [(int)$z['item_id']]);
        $kum = 0.0; $grenzen = [];
        foreach ($ch as $c) {
            $kg = (float)$c['m'] * (in_array(strtolower((string)$c['einheit']), ['g'], true) ? 0.001 : (strtolower((string)$c['einheit']) === 'mg' ? 1e-6 : 1.0));
            $kum += $kg;
            $grenzen[] = ['bis_mix' => $kum / $anteil, 'charge' => (string)$c['nr']];   // bis zu dieser Mischmenge reicht die Charge
        }
        $plaene[$zi] = ['name' => (string)$z['name'], 'anteil' => $anteil, 'grenzen' => $grenzen];
    }
    // Bruchstellen: jedes Chargenende (vor dem Gesamtende) beendet einen Eimer
    $brueche = [];
    foreach ($plaene as $zi => $p) foreach (array_slice($p['grenzen'], 0, -1) as $g)
        if ($g['bis_mix'] > 0.0005 && $g['bis_mix'] < $total_kg - 0.0005) $brueche[] = ['x' => round($g['bis_mix'], 3), 'grund' => 'Charge ' . $g['charge'] . ' von ' . $p['name'] . ' dann aufgebraucht ist'];
    usort($brueche, fn($a, $b) => $a['x'] <=> $b['x']);
    $punkte = []; $gruende = [];
    foreach ($brueche as $b) { $k = (string)$b['x']; if (!isset($gruende[$k])) { $punkte[] = $b['x']; $gruende[$k] = $b['grund']; } else $gruende[$k] .= '; ' . $b['grund']; }
    // Abschnitte zwischen den Bruchstellen in Eimer ≤ Standard teilen
    $eimer = []; $von = 0.0;
    foreach (array_merge($punkte, [round($total_kg, 3)]) as $bis) {
        $seg = $bis - $von;
        if ($seg <= 0.0005) { $von = $bis; continue; }
        $n = max(1, (int) ceil($seg / $std - 1e-9));
        for ($i = 0; $i < $n; $i++) {
            $a = $von + $seg * $i / $n; $b = $von + $seg * ($i + 1) / $n;
            $eimer[] = ['von' => $a, 'bis' => $b, 'grund' => $i === $n - 1 && isset($gruende[(string)$bis]) ? $gruende[(string)$bis] : ''];
        }
        $von = $bis;
    }
    foreach ($eimer as $k => $e) {
        $kg = $e['bis'] - $e['von']; $mitte = ($e['von'] + $e['bis']) / 2;
        $zr = [];
        foreach ($plaene as $p) {
            $charge = '';
            foreach ($p['grenzen'] as $g) if ($mitte <= $g['bis_mix'] + 1e-9) { $charge = $g['charge']; break; }
            if ($charge === '' && $p['grenzen']) $charge = end($p['grenzen'])['charge'] . ' (Bestand reicht nicht)';
            $zr[] = ['name' => $p['name'], 'kg' => $kg * $p['anteil'], 'charge' => $charge];
        }
        $bs = $k < 26 ? chr(65 + $k) : 'Z' . chr(65 + min(25, $k - 26));
        $out['gebinde'][] = ['nr' => $k + 1, 'buchstabe' => $bs, 'kg' => round($kg, 3),
                             'grund' => $e['grund'] !== '' ? 'Dieser Eimer endet, weil ' . $e['grund'] . ' – danach neuer Eimer (neuer Buchstabe).' : '', 'zutaten' => $zr];
    }
    $out['anzahl'] = count($out['gebinde']);
    return $out;
}
