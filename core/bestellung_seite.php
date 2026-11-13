<?php
// Bestellseite aufgeräumt (13.11.2026): gemeinsame Bausteine für die Bestellseite (?p=bestellung), das Bestell-Pop-up aus der
// Liste „Bestellt“ (?p=bestellung_pop) und Einkauf mobil (?p=einkauf_mobil). Überall dieselbe Reihenfolge: Kopf (Status, Lieferant,
// Termine, Badges, Betrag, EIN Hauptknopf für den nächsten Schritt), Position (bestellt / geliefert / offen, Balken), Reiter.
require_once __DIR__ . '/lieferant_unterlagen.php';
require_once __DIR__ . '/wareneingang_bestellung.php';

// Alles, was Kopf und Reiter brauchen, in einem Rutsch.
function bs_daten(array $b): array {
    $id = (int)$b['id'];
    $st = (string)$b['status'];
    $d = ['st' => $st, 'stufe' => bestellung_stufe($b)];
    $d['offen'] = !in_array($st, ['geliefert', 'storniert'], true) && empty($b['angekommen_am']);   // noch Ware erwartet
    $d['lief'] = !empty($b['lieferant_id']) ? one("SELECT id, firma, lieferantennummer FROM lieferanten WHERE id=?", [(int)$b['lieferant_id']]) : null;
    $d['portal'] = bestellung_ist_portal($b);
    lu_abgleich();
    $d['luF'] = $st !== 'storniert' && !empty($b['lieferant_id']) ? lu_bestellung_fehlt($id) : [];
    $d['rechnungen'] = all("SELECT id, datei_orig, titel, rechnung_nr, dok_datum, betrag, waehrung, hochgeladen_von FROM dokument WHERE objekt_typ='bestellung' AND objekt_id=? AND typ='rechnung' ORDER BY id DESC", [$id]);
    $d['er'] = function_exists('er_status_bestellung') ? er_status_bestellung($id) : [];
    $erOffen = array_filter($d['er'], fn($e) => $e['status'] !== 'bezahlt');
    $d['bezahlt'] = !empty($b['bezahlt_am']) || ($d['er'] && !$erOffen);
    $d['bezahlt_am'] = !empty($b['bezahlt_am']) ? (string)$b['bezahlt_am'] : (string)($d['er'][0]['bezahlt_am'] ?? '');
    $d['betrag'] = (float) scalar("SELECT COALESCE(SUM(menge*ek_preis),0) FROM bestellung_position WHERE bestellung_id=? AND COALESCE(pos_storno_grund,'')=''", [$id]);
    if (($b['bestellart'] ?? '') === 'online' && ($b['online_preis'] ?? null) !== null) $d['betrag'] = (float)$b['online_preis'];
    $d['teil'] = (float) scalar("SELECT COALESCE(SUM(geliefert_menge),0) FROM bestellung_position WHERE bestellung_id=?", [$id]);
    $d['chargen'] = (int) scalar("SELECT COUNT(*) FROM charge c JOIN bestellung_position bp ON bp.id=c.bestellung_position_id WHERE bp.bestellung_id=?", [$id]);
    try { $d['chargen'] += (int) scalar("SELECT COUNT(*) FROM bestellung_charge bc JOIN bestellung_position bp ON bp.id=bc.bestellung_position_id WHERE bp.bestellung_id=?", [$id]); } catch (\Throwable $e) {}
    $d['ungelesen'] = !empty($b['lieferant_id']) ? (int) scalar("SELECT COUNT(*) FROM nachricht WHERE lieferant_id=? AND akteur='lieferant' AND bezug_typ='bestellung' AND bezug_id=? AND gelesen_team=0", [(int)$b['lieferant_id'], $id]) : 0;
    $bp = one("SELECT bp.id FROM bestellung_position bp WHERE bp.bestellung_id=? AND bp.menge>0 AND bp.rest_storniert_am IS NULL AND COALESCE(bp.geliefert_menge,0) < bp.menge*0.98 ORDER BY bp.sort, bp.id LIMIT 1", [$id]);
    $d['bp_offen'] = $bp ? (int)$bp['id'] : 0;
    $vor = $bp ? webe_vormerkung((int)$bp['id']) : null;
    $d['einbuchen_url'] = $bp ? '?p=wareneingang&bp=' . (int)$bp['id'] . ($vor ? '&bch=' . (int)$vor['id'] : '') : '';
    return $d;
}

// Der EINE nächste Schritt: Entwurf → Bestellen; bestellt/unterwegs → Ware ist da - einbuchen; geliefert ohne Rechnung → Rechnung
// erfassen; Rechnung offen → Als bezahlt markieren. Rückgabe ['art' => bestellen|einbuchen|rechnung|bezahlt|'', 'label' => …].
function bs_haupt(array $b, array $d): array {
    $st = $d['st'];
    if ($st === 'offen') return ['art' => 'bestellen', 'label' => 'Bestellen'];
    if (in_array($st, ['bestellt', 'gesendet'], true) && empty($b['angekommen_am']) && $d['bp_offen']) return ['art' => 'einbuchen', 'label' => 'Ware ist da - einbuchen'];
    if ($st === 'storniert' || empty($b['lieferant_id'])) return ['art' => '', 'label' => ''];
    if (!$d['rechnungen']) return ['art' => 'rechnung', 'label' => 'Rechnung erfassen'];
    if (!$d['bezahlt']) return ['art' => 'bezahlt', 'label' => 'In der Buchhaltung bezahlen'];   // Fable-6 T4.1: bezahlt nur in der Buchhaltung
    return ['art' => '', 'label' => ''];
}

// Hauptknopf als Link (Pop-up „Bestellt“, Einkauf mobil). Die Bestellseite öffnet die Pop-ups über #rechnung / #bezahlt.
function bs_haupt_link(array $b, array $d, string $klasse = 'btn btn-primary bs-haupt'): string {
    $hp = bs_haupt($b, $d);
    $url = match ($hp['art']) {
        'einbuchen' => $d['einbuchen_url'],
        'rechnung'  => '?p=bestellung&id=' . (int)$b['id'] . '#rechnung',
        'bezahlt'   => (function_exists('er_link_bestellung') ? er_link_bestellung((int)$b['id']) : '') ?: '?p=bestellung&id=' . (int)$b['id'] . '#zahlung',
        'bestellen' => '?p=bestellung&id=' . (int)$b['id'],
        default     => '',
    };
    return $url !== '' ? '<a class="' . h($klasse) . '" href="' . h($url) . '">' . h($hp['label']) . '</a>' : '';
}

function bs_badges(array $b, array $d): string {
    $o = '';
    if ($d['luF']) $o .= bx_badge('Unterlagen fehlen', 'warn') . ' ';
    if ($d['rechnungen'] && !$d['bezahlt'] && $d['st'] !== 'storniert') $o .= bx_badge('Rechnung offen', 'warn') . ' ';
    if (bestellung_ueberfaellig($b)) $o .= bx_badge('überfällig (T+' . bestellung_tage_offen($b) . ')', 'err') . ' ';
    if ($d['teil'] > 0 && $d['offen']) $o .= bx_badge('Teillieferung', 'info') . ' ';
    if ($d['bezahlt'] && $d['bezahlt_am'] !== '') $o .= bx_badge('bezahlt', 'ok') . ' ';
    return $o;
}

// Kopf-Karte. $aktionen = fertiges HTML rechts (Hauptknopf + ggf. Menü „⋯“).
function bs_kopf_html(array $b, array $d, string $aktionen): string {
    $farbe = ['ok' => 'var(--gruen,#1D9E75)', 'warn' => 'var(--warn,#8a5a12)', 'err' => '#8f231b'][$d['stufe']['kind']] ?? 'inherit';
    $dt = fn($x) => date('d.m.Y', strtotime((string)$x));
    $erw = (!empty($b['eta_geplant']) && (empty($b['bestelldatum']) || (string)$b['eta_geplant'] > (string)$b['bestelldatum'])) ? $dt($b['eta_geplant']) : '';
    $lief = $d['lief'] ? '<a href="?p=lieferant&id=' . (int)$d['lief']['id'] . '">' . h((string)$d['lief']['firma']) . '</a>'
          : (($b['bestellart'] ?? '') === 'online' ? 'Online-Einkauf' : 'kein Lieferant');
    $badges = bs_badges($b, $d);
    return '<div class="bx-panel bs-kopf"><div class="bs-kopf-l">'
         . '<div class="muted" style="font-size:13px">Bestellung <a href="?p=bestellung&id=' . (int)$b['id'] . '">' . h((string)$b['nummer']) . '</a></div>'
         . '<div class="bs-status" style="color:' . $farbe . '">' . h($d['stufe']['label']) . '</div>'
         . '<div class="bs-meta">' . $lief
         . (!empty($b['bestelldatum']) ? ' · bestellt am ' . h($dt($b['bestelldatum'])) : '')
         . ($erw !== '' ? ' · erwartet am ' . h($erw) : '')
         . (!empty($b['angekommen_am']) ? ' · eingegangen ' . h($dt($b['angekommen_am'])) : '') . '</div>'
         . ($badges !== '' ? '<div class="bs-badges">' . $badges . '</div>' : '')
         . '</div><div class="bs-kopf-r"><div class="bs-betrag">' . ($d['betrag'] > 0 ? h(number_format($d['betrag'], 2, ',', '.') . ' €') : '<span class="muted">–</span>') . '</div>'
         . ($aktionen !== '' ? '<div class="bs-aktionen">' . $aktionen . '</div>' : '')
         . '</div></div>';
}

// Positions-Karte: je Position eine Zeile (Artikel, für Auftrag, bestellt, geliefert, offen, EK) und der Balken geliefert/bestellt.
// $stift = Pop-up-ID für „Bearbeiten“ (leer = kein Stift).
function bs_position_html(array $b, string $stift = ''): string {
    $pos = all("SELECT bp.id, bp.menge, bp.einheit, bp.item_id, bp.auftrag_id, bp.ek_preis, COALESCE(NULLIF(bp.bezeichnung,''), i.name, '–') AS pname,
                       a.nummer AS auftrag_nr, COALESCE(bp.geliefert_menge,0) AS geliefert, bp.rest_storniert_am, bp.pos_storno_grund
                FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id LEFT JOIN auftrag a ON a.id=bp.auftrag_id
                WHERE bp.bestellung_id=? ORDER BY bp.sort, bp.id", [(int)$b['id']]);
    if (!$pos) return '';
    require_once __DIR__ . '/item_link.php';
    $mz = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
    $o = '<div class="bx-panel bs-pos"><div class="bs-pos-kopf"><h2>Position' . (count($pos) > 1 ? 'en' : '') . '</h2>';
    if ($stift !== '') $o .= '<button type="button" class="bx-stift" title="Bearbeiten (Menge, Preis, Auftrag, Notizen)" onclick="bxPopup.open(\'' . h($stift) . '\')">'
        . '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></button>';
    $o .= '</div>';
    foreach ($pos as $p) {
        $best = (float)$p['menge']; $gel = (float)$p['geliefert']; $ein = h((string)$p['einheit']);
        $storno = !empty($p['rest_storniert_am']) || trim((string)($p['pos_storno_grund'] ?? '')) !== '';
        $rest = ((string)$b['status'] === 'storniert' || $storno) ? 0.0 : max(0.0, $best - $gel);
        $pct = $best > 0 ? (int) min(100, round($gel / $best * 100)) : 0;
        $art = !empty($p['item_id']) ? item_link_html((int)$p['item_id'], (string)$p['pname']) : h((string)$p['pname']);
        $o .= '<div class="bs-pos-zeile"><div class="bs-pos-art"><span class="bx-lauf">' . $art . '</span>'
            . ($storno ? ' ' . bx_badge(trim((string)($p['pos_storno_grund'] ?? '')) !== '' ? 'storniert' : 'Rest storniert', 'err') : '')
            . '<div class="muted" style="font-size:13px">' . (!empty($p['auftrag_nr']) ? 'für <a href="?p=auftrag&id=' . (int)$p['auftrag_id'] . '">' . h((string)$p['auftrag_nr']) . '</a>' : 'Lager / allgemein') . '</div></div>'
            . '<div class="bs-pos-z"><div class="k">bestellt</div><div>' . $mz($best) . ' ' . $ein . '</div></div>'
            . '<div class="bs-pos-z"><div class="k">geliefert</div><div>' . ($gel > 0 ? $mz($gel) . ' ' . $ein : '–') . '</div></div>'
            . '<div class="bs-pos-z"><div class="k">offen</div><div>' . ($rest > 0.0001 ? $mz($rest) . ' ' . $ein : '–') . '</div></div>'
            . '<div class="bs-pos-z"><div class="k">EK je Einheit</div><div>' . ((float)$p['ek_preis'] > 0 ? h(preis_anzeige((float)$p['ek_preis'], 'EUR', (string)$p['einheit'] ?: 'Stk')) : '–') . '</div></div>'
            . '<div class="bs-balken" title="' . $pct . ' % geliefert"><span style="width:' . $pct . '%"></span></div></div>';
    }
    return $o . '</div>';
}

// Reiter: $tabs = [key => [Beschriftung, Zähler/Punkt, Inhalt-HTML]]. Umschalten und Anker (#unterlagen, #wareneingang, #zahlung …)
// macht bx-ui.js (data-bs-tab / data-bs-panel), damit es auch im nachgeladenen Pop-up geht.
function bs_reiter_html(array $tabs, string $standard): string {
    $o = '<div class="bs-reiter"><div class="bs-tabs" role="tablist">';
    foreach ($tabs as $k => $t) $o .= '<button type="button" role="tab" class="bs-tab' . ($k === $standard ? ' on' : '') . '" data-bs-tab="' . h($k) . '">' . h($t[0])
        . ((string)$t[1] !== '' ? ' <span class="bs-tab-z">' . h((string)$t[1]) . '</span>' : '') . '</button>';
    $o .= '</div>';
    foreach ($tabs as $k => $t) $o .= '<section class="bs-panel" data-bs-panel="' . h($k) . '"' . ($k === $standard ? '' : ' hidden') . '>' . $t[2] . '</section>';
    return $o . '</div>';
}
