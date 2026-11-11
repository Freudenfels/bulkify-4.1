<?php
// Inventur – Abgleich Soll/Ist (11.11.2026). Entsteht bei „Platz fertig" (inv_abgleich_bereich) bzw. „Zählung beenden"
// (nicht gefunden). Soll = Buchbestand ZUM SCAN-ZEITPUNKT (aus lg_inventur_zaehlung). Sichtbar nur für den Admin.

function inv_diff_arten(): array {
    return ['passt' => 'Gefunden, passt', 'menge' => 'Menge weicht ab', 'nicht_gefunden' => 'Nicht gefunden', 'ohne_buchung' => 'Gefunden ohne Buchung',
            'falscher_platz' => 'Falscher Platz', 'einheit' => 'Einheit verdächtig', 'leer' => 'Gebinde leer'];
}
// Art einer Abweichung für einen Scan. $o = aktuelles Objekt (für Artikel-Einheit), $z = Scan.
function inv_diff_bewerten(array $inv, array $z, ?array $o): array {
    $soll = (float)$z['soll']; $ist = (float)$z['ist'];
    $se = erp_einheit_norm((string)$z['soll_einheit']); $ie = erp_einheit_norm((string)$z['einheit']);
    if ((string)$z['obj'] === 'k') return ['art' => 'ohne_buchung', 'diff' => $ist, 'prozent' => null];
    if ((string)$z['art'] === 'leer') return $soll > 0 ? ['art' => 'leer', 'diff' => -$soll, 'prozent' => -100.0] : ['art' => 'passt', 'diff' => 0.0, 'prozent' => 0.0];
    if ($soll <= 0.0005) return $ist > 0.0005 ? ['art' => 'ohne_buchung', 'diff' => $ist, 'prozent' => null] : ['art' => 'passt', 'diff' => 0.0, 'prozent' => 0.0];
    $diff = round($ist - $soll, 3); $proz = round($diff / $soll * 100, 2);
    // Einheit verdächtig: Soll in anderer Einheit als der Artikel (kg statt Stk) oder Faktor ~1.000 (12.000 kg gebucht, 12 kg da).
    $q = $ist > 0 ? $soll / $ist : 0;
    if (($se !== '' && $ie !== '' && $se !== $ie) || ($q >= 900 && $q <= 1100) || ($q > 0 && $q >= 1 / 1100 && $q <= 1 / 900))
        return ['art' => 'einheit', 'diff' => $diff, 'prozent' => $proz];
    $tol = in_array((string)$z['art'], ['wiegen', 'kapsel'], true) ? (float)$inv['tol_gewogen'] : (float)$inv['tol_stk'];
    if (abs($diff) <= $soll * $tol / 100 + 0.0005) return ['art' => 'passt', 'diff' => $diff, 'prozent' => $proz];
    return ['art' => 'menge', 'diff' => $diff, 'prozent' => $proz];
}
// Eine Abweichungszeile schreiben bzw. aktualisieren (nur solange nicht entschieden). Rückgabe diff-id.
function inv_diff_setzen(array $inv, array $d): int {
    $alt = one("SELECT id, status FROM lg_inventur_diff WHERE inventur_id=? AND obj=? AND gebinde_id=? AND charge_id=? AND art" . ($d['art'] === 'falscher_platz' ? "='falscher_platz'" : "<>'falscher_platz'") . ($d['obj'] === 'k' ? " AND zaehlung_id=?" : "") . " ORDER BY id DESC LIMIT 1",
               array_merge([(int)$inv['id'], $d['obj'], (int)$d['gebinde_id'], (int)$d['charge_id']], $d['obj'] === 'k' ? [(int)$d['zaehlung_id']] : []));
    if ($alt && in_array((string)$alt['status'], ['gebucht', 'offen_gelassen'], true)) return (int)$alt['id'];
    $f = ['bereich_id', 'zaehlung_id', 'obj', 'gebinde_id', 'charge_id', 'item_id', 'art', 'soll', 'soll_einheit', 'ist', 'einheit', 'diff', 'diff_prozent', 'wert_eur', 'soll_ort', 'ist_ort', 'status', 'erster_zaehler', 'notiz'];
    $v = array_map(fn($k) => $d[$k] ?? null, $f);
    if ($alt) { q("UPDATE lg_inventur_diff SET " . implode(',', array_map(fn($k) => "$k=?", $f)) . " WHERE id=?", array_merge($v, [(int)$alt['id']])); return (int)$alt['id']; }
    q("INSERT INTO lg_inventur_diff (inventur_id," . implode(',', $f) . ") VALUES (?" . str_repeat(',?', count($f)) . ")", array_merge([(int)$inv['id']], $v));
    return insert_id();
}
// Abgleich eines Scans (gilt der neueste Durchgang).
function inv_abgleich_scan(array $inv, array $z, string $bereich_key, bool $nachzaehl_pruefen = true): void {
    $o = (string)$z['obj'] === 'k' ? null : erp_inv_objekt((string)$z['obj'], (int)((string)$z['obj'] === 'g' ? $z['gebinde_id'] : $z['charge_id']));
    $b = inv_diff_bewerten($inv, $z, $o);
    $wo = $o ?: ['ek_preis' => (erp_inv_item((int)$z['item_id'])['ek_preis'] ?? 0), 'preis_bezug' => (erp_inv_item((int)$z['item_id'])['preis_bezug'] ?? ''), 'item_einheit' => (string)$z['einheit']];
    $status = $b['art'] === 'passt' ? 'passt' : 'offen';
    if ($nachzaehl_pruefen && (int)$z['durchgang'] === 1 && $inv['nachzaehl_prozent'] !== null && in_array($b['art'], ['menge', 'einheit'], true)
        && $b['prozent'] !== null && abs((float)$b['prozent']) > (float)$inv['nachzaehl_prozent']) $status = 'nachzaehlen';
    inv_diff_setzen($inv, [
        'bereich_id' => (int)$z['bereich_id'], 'zaehlung_id' => (int)$z['id'], 'obj' => (string)$z['obj'], 'gebinde_id' => (int)$z['gebinde_id'], 'charge_id' => (int)$z['charge_id'],
        'item_id' => (int)$z['item_id'], 'art' => $b['art'], 'soll' => (float)$z['soll'], 'soll_einheit' => (string)$z['soll_einheit'], 'ist' => (float)$z['ist'], 'einheit' => (string)$z['einheit'],
        'diff' => $b['diff'], 'diff_prozent' => $b['prozent'], 'wert_eur' => $b['art'] === 'einheit' ? null : erp_inv_wert($wo, (float)$b['diff'], (string)$z['soll_einheit']),   // Einheitenfehler: Wert erst nach Korrektur sinnvoll
        'soll_ort' => $z['soll_ort'], 'ist_ort' => $bereich_key, 'status' => $status, 'erster_zaehler' => (int)$z['zaehler_id'] ?: null,
        'notiz' => (int)$z['durchgang'] === 2 ? 'Nachgezählt von ' . erp_inv_benutzer_name((int)$z['zaehler_id']) : null,
    ]);
    // Falscher Platz: gebucht an einem anderen Platz/Kiste als gezählt -> eigene Zeile (Umlagerung buchen).
    if ((string)$z['obj'] !== 'k' && (string)($z['soll_ort'] ?? '') !== '' && (string)$z['soll_ort'] !== $bereich_key)
        inv_diff_setzen($inv, ['bereich_id' => (int)$z['bereich_id'], 'zaehlung_id' => (int)$z['id'], 'obj' => (string)$z['obj'], 'gebinde_id' => (int)$z['gebinde_id'], 'charge_id' => (int)$z['charge_id'],
            'item_id' => (int)$z['item_id'], 'art' => 'falscher_platz', 'soll' => null, 'soll_einheit' => null, 'ist' => null, 'einheit' => null, 'diff' => 0, 'diff_prozent' => null, 'wert_eur' => null,
            'soll_ort' => $z['soll_ort'], 'ist_ort' => $bereich_key, 'status' => 'offen', 'erster_zaehler' => null, 'notiz' => null]);
}
// Abgleich eines fertig gezählten Platzes.
function inv_abgleich_bereich(array $inv, int $bereich_id): void {
    $b = inv_bereich($bereich_id);
    if (!$b) return;
    foreach (all("SELECT z.* FROM lg_inventur_zaehlung z WHERE z.bereich_id=? AND z.ersetzt=0 AND z.durchgang=1", [$bereich_id]) as $z) {
        $zwei = one("SELECT * FROM lg_inventur_zaehlung WHERE inventur_id=? AND obj=? AND gebinde_id=? AND charge_id=? AND durchgang=2 AND ersetzt=0 ORDER BY id DESC LIMIT 1",
                    [(int)$inv['id'], (string)$z['obj'], (int)$z['gebinde_id'], (int)$z['charge_id']]);
        inv_abgleich_scan($inv, $zwei ?: $z, (string)$b['schluessel'], !$zwei);
    }
}
// Nachzählung (Durchgang 2) liegt vor: bestätigt die zweite Person den Wert, gilt er. Die Zeile wird neu bewertet (ohne erneutes Nachzählen).
function inv_nachzaehlung_uebernehmen(array $inv, int $diff_id, int $zaehlung_id): void {
    $z = one("SELECT z.*, b.schluessel FROM lg_inventur_zaehlung z JOIN lg_inventur_bereich b ON b.id=z.bereich_id WHERE z.id=?", [$zaehlung_id]);
    $erst = one("SELECT soll, soll_einheit, soll_ort FROM lg_inventur_zaehlung WHERE inventur_id=? AND obj=? AND gebinde_id=? AND charge_id=? AND durchgang=1 AND ersetzt=0 ORDER BY id DESC LIMIT 1",
                [(int)$inv['id'], (string)$z['obj'], (int)$z['gebinde_id'], (int)$z['charge_id']]);
    if ($erst) { $z['soll'] = $erst['soll']; $z['soll_einheit'] = $erst['soll_einheit']; }   // Soll bleibt das vom ersten Scan (Stichtag-nah)
    q("UPDATE lg_inventur_zaehlung SET soll=?, soll_einheit=? WHERE id=?", [$z['soll'], $z['soll_einheit'], $zaehlung_id]);
    q("UPDATE lg_inventur_diff SET status='offen' WHERE id=?", [$diff_id]);
    inv_abgleich_scan($inv, $z, (string)$z['schluessel'], false);
}
// Zählung beenden: alles, was im Umfang gebucht ist, aber nirgends gescannt wurde -> „nicht gefunden".
// Ganzes Lager: alles im Umfang. Einzelne Plätze/Kisten: nur was auf diesen Plätzen/Kisten gebucht ist.
function inv_zaehlung_beenden(array $inv): array {
    $offen = all("SELECT name FROM lg_inventur_bereich WHERE inventur_id=? AND status<>'fertig'", [(int)$inv['id']]);
    if ($offen) return ['ok' => false, 'msg' => 'Noch nicht fertig gezählt: ' . implode(', ', array_column($offen, 'name')) . '.'];
    $keys = array_flip(array_column(inv_bereiche((int)$inv['id']), 'schluessel'));
    $gez = [];
    foreach (all("SELECT obj, gebinde_id, charge_id FROM lg_inventur_zaehlung WHERE inventur_id=? AND ersetzt=0 AND obj<>'k'", [(int)$inv['id']]) as $z) $gez[$z['obj'] . ':' . (int)$z['gebinde_id'] . ':' . (int)$z['charge_id']] = 1;
    $n = 0;
    foreach (erp_inv_objekte($inv) as $o) {
        if (isset($gez[$o['obj'] . ':' . (int)$o['gebinde_id'] . ':' . (int)$o['charge_id']])) continue;
        // Gebinde gezählt als ganze Charge (oder umgekehrt)? Dann nicht doppelt melden.
        if ($o['obj'] === 'g' && isset($gez['c:0:' . (int)$o['charge_id']])) continue;
        $ort = inv_ort_von((int)$o['charge_id'], (int)$o['gebinde_id']);
        if (!(int)$inv['ganz'] && !isset($keys[$ort['key']])) continue;
        inv_diff_setzen($inv, ['bereich_id' => null, 'zaehlung_id' => null, 'obj' => $o['obj'], 'gebinde_id' => (int)$o['gebinde_id'], 'charge_id' => (int)$o['charge_id'], 'item_id' => (int)$o['item_id'],
            'art' => 'nicht_gefunden', 'soll' => (float)$o['soll'], 'soll_einheit' => (string)$o['einheit'], 'ist' => 0, 'einheit' => (string)$o['item_einheit'],
            'diff' => -(float)$o['soll'], 'diff_prozent' => -100, 'wert_eur' => erp_inv_wert($o, -(float)$o['soll'], (string)$o['einheit']),
            'soll_ort' => $ort['key'] ?: null, 'ist_ort' => null, 'status' => 'offen', 'erster_zaehler' => null, 'notiz' => null]);
        $n++;
    }
    q("UPDATE lg_inventur SET status='auswerten', zaehlung_ende=? WHERE id=?", [jetzt_utc(), (int)$inv['id']]);
    $nie = 0;
    if ((int)$inv['ganz']) $nie = (int) scalar("SELECT COUNT(*) FROM lg_platz p WHERE p.aktiv=1 AND p.lager=? AND NOT EXISTS (SELECT 1 FROM lg_inventur_bereich b WHERE b.inventur_id=? AND b.schluessel=CONCAT('p:',p.id))",
                                               [(string)$inv['lager'] === '2' ? 'lager2' : 'lager1', (int)$inv['id']]);
    return ['ok' => true, 'msg' => 'Zählung beendet. ' . $n . ' Gebinde/Chargen nicht gefunden.' . ($nie ? ' Hinweis: ' . $nie . ' Plätze wurden nie gescannt.' : '') . ' Jetzt auswerten.'];
}
