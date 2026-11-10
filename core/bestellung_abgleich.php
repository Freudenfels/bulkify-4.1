<?php
// Altlasten Einkauf (Einkauf gerade ziehen, Teil 4, 10.11.2026): Bestellungen ohne Wareneingang mit vorhandenen Chargen
// abgleichen. Nur auf Klick: „verknüpfen", „als geliefert schließen", „stornieren". Dashboard-Datei (braucht core/schema.php).
require_once __DIR__ . '/artikel_dubletten.php';

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
                . '<td>' . h((string)($c['lieferant'] ?? '–')) . '</td><td class="muted" style="font-size:12px">' . h(implode(', ', $c['gruende'])) . '</td>'
                . '<td style="text-align:right"><form method="post" style="display:inline"><input type="hidden" name="aktion" value="abgl_verknuepfen"><input type="hidden" name="bp_id" value="' . (int)$p['id'] . '"><input type="hidden" name="charge_id" value="' . (int)$c['id'] . '"><button class="btn btn-primary btn-sm" type="submit">verknüpfen</button></form></td></tr>';
        $h .= '</tbody></table></div></div>';
    }
    $h .= '<div class="bx-row" style="gap:8px;flex-wrap:wrap">'
        . '<form method="post" style="display:inline" onsubmit="return confirm(\'Bestellung als geliefert schließen? Offene Positionen zählen nicht mehr als unterwegs. Es wird keine Charge angelegt.\');"><input type="hidden" name="aktion" value="abgl_geliefert"><button class="btn btn-ghost btn-sm" type="submit" title="Ware ist gekommen, aber nicht einzeln eingebucht">als geliefert schließen</button></form>';
    if (!$eingegangen) $h .= '<form method="post" style="display:inline" onsubmit="return confirm(\'Bestellung stornieren?\');"><input type="hidden" name="aktion" value="storno"><button class="btn btn-ghost btn-sm" type="submit" style="color:#8f231b">stornieren</button></form>';
    $h .= '</div></div>';
    return $h;
}
