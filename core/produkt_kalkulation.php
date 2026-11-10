<?php
// Produktseite (Nico 09.11.2026): Kosten je Packung aufgeschlüsselt je Bezugsweg, Beschaffungs-Überblick und Kunden-Überblick.
// Nur LESEN – hier wird nichts gespeichert. Gerechnet wird mit denselben Bausteinen wie der Beschaffungsweg am Auftrag
// (auftrag_beschaffungswege): Staffel je Lieferant zur Menge (staffel_je_lieferant / rohstoff_ek_detail), Zukaufpreise am Produkt
// (produkt_lieferant_preis) und an der Rezeptur (rezeptur_lief_angebot), Premix-Artikel, Leerkapsel, Behälter-EK-Staffel.
// Fehlt ein Preis, steht null in der Zeile und der Weg ist „unvollständig" – es wird nie still mit 0 gerechnet.

// Bezugseinheit-Umrechnung: mg -> Menge in der Preis-Einheit des Rohstoffs (kg, g, L mit Dichte; sonst kg).
function pk_mg_in_bezug(float $mg, string $bezug, float $dichte = 0.0): float {
    $b = strtolower(trim($bezug));
    if ($b === 'g') return $mg / 1000;
    if ($b === 'l') return $dichte > 0 ? $mg / 1e6 / $dichte : $mg / 1e6;
    if ($b === 'ml') return $dichte > 0 ? $mg / 1000 / $dichte : $mg / 1000;
    return $mg / 1e6;   // kg (Standard)
}

// Zukaufpreise am Produkt je Lieferant (auch Freitext-Lieferanten wie „Wellgreen"), umgerechnet je EINHEIT (Kapsel/Stk) bzw.
// je kg bei Schüttgut-Zeilen in kg. Staffel: größte Stufe bis zur Menge, sonst die kleinste (= Mindestmenge, Hinweis).
// Rückgabe je Lieferant: ['key','lieferant_id','lieferant','preis','einheit','ab','mindest','quelle'].
function pk_zukauf_kandidaten(int $pid, int $rid, float $einheiten, bool $schuett, float $kg): array {
    $ep = max(1, (int) scalar("SELECT COALESCE(einheiten_pro_packung,0) FROM produkt WHERE id=?", [$pid]));
    $je = [];
    if (table_exists('produkt_lieferant_preis')) {
        foreach (all("SELECT z.lieferant_id, z.lieferant_name, z.menge_ab, z.preis, LOWER(COALESCE(z.einheit,'')) AS e, z.stand, COALESCE(l.firma, z.lieferant_name, '') AS firma
                      FROM produkt_lieferant_preis z LEFT JOIN lieferanten l ON l.id=z.lieferant_id
                      WHERE z.produkt_id=? AND z.preis>0 AND (z.waehrung IS NULL OR z.waehrung='EUR')", [$pid]) as $r) {
            $pr = (float)$r['preis']; $ab = (float)$r['menge_ab'];
            if (in_array($r['e'], ['packung', 'pkg', 'pkg.', 'packungen'], true)) { $pr /= $ep; $ab *= $ep; }   // je Packung -> je Einheit
            if ($schuett && in_array($r['e'], ['kg'], true)) { $menge = $kg; $einh = 'kg'; } else { $menge = $einheiten; $einh = 'Stk'; }
            $k = 'p' . (int)$r['lieferant_id'] . '|' . mb_strtolower(trim((string)$r['firma']));
            $je[$k]['rows'][] = [$ab, $pr, $einh, (string)$r['stand']];
            $je[$k] += ['lieferant_id' => (int)$r['lieferant_id'], 'lieferant' => (string)$r['firma'], 'menge' => $menge, 'quelle' => 'Zukaufpreis am Produkt'];
        }
    }
    if ($rid > 0 && table_exists('rezeptur_lief_angebot')) {
        foreach (all("SELECT a.lieferant_id, a.menge, a.preis, LOWER(COALESCE(a.einheit,'')) AS e, a.stand, COALESCE(l.firma,'') AS firma
                      FROM rezeptur_lief_angebot a LEFT JOIN lieferanten l ON l.id=a.lieferant_id
                      WHERE a.rezeptur_id IN (?, ?) AND a.preis>0", [$rid, function_exists('rezeptur_stamm_id') ? rezeptur_stamm_id($rid) : $rid]) as $r) {
            $istKg = in_array($r['e'], ['kg', 'kilogramm'], true);
            if ($schuett !== $istKg && !($schuett && $r['e'] === '')) continue;   // Stück-Formen: Preis je Stk; Schüttgut: €/kg (Alt: ohne Einheit = €/kg)
            $k = 'r' . (int)$r['lieferant_id'] . '|' . mb_strtolower(trim((string)$r['firma']));
            $je[$k]['rows'][] = [(float)$r['menge'], (float)$r['preis'], $schuett ? 'kg' : 'Stk', (string)$r['stand']];
            $je[$k] += ['lieferant_id' => (int)$r['lieferant_id'], 'lieferant' => (string)$r['firma'], 'menge' => $schuett ? $kg : $einheiten, 'quelle' => 'Fremdfertigungs-Angebot an der Rezeptur'];
        }
    }
    $out = [];
    foreach ($je as $k => $j) {
        $rows = $j['rows']; usort($rows, fn($a, $b) => $a[0] <=> $b[0]);
        $sel = $rows[0];
        foreach ($rows as $r) if ($r[0] <= $j['menge'] + 1e-9) $sel = $r;
        $out[] = ['key' => $k, 'lieferant_id' => $j['lieferant_id'], 'lieferant' => $j['lieferant'] !== '' ? $j['lieferant'] : 'ohne Namen',
                  'preis' => $sel[1], 'einheit' => $sel[2], 'ab' => $sel[0], 'stand' => $sel[3],
                  'mindest' => $rows[0][0] > $j['menge'] + 1e-9 ? $rows[0][0] : null, 'quelle' => $j['quelle']];
    }
    usort($out, fn($a, $b) => $a['preis'] <=> $b['preis']);
    return $out;
}

// Kosten je Packung aufgeschlüsselt. $packungen = Bestellmenge (Staffel). Rückgabe:
// ['ok','packungen','einh','form','schuett','einheit_lbl','kg_je_pack','verpackung'=>[zeilen],'herstellung'=>null,
//  'wege'=>[key=>['label','zeilen'=>[['text','detail','preis'(je Pkg|null),'link']],'summe','vollstaendig','lieferanten'=>[…]]],
//  'standard'=>key]
function produkt_kalkulation(int $pid, int $packungen = 1000): array {
    $p = one("SELECT * FROM produkt WHERE id=?", [$pid]);
    if (!$p) return ['ok' => false];
    $packungen = max(1, $packungen);
    $rid  = (int)($p['rezeptur_id'] ?? 0);
    $rez  = $rid ? one("SELECT id, name, darreichungsform FROM rezeptur WHERE id=?", [$rid]) : null;
    $form = (string)($rez['darreichungsform'] ?? '');
    $einh = (float)($p['einheiten_pro_packung'] ?? 0);
    $schuett = $form !== '' && function_exists('form_groessen_einheit') && form_groessen_einheit($form) !== '';
    $zut  = $rid ? all("SELECT z.item_id, z.menge_mg, i.name, i.ek_preis, COALESCE(NULLIF(i.preis_bezug,''),'kg') AS bezug, COALESCE(i.dichte,0) AS dichte
                        FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=? ORDER BY z.menge_mg DESC", [$rid]) : [];
    $mgEinheit = 0.0; foreach ($zut as $z) $mgEinheit += (float)$z['menge_mg'];
    // mg Mischung je Packung: Stück-Formen = mg je Einheit × Einheiten; Schüttgut = Füllmenge g × 1000.
    $mgPack = $schuett ? $einh * 1000 : $mgEinheit * $einh;
    $kgPack = $mgPack / 1e6;
    $out = ['ok' => true, 'packungen' => $packungen, 'einh' => $einh, 'form' => $form, 'schuett' => $schuett, 'rezeptur' => $rez,
            'einheit_lbl' => $schuett ? form_groessen_einheit($form) : 'Stk', 'kg_je_pack' => $kgPack, 'verpackung' => [], 'wege' => [], 'hinweise' => []];
    if ($einh <= 0) $out['hinweise'][] = 'Menge je Packung fehlt am Produkt – Inhalt kann nicht gerechnet werden.';

    // ---- Verpackung je Packung (für alle Wege gleich) ----
    $vz = [];
    foreach ((function_exists('produkt_verpackung_items') ? produkt_verpackung_items($pid) : []) as $it) {
        $ek = pack_ek_bei_menge((int)$it['id'], $packungen);
        $art = $it['rolle'] === 'Verpackung' ? 'Glas/Behälter' : $it['rolle'];
        $vz[] = ['text' => $art, 'detail' => (string)$it['name'], 'preis' => $ek > 0 ? $ek : null, 'link' => '?p=verpackung&id=' . (int)$it['id']];
    }
    if (empty($p['verpackung_id']) && !$schuett) $vz[] = ['text' => 'Glas/Behälter', 'detail' => 'keiner gewählt', 'preis' => null, 'link' => '', 'leer' => true];
    if (!empty($p['karton_id'])) {
        $kn = (string) scalar("SELECT name FROM item WHERE id=?", [(int)$p['karton_id']]);
        $ek = pack_ek_bei_menge((int)$p['karton_id'], (int) ceil($packungen / max(1, (int)$p['karton_inhalt'])));
        $inh = (int)($p['karton_inhalt'] ?? 0);
        $vz[] = ['text' => 'Umkarton (anteilig)', 'detail' => $kn . ($inh > 0 ? ' · ' . $inh . ' Pkg je Karton' : ' · Packungen je Karton fehlt'),
                 'preis' => ($ek > 0 && $inh > 0) ? $ek / $inh : null, 'link' => '?p=verpackung&id=' . (int)$p['karton_id']];
    }
    if (!empty($p['beipack_id'])) {
        $ek = pack_ek_bei_menge((int)$p['beipack_id'], $packungen);
        $vz[] = ['text' => 'Beipackzettel', 'detail' => (string) scalar("SELECT name FROM item WHERE id=?", [(int)$p['beipack_id']]), 'preis' => $ek > 0 ? $ek : null, 'link' => '?p=verpackung&id=' . (int)$p['beipack_id']];
    }
    $out['verpackung'] = $vz;
    $vSumme = 0.0; $vVoll = true;
    foreach ($vz as $z) { if ($z['preis'] === null) { if (empty($z['leer'])) $vVoll = false; } else $vSumme += $z['preis']; }

    // Leerkapsel / Hilfsstoffe je Packung (Premix + Eigen; bei fertig zugekauften Kapseln im Preis enthalten).
    $zusatz = null;
    if (in_array($form, ['kapsel'], true)) {
        $kid = produkt_leerkapsel_id($pid);
        $kek = $kid ? (float) scalar("SELECT ek_preis FROM item WHERE id=?", [$kid]) : 0.0;
        $zusatz = ['text' => 'Leerkapseln', 'detail' => ($kid ? (string) scalar("SELECT name FROM item WHERE id=?", [$kid]) : 'keine eindeutige Leerkapsel') . ' · ' . number_format($einh, 0, ',', '.') . ' Stk',
                   'preis' => $kek > 0 ? $kek * $einh : null, 'link' => $kid ? '?p=rohstoff&id=' . $kid : ''];
    } elseif ($form === 'tablette' && $rid) {
        $zusatz = ['text' => 'Presshilfsstoffe', 'detail' => tablette_hilfsstoff_prozent() . ' % auf das Wirkstoffgewicht', 'preis' => tablette_hilfsstoff_ek_stueck($rid) * $einh, 'link' => ''];
    } elseif ($form === 'gummi' && $rid && function_exists('gummi_basis_ek_stueck')) {
        $zusatz = ['text' => 'Gummi-Grundmasse', 'detail' => '', 'preis' => gummi_basis_ek_stueck($rid) * $einh, 'link' => ''];
    } elseif ($form === 'fluessig') {
        $zusatz = ['text' => 'Trägerflüssigkeit', 'detail' => number_format(fluessig_basis_ek_l(), 2, ',', '.') . ' €/l', 'preis' => $einh / 1000 * fluessig_basis_ek_l(), 'link' => ''];
    } elseif ($form === 'gel' && function_exists('gel_basis_ek_l')) {
        $zusatz = ['text' => 'Gel-Grundmasse', 'detail' => '', 'preis' => $einh / 1000 * gel_basis_ek_l(), 'link' => ''];
    }

    $fertig = function (string $label, array $inhalt) use ($vz, $vSumme, $vVoll) {
        $s = 0.0; $voll = $vVoll;
        foreach ($inhalt as $z) { if ($z['preis'] === null) $voll = false; else $s += $z['preis']; }
        return ['label' => $label, 'inhalt' => $inhalt, 'summe' => $s + $vSumme, 'inhalt_summe' => $s, 'vollstaendig' => $voll && $inhalt];
    };
    $fmtP = fn(float $x) => number_format($x, $x < 1 ? 4 : 2, ',', '.') . ' €';

    // ---- Weg (a): fertig zukaufen (Kapseln/Tabletten bzw. fertige Mischung) ----
    $unitsTot = $einh * $packungen;
    $kand = $rid || $pid ? pk_zukauf_kandidaten($pid, $rid, $unitsTot, $schuett, $kgPack * $packungen) : [];
    if ($kand) {
        $b = $kand[0];
        $jePack = $b['einheit'] === 'kg' ? $b['preis'] * $kgPack : $b['preis'] * $einh;
        $det = $b['lieferant'] . ' · ' . $fmtP($b['preis']) . '/' . ($b['einheit'] === 'kg' ? 'kg' : ($form === 'kapsel' ? 'Kapsel' : 'Stk'))
             . ($b['ab'] > 0 ? ' ab ' . number_format($b['ab'], 0, ',', '.') : '') . ' × ' . ($b['einheit'] === 'kg' ? number_format($kgPack * 1000, 1, ',', '.') . ' g' : number_format($einh, 0, ',', '.'))
             . ($b['mindest'] ? ' · Mindestmenge ' . number_format($b['mindest'], 0, ',', '.') . ' ' . $b['einheit'] . ' (Bedarf ' . number_format($b['einheit'] === 'kg' ? $kgPack * $packungen : $unitsTot, 0, ',', '.') . ')' : '');
        $inh = [['text' => 'Inhalt (fertig zugekauft)', 'detail' => $det, 'preis' => $einh > 0 ? $jePack : null, 'link' => $b['lieferant_id'] ? '?p=lieferant&id=' . $b['lieferant_id'] : '']];
    } else {
        $inh = [['text' => 'Inhalt (fertig zugekauft)', 'detail' => 'kein Zukaufpreis hinterlegt', 'preis' => null, 'link' => '']];
    }
    $out['wege']['kapsel'] = $fertig($schuett ? 'Zukauf fertige Mischung' : 'Zukauf fertig (Kapsel/Tablette)', $inh) + ['lieferanten' => $kand];

    // ---- Weg (b): Premix (fertige Mischung zukaufen, selbst verkapseln) – nur Stück-Formen ----
    if (!$schuett && $rid) {
        $pm = rezeptur_premixitem($rid, false);
        $zb = [];
        foreach (all("SELECT lieferant_id, menge, preis, LOWER(COALESCE(einheit,'')) AS e FROM rezeptur_lief_angebot WHERE rezeptur_id IN (?, ?) AND lieferant_id IS NOT NULL AND preis>0",
                     [$rid, rezeptur_stamm_id($rid)]) as $r) if ($r['e'] === 'kg') $zb[] = [(int)$r['lieferant_id'], (float)$r['menge'], (float)$r['preis']];
        if ($pm) foreach (all("SELECT lieferant_id, menge_ab, preis FROM lieferant_preis WHERE item_id=? AND lieferant_id IS NOT NULL AND (waehrung IS NULL OR waehrung='EUR')", [$pm]) as $r)
            $zb[] = [(int)$r['lieferant_id'], (float)$r['menge_ab'], (float)$r['preis']];
        $pp = staffel_je_lieferant($zb, $kgPack * $packungen); asort($pp);
        $lid = $pp ? (int) array_key_first($pp) : 0;
        $inh = [['text' => 'Premix', 'detail' => $pp ? ((string) scalar("SELECT firma FROM lieferanten WHERE id=?", [$lid]) . ' · ' . $fmtP((float)$pp[$lid]) . '/kg × ' . number_format($kgPack * 1000, 1, ',', '.') . ' g') : 'kein Premix-Preis hinterlegt',
                 'preis' => $pp ? (float)$pp[$lid] * $kgPack : null, 'link' => $pm ? '?p=rohstoff&id=' . $pm : '']];
        if ($zusatz) $inh[] = $zusatz;
        $out['wege']['premix'] = $fertig('Premix (fertige Mischung)', $inh);
    }

    // ---- Weg (c): Einzelrohstoffe (Eigenproduktion) ----
    if ($rid) {
        $inh = [];
        foreach ($zut as $z) {
            $mg = $schuett ? ($mgEinheit > 0 ? (float)$z['menge_mg'] / $mgEinheit * $mgPack : 0.0) : (float)$z['menge_mg'] * $einh;
            $mBez = pk_mg_in_bezug($mg, (string)$z['bezug'], (float)$z['dichte']);
            $d = rohstoff_ek_detail((int)$z['item_id'], $mBez * $packungen);
            $pr = $d['preis'] ?? ((float)$z['ek_preis'] > 0 ? (float)$z['ek_preis'] : null);
            $inh[] = ['text' => (string)$z['name'], 'detail' => number_format($mg, $mg < 100 ? 1 : 0, ',', '.') . ' mg je Pkg' . ($pr !== null ? ' · ' . $fmtP($pr) . '/' . $z['bezug'] . ($d === null ? ' (EK am Rohstoff)' : '') : ''),
                      'preis' => $pr !== null ? $pr * $mBez : null, 'link' => '?p=rohstoff&id=' . (int)$z['item_id']];
        }
        if (!$zut) $inh[] = ['text' => 'Rezeptur', 'detail' => 'ohne Zutaten', 'preis' => null, 'link' => '?p=rezeptur_detail&id=' . $rid];
        if ($zusatz) $inh[] = $zusatz;
        $out['wege']['rohstoffe'] = $fertig('Einzelrohstoffe (Eigenproduktion)', $inh);
    }
    // Herstellung (Lohn/Arbeit): es gibt keinen hinterlegten Satz -> nur Hinweis, nicht gerechnet.
    $out['herstellung'] = null;

    // ---- Standard-Weg: zuletzt gewählter Weg in einem Produktionsauftrag dieses Produkts, sonst aus dem Produkt abgeleitet ----
    $std = '';
    $lastPa = one("SELECT pa.beschaffungsweg, pa.produktionsart, pa.art_festgelegt_am FROM produktionsauftrag pa WHERE pa.produkt_id=? ORDER BY pa.id DESC LIMIT 1", [$pid]);
    if ($lastPa) $std = pa_beschaffungsweg($lastPa);
    if ($std === '' || !isset($out['wege'][$std])) {
        if ((int)($p['handelsware'] ?? 0) === 1 || (!empty($out['wege']['kapsel']['vollstaendig']) && empty($out['wege']['rohstoffe']['vollstaendig']))) $std = 'kapsel';
        else $std = isset($out['wege']['rohstoffe']) ? 'rohstoffe' : 'kapsel';
    }
    $out['standard'] = $std;
    $out['standard_quelle'] = $lastPa && pa_beschaffungsweg($lastPa) === $std ? 'zuletzt im Produktionsauftrag gewählt' : 'aus den hinterlegten Preisen abgeleitet';
    return $out;
}

// Kosten je Packung beim Standard-Weg (Kachel). ['preis'=>float|null, 'vollstaendig'=>bool, 'weg'=>label]
function produkt_kosten_kurz(int $pid, int $packungen = 1000): array {
    $k = produkt_kalkulation($pid, $packungen);
    if (empty($k['ok']) || empty($k['wege'][$k['standard']])) return ['preis' => null, 'vollstaendig' => false, 'weg' => ''];
    $w = $k['wege'][$k['standard']];
    return ['preis' => $w['summe'] > 0 ? $w['summe'] : null, 'vollstaendig' => (bool)$w['vollstaendig'], 'weg' => $w['label']];
}

// Wirkstoffe der Rezeptur je Rohstoff (für den Reiter Rezeptur): mg je Einheit, Nährstoffe je Rohstoff, Tagesdosis.
// Rückgabe ['zeilen'=>[['item_id','name','mg','naehrstoffe'=>[[name,mg,einheit]]]], 'deklaration'=>[[name,mg_tag,einheit,nrv_proz|null,anzeige]], 'faktor'=>Einheiten je Tag]
function produkt_rezeptur_ansicht(int $pid): array {
    $p = one("SELECT rezeptur_id, einnahme_pro_tag FROM produkt WHERE id=?", [$pid]);
    $rid = (int)($p['rezeptur_id'] ?? 0);
    if (!$rid) return ['zeilen' => [], 'deklaration' => [], 'faktor' => 0];
    $form = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rid]);
    $faktor = in_array($form, ['pulver', 'granulat'], true) ? 1.0 : max(0.0, (float)($p['einnahme_pro_tag'] ?? 1));   // Pulver: Rezeptur = 1 Tagesdosis
    $zeilen = []; $dekl = [];
    foreach (all("SELECT z.item_id, z.menge_mg, i.name FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=? ORDER BY z.menge_mg DESC", [$rid]) as $z) {
        $ns = [];
        foreach (all("SELECT n.name, n.nrv_wert, n.einheit, n.ie_mg, n.einheit_anzeige, COALESCE(iw.gehalt_wert, iw.gehalt_prozent) AS gw, COALESCE(iw.gehalt_einheit,'prozent') AS ge
                      FROM item_wirkstoff iw JOIN naehrstoff n ON n.id=iw.naehrstoff_id WHERE iw.item_id=?", [(int)$z['item_id']]) as $w) {
            if ($w['gw'] === null) { $ns[] = ['name' => $w['name'], 'mg' => null, 'einheit' => $w['einheit']]; continue; }
            $mgN = (float)$z['menge_mg'] * wirkstoff_mg_je_mg($w['gw'], $w['ge'], $w['ie_mg']);
            $ns[] = ['name' => $w['name'], 'mg' => $mgN, 'einheit' => $w['einheit']];
            $k = $w['name'];
            if (!isset($dekl[$k])) $dekl[$k] = ['name' => $w['name'], 'mg' => 0.0, 'einheit' => $w['einheit'], 'nrv' => $w['nrv_wert'], 'anzeige' => $w['einheit_anzeige'], 'ie_mg' => $w['ie_mg']];
            $dekl[$k]['mg'] += $mgN;
        }
        $zeilen[] = ['item_id' => (int)$z['item_id'], 'name' => (string)$z['name'], 'mg' => (float)$z['menge_mg'], 'naehrstoffe' => $ns];
    }
    $out = [];
    foreach ($dekl as $d) {
        $tag = $d['mg'] * $faktor;
        $nrvMg = $d['nrv'] !== null ? ($d['einheit'] === 'µg' ? (float)$d['nrv'] / 1000 : (float)$d['nrv']) : 0.0;
        $out[] = ['name' => $d['name'], 'mg_einheit' => $d['mg'], 'mg_tag' => $tag, 'einheit' => $d['einheit'], 'anzeige' => $d['anzeige'], 'ie_mg' => $d['ie_mg'],
                  'nrv_proz' => $nrvMg > 0 ? $tag / $nrvMg * 100 : null];
    }
    return ['zeilen' => $zeilen, 'deklaration' => $out, 'faktor' => $faktor, 'form' => $form, 'rezeptur_id' => $rid];
}

// Beschaffung eines Produkts (Reiter Beschaffung): Lieferanten für Zukauf, Rohstoffe, Verpackung, letzte Bestellungen, Anfragen.
function produkt_beschaffung(int $pid, int $packungen = 1000): array {
    $p = one("SELECT * FROM produkt WHERE id=?", [$pid]);
    if (!$p) return [];
    $rid = (int)($p['rezeptur_id'] ?? 0);
    $hatPortal = function (int $lid): bool { return $lid > 0 && (int) scalar("SELECT COUNT(*) FROM benutzer WHERE lieferant_id=? AND aktiv=1", [$lid]) > 0; };
    $lief = function (int $lid) { return $lid > 0 ? one("SELECT id, firma, land, ansprechpartner, email, telefon, lieferzeit_tage, mindestbestellwert FROM lieferanten WHERE id=?", [$lid]) : null; };
    // (1) Zukauf-Lieferanten mit allen Staffeln (Produkt + Rezeptur), je Lieferant gebündelt.
    $pkSchuett = $rid > 0 && form_groessen_einheit((string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rid])) !== '';
    $zk = [];
    foreach (all("SELECT z.*, COALESCE(l.firma, z.lieferant_name, '') AS firma FROM produkt_lieferant_preis z LEFT JOIN lieferanten l ON l.id=z.lieferant_id
                  WHERE z.produkt_id=? ORDER BY z.menge_ab", [$pid]) as $r) {
        $k = (int)$r['lieferant_id'] . '|' . mb_strtolower(trim((string)$r['firma']));
        $zk[$k]['lid'] = (int)$r['lieferant_id']; $zk[$k]['firma'] = (string)$r['firma'];
        $zk[$k]['staffeln'][] = ['ab' => (float)$r['menge_ab'], 'preis' => (float)$r['preis'], 'einheit' => (string)($r['einheit'] ?: 'Stk'), 'waehrung' => (string)($r['waehrung'] ?: 'EUR'),
                                 'incoterm' => (string)($r['incoterm'] ?? ''), 'versandart' => (string)($r['versandart'] ?? ''), 'stand' => (string)($r['stand'] ?? ''), 'quelle' => 'Produkt', 'id' => (int)$r['id']];
    }
    if ($rid) foreach (all("SELECT a.*, COALESCE(l.firma,'') AS firma FROM rezeptur_lief_angebot a LEFT JOIN lieferanten l ON l.id=a.lieferant_id
                            WHERE a.rezeptur_id IN (?, ?) AND a.preis>0 ORDER BY a.menge", [$rid, rezeptur_stamm_id($rid)]) as $r) {
        // Kapsel/Tablette: Rezeptur-Preise in kg sind Premix (lose Mischung je kg) - eigene Gruppe, nicht mit Stückpreisen vergleichen.
        $istPm = !$pkSchuett && in_array(mb_strtolower(trim((string)$r['einheit'])), ['kg', 'kilogramm'], true);
        $k = ($istPm ? 'pm' : '') . (int)$r['lieferant_id'] . '|' . mb_strtolower(trim((string)$r['firma']));
        $zk[$k]['lid'] = (int)$r['lieferant_id']; $zk[$k]['firma'] = (string)$r['firma']; $zk[$k]['premix'] = $istPm;
        $zk[$k]['staffeln'][] = ['ab' => (float)$r['menge'], 'preis' => (float)$r['preis'], 'einheit' => (string)($r['einheit'] ?: 'Stk'), 'waehrung' => 'EUR',
                                 'incoterm' => '', 'versandart' => '', 'stand' => (string)($r['stand'] ?: $r['angelegt']), 'quelle' => $istPm ? 'Premix (Rezeptur)' : 'Rezeptur', 'id' => 0];
    }
    $zukauf = [];
    foreach ($zk as $k => $z) {
        $l = $lief($z['lid']);
        $doks = $z['lid'] ? all("SELECT id, typ, datei_orig FROM dokument WHERE lieferant_id=? AND ((objekt_typ='produkt' AND objekt_id=?) OR (objekt_typ='rezeptur' AND objekt_id=?)) ORDER BY id DESC LIMIT 6", [$z['lid'], $pid, $rid]) : [];
        $zukauf[] = ['lieferant_id' => $z['lid'], 'firma' => $z['firma'] ?: 'ohne Namen', 'lief' => $l, 'portal' => $hatPortal($z['lid']), 'staffeln' => $z['staffeln'], 'doks' => $doks, 'premix' => !empty($z['premix']),
                     'min_preis' => min(array_map(fn($s) => $s['preis'], $z['staffeln']))];
    }
    usort($zukauf, fn($a, $b) => [$a['premix'], $a['min_preis']] <=> [$b['premix'], $b['min_preis']]);   // Stückpreise zuerst, Premix (je kg) danach
    // (2) Rohstoffe der Rezeptur mit Lieferantenpreisen + Bestand.
    $kalk = produkt_kalkulation($pid, $packungen);
    $roh = [];
    if ($rid) foreach (all("SELECT z.item_id, z.menge_mg, i.name, i.einheit, COALESCE(NULLIF(i.preis_bezug,''),'kg') AS bezug, i.ek_preis FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=? ORDER BY z.menge_mg DESC", [$rid]) as $z) {
        $pr = all("SELECT lp.lieferant_id, lp.menge_ab, lp.preis, lp.waehrung, COALESCE(l.firma, lp.lieferant_name, '') AS firma FROM lieferant_preis lp LEFT JOIN lieferanten l ON l.id=lp.lieferant_id
                   WHERE lp.item_id=? AND lp.preis>0 ORDER BY lp.preis, lp.menge_ab", [(int)$z['item_id']]);
        $roh[] = $z + ['preise' => $pr, 'bestand' => (float) item_bestand((int)$z['item_id'], true)];
    }
    // (3) Verpackung mit Lieferant/Preis/Bestand.
    $verp = [];
    $slots = ['verpackung_id' => 'Glas/Behälter', 'verschluss_id' => 'Deckel', 'etikett_id' => 'Etikett', 'karton_id' => 'Umkarton', 'beipack_id' => 'Beipackzettel'];
    foreach ($slots as $f => $lbl) {
        $iid = (int)($p[$f] ?? 0);
        if ($f === 'etikett_id' && !$iid && !empty($p['verpackung_id'])) $iid = (int) etikett_id_fuer_behaelter((int)$p['verpackung_id']);
        if (!$iid) continue;
        $it = one("SELECT i.id, i.name, i.artikelnummer, i.ek_preis, i.haupt_lieferant_id, l.firma FROM item i LEFT JOIN lieferanten l ON l.id=i.haupt_lieferant_id WHERE i.id=?", [$iid]);
        if (!$it) continue;
        $ekS = all("SELECT menge_ab, ek_preis FROM pack_ek_staffel WHERE item_id=? ORDER BY menge_ab", [$iid]);
        $verp[] = ['rolle' => $lbl, 'item' => $it, 'ek_staffel' => $ekS, 'ek' => pack_ek_bei_menge($iid, $packungen), 'bestand' => (float) item_bestand($iid, true), 'unterwegs' => (float) item_unterwegs($iid)];
    }
    // (4) Letzte Bestellungen: Rohstoffe, Verpackung, Bulk-/Premix-Artikel und Freitext-Positionen zu Aufträgen dieses Produkts.
    $items = array_merge(array_map(fn($r) => (int)$r['item_id'], $roh), array_map(fn($v) => (int)$v['item']['id'], $verp));
    if ($rid) { $bk = (int) scalar("SELECT id FROM item WHERE rezeptur_id=? AND kategorie<>'verpackung' AND COALESCE(form,'')<>'premix' LIMIT 1", [rezeptur_stamm_id($rid)]); if ($bk) $items[] = $bk;
                $pm = rezeptur_premixitem($rid, false); if ($pm) $items[] = $pm; }
    $in = implode(',', array_map('intval', $items ?: [0]));
    $best = all("SELECT b.*, COALESCE(l.firma, b.online_haendler, '') AS lieferant,
                        bp.menge, bp.einheit, bp.ek_preis, COALESCE(i.name, bp.bezeichnung) AS was
                 FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id LEFT JOIN lieferanten l ON l.id=b.lieferant_id LEFT JOIN item i ON i.id=bp.item_id
                 WHERE b.status<>'storniert' AND (bp.item_id IN ($in) OR (bp.item_id IS NULL AND bp.auftrag_id IN (SELECT id FROM auftrag WHERE produkt_id=?)))
                 ORDER BY b.id DESC LIMIT 12", [$pid]);
    // (5) Preisanfragen zu Rezeptur/Produkt und zu den Rohstoffen, mit Antwort.
    $anf = all("SELECT la.id, la.nummer, la.status, la.angelegt, la.art, la.form, la.menge, la.einheit, COALESCE(l.firma,'') AS firma, COALESCE(i.name,'') AS item,
                       ag.preis AS ang_preis, ag.einheit AS ang_einheit, ag.waehrung AS ang_waehrung, ag.lieferzeit_tage AS ang_lz, ag.mindestmenge AS ang_mm
                FROM lieferant_anfrage la LEFT JOIN lieferanten l ON l.id=la.lieferant_id LEFT JOIN item i ON i.id=la.item_id
                LEFT JOIN lieferant_angebot ag ON ag.anfrage_id=la.id
                WHERE (? > 0 AND la.rezeptur_id IN (?, ?)) OR la.item_id IN ($in)
                ORDER BY la.id DESC LIMIT 15", [$rid, $rid, $rid ? rezeptur_stamm_id($rid) : 0]);
    return ['zukauf' => $zukauf, 'rohstoffe' => $roh, 'verpackung' => $verp, 'bestellungen' => $best, 'anfragen' => $anf, 'kalk' => $kalk, 'rezeptur_id' => $rid];
}

// Kunden eines Produkts (Reiter Kunden): eine Zeile je Kunde aus Name beim Kunden, Kundenpreisen, Kundenetikett,
// Angeboten und Rechnungen. Rückgabe [kunde_id => ['kunde_id','firma','kuerzel','name','preise'=>[],'etikett'=>?,'angebot'=>?,'rechnung'=>?]].
function produkt_kunden(int $pid): array {
    $k = [];
    $neu = function (int $kid, string $firma) use (&$k) {
        if (!isset($k[$kid])) $k[$kid] = ['kunde_id' => $kid, 'firma' => $firma, 'name' => '', 'preise' => [], 'etikett' => null, 'angebot' => null, 'rechnung' => null, 'auftraege' => 0];
    };
    foreach (produkt_kundennamen($pid) as $r) { $neu((int)$r['kunde_id'], (string)$r['firma']); $k[(int)$r['kunde_id']]['name'] = (string)$r['name']; }
    foreach (all("SELECT kp.*, k.firma FROM produkt_kundenpreis kp JOIN kunden k ON k.id=kp.kunde_id WHERE kp.produkt_id=? ORDER BY kp.anzahl_vpe", [$pid]) as $r) {
        $neu((int)$r['kunde_id'], (string)$r['firma']); $k[(int)$r['kunde_id']]['preise'][] = $r;
    }
    foreach (all("SELECT i.id, i.kunde_id, COALESCE(i.etikett_version,1) AS v, COALESCE(i.etikett_dokument_id,0) AS dok, k.firma FROM item i JOIN kunden k ON k.id=i.kunde_id
                  WHERE i.verpackung_rolle='etikett' AND i.produkt_id=? AND COALESCE(i.gesperrt,0)=0 ORDER BY i.etikett_version DESC", [$pid]) as $r) {
        $neu((int)$r['kunde_id'], (string)$r['firma']);
        if ($k[(int)$r['kunde_id']]['etikett'] === null) {
            $bi = etikett_bestand_info($pid, (int)$r['kunde_id']);
            $k[(int)$r['kunde_id']]['etikett'] = ['item_id' => (int)$r['id'], 'version' => (int)$r['v'], 'dok' => (int)$r['dok'], 'lager' => (float)$bi['physisch'],
                                                  'unterwegs' => (float) item_unterwegs((int)$r['id']), 'bedarf' => (float)$bi['kunde_rest']];
        }
    }
    foreach (all("SELECT a.id, a.nummer, a.status, a.angelegt, a.kunde_id, k.firma FROM angebot a JOIN kunden k ON k.id=a.kunde_id
                  WHERE (a.produkt_id=? OR a.id IN (SELECT angebot_id FROM angebot_produkt WHERE produkt_id=?)) AND COALESCE(a.notiz,'') NOT LIKE 'Nachbestellung #%'
                  ORDER BY a.id DESC", [$pid, $pid]) as $r) {
        $neu((int)$r['kunde_id'], (string)$r['firma']);
        if ($k[(int)$r['kunde_id']]['angebot'] === null) $k[(int)$r['kunde_id']]['angebot'] = $r;
    }
    foreach (all("SELECT b.id, b.nummer, b.netto, b.datum, a.menge, a.kunde_id, k.firma, a.id AS auftrag_id FROM beleg b JOIN auftrag a ON a.id=b.auftrag_id JOIN kunden k ON k.id=a.kunde_id
                  WHERE a.produkt_id=? AND b.typ='rechnung' AND b.status<>'storniert' ORDER BY b.id DESC", [$pid]) as $r) {
        $neu((int)$r['kunde_id'], (string)$r['firma']);
        if ($k[(int)$r['kunde_id']]['rechnung'] === null) $k[(int)$r['kunde_id']]['rechnung'] = $r + ['je_pkg' => (int)$r['menge'] > 0 ? (float)$r['netto'] / (int)$r['menge'] : 0.0];
    }
    foreach (all("SELECT kunde_id, COUNT(*) AS n FROM auftrag WHERE produkt_id=? AND status<>'storniert' AND kunde_id IS NOT NULL GROUP BY kunde_id", [$pid]) as $r)
        if (isset($k[(int)$r['kunde_id']])) $k[(int)$r['kunde_id']]['auftraege'] = (int)$r['n'];
    uasort($k, fn($a, $b) => strcasecmp(kunde_name_voll($a['kunde_id'], $a['firma'], false), kunde_name_voll($b['kunde_id'], $b['firma'], false)));
    return $k;
}

// Einheitliche Kundenanzeige auf der Produktseite: „Kürzel (Firma)", ohne Kürzel nur die Firma (Nico 09.11.2026).
function kunde_name_voll(int $kid, string $firma, bool $html = true): string {
    $kz = $kid && function_exists('kunde_kuerzel') ? trim((string) kunde_kuerzel($kid)) : '';
    $t = ($kz !== '' && mb_strtolower($kz) !== mb_strtolower($firma)) ? $kz . ' (' . $firma . ')' : $firma;
    return $html ? h($t) : $t;
}
