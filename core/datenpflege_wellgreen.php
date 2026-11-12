<?php
// Einmalige Datenpflege live: Wellgreen-Bestellungen gegen Maggis Liste („Maggi x Nico Order“, Stand 12.11.2026).
// Ausdrücklich freigegeben von Nico (12.11.2026, 20:15). Wellgreen wird über NICHTS informiert: keine Portal-Nachricht, keine Mail
// (bestellung_stornieren / bestellung_position_stornieren mit $lieferant_informieren=false, Verknüpfen ohne Meldung).
// Nur die EINDEUTIGEN Fälle (gleiches Produkt, Charge = Maggi-Menge ± 2 %, Eingang ± 5 Tage um Maggis Ankunft, genau eine Charge
// und genau eine Bestellung mit passendem Bestelldatum); alles andere steht nur in der Rückmeldung (Nico entscheidet).
// Läuft genau einmal (app_meta dpf_wg_erledigt) aus dem Schema-Abgleich und prüft jede Bedingung zur Laufzeit neu – passt etwas
// nicht mehr (z. B. inzwischen von Hand erledigt), bleibt es unberührt. Nichts wird gelöscht, keine Charge-Menge geändert.
// Ergebnis: app_meta dpf_wg_ergebnis (JSON), angezeigt unter ?p=wellgreen_abgleich. Siehe datenpflege_wellgreen.md.

const DPF_WG_VERMERK = 'Abgleich Maggi-Liste, Freigabe Nico 12.11.2026';

// Konfiguration live (aus dem Abgleich vom 12.11.2026 abends, live nur gelesen).
function dpf_wg_konfig(): array {
    return [
        'lieferant' => 'L-2789',
        // 1. Charge an die ursprüngliche Wellgreen-Bestellung hängen (Bestelldatum passt zu Maggi, Menge/Eingang passen).
        'links' => [
            ['charge' => 29,  'charge_nr' => '260908', 'menge' => 240000, 'nr' => 'BE-2876', 'bp' => 614, 'such' => 'Mitochondrien', 'maggi' => 'AUG Mitochondrien Komplex 240.000, Ankunft 30.09.'],
            ['charge' => 352, 'charge_nr' => '260919', 'menge' => 240000, 'nr' => 'BE-2880', 'bp' => 618, 'such' => 'Silizium',      'maggi' => 'AUG Silizium Komplex 240.000, Ankunft 15.10.'],
            ['charge' => 471, 'charge_nr' => '261017', 'menge' => 300000, 'nr' => 'BE-2877', 'bp' => 615, 'such' => 'Mens Komplex',  'maggi' => 'AUG Mens Komplex 305.000, Ankunft 06.11.'],
        ],
        // 2. Ganze Bestellung stornieren (doppelt).
        'storno' => [
            ['nr' => 'BE-2933', 'such' => 'Mikrozirkulation', 'menge' => 300000, 'grund' => 'doppelt zu BE-2878 (laut Maggi nur eine Bestellung)'],
        ],
        // 3. Einzelne Positionen stornieren (doppelt zu einer älteren, gelieferten Bestellung). 'nach' = nur, wenn diese Bestellung
        //    (nach Schritt 1) wirklich geliefert ist.
        'pos_storno' => [
            ['nr' => 'BE-2890', 'bp' => 638, 'such' => 'Mikrozirkulation', 'menge' => 300000, 'nach' => 'BE-2878', 'grund' => 'doppelt zu BE-2878'],
            ['nr' => 'BE-2890', 'bp' => 637, 'such' => 'Schilddrüsen',     'menge' => 240000, 'nach' => 'BE-2879', 'grund' => 'doppelt zu BE-2879 (Charge 260911)'],
            ['nr' => 'BE-2890', 'bp' => 636, 'such' => 'Silizium',         'menge' => 240000, 'nach' => 'BE-2880', 'grund' => 'doppelt zu BE-2880 (Charge 260919)'],
            ['nr' => 'BE-2895', 'bp' => 648, 'such' => 'Mens Komplex',     'menge' => 300000, 'nach' => 'BE-2877', 'grund' => 'doppelt zu BE-2877 (Charge 261017)'],
        ],
    ];
}

function dpf_wg_log(string $typ, int $id, string $text): void {
    if (function_exists('log_aktivitaet')) {
        try { log_aktivitaet($typ, $id, 'system', $text . ' (' . DPF_WG_VERMERK . ')', 'status'); } catch (\Throwable $e) {}
    }
}

// Ein Durchlauf mit beliebiger Konfiguration (Testkopie: eigene Daten). Rückgabe: Liste ['schritt','nr','bid','ok','text'].
function dpf_wg_ausfuehren(array $k): array {
    require_once __DIR__ . '/wareneingang_bestellung.php';
    $erg = [];
    $add = function (string $schritt, string $nr, int $bid, bool $ok, string $text) use (&$erg) { $erg[] = ['schritt' => $schritt, 'nr' => $nr, 'bid' => $bid, 'ok' => $ok, 'text' => $text]; };
    $lid = (int) (scalar("SELECT id FROM lieferanten WHERE lieferantennummer=?", [(string)$k['lieferant']]) ?: 0);
    if ($lid <= 0) { $add('Start', '', 0, false, 'Lieferant ' . $k['lieferant'] . ' nicht gefunden – nichts gemacht.'); return $erg; }
    $nachVor = (int) scalar("SELECT COALESCE(MAX(id),0) FROM nachricht");
    $bestNr = fn(string $nr) => one("SELECT * FROM bestellung WHERE nummer=?", [$nr]);
    $posIn = function (array $b, int $bp, string $such, float $menge) {
        return one("SELECT bp.*, COALESCE(NULLIF(bp.bezeichnung,''), i.name, '') AS pname, (SELECT COUNT(*) FROM charge c WHERE c.bestellung_position_id=bp.id) AS n_ch
                    FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id
                    WHERE bp.id=? AND bp.bestellung_id=? AND COALESCE(NULLIF(bp.bezeichnung,''), i.name, '') LIKE ? AND ABS(bp.menge - ?) < 0.5",
                   [$bp, (int)$b['id'], '%' . $such . '%', $menge]);
    };

    // 1. Verknüpfen
    foreach ($k['links'] ?? [] as $l) {
        $b = $bestNr($l['nr']);
        if (!$b || (int)$b['lieferant_id'] !== $lid || (string)$b['status'] === 'storniert') { $add('Verknüpfen', $l['nr'], (int)($b['id'] ?? 0), false, 'übersprungen: Bestellung fehlt, anderer Lieferant oder storniert'); continue; }
        $p = $posIn($b, (int)$l['bp'], (string)$l['such'], (float)$l['menge']);
        $c = one("SELECT * FROM charge WHERE id=? AND charge_nr=?", [(int)$l['charge'], (string)$l['charge_nr']]);
        if (!$p || !empty($p['rest_storniert_am']) || (int)$p['n_ch'] > 0) { $add('Verknüpfen', $l['nr'], (int)$b['id'], false, 'übersprungen: Position passt nicht mehr (Menge/Name, storniert oder schon mit Charge)'); continue; }
        if (!$c || !empty($c['bestellung_position_id']) || abs((float)$c['menge'] - (float)$l['menge']) > 0.5) { $add('Verknüpfen', $l['nr'], (int)$b['id'], false, 'übersprungen: Charge ' . $l['charge_nr'] . ' fehlt, ist schon verknüpft oder Menge geändert'); continue; }
        // Eingangsdatum der Bestellung = Wareneingang der Charge (nicht „heute“).
        if (empty($b['angekommen_am']) && !empty($c['wareneingang'])) q("UPDATE bestellung SET angekommen_am=? WHERE id=?", [substr((string)$c['wareneingang'], 0, 10), (int)$b['id']]);
        webe_charge_verknuepfen((int)$c['id'], (int)$p['id'], false, DPF_WG_VERMERK);
        $txt = 'Charge ' . $l['charge_nr'] . ' (' . number_format((float)$c['menge'], 0, ',', '.') . ' ' . $c['einheit'] . ', Eingang ' . ($c['wareneingang'] ? date('d.m.Y', strtotime((string)$c['wareneingang'])) : '–') . ') mit ' . $l['nr'] . ' verknüpft – laut Maggi: ' . $l['maggi'];
        dpf_wg_log('bestellung', (int)$b['id'], $txt);
        dpf_wg_log('charge', (int)$c['id'], $txt);
        $st = (string) scalar("SELECT status FROM bestellung WHERE id=?", [(int)$b['id']]);
        $add('Verknüpfen', $l['nr'], (int)$b['id'], true, $txt . ' · Bestellung jetzt ' . $st);
    }

    // 2. Ganze Bestellung stornieren, ohne Nachricht an den Lieferanten.
    foreach ($k['storno'] ?? [] as $s) {
        $b = $bestNr($s['nr']);
        $pos = $b ? all("SELECT bp.id, bp.menge, COALESCE(NULLIF(bp.bezeichnung,''), i.name, '') AS pname FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id WHERE bp.bestellung_id=? AND bp.menge>0", [(int)$b['id']]) : [];
        $passt = $b && (int)$b['lieferant_id'] === $lid && count($pos) === 1 && mb_stripos((string)$pos[0]['pname'], (string)$s['such']) !== false && abs((float)$pos[0]['menge'] - (float)$s['menge']) < 0.5;
        if (!$passt) { $add('Bestellung stornieren', $s['nr'], (int)($b['id'] ?? 0), false, 'übersprungen: Bestellung passt nicht mehr (Lieferant, Positionen, Menge)'); continue; }
        $r = bestellung_stornieren((int)$b['id'], 'Datenpflege', $s['grund'] . ' – ' . DPF_WG_VERMERK, false);
        $add('Bestellung stornieren', $s['nr'], (int)$b['id'], !empty($r['ok']), (string)($r['msg'] ?? '') . (!empty($r['ok']) ? ' Grund: ' . $s['grund'] : ''));
    }

    // 3. Einzelne Positionen stornieren, ohne Nachricht.
    foreach ($k['pos_storno'] ?? [] as $s) {
        $b = $bestNr($s['nr']);
        $p = $b ? $posIn($b, (int)$s['bp'], (string)$s['such'], (float)$s['menge']) : null;
        if (!$p || !empty($p['rest_storniert_am']) || (int)$p['n_ch'] > 0 || (float)$p['geliefert_menge'] > 0) { $add('Position stornieren', $s['nr'], (int)($b['id'] ?? 0), false, 'übersprungen: Position ' . $s['such'] . ' passt nicht mehr (Menge/Name, schon storniert oder Ware eingebucht)'); continue; }
        $nach = $bestNr((string)$s['nach']);
        $nachGel = $nach && ((string)$nach['status'] === 'geliefert' || !empty($nach['angekommen_am']))
                && (int) scalar("SELECT COUNT(*) FROM charge c JOIN bestellung_position bp ON bp.id=c.bestellung_position_id WHERE bp.bestellung_id=?", [(int)$nach['id']]) > 0;
        if (!$nachGel) { $add('Position stornieren', $s['nr'], (int)$b['id'], false, 'übersprungen: ' . $s['nach'] . ' ist (noch) nicht mit Charge geliefert'); continue; }
        $r = bestellung_position_stornieren((int)$p['id'], 'Datenpflege', $s['grund'] . ' – ' . DPF_WG_VERMERK, false);
        $add('Position stornieren', $s['nr'], (int)$b['id'], !empty($r['ok']), (string)$p['pname'] . ' (' . number_format((float)$p['menge'], 0, ',', '.') . '): ' . (string)($r['msg'] ?? '') . ' Grund: ' . $s['grund']);
    }

    $neu = (int) scalar("SELECT COUNT(*) FROM nachricht WHERE id>? AND lieferant_id=?", [$nachVor, $lid]);
    $add('Kontrolle', '', 0, $neu === 0, 'Neue Portal-Nachrichten an ' . $k['lieferant'] . ' während der Datenpflege: ' . $neu);
    if (function_exists('bedarf_bump')) bedarf_bump();
    return $erg;
}

// Aus dem Schema-Abgleich: genau einmal, nur mit der Live-Konfiguration (Testumgebung nie).
function dpf_wg_einmal(): void {
    try {
        if (meta_get('dpf_wg_erledigt', '') === '1' || !column_exists('bestellung_position', 'pos_storno_grund')) return;
        if (function_exists('bx_testumgebung') && bx_testumgebung()) return;
        meta_set('dpf_wg_erledigt', '1');   // zuerst setzen: lieber nie als doppelt
        $erg = dpf_wg_ausfuehren(dpf_wg_konfig());
        meta_set('dpf_wg_ergebnis', json_encode($erg, JSON_UNESCAPED_UNICODE));
    } catch (\Throwable $e) {
        try { meta_set('dpf_wg_ergebnis', 'Fehler: ' . $e->getMessage()); } catch (\Throwable $e2) {}
    }
}
