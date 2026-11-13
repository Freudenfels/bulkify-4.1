<?php
// Reservierung ehrlich anzeigen (Sofortfix 13.11.2026, Teil 3). Eine Reservierung ist keine Ware: Reserviert man mehr, als im Lager
// liegt bzw. unterwegs ist, sah das bisher aus wie „6 kg da, reserviert“ (Sanddorn, PR-2761). EINE Rechnung für Rohstoff-Kopf,
// Reiter Lager, Reservierungsliste, PR (Spalte „Reserviert“) und Auftrag (Reiter Material):
//   im Lager    = durch freien Bestand gedeckt
//   unterwegs   = durch offene Bestellungen gedeckt (item_unterwegs, ohne Sperr-Entwürfe und Storno)
//   ungedeckt   = weder noch (rot)
// Reihenfolge der Deckung: Reservierungen aus der Produktion zuerst, dann manuelle nach Anlage. Reservierungen auf eine konkrete Charge
// (charge_id) zählen auf diese Charge, auf eine Bestellposition (bestellung_position_id) auf deren offenen Rest.
// PR an der Reservierung: Produktion = pa_id; manuell = ziel_pa_id (beim Anlegen der einzige offene PR des Auftrags) bzw. – für alte
// Reservierungen – nur in der Anzeige der einzige offene PR des Auftrags (nicht in die Datenbank geschrieben).
// Dashboard-Datei (braucht core/schema.php).

// Der einzige offene Produktionsauftrag eines Auftrags (nicht erledigt/storniert) – null bei keinem oder mehreren.
function res_einzig_offener_pa(int $auftrag_id): ?array {
    if ($auftrag_id <= 0) return null;
    static $c = [];
    if (array_key_exists($auftrag_id, $c)) return $c[$auftrag_id];
    $r = all("SELECT id, nummer FROM produktionsauftrag WHERE auftrag_id=? AND status NOT IN ('erledigt','storniert') ORDER BY id LIMIT 2", [$auftrag_id]);
    return $c[$auftrag_id] = count($r) === 1 ? ['id' => (int)$r[0]['id'], 'nummer' => (string)$r[0]['nummer']] : null;
}
// PR einer Reservierung für die Anzeige: ['id','nummer','abgeleitet'(bool)] oder null.
function res_pa_anzeige(array $r): ?array {
    foreach (['pa_id', 'ziel_pa_id'] as $sp)
        if (!empty($r[$sp])) { $n = (string) scalar("SELECT nummer FROM produktionsauftrag WHERE id=?", [(int)$r[$sp]]); return ['id' => (int)$r[$sp], 'nummer' => $n, 'abgeleitet' => false]; }
    $p = res_einzig_offener_pa((int)($r['auftrag_id'] ?? 0));
    return $p ? $p + ['abgeleitet' => true] : null;
}
// Deckung aller aktiven Reservierungen eines Artikels. Rückgabe ['frei','unterwegs','reserviert','lager','unterwegs_res','ungedeckt',
// 'zeilen' => [res_id => ['menge','lager','unterwegs','ungedeckt']]].
function reservierung_deckung(int $item_id): array {
    static $cache = [];
    if (isset($cache[$item_id])) return $cache[$item_id];
    $frei = (float) item_bestand($item_id, true);
    $unt  = function_exists('item_unterwegs') ? (float) item_unterwegs($item_id) : 0.0;
    $poolL = $frei; $poolU = $unt;
    $zeilen = []; $sL = $sU = $sX = $sR = 0.0;
    $rows = all("SELECT r.*, c.menge_verfuegbar AS c_frei, c.status AS c_status,
                        (SELECT IF(bp.rest_storniert_am IS NULL, GREATEST(bp.menge - COALESCE(bp.geliefert_menge,0), 0), 0) FROM bestellung_position bp WHERE bp.id=r.bestellung_position_id) AS bp_rest
                 FROM reservierung r LEFT JOIN charge c ON c.id=r.charge_id
                 WHERE r.item_id=? AND r.status='aktiv' ORDER BY (r.pa_id IS NULL), r.angelegt, r.id", [$item_id]);
    foreach ($rows as $r) {
        $m = max(0.0, (float)$r['menge']); $l = $u = 0.0;
        if (!empty($r['charge_id'])) { $l = min($m, max(0.0, (float)$r['c_frei'])); $poolL = max(0.0, $poolL - $l); }
        elseif (!empty($r['bestellung_position_id'])) { $u = min($m, max(0.0, (float)$r['bp_rest'])); $poolU = max(0.0, $poolU - $u); }
        else { $l = min($m, $poolL); $poolL -= $l; $u = min($m - $l, $poolU); $poolU -= $u; }
        $x = max(0.0, $m - $l - $u);
        $zeilen[(int)$r['id']] = ['menge' => $m, 'lager' => $l, 'unterwegs' => $u, 'ungedeckt' => $x];
        $sR += $m; $sL += $l; $sU += $u; $sX += $x;
    }
    return $cache[$item_id] = ['frei' => $frei, 'unterwegs' => $unt, 'reserviert' => $sR, 'lager' => $sL, 'unterwegs_res' => $sU, 'ungedeckt' => $sX, 'zeilen' => $zeilen];
}
// Deckung je Auftrag (Summe seiner Reservierungen dieses Artikels).
function reservierung_deckung_auftrag(int $item_id, int $auftrag_id): array {
    $d = reservierung_deckung($item_id);
    $o = ['menge' => 0.0, 'lager' => 0.0, 'unterwegs' => 0.0, 'ungedeckt' => 0.0, 'pa' => null];
    foreach (all("SELECT * FROM reservierung WHERE item_id=? AND auftrag_id=? AND status='aktiv'", [$item_id, $auftrag_id]) as $r) {
        $z = $d['zeilen'][(int)$r['id']] ?? null; if (!$z) continue;
        foreach (['menge', 'lager', 'unterwegs', 'ungedeckt'] as $k) $o[$k] += $z[$k];
        $o['pa'] = $o['pa'] ?? res_pa_anzeige($r);
    }
    return $o;
}
// Kurzer Text: „reserviert 6 kg – im Lager 0, unterwegs 0, ungedeckt 6 kg“ (ungedeckt rot). $html=false = reiner Text.
function reservierung_deckung_text(array $z, string $einheit, bool $html = true): string {
    $f = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
    $e = $einheit !== '' ? ' ' . $einheit : '';
    $t = 'im Lager ' . $f($z['lager']) . ', unterwegs ' . $f($z['unterwegs']) . ', ';
    $x = 'ungedeckt ' . $f($z['ungedeckt']) . $e;
    if (!$html) return $t . $x;
    return h($t) . ((float)$z['ungedeckt'] > 1e-9 ? '<span style="color:#c0392b;font-weight:600">' . h($x) . '</span>' : h($x));
}
// Bestellungen dieses Artikels mit vorgemerkter, nicht eingebuchter Charge (ohne Ware geschlossen bzw. noch nicht eingebucht):
// [['bestellung_id','nummer','bp_id','bch_id','charge_nr','zu'(bool)]] – für „BE-xxxx: Charge eingetragen, nicht eingebucht – jetzt einbuchen“.
function item_nicht_eingebuchte_vormerkungen(int $item_id): array {
    if ($item_id <= 0) return [];
    require_once __DIR__ . '/wareneingang_bestellung.php';
    $out = [];
    try {
        foreach (all("SELECT bp.id, bp.bestellung_id, b.nummer, bp.rest_storniert_am FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id
                      WHERE bp.item_id=? AND b.status<>'storniert' AND COALESCE(bp.pos_storno_grund,'')=''
                        AND EXISTS (SELECT 1 FROM bestellung_charge bc WHERE bc.bestellung_position_id=bp.id) ORDER BY b.id DESC LIMIT 20", [$item_id]) as $p) {
            $v = webe_vormerkung((int)$p['id']);
            if ($v) $out[] = ['bestellung_id' => (int)$p['bestellung_id'], 'nummer' => (string)$p['nummer'], 'bp_id' => (int)$p['id'], 'bch_id' => (int)$v['id'],
                              'charge_nr' => (string)$v['charge_nr'], 'zu' => !empty($p['rest_storniert_am'])];
        }
    } catch (\Throwable $e) {}
    return $out;
}
