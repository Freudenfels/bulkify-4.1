<?php
require_once __DIR__ . '/../../core/gewicht.php';   // gewicht_lesen(), tara_aus_id() (13.11.2026)
// Inventur – Zählen (11.11.2026). Blind: Nichts hier gibt dem Zähler eine Soll-Menge zurück. Soll und gebuchter Ort werden
// beim Speichern eines Scans eingefroren (lg_inventur_zaehlung.soll/soll_ort) – Bewegungen danach ändern die Zählung nicht.

// Eingabeart eines Objekts: wiegen (kg/g), kapsel (Bulk-Kapseln in Stk mit bekanntem Kapselgewicht), stk (alles andere).
function inv_modus(array $o): string {
    $e = erp_einheit_norm((string)($o['item_einheit'] ?? $o['einheit'] ?? ''));
    if (in_array($e, ['kg', 'g'], true)) return 'wiegen';
    if ($e === 'Stk' && erp_inv_kapsel_mg((int)$o['item_id'], (int)$o['charge_id']) > 0) return 'kapsel';
    return 'stk';
}
// Was der Zähler zu einem Objekt sieht: Artikel, Charge, MHD, Einheit (groß), Gebinde x/y, Inhalt „voll" – KEIN Soll.
function inv_objekt_info(array $inv, array $o): array {
    $modus = inv_modus($o);
    $voll = null;
    if ($modus === 'stk') {
        if ($o['obj'] === 'g' && (float)$o['g_menge'] > 0) $voll = (float)$o['g_menge'];
        elseif ($o['obj'] === 'c' && function_exists('lg_pakete') && lg_pakete((int)$o['charge_id']) > 1 && (float)$o['charge_menge'] > 0)
            $voll = floor((float)$o['charge_menge'] / lg_pakete((int)$o['charge_id']));
    }
    $schon = one("SELECT z.ist, z.einheit, z.art, b.name AS bereich FROM lg_inventur_zaehlung z JOIN lg_inventur_bereich b ON b.id=z.bereich_id
                  WHERE z.inventur_id=? AND z.obj=? AND z.gebinde_id=? AND z.charge_id=? AND z.ersetzt=0 ORDER BY z.id DESC LIMIT 1",
                 [(int)$inv['id'], (string)$o['obj'], (int)$o['gebinde_id'], (int)$o['charge_id']]);
    return [
        'obj' => (string)$o['obj'], 'id' => (int)($o['obj'] === 'g' ? $o['gebinde_id'] : $o['charge_id']),
        'artikel' => (string)$o['item_name'], 'charge' => (string)($o['charge_nr'] ?? ''), 'mhd' => $o['mhd'] ? date('d.m.Y', strtotime((string)$o['mhd'])) : '',
        'einheit' => erp_einheit_norm((string)$o['item_einheit']) ?: (string)$o['item_einheit'],
        'gebinde' => $o['obj'] === 'g' ? 'Gebinde ' . (int)$o['g_nr'] . ' von ' . (int)$o['g_von'] . ((string)($o['inventar_nr'] ?? '') !== '' ? ' · Inv ' . $o['inventar_nr'] : '') : 'ganze Charge',
        'modus' => $modus, 'voll' => $voll, 'kapsel_mg' => $modus === 'kapsel' ? erp_inv_kapsel_mg((int)$o['item_id'], (int)$o['charge_id']) : 0,
        'lager2' => !empty($o['fremd_kunde_id']), 'quarantaene' => (string)$o['charge_status'] === 'quarantaene',
        'schon' => $schon ? 'schon gezählt: ' . inv_mz($schon['ist']) . ' ' . $schon['einheit'] . ' auf ' . $schon['bereich'] : '',
    ];
}
// Gescannten Code einordnen. Rückgabe ['typ' => platz|kiste|objekt|gebinde_wahl|klaerfall|unbekannt, …].
function inv_scan(array $inv, string $code): array {
    $code = trim($code);
    if ($code === '') return ['typ' => 'unbekannt', 'msg' => 'Leerer Scan.'];
    if ($p = inv_platz_per_code($code)) return ['typ' => 'platz', 'key' => 'p:' . (int)$p['id'], 'name' => inv_platz_name($p)];
    if (function_exists('kiste_per_barcode') && ($k = kiste_per_barcode($code))) return ['typ' => 'kiste', 'key' => 'k:' . (int)$k['id'], 'name' => 'Kiste ' . $k['name']];
    // Klärfall-Etikett dieser Inventur (noch nicht gebucht) erneut gescannt
    $inr = erp_inventar_norm($code);
    if ($inr !== '' && ($z = one("SELECT * FROM lg_inventur_zaehlung WHERE kf_inventar_nr=? AND inventur_id=? AND ersetzt=0 ORDER BY id DESC LIMIT 1", [$inr, (int)$inv['id']])) && !$z['kf_charge_id'])
        return ['typ' => 'klaerfall', 'msg' => 'Gebinde ohne Etikett, schon erfasst (Inv ' . $inr . ') – wird bei der Auswertung geklärt.'];
    $r = erp_inv_scan($code);
    if (!$r && function_exists('led_leiste_normalisieren') && function_exists('leiste_per_code')) {   // Blinker an einer Kiste
        $lc = led_leiste_normalisieren($code); $l = $lc ? leiste_per_code($lc) : null;
        if ($l && !empty($l['kiste_id'])) { $k = one("SELECT * FROM lg_kiste WHERE id=?", [(int)$l['kiste_id']]); if ($k) return ['typ' => 'kiste', 'key' => 'k:' . (int)$k['id'], 'name' => 'Kiste ' . $k['name']]; }
    }
    if (!$r) return ['typ' => 'unbekannt', 'msg' => 'Code nicht gefunden. Ware ohne gültiges Etikett? Dann „Gefunden ohne Etikett“.'];
    if ($r['obj'] === 'c') {
        $geb = array_values(array_filter(erp_inv_gebinde_der_charge($r['id']), fn($g) => (string)$g['status'] !== 'leer'));
        if ($geb) {
            $o = erp_inv_objekt('c', $r['id']);
            $gez = [];
            foreach (all("SELECT gebinde_id FROM lg_inventur_zaehlung WHERE inventur_id=? AND obj='g' AND charge_id=? AND ersetzt=0", [(int)$inv['id'], $r['id']]) as $x) $gez[(int)$x['gebinde_id']] = 1;
            return ['typ' => 'gebinde_wahl', 'artikel' => (string)$o['item_name'], 'charge' => (string)$o['charge_nr'],
                    'gebinde' => array_map(fn($g) => ['id' => (int)$g['id'], 'text' => 'Gebinde ' . (int)$g['nummer'] . ' von ' . (int)$g['anzahl_gesamt'] . ((string)$g['inventar_nr'] !== '' ? ' · Inv ' . $g['inventar_nr'] : ''), 'gezaehlt' => isset($gez[(int)$g['id']])], $geb)];
        }
    }
    $o = erp_inv_objekt($r['obj'], $r['id']);
    if (!$o) return ['typ' => 'unbekannt', 'msg' => 'Code nicht gefunden.'];
    return ['typ' => 'objekt'] + inv_objekt_info($inv, $o);
}
// Bereich (Platz/Kiste) zum Zählen öffnen. Ganzes Lager: Bereich entsteht hier. Sonst nur Bereiche der Inventur.
function inv_bereich_starten(array $inv, string $key): array {
    $inv_id = (int)$inv['id'];
    $b = one("SELECT * FROM lg_inventur_bereich WHERE inventur_id=? AND schluessel=?", [$inv_id, $key]);
    if (!$b) {
        if (!(int)$inv['ganz']) return ['ok' => false, 'msg' => inv_ort_name($key) . ' gehört nicht zu dieser Inventur.'];
        if (preg_match('/^p:(\d+)$/', $key, $m)) { $p = inv_platz((int)$m[1]); if ($p && ((string)$p['lager'] === 'lager2') !== ((string)$inv['lager'] === '2')) return ['ok' => false, 'msg' => inv_platz_name($p) . ' ist ein Platz in ' . ((string)$p['lager'] === 'lager2' ? 'Lager 2' : 'Lager 1') . '.']; }
        $b = inv_bereich_anlegen($inv_id, $key);
        if (isset($b['fehler'])) return ['ok' => false, 'msg' => $b['fehler']];
    }
    if ((string)$b['status'] === 'fertig') {
        // Nachzählen auf einem fertigen Platz (zweite Person): Platz öffnen nur für die Nachzähl-Aufgaben.
        if ((int) scalar("SELECT COUNT(*) FROM lg_inventur_diff WHERE bereich_id=? AND status='nachzaehlen'", [(int)$b['id']]) > 0)
            return ['ok' => true, 'bereich_id' => (int)$b['id'], 'name' => (string)$b['name'], 'nachzaehlen' => true, 'msg' => (string)$b['name'] . ' · nur Nachzählen'];
        return ['ok' => false, 'msg' => (string)$b['name'] . ' ist schon fertig gezählt. Wieder öffnen kann der Admin.', 'fertig' => true];
    }
    if ((string)$b['status'] === 'offen') q("UPDATE lg_inventur_bereich SET status='laeuft', gestartet_am=?, gestartet_von=? WHERE id=?", [jetzt_utc(), lg_uid() ?: null, (int)$b['id']]);
    return ['ok' => true, 'bereich_id' => (int)$b['id'], 'name' => (string)$b['name'], 'msg' => (string)$b['name'] . ' · Zählung läuft'];
}
// Ist aus der Eingabe rechnen – immer in der Einheit des Artikels.
function inv_ist_rechnen(array $o, array $e): array {
    $art = (string)($e['art'] ?? 'stk');
    $ie = erp_einheit_norm((string)$o['item_einheit']) ?: (string)$o['item_einheit'];
    $n = fn($k) => erp_menge_parse((string)($e[$k] ?? ''));   // Stück
    $g = fn($k) => gewicht_lesen((string)($e[$k] ?? ''));     // 13.11.2026 (Fable-3 T1.2): Gewichte (Brutto, Tara, mg je Kapsel)
    $r = ['art' => $art, 'ist' => 0.0, 'einheit' => $ie, 'brutto' => null, 'tara' => null, 'mg' => null];
    if ($art === 'leer') return $r;
    if ($art === 'voll') { $info = inv_objekt_info(['id' => 0], $o); $r['ist'] = (float)($info['voll'] ?? 0); return $r; }
    if ($art === 'wiegen' || $art === 'kapsel') {
        $r['brutto'] = $g('brutto');
        // 13.11.2026 (Fable-3 T1.1): Tara aus lg_tara über die ID; die Zahl aus dem Feld nur, wenn sie von Hand geändert wurde.
        $ta = empty($e['tara_hand']) ? tara_aus_id((int)($e['tara_id'] ?? 0)) : null;
        $r['tara'] = $ta ? (float)$ta['kg'] : max(0, $g('tara'));
        $netto = max(0.0, $r['brutto'] - $r['tara']);   // kg
        if ($art === 'kapsel') {
            $r['mg'] = $g('kapsel_mg') ?: erp_inv_kapsel_mg((int)$o['item_id'], (int)$o['charge_id']);
            $r['ist'] = $ie === 'Stk' ? ($r['mg'] > 0 ? round($netto * 1000000 / $r['mg']) : 0) : ($ie === 'g' ? $netto * 1000 : $netto);
        } else $r['ist'] = $ie === 'g' ? round($netto * 1000, 3) : ($ie === 'Stk' ? $netto : round($netto, 3));
        return $r;
    }
    $r['art'] = 'stk'; $r['ist'] = max(0, $n('menge'));
    return $r;
}
// Einen Scan speichern. Gleiches Objekt in derselben Inventur nochmal gezählt -> der alte Scan ist ersetzt.
// Steht für das Objekt eine Nachzählung an (zweite Person), wird es Durchgang 2.
function inv_zaehlen(array $inv, int $bereich_id, string $obj, int $id, array $e): array {
    $b = inv_bereich($bereich_id);
    if (!$b || (int)$b['inventur_id'] !== (int)$inv['id']) return ['ok' => false, 'msg' => 'Bitte zuerst den Platz scannen.'];
    $o = erp_inv_objekt($obj, $id);
    if (!$o) return ['ok' => false, 'msg' => 'Gebinde nicht gefunden.'];
    $nz = one("SELECT * FROM lg_inventur_diff WHERE inventur_id=? AND status='nachzaehlen' AND obj=? AND gebinde_id=? AND charge_id=? ORDER BY id DESC LIMIT 1",
              [(int)$inv['id'], $obj, (int)$o['gebinde_id'], (int)$o['charge_id']]);
    $durchgang = 1;
    if ($nz) {
        if ((int)$nz['erster_zaehler'] === lg_uid() && !lg_ist_admin()) return ['ok' => false, 'msg' => 'Nachzählen muss eine zweite Person.'];
        $durchgang = 2;
    } elseif ((string)$b['status'] === 'fertig') return ['ok' => false, 'msg' => (string)$b['name'] . ' ist schon fertig gezählt.'];
    $r = inv_ist_rechnen($o, $e);
    if ($r['art'] !== 'leer' && $r['ist'] <= 0 && !in_array($r['art'], ['wiegen', 'kapsel'], true)) return ['ok' => false, 'msg' => 'Bitte eine Menge eingeben (oder „Gebinde leer“).'];
    $ort = inv_ort_von((int)$o['charge_id'], (int)$o['gebinde_id']);
    q("UPDATE lg_inventur_zaehlung SET ersetzt=1 WHERE inventur_id=? AND obj=? AND gebinde_id=? AND charge_id=? AND durchgang=? AND ersetzt=0",
      [(int)$inv['id'], $obj, (int)$o['gebinde_id'], (int)$o['charge_id'], $durchgang]);
    q("INSERT INTO lg_inventur_zaehlung (inventur_id, bereich_id, obj, gebinde_id, charge_id, item_id, art, ist, einheit, brutto_kg, tara_kg, kapsel_mg, soll, soll_einheit, soll_ort, durchgang, zaehler_id, zeit)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [(int)$inv['id'], $bereich_id, $obj, (int)$o['gebinde_id'], (int)$o['charge_id'], (int)$o['item_id'], $r['art'], $r['ist'], $r['einheit'],
       $r['brutto'], $r['tara'], $r['mg'], (float)$o['soll'], (string)$o['einheit'], $ort['key'] ?: null, $durchgang, lg_uid() ?: null, jetzt_utc()]);
    $zid = insert_id();
    if ($durchgang === 2 && function_exists('inv_nachzaehlung_uebernehmen')) inv_nachzaehlung_uebernehmen($inv, (int)$nz['id'], $zid);
    $txt = $r['art'] === 'leer' ? 'Gebinde leer' : inv_mz($r['ist']) . ' ' . $r['einheit'];
    return ['ok' => true, 'zaehlung_id' => $zid, 'msg' => (string)$o['item_name'] . ': ' . $txt . ' erfasst' . ($durchgang === 2 ? ' (Nachzählung)' : '') . '.'];
}
// Ware ohne Barcode/Etikett: Klärfall mit Artikel, abgetippter Charge, MHD, Menge, Pflichtfoto. Bekommt sofort eine Inventarnummer
// und ein Etikett (Druckbrücke). Gebucht wird erst nach Nicos Entscheidung (Auswertung).
function inv_klaerfall(array $inv, int $bereich_id, array $d, array $foto): array {
    $b = inv_bereich($bereich_id);
    if (!$b || (int)$b['inventur_id'] !== (int)$inv['id'] || (string)$b['status'] === 'fertig') return ['ok' => false, 'msg' => 'Bitte zuerst den Platz scannen.'];
    $it = erp_inv_item((int)($d['item_id'] ?? 0));
    if (!$it) return ['ok' => false, 'msg' => 'Bitte den Artikel wählen.'];
    if (empty($foto['tmp_name']) || !is_uploaded_file($foto['tmp_name'])) return ['ok' => false, 'msg' => 'Bitte ein Foto vom Gebinde machen (Pflicht).'];
    $ext = strtolower(pathinfo((string)$foto['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) $ext = 'jpg';
    $o = ['item_id' => (int)$it['id'], 'charge_id' => 0, 'item_einheit' => (string)$it['einheit'], 'obj' => 'k', 'gebinde_id' => 0, 'charge_menge' => 0, 'g_menge' => 0];
    $r = inv_ist_rechnen($o, $d);
    if ($r['ist'] <= 0) return ['ok' => false, 'msg' => 'Bitte die Menge erfassen.'];
    $datei = 'inv' . (int)$inv['id'] . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!@move_uploaded_file($foto['tmp_name'], BX_UPLOADS . '/' . $datei)) return ['ok' => false, 'msg' => 'Foto konnte nicht gespeichert werden.'];
    $nr = erp_inv_inventar_reservieren();
    $mhd = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['mhd'] ?? '')) ? (string)$d['mhd'] : null;
    q("INSERT INTO lg_inventur_zaehlung (inventur_id, bereich_id, obj, item_id, art, ist, einheit, brutto_kg, tara_kg, kapsel_mg, soll, soll_einheit, durchgang, zaehler_id, zeit, kf_charge_nr, kf_mhd, kf_foto, kf_inventar_nr, kf_notiz)
       VALUES (?,?,'k',?,?,?,?,?,?,?,0,?,1,?,?,?,?,?,?,?)",
      [(int)$inv['id'], $bereich_id, (int)$it['id'], $r['art'], $r['ist'], $r['einheit'], $r['brutto'], $r['tara'], $r['mg'], $r['einheit'], lg_uid() ?: null, jetzt_utc(),
       mb_substr(trim((string)($d['charge_nr'] ?? '')), 0, 80) ?: null, $mhd, $datei, $nr, mb_substr(trim((string)($d['notiz'] ?? '')), 0, 255) ?: null]);
    $zid = insert_id();
    q("INSERT INTO lg_druckjob (ids, format, typ, status, benutzer_id, angelegt) VALUES (?,'klein','inventur','offen',?,?)", [(string)$zid, lg_uid() ?: null, jetzt_utc()]);
    $wach = lg_meta_lesen('bruecke_zuletzt', '');
    $laeuft = $wach !== '' && (time() - strtotime($wach . ' UTC')) < 15;
    return ['ok' => true, 'zaehlung_id' => $zid, 'inventar_nr' => $nr,
            'msg' => 'Klärfall erfasst: ' . (string)$it['name'] . ', ' . inv_mz($r['ist']) . ' ' . $r['einheit'] . '. Etikett Inv ' . $nr . ($laeuft ? ' wird gedruckt – bitte aufkleben.' : ' liegt in der Druck-Warteschlange (Brücke läuft gerade nicht).'),
            'etikett_url' => '?p=inventur_api&a=kf_etikett&id=' . (int)$inv['id'] . '&z=' . $zid];
}
// Scans eines Bereichs für den Zähler (seine Ist-Werte, kein Soll).
function inv_bereich_scans(int $bereich_id): array {
    $out = [];
    foreach (all("SELECT * FROM lg_inventur_zaehlung WHERE bereich_id=? AND ersetzt=0 ORDER BY id DESC", [$bereich_id]) as $z) {
        $name = $z['obj'] === 'k' ? (string)(erp_inv_item((int)$z['item_id'])['name'] ?? '') : (string)((erp_inv_objekt((string)$z['obj'], (int)($z['obj'] === 'g' ? $z['gebinde_id'] : $z['charge_id'])) ?? [])['item_name'] ?? '');
        $out[] = ['id' => (int)$z['id'], 'artikel' => $name, 'ist' => $z['art'] === 'leer' ? 'leer' : inv_mz($z['ist']) . ' ' . $z['einheit'],
                  'art' => (string)$z['art'], 'klaerfall' => $z['obj'] === 'k' ? 'ohne Etikett · Inv ' . $z['kf_inventar_nr'] : '', 'nachzaehlung' => (int)$z['durchgang'] === 2];
    }
    return $out;
}
// Platz fertig: ab jetzt sieht der Admin die Differenzen dieses Platzes (Abgleich), der Zähler weiterhin nicht.
function inv_bereich_fertig(array $inv, int $bereich_id): array {
    $b = inv_bereich($bereich_id);
    if (!$b || (int)$b['inventur_id'] !== (int)$inv['id']) return ['ok' => false, 'msg' => 'Platz nicht gefunden.'];
    q("UPDATE lg_inventur_bereich SET status='fertig', fertig_am=?, fertig_von=? WHERE id=?", [jetzt_utc(), lg_uid() ?: null, $bereich_id]);
    if (function_exists('inv_abgleich_bereich')) inv_abgleich_bereich($inv, $bereich_id);
    return ['ok' => true, 'msg' => (string)$b['name'] . ' ist gezählt. Nächsten Platz scannen.'];
}
// Nachzählen-Aufgaben für diesen Nutzer (blind: Artikel, Charge, wo zuletzt gezählt – keine Mengen).
function inv_nachzaehl_aufgaben(array $inv): array {
    $out = [];
    foreach (all("SELECT d.*, b.name AS bereich FROM lg_inventur_diff d LEFT JOIN lg_inventur_bereich b ON b.id=d.bereich_id
                  WHERE d.inventur_id=? AND d.status='nachzaehlen' ORDER BY b.name, d.id", [(int)$inv['id']]) as $d) {
        if ((int)$d['erster_zaehler'] === lg_uid() && !lg_ist_admin()) continue;
        $o = erp_inv_objekt((string)$d['obj'], (int)($d['obj'] === 'g' ? $d['gebinde_id'] : $d['charge_id']));
        $out[] = ['artikel' => (string)($o['item_name'] ?? ''), 'charge' => (string)($o['charge_nr'] ?? ''), 'bereich' => (string)($d['bereich'] ?? ''),
                  'gebinde' => $o && $o['obj'] === 'g' ? 'Gebinde ' . (int)$o['g_nr'] . ' von ' . (int)$o['g_von'] : ''];
    }
    return $out;
}
