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
// Machbar FRISCH (13.11.2026, Werk-App Teil 1.5): dieselbe Rechnung wie der Plan – einmal je Seitenaufruf per Loopback ans Dashboard
// (?p=api_pa_machbar, produktion_machbar_schritte für alle offenen PAs). Ein Wareneingang macht einen PA damit sofort sichtbar.
// Fällt der Aufruf aus, gilt der zuletzt gespeicherte Stand (pa_machbar).
// Fable-5 T2.5 (13.11.2026): Fällt der Loopback aus (Zeitgrenze 8 s, HTML, HTTP-Fehler), gilt der gespeicherte Stand (pa_machbar) –
// sichtbar als „Stand von HH:MM“ ($GLOBALS['wa_stand']) und mit Protokolleintrag (höchstens alle 10 Minuten).
function erp_wa_machbar_alle(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $j = erp_dashboard_api('api_pa_machbar', [], 8);
    if (is_array($j) && !empty($j['ok']) && is_array($j['pa'] ?? null)) {
        foreach ($j['pa'] as $id => $m) $cache[(int)$id] = ['machbar' => !empty($m['machbar']), 'stufe' => (string)($m['stufe'] ?? ''), 'grund' => (string)($m['grund'] ?? ''), 'etikett_gesperrt' => !empty($m['etikett_gesperrt'])];
        if (!empty($j['fehler'])) erp_wa_loopback_protokoll('Machbar-Rechnung: ' . (int)$j['fehler'] . ' Auftrag/Aufträge mit Fehler, Rest frisch.');
    } else {
        $GLOBALS['wa_stand'] = tabelle_da('pa_machbar') ? (string) scalar("SELECT MAX(berechnet) FROM pa_machbar") : '';
        erp_wa_loopback_protokoll('Machbar-Rechnung nicht erreichbar – Werk-App zeigt den gespeicherten Stand' . ($GLOBALS['wa_stand'] !== '' ? ' vom ' . $GLOBALS['wa_stand'] . ' UTC' : '') . '.');
    }
    return $cache;
}
function erp_wa_loopback_protokoll(string $txt): void {
    error_log('[werk-app] ' . $txt);
    try {
        if (!tabelle_da('app_meta')) return;
        $letzt = (string) scalar("SELECT v FROM app_meta WHERE k='wa_loopback_log'");
        if ($letzt !== '' && strtotime($letzt . ' UTC') > time() - 600) return;
        q("INSERT INTO app_meta (k,v) VALUES ('wa_loopback_log', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [gmdate('Y-m-d H:i:s')]);
        if (tabelle_da('aktivitaet')) q("INSERT INTO aktivitaet (objekt_typ, objekt_id, akteur, typ, text, erstellt) VALUES ('system', 0, 'system', 'notiz', ?, ?)", [mb_substr('Werk-App: ' . $txt, 0, 500), gmdate('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {}
}
// „Stand von HH:MM“ (Europe/Berlin) für die Anzeige, '' wenn der Stand frisch ist.
function erp_wa_stand_text(): string {
    $s = (string)($GLOBALS['wa_stand'] ?? '');
    if ($s === '') return '';
    try { return (new DateTime($s . ' UTC'))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i'); } catch (\Throwable $e) { return ''; }
}
// Machbar-Stand eines PA: frisch (s. o.), sonst pa_machbar, sonst die eigene Bereitschaft als Rückfall.
// $frisch=false (Buchungen, Fable-5 T2.5): nur der gespeicherte Stand – höchstens eine Neuberechnung je Buchung (die Seite danach).
function erp_wa_machbar(int $pa_id, bool $frisch_holen = true): array {
    $frisch = $frisch_holen ? erp_wa_machbar_alle() : [];
    if (isset($frisch[$pa_id])) return $frisch[$pa_id];
    $m = tabelle_da('pa_machbar') ? one("SELECT * FROM pa_machbar WHERE pa_id=?", [$pa_id]) : null;
    if ($m) return ['machbar' => (int)$m['machbar'] === 1, 'stufe' => (string)$m['stufe'], 'grund' => (string)($m['grund'] ?? ''), 'etikett_gesperrt' => (int)$m['etikett_gesperrt'] === 1];
    $b = erp_pa_bereitschaft($pa_id);
    return ['machbar' => in_array((string)($b['status'] ?? ''), ['bereit', 'laeuft'], true), 'stufe' => (string)($b['status'] ?? ''), 'grund' => '', 'etikett_gesperrt' => erp_etikett_sperre($pa_id) !== ''];
}
// Sieht die Werk-App diesen PA? (13.11.2026, Teil 1) '' = ja, sonst der Grund: 'status' (nicht offen/laufend), 'fertig' (produziert ≥ Soll),
// 'nicht_machbar' (bleibt nur im Dashboard-Plan grau mit Grund), 'datum' (Plan-Datum liegt in der Zukunft, Europe/Berlin; laufende immer).
// Fable-5 T2.1: Eine laufende Unter-Charge bzw. ein laufender PA (pa_ist_laufend) ist IMMER sichtbar und buchbar – „machbar“ entscheidet nur
// über den Start eines noch nicht begonnenen PA.
function erp_wa_sichtbar_grund(array $pa, array $m): string {
    $st = (string)($pa['status'] ?? '');
    if (!in_array($st, ['offen', 'laufend'], true)) return 'status';
    if ((int)($pa['uc_laeuft'] ?? 0) > 0) return '';
    if ((float)($pa['menge'] ?? 0) > 0 && (float)($pa['produziert'] ?? 0) + 1e-6 >= (float)$pa['menge']) return 'fertig';
    if (pa_ist_laufend($st, (float)($pa['produziert'] ?? 0))) return '';
    if (empty($m['machbar'])) return 'nicht_machbar';
    $d = (string)($pa['geplant_am'] ?? '');
    if ($st !== 'laufend' && $d !== '' && $d > heute_ort()) return 'datum';
    return '';
}
function erp_wa_pa_sichtbar(int $pa_id, bool $frisch = true): string {
    $pa = one("SELECT pa.status, pa.menge, pa.geplant_am, " . pa_produziert_sql('pa.id') . " AS produziert,
                      (SELECT COUNT(*) FROM pa_unter_charge u WHERE u.pa_id=pa.id AND u.status='laeuft') AS uc_laeuft FROM produktionsauftrag pa WHERE pa.id=?", [$pa_id]);
    return $pa ? erp_wa_sichtbar_grund($pa, erp_wa_machbar($pa_id, $frisch)) : 'status';
}
// Tagesplan für die Werk-App (13.11.2026, Teil 1): NUR was wirklich produziert werden kann – laufende mit offenem Rest, startklare und
// „bis vor Etikettieren“, in der Plan-Reihenfolge (plan_sort, dann Datum). Nicht machbare, voll produzierte und künftig geplante nie.
function erp_wa_tagesplan(): array {
    $hatPlan = erp_spalte_da('produktionsauftrag', 'plan_sort');
    $rows = all("SELECT pa.id, pa.nummer, pa.menge, pa.status, pa.geplant_am, " . ($hatPlan ? "pa.plan_sort, pa.linie," : "NULL AS plan_sort, NULL AS linie,") . " pa.mitarbeiter_id,
                        b.name AS mitarbeiter, COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name, r.name) AS produkt, k.firma AS kunde,
                        " . pa_produziert_sql('pa.id') . " AS produziert,
                        (SELECT COUNT(*) FROM pa_unter_charge u WHERE u.pa_id=pa.id AND u.status='laeuft') AS uc_laeuft
                 FROM produktionsauftrag pa LEFT JOIN auftrag a ON a.id=pa.auftrag_id LEFT JOIN produkt p ON p.id=pa.produkt_id
                 LEFT JOIN rezeptur r ON r.id=pa.rezeptur_id LEFT JOIN kunden k ON k.id=pa.kunde_id LEFT JOIN benutzer b ON b.id=pa.mitarbeiter_id
                 WHERE pa.status IN ('offen','laufend') AND (a.id IS NULL OR a.status NOT IN ('storniert'))
                   AND EXISTS (SELECT 1 FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=0
                               AND s.station NOT IN ('Qualitätsprüfung','Produktions-Freigabe','Versand-Freigabe'))");   // Produktion fertig, nur noch QM -> nicht mehr im Werk
    $heute = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    $sicht = [];
    foreach ($rows as $r) {
        $r['_m'] = erp_wa_machbar((int)$r['id']); $r['_lauf'] = pa_ist_laufend((string)$r['status'], (float)$r['produziert']) || (int)$r['uc_laeuft'] > 0; $r['_heute'] = (string)($r['geplant_am'] ?? '') !== '' && (string)$r['geplant_am'] <= $heute;
        if (erp_wa_sichtbar_grund($r, $r['_m']) === '') $sicht[] = $r;
    }
    // 13.11.2026: laufende PAs zuerst (untereinander plan_sort, dann Startzeit), danach die übrigen in Plan-Reihenfolge.
    // Laufende stehen nicht im Reiter „Plan“ und lassen sich dort nicht ziehen – sonst rutschten sie ganz nach unten.
    return pa_reihenfolge_laufend_zuerst($sicht, fn($r, $t) => !empty($r['_lauf']));   // Fable-5 T2.4: eine Regel „laufend“
}
function erp_wa_tara(): array { return tabelle_da('lg_tara') ? all("SELECT id, name, tara_kg FROM lg_tara WHERE aktiv=1 ORDER BY sort, name") : []; }
// Kapseln je Packung + Kapsel-Bulk? (Gegenprobe Gläser × Kapseln je Glas gegen Kapselzahl aus dem Sackgewicht.)
function erp_wa_kapsel_info(int $pa_id): array {
    $r = one("SELECT COALESCE(p.einheiten_pro_packung, a.stueck, 0) AS je, r.darreichungsform AS df FROM produktionsauftrag pa LEFT JOIN produkt p ON p.id=pa.produkt_id
              LEFT JOIN auftrag a ON a.id=pa.auftrag_id LEFT JOIN rezeptur r ON r.id=COALESCE(pa.rezeptur_id, p.rezeptur_id) WHERE pa.id=?", [$pa_id]) ?: [];
    return ['je_packung' => (int)($r['je'] ?? 0), 'kapsel' => in_array((string)($r['df'] ?? ''), ['kapsel', 'softgel', 'tablette'], true)];
}
