<?php
// Altlasten Einkauf (Einkauf gerade ziehen, Teil 4, 10.11.2026): Bestellungen ohne Wareneingang mit vorhandenen Chargen
// abgleichen. Nur auf Klick: „verknüpfen", „als geliefert schließen", „stornieren". Dashboard-Datei (braucht core/schema.php).
require_once __DIR__ . '/artikel_dubletten.php';
require_once __DIR__ . '/charge_korrektur.php';   // Block B: „Menge korrigieren und verknüpfen"

// Artikel, auf die die Ware einer Position gebucht sein kann: der Artikel selbst + ähnliche (Dubletten, zusammengeführte);
// bei Freitext-Positionen der Bulk-/Fertigartikel des Auftrags bzw. Namenstreffer. Rückgabe [item_id => 'gleich'|'ähnlich'].
function bestellung_abgleich_artikel(array $bp): array {
    $set = [];
    $iid = (int)($bp['item_id'] ?? 0);
    if ($iid > 0) {
        $set[$iid] = 'gleich';
        foreach (artikel_aehnliche($iid) as $a) $set[$a] = $set[$a] ?? 'ähnlich';
        return $set;
    }
    $aid = (int)($bp['auftrag_id'] ?? 0);
    if ($aid > 0) {
        $au = one("SELECT a.produkt_id, COALESCE(a.rezeptur_id, p.rezeptur_id) AS rid FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$aid]);
        if ($au) {
            foreach (all("SELECT id FROM item WHERE rezeptur_id=? AND kategorie='fertig'", [(int)$au['rid']]) as $r) $set[(int)$r['id']] = 'gleich';
            foreach (all("SELECT id FROM item WHERE produkt_id=? AND kategorie='verkaufsfertig'", [(int)$au['produkt_id']]) as $r) $set[(int)$r['id']] = 'gleich';
        }
    }
    $nm = trim(preg_replace('/\s*\(.*$/u', '', preg_replace('/^(Bulk|Premix):\s*/u', '', (string)($bp['bezeichnung'] ?? ''))));
    if ($nm !== '') foreach (artikel_dubletten_treffer($nm, '', 6) as $t) $set[(int)$t['id']] = $set[(int)$t['id']] ?? 'ähnlich';
    return $set;
}

// Vorschläge vorhandener Chargen für eine Bestellposition. Kriterien: gleicher Lieferant, gleicher oder ähnlicher Artikel,
// passende Menge, Eingang nach dem Bestelldatum; nur Chargen ohne Bestellbezug und keine eigene Produktion.
function bestellung_abgleich_vorschlaege(int $bp_id, int $limit = 5): array {
    $bp = one("SELECT bp.*, b.lieferant_id, COALESCE(b.bestelldatum, DATE(b.angelegt)) AS bdatum FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id WHERE bp.id=?", [$bp_id]);
    if (!$bp) return [];
    $set = bestellung_abgleich_artikel($bp);
    if (!$set) return [];
    $in = implode(',', array_map('intval', array_keys($set)));
    $rows = all("SELECT c.id, c.charge_nr, c.item_id, c.menge, c.menge_verfuegbar, c.einheit, c.lieferant_id, c.wareneingang, c.mhd, c.auftrag_id,
                        i.name AS artikel, l.firma AS lieferant
                 FROM charge c JOIN item i ON i.id=c.item_id LEFT JOIN lieferanten l ON l.id=c.lieferant_id
                 WHERE c.item_id IN ($in) AND c.bestellung_position_id IS NULL AND c.pa_id IS NULL AND c.fremd_kunde_id IS NULL AND c.menge > 0
                   AND COALESCE(c.wareneingang, DATE(c.angelegt)) >= DATE_SUB(?, INTERVAL 3 DAY)
                 ORDER BY COALESCE(c.wareneingang, DATE(c.angelegt))", [(string)$bp['bdatum']]);
    $out = [];
    foreach ($rows as $c) {
        $p = 0; $gr = [];
        if ($set[(int)$c['item_id']] === 'gleich') { $p += 3; $gr[] = 'gleicher Artikel'; } else { $p += 1; $gr[] = 'ähnlicher Artikel'; }
        if ((int)$c['lieferant_id'] > 0 && (int)$c['lieferant_id'] === (int)$bp['lieferant_id']) { $p += 3; $gr[] = 'gleicher Lieferant'; }
        elseif ((int)$c['lieferant_id'] > 0 && (int)$bp['lieferant_id'] > 0) { $p -= 2; $gr[] = 'anderer Lieferant'; }
        $m = (float)$c['menge'] * webe_einheit_faktor((string)$c['einheit'], (string)$bp['einheit']);
        $soll = max(0.0, (float)$bp['menge'] - (float)$bp['geliefert_menge']);
        if ($soll > 0 && abs($m - $soll) <= $soll * 0.02) { $p += 3; $gr[] = 'Menge passt'; }
        elseif ($soll > 0 && $m <= $soll * 1.25) { $p += 1; $gr[] = 'Teilmenge'; }
        if (!empty($c['auftrag_id']) && (int)$c['auftrag_id'] === (int)$bp['auftrag_id']) { $p += 2; $gr[] = 'gleicher Auftrag'; }
        if ($p < 3) continue;
        $c['punkte'] = $p; $c['gruende'] = $gr; $c['menge_bp'] = $m;
        // Block B Teil 2 (11.11.2026): offene Bestellmenge in der Einheit der Charge + sichtbare Abweichung („Charge 300.000, offen 240.000").
        $fk = webe_einheit_faktor((string)$c['einheit'], (string)$bp['einheit']);
        $c['offen_charge'] = $fk > 0 ? round($soll / $fk, 3) : $soll;
        $c['abweichung'] = ($soll > 0 && abs($m - $soll) > $soll * 0.02)
            ? 'Charge ' . rtrim(rtrim(number_format((float)$c['menge'], 3, ',', '.'), '0'), ',') . ', offen ' . rtrim(rtrim(number_format($c['offen_charge'], 3, ',', '.'), '0'), ',') . ' ' . (string)$c['einheit'] : '';
        $out[] = $c;
    }
    usort($out, fn($a, $b) => $b['punkte'] <=> $a['punkte']);
    return array_slice($out, 0, $limit);
}

// Anzahl Positionen einer Bestellung, für die es einen Vorschlag gibt (Datenprüfung).
function bestellung_abgleich_treffer(int $bestellung_id): int {
    $n = 0;
    foreach (all("SELECT id FROM bestellung_position WHERE bestellung_id=? AND menge>0", [$bestellung_id]) as $p)
        if (bestellung_abgleich_vorschlaege((int)$p['id'], 1)) $n++;
    return $n;
}

// Block auf der Bestellseite: je Position ohne (volle) Charge die Vorschläge mit „verknüpfen". Plus „als geliefert schließen"
// (Ware kam, ist aber nicht einzeln eingebucht) und „stornieren". Erscheint nur, wenn es offene/unverknüpfte Positionen gibt.
function bestellung_abgleich_panel(array $b): string {
    if (in_array((string)$b['status'], ['storniert', 'offen'], true)) return '';
    $pos = all("SELECT bp.*, i.name AS artikel, (SELECT COUNT(*) FROM charge c WHERE c.bestellung_position_id=bp.id) AS n_chargen
                FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id WHERE bp.bestellung_id=? AND bp.menge>0 ORDER BY bp.sort, bp.id", [(int)$b['id']]);
    $offen = array_filter($pos, fn($p) => empty($p['rest_storniert_am']) && ((int)$p['n_chargen'] === 0 || (float)$p['geliefert_menge'] < (float)$p['menge'] * 0.98));
    if (!$offen) return '';
    $eingegangen = (string)$b['status'] === 'geliefert' || !empty($b['angekommen_am']);
    $mz = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
    $h = '<div class="bx-panel" id="abgleich"><h2 style="margin-top:0">Wareneingang zuordnen</h2>'
       . '<p class="muted" style="margin:0 0 12px">' . ($eingegangen ? 'Die Bestellung steht auf eingegangen, aber nicht jede Position hängt an einer eingebuchten Charge.' : 'Noch nicht (vollständig) eingegangen.')
       . ' Vorschläge aus vorhandenen Chargen (gleicher Lieferant, gleicher oder ähnlicher Artikel, passende Menge, Eingang nach dem Bestelldatum). Nichts passiert automatisch.</p>';
    foreach ($offen as $p) {
        $v = bestellung_abgleich_vorschlaege((int)$p['id']);
        $h .= '<div style="margin:0 0 14px"><div><strong style="font-weight:600">' . h((string)($p['artikel'] ?: $p['bezeichnung'])) . '</strong> <span class="muted">· bestellt ' . $mz($p['menge']) . ' ' . h((string)$p['einheit'])
            . ' · geliefert ' . $mz($p['geliefert_menge']) . ($p['n_chargen'] ? ' (' . (int)$p['n_chargen'] . ' Charge(n))' : '') . '</span></div>';
        if (!$v) { $h .= '<div class="muted" style="font-size:13px;margin-top:4px">Keine passende Charge gefunden.' . (!$eingegangen ? ' <a href="?p=wareneingang&bp=' . (int)$p['id'] . '">Wareneingang buchen</a>' : '') . '</div></div>'; continue; }
        $h .= '<div class="bx-tablewrap" style="margin-top:6px"><table class="bx-table"><thead><tr><th>Charge</th><th>Artikel</th><th class="bx-num">Menge</th><th>Eingang</th><th>Lieferant</th><th>Warum</th><th></th></tr></thead><tbody>';
        foreach ($v as $c)
            $h .= '<tr><td><a href="?p=chargen&id=' . (int)$c['id'] . '">' . h((string)($c['charge_nr'] ?: '#' . $c['id'])) . '</a></td><td>' . h((string)$c['artikel']) . '</td>'
                . '<td class="bx-num">' . $mz($c['menge']) . ' ' . h((string)$c['einheit']) . '</td><td>' . ($c['wareneingang'] ? h(date('d.m.Y', strtotime((string)$c['wareneingang']))) : '–') . '</td>'
                . '<td>' . h((string)($c['lieferant'] ?? '–')) . '</td><td class="muted" style="font-size:12px">' . h(implode(', ', $c['gruende'])) . ($c['abweichung'] !== '' ? '<div style="color:#8a6d1f">' . h($c['abweichung']) . '</div>' : '') . '</td>'
                . '<td style="text-align:right;white-space:nowrap"><form method="post" style="display:inline"><input type="hidden" name="aktion" value="abgl_verknuepfen"><input type="hidden" name="bp_id" value="' . (int)$p['id'] . '"><input type="hidden" name="charge_id" value="' . (int)$c['id'] . '"><button class="btn btn-primary btn-sm" type="submit">verknüpfen</button></form>'
                . (function_exists('has_role') && has_role('admin') ? ' <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById(\'dlgKV' . (int)$p['id'] . '_' . (int)$c['id'] . '\').showModal()">Menge korrigieren und verknüpfen</button>'
                    . charge_korr_dialog($c, 'dlgKV' . (int)$p['id'] . '_' . (int)$c['id'], '<input type="hidden" name="aktion" value="abgl_korr_verknuepfen"><input type="hidden" name="bp_id" value="' . (int)$p['id'] . '"><input type="hidden" name="charge_id" value="' . (int)$c['id'] . '">',
                                         (float)$c['offen_charge'], 'ursprung', 'Menge korrigieren und verknüpfen', 'Korrigieren und verknüpfen') : '')
                . '</td></tr>';
        $h .= '</tbody></table></div></div>';
    }
    $h .= '<div class="bx-row" style="gap:8px;flex-wrap:wrap">'
        . '<form method="post" style="display:inline" onsubmit="return confirm(\'Bestellung als geliefert schließen? Offene Positionen zählen nicht mehr als unterwegs. Es wird keine Charge angelegt.\');"><input type="hidden" name="aktion" value="abgl_geliefert"><button class="btn btn-ghost btn-sm" type="submit" title="Ware ist gekommen, aber nicht einzeln eingebucht">als geliefert schließen</button></form>';
    if (!$eingegangen) $h .= '<form method="post" style="display:inline" onsubmit="return confirm(\'Bestellung stornieren?\');"><input type="hidden" name="aktion" value="storno"><button class="btn btn-ghost btn-sm" type="submit" style="color:#8f231b">stornieren</button></form>';
    $h .= '</div></div>';
    return $h;
}

// ---- Datenprüfung: alles in der Zeile (Nico 11.11.2026) ------------------------------------------------------------
// Offene Position (Alias bp): Menge > 0, nicht Rest-storniert, noch nicht (fast) voll geliefert, keine Charge verknüpft.
// Je Position, nicht je Bestellung: eine Bündel-Bestellung bleibt in der Datenprüfung, bis jede Position erledigt ist (BE-2888, 11.11.2026).
// Block B Teil 4 (11.11.2026): Eine Position bleibt offen, solange weniger als 98 % geliefert sind – auch wenn schon eine
// Teilmengen-Charge dranhängt (vorher fiel sie nach der ersten Charge ganz raus, Fable 18.4.1).
if (!defined('BA_POS_OFFEN')) define('BA_POS_OFFEN', "bp.menge>0 AND bp.rest_storniert_am IS NULL AND COALESCE(bp.geliefert_menge,0) < bp.menge*0.98");
function bestellung_abgleich_offen_anzahl(int $bestellung_id): int {
    return (int) scalar("SELECT COUNT(*) FROM bestellung_position bp WHERE bp.bestellung_id=? AND " . BA_POS_OFFEN, [$bestellung_id]);
}
// Offene Positionen einer Bestellung mit ihren Vorschlägen (für die Datenprüfungs-Zeile).
function bestellung_abgleich_positionen(int $bestellung_id): array {
    $out = [];
    foreach (all("SELECT bp.id, bp.menge, bp.einheit, COALESCE(bp.geliefert_menge,0) AS geliefert, bp.bezeichnung, i.name AS artikel
                  FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id
                  WHERE bp.bestellung_id=? AND " . BA_POS_OFFEN . " ORDER BY bp.sort, bp.id", [$bestellung_id]) as $p) {
        $p['vorschlaege'] = bestellung_abgleich_vorschlaege((int)$p['id'], 5);
        $out[] = $p;
    }
    return $out;
}
// Charge in mehreren Bestellungen vorgeschlagen? Aus den Zeilen [ ['nummer'=>…, '_pos'=>[…]], … ] -> [charge_id => [Nummern]].
function bestellung_abgleich_konflikte(array $zeilen): array {
    $m = [];
    foreach ($zeilen as $r) foreach (($r['_pos'] ?? []) as $p) foreach ($p['vorschlaege'] as $v) $m[(int)$v['id']][(string)$r['nummer']] = true;
    $out = [];
    foreach ($m as $cid => $nrs) if (count($nrs) > 1) $out[$cid] = array_keys($nrs);
    return $out;
}
// Eindeutiger Vorschlag einer Position: genau einer, gleicher Artikel, Menge passt, kein Konflikt. Sonst null.
function bestellung_abgleich_eindeutig(array $p, array $konflikte): ?array {
    if (count($p['vorschlaege']) !== 1) return null;
    $v = $p['vorschlaege'][0];
    if (!in_array('gleicher Artikel', $v['gruende'], true) || !in_array('Menge passt', $v['gruende'], true)) return null;
    return isset($konflikte[(int)$v['id']]) ? null : $v;
}
// Zelle „Vorschläge" der Datenprüfung: je Position Artikel, bestellt/geliefert und je Vorschlag Charge, Menge, Eingang, Grund + „verknüpfen".
function bestellung_abgleich_zelle(array $r, array $konflikte): string {
    $mz = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
    $h = ''; $bid = (int)$r['_id']; $nr = (string)$r['nummer'];
    foreach (($r['_pos'] ?? []) as $p) {
        $v = $p['vorschlaege'];
        $h .= '<div data-dp-pos="' . (int)$p['id'] . '" style="margin:0 0 6px"><div style="font-size:13px"><strong style="font-weight:600">' . h((string)($p['artikel'] ?: $p['bezeichnung'])) . '</strong> <span class="muted">· bestellt ' . $mz($p['menge']) . ' ' . h((string)$p['einheit']) . ' · geliefert ' . $mz($p['geliefert']) . '</span></div>';
        if (!$v) { $h .= '<div class="muted" style="font-size:12px">keine passende Charge</div></div>'; continue; }
        $gleicheMenge = count($v) > 1 && count(array_unique(array_map(fn($c) => (string)round((float)$c['menge_bp'], 3), $v))) < count($v);
        if ($gleicheMenge) $h .= '<div style="font-size:12px;color:#8a6d1f">mehrere passende Chargen - bitte wählen</div>';
        $zeilen = '';
        foreach ($v as $c) {
            $konf = array_values(array_diff($konflikte[(int)$c['id']] ?? [], [$nr]));
            $zeilen .= '<div class="bx-row" style="gap:8px;align-items:center;flex-wrap:wrap;font-size:12px;margin:3px 0">'
                . '<a href="?p=chargen&id=' . (int)$c['id'] . '" target="_blank" rel="noopener">' . h((string)($c['charge_nr'] ?: '#' . $c['id'])) . '</a>'
                . '<span>' . $mz($c['menge']) . ' ' . h((string)$c['einheit']) . '</span>'
                . '<span class="muted">' . ($c['wareneingang'] ? 'Eingang ' . h(date('d.m.Y', strtotime((string)$c['wareneingang']))) : '') . '</span>'
                . '<span class="muted">' . h(implode(', ', $c['gruende'])) . '</span>'
                . ($c['abweichung'] !== '' ? '<span style="color:#8a6d1f">' . h($c['abweichung']) . '</span>' : '')
                . ($konf ? '<span style="color:#8f231b">Charge auch bei ' . h(implode(', ', $konf)) . ' vorgeschlagen</span>' : '')
                . '<button type="button" class="btn btn-primary btn-sm" data-dpabgl="verknuepfen" data-bestellung="' . $bid . '" data-bp="' . (int)$p['id'] . '" data-charge="' . (int)$c['id'] . '">verknüpfen</button>'
                . '<button type="button" class="btn btn-ghost btn-sm" data-dpkorr="1" data-bestellung="' . $bid . '" data-bp="' . (int)$p['id'] . '" data-charge="' . (int)$c['id'] . '" data-chargenr="' . h((string)($c['charge_nr'] ?: '#' . $c['id'])) . '"'
                . ' data-menge="' . h(rtrim(rtrim(number_format((float)$c['menge'], 3, ',', '.'), '0'), ',')) . '" data-offen="' . h(rtrim(rtrim(number_format((float)$c['offen_charge'], 3, ',', '.'), '0'), ',')) . '" data-einheit="' . h((string)$c['einheit']) . '">Menge korrigieren und verknüpfen</button></div>';
        }
        $h .= count($v) > 1 ? '<details' . ($gleicheMenge ? '' : ' open') . '><summary style="cursor:pointer;font-size:12px">' . count($v) . ' Vorschläge</summary>' . $zeilen . '</details>' : $zeilen;
        $h .= '</div>';
    }
    return $h !== '' ? $h : '<span class="muted">–</span>';
}
// Knöpfe je Bestellung in der Datenprüfung.
function bestellung_abgleich_knoepfe(array $r): string {
    $bid = (int)$r['_id'];
    $b = one("SELECT status, angekommen_am FROM bestellung WHERE id=?", [$bid]);
    $eingegangen = $b && ((string)$b['status'] === 'geliefert' || !empty($b['angekommen_am']));
    return '<div style="display:flex;flex-direction:column;gap:4px;align-items:flex-end">'
        . '<button type="button" class="btn btn-ghost btn-sm" data-dpabgl="geliefert" data-bestellung="' . $bid . '" data-frage="Als geliefert schließen? Offene Positionen zählen nicht mehr als unterwegs, es wird keine Charge angelegt.">als geliefert schließen</button>'
        . '<button type="button" class="btn btn-ghost btn-sm" data-dpabgl="rest" data-bestellung="' . $bid . '" data-frage="Rest stornieren? Die noch offene Menge zählt nicht mehr als unterwegs.">Rest storniert</button>'
        . (!$eingegangen ? '<button type="button" class="btn btn-ghost btn-sm" style="color:#8f231b" data-dpabgl="storno" data-bestellung="' . $bid . '" data-frage="Bestellung wirklich stornieren?">stornieren</button>' : '')
        . '</div>';
}
// Aktion ausführen (gleiche Funktionen wie auf der Bestellseite). Rückgabe ['ok'=>bool, 'msg'=>string].
function bestellung_abgleich_aktion(string $aktion, int $bestellung_id, int $bp_id = 0, int $charge_id = 0): array {
    require_once __DIR__ . '/wareneingang_bestellung.php';
    $wer = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? 'Team') : 'Team';
    $nr = (string) scalar("SELECT nummer FROM bestellung WHERE id=?", [$bestellung_id]);
    if ($nr === '') return ['ok' => false, 'msg' => 'Bestellung nicht gefunden.'];
    if ($aktion === 'verknuepfen') {
        $ok = (int) scalar("SELECT COUNT(*) FROM bestellung_position WHERE id=? AND bestellung_id=?", [$bp_id, $bestellung_id]) === 1
           && (int) scalar("SELECT COUNT(*) FROM charge WHERE id=? AND bestellung_position_id IS NULL", [$charge_id]) === 1;
        if (!$ok) return ['ok' => false, 'msg' => 'Charge oder Position nicht (mehr) passend - nichts geändert.'];
        webe_charge_verknuepfen($charge_id, $bp_id, false);
        aenderung_protokollieren('charge', $charge_id, ['bestellung_position_id' => null], ['bestellung_position_id' => $bp_id], 'datenpruefung');
        $cnr = (string) scalar("SELECT COALESCE(charge_nr, CONCAT('#', id)) FROM charge WHERE id=?", [$charge_id]);
        log_aktivitaet('bestellung', $bestellung_id, 'team', 'Charge ' . $cnr . ' mit der Bestellung verknüpft (Datenprüfung).', 'status');
        $offen = bestellung_abgleich_offen_anzahl($bestellung_id);
        $pr = one("SELECT menge, COALESCE(geliefert_menge,0) AS g, einheit FROM bestellung_position WHERE id=?", [$bp_id]);
        $posRest = $pr ? max(0.0, (float)$pr['menge'] - (float)$pr['g']) : 0.0;
        $posVoll = !$pr || (float)$pr['g'] >= (float)$pr['menge'] * 0.98;
        return ['ok' => true, 'offen' => $offen, 'pos_voll' => $posVoll, 'charge' => $charge_id,
                'msg' => $nr . ': Charge ' . $cnr . ' verknüpft.' . (!$posVoll ? ' Position noch offen: ' . rtrim(rtrim(number_format($posRest, 3, ',', '.'), '0'), ',') . ' ' . $pr['einheit'] . '.' : '') . ($offen ? ' Noch ' . $offen . ' Position(en) offen.' : '')];
    }
    // Block B (11.11.2026): erst Menge der Charge korrigieren (Pop-up wie auf der Chargenseite), dann verknüpfen.
    if ($aktion === 'korr_verknuepfen') {
        if (!(function_exists('has_role') && has_role('admin'))) return ['ok' => false, 'msg' => 'Menge korrigieren darf nur ein Admin.'];
        $ok = (int) scalar("SELECT COUNT(*) FROM bestellung_position WHERE id=? AND bestellung_id=?", [$bp_id, $bestellung_id]) === 1
           && (int) scalar("SELECT COUNT(*) FROM charge WHERE id=? AND bestellung_position_id IS NULL", [$charge_id]) === 1;
        if (!$ok) return ['ok' => false, 'msg' => 'Charge oder Position nicht (mehr) passend - nichts geändert.'];
        $k = charge_menge_korrigieren($charge_id, (string)($_POST['korr_was'] ?? 'ursprung'), (string)($_POST['korr_menge'] ?? ''),
                                      (string)($_POST['korr_grund'] ?? ''), (string)($_POST['korr_notiz'] ?? ''), $wer, charge_korr_beleg_speichern($_FILES['korr_beleg'] ?? [], $charge_id));
        if (!$k['ok'] && !str_starts_with((string)$k['meldung'], 'Keine Änderung')) return ['ok' => false, 'msg' => $k['meldung']];
        $r = bestellung_abgleich_aktion('verknuepfen', $bestellung_id, $bp_id, $charge_id);
        if ($r['ok']) $r['msg'] = ($k['ok'] ? $k['meldung'] . ' ' : '') . $r['msg'];
        return $r;
    }
    if ($aktion === 'loesen') {
        if (!(function_exists('has_role') && has_role('admin'))) return ['ok' => false, 'msg' => 'Verknüpfung lösen darf nur ein Admin.'];
        if ((int) scalar("SELECT COUNT(*) FROM charge c JOIN bestellung_position bp ON bp.id=c.bestellung_position_id WHERE c.id=? AND bp.bestellung_id=?", [$charge_id, $bestellung_id]) !== 1)
            return ['ok' => false, 'msg' => 'Diese Charge hängt nicht (mehr) an der Bestellung.'];
        $r = webe_charge_loesen($charge_id, $wer);
        return ['ok' => $r['ok'], 'msg' => $nr . ': ' . $r['msg'], 'offen' => bestellung_abgleich_offen_anzahl($bestellung_id)];
    }
    if ($aktion === 'geliefert') {
        webe_als_geliefert_schliessen($bestellung_id, $wer);
        log_aktivitaet('bestellung', $bestellung_id, 'team', 'Als geliefert geschlossen ohne eingebuchte Charge (Datenprüfung).', 'status');
        return ['ok' => true, 'msg' => $nr . ' als geliefert geschlossen.'];
    }
    if ($aktion === 'rest') {
        webe_rest_stornieren($bestellung_id, $wer);
        log_aktivitaet('bestellung', $bestellung_id, 'team', 'Rest storniert (Datenprüfung).', 'status');
        return ['ok' => true, 'msg' => $nr . ': Rest storniert.'];
    }
    if ($aktion === 'storno') {
        $r = bestellung_stornieren($bestellung_id, $wer, 'Datenprüfung (Altlasten)');
        return ['ok' => !empty($r['ok']), 'msg' => $nr . ': ' . (string)($r['msg'] ?? '')];
    }
    return ['ok' => false, 'msg' => 'Unbekannte Aktion.'];
}
// Inhalt einer Bestellung für die Datenprüfung (Nico 11.11.2026: „kp was da versendet wurde"): je Position Artikel/Produkt mit Menge,
// dazu Auftrag (verlinkt) und Kunde. Die ersten zwei sichtbar, der Rest aufklappbar („… und 7 weitere").
function bestellung_abgleich_inhalt(int $bestellung_id): string {
    $mz = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
    $pos = all("SELECT COALESCE(NULLIF(i.name,''), bp.bezeichnung) AS name, bp.menge, bp.einheit, bp.auftrag_id, a.nummer AS anr, k.firma
                FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id LEFT JOIN auftrag a ON a.id=bp.auftrag_id LEFT JOIN kunden k ON k.id=a.kunde_id
                WHERE bp.bestellung_id=? AND bp.menge>0 ORDER BY bp.sort, bp.id", [$bestellung_id]);
    if (!$pos) return '<span class="muted">keine Positionen</span>';
    $zeile = fn($p) => '<div style="font-size:13px">' . h((string)$p['name']) . ' <span class="muted">· ' . $mz($p['menge']) . ' ' . h((string)$p['einheit']) . '</span>'
        . (!empty($p['auftrag_id']) ? ' <span class="muted" style="font-size:12px">· <a href="?p=auftrag&id=' . (int)$p['auftrag_id'] . '" target="_blank" rel="noopener">' . h((string)($p['anr'] ?: '#' . $p['auftrag_id'])) . '</a>' . ($p['firma'] ? ' ' . h((string)$p['firma']) : '') . '</span>' : '') . '</div>';
    $h = implode('', array_map($zeile, array_slice($pos, 0, 2)));
    if (count($pos) > 2) $h .= '<details><summary style="cursor:pointer;font-size:12px" class="muted">… und ' . (count($pos) - 2) . ' weitere</summary>' . implode('', array_map($zeile, array_slice($pos, 2))) . '</details>';
    return $h;
}

