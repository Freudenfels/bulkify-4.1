<?php
// Wareneingang gegen eine Bestellung (Einkauf gerade ziehen, Teil 1-3, 10.11.2026). EINE Stelle für alle Einbuchwege:
// Dashboard (?p=wareneingang, Bestellseite) und Lager-App (?p=we, ?p=eingang). Ohne Abhängigkeiten zu core/schema.php
// (Sub-App-Regel): nur q/one/all/scalar/insert_id, die beide Programme gleich haben. Alle Funktionen mit Präfix webe_.
//
// Regeln (Soll-Handbuch 4.5):
// - Die Charge trägt bestellung_position_id. Gelieferte Menge einer Position = Summe der verknüpften Chargen (in der
//   Einheit der Bestellposition). bestellung_position.geliefert_menge ist nur der Zwischenspeicher dafür, damit alle
//   bestehenden Rechnungen („unterwegs" = Menge minus geliefert) unverändert bleiben.
// - Alles da (mind. 98 %) oder Rest storniert: Bestellung schließt sich selbst (geliefert, angekommen_am) - egal,
//   welchen Stand der Lieferant im Portal gesetzt hat. Teillieferung: bleibt offen, der Rest zählt als unterwegs.
// - Mehrlieferung: nur Hinweis, der Überschuss ist freier Bestand.
// - Wareneingang ohne Bestellung nur mit Grund. „Alte Bestellung" legt eine nachträgliche Bestellung an (direkt bestellt,
//   geliefert, Menge = Eingang) und verknüpft die Charge. Jede Buchung ohne Bestellung meldet sich zum Prüfen.

if (!function_exists('webe_spalten')) {

// Spalten best-effort anlegen (die Lager-App kann vor dem Dashboard laufen). Einmal je Anfrage.
function webe_spalten(): void {
    static $fertig = false;
    if ($fertig) return;
    $fertig = true;
    $soll = [
        ['bestellung_position', 'rest_storniert_am', 'DATE NULL'],
        ['bestellung', 'nachtraeglich_grund', 'VARCHAR(40) NULL'],
        ['bestellung', 'nachtraeglich_geprueft_am', 'DATETIME NULL'],
        ['charge', 'we_grund', 'VARCHAR(40) NULL'],
        ['charge', 'we_grund_notiz', 'VARCHAR(255) NULL'],
    ];
    foreach ($soll as [$t, $c, $def]) {
        try {
            $da = (int) scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?", [$t, $c]);
            if (!$da) q("ALTER TABLE `$t` ADD COLUMN `$c` $def");
        } catch (\Throwable $e) { /* best-effort */ }
    }
}

// Gründe für einen Wareneingang ohne Bestellung (Übergangsphase, Nico 10.11.2026).
function webe_gruende(): array {
    return [
        'alt_bestellung' => 'Alte Bestellung, nicht im System',
        'muster'         => 'Muster',
        'kundenware'     => 'Kundenware (Lager 2)',
        'sonstiges'      => 'Sonstiges',
    ];
}

function webe_einheit_faktor(string $von, string $nach): float {
    $v = mb_strtolower(trim($von)); $n = mb_strtolower(trim($nach));
    $v = ['kilogramm' => 'kg', 'gramm' => 'g', 'liter' => 'l', 'stück' => 'stk', 'stueck' => 'stk'][$v] ?? $v;
    $n = ['kilogramm' => 'kg', 'gramm' => 'g', 'liter' => 'l', 'stück' => 'stk', 'stueck' => 'stk'][$n] ?? $n;
    if ($v === $n || $v === '' || $n === '') return 1.0;
    $f = ['g>kg' => 0.001, 'kg>g' => 1000, 'mg>g' => 0.001, 'g>mg' => 1000, 'mg>kg' => 0.000001, 'kg>mg' => 1000000, 'ml>l' => 0.001, 'l>ml' => 1000];
    return $f[$v . '>' . $n] ?? 1.0;
}

function webe_bedarf_bump(): void {
    if (function_exists('bedarf_bump')) bedarf_bump();
    elseif (function_exists('erp_bedarf_bump')) erp_bedarf_bump();
}

function webe_melden(string $betreff, string $text, string $link, bool $wichtig = false): void {
    try { q("INSERT INTO benachrichtigung (betreff,text,link,art,wichtig,erstellt) VALUES (?,?,?,?,?,?)",
            [mb_substr($betreff, 0, 255), mb_substr($text, 0, 4000), mb_substr($link, 0, 255), 'bestellung', $wichtig ? 1 : 0, gmdate('Y-m-d H:i:s')]); }
    catch (\Throwable $e) {
        try { q("INSERT INTO benachrichtigung (betreff,text,link,erstellt) VALUES (?,?,?,?)", [mb_substr($betreff, 0, 255), mb_substr($text, 0, 4000), mb_substr($link, 0, 255), gmdate('Y-m-d H:i:s')]); }
        catch (\Throwable $e2) { /* best-effort */ }
    }
}

function webe_kapsel_label(int $item_id): string {
    if ($item_id <= 0) return '';
    try {
        $r = one("SELECT COALESCE(kgi.name, kgr.name) AS kg, r.einheit_fuellmenge, r.darreichungsform, i.material
                  FROM item i LEFT JOIN kapselgroesse kgi ON kgi.id=i.kapselgroesse_id
                  LEFT JOIN rezeptur r ON r.id=i.rezeptur_id LEFT JOIN kapselgroesse kgr ON kgr.id=r.kapselgroesse_id
                  WHERE i.id=?", [$item_id]);
    } catch (\Throwable $e) { return ''; }
    if (!$r) return '';
    $t = [];
    if ((string)($r['kg'] ?? '') !== '') $t[] = 'Größe ' . preg_replace('/^(Größe|Gr\.?)\s*/iu', '', (string)$r['kg']);
    if ((float)($r['einheit_fuellmenge'] ?? 0) > 0 && in_array((string)($r['darreichungsform'] ?? ''), ['kapsel', 'softgel', 'tablette'], true))
        $t[] = rtrim(rtrim(number_format((float)$r['einheit_fuellmenge'], 1, ',', '.'), '0'), ',') . ' mg';
    return implode(' · ', $t);
}

// Gelieferte Menge einer Position aus den verknüpften Chargen neu rechnen. Gibt es keine verknüpfte Charge, bleibt ein
// früher eingetragener Wert stehen (Altdaten, Handbuch 1.8). Rückgabe: gelieferte Menge in der Einheit der Position.
function webe_position_neu_rechnen(int $bp_id): float {
    $bp = one("SELECT id, menge, einheit, COALESCE(geliefert_menge,0) AS gel FROM bestellung_position WHERE id=?", [$bp_id]);
    if (!$bp) return 0.0;
    $ch = all("SELECT menge, einheit FROM charge WHERE bestellung_position_id=?", [$bp_id]);
    if (!$ch) return (float)$bp['gel'];
    $sum = 0.0;
    foreach ($ch as $c) $sum += (float)$c['menge'] * webe_einheit_faktor((string)$c['einheit'], (string)$bp['einheit']);
    $sum = round($sum, 3);
    if (abs($sum - (float)$bp['gel']) > 0.0005) q("UPDATE bestellung_position SET geliefert_menge=? WHERE id=?", [$sum, $bp_id]);
    return $sum;
}

// Stand einer Bestellung prüfen und ggf. schließen. Rückgabe ['komplett'=>bool, 'offen'=>[[id, rest, einheit]], 'mehr'=>[...]].
// $oeffnen (Block B, 11.11.2026): nach Mengenkorrektur oder „Verknüpfung lösen" darf eine schon gelieferte Bestellung
// wieder aufgehen (status 'geliefert' -> 'bestellt', angekommen_am leer), wenn wieder etwas offen ist. Rückgabe zusätzlich 'wieder_offen'.
function webe_bestellung_stand(int $bestellung_id, bool $melden = false, string $quelle = '', bool $oeffnen = false): array {
    webe_spalten();
    $b = one("SELECT id, nummer, status, angekommen_am, lieferant_id FROM bestellung WHERE id=?", [$bestellung_id]);
    if (!$b) return ['komplett' => false, 'offen' => [], 'mehr' => []];
    $offen = []; $mehr = []; $irgendwas = false; $pos = 0;
    foreach (all("SELECT id, menge, einheit, COALESCE(geliefert_menge,0) AS gel, rest_storniert_am, bezeichnung, item_id FROM bestellung_position WHERE bestellung_id=?", [$bestellung_id]) as $p) {
        if ((float)$p['menge'] <= 0) continue;
        $pos++;
        $gel = webe_position_neu_rechnen((int)$p['id']);
        if ($gel > 0) $irgendwas = true;
        if ($gel > (float)$p['menge'] * 1.02 + 1e-9) $mehr[] = ['id' => (int)$p['id'], 'mehr' => $gel - (float)$p['menge'], 'einheit' => (string)$p['einheit']];
        if (!empty($p['rest_storniert_am'])) continue;
        if ($gel + 1e-9 < (float)$p['menge'] * 0.98) $offen[] = ['id' => (int)$p['id'], 'rest' => (float)$p['menge'] - $gel, 'einheit' => (string)$p['einheit']];
    }
    $komplett = $pos > 0 && !$offen && ($irgendwas || (int) scalar("SELECT COUNT(*) FROM bestellung_position WHERE bestellung_id=? AND rest_storniert_am IS NOT NULL", [$bestellung_id]) > 0);
    $warSchon = (string)$b['status'] === 'geliefert' || !empty($b['angekommen_am']);
    if ($komplett) {
        q("UPDATE bestellung SET angekommen_am=COALESCE(angekommen_am, CURDATE()) WHERE id=?", [$bestellung_id]);
        q("UPDATE bestellung SET status='geliefert' WHERE id=? AND status IN ('offen','gesendet','bestellt')", [$bestellung_id]);
        if (!empty($b['lieferant_id'])) q("UPDATE bestellung SET station='eingegangen' WHERE id=?", [$bestellung_id]);
    } elseif ($irgendwas) {
        q("UPDATE bestellung SET teilgeliefert_am=COALESCE(teilgeliefert_am, CURDATE()) WHERE id=?", [$bestellung_id]);
    }
    $wiederOffen = false;
    if (!$komplett && $oeffnen && $warSchon && $offen) {
        q("UPDATE bestellung SET status='bestellt', angekommen_am=NULL WHERE id=? AND status='geliefert'", [$bestellung_id]);
        if (!empty($b['lieferant_id'])) { try { q("UPDATE bestellung SET station=NULL WHERE id=? AND station='eingegangen'", [$bestellung_id]); } catch (\Throwable $e) {} }
        $wiederOffen = true;
        if (function_exists('log_aktivitaet')) { try { log_aktivitaet('bestellung', $bestellung_id, 'team', 'Bestellung wieder offen: nach Korrektur bzw. gelöster Verknüpfung fehlt noch Ware.', 'status'); } catch (\Throwable $e) {} }
    }
    webe_bedarf_bump();
    if ($melden && $irgendwas && !$warSchon) {
        $firma = $b['lieferant_id'] ? (string) scalar("SELECT firma FROM lieferanten WHERE id=?", [(int)$b['lieferant_id']]) : '';
        webe_melden('Lieferung eingebucht: ' . $b['nummer'] . ($firma !== '' ? ' (' . $firma . ')' : '') . ($komplett ? '' : ' – Teillieferung'),
            $komplett ? 'Die Bestellung ' . $b['nummer'] . ' ist vollständig eingegangen' . ($quelle !== '' ? ' (' . $quelle . ')' : '') . '.'
                      : 'Teillieferung zu ' . $b['nummer'] . ' eingebucht' . ($quelle !== '' ? ' (' . $quelle . ')' : '') . ', ' . count($offen) . ' Position(en) noch offen (zählen weiter als unterwegs).',
            '?p=bestellung&id=' . $bestellung_id);
    }
    return ['komplett' => $komplett, 'offen' => $offen, 'mehr' => $mehr, 'wieder_offen' => $wiederOffen];
}

// Verknüpfung Charge <-> Bestellposition lösen (Block B Teil 3, 11.11.2026), z. B. falsch verknüpft (Mikrozirkulation BE-2890 vs.
// BE-2933). Danach ist die Charge frei (kein Bestellbezug), die Position rechnet neu (ohne Charge: geliefert 0) und die Bestellung
// geht ggf. wieder auf. Auftrag/Lieferant an der Charge bleiben (können echt sein). Rückgabe ['ok','msg'].
function webe_charge_loesen(int $charge_id, string $von = ''): array {
    $c = one("SELECT id, charge_nr, bestellung_position_id FROM charge WHERE id=?", [$charge_id]);
    if (!$c || empty($c['bestellung_position_id'])) return ['ok' => false, 'msg' => 'Diese Charge ist mit keiner Bestellung verknüpft.'];
    $bpId = (int)$c['bestellung_position_id'];
    $bp = one("SELECT bp.bestellung_id, b.nummer FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id WHERE bp.id=?", [$bpId]);
    q("UPDATE charge SET bestellung_position_id=NULL WHERE id=?", [$charge_id]);
    if ((int) scalar("SELECT COUNT(*) FROM charge WHERE bestellung_position_id=?", [$bpId]) === 0) q("UPDATE bestellung_position SET geliefert_menge=0 WHERE id=?", [$bpId]);
    else webe_position_neu_rechnen($bpId);
    $st = $bp ? webe_bestellung_stand((int)$bp['bestellung_id'], false, '', true) : ['wieder_offen' => false];
    $cnr = (string)($c['charge_nr'] ?: '#' . $charge_id);
    $txt = 'Verknüpfung gelöst: Charge ' . $cnr . ' gehört nicht mehr zu ' . ($bp['nummer'] ?? 'der Bestellung') . ($von !== '' ? ' (' . $von . ')' : '') . '.';
    if (function_exists('log_aktivitaet')) {
        try { if ($bp) log_aktivitaet('bestellung', (int)$bp['bestellung_id'], 'team', $txt, 'status'); log_aktivitaet('charge', $charge_id, 'team', $txt, 'notiz'); } catch (\Throwable $e) {}
    }
    if (function_exists('aenderung_protokollieren')) { try { aenderung_protokollieren('charge', $charge_id, ['bestellung_position_id' => $bpId], ['bestellung_position_id' => null], 'verknuepfung_loesen'); } catch (\Throwable $e) {} }
    try { q("INSERT INTO lg_charge_log (charge_id,feld,alt,neu,benutzer_id,benutzer_name,angelegt) VALUES (?,?,?,?,?,?,?)",
            [$charge_id, 'Bestellung', (string)($bp['nummer'] ?? ''), 'gelöst', null, mb_substr($von, 0, 120), gmdate('Y-m-d H:i:s')]); } catch (\Throwable $e) {}
    return ['ok' => true, 'msg' => $txt . (!empty($st['wieder_offen']) ? ' Die Bestellung ist wieder offen.' : '')];
}

// Charge an eine Bestellposition hängen (überschreibt eine frühere Verknüpfung) und die Bestellung neu bewerten.
// Lieferant und Auftrag der Position werden übernommen, wenn die Charge sie noch nicht hat. Rückgabe bestellung_id (0 = Fehler).
function webe_charge_verknuepfen(int $charge_id, int $bp_id, bool $melden = true, string $quelle = ''): int {
    $bp = one("SELECT bp.id, bp.bestellung_id, bp.auftrag_id, b.lieferant_id FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id WHERE bp.id=?", [$bp_id]);
    if (!$bp || $charge_id <= 0) return 0;
    $alt = (int) scalar("SELECT COALESCE(bestellung_position_id,0) FROM charge WHERE id=?", [$charge_id]);
    q("UPDATE charge SET bestellung_position_id=?, lieferant_id=COALESCE(lieferant_id, ?) WHERE id=?", [$bp_id, $bp['lieferant_id'] ?: null, $charge_id]);
    if (!empty($bp['auftrag_id'])) {
        try { q("UPDATE charge SET auftrag_id=? WHERE id=? AND auftrag_id IS NULL AND fremd_kunde_id IS NULL", [(int)$bp['auftrag_id'], $charge_id]); } catch (\Throwable $e) {}
    }
    if ($alt > 0 && $alt !== $bp_id) {
        $altB = (int) scalar("SELECT bestellung_id FROM bestellung_position WHERE id=?", [$alt]);
        webe_position_neu_rechnen($alt);
        if ($altB && $altB !== (int)$bp['bestellung_id']) webe_bestellung_stand($altB);
    }
    webe_bestellung_stand((int)$bp['bestellung_id'], $melden, $quelle);
    return (int)$bp['bestellung_id'];
}

// Rest einer Teillieferung stornieren: offene Positionen gelten als erledigt, der Rest zählt nicht mehr als unterwegs.
function webe_rest_stornieren(int $bestellung_id, string $wer = ''): bool {
    webe_spalten();
    $b = one("SELECT id, nummer, status FROM bestellung WHERE id=?", [$bestellung_id]);
    if (!$b || in_array((string)$b['status'], ['storniert'], true)) return false;
    q("UPDATE bestellung_position SET rest_storniert_am=CURDATE() WHERE bestellung_id=? AND rest_storniert_am IS NULL AND COALESCE(geliefert_menge,0) < menge", [$bestellung_id]);
    $gel = (float) scalar("SELECT COALESCE(SUM(geliefert_menge),0) FROM bestellung_position WHERE bestellung_id=?", [$bestellung_id]);
    if ($gel <= 0) q("UPDATE bestellung SET status='storniert' WHERE id=?", [$bestellung_id]);
    else {
        q("UPDATE bestellung SET angekommen_am=COALESCE(angekommen_am, CURDATE()) WHERE id=?", [$bestellung_id]);
        q("UPDATE bestellung SET status='geliefert' WHERE id=? AND status IN ('offen','gesendet','bestellt')", [$bestellung_id]);
    }
    $vermerk = "\n[" . gmdate('d.m.Y') . '] Rest storniert' . ($wer !== '' ? ' (' . $wer . ')' : '') . ' – zählt nicht mehr als unterwegs.';
    q("UPDATE bestellung SET notiz=CONCAT(COALESCE(notiz,''), ?) WHERE id=?", [$vermerk, $bestellung_id]);
    webe_bedarf_bump();
    return true;
}

// Altlasten (Teil 4): Ware ist gekommen, aber nicht einzeln eingebucht -> Bestellung schließen, offene Positionen zählen nicht
// mehr als unterwegs (rest_storniert_am + Vermerk). Es wird keine Charge angelegt.
function webe_als_geliefert_schliessen(int $bestellung_id, string $wer = ''): bool {
    webe_spalten();
    if (!one("SELECT id FROM bestellung WHERE id=? AND status<>'storniert'", [$bestellung_id])) return false;
    q("UPDATE bestellung_position SET rest_storniert_am=CURDATE() WHERE bestellung_id=? AND rest_storniert_am IS NULL AND COALESCE(geliefert_menge,0) < menge", [$bestellung_id]);
    q("UPDATE bestellung SET angekommen_am=COALESCE(angekommen_am, CURDATE()) WHERE id=?", [$bestellung_id]);
    q("UPDATE bestellung SET status='geliefert' WHERE id=? AND status IN ('offen','gesendet','bestellt')", [$bestellung_id]);
    q("UPDATE bestellung SET notiz=CONCAT(COALESCE(notiz,''), ?) WHERE id=?", ["\n[" . gmdate('d.m.Y') . '] Als geliefert geschlossen ohne eingebuchte Charge' . ($wer !== '' ? ' (' . $wer . ')' : '') . '.', $bestellung_id]);
    webe_bedarf_bump();
    return true;
}

function webe_naechste_nummer(string $prefix): string {
    if (function_exists('naechste_nummer')) return naechste_nummer($prefix);
    if (function_exists('erp_naechste_nummer')) return erp_naechste_nummer($prefix);
    return $prefix . '-' . gmdate('ymdHis');
}

// Wareneingang ohne Bestellung, Grund „alte Bestellung": nachträgliche Bestellung anlegen (Bestellweg direkt bestellt,
// Status geliefert, Menge = Eingang) und die Charge damit verknüpfen. Nico prüft Preis und Daten (Benachrichtigung +
// Datenprüfung „Nachträgliche Bestellung prüfen"). Rückgabe bestellung_id.
function webe_nachtraegliche_bestellung(int $charge_id, int $lieferant_id, string $notiz = '', string $wer = ''): int {
    webe_spalten();
    $c = one("SELECT c.id, c.item_id, c.menge, c.einheit, c.auftrag_id, c.charge_nr, i.name FROM charge c JOIN item i ON i.id=c.item_id WHERE c.id=?", [$charge_id]);
    if (!$c || $lieferant_id <= 0) return 0;
    $nr = webe_naechste_nummer('BE');
    $heute = gmdate('Y-m-d');
    q("INSERT INTO bestellung (nummer,lieferant_id,status,notiz,bestellart,bestaetigt,bestaetigt_am,bestelldatum,angekommen_am,station,nachtraeglich_grund)
       VALUES (?,?,'geliefert',?,'direkt',1,?,?,?,'eingegangen','alt_bestellung')",
      [$nr, $lieferant_id, trim('Nachträglich angelegt beim Wareneingang (alte Bestellung, nicht im System)' . ($wer !== '' ? ', ' . $wer : '') . '. Preis und Daten prüfen.' . ($notiz !== '' ? "\n" . $notiz : '')),
       gmdate('Y-m-d H:i:s'), $heute, $heute]);
    $bid = (int) insert_id();
    q("INSERT INTO bestellung_position (bestellung_id,item_id,bezeichnung,menge,einheit,auftrag_id,sort,geliefert_menge) VALUES (?,?,?,?,?,?,0,?)",
      [$bid, (int)$c['item_id'], mb_substr((string)$c['name'], 0, 200), (float)$c['menge'], (string)$c['einheit'], $c['auftrag_id'] ?: null, (float)$c['menge']]);
    $bpId = (int) insert_id();
    q("UPDATE charge SET bestellung_position_id=?, lieferant_id=COALESCE(lieferant_id, ?), we_grund='alt_bestellung' WHERE id=?", [$bpId, $lieferant_id, $charge_id]);
    webe_bedarf_bump();
    $firma = (string) scalar("SELECT firma FROM lieferanten WHERE id=?", [$lieferant_id]);
    webe_melden('Nachträgliche Bestellung ' . $nr . ' prüfen (' . $c['name'] . ')',
        'Beim Wareneingang ohne Bestellung angelegt (alte Bestellung, nicht im System): ' . $c['name'] . ', '
        . rtrim(rtrim(number_format((float)$c['menge'], 3, ',', '.'), '0'), ',') . ' ' . $c['einheit'] . ($firma !== '' ? ', ' . $firma : '')
        . ($wer !== '' ? ', gebucht von ' . $wer : '') . '. Bitte Preis und Daten an der Bestellung prüfen.',
        '?p=bestellung&id=' . $bid, true);
    return $bid;
}

// Wareneingang ohne Bestellung, sonstige Gründe (Muster, Kundenware, Sonstiges): Grund + Pflichtnotiz an der Charge und
// eine Meldung zum Prüfen.
function webe_ohne_bestellung_vermerken(int $charge_id, string $grund, string $notiz, string $wer = ''): void {
    webe_spalten();
    if ($charge_id <= 0) return;
    $grund = isset(webe_gruende()[$grund]) ? $grund : 'sonstiges';
    q("UPDATE charge SET we_grund=?, we_grund_notiz=? WHERE id=?", [$grund, mb_substr(trim($notiz), 0, 255) ?: null, $charge_id]);
    $c = one("SELECT c.menge, c.einheit, i.name FROM charge c JOIN item i ON i.id=c.item_id WHERE c.id=?", [$charge_id]);
    if (!$c) return;
    webe_melden('Wareneingang ohne Bestellung: ' . $c['name'] . ' (' . webe_gruende()[$grund] . ')',
        rtrim(rtrim(number_format((float)$c['menge'], 3, ',', '.'), '0'), ',') . ' ' . $c['einheit'] . ' ' . $c['name'] . ' ohne Bestellung eingebucht'
        . ($wer !== '' ? ' (' . $wer . ')' : '') . '. Grund: ' . webe_gruende()[$grund] . ($notiz !== '' ? ' – ' . $notiz : '') . '. Bitte prüfen.',
        '?p=chargen&id=' . $charge_id);
}

// Offene Bestellpositionen (erwartete Lieferungen) für die Auswahl „Bestellung wählen". $such: BE-Nr., Lieferant,
// Artikel, Auftrag oder Sendungsnummer (auch gescannt). Je Zeile: Position, Artikel (fest), Einheit, Rest, Kapselgröße.
function webe_offene_positionen(string $such = '', int $limit = 300, int $bestellung_id = 0): array {
    webe_spalten();
    $w = ["b.status NOT IN ('geliefert','storniert')", "b.angekommen_am IS NULL", "bp.menge > 0", "bp.rest_storniert_am IS NULL",
          "COALESCE(bp.geliefert_menge,0) < bp.menge * 0.98"];
    $p = [];
    if ($bestellung_id > 0) { $w = ["b.id = ?", "bp.menge > 0"]; $p[] = $bestellung_id; }
    $such = trim($such);
    if ($such !== '') {
        $kurz = preg_replace('/\s+/', '', $such);
        $like = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $such) . '%';
        $w[] = "(b.nummer LIKE ? ESCAPE '=' OR REPLACE(b.nummer,'-','') = REPLACE(?,'-','') OR l.firma LIKE ? ESCAPE '=' OR i.name LIKE ? ESCAPE '=' OR bp.bezeichnung LIKE ? ESCAPE '='
                 OR COALESCE(i.synonym,'') LIKE ? ESCAPE '=' OR a.nummer LIKE ? ESCAPE '=' OR REPLACE(COALESCE(b.tracking,''),' ','') = ? OR COALESCE(b.online_haendler,'') LIKE ? ESCAPE '='"
             . (webe_hat_spalte('item', 'synonyme') ? " OR COALESCE(i.synonyme,'') LIKE ? ESCAPE '='" : '') . ")";
        array_push($p, $like, $kurz, $like, $like, $like, $like, $like, $kurz, $like);
        if (webe_hat_spalte('item', 'synonyme')) $p[] = $like;
    }
    $rows = all("SELECT bp.id AS bp_id, bp.bestellung_id, b.nummer, b.status, b.station, b.bestellart, b.bestelldatum, b.eta_geplant, b.lieferant_id,
                        COALESCE(l.firma, NULLIF(b.online_haendler,''), '') AS lieferant,
                        bp.item_id, COALESCE(NULLIF(i.name,''), bp.bezeichnung) AS name, bp.bezeichnung, i.kategorie, i.form, i.rezeptur_id,
                        bp.menge, COALESCE(bp.geliefert_menge,0) AS geliefert, GREATEST(bp.menge - COALESCE(bp.geliefert_menge,0), 0) AS rest,
                        COALESCE(NULLIF(bp.einheit,''), i.einheit, '') AS einheit, bp.auftrag_id, a.nummer AS auftrag_nr
                 FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id
                 LEFT JOIN lieferanten l ON l.id=b.lieferant_id LEFT JOIN item i ON i.id=bp.item_id LEFT JOIN auftrag a ON a.id=bp.auftrag_id
                 WHERE " . implode(' AND ', $w) . "
                 ORDER BY (b.eta_geplant IS NULL), b.eta_geplant, b.id DESC, bp.sort, bp.id LIMIT " . max(1, (int)$limit), $p);
    foreach ($rows as &$r) $r['kapsel'] = webe_kapsel_label((int)$r['item_id']);
    unset($r);
    return $rows;
}

function webe_hat_spalte(string $t, string $c): bool {
    static $cache = [];
    $k = $t . '.' . $c;
    if (!array_key_exists($k, $cache)) {
        try { $cache[$k] = (int) scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?", [$t, $c]) > 0; }
        catch (\Throwable $e) { $cache[$k] = false; }
    }
    return $cache[$k];
}

// Bestellposition ohne Artikel (Freitext, z. B. „Bulk: … (AB-…)") bekommt beim Einbuchen den Artikel, auf den gebucht wurde,
// damit Bestellung, Charge und Netto-Rechnung denselben Artikel sehen.
function webe_position_artikel_setzen(int $bp_id, int $item_id): void {
    if ($bp_id > 0 && $item_id > 0) q("UPDATE bestellung_position SET item_id=? WHERE id=? AND item_id IS NULL", [$item_id, $bp_id]);
}

}
