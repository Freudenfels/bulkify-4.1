<?php
// Warnung bei Doppelbestellung (Sofortfix 11.11.2026, Fable 3.6 Zusatzfrage B). Live entstanden am 09.11. drei doppelte
// Bestellungen (BE-2932/2933/2934), z. B. AB-3227 je 300.000 in BE-2890 und BE-2933. Keine harte Sperre: vor dem Anlegen
// zeigt ein Pop-up die offenen Bestellungen mit Link; weiter nur mit Bestätigung, die im Verlauf der neuen Bestellung steht.
//
// Ablauf auf jedem Bestellweg (Einkaufsliste inkl. „Vorsorglich" und „Direkt bestellt", Beschaffungsweg, PA „Bedarf bestellen"):
//   $t = bestellung_doppelt_treffer($items, $bulkProdukte, $auftragId);
//   if ($t && empty($_POST['doppelt_ok'])) bestellung_doppelt_stopp($t, $zurueckUrl);   // merkt den POST, zurück, Pop-up
//   ... Bestellung(en) anlegen ...
//   bestellung_doppelt_vermerken($neueIds, $t);                                         // nur wenn bestätigt
// Das Pop-up rendert core/layout.php (bestellung_doppelt_dialog()) einmalig auf der nächsten Seite.

// Offene Positionen: Menge offen (< 98 % geliefert), Rest nicht storniert, Bestellung Entwurf/gesendet/bestellt – oder
// eingegangen, aber erst teilweise geliefert (an der Bestellung hängt schon eine Charge). Alt-Bestellungen „geliefert"
// ohne verknüpfte Charge zählen nicht (Altbestand, siehe Datenprüfung Einkauf-Altlasten).
function bestellung_doppelt_offen_sql(): string {
    return "bp.menge>0 AND bp.rest_storniert_am IS NULL AND COALESCE(bp.geliefert_menge,0) < bp.menge*0.98
            AND (b.status IN ('offen','gesendet','bestellt')
                 OR (b.status='geliefert' AND EXISTS (SELECT 1 FROM charge cx JOIN bestellung_position bx ON bx.id=cx.bestellung_position_id WHERE bx.bestellung_id=b.id)))";
}
// Treffer für Artikel ($item_ids), Bulk-/Fertigware-Produkte ($bulk_produkt_ids, Positionen ohne Artikel an Aufträgen des
// Produkts) und einen Auftrag ($auftrag_id: Bulk-/Premix-Positionen dieses Auftrags). Rückgabe je offene Position:
// bestellung_id, nummer, status, bez, menge, offen, einheit.
function bestellung_doppelt_treffer(array $item_ids = [], array $bulk_produkt_ids = [], int $auftrag_id = 0): array {
    $items = array_values(array_unique(array_filter(array_map('intval', $item_ids))));
    $prods = array_values(array_unique(array_filter(array_map('intval', $bulk_produkt_ids))));
    $or = []; $args = [];
    if ($items && $auftrag_id <= 0) $or[] = 'bp.item_id IN (' . implode(',', $items) . ')';
    if ($prods) $or[] = "(bp.item_id IS NULL AND bp.auftrag_id IN (SELECT id FROM auftrag WHERE produkt_id IN (" . implode(',', $prods) . ")))";
    if ($auftrag_id > 0) { $or[] = '(bp.auftrag_id=? AND (bp.item_id IS NULL' . ($items ? ' OR bp.item_id IN (' . implode(',', $items) . ')' : '') . '))'; $args[] = $auftrag_id; }
    if (!$or) return [];
    try {
        return all("SELECT b.id AS bestellung_id, b.nummer, b.status, COALESCE(NULLIF(bp.bezeichnung,''), i.name, '') AS bez, bp.menge,
                           IF(bp.rest_storniert_am IS NULL, GREATEST(bp.menge - COALESCE(bp.geliefert_menge,0), 0), 0) AS offen, COALESCE(bp.einheit, i.einheit, '') AS einheit
                    FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id LEFT JOIN item i ON i.id=bp.item_id
                    WHERE (" . implode(' OR ', $or) . ") AND " . bestellung_doppelt_offen_sql() . "
                    ORDER BY b.id DESC LIMIT 20", $args);
    } catch (\Throwable $e) { return []; }
}
// Treffer gefunden, noch nicht bestätigt: Formular-Daten merken, zurück auf die Seite, dort erscheint das Pop-up.
function bestellung_doppelt_stopp(array $treffer, string $zurueck): void {
    $_SESSION['bx_doppelt'] = ['post' => $_POST, 'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''), 'treffer' => $treffer, 'zurueck' => $zurueck];
    header('Location: ' . $zurueck); exit;
}
// Nach dem bestätigten Anlegen: Vermerk im Verlauf jeder neuen Bestellung (wer, welche offene Bestellung).
function bestellung_doppelt_vermerken(array $neue_ids, array $treffer): void {
    if (!$treffer || empty($_POST['doppelt_ok'])) return;
    $wer = function_exists('current_user') ? (string)(current_user()['name'] ?? 'Team') : 'Team';
    $nrn = implode(', ', array_unique(array_column($treffer, 'nummer')));
    foreach (array_filter(array_map('intval', $neue_ids)) as $bid)
        log_aktivitaet('bestellung', $bid, 'team', 'Trotz offener Bestellung ' . $nrn . ' angelegt - bestätigt von ' . $wer . '.', 'notiz');
}
// Pop-up (einmalig, aus der Session). Wird in core/layout.php direkt nach <main> ausgegeben.
function bestellung_doppelt_dialog(): string {
    $d = $_SESSION['bx_doppelt'] ?? null;
    if (!is_array($d) || empty($d['treffer'])) return '';
    unset($_SESSION['bx_doppelt']);
    $nf = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
    $zeilen = '';
    foreach ($d['treffer'] as $t)
        $zeilen .= '<li style="margin:4px 0"><a href="?p=bestellung&amp;id=' . (int)$t['bestellung_id'] . '" target="_blank">' . h((string)$t['nummer']) . '</a> · '
                 . h((string)$t['bez']) . ' (' . $nf($t['menge']) . ', offen ' . $nf($t['offen']) . ($t['einheit'] !== '' ? ' ' . h((string)$t['einheit']) : '') . ') · '
                 . h(function_exists('status_text') ? status_text((string)$t['status']) : (string)$t['status']) . '</li>';
    $hidden = '';
    $flach = function ($wert, string $name) use (&$flach, &$hidden) {
        if (is_array($wert)) { foreach ($wert as $k => $v) $flach($v, $name . '[' . $k . ']'); return; }
        $hidden .= '<input type="hidden" name="' . h($name) . '" value="' . h((string)$wert) . '">';
    };
    foreach ((array)$d['post'] as $k => $v) if ($k !== 'doppelt_ok') $flach($v, (string)$k);
    $ziel = preg_match('/^\/?(\?|index\.php\?|$)/', (string)$d['uri']) ? (string)$d['uri'] : '';
    return '<dialog class="bx-dialog" style="max-width:600px" open>'
         . '<h2 style="margin-top:0">Es gibt schon eine offene Bestellung</h2>'
         . '<p class="muted" style="margin-top:0">Für diesen Auftrag/Artikel ist schon bestellt:</p><ul style="margin:0 0 12px 18px;padding:0">' . $zeilen . '</ul>'
         . '<p class="muted">Trotzdem bestellen? Die Bestätigung steht im Verlauf der neuen Bestellung.</p>'
         . '<form method="post" action="' . h($ziel) . '" style="margin:0">' . $hidden . '<input type="hidden" name="doppelt_ok" value="1">'
         . '<div class="bx-row" style="justify-content:flex-end;gap:8px;margin-top:14px"><a class="btn btn-ghost" href="' . h((string)$d['zurueck']) . '">Abbrechen</a>'
         . '<button class="btn btn-primary" type="submit">Trotzdem bestellen</button></div></form></dialog>';
}
