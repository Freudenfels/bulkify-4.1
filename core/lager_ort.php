<?php
// Lagerort einer Charge fürs Dashboard (Nico 12.11.2026: „Ich sehe im Warenlager nicht, welcher Blinker, keinen Bestand,
// kann nichts anpassen“). Liest die Tabellen der Lager-App (lg_leiste, lg_kiste_inhalt/lg_kiste, lg_platz_inhalt/lg_platz)
// nur LESEND und best-effort: fehlt eine Tabelle (z. B. neue Installation ohne Lager-App), bleibt der Ort leer.
// Gebraucht von module/lager/rohstoff_detail.php und module/lager/verpackung_detail.php (Reiter „Lager“).

if (!function_exists('lager_ort_je_charge')) {

// [charge_id => ['blinker'=>[Codes], 'kiste'=>'Kiste X · Fach', 'platz'=>'A-01-2 (LP-00012)', 'text'=>'…']]
function lager_ort_je_charge(array $charge_ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $charge_ids), fn($x) => $x > 0)));
    $out = [];
    foreach ($ids as $cid) $out[$cid] = ['blinker' => [], 'kiste' => '', 'platz' => '', 'text' => ''];
    if (!$ids) return $out;
    $in = implode(',', $ids);
    try {
        if (table_exists('lg_leiste'))
            foreach (all("SELECT charge_id, code FROM lg_leiste WHERE charge_id IN ($in) ORDER BY code") as $r)
                $out[(int)$r['charge_id']]['blinker'][] = (string)$r['code'];
    } catch (Throwable $e) { /* best effort */ }
    try {
        if (table_exists('lg_kiste_inhalt') && table_exists('lg_kiste'))
            foreach (all("SELECT ki.charge_id, ki.fach, k.name FROM lg_kiste_inhalt ki JOIN lg_kiste k ON k.id=ki.kiste_id WHERE ki.charge_id IN ($in)") as $r)
                $out[(int)$r['charge_id']]['kiste'] = 'Kiste ' . (string)$r['name'] . (trim((string)$r['fach']) !== '' ? ' · ' . trim((string)$r['fach']) : '');
    } catch (Throwable $e) { /* best effort */ }
    try {
        if (table_exists('lg_platz_inhalt') && table_exists('lg_platz'))
            foreach (all("SELECT pi.charge_id, p.* FROM lg_platz_inhalt pi JOIN lg_platz p ON p.id=pi.platz_id WHERE pi.charge_id IN ($in) ORDER BY pi.gebinde_id") as $r) {
                $cid = (int)$r['charge_id'];
                if ($out[$cid]['platz'] !== '') continue;   // je Charge der erste Platz (Gebinde können verteilt liegen)
                $name = trim((string)($r['bezeichnung'] ?? '')) !== '' ? trim((string)$r['bezeichnung'])
                      : (string)$r['bereich'] . '-' . str_pad((string)(int)$r['regal'], 2, '0', STR_PAD_LEFT) . '-' . (int)$r['ebene'] . ((int)$r['fach'] > 1 ? '-' . (int)$r['fach'] : '');
                $out[$cid]['platz'] = $name . ' (LP-' . str_pad((string)(int)$r['id'], 5, '0', STR_PAD_LEFT) . ')';
            }
    } catch (Throwable $e) { /* best effort */ }
    foreach ($out as $cid => $o) {
        $t = [];
        if ($o['blinker']) $t[] = 'Blinker ' . implode(', ', $o['blinker']);
        if ($o['kiste'] !== '') $t[] = $o['kiste'];
        if ($o['platz'] !== '') $t[] = 'Platz ' . $o['platz'];
        $out[$cid]['text'] = implode(' · ', $t);
    }
    return $out;
}

// Alle Blinker-Codes eines Artikels (über seine Chargen mit Bestand) – für die Kachel oben.
function lager_blinker_fuer_item(int $item_id): array {
    if ($item_id <= 0) return [];
    try {
        if (!table_exists('lg_leiste')) return [];
        return array_map(fn($r) => (string)$r['code'], all("SELECT DISTINCT l.code FROM lg_leiste l JOIN charge c ON c.id=l.charge_id
                                                            WHERE c.item_id=? ORDER BY l.code", [$item_id]));
    } catch (Throwable $e) { return []; }
}

// Zelle „Ort“ für die Chargentabelle: Text oder grauer Strich, plus Link in die Lager-App (Charge finden/blinken).
function lager_ort_zelle(array $ort, int $charge_id): string {
    $txt = trim((string)($ort['text'] ?? ''));
    return ($txt !== '' ? h($txt) : '<span class="muted">nicht eingelagert</span>')
         . ' <a class="muted" style="font-size:12px;white-space:nowrap" target="_blank" href="/lager/?p=charge&amp;id=' . $charge_id . '" title="In der Lager-App öffnen: Ort, Blinker klingeln, umlagern">Lager-App</a>';
}

}
