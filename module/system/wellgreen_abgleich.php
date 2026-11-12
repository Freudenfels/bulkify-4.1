<?php
// Abgleich Wellgreen-Bestellungen gegen Maggis Liste (Nico 12.11.2026). Nur Admin.
// Teil A (nur lesen): ?p=wellgreen_abgleich&format=json gibt alle Bestellpositionen von Wellgreen (L-2789) und aller
// Bestellungen ohne Lieferant samt verknüpften Chargen aus, dazu die in Frage kommenden Chargen (Wellgreen, gleiche Artikel,
// Fertigware/Bulk seit 01.01.2026). Daraus entsteht die Abgleich-Tabelle der Rückmeldung.
// Teil B: zeigt das Ergebnis der einmaligen Datenpflege (core/datenpflege_wellgreen.php, app_meta dpf_wg_ergebnis).
if (!has_role('admin')) { render_header('wellgreen_abgleich', 'Abgleich Wellgreen'); echo '<div class="bx-panel">Nur für Admin.</div>'; render_footer(); exit; }

$WG = (int) (scalar("SELECT id FROM lieferanten WHERE lieferantennummer='L-2789'") ?: 96);

if (($_GET['format'] ?? '') === 'json') {
    $best = all("SELECT b.id, b.nummer, b.lieferant_id, b.status, b.station, b.bestellart, b.bestelldatum, b.angelegt, b.angekommen_am,
                        b.teilgeliefert_am, b.eta_geplant, b.bestaetigt, LEFT(COALESCE(b.notiz,''), 600) AS notiz
                 FROM bestellung b WHERE b.lieferant_id=? OR b.lieferant_id IS NULL ORDER BY b.id", [$WG]);
    $ids = array_map(fn($b) => (int)$b['id'], $best);
    $pos = []; $itemIds = [];
    if ($ids) {
        $in = implode(',', $ids);
        foreach (all("SELECT bp.id, bp.bestellung_id, bp.item_id, bp.menge, bp.einheit, bp.bezeichnung, COALESCE(bp.geliefert_menge,0) AS geliefert,
                             bp.rest_storniert_am, bp.auftrag_id, i.name AS item_name, i.kategorie, a.nummer AS auftrag_nr
                      FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id LEFT JOIN auftrag a ON a.id=bp.auftrag_id
                      WHERE bp.bestellung_id IN ($in) ORDER BY bp.bestellung_id, bp.sort, bp.id") as $p) {
            $pos[] = $p;
            if ($p['item_id']) $itemIds[(int)$p['item_id']] = true;
        }
    }
    $wo = ["c.lieferant_id=" . $WG, "(i.kategorie IN ('fertig','verkaufsfertig') AND COALESCE(c.wareneingang, DATE(c.angelegt)) >= '2026-01-01' AND c.pa_id IS NULL)"];
    if ($itemIds) $wo[] = 'c.item_id IN (' . implode(',', array_keys($itemIds)) . ')';
    $ch = all("SELECT c.id, c.charge_nr, c.item_id, i.name AS item_name, i.kategorie, c.menge, c.menge_verfuegbar, c.einheit, c.lieferant_id,
                      l.firma AS lieferant, c.wareneingang, DATE(c.angelegt) AS angelegt, c.status, c.bestellung_position_id,
                      b.nummer AS bestellung_nr, c.auftrag_id, c.we_grund
               FROM charge c LEFT JOIN item i ON i.id=c.item_id LEFT JOIN lieferanten l ON l.id=c.lieferant_id
               LEFT JOIN bestellung_position bp ON bp.id=c.bestellung_position_id LEFT JOIN bestellung b ON b.id=bp.bestellung_id
               WHERE " . implode(' OR ', $wo) . " ORDER BY c.id");
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['wellgreen_id' => $WG, 'bestellungen' => $best, 'positionen' => $pos, 'chargen' => $ch], JSON_UNESCAPED_UNICODE);
    exit;
}

render_header('wellgreen_abgleich', 'Abgleich Wellgreen');
echo '<div class="bx-panel"><h2 style="margin-top:0">Abgleich Wellgreen gegen Maggis Liste</h2>'
   . '<p class="muted">Nur lesen. Rohdaten: <a href="?p=wellgreen_abgleich&amp;format=json">JSON</a>. Ergebnis der einmaligen Datenpflege unten.</p></div>';
$erg = (string) meta_get('dpf_wg_ergebnis', '');
echo '<div class="bx-panel"><h2 style="margin-top:0">Ergebnis Datenpflege</h2>';
if ($erg === '') echo '<p class="muted">Noch nicht gelaufen.</p>';
else {
    $e = json_decode($erg, true);
    if (!is_array($e)) echo '<p>' . h($erg) . '</p>';
    else {
        echo '<div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Schritt</th><th>Bestellung</th><th>Ergebnis</th></tr></thead><tbody>';
        foreach ($e as $z) echo '<tr><td>' . h((string)($z['schritt'] ?? '')) . '</td><td>' . (!empty($z['bid']) ? '<a href="?p=bestellung&amp;id=' . (int)$z['bid'] . '">' . h((string)($z['nr'] ?? '')) . '</a>' : h((string)($z['nr'] ?? ''))) . '</td><td>' . h((string)($z['text'] ?? '')) . '</td></tr>';
        echo '</tbody></table></div>';
    }
}
echo '</div>';
render_footer();
