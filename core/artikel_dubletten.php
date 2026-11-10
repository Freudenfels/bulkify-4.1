<?php
// Artikel-Dubletten erkennen und zusammenführen (Einkauf gerade ziehen, Teil 4 + 5, 10.11.2026). Dashboard-Datei (braucht
// core/schema.php). Regel (Nico): gleiche Qualität/Spezifikation von verschiedenen Lieferanten = EIN Artikel mit mehreren
// Lieferantenpreisen. Andere Spezifikation (13 % vs. 20 % Mg, Bio vs. konventionell, anderer Extraktgehalt) = eigener Artikel.
// Bulk-Kapseln, Premixe und Fertigware mit anderer Kapselgröße, anderem Füllgewicht oder Kapselmaterial sind IMMER eigene
// Artikel – Zusammenführen ist dann gesperrt. Zusammengeführt wird nur auf Klick (Nico wählt den Hauptartikel); der alte
// Artikel wird gesperrt (zusammengefuehrt_in), nichts wird gelöscht, sein Name bleibt als Synonym am Hauptartikel.

// Normalform eines Artikelnamens für die Gruppierung (ohne Zahlen/Gehalt/Bio – die stehen im Vergleich daneben).
function artikel_norm_key(string $name, string $kategorie = ''): string {
    $s = mb_strtolower(trim($name), 'UTF-8');
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'µ' => 'u']);
    $s = preg_replace('/^test-[a-z0-9]+\s+/u', '', $s);                 // Testkopie: „TEST-A …"
    $s = preg_replace('/\bvitamin\s*-?\s*([a-z])(\d{0,2})\b/u', 'vitamin$1$2', $s);
    $s = preg_replace('/\bl-(?=[a-z])/u', '', $s);                     // L-Ascorbinsäure, L-Carnitin
    $verp = $kategorie === 'verpackung';
    $tok = preg_split('/[^a-z0-9%]+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
    $stopp = ['bulk', 'lose', 'loses', 'pulver', 'powder', 'rohstoff', 'premium', 'kapsel', 'kapseln', 'capsule', 'capsules', 'tablette', 'tabletten',
              'gr', 'groesse', 'size', 'mg', 'g', 'kg', 'ml', 'l', 'mit', 'und', 'and', 'the', 'of', 'von', 'x', 'bio', 'organic', 'oeko', 'stk', 'je', 'pro'];
    $t = [];
    foreach ($tok as $w) {
        if (!$verp && (preg_match('/^\d+([.,]\d+)?%?$/', $w) || str_ends_with($w, '%'))) continue;
        if (in_array($w, $stopp, true)) continue;
        $t[] = $w;
    }
    $k = implode('', $t);
    $syn = ['lascorbinsaeure' => 'vitaminc', 'ascorbinsaeure' => 'vitaminc', 'ascorbicacid' => 'vitaminc',
            'bisglycinate' => 'glycinat', 'bisglycinat' => 'glycinat', 'diglycinat' => 'glycinat', 'glycinate' => 'glycinat',
            'creatine' => 'kreatin', 'creatin' => 'kreatin', 'zinc' => 'zink', 'citrate' => 'citrat', 'gluconate' => 'gluconat',
            'magnesiummagnesium' => 'magnesium'];
    $k = strtr($k, $syn);
    $k = preg_replace('/(vitaminc){2,}/', 'vitaminc', $k);
    return $k;
}

// Spezifikation aus dem Namen: Gehalt in % (z. B. „20 % Mg", „95 % Withanolide") und Bio.
function artikel_spec(string $name): array {
    $n = mb_strtolower($name, 'UTF-8');
    preg_match_all('/(\d+(?:[.,]\d+)?)\s*%/u', $n, $m);
    $geh = array_values(array_unique(array_map(fn($x) => str_replace('.', ',', $x) . ' %', $m[1] ?? [])));
    return ['gehalt' => implode(', ', $geh), 'bio' => (bool) preg_match('/\b(bio|organic|öko|oeko)\b/u', $n)];
}

// Vergleichsdaten eines Artikels: Spec, Kapselgröße/Füllgewicht/Material, Lieferanten, Bestand, Verwendung.
function artikel_info(int $id): ?array {
    $i = one("SELECT i.id, i.name, i.kategorie, i.einheit, i.form, i.material, i.rezeptur_id, i.kapselgroesse_id, i.gesperrt, i.artikelnummer,
                     COALESCE(i.kapselgroesse_id, r.kapselgroesse_id) AS kg_id, COALESCE(kgi.name, kgr.name) AS kg_name,
                     IF(r.darreichungsform IN ('kapsel','softgel','tablette'), r.einheit_fuellmenge, NULL) AS fuell_mg, r.name AS rezeptur_name
              FROM item i LEFT JOIN rezeptur r ON r.id=i.rezeptur_id
              LEFT JOIN kapselgroesse kgi ON kgi.id=i.kapselgroesse_id LEFT JOIN kapselgroesse kgr ON kgr.id=r.kapselgroesse_id
              WHERE i.id=?", [$id]);
    if (!$i) return null;
    $i += artikel_spec((string)$i['name']);
    $i['kapsel'] = webe_kapsel_label($id);
    $i['lieferanten'] = array_values(array_unique(array_merge(
        array_column(all("SELECT DISTINCT l.firma FROM lieferant_preis lp JOIN lieferanten l ON l.id=lp.lieferant_id WHERE lp.item_id=?", [$id]), 'firma'),
        array_column(all("SELECT DISTINCT l.firma FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id JOIN lieferanten l ON l.id=b.lieferant_id WHERE bp.item_id=?", [$id]), 'firma'),
        array_column(all("SELECT DISTINCT l.firma FROM charge c JOIN lieferanten l ON l.id=c.lieferant_id WHERE c.item_id=?", [$id]), 'firma'))));
    $i['bestand'] = (float) scalar("SELECT COALESCE(SUM(menge_verfuegbar),0) FROM charge WHERE item_id=? AND fremd_kunde_id IS NULL", [$id]);
    $i['n_chargen'] = (int) scalar("SELECT COUNT(*) FROM charge WHERE item_id=?", [$id]);
    $i['n_rezepturen'] = (int) scalar("SELECT COUNT(DISTINCT rezeptur_id) FROM rezeptur_zutat WHERE item_id=?", [$id]);
    $i['n_bestellungen'] = (int) scalar("SELECT COUNT(DISTINCT bestellung_id) FROM bestellung_position WHERE item_id=?", [$id]);
    $i['n_produkte'] = (int) scalar("SELECT COUNT(*) FROM produkt WHERE ? IN (COALESCE(verpackung_id,0),COALESCE(verschluss_id,0),COALESCE(etikett_id,0),COALESCE(karton_id,0),COALESCE(beipack_id,0),COALESCE(leerkapsel_id,0))", [$id]);
    return $i;
}

// Gründe, warum $quelle NICHT in $haupt zusammengeführt werden darf (leer = erlaubt).
function artikel_merge_sperren(array $haupt, array $quelle): array {
    $g = [];
    if ((int)$haupt['id'] === (int)$quelle['id']) $g[] = 'derselbe Artikel';
    if ((string)$haupt['einheit'] !== (string)$quelle['einheit']) $g[] = 'andere Einheit (' . $quelle['einheit'] . ' statt ' . $haupt['einheit'] . ')';
    if ((string)$haupt['kategorie'] !== (string)$quelle['kategorie']) $g[] = 'andere Warenart (' . kategorie_label((string)$quelle['kategorie']) . ' statt ' . kategorie_label((string)$haupt['kategorie']) . ')';
    if ((int)($haupt['kg_id'] ?? 0) !== (int)($quelle['kg_id'] ?? 0) && ((int)($haupt['kg_id'] ?? 0) || (int)($quelle['kg_id'] ?? 0)))
        $g[] = 'andere Kapselgröße (' . ((string)($quelle['kg_name'] ?? '') ?: 'keine') . ' statt ' . ((string)($haupt['kg_name'] ?? '') ?: 'keine') . ')';
    if (abs((float)($haupt['fuell_mg'] ?? 0) - (float)($quelle['fuell_mg'] ?? 0)) > 0.05) $g[] = 'anderes Füllgewicht';
    if (mb_strtolower(trim((string)($haupt['material'] ?? ''))) !== mb_strtolower(trim((string)($quelle['material'] ?? '')))
        && trim((string)($haupt['material'] ?? '')) !== '' && trim((string)($quelle['material'] ?? '')) !== '') $g[] = 'anderes Kapselmaterial';
    if (!empty($haupt['rezeptur_id']) && !empty($quelle['rezeptur_id']) && (int)$haupt['rezeptur_id'] !== (int)$quelle['rezeptur_id']) $g[] = 'Bulk/Premix verschiedener Rezepturen';
    if ((string)($haupt['gehalt'] ?? '') !== (string)($quelle['gehalt'] ?? '')) $g[] = 'anderer Gehalt (' . (($quelle['gehalt'] ?? '') ?: 'ohne Angabe') . ' statt ' . (($haupt['gehalt'] ?? '') ?: 'ohne Angabe') . ')';
    if ((bool)($haupt['bio'] ?? false) !== (bool)($quelle['bio'] ?? false)) $g[] = 'Bio und konventionell';
    $beide = (int) scalar("SELECT COUNT(DISTINCT a.rezeptur_id) FROM rezeptur_zutat a JOIN rezeptur_zutat b ON b.rezeptur_id=a.rezeptur_id WHERE a.item_id=? AND b.item_id=?", [(int)$haupt['id'], (int)$quelle['id']]);
    if ($beide > 0) $g[] = $beide . ' Rezeptur(en) enthalten beide Artikel';
    return $g;
}

// Gruppen ähnlicher Artikel (mind. 2 aktive Artikel derselben Warenart mit gleicher Normalform). Rückgabe [kategorie|key => [item-ids]].
function artikel_dubletten_gruppen(): array {
    $gr = [];
    foreach (all("SELECT id, name, kategorie FROM item WHERE COALESCE(gesperrt,0)=0 AND kategorie IN ('rohstoff','fertig','verkaufsfertig','verpackung','verbrauch')") as $r) {
        $k = artikel_norm_key((string)$r['name'], (string)$r['kategorie']);
        if (mb_strlen($k) < 4) continue;
        $gr[(string)$r['kategorie'] . '|' . $k][] = (int)$r['id'];   // je Warenart: Bulk 0/00 stehen zusammen, Rohstoff getrennt
    }
    return array_filter($gr, fn($ids) => count($ids) >= 2);
}

// Aktive Artikel mit derselben Normalform wie $item_id (für „ähnlicher Artikel", Teil 4).
function artikel_aehnliche(int $item_id): array {
    static $idx = null;
    if ($idx === null) {
        $idx = ['key' => [], 'ids' => []];
        foreach (all("SELECT id, name, kategorie FROM item") as $r) {
            $k = artikel_norm_key((string)$r['name'], (string)$r['kategorie']);
            $idx['key'][(int)$r['id']] = $k;
            if (mb_strlen($k) >= 4) $idx['ids'][$k][] = (int)$r['id'];
        }
    }
    $k = $idx['key'][$item_id] ?? '';
    $ids = $k !== '' ? ($idx['ids'][$k] ?? []) : [];
    // auch zusammengeführte Artikel (Quelle <-> Ziel)
    foreach (all("SELECT id FROM item WHERE zusammengefuehrt_in=? UNION SELECT zusammengefuehrt_in FROM item WHERE id=? AND zusammengefuehrt_in IS NOT NULL", [$item_id, $item_id]) as $z) $ids[] = (int)$z['id'];
    return array_values(array_unique(array_diff($ids, [$item_id])));
}

// Treffer für „Meintest du …?" beim Anlegen eines neuen Artikels (gleiche Normalform oder Name enthält den Kern).
function artikel_dubletten_treffer(string $name, string $kategorie = '', int $limit = 8): array {
    $k = artikel_norm_key($name, $kategorie);
    if (mb_strlen($k) < 4) return [];
    $out = [];
    foreach (all("SELECT id, name, kategorie, einheit FROM item WHERE COALESCE(gesperrt,0)=0") as $r) {
        $rk = artikel_norm_key((string)$r['name'], (string)$r['kategorie']);
        if ($rk === $k || (mb_strlen($k) >= 6 && mb_strlen($rk) >= 6 && (str_contains($rk, $k) || str_contains($k, $rk)))) {
            $r['kapsel'] = webe_kapsel_label((int)$r['id']);
            $out[] = $r;
            if (count($out) >= $limit) break;
        }
    }
    return $out;
}

// Zusammenführen (nur auf Klick): alle Bezüge von $quelle auf $ziel, Quelle sperren, Name als Synonym ans Ziel. Rückgabe
// ['ok'=>bool, 'fehler'=>string, 'zahlen'=>[tabelle=>anzahl]].
function artikel_zusammenfuehren_voll(int $quelle_id, int $ziel_id, string $wer = ''): array {
    $h = artikel_info($ziel_id); $q = artikel_info($quelle_id);
    if (!$h || !$q) return ['ok' => false, 'fehler' => 'Artikel nicht gefunden.', 'zahlen' => []];
    if (!empty($q['gesperrt'])) return ['ok' => false, 'fehler' => '„' . $q['name'] . '“ ist schon gesperrt/zusammengeführt.', 'zahlen' => []];
    $sp = artikel_merge_sperren($h, $q);
    if ($sp) return ['ok' => false, 'fehler' => 'Gesperrt: ' . implode(', ', $sp) . '.', 'zahlen' => []];
    $z = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ziele = [
            ['charge', 'item_id'], ['reservierung', 'item_id'], ['bestellung_position', 'item_id'], ['rezeptur_zutat', 'item_id'],
            ['lieferant_preis', 'item_id'], ['lieferant_katalog', 'item_id'], ['lieferant_anfrage', 'item_id'], ['produktion_verbrauch', 'item_id'],
            ['prod_charge_rohstoff', 'item_id'], ['prod_probe', 'item_id'], ['schwund', 'item_id'], ['ek_import', 'item_id'], ['dok_import_datei', 'item_id'],
            ['rezeptur_anfrage_wunsch', 'item_id'], ['rohstoff_variante_vorschlag', 'item_id'], ['etikett_preis', 'item_id'], ['verpackung_dokument', 'item_id'],
            ['pack_ek_staffel', 'item_id'], ['pack_vk_staffel', 'item_id'], ['pack_kapazitaet', 'item_id'], ['lg_artikel', 'item_id'], ['lg_versand_pos', 'item_id'],
            ['lager2_bewegung', 'item_id'], ['portal_anfrage', 'rohstoff_id'], ['auftrag', 'etikett_item_id'],
            ['produkt', 'verpackung_id'], ['produkt', 'verschluss_id'], ['produkt', 'etikett_id'], ['produkt', 'karton_id'], ['produkt', 'beipack_id'], ['produkt', 'leerkapsel_id'],
            ['item', 'basis_item_id'], ['item', 'karton_item_id'],
        ];
        foreach ($ziele as [$t, $c]) {
            if (!table_exists($t) || !column_exists($t, $c)) continue;
            $n = q("UPDATE IGNORE `$t` SET `$c`=? WHERE `$c`=?", [$ziel_id, $quelle_id])->rowCount();
            if ($n) $z[$t . ($c !== 'item_id' ? '.' . $c : '')] = ($z[$t] ?? 0) + $n;
        }
        // Stammdaten der Qualität (Wirkstoffe, Grenzwerte, Kennwerte): nur übernehmen, wenn der Hauptartikel noch keine hat.
        foreach (['item_wirkstoff', 'item_grenzwert', 'item_kennwert'] as $t) {
            if (!table_exists($t)) continue;
            if ((int) scalar("SELECT COUNT(*) FROM `$t` WHERE item_id=?", [$ziel_id]) === 0) {
                $n = q("UPDATE `$t` SET item_id=? WHERE item_id=?", [$ziel_id, $quelle_id])->rowCount();
                if ($n) $z[$t] = $n;
            }
        }
        $n = q("UPDATE dokument SET objekt_id=? WHERE objekt_typ='item' AND objekt_id=?", [$ziel_id, $quelle_id])->rowCount();
        if ($n) $z['dokument'] = $n;
        // Name (+ alte Synonyme) des Doppel-Artikels als Synonym am Hauptartikel – damit er weiter gefunden wird.
        $syn = array_filter(array_map('trim', preg_split('/\s*;\s*/', (string) scalar("SELECT COALESCE(synonyme,'') FROM item WHERE id=?", [$ziel_id]))));
        foreach (array_merge([(string)$q['name']], preg_split('/\s*;\s*/', (string) scalar("SELECT COALESCE(synonyme,'') FROM item WHERE id=?", [$quelle_id]))) as $s)
            if (trim($s) !== '' && !in_array(trim($s), $syn, true) && trim($s) !== (string)$h['name']) $syn[] = trim($s);
        q("UPDATE item SET synonyme=? WHERE id=?", [$syn ? implode('; ', $syn) : null, $ziel_id]);
        $vermerk = "\n[" . gmdate('d.m.Y') . '] zusammengeführt in Artikel #' . $ziel_id . ' – ' . mb_substr((string)$h['name'], 0, 80) . ($wer !== '' ? ' (' . $wer . ')' : '');
        q("UPDATE item SET gesperrt=1, zusammengefuehrt_in=?, notiz=CONCAT(COALESCE(notiz,''), ?) WHERE id=?", [$ziel_id, $vermerk, $quelle_id]);
        aenderung_protokollieren('item', $quelle_id, ['zusammengefuehrt_in' => null, 'gesperrt' => 0], ['zusammengefuehrt_in' => $ziel_id, 'gesperrt' => 1], 'artikel_zusammenfuehren');
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'fehler' => 'Fehler, nichts geändert: ' . $e->getMessage(), 'zahlen' => []];
    }
    $txt = implode(', ', array_map(fn($k, $v) => $k . ' ' . $v, array_keys($z), $z));
    log_aktivitaet('item', $quelle_id, 'team', 'Zusammengeführt in „' . $h['name'] . '“ (#' . $ziel_id . '). Umgezogen: ' . ($txt ?: 'nichts') . '.', 'notiz');
    log_aktivitaet('item', $ziel_id, 'team', 'Doppel-Artikel „' . $q['name'] . '“ (#' . $quelle_id . ') hierher zusammengeführt. Umgezogen: ' . ($txt ?: 'nichts') . '. Alter Name ist jetzt Synonym.', 'notiz');
    if (function_exists('bedarf_bump')) bedarf_bump();
    return ['ok' => true, 'fehler' => '', 'zahlen' => $z];
}
