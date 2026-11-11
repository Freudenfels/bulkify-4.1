<?php
// Inventur – Differenzen auflösen (11.11.2026). Nichts wird automatisch gebucht: Nico wählt je Zeile eine Ursache, erst dann
// wird gebucht. Jede Korrektur = Bewegung in lg_bewegung (typ 'inventur', inventur_id, ursache, Grund „Inventur <Name> <Datum> · …").
// Gebucht wird die DIFFERENZ zum Scan-Zeitpunkt auf den aktuellen Bestand – Entnahmen während der Inventur bleiben richtig.

// Ursache -> Aktion und für welche Abweichungsarten sie passt.
function inv_ursachen(): array {
    return [
        'zaehlfehler' => ['Zählfehler', 'Nachzählen anstoßen', ['menge', 'einheit', 'nicht_gefunden', 'leer']],
        'schwund'     => ['Schwund / Bruch / Verderb', 'Ist übernehmen (Korrekturbuchung)', ['menge', 'nicht_gefunden', 'leer']],
        'einheit'     => ['Einheitenfehler', 'Einheit/Faktor der Charge korrigieren, Menge neu rechnen', ['einheit', 'menge']],
        'doppelt'     => ['Doppelbuchung', 'Doppelte Charge ausbuchen, mit Verweis auf die andere', ['nicht_gefunden', 'menge', 'einheit']],
        'dublette'    => ['Falscher Artikel / Dublette', 'Charge auf den richtigen Artikel umbuchen', ['menge', 'passt', 'einheit', 'ohne_buchung']],
        'nicht_gebucht' => ['Gefunden, nicht gebucht', 'Charge anlegen (Wareneingang ohne Bestellung, Grund „Inventur“) bzw. Ist übernehmen', ['ohne_buchung']],
        'platz'       => ['Falscher Platz', 'Umlagerung buchen', ['falscher_platz']],
        'leer'        => ['Gebinde leer', 'Ausbuchen', ['leer', 'nicht_gefunden']],
        'offen'       => ['Offen lassen', 'Mit Notiz bewusst offen lassen (nichts gebucht)', ['menge', 'nicht_gefunden', 'ohne_buchung', 'falscher_platz', 'einheit', 'leer']],
    ];
}
function inv_grund(array $inv, string $ursache): string {
    return 'Inventur ' . (string)$inv['name'] . ' ' . date('d.m.Y', strtotime((string)$inv['stichtag'])) . ' · ' . (inv_ursachen()[$ursache][0] ?? $ursache);
}
function inv_bewegung(array $inv, int $charge_id, float $menge, string $einheit, string $item_name, string $ursache, string $text = ''): void {
    q("INSERT INTO lg_bewegung (charge_id,item_name,typ,menge,einheit,grund,benutzer_id,angelegt,inventur_id,ursache) VALUES (?,?,'inventur',?,?,?,?,?,?,?)",
      [$charge_id ?: null, $item_name ?: null, $menge, $einheit ?: null, mb_substr(inv_grund($inv, $ursache) . ($text !== '' ? ' · ' . $text : ''), 0, 190),
       lg_uid() ?: null, jetzt_utc(), (int)$inv['id'], $ursache]);
}
// Eine Abweichung entscheiden. $p: notiz, faktor, einheit, andere_charge, ziel_item. Rückgabe ['ok','msg'].
function inv_entscheiden(array $inv, int $diff_id, string $ursache, array $p = []): array {
    if ((string)$inv['status'] === 'abgeschlossen') return ['ok' => false, 'msg' => 'Inventur ist abgeschlossen.'];
    $d = one("SELECT * FROM lg_inventur_diff WHERE id=? AND inventur_id=?", [$diff_id, (int)$inv['id']]);
    if (!$d) return ['ok' => false, 'msg' => 'Zeile nicht gefunden.'];
    if (in_array((string)$d['status'], ['gebucht'], true)) return ['ok' => false, 'msg' => 'Schon gebucht.'];
    $U = inv_ursachen();
    if (!isset($U[$ursache]) || !in_array((string)$d['art'], $U[$ursache][2], true)) return ['ok' => false, 'msg' => 'Diese Ursache passt nicht zu „' . (inv_diff_arten()[$d['art']] ?? $d['art']) . '“.'];
    $notiz = trim((string)($p['notiz'] ?? ''));
    $cid = (int)$d['charge_id']; $gid = (int)$d['gebinde_id'];
    $delta = round((float)$d['ist'] - (float)$d['soll'], 3);   // Differenz zum Scan-Zeitpunkt (Einheit des Solls)
    $erg = ''; $status = 'gebucht';
    $o = $cid ? erp_inv_objekt($gid ? 'g' : 'c', $gid ?: $cid) : null;
    $name = (string)($o['item_name'] ?? (erp_inv_item((int)$d['item_id'])['name'] ?? ''));
    $se = (string)($d['soll_einheit'] ?: ($o['einheit'] ?? ''));
    $mz = fn($v) => inv_mz($v);

    switch ($ursache) {
        case 'zaehlfehler':
            $erster = (int) scalar("SELECT zaehler_id FROM lg_inventur_zaehlung WHERE id=?", [(int)$d['zaehlung_id']]);
            // „Nicht gefunden" hat keinen Zählplatz: nachgezählt wird am gebuchten Platz (falls Teil der Inventur).
            $bid = (int)$d['bereich_id'] ?: (int) scalar("SELECT id FROM lg_inventur_bereich WHERE inventur_id=? AND schluessel=?", [(int)$inv['id'], (string)$d['soll_ort']]);
            q("UPDATE lg_inventur_diff SET status='nachzaehlen', bereich_id=?, erster_zaehler=?, ursache='zaehlfehler', notiz=?, entschieden_von=?, entschieden_am=? WHERE id=?", [$bid ?: null, $erster ?: null, $notiz ?: null, lg_uid() ?: null, jetzt_utc(), $diff_id]);
            return ['ok' => true, 'msg' => 'Nachzählen angestoßen – erscheint beim Zählen für eine zweite Person.'];
        case 'offen':
            if ($notiz === '') return ['ok' => false, 'msg' => 'Bitte eine Notiz angeben, warum es offen bleibt.'];
            $status = 'offen_gelassen'; $erg = 'bewusst offen gelassen';
            break;
        case 'schwund':
        case 'leer':
            if (!$cid) return ['ok' => false, 'msg' => 'Kein Bestand zum Korrigieren.'];
            $r = $ursache === 'leer' && (string)$d['art'] === 'leer' ? erp_inv_ausbuchen($cid, $gid, inv_grund($inv, 'leer')) : erp_inv_korrektur($cid, $gid, $delta);
            if (!$r['ok']) return $r;
            inv_bewegung($inv, $cid, $r['delta'], $r['einheit'], $name, $ursache, $notiz);
            $erg = ($r['delta'] >= 0 ? '+' : '') . $mz($r['delta']) . ' ' . $r['einheit'] . ' gebucht (Bestand jetzt ' . $mz($r['neu']) . ')';
            break;
        case 'einheit':
            if (!$cid) return ['ok' => false, 'msg' => 'Keine Charge.'];
            $f = erp_menge_parse((string)($p['faktor'] ?? '1')) ?: 1.0;
            $neuE = (string)($p['einheit'] ?: ($o['item_einheit'] ?? $se));
            $r = erp_inv_einheit_korrigieren($cid, $f, $neuE);
            if (!$r['ok']) return $r;
            inv_bewegung($inv, $cid, 0, $r['einheit'], $name, 'einheit', 'Faktor ' . $mz($f) . ', ' . $r['alt_einheit'] . ' → ' . $r['einheit'] . ($notiz !== '' ? ' · ' . $notiz : ''));
            // Menge neu rechnen: Rest-Differenz zum gezählten Ist (Soll zum Scan × Faktor) gleich mit buchen.
            $rest = round((float)$d['ist'] - (float)$d['soll'] * $f, 3);
            $erg = 'Einheit ' . $r['alt_einheit'] . ' → ' . $r['einheit'] . ', Faktor ' . $mz($f);
            if (abs($rest) > 0.0005) {
                $k = erp_inv_korrektur($cid, $gid, $rest);
                if ($k['ok']) { inv_bewegung($inv, $cid, $k['delta'], $k['einheit'], $name, 'einheit', 'Rest nach Umrechnung'); $erg .= ', Rest ' . ($k['delta'] >= 0 ? '+' : '') . $mz($k['delta']) . ' ' . $k['einheit']; }
            }
            break;
        case 'doppelt':
            $andere = (int)($p['andere_charge'] ?? 0);
            if (!$cid || $andere <= 0) return ['ok' => false, 'msg' => 'Bitte die andere (richtige) Charge wählen.'];
            $an = one("SELECT id, charge_nr FROM charge WHERE id=?", [$andere]);
            $r = erp_inv_ausbuchen($cid, 0, 'Doppelbuchung zu Charge ' . ($an['charge_nr'] ?: '#' . $andere) . ' (#' . $andere . ') – ' . inv_grund($inv, 'doppelt'));
            if (!$r['ok']) return $r;
            inv_bewegung($inv, $cid, $r['delta'], $r['einheit'], $name, 'doppelt', 'doppelt zu Charge #' . $andere);
            $erg = 'ausgebucht (' . $mz($r['delta']) . ' ' . $r['einheit'] . '), doppelt zu Charge ' . ($an['charge_nr'] ?: '#' . $andere);
            break;
        case 'dublette':
            $ziel = (int)($p['ziel_item'] ?? 0);
            if (!$cid || $ziel <= 0) return ['ok' => false, 'msg' => 'Bitte den richtigen Artikel wählen.'];
            $r = erp_inv_artikel_umbuchen($cid, $ziel);
            if (!$r['ok']) return $r;
            inv_bewegung($inv, $cid, 0, $se, $name, 'dublette', 'umgebucht auf ' . $r['ziel']);
            $erg = 'umgebucht auf „' . $r['ziel'] . '“';
            if (abs($delta) > 0.0005 && in_array((string)$d['art'], ['menge'], true)) {
                $k = erp_inv_korrektur($cid, $gid, $delta);
                if ($k['ok']) { inv_bewegung($inv, $cid, $k['delta'], $k['einheit'], $r['ziel'], 'dublette', 'Ist übernommen'); $erg .= ', ' . ($k['delta'] >= 0 ? '+' : '') . $mz($k['delta']) . ' ' . $k['einheit']; }
            }
            break;
        case 'nicht_gebucht':
            if ((string)$d['obj'] === 'k') {
                $z = one("SELECT * FROM lg_inventur_zaehlung WHERE id=?", [(int)$d['zaehlung_id']]);
                if (!$z || $z['kf_charge_id']) return ['ok' => false, 'msg' => 'Klärfall schon gebucht.'];
                $r = erp_inv_klaerfall_buchen($z, inv_grund($inv, 'nicht_gebucht') . ($notiz !== '' ? ' · ' . $notiz : ''), (string)$inv['lager'] === '2' ? (int)$inv['kunde_id'] : 0);
                if (!$r['ok']) return $r;
                q("UPDATE lg_inventur_zaehlung SET kf_charge_id=?, charge_id=?, gebinde_id=? WHERE id=?", [$r['charge_id'], $r['charge_id'], (int)$r['gebinde_id'], (int)$z['id']]);
                q("UPDATE lg_inventur_diff SET charge_id=?, gebinde_id=? WHERE id=?", [$r['charge_id'], (int)$r['gebinde_id'], $diff_id]);
                $b = inv_bereich((int)$z['bereich_id']);
                if ($b && preg_match('/^p:(\d+)$/', (string)$b['schluessel'], $m)) inv_platz_setzen($r['charge_id'], (int)$r['gebinde_id'], (int)$m[1], 'Inventur');
                inv_bewegung($inv, $r['charge_id'], (float)$z['ist'], (string)$z['einheit'], $name, 'nicht_gebucht', 'Charge angelegt (Inv ' . $z['kf_inventar_nr'] . ')');
                $cid = (int)$r['charge_id'];
                $erg = 'Charge #' . $r['charge_id'] . ' angelegt, ' . $mz($z['ist']) . ' ' . $z['einheit'] . ' (Wareneingang ohne Bestellung, Grund Inventur – Bestellung kann an der Bestellung zugeordnet werden)';
            } else {
                if (!$cid) return ['ok' => false, 'msg' => 'Keine Charge.'];
                $r = erp_inv_korrektur($cid, $gid, $delta);
                if (!$r['ok']) return $r;
                inv_bewegung($inv, $cid, $r['delta'], $r['einheit'], $name, 'nicht_gebucht', $notiz);
                $erg = '+' . $mz($r['delta']) . ' ' . $r['einheit'] . ' gebucht';
            }
            break;
        case 'platz':
            $ziel = (string)$d['ist_ort'];
            if (preg_match('/^p:(\d+)$/', $ziel, $m)) inv_platz_setzen($cid, $gid, (int)$m[1], inv_grund($inv, 'platz'));
            elseif (preg_match('/^k:(\d+)$/', $ziel, $m) && function_exists('kiste_charge_zuordnen')) {
                q("DELETE FROM lg_platz_inhalt WHERE charge_id=? AND gebinde_id=?", [$cid, $gid]);   // nur die Zuordnung (keine Ware) – liegt jetzt in der Kiste
                if (function_exists('kiste_charge_entfernen')) kiste_charge_entfernen($cid);
                $f = kiste_charge_zuordnen((int)$m[1], $cid);
                if ($f !== '') return ['ok' => false, 'msg' => $f];
            } else return ['ok' => false, 'msg' => 'Kein Zielplatz.'];
            inv_bewegung($inv, $cid, 0, '', $name, 'platz', inv_ort_name((string)$d['soll_ort']) . ' → ' . inv_ort_name($ziel));
            $erg = 'umgelagert: ' . inv_ort_name((string)$d['soll_ort']) . ' → ' . inv_ort_name($ziel);
            break;
    }
    q("UPDATE lg_inventur_diff SET status=?, ursache=?, notiz=?, ergebnis=?, entschieden_von=?, entschieden_am=? WHERE id=?",
      [$status, $ursache, $notiz ?: ($d['notiz'] ?: null), mb_substr($erg, 0, 255), lg_uid() ?: null, jetzt_utc(), $diff_id]);
    // Korrektur an Kundenware (Lager 2): zusätzlich Hinweis an Nico – Kundeneigentum.
    if ($status === 'gebucht' && $cid && (!empty($o['fremd_kunde_id']) || (string)$inv['lager'] === '2'))
        erp_inv_benachrichtigen('Inventur Lager 2: Korrektur an Kundenware', $name . ' (Charge ' . (string)($o['charge_nr'] ?? '#' . $cid) . '): ' . $erg . '. ' . inv_grund($inv, $ursache) . '.', '/lager/?p=inventur_auswerten&id=' . (int)$inv['id']);
    return ['ok' => true, 'msg' => $name . ': ' . ($erg ?: 'entschieden') . '.'];
}
// Sammel-Knopf „Kleine Differenzen übernehmen": nur Mengenabweichungen unter klein_eur UND unter klein_prozent, offen.
function inv_kleine_differenzen(array $inv): array {
    return array_values(array_filter(all("SELECT * FROM lg_inventur_diff WHERE inventur_id=? AND art='menge' AND status='offen'", [(int)$inv['id']]),
        fn($d) => $d['wert_eur'] !== null && abs((float)$d['wert_eur']) < (float)$inv['klein_eur'] && $d['diff_prozent'] !== null && abs((float)$d['diff_prozent']) < (float)$inv['klein_prozent']));
}
function inv_kleine_uebernehmen(array $inv, array $ids): array {
    $ok = 0; $erlaubt = array_flip(array_map(fn($d) => (int)$d['id'], inv_kleine_differenzen($inv)));
    foreach ($ids as $id) if (isset($erlaubt[(int)$id]) && inv_entscheiden($inv, (int)$id, 'schwund', ['notiz' => 'kleine Differenz (Sammel)'])['ok']) $ok++;
    return ['ok' => $ok > 0, 'msg' => $ok . ' kleine Differenz(en) übernommen.'];
}
