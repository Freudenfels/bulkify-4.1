<?php
// Preise mit Gültigkeit („gültig bis") für schwankende Rohstoffe (Nico 11.11.2026, z. B. Whey).
// Jeder Lieferantenpreis kann ein Datum gueltig_bis haben (leer = unbegrenzt, wie bisher). Abgelaufene Preise werden
// nicht gelöscht, sondern markiert; die Kalkulation nimmt bevorzugt gültige Preise (pg_auswahl) und verwendet
// abgelaufene nie still. Rohstoffe mit „Preis schwankt" bekommen eine Standard-Gültigkeit (Tage) zur Vorbelegung.
require_once __DIR__ . '/schema.php';

// Spalten anlegen (best effort; stehen auch in core/schema.php).
function pg_schema(): void {
    static $fertig = false; if ($fertig) return; $fertig = true;
    try {
        foreach (['lieferant_preis', 'lieferant_angebot', 'rezeptur_lief_angebot', 'lieferant_preisliste', 'produkt_lieferant_preis'] as $t)
            if (table_exists($t)) ensure_column($t, 'gueltig_bis', "DATE NULL");
        ensure_column('item', 'preis_schwankt', "TINYINT(1) NOT NULL DEFAULT 0");
        ensure_column('item', 'preis_gueltig_tage', "INT NULL");
    } catch (Throwable $e) {}
}

// Heutiges Datum in Deutschland (Gültigkeit ist ein Kalendertag, kein Zeitpunkt).
function pg_heute(): string {
    try { return (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d'); } catch (Throwable $e) { return date('Y-m-d'); }
}
function pg_plus_tage(int $tage): string {
    try { return (new DateTime('now', new DateTimeZone('Europe/Berlin')))->modify('+' . $tage . ' days')->format('Y-m-d'); } catch (Throwable $e) { return date('Y-m-d', strtotime('+' . $tage . ' days')); }
}

// Datum aus einer Eingabe lesen: 2026-11-25 oder 25.11.2026. Leer/ungültig = null (unbegrenzt).
function pg_lesen(?string $roh): ?string {
    $s = trim((string)$roh);
    if ($s === '') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return $s;
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return null;
}

// Status: unbegrenzt | gueltig | bald (≤ 3 Tage) | abgelaufen
function pg_status(?string $bis): string {
    $bis = trim((string)$bis);
    if ($bis === '' || $bis === '0000-00-00') return 'unbegrenzt';
    $heute = pg_heute();
    if ($bis < $heute) return 'abgelaufen';
    return $bis <= pg_plus_tage(3) ? 'bald' : 'gueltig';
}
function pg_datum(?string $d): string { return $d ? date('d.m.Y', strtotime((string)$d)) : ''; }

// Text neben dem Preis. $sprache für das Lieferantenportal (de/en/zh).
function pg_text(?string $bis, string $sprache = 'de'): string {
    $T = [
        'unb'  => ['de' => 'unbegrenzt', 'en' => 'no expiry', 'zh' => '长期有效'],
        'bis'  => ['de' => 'gültig bis', 'en' => 'valid until', 'zh' => '有效期至'],
        'abg'  => ['de' => 'abgelaufen seit', 'en' => 'expired since', 'zh' => '已过期，自'],
        'bald' => ['de' => 'läuft bald ab', 'en' => 'expires soon', 'zh' => '即将过期'],
    ];
    $s = in_array($sprache, ['de', 'en', 'zh'], true) ? $sprache : 'en';
    $st = pg_status($bis);
    if ($st === 'unbegrenzt') return $T['unb'][$s];
    if ($st === 'abgelaufen') return $T['abg'][$s] . ' ' . pg_datum($bis);
    return $T['bis'][$s] . ' ' . pg_datum($bis) . ($st === 'bald' ? ' (' . $T['bald'][$s] . ')' : '');
}
// Kleine Anzeige (HTML) – grau bei unbegrenzt, gelb bei bald, rot bei abgelaufen.
function pg_anzeige(?string $bis, string $sprache = 'de', bool $unbegrenztZeigen = true): string {
    $st = pg_status($bis);
    if ($st === 'unbegrenzt' && !$unbegrenztZeigen) return '';
    $farbe = ['unbegrenzt' => 'var(--muted)', 'gueltig' => 'var(--muted)', 'bald' => '#8a6d1f', 'abgelaufen' => '#8f231b'][$st];
    return '<span style="font-size:12px;color:' . $farbe . ';white-space:nowrap">' . h(pg_text($bis, $sprache)) . '</span>';
}

// Standard-Gültigkeit eines Rohstoffs (Tage) – nur bei „Preis schwankt". null = nicht markiert.
function pg_item_tage(int $item_id): ?int {
    if ($item_id <= 0) return null;
    pg_schema();
    try { $r = one("SELECT preis_schwankt, preis_gueltig_tage FROM item WHERE id=?", [$item_id]); } catch (Throwable $e) { return null; }
    if (!$r || (int)$r['preis_schwankt'] !== 1) return null;
    return max(1, (int)($r['preis_gueltig_tage'] ?: 14));
}
// Vorbelegung „gültig bis" für einen Artikel: heute + Standard-Gültigkeit, sonst leer.
function pg_vorbelegung(int $item_id): ?string { $t = pg_item_tage($item_id); return $t ? pg_plus_tage($t) : null; }

// Eingabefeld „gültig bis" mit Schnellwahl 7/14/30/90 Tage und freiem Datum.
// $opt: pflicht (bool), sprache (de/en/zh), label (string|null), hinweis (string)
function pg_eingabe(string $name, ?string $wert, array $opt = []): string {
    static $js = false;
    $s = in_array($opt['sprache'] ?? 'de', ['de', 'en', 'zh'], true) ? ($opt['sprache'] ?? 'de') : 'de';
    $T = ['lbl' => ['de' => 'Preis gültig bis', 'en' => 'Price valid until', 'zh' => '价格有效期至'],
          'tg'  => ['de' => 'Tage', 'en' => 'days', 'zh' => '天'],
          'leer'=> ['de' => 'leer = unbegrenzt', 'en' => 'empty = no expiry', 'zh' => '留空 = 长期有效'],
          'pfl' => ['de' => 'Bitte angeben, wie lange Ihr Preis gilt', 'en' => 'Please state how long your price is valid', 'zh' => '请注明您的价格有效期']];
    $pflicht = !empty($opt['pflicht']);
    $label = array_key_exists('label', $opt) ? $opt['label'] : $T['lbl'][$s];
    $id = 'pg' . substr(md5($name . mt_rand()), 0, 8);
    $o = '<div class="bx-field" style="margin:0">';
    if ($label !== null) $o .= '<label for="' . $id . '">' . h($label) . ($pflicht ? ' *' : '') . '</label>';
    $o .= '<div class="bx-row" style="gap:6px;align-items:center;flex-wrap:wrap;margin:0">'
        . '<input type="date" id="' . $id . '" name="' . h($name) . '" value="' . h((string)$wert) . '" min="' . h(pg_heute()) . '"' . ($pflicht ? ' required' : '') . ' style="width:auto">';
    foreach ([7, 14, 30, 90] as $t)
        $o .= '<button type="button" class="btn btn-ghost btn-sm" data-pg-ziel="' . $id . '" data-pg-tage="' . $t . '">' . $t . ' ' . h($T['tg'][$s]) . '</button>';
    $o .= '</div><div class="muted" style="font-size:12px;margin-top:3px">' . h($pflicht ? $T['pfl'][$s] : $T['leer'][$s]) . (!empty($opt['hinweis']) ? ' · ' . h((string)$opt['hinweis']) : '') . '</div></div>';
    if (!$js) {
        $js = true;
        $o .= '<script>document.addEventListener("click",function(e){var b=e.target.closest("[data-pg-tage]");if(!b)return;var i=document.getElementById(b.dataset.pgZiel);if(!i)return;'
            . 'var d=new Date();d.setDate(d.getDate()+parseInt(b.dataset.pgTage,10));i.value=d.getFullYear()+"-"+String(d.getMonth()+1).padStart(2,"0")+"-"+String(d.getDate()).padStart(2,"0");i.dispatchEvent(new Event("change",{bubbles:true}));});</script>';
    }
    return $o;
}

// Zeilen für die Kalkulation auswählen (Teil 3). $rows: Preiszeilen mit Feld 'gueltig_bis'.
// - Gibt es gültige Zeilen (unbegrenzt oder bis >= heute), werden NUR diese genommen. 'abgelaufen_ignoriert' = es gab
//   zusätzlich abgelaufene (Hinweis, z. B. „billigerer Preis abgelaufen").
// - Gibt es nur abgelaufene, werden die des letzten Ablaufdatums genommen und 'abgelaufen' = dieses Datum gesetzt.
// $kontext (Block D, 11.11.2026): ['item'|'produkt'|'rezeptur', id] – wird nur abgelaufen gerechnet, merkt pg_abgelaufen_merken()
// das für den Hinweis „Preis abgelaufen seit …, Summe unsicher" auf der Seite (nie still verwendet, Handbuch 4.2).
function pg_auswahl(array $rows, string $feld = 'gueltig_bis', array $kontext = []): array {
    $heute = pg_heute(); $gueltig = []; $abg = [];
    foreach ($rows as $r) {
        $b = trim((string)($r[$feld] ?? ''));
        if ($b === '' || $b === '0000-00-00' || $b >= $heute) $gueltig[] = $r; else $abg[] = $r;
    }
    if ($gueltig) return ['rows' => $gueltig, 'abgelaufen' => null, 'abgelaufen_ignoriert' => (bool)$abg];
    if (!$abg) return ['rows' => [], 'abgelaufen' => null, 'abgelaufen_ignoriert' => false];
    $max = max(array_map(fn($r) => (string)$r[$feld], $abg));
    pg_abgelaufen_merken((string)($kontext[0] ?? ''), (int)($kontext[1] ?? 0), $max);
    return ['rows' => array_values(array_filter($abg, fn($r) => (string)$r[$feld] === $max)), 'abgelaufen' => $max, 'abgelaufen_ignoriert' => false];
}
// Merkliste der in diesem Seitenaufruf benutzten abgelaufenen Preise (Block D, 11.11.2026). $typ item|produkt|rezeptur.
function pg_abgelaufen_merken(string $typ, int $id, string $datum): void {
    $k = $typ . ':' . $id;
    if (!isset($GLOBALS['pg_abgelaufen'][$k]) || $GLOBALS['pg_abgelaufen'][$k]['datum'] > $datum)
        $GLOBALS['pg_abgelaufen'][$k] = ['typ' => $typ, 'id' => $id, 'datum' => $datum];
}
// Hinweis für die Seite: „Preis abgelaufen seit …, Summe unsicher" mit den betroffenen Artikeln/Produkten/Rezepturen (verlinkt).
// Leer, wenn auf dieser Seite kein abgelaufener Preis gerechnet wurde. Ausgabe in core/layout.php (render_footer) oben auf der Seite.
function pg_abgelaufen_hinweis(): string {
    $l = $GLOBALS['pg_abgelaufen'] ?? [];
    if (!$l) return '';
    $teile = [];
    foreach ($l as $e) {
        [$sql, $url, $lbl] = match ($e['typ']) {
            'item'     => ["SELECT name FROM item WHERE id=?", '?p=rohstoff&id=', 'Artikel'],
            'produkt'  => ["SELECT name FROM produkt WHERE id=?", '?p=produkt&id=', 'Produkt'],
            'rezeptur' => ["SELECT name FROM rezeptur WHERE id=?", '?p=rezeptur_detail&id=', 'Rezeptur'],
            default    => ['', '', 'Preis'],
        };
        $name = ($sql !== '' && $e['id'] > 0) ? (string) scalar($sql, [$e['id']]) : '';
        $teile[] = ($name !== '' ? '<a href="' . $url . (int)$e['id'] . '">' . htmlspecialchars($name) . '</a>' : htmlspecialchars($lbl))
                 . ' (abgelaufen seit ' . date('d.m.Y', strtotime($e['datum'])) . ')';
    }
    return '<div class="bx-panel" id="pgAbgelaufen" style="padding:10px 14px;border-color:#e8c37a">Preis abgelaufen - Summe unsicher. Gerechnet wurde mit dem letzten, abgelaufenen Lieferantenpreis: '
         . implode(', ', $teile) . '. Bitte neu anfragen oder ein neues „gültig bis" eintragen.</div>';
}
// Frühestes „gültig bis" (heute oder später) einer Liste – für die Angebotsgültigkeit.
function pg_fruehestes(array $daten): ?string {
    $heute = pg_heute(); $min = null;
    foreach ($daten as $d) { $d = trim((string)$d); if ($d !== '' && $d >= $heute && ($min === null || $d < $min)) $min = $d; }
    return $min;
}

// Teil 2: Wie schwankt der Preis bei einer Lieferanten-Anfrage?
//  ['pflicht'=>bool, 'tage'=>?int, 'namen'=>[…]] - Pflicht nur, wenn der angefragte Artikel selbst „Preis schwankt" hat.
//  Bei Premix/Bulk/Fremdfertigung (Rezeptur) mit einem schwankenden Rohstoff: nur Hinweis (namen gesetzt, pflicht=false).
function pg_anfrage_schwankung(array $a): array {
    $out = ['pflicht' => false, 'tage' => null, 'namen' => []];
    $iid = (int)($a['item_id'] ?? 0);
    if ($iid > 0 && ($t = pg_item_tage($iid))) {
        $out['pflicht'] = true; $out['tage'] = $t; $out['namen'][] = (string) scalar("SELECT name FROM item WHERE id=?", [$iid]);
        return $out;
    }
    $rid = (int)($a['rezeptur_id'] ?? 0);
    if ($rid > 0) {
        try {
            foreach (all("SELECT DISTINCT i.name, i.preis_gueltig_tage FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id
                          WHERE z.rezeptur_id=? AND i.preis_schwankt=1", [$rid]) as $r) {
                $out['namen'][] = (string)$r['name'];
                $t = max(1, (int)($r['preis_gueltig_tage'] ?: 14));
                $out['tage'] = $out['tage'] === null ? $t : min($out['tage'], $t);
            }
        } catch (Throwable $e) {}
    }
    return $out;
}


// Teil 3: bald ablaufende Preise (≤ 3 Tage), die in einem OFFENEN Angebot stecken -> Benachrichtigung (einmal je Preis und Datum).
// Läuft höchstens einmal am Tag (app_meta pg_bald_check), angestoßen vom Dashboard. Später Aufgabe in „Zu erledigen".
function pg_bald_pruefen(): int {
    if (!function_exists('meta_get') || meta_get('pg_bald_check', '') === pg_heute()) return 0;
    meta_set('pg_bald_check', pg_heute());
    $n = 0;
    try {
        $bald = all("SELECT lp.item_id, MIN(lp.gueltig_bis) AS bis, i.name FROM lieferant_preis lp JOIN item i ON i.id=lp.item_id
                     WHERE lp.gueltig_bis IS NOT NULL AND lp.gueltig_bis BETWEEN ? AND ? GROUP BY lp.item_id, i.name", [pg_heute(), pg_plus_tage(3)]);
        foreach ($bald as $b) {
            $iid = (int)$b['item_id'];
            // Offene Angebote (Entwurf oder gesendet, noch gültig), in denen der Rohstoff steckt: als Rohstoff-Position oder in einer Rezeptur.
            $ang = all("SELECT DISTINCT a.id, a.nummer FROM angebot a JOIN angebot_position ap ON ap.angebot_id=a.id
                        LEFT JOIN rezeptur_zutat z ON z.rezeptur_id=ap.rezeptur_id
                        WHERE a.status IN ('offen','gesendet') AND (a.gueltig_bis IS NULL OR a.gueltig_bis >= ?)
                          AND (z.item_id=? OR (ap.quelle='rohstoff' AND ap.artikelnr=(SELECT artikelnummer FROM item WHERE id=?)))
                        LIMIT 10", [pg_heute(), $iid, $iid]);
            if (!$ang) continue;
            $key = 'pg_bald_' . $iid . '_' . $b['bis'];
            if (meta_get($key, '') === '1') continue;
            meta_set($key, '1');
            benachrichtigung_add('Preis läuft bald ab: ' . $b['name'] . ' (bis ' . pg_datum($b['bis']) . ')',
                'Steckt in offenen Angeboten: ' . implode(', ', array_column($ang, 'nummer')) . '. Neu anfragen oder Angebotsgültigkeit prüfen.',
                '?p=rohstoff&id=' . $iid . '&tab=ek', 'sonstiges', true);
            // Zusätzlich Aufgabe in „Zu erledigen" (Team, fällig am Ablaufdatum) - verschwindet erst, wenn jemand sie erledigt.
            if (function_exists('aufgabe_neu') && table_exists('aufgabe')) {
                try {
                    aufgabe_neu('Preis neu anfragen: ' . $b['name'] . ' (gültig bis ' . pg_datum($b['bis']) . ')',
                        'Der Lieferantenpreis läuft bald ab und steckt in offenen Angeboten: ' . implode(', ', array_column($ang, 'nummer'))
                        . '. Am Rohstoff (Reiter Einkauf) „neu anfragen“ oder die Angebotsgültigkeit anpassen.',
                        1, null, (string)$b['bis'], null, 'item', $iid);
                } catch (Throwable $e) {}
            }
            $n++;
        }
    } catch (Throwable $e) {}
    // Block D (11.11.2026, Fable 14.14): auch Zukauf (produkt_lieferant_preis) und Fremdfertigung/Premix (rezeptur_lief_angebot).
    $n += pg_bald_pruefen_quelle("SELECT z.produkt_id AS oid, MIN(z.gueltig_bis) AS bis, p.name FROM produkt_lieferant_preis z JOIN produkt p ON p.id=z.produkt_id
                                  WHERE z.gueltig_bis IS NOT NULL AND z.gueltig_bis BETWEEN ? AND ? GROUP BY z.produkt_id, p.name",
                                 "SELECT DISTINCT a.id, a.nummer FROM angebot a WHERE a.produkt_id=? AND a.status IN ('offen','gesendet') AND (a.gueltig_bis IS NULL OR a.gueltig_bis >= ?) LIMIT 10",
                                 'produkt', 'Zukaufpreis', '?p=produkt&id=');
    $n += pg_bald_pruefen_quelle("SELECT r.id AS oid, MIN(a.gueltig_bis) AS bis, r.name FROM rezeptur_lief_angebot a JOIN rezeptur r ON r.id=a.rezeptur_id
                                  WHERE a.gueltig_bis IS NOT NULL AND a.gueltig_bis BETWEEN ? AND ? GROUP BY r.id, r.name",
                                 "SELECT DISTINCT an.id, an.nummer FROM angebot an JOIN angebot_position ap ON ap.angebot_id=an.id
                                  WHERE ap.rezeptur_id=? AND an.status IN ('offen','gesendet') AND (an.gueltig_bis IS NULL OR an.gueltig_bis >= ?) LIMIT 10",
                                 'rezeptur', 'Fremdfertigungs-/Premixpreis', '?p=rezeptur_detail&id=');
    return $n;
}
// Bald ablaufende Preise einer Quelle (Produkt-Zukauf, Rezeptur-Fremdfertigung) -> Benachrichtigung + Aufgabe, wenn sie in einem
// offenen Angebot stecken. Einmal je Objekt und Datum (app_meta pg_bald_<typ>_<id>_<datum>).
function pg_bald_pruefen_quelle(string $sqlBald, string $sqlAngebote, string $typ, string $was, string $link): int {
    $n = 0;
    try {
        foreach (all($sqlBald, [pg_heute(), pg_plus_tage(3)]) as $b) {
            $oid = (int)$b['oid'];
            $ang = all($sqlAngebote, [$oid, pg_heute()]);
            if (!$ang) continue;
            $key = 'pg_bald_' . $typ . '_' . $oid . '_' . $b['bis'];
            if (meta_get($key, '') === '1') continue;
            meta_set($key, '1');
            benachrichtigung_add($was . ' läuft bald ab: ' . $b['name'] . ' (bis ' . pg_datum($b['bis']) . ')',
                'Steckt in offenen Angeboten: ' . implode(', ', array_column($ang, 'nummer')) . '. Neu anfragen oder Angebotsgültigkeit prüfen.', $link . $oid, 'sonstiges', true);
            if (function_exists('aufgabe_neu') && table_exists('aufgabe')) {
                try { aufgabe_neu($was . ' neu anfragen: ' . $b['name'] . ' (gültig bis ' . pg_datum($b['bis']) . ')',
                        'Der Preis läuft bald ab und steckt in offenen Angeboten: ' . implode(', ', array_column($ang, 'nummer')) . '.', 1, null, (string)$b['bis'], null, $typ, $oid); } catch (Throwable $e) {}
            }
            $n++;
        }
    } catch (Throwable $e) {}
    return $n;
}
// Täglich (Cron): Auto-EK, dessen Lieferantenpreise inzwischen alle abgelaufen sind, bekommt das Kennzeichen „aus abgelaufenem Preis"
// (item_ek_aus_lieferantenpreis setzt es; der EK selbst bleibt). Block D, 11.11.2026.
function pg_ek_abgelaufen_pruefen(): int {
    $n = 0;
    try {
        foreach (all("SELECT i.id FROM item i WHERE i.ek_auto=1 AND i.ek_abgelaufen_seit IS NULL
                        AND EXISTS (SELECT 1 FROM lieferant_preis lp WHERE lp.item_id=i.id AND lp.preis>0)
                        AND NOT EXISTS (SELECT 1 FROM lieferant_preis lp WHERE lp.item_id=i.id AND lp.preis>0 AND (lp.gueltig_bis IS NULL OR lp.gueltig_bis >= CURDATE()))") as $r) {
            item_ek_aus_lieferantenpreis((int)$r['id']); $n++;
        }
    } catch (Throwable $e) {}
    return $n;
}
