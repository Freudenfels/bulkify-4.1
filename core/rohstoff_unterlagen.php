<?php
// Unterlagen der Rohstoffe (Spezifikation/CoA/Analyse des Lieferanten) für eine Anfrage bzw. Rezeptur sichtbar machen
// (Nico 10.11.2026: „ein Mitarbeiter soll das sehen, wenn er eine Anfrage bekommt, und gezielt abarbeiten“).
// Dashboard-Datei (braucht core/schema.php + core/anfrage_ui.php für das Anfrage-Popup). Die bulkify-Spezifikation zählt
// NICHT als Unterlage – sie wird nur erzeugt.

require_once __DIR__ . '/preis_gueltig.php';   // „gültig bis" im Pop-up

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
    $zurueck = '?' . (string)($_SERVER['QUERY_STRING'] ?? '');
    $meld = rohstoff_erfassen_meldung();
    if (!$fehlt) return $meld . '<div class="bx-panel" style="padding:10px 14px;border-left:3px solid var(--gruen,#1D9E75)"><strong style="font-weight:600">Unterlagen:</strong> alle ' . count($st) . ' Rohstoffe haben Spezifikation oder CoA.</div>';
    usort($fehlt, fn($a, $b) => [$a['anfragen'] > 0, $a['name']] <=> [$b['anfragen'] > 0, $b['name']]);   // noch nie angefragt zuerst
    $h = $meld . '<div class="bx-panel" id="rsUnterlagen" style="border-left:3px solid var(--warn,#C9821A)">'
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
            . '<td style="text-align:right">' . rohstoff_erfassen_knopf((int)$r['id'], $r['name']) . '</td></tr>';
    }
    $h .= '</tbody></table></div>'
        . '<div style="margin-top:10px"><button type="button" class="btn btn-primary btn-sm" onclick="(function(){var ids=[].map.call(document.querySelectorAll(\'.rsUnterlagenWahl:checked\'),function(c){return parseInt(c.value,10);});'
        . 'if(!ids.length){alert(\'Bitte mindestens einen Rohstoff anhaken.\');return;}if(typeof bxAnfrageBulkOeffnen!==\'function\'){alert(\'Anfrage-Fenster fehlt auf dieser Seite.\');return;}'
        . 'bxAnfrageBulkOeffnen(ids, ids.length+\' Rohstoff(e) – bei welchen Lieferanten anfragen? (Preis je kg, CoA/Spezifikation)\');if(typeof bxAnfrageModus===\'function\')bxAnfrageModus(\'preis\');})()">Ausgewählte beim Lieferanten anfragen</button></div>'
        . '</div>' . rohstoff_erfassen_dialog($zurueck);
    return $h;
}

// --- Pop-up „Preis & Unterlagen“ (Nico 10.11.2026) ------------------------------------------------------------------
// Direkt aus einer Liste heraus: Lieferantenpreis ab Menge eintragen und/oder CoA/Spezifikation hochladen. Die KI liest die
// Unterlage aus und füllt Kennwerte, Grenzwerte, Wirkstoffe (für die NRV) und leere Stammdaten. Ziel: ?p=rohstoff_erfassen
// (module/anfrage/rohstoff_erfassen.php), danach zurück auf die aufrufende Seite. Dialog einmal je Seite ausgeben.
function rohstoff_erfassen_knopf(int $item_id, string $name, string $label = 'Preis / Unterlage'): string {
    return '<button type="button" class="btn btn-ghost btn-sm" style="white-space:nowrap" onclick="bxRohErfassen(' . $item_id . ',' . h(json_encode($name, JSON_UNESCAPED_UNICODE)) . ')">' . h($label) . '</button>';
}
function rohstoff_erfassen_dialog(string $zurueck): string {
    static $done = false; if ($done) return ''; $done = true;
    $o = '<option value="">– Lieferant wählen –</option>';
    foreach (all("SELECT id, firma, land FROM lieferanten WHERE gesperrt=0 ORDER BY firma") as $l)
        $o .= '<option value="' . (int)$l['id'] . '">' . h((string)$l['firma']) . ($l['land'] ? ' · ' . h((string)$l['land']) : '') . '</option>';
    $ki = function_exists('ki_bereit') && ki_bereit();
    return '<div id="bxRohErfassen" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9998;align-items:center;justify-content:center;padding:16px" onclick="if(event.target===this)this.style.display=\'none\'">'
      . '<form method="post" action="?p=rohstoff_erfassen" enctype="multipart/form-data" class="bx-panel" style="max-width:560px;width:100%;max-height:92vh;overflow:auto;margin:0">'
      . '<input type="hidden" name="item_id" value=""><input type="hidden" name="zurueck" value="' . h($zurueck) . '">'
      . '<div class="bx-row" style="justify-content:space-between;align-items:baseline;gap:10px"><h2 style="margin:0">Preis &amp; Unterlagen</h2>'
      . '<button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById(\'bxRohErfassen\').style.display=\'none\'">Schließen</button></div>'
      . '<div class="muted" id="bxRohErfassenName" style="margin:4px 0 14px"></div>'
      . '<div class="bx-field"><label>Lieferant</label><select name="lieferant_id" class="rscombo" style="min-width:300px">' . $o . '</select></div>'
      . '<div class="bx-grid">'
      . '<div class="bx-field"><label>Preis je kg (EUR, netto)</label><input type="text" inputmode="decimal" name="preis" placeholder="z. B. 24,50"></div>'
      . '<div class="bx-field"><label>ab Menge (kg)</label><input type="text" inputmode="decimal" name="menge_ab" placeholder="z. B. 25"></div>'
      . '</div>'
      . (function_exists('pg_eingabe') ? '<div style="margin:0 0 12px">' . pg_eingabe('gueltig_bis', null) . '</div>' : '')
      . '<div class="muted" style="font-size:12px;margin:-4px 0 14px">Weitere Staffel: Fenster erneut öffnen und die nächste Menge eintragen. Der EK des Rohstoffs folgt dem günstigsten Lieferantenpreis.</div>'
      . '<div class="bx-grid">'
      . '<div class="bx-field"><label>Unterlage</label><input type="file" name="dok" accept=".pdf,.jpg,.jpeg,.png"></div>'
      . '<div class="bx-field"><label>Art</label><select name="dok_typ"><option value="spec">Spezifikation</option><option value="coa">CoA (Analysenzertifikat)</option><option value="analyse">Laboranalyse</option></select></div>'
      . '</div>'
      . ($ki ? '<label style="display:flex;gap:8px;align-items:center;margin:4px 0 14px;font-weight:400"><input type="checkbox" name="ki_fuellen" value="1" checked> Infos automatisch aus der Unterlage füllen (Kennwerte, Grenzwerte, Wirkstoffe für die NRV, leere Stammdaten)</label>'
             : '<div class="muted" style="font-size:12px;margin:4px 0 14px">KI ist nicht eingerichtet – die Unterlage wird nur abgelegt.</div>')
      . '<button class="btn btn-primary" type="submit">Speichern</button>'
      . '</form></div>'
      . '<script>function bxRohErfassen(id,name){var d=document.getElementById("bxRohErfassen");if(!d)return;var f=d.querySelector("form");'
      . 'f.preis.value="";f.menge_ab.value="";if(f.gueltig_bis)f.gueltig_bis.value=(arguments[2]||"");f.dok.value="";f.item_id.value=id;document.getElementById("bxRohErfassenName").textContent=name;d.style.display="flex";}</script>';
}
// Rückmeldung nach dem Speichern (auf der aufrufenden Seite oben ausgeben).
function rohstoff_erfassen_meldung(): string {
    if (!isset($_GET['re_ok'])) return '';
    $t = []; $warn = false;
    if (!empty($_GET['re_preis'])) $t[] = 'Preis gespeichert';
    if (!empty($_GET['re_ohnelief'])) { $t[] = 'Preis NICHT gespeichert – bitte einen Lieferanten wählen'; $warn = true; }
    if (!empty($_GET['re_dok'])) $t[] = 'Unterlage abgelegt';
    if (!empty($_GET['re_dokfehler'])) { $t[] = 'Datei konnte nicht gespeichert werden'; $warn = true; }
    if (!empty($_GET['re_ki'])) $t[] = 'KI hat ausgelesen: Wirkstoffe, Kennwerte und Grenzwerte ergänzt, ' . (int)($_GET['re_kin'] ?? 0) . ' leere Stammdatenfeld(er) gefüllt';
    if (!empty($_GET['re_kioff'])) { $t[] = 'KI konnte die Datei nicht auslesen – bitte am Rohstoff prüfen'; $warn = true; }
    $id = (int)$_GET['re_ok'];
    $name = $id ? (string) scalar("SELECT name FROM item WHERE id=?", [$id]) : '';
    return '<div class="bx-panel ' . ($warn ? 'badge-warn' : 'badge-ok') . '" style="padding:10px 14px">' . ($name !== '' ? h($name) . ': ' : '')
         . ($t ? h(implode(' · ', $t)) : 'nichts geändert (weder Preis noch Datei angegeben)') . '.'
         . ($id ? ' <a href="?p=rohstoff&id=' . $id . '&tab=spec" target="_blank" rel="noopener">Rohstoff ansehen</a>' : '') . '</div>';
}
