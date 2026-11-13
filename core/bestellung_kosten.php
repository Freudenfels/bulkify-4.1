<?php
// Reiter „Kosten“ an der Bestellung (Nico 13.11.2026: „Reiter mit Preisen, dass ich sehe, was die Gesamtkosten sind“).
// Warenwert je Position (bestellt + geliefert), Rechnung(en), Nebenkosten (Fracht, Zoll, Bankgebühr, Sonstiges) und daraus die
// Gesamtkosten samt Einstandspreis je Einheit (Nebenkosten nach Warenwert auf die Positionen verteilt).
// Grundlage der Gesamtkosten: Rechnungssumme in EUR, wenn erfasst, sonst Bestellwert der Positionen. Nebenkosten in EUR.
// Nebenkosten-Zeilen werden nie gelöscht, nur storniert (storniert_am).

function bk_schema(): void {
    static $ok = false; if ($ok) return; $ok = true;
    try {
        q("CREATE TABLE IF NOT EXISTS bestellung_kosten (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bestellung_id INT NOT NULL,
            art VARCHAR(30) NOT NULL DEFAULT 'sonstiges',
            betrag DECIMAL(12,2) NOT NULL DEFAULT 0,
            notiz VARCHAR(255) NULL,
            angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            angelegt_von VARCHAR(190) NULL,
            storniert_am DATETIME NULL,
            KEY (bestellung_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {}
}

function bk_arten(): array {
    return ['fracht' => 'Fracht / Versand', 'zoll' => 'Zoll / Einfuhrumsatzsteuer', 'bank' => 'Bank- / Zahlungsgebühr', 'sonstiges' => 'Sonstiges'];
}

// Einstandspreis lesbar: ab 1 € 2 Stellen, ab 1 Cent 4, darunter 6 (Leerkapseln, Etiketten)
function bk_rund(float $x): float { return round($x, $x >= 1 ? 2 : ($x >= 0.01 ? 4 : 6)); }

function bk_eur(float $x): string { return number_format($x, 2, ',', '.') . ' €'; }

function bk_daten(array $b): array {
    bk_schema();
    $id = (int)$b['id'];
    $pos = all("SELECT bp.id, bp.menge, bp.einheit, bp.ek_preis, COALESCE(bp.geliefert_menge,0) AS geliefert, bp.rest_storniert_am,
                       COALESCE(bp.pos_storno_grund,'') AS storno, COALESCE(NULLIF(bp.bezeichnung,''), i.name, '–') AS pname
                FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id WHERE bp.bestellung_id=? ORDER BY bp.sort, bp.id", [$id]);
    $ware = 0.0; $wareGel = 0.0;
    foreach ($pos as &$p) {
        $p['aktiv'] = trim((string)$p['storno']) === '';
        // Zu zahlende Menge: Rest storniert → nur das Gelieferte, sonst bestellt
        $p['menge_kosten'] = !$p['aktiv'] ? 0.0 : (!empty($p['rest_storniert_am']) ? (float)$p['geliefert'] : (float)$p['menge']);
        $p['wert'] = round($p['menge_kosten'] * (float)$p['ek_preis'], 2);
        $p['wert_gel'] = round((float)$p['geliefert'] * (float)$p['ek_preis'], 2);
        $ware += $p['wert']; $wareGel += $p['wert_gel'];
    }
    unset($p);
    $online = ($b['bestellart'] ?? '') === 'online' && ($b['online_preis'] ?? null) !== null;
    $posSumme = $ware;
    if ($online) $ware = (float)$b['online_preis'];
    $re = all("SELECT id, rechnung_nr, betrag, COALESCE(NULLIF(waehrung,''),'EUR') AS waehrung FROM dokument WHERE objekt_typ='bestellung' AND objekt_id=? AND typ='rechnung' ORDER BY id", [$id]);
    $reEur = 0.0; $reMitBetrag = 0; $reFremd = [];
    foreach ($re as $r) {
        if ($r['betrag'] === null) continue;
        if (strtoupper((string)$r['waehrung']) === 'EUR') { $reEur += (float)$r['betrag']; $reMitBetrag++; }
        else $reFremd[] = $r;
    }
    $basisRechnung = $reMitBetrag > 0 && !$reFremd;
    $nk = [];
    try { $nk = all("SELECT * FROM bestellung_kosten WHERE bestellung_id=? AND storniert_am IS NULL ORDER BY id", [$id]); } catch (\Throwable $e) {}
    $nkSumme = array_sum(array_map(fn($k) => (float)$k['betrag'], $nk));
    $basis = $basisRechnung ? $reEur : $ware;
    $gesamt = $basis + $nkSumme;
    // Einstandspreis je Einheit: Positionswert + anteilige Nebenkosten (und Rechnungsabweichung) nach Warenwert verteilt
    $faktor = $posSumme > 0 ? $gesamt / $posSumme : 0.0;
    foreach ($pos as &$p) $p['einstand'] = ($p['menge_kosten'] > 0 && $faktor > 0) ? bk_rund($p['wert'] * $faktor / $p['menge_kosten']) : null;
    unset($p);
    return ['pos' => $pos, 'ware' => $ware, 'ware_gel' => $wareGel, 'online' => $online, 're' => $re, 're_eur' => $reEur,
            're_fremd' => $reFremd, 'basis_rechnung' => $basisRechnung, 'nk' => $nk, 'nk_summe' => $nkSumme, 'gesamt' => $gesamt];
}

// POST: aktion bk_neu (art, betrag, notiz) / bk_storno (kosten_id). Rückgabe ['ok','msg'] oder null, wenn nicht zuständig.
function bk_post(array $b, string $von): ?array {
    $a = (string)($_POST['aktion'] ?? '');
    if ($a !== 'bk_neu' && $a !== 'bk_storno') return null;
    bk_schema();
    $id = (int)$b['id'];
    if ($a === 'bk_neu') {
        require_once __DIR__ . '/zahl_lesen.php';
        $art = (string)($_POST['art'] ?? 'sonstiges');
        if (!isset(bk_arten()[$art])) $art = 'sonstiges';
        $betrag = round(betrag_lesen((string)($_POST['betrag'] ?? '')), 2);
        if (abs($betrag) < 0.005) return ['ok' => false, 'msg' => 'Bitte einen Betrag angeben.'];
        $notiz = mb_substr(trim((string)($_POST['notiz'] ?? '')), 0, 255);
        q("INSERT INTO bestellung_kosten (bestellung_id, art, betrag, notiz, angelegt, angelegt_von) VALUES (?,?,?,?,?,?)",
          [$id, $art, $betrag, $notiz !== '' ? $notiz : null, gmdate('Y-m-d H:i:s'), $von]);
        if (function_exists('log_aktivitaet')) { try { log_aktivitaet('bestellung', $id, 'team', 'Nebenkosten ' . bk_arten()[$art] . ' ' . bk_eur($betrag) . ' erfasst' . ($notiz !== '' ? ' (' . $notiz . ')' : '') . '.'); } catch (\Throwable $e) {} }
        return ['ok' => true, 'msg' => bk_arten()[$art] . ' ' . bk_eur($betrag) . ' erfasst.'];
    }
    $k = one("SELECT * FROM bestellung_kosten WHERE id=? AND bestellung_id=? AND storniert_am IS NULL", [(int)($_POST['kosten_id'] ?? 0), $id]);
    if (!$k) return ['ok' => false, 'msg' => 'Kostenzeile nicht gefunden.'];
    q("UPDATE bestellung_kosten SET storniert_am=? WHERE id=?", [gmdate('Y-m-d H:i:s'), (int)$k['id']]);
    if (function_exists('log_aktivitaet')) { try { log_aktivitaet('bestellung', $id, 'team', 'Nebenkosten ' . (bk_arten()[$k['art']] ?? $k['art']) . ' ' . bk_eur((float)$k['betrag']) . ' storniert.'); } catch (\Throwable $e) {} }
    return ['ok' => true, 'msg' => 'Kostenzeile storniert.'];
}

function bk_panel_html(array $b): string {
    $d = bk_daten($b);
    $mz = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
    $o = '';
    // Hinweise oben (gelb vor Info)
    if ($d['re_fremd']) {
        $t = []; foreach ($d['re_fremd'] as $r) $t[] = number_format((float)$r['betrag'], 2, ',', '.') . ' ' . $r['waehrung'];
        $o .= '<div class="bs-hinweis" style="margin:0 0 12px"><span>Rechnung in Fremdwährung (' . h(implode(', ', $t)) . '): Gesamtkosten rechnen mit dem Bestellwert in EUR. Bankgebühr oder Kursdifferenz bitte als Nebenkosten erfassen.</span></div>';
    } elseif ($d['basis_rechnung'] && abs($d['re_eur'] - $d['ware']) >= 0.01 && !$d['online']) {
        $diff = $d['re_eur'] - $d['ware'];
        $o .= '<div class="bs-hinweis" style="margin:0 0 12px"><span>Rechnung weicht vom Bestellwert ab: ' . ($diff > 0 ? '+' : '−') . bk_eur(abs($diff)) . '. Gesamtkosten rechnen mit der Rechnung.</span></div>';
    }
    // Kacheln
    $kachel = fn($k, $v, $extra = '') => '<div class="bs-pos-z"><div class="k">' . h($k) . '</div><div' . $extra . '>' . $v . '</div></div>';
    $o .= '<div class="bs-kosten-kacheln">'
        . $kachel($d['online'] ? 'Online-Preis' : 'Warenwert (Bestellung)', h(bk_eur($d['ware'])))
        . $kachel('Rechnung', $d['re_eur'] > 0 ? h(bk_eur($d['re_eur'])) : ($d['re_fremd'] ? 'Fremdwährung' : '–'))
        . $kachel('Nebenkosten', $d['nk_summe'] != 0 ? h(bk_eur($d['nk_summe'])) : '–')
        . $kachel('Gesamtkosten', h(bk_eur($d['gesamt'])), ' style="font-weight:700;font-size:1.15em"')
        . '</div>';
    // Positionen
    if ($d['pos']) {
        $o .= '<div class="bx-tablewrap"><table class="bx-table bx-karten-handy"><thead><tr><th>Artikel</th><th class="bx-num">Menge</th><th class="bx-num">EK je Einheit</th><th class="bx-num">Warenwert</th><th class="bx-num">davon geliefert</th><th class="bx-num">Einstandspreis' . bx_hint('EK plus anteilige Nebenkosten (nach Warenwert verteilt), bei erfasster Rechnung auf deren Summe hochgerechnet') . '</th></tr></thead><tbody>';
        foreach ($d['pos'] as $p) {
            $ein = (string)$p['einheit'] ?: 'Stk';
            $o .= '<tr' . (!$p['aktiv'] ? ' class="muted"' : '') . '><td><span class="bx-lauf">' . h((string)$p['pname']) . '</span>' . (!$p['aktiv'] ? ' ' . bx_badge('storniert', 'err') : (!empty($p['rest_storniert_am']) ? ' ' . bx_badge('Rest storniert', 'warn') : '')) . '</td>'
                . '<td class="bx-num">' . $mz($p['menge_kosten']) . ' ' . h($ein) . '</td>'
                . '<td class="bx-num">' . ((float)$p['ek_preis'] > 0 ? h(preis_anzeige((float)$p['ek_preis'], 'EUR', $ein)) : 'Preis fehlt') . '</td>'
                . '<td class="bx-num">' . h(bk_eur($p['wert'])) . '</td>'
                . '<td class="bx-num">' . ((float)$p['geliefert'] > 0 ? h(bk_eur($p['wert_gel'])) : '–') . '</td>'
                . '<td class="bx-num">' . ($p['einstand'] !== null ? h(preis_anzeige((float)$p['einstand'], 'EUR', $ein)) : '–') . '</td></tr>';
        }
        $o .= '</tbody></table></div>';
    }
    // Nebenkosten
    $o .= '<div class="bs-pos-kopf" style="margin-top:14px"><h3 style="margin:0">Nebenkosten</h3>'
        . '<button type="button" class="btn btn-ghost btn-sm" onclick="bxPopup.open(\'dlgBkNeu\')">Nebenkosten erfassen</button></div>';
    if ($d['nk']) {
        $o .= '<div class="bx-tablewrap"><table class="bx-table bx-karten-handy"><thead><tr><th>Art</th><th>Notiz</th><th class="bx-num">Betrag</th><th>Erfasst</th><th></th></tr></thead><tbody>';
        foreach ($d['nk'] as $k) {
            $o .= '<tr><td>' . h(bk_arten()[$k['art']] ?? (string)$k['art']) . '</td><td>' . h((string)($k['notiz'] ?? '')) . '</td>'
                . '<td class="bx-num">' . h(bk_eur((float)$k['betrag'])) . '</td>'
                . '<td>' . h(function_exists('fmt_zeit') ? fmt_zeit((string)$k['angelegt']) : (string)$k['angelegt']) . ((string)($k['angelegt_von'] ?? '') !== '' ? ' · ' . h((string)$k['angelegt_von']) : '') . '</td>'
                . '<td data-label=""><form method="post" onsubmit="return confirm(\'Diese Kostenzeile stornieren?\')"><input type="hidden" name="aktion" value="bk_storno"><input type="hidden" name="kosten_id" value="' . (int)$k['id'] . '">'
                . '<button class="btn btn-ghost btn-sm" type="submit">Stornieren</button></form></td></tr>';
        }
        $o .= '</tbody></table></div>';
    } else {
        $o .= '<p class="muted" style="margin:6px 0 0">Noch keine Nebenkosten erfasst (z. B. Fracht, Zoll, Bankgebühr).</p>';
    }
    $o .= '<p class="muted" style="font-size:13px;margin:10px 0 0">Gesamtkosten = ' . ($d['basis_rechnung'] ? 'Rechnung' : ($d['online'] ? 'Online-Preis' : 'Bestellwert')) . ' + Nebenkosten, alle Beträge netto in EUR wie erfasst.</p>';
    // Pop-up Nebenkosten erfassen
    $opt = ''; foreach (bk_arten() as $k => $t) $opt .= '<option value="' . h($k) . '">' . h($t) . '</option>';
    $o .= '<dialog id="dlgBkNeu" class="bx-dialog"><h2>Nebenkosten erfassen</h2><form method="post"><input type="hidden" name="aktion" value="bk_neu">'
        . '<div class="bx-grid"><div class="bx-field"><label>Art</label><select name="art">' . $opt . '</select></div>'
        . '<div class="bx-field"><label>Betrag (EUR)</label><input type="text" name="betrag" inputmode="decimal" required data-pflicht placeholder="z. B. 185,00"></div></div>'
        . '<div class="bx-field"><label>Notiz</label><input type="text" name="notiz" maxlength="255" placeholder="z. B. DHL Freight, Rechnung 4711"></div>'
        . '<div class="bx-pop-fuss"><button class="btn btn-primary" type="submit">Speichern</button></div></form></dialog>';
    return $o;
}
