<?php
// Werk-App: geführter Ablauf (Nico 11.11.2026, plan-produktion-gefuehrt.md Teil 4). Naht zum Dashboard für die Werk-App –
// liest Material-Rollen eines PA und löst Scans auf. Gebucht wird über den gemeinsamen Kern core/pa_ablauf.php.

// Welche Stationen deckt der geführte Ablauf ab? (Abfüllen bis Etikettieren/Verpacken.) Mischen/Verkapseln bleiben eigene Schritte.
function erp_wa_stationen(): array {
    return ['Fertigware bereitstellen', 'Abfüllung', 'Pulver-Abfüllung', 'Stick-Abfüllung', 'Verpacken', 'Etikettieren', 'Beipackzettel beilegen', 'Umkarton', 'Fertigware bereitstellen'];
}
// Läuft der PA im geführten Ablauf? = der nächste offene Schritt ist eine Abfüll-/Verpackungsstation (oder alle davor sind erledigt).
function erp_wa_aktiv(int $pa_id): bool {
    $s = one("SELECT station FROM produktion_schritt WHERE pa_id=? AND erledigt=0 ORDER BY sort LIMIT 1", [$pa_id]);
    return $s && in_array((string)$s['station'], erp_wa_stationen(), true);
}
// Material-Rollen des PA: item_id => rolle (bulk, glas, deckel, etikett, leerkapsel, karton, beipack).
function erp_wa_rollen(int $pa_id): array {
    $pa = one("SELECT pa.*, p.verpackung_id AS p_verp, p.verschluss_id, p.etikett_id, p.karton_id, p.beipack_id, p.leerkapsel_id, p.rezeptur_id AS p_rez,
                      a.etikett_item_id AS a_etikett, a.verpackung_id AS a_verp
               FROM produktionsauftrag pa LEFT JOIN produkt p ON p.id=pa.produkt_id LEFT JOIN auftrag a ON a.id=pa.auftrag_id WHERE pa.id=?", [$pa_id]);
    if (!$pa) return [];
    $r = [];
    foreach ([(int)($pa['verpackung_id'] ?: ($pa['a_verp'] ?: $pa['p_verp'])) => 'glas', (int)$pa['verschluss_id'] => 'deckel', (int)($pa['a_etikett'] ?: $pa['etikett_id']) => 'etikett',
              (int)$pa['karton_id'] => 'karton', (int)$pa['beipack_id'] => 'beipack', (int)$pa['leerkapsel_id'] => 'leerkapsel'] as $iid => $rolle)
        if ($iid > 0) $r[$iid] = $rolle;
    // Bulk: Fertigware-Artikel des Auftrags bzw. Bulk-Artikel der (Stamm-)Rezeptur
    $rid = (int)($pa['rezeptur_id'] ?: $pa['p_rez']);
    foreach (all("SELECT DISTINCT i.id FROM item i WHERE i.kategorie='fertig' AND (i.rezeptur_id=? OR i.id IN (SELECT c.item_id FROM charge c WHERE c.auftrag_id=?))", [$rid, (int)($pa['auftrag_id'] ?? 0)]) as $b)
        $r[(int)$b['id']] = 'bulk';
    // Kundenetiketten des Auftrags (gebundene Versionen)
    if (!empty($pa['auftrag_id']))
        foreach (all("SELECT DISTINCT c.item_id FROM charge c JOIN item i ON i.id=c.item_id WHERE c.auftrag_id=? AND i.verpackung_rolle='etikett'", [(int)$pa['auftrag_id']]) as $e) $r[(int)$e['item_id']] = 'etikett';
    return $r;
}
// Gescannten Code auflösen: Gebinde-QR (bg=), Charge-QR (id=), Inventaraufkleber, Chargennummer, Eimer/Sack (prod_charge).
// Rückgabe ['charge_id','gebinde_id','prod_charge_id','item_id','charge_nr','mhd','name'] oder null.
function erp_wa_scan(string $code): ?array {
    $q = trim($code);
    if ($q === '') return null;
    $c = null; $gid = 0; $pc = 0;
    if (preg_match('/[?&]bg=(\d+)/', $q, $m)) { $g = one("SELECT id, charge_id FROM charge_gebinde WHERE id=?", [(int)$m[1]]); if ($g) { $gid = (int)$g['id']; $c = (int)$g['charge_id']; } }
    elseif (preg_match('/[?&]id=(\d+)/', $q, $m)) $c = (int)$m[1];
    elseif (preg_match('/^\d{7,8}$/', $q) && tabelle_da('charge_gebinde')) { $g = one("SELECT id, charge_id FROM charge_gebinde WHERE inventar_nr=? ORDER BY (status<>'leer') DESC, id DESC LIMIT 1", [$q]); if ($g) { $gid = (int)$g['id']; $c = (int)$g['charge_id']; } }
    if (!$c && tabelle_da('prod_charge')) {   // Eimer/Sack-Etikett: QR = Nummer der Unter-Charge (CH-2690-A …)
        $p = one("SELECT id, nummer, sub_kennung, pa_id FROM prod_charge WHERE nummer=? OR CONCAT(nummer, COALESCE(sub_kennung,''))=? OR CONCAT(nummer,'-',COALESCE(sub_kennung,''))=? ORDER BY id DESC LIMIT 1", [$q, $q, $q]);
        if ($p) $pc = (int)$p['id'];
    }
    if (!$c && !$pc) { $x = one("SELECT id FROM charge WHERE charge_nr=? AND menge_verfuegbar>0 ORDER BY id DESC LIMIT 1", [$q]); if ($x) $c = (int)$x['id']; }
    if ($pc) { $p = one("SELECT pc.*, CONCAT(pc.nummer, COALESCE(pc.sub_kennung,'')) AS anzeige FROM prod_charge pc WHERE pc.id=?", [$pc]);
        return ['charge_id' => 0, 'gebinde_id' => 0, 'prod_charge_id' => $pc, 'item_id' => 0, 'charge_nr' => (string)$p['anzeige'], 'mhd' => '', 'name' => 'Eimer/Sack ' . $p['anzeige'], 'pa_id' => (int)$p['pa_id']]; }
    if (!$c) return null;
    $r = one("SELECT c.id, c.item_id, c.charge_nr, c.mhd, c.status, c.menge_verfuegbar, i.name FROM charge c JOIN item i ON i.id=c.item_id WHERE c.id=?", [$c]);
    return $r ? ['charge_id' => (int)$r['id'], 'gebinde_id' => $gid, 'prod_charge_id' => 0, 'item_id' => (int)$r['item_id'], 'charge_nr' => (string)($r['charge_nr'] ?: '#' . $r['id']),
                 'mhd' => (string)$r['mhd'], 'name' => (string)$r['name'], 'status' => (string)$r['status']] : null;
}
// Ältere freie Charge desselben Bulk-Artikels vorhanden (ältere zuerst, Handbuch 4.8)? Rückgabe Charge-Nr oder ''.
// 13.11.2026: Bulk ohne MHD wird nach Wareneingang verglichen (vorher: gar nicht geprüft).
function erp_wa_aeltere_bulk(int $item_id, int $charge_id, string $mhd): string {
    if ($mhd === '') {
        $we = (string) scalar("SELECT COALESCE(wareneingang, DATE(angelegt)) FROM charge WHERE id=?", [$charge_id]);
        if ($we === '') return '';
        $x = one("SELECT charge_nr FROM charge WHERE item_id=? AND id<>? AND status='frei' AND menge_verfuegbar>0 AND fremd_kunde_id IS NULL
                  AND COALESCE(wareneingang, DATE(angelegt)) < ? ORDER BY COALESCE(wareneingang, DATE(angelegt)) LIMIT 1", [$item_id, $charge_id, $we]);
        return $x ? (string)$x['charge_nr'] : '';
    }
    $x = one("SELECT charge_nr FROM charge WHERE item_id=? AND id<>? AND status='frei' AND menge_verfuegbar>0 AND fremd_kunde_id IS NULL AND mhd IS NOT NULL AND mhd<? ORDER BY mhd LIMIT 1", [$item_id, $charge_id, $mhd]);
    return $x ? (string)$x['charge_nr'] : '';
}
// Machbar-Stand aus dem Dashboard (pa_machbar, eine Rechnung) – fehlt er, die eigene Bereitschaft als Rückfall.
function erp_wa_machbar(int $pa_id): array {
    $m = tabelle_da('pa_machbar') ? one("SELECT * FROM pa_machbar WHERE pa_id=?", [$pa_id]) : null;
    if ($m) return ['machbar' => (int)$m['machbar'] === 1, 'stufe' => (string)$m['stufe'], 'grund' => (string)($m['grund'] ?? ''), 'etikett_gesperrt' => (int)$m['etikett_gesperrt'] === 1];
    $b = erp_pa_bereitschaft($pa_id);
    return ['machbar' => in_array((string)($b['status'] ?? ''), ['bereit', 'laeuft'], true), 'stufe' => (string)($b['status'] ?? ''), 'grund' => '', 'etikett_gesperrt' => erp_etikett_sperre($pa_id) !== ''];
}
// Tagesplan für die Werk-App: genau die Reihenfolge aus dem Reiter Plan (plan_sort), laufende zuerst. Nicht machbare grau mit Grund –
// ist der oberste blockiert, rückt der nächste machbare nach vorne (machbare vor gesperrten, sonst Plan-Reihenfolge).
function erp_wa_tagesplan(): array {
    $hatPlan = erp_spalte_da('produktionsauftrag', 'plan_sort');
    $rows = all("SELECT pa.id, pa.nummer, pa.menge, pa.status, pa.geplant_am, " . ($hatPlan ? "pa.plan_sort, pa.linie," : "NULL AS plan_sort, NULL AS linie,") . " pa.mitarbeiter_id,
                        b.name AS mitarbeiter, COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name, r.name) AS produkt, k.firma AS kunde,
                        (SELECT COALESCE(SUM(c.menge),0) FROM charge c WHERE c.pa_id=pa.id) AS produziert
                 FROM produktionsauftrag pa LEFT JOIN auftrag a ON a.id=pa.auftrag_id LEFT JOIN produkt p ON p.id=pa.produkt_id
                 LEFT JOIN rezeptur r ON r.id=pa.rezeptur_id LEFT JOIN kunden k ON k.id=pa.kunde_id LEFT JOIN benutzer b ON b.id=pa.mitarbeiter_id
                 WHERE pa.status IN ('offen','laufend') AND (a.id IS NULL OR a.status NOT IN ('storniert'))
                   AND EXISTS (SELECT 1 FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=0
                               AND s.station NOT IN ('Qualitätsprüfung','Produktions-Freigabe','Versand-Freigabe'))");   // Produktion fertig, nur noch QM -> nicht mehr im Werk
    $heute = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    foreach ($rows as &$r) { $r['_m'] = erp_wa_machbar((int)$r['id']); $r['_lauf'] = (string)$r['status'] === 'laufend'; $r['_heute'] = (string)($r['geplant_am'] ?? '') !== '' && (string)$r['geplant_am'] <= $heute; }
    unset($r);
    usort($rows, fn($a, $b) => [!$a['_lauf'], !$a['_m']['machbar'], $a['plan_sort'] === null, (int)$a['plan_sort'], (string)($a['geplant_am'] ?? '9999'), (int)$a['id']]
                          <=> [!$b['_lauf'], !$b['_m']['machbar'], $b['plan_sort'] === null, (int)$b['plan_sort'], (string)($b['geplant_am'] ?? '9999'), (int)$b['id']]);
    return $rows;
}
function erp_wa_tara(): array { return tabelle_da('lg_tara') ? all("SELECT id, name, tara_kg FROM lg_tara WHERE aktiv=1 ORDER BY sort, name") : []; }
// Kapseln je Packung + Kapsel-Bulk? (Gegenprobe Gläser × Kapseln je Glas gegen Kapselzahl aus dem Sackgewicht.)
function erp_wa_kapsel_info(int $pa_id): array {
    $r = one("SELECT COALESCE(p.einheiten_pro_packung, a.stueck, 0) AS je, r.darreichungsform AS df FROM produktionsauftrag pa LEFT JOIN produkt p ON p.id=pa.produkt_id
              LEFT JOIN auftrag a ON a.id=pa.auftrag_id LEFT JOIN rezeptur r ON r.id=COALESCE(pa.rezeptur_id, p.rezeptur_id) WHERE pa.id=?", [$pa_id]) ?: [];
    return ['je_packung' => (int)($r['je'] ?? 0), 'kapsel' => in_array((string)($r['df'] ?? ''), ['kapsel', 'softgel', 'tablette'], true)];
}
