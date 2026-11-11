<?php
// Naht der Inventur zum Dashboard (11.11.2026). Wie core/erp.php: ALLE Lese-Zugriffe der Inventur auf Dashboard-Tabellen
// (charge, charge_gebinde, item, rezeptur, kapselgroesse, kunden, benutzer) stehen hier. Gebucht wird nur in
// erp_inventur_buchen.php (Korrekturen nach Nicos Entscheidung). Siehe core/inventur.md.
require_once __DIR__ . '/erp.php';

// Warenarten der Inventur -> SQL-Bedingung auf item (Alias i). Etiketten sind Verpackung mit Rolle „etikett".
function erp_inv_warenarten(): array {
    return ['rohstoff' => 'Rohstoffe', 'fertig' => 'Bulk / lose', 'verpackung' => 'Verpackung', 'etikett' => 'Etiketten',
            'verkaufsfertig' => 'Fertigware', 'verbrauch' => 'Verbrauchsgüter'];
}
function erp_inv_warenart_sql(array $arten, string $i = 'i'): string {
    $arten = array_values(array_intersect($arten, array_keys(erp_inv_warenarten())));
    if (!$arten) return '1=1';
    $t = [];
    foreach ($arten as $a) {
        if ($a === 'etikett')        $t[] = "($i.kategorie='verpackung' AND $i.verpackung_rolle='etikett')";
        elseif ($a === 'verpackung') $t[] = "($i.kategorie='verpackung' AND COALESCE($i.verpackung_rolle,'')<>'etikett')";
        else                          $t[] = "$i.kategorie='" . $a . "'";
    }
    return '(' . implode(' OR ', $t) . ')';
}

// Gemeinsame Spalten eines Zählobjekts (Gebinde g bzw. Charge c ohne aktive Gebinde).
function erp_inv_spalten(): string {
    return "c.id AS charge_id, c.charge_nr, c.mhd, c.status AS charge_status, c.fremd_kunde_id, c.einheit AS charge_einheit,
            c.menge_verfuegbar AS charge_verfuegbar, c.menge AS charge_menge, c.kapselgroesse_id AS c_kg,
            i.id AS item_id, i.name AS item_name, i.einheit AS item_einheit, i.kategorie, i.verpackung_rolle, i.form,
            i.ek_preis, i.preis_bezug, i.rezeptur_id";
}

// Alle Zählobjekte im Umfang einer Inventur mit dem AKTUELLEN Buchbestand (Soll). Gebinde mit Bestand sind je ein Objekt;
// Chargen ohne aktives Gebinde zählen als ein Objekt. Ware in der Produktion (standort=produktion) liegt nicht im Lager.
function erp_inv_objekte(array $inv): array {
    if (!tabelle_da('charge')) return [];
    $w = [erp_pk_wo('c'), erp_inv_warenart_sql(array_filter(explode(',', (string)$inv['warenarten'])))];
    $p = [];
    if ((string)$inv['lager'] === '2') { $w[] = 'c.fremd_kunde_id IS NOT NULL'; if ((int)$inv['kunde_id'] > 0) { $w[] = 'c.fremd_kunde_id=?'; $p[] = (int)$inv['kunde_id']; } }
    else $w[] = 'c.fremd_kunde_id IS NULL';
    $wo = implode(' AND ', $w);
    $out = [];
    if (tabelle_da('charge_gebinde')) {
        foreach (all("SELECT 'g' AS obj, g.id AS gebinde_id, g.nummer AS g_nr, g.anzahl_gesamt AS g_von, g.menge AS g_menge, g.menge_verfuegbar AS soll,
                             COALESCE(NULLIF(g.einheit,''), c.einheit) AS einheit, g.inventar_nr, " . erp_inv_spalten() . "
                      FROM charge_gebinde g JOIN charge c ON c.id=g.charge_id JOIN item i ON i.id=c.item_id
                      WHERE g.status<>'leer' AND g.menge_verfuegbar>0 AND COALESCE(g.standort,'lager1')<>'produktion' AND $wo", $p) as $r) $out[] = $r;
    }
    $ohneGeb = tabelle_da('charge_gebinde') ? "AND NOT EXISTS (SELECT 1 FROM charge_gebinde gx WHERE gx.charge_id=c.id AND gx.status<>'leer' AND gx.menge_verfuegbar>0)" : '';
    foreach (all("SELECT 'c' AS obj, 0 AS gebinde_id, NULL AS g_nr, NULL AS g_von, NULL AS g_menge, c.menge_verfuegbar AS soll, c.einheit, NULL AS inventar_nr, " . erp_inv_spalten() . "
                  FROM charge c JOIN item i ON i.id=c.item_id
                  WHERE c.menge_verfuegbar>0 AND COALESCE(c.status,'')<>'leer' AND COALESCE(c.standort,'lager1')<>'produktion' $ohneGeb AND $wo", $p) as $r) $out[] = $r;
    return $out;
}
// Ein Zählobjekt (auch leer/außerhalb des Umfangs) mit aktuellem Soll. $obj = 'g' (Gebinde-ID) oder 'c' (Charge-ID).
function erp_inv_objekt(string $obj, int $id): ?array {
    if ($id <= 0) return null;
    if ($obj === 'g') {
        if (!tabelle_da('charge_gebinde')) return null;
        return one("SELECT 'g' AS obj, g.id AS gebinde_id, g.nummer AS g_nr, g.anzahl_gesamt AS g_von, g.menge AS g_menge, g.menge_verfuegbar AS soll,
                           COALESCE(NULLIF(g.einheit,''), c.einheit) AS einheit, g.inventar_nr, g.status AS g_status, g.standort AS g_standort, " . erp_inv_spalten() . "
                    FROM charge_gebinde g JOIN charge c ON c.id=g.charge_id JOIN item i ON i.id=c.item_id WHERE g.id=?", [$id]);
    }
    return one("SELECT 'c' AS obj, 0 AS gebinde_id, NULL AS g_nr, NULL AS g_von, NULL AS g_menge, c.menge_verfuegbar AS soll, c.einheit, NULL AS inventar_nr, " . erp_inv_spalten() . "
                FROM charge c JOIN item i ON i.id=c.item_id WHERE c.id=?", [$id]);
}
// Aktive Gebinde einer Charge (zum Auswählen, wenn das Karton-Etikett der Charge gescannt wird). Ohne Mengen (blind).
function erp_inv_gebinde_der_charge(int $charge_id): array {
    if (!tabelle_da('charge_gebinde')) return [];
    return all("SELECT id, nummer, anzahl_gesamt, inventar_nr, status FROM charge_gebinde WHERE charge_id=? ORDER BY nummer, id", [$charge_id]);
}
// Gescannten Code auflösen: Gebinde-QR (bg=), Charge-QR (?p=charge&id=), Inventaraufkleber (7/8 Ziffern), alter Gebinde-Code
// (GB-…), Blinker-Code. Rückgabe ['obj'=>'g'|'c', 'id'=>int] oder null. Plätze/Kisten/Klärfälle löst core/inventur.php auf.
function erp_inv_scan(string $code): ?array {
    $q = trim($code);
    if ($q === '') return null;
    if (preg_match('/[?&]bg=(\d+)/i', $q, $m) && erp_inv_objekt('g', (int)$m[1])) return ['obj' => 'g', 'id' => (int)$m[1]];
    if (preg_match('/[?&]nr=(GB-[0-9A-Z]+)/i', $q, $m) || preg_match('/^(GB-[0-9A-Z]+)$/i', $q, $m)) {
        $cid = (int) scalar("SELECT charge_id FROM lg_gebinde WHERE nummer=?", [strtoupper($m[1])]);
        if ($cid > 0) return ['obj' => 'c', 'id' => $cid];
    }
    if (preg_match('/[?&]p=charge\b.*[?&]id=(\d+)/i', $q, $m) || preg_match('/[?&]id=(\d+)/i', $q, $m) || preg_match('~charge[\/=](\d+)~i', $q, $m)) {
        if (erp_inv_objekt('c', (int)$m[1])) return ['obj' => 'c', 'id' => (int)$m[1]];
    }
    $inv = erp_inventar_norm($q);
    if ($inv !== '' && tabelle_da('charge_gebinde')) {
        $gid = (int) scalar("SELECT id FROM charge_gebinde WHERE inventar_nr=? ORDER BY (status<>'leer') DESC, id DESC LIMIT 1", [$inv]);
        if ($gid > 0) return ['obj' => 'g', 'id' => $gid];
    }
    if (function_exists('led_leiste_normalisieren') && function_exists('leiste_per_code')) {
        $lc = led_leiste_normalisieren($q);
        $l = $lc ? leiste_per_code($lc) : null;
        if ($l && !empty($l['charge_id'])) return ['obj' => 'c', 'id' => (int)$l['charge_id']];
    }
    return null;
}
// Artikel für „Gefunden ohne Etikett": Suche mit Synonymen, Kapselgröße und Füllgewicht sichtbar (wie Wareneingang).
function erp_inv_artikel(): array {
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'einheit' => (string)$r['einheit'],
        'kat' => (string)$r['kategorie'], 'syn' => (string)($r['syn'] ?? ''), 'kapsel' => (string)($r['kapsel'] ?? '')], erp_items_eingang());
}
function erp_inv_item(int $id): ?array {
    return $id > 0 ? one("SELECT id, name, einheit, kategorie, verpackung_rolle, form, ek_preis, preis_bezug, rezeptur_id, kapselgroesse_id FROM item WHERE id=?", [$id]) : null;
}
// Gewicht einer Kapsel in mg (Füllgewicht der Rezeptur + Leerkapsel) für „Bulk-Sack wiegen" - wie in der Produktion (4.8).
// 0 = unbekannt (dann ist es keine Kapsel-Bulkware bzw. es muss eingegeben werden).
function erp_inv_kapsel_mg(int $item_id, int $charge_id = 0): float {
    $r = one("SELECT r.einheit_fuellmenge AS ff, (SELECT COALESCE(SUM(z.menge_mg),0) FROM rezeptur_zutat z WHERE z.rezeptur_id=r.id) AS zut, r.darreichungsform AS df,
                     COALESCE(kc.leergewicht_mg, ki.leergewicht_mg, kr.leergewicht_mg) AS leer
              FROM item i LEFT JOIN rezeptur r ON r.id=i.rezeptur_id
              LEFT JOIN charge c ON c.id=? LEFT JOIN kapselgroesse kc ON kc.id=c.kapselgroesse_id
              LEFT JOIN kapselgroesse ki ON ki.id=i.kapselgroesse_id LEFT JOIN kapselgroesse kr ON kr.id=r.kapselgroesse_id
              WHERE i.id=?", [$charge_id, $item_id]);
    if (!$r || !in_array((string)$r['df'], ['kapsel', 'softgel'], true)) return 0.0;
    // Füllgewicht je Kapsel: einheit_fuellmenge (Altdaten in g, sonst mg), sonst Summe der Rezepturzeilen (mg je Einheit).
    $ff = (float)($r['ff'] ?? 0);
    $fuell = $ff > 0 ? ($ff < 5 ? $ff * 1000 : $ff) : (float)$r['zut'];
    if ($fuell <= 0) return 0.0;
    return round($fuell + (float)($r['leer'] ?? 0), 2);
}
// Wert in € einer Menge zum EK (item.ek_preis je preis_bezug). null = nicht umrechenbar (z. B. Stk gegen kg).
function erp_inv_wert(array $o, float $menge, string $einheit): ?float {
    $ek = (float)($o['ek_preis'] ?? 0);
    if ($ek <= 0) return null;
    $bezug = erp_einheit_norm((string)($o['preis_bezug'] ?? '')) ?: erp_einheit_norm((string)($o['item_einheit'] ?? ''));
    $e = erp_einheit_norm($einheit);
    $f = ['kg' => 1000.0, 'g' => 1.0, 'mg' => 0.001];
    if ($e === $bezug) return round($menge * $ek, 2);
    if (isset($f[$e], $f[$bezug])) return round($menge * $f[$e] / $f[$bezug] * $ek, 2);
    return null;
}
// Nächste freie 8-stellige Inventarnummer für ein Gebinde ohne Etikett (Klärfall). Berücksichtigt auch schon für
// Klärfälle reservierte Nummern (die Gebinde entstehen erst beim Buchen). Unter derselben Sperre wie der Wareneingang.
function erp_inv_inventar_reservieren(): string {
    $lock = (int) scalar("SELECT GET_LOCK('bx_inventar_nr', 5)") === 1;
    try {
        $benutzt = [];
        foreach (all("SELECT kf_inventar_nr FROM lg_inventur_zaehlung WHERE kf_inventar_nr IS NOT NULL") as $r) $benutzt[(string)$r['kf_inventar_nr']] = 1;
        $max = $benutzt ? max(array_map('intval', array_keys($benutzt))) : 0;
        $nr = erp_inventar_naechste($benutzt);
        if ((int)$nr <= $max && $max >= 90000000) { for ($n = $max + 1; $n <= 99999999; $n++) if (erp_inventar_frei((string)$n)) { $nr = (string)$n; break; } }
        return $nr;
    } finally { if ($lock) scalar("SELECT RELEASE_LOCK('bx_inventar_nr')"); }
}
// Zähler-Auswahl: aktive Mitarbeiter mit Lager-Rolle bzw. Admin.
function erp_inv_zaehler_auswahl(): array {
    if (!tabelle_da('benutzer')) return [];
    return array_values(array_filter(all("SELECT id, name, rollen FROM benutzer WHERE aktiv=1 ORDER BY name"),
        fn($u) => (bool) array_intersect(['admin', 'fulfillment', 'lager', 'production'], array_map('trim', explode(',', (string)$u['rollen'])))));
}
function erp_inv_benutzer_name(int $id): string {
    return $id > 0 ? (string) scalar("SELECT name FROM benutzer WHERE id=?", [$id]) : '';
}
function erp_inv_kunde_name(int $id): string {
    return $id > 0 && tabelle_da('kunden') ? (string) scalar("SELECT firma FROM kunden WHERE id=?", [$id]) : '';
}
