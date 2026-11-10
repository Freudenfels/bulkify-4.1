<?php
// Unterlagen der Rohstoffe (Spezifikation/CoA/Analyse des Lieferanten) für eine Anfrage bzw. Rezeptur sichtbar machen
// (Nico 10.11.2026: „ein Mitarbeiter soll das sehen, wenn er eine Anfrage bekommt, und gezielt abarbeiten“).
// Dashboard-Datei (braucht core/schema.php + core/anfrage_ui.php für das Anfrage-Popup). Die bulkify-Spezifikation zählt
// NICHT als Unterlage – sie wird nur erzeugt.

// Stand je Rohstoff: dok (Lieferanten-Unterlage vorhanden), anfragen, beantwortet, offen, letzte, lieferanten.
function rohstoff_unterlagen_stand(array $item_ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $item_ids))));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $out = [];
    foreach (all("SELECT id, name, artikelnummer FROM item WHERE id IN ($in) AND kategorie='rohstoff' AND COALESCE(form,'') NOT IN ('kapselhuelle','premix')") as $i)
        $out[(int)$i['id']] = ['id' => (int)$i['id'], 'name' => (string)$i['name'], 'nr' => (string)($i['artikelnummer'] ?? ''), 'dok' => false,
                               'anfragen' => 0, 'beantwortet' => 0, 'letzte' => '', 'lieferanten' => ''];
    if (!$out) return [];
    $in = implode(',', array_keys($out));
    foreach (all("SELECT DISTINCT objekt_id FROM dokument WHERE objekt_typ='item' AND objekt_id IN ($in) AND typ IN ('spec','coa','beides','analyse')") as $d)
        $out[(int)$d['objekt_id']]['dok'] = true;
    foreach (all("SELECT la.item_id, COUNT(DISTINCT la.id) AS n, COUNT(DISTINCT ag.id) AS b, DATE(MAX(la.angelegt)) AS letzte,
                         GROUP_CONCAT(DISTINCT l.firma ORDER BY l.firma SEPARATOR ', ') AS lief
                  FROM lieferant_anfrage la LEFT JOIN lieferant_angebot ag ON ag.anfrage_id=la.id LEFT JOIN lieferanten l ON l.id=la.lieferant_id
                  WHERE la.item_id IN ($in) AND la.status NOT IN ('zurueckgezogen') GROUP BY la.item_id") as $a) {
        $o = &$out[(int)$a['item_id']];
        $o['anfragen'] = (int)$a['n']; $o['beantwortet'] = (int)$a['b']; $o['letzte'] = (string)$a['letzte']; $o['lieferanten'] = (string)($a['lief'] ?? '');
        unset($o);
    }
    return $out;
}

// Rohstoffe einer Rezepturanfrage: zugeordnete Wunschzeilen + Zutaten der daraus erstellten Rezeptur.
function rezeptur_anfrage_rohstoff_ids(int $anfrage_id, array $wunsch_item_ids = []): array {
    $ids = array_map('intval', $wunsch_item_ids);
    foreach (all("SELECT item_id FROM rezeptur_anfrage_wunsch WHERE anfrage_id=? AND item_id IS NOT NULL", [$anfrage_id]) as $r) $ids[] = (int)$r['item_id'];
    $rid = (int) scalar("SELECT rezeptur_id FROM rezeptur_anfrage WHERE id=?", [$anfrage_id]);
    if ($rid > 0) foreach (all("SELECT item_id FROM rezeptur_zutat WHERE rezeptur_id=? AND item_id IS NOT NULL", [$rid]) as $r) $ids[] = (int)$r['item_id'];
    return array_values(array_unique(array_filter($ids)));
}

// Anzahl fehlender Unterlagen je Anfrage für die Anfragen-Liste (eine Abfrage-Runde für alle).
// $rez: [anfrage_id => true] (rezeptur_anfrage), $portal: [portal_anfrage_id => ['rohstoff_id'=>?, 'rezeptur_id'=>?]].
function anfragen_unterlagen_fehlen(array $rez, array $portal): array {
    $je = ['rezeptur' => [], 'portal' => []]; $alle = [];
    if ($rez) {
        $in = implode(',', array_map('intval', array_keys($rez)));
        foreach (all("SELECT anfrage_id, item_id FROM rezeptur_anfrage_wunsch WHERE anfrage_id IN ($in) AND item_id IS NOT NULL
                      UNION SELECT a.id, z.item_id FROM rezeptur_anfrage a JOIN rezeptur_zutat z ON z.rezeptur_id=a.rezeptur_id WHERE a.id IN ($in) AND z.item_id IS NOT NULL") as $r) {
            $je['rezeptur'][(int)$r['anfrage_id']][(int)$r['item_id']] = true; $alle[] = (int)$r['item_id'];
        }
    }
    foreach ($portal as $pid => $p) {
        if (!empty($p['rohstoff_id'])) { $je['portal'][(int)$pid][(int)$p['rohstoff_id']] = true; $alle[] = (int)$p['rohstoff_id']; }
        if (!empty($p['rezeptur_id'])) foreach (all("SELECT item_id FROM rezeptur_zutat WHERE rezeptur_id=? AND item_id IS NOT NULL", [(int)$p['rezeptur_id']]) as $r) {
            $je['portal'][(int)$pid][(int)$r['item_id']] = true; $alle[] = (int)$r['item_id'];
        }
    }
    $st = rohstoff_unterlagen_stand($alle);
    $out = ['rezeptur' => [], 'portal' => []];
    foreach ($je as $art => $map) foreach ($map as $aid => $items) {
        $f = 0; $angefragt = 0;
        foreach (array_keys($items) as $iid) if (isset($st[$iid]) && !$st[$iid]['dok']) { $f++; if ($st[$iid]['anfragen'] > 0) $angefragt++; }
        $out[$art][$aid] = ['fehlen' => $f, 'angefragt' => $angefragt];
    }
    return $out;
}

// Kasten „Unterlagen der Rohstoffe“: Rohstoffe ohne Spezifikation/CoA mit Anfrage-Stand, Häkchen + Sammel-Anfrage beim
// Lieferanten (Preis je kg + CoA/Spezifikation) über das Anfrage-Popup der Seite (anfrage_modal muss eingebunden sein).
function rohstoff_unterlagen_panel(array $item_ids, string $kontext = 'diese Anfrage'): string {
    $st = rohstoff_unterlagen_stand($item_ids);
    if (!$st) return '';
    $fehlt = array_values(array_filter($st, fn($r) => !$r['dok']));
    $ok = count($st) - count($fehlt);
    if (!$fehlt) return '<div class="bx-panel" style="padding:10px 14px;border-left:3px solid var(--gruen,#1D9E75)"><strong style="font-weight:600">Unterlagen:</strong> alle ' . count($st) . ' Rohstoffe haben Spezifikation oder CoA.</div>';
    usort($fehlt, fn($a, $b) => [$a['anfragen'] > 0, $a['name']] <=> [$b['anfragen'] > 0, $b['name']]);   // noch nie angefragt zuerst
    $h = '<div class="bx-panel" id="rsUnterlagen" style="border-left:3px solid var(--warn,#C9821A)">'
       . '<div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px"><h2 style="margin:0">Unterlagen der Rohstoffe ' . bx_badge(count($fehlt) . ' ohne Spezifikation/CoA', 'warn') . '</h2>'
       . '<span class="muted" style="font-size:13px">' . $ok . ' von ' . count($st) . ' mit Unterlagen</span></div>'
       . '<p class="muted" style="margin:6px 0 10px;font-size:13px">Für ' . h($kontext) . ' fehlen bei diesen Rohstoffen Spezifikation bzw. CoA des Lieferanten. Anhaken und beim Lieferanten anfragen – Spec/CoA sind bei der Preisabgabe Pflicht und landen automatisch am Rohstoff. Die bulkify-Spezifikation zählt hier nicht.</p>'
       . '<div class="bx-tablewrap"><table class="bx-table" style="font-size:13px"><thead><tr><th style="width:28px"><input type="checkbox" title="alle" onclick="document.querySelectorAll(\'.rsUnterlagenWahl\').forEach(function(c){c.checked=this.checked;}.bind(this))"></th>'
       . '<th>Rohstoff</th><th>Beim Lieferanten angefragt</th><th></th></tr></thead><tbody>';
    foreach ($fehlt as $r) {
        $stand = $r['anfragen'] > 0
            ? bx_badge($r['beantwortet'] > 0 ? 'beantwortet, ohne Unterlage' : 'angefragt, wartet', $r['beantwortet'] > 0 ? 'err' : 'info') . ' <span class="muted">' . (int)$r['anfragen'] . '× · zuletzt ' . h(date('d.m.Y', strtotime($r['letzte']))) . ($r['lieferanten'] !== '' ? ' · ' . h($r['lieferanten']) : '') . '</span>'
            : '<span style="color:#8f231b">noch nicht angefragt</span>';
        $h .= '<tr><td><input type="checkbox" class="rsUnterlagenWahl" value="' . (int)$r['id'] . '"' . ($r['anfragen'] === 0 ? ' checked' : '') . '></td>'
            . '<td><a href="?p=rohstoff&id=' . (int)$r['id'] . '" target="_blank" rel="noopener">' . h($r['name']) . '</a>' . ($r['nr'] !== '' ? ' <span class="muted" style="font-size:11px">' . h($r['nr']) . '</span>' : '') . '</td>'
            . '<td>' . $stand . '</td>'
            . '<td style="text-align:right"><a class="btn btn-ghost btn-sm" href="?p=rohstoff&id=' . (int)$r['id'] . '" target="_blank" rel="noopener">Unterlage hochladen</a></td></tr>';
    }
    $h .= '</tbody></table></div>'
        . '<div style="margin-top:10px"><button type="button" class="btn btn-primary btn-sm" onclick="(function(){var ids=[].map.call(document.querySelectorAll(\'.rsUnterlagenWahl:checked\'),function(c){return parseInt(c.value,10);});'
        . 'if(!ids.length){alert(\'Bitte mindestens einen Rohstoff anhaken.\');return;}if(typeof bxAnfrageBulkOeffnen!==\'function\'){alert(\'Anfrage-Fenster fehlt auf dieser Seite.\');return;}'
        . 'bxAnfrageBulkOeffnen(ids, ids.length+\' Rohstoff(e) – bei welchen Lieferanten anfragen? (Preis je kg, CoA/Spezifikation)\');if(typeof bxAnfrageModus===\'function\')bxAnfrageModus(\'preis\');})()">Ausgewählte beim Lieferanten anfragen</button></div>'
        . '</div>';
    return $h;
}
