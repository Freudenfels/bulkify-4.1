<?php
// Einmalige Datenpflege auf live, Paket 12.11.2026 Teil 1 – ausdrücklich freigegeben von Nico (11.11.2026 abends).
// Läuft genau einmal (app_meta dpf_1211_erledigt) aus dem Schema-Abgleich. Arbeitet über die vorhandenen App-Funktionen
// (bestellung_stornieren, webe_als_geliefert_schliessen, Lieferant sperren, Artikel-Synonyme) und prüft jede Bedingung
// zur Laufzeit neu – passt eine Bestellung nicht mehr (z. B. inzwischen bestellt), bleibt sie unberührt.
// Jede Änderung bekommt einen Verlaufseintrag „Freigabe Nico 11.11.2026“. Ergebnis: app_meta dpf_1211_ergebnis (JSON).
// Siehe datenpflege_1211.md.

const DPF_1211_VERMERK = 'Freigabe Nico 11.11.2026';

// Konfiguration live (nach Sichtprüfung am 12.11.2026 in der Datenprüfung):
//  - storno: Entwürfe älter als 14 Tage ohne Position oder ohne Lieferant
//  - schliessen: Wellgreen-Bestellungen laut Lieferübersicht „Maggi x Nico Order“ vollständig geliefert
//  - lieferant: GS Capsule (L-2839) -> GS Capsules (L-2841)
function dpf_1211_konfig(): array {
    return [
        'storno'     => ['BE-2871', 'BE-2872', 'BE-2875', 'BE-2884'],
        'schliessen' => ['BE-2863', 'BE-2868'],
        'schliessen_notiz' => 'laut Lieferübersicht Wellgreen vollständig geliefert, Rest war Einbuchfehler',
        'lief_von'   => 'L-2839',
        'lief_nach'  => 'L-2841',
        'umbenennen' => ['HMPC' => 'HPMC'],
    ];
}

function dpf_1211_log(string $typ, int $id, string $text): void {
    if (function_exists('log_aktivitaet')) {
        try { log_aktivitaet($typ, $id, 'system', $text . ' (' . DPF_1211_VERMERK . ')', 'status'); } catch (\Throwable $e) {}
    }
}

// Ein Durchlauf mit beliebiger Konfiguration (Testkopie: eigene Nummern). Rückgabe: Liste je Bereich.
function dpf_1211_ausfuehren(array $k): array {
    require_once __DIR__ . '/wareneingang_bestellung.php';
    $erg = ['storniert' => [], 'nicht_storniert' => [], 'geschlossen' => [], 'nicht_geschlossen' => [], 'lieferant' => [], 'umbenannt' => []];

    // 1. Alte Entwürfe ohne Position oder ohne Lieferant stornieren (Bedingung wird hier noch einmal geprüft).
    foreach ($k['storno'] ?? [] as $nr) {
        $b = one("SELECT b.*, l.id AS lid FROM bestellung b LEFT JOIN lieferanten l ON l.id=b.lieferant_id WHERE b.nummer=?", [$nr]);
        if (!$b) { $erg['nicht_storniert'][] = $nr . ': nicht gefunden'; continue; }
        $pos = (int) scalar("SELECT COUNT(*) FROM bestellung_position WHERE bestellung_id=? AND menge>0", [(int)$b['id']]);
        $alt = strtotime((string)$b['angelegt']) < time() - 14 * 86400;
        if ((string)$b['status'] !== 'offen' || !$alt || ($pos > 0 && !empty($b['lid']))) {
            $erg['nicht_storniert'][] = $nr . ': Bedingung passt nicht mehr (Status ' . $b['status'] . ', Positionen ' . $pos . ')';
            continue;
        }
        $grund = 'alter Entwurf ' . ($pos === 0 ? 'ohne Position' : 'ohne Lieferant') . ', ' . DPF_1211_VERMERK;
        $r = bestellung_stornieren((int)$b['id'], 'Datenpflege', $grund);
        if (!empty($r['ok'])) {
            dpf_1211_log('bestellung', (int)$b['id'], 'Storniert: ' . ($pos === 0 ? 'alter Entwurf ohne Position' : 'alter Entwurf ohne Lieferant'));
            $erg['storniert'][] = ['id' => (int)$b['id'], 'nummer' => $nr];
        } else $erg['nicht_storniert'][] = $nr . ': ' . ($r['msg'] ?? 'Fehler');
    }

    // 2. Als geliefert schließen (vorhandener Weg „als geliefert schließen“, nicht stornieren).
    foreach ($k['schliessen'] ?? [] as $nr) {
        $b = one("SELECT * FROM bestellung WHERE nummer=?", [$nr]);
        if (!$b || in_array((string)$b['status'], ['storniert', 'geliefert'], true)) {
            $erg['nicht_geschlossen'][] = $nr . ': ' . ($b ? 'Status ' . $b['status'] : 'nicht gefunden');
            continue;
        }
        if (webe_als_geliefert_schliessen((int)$b['id'], 'Datenpflege')) {
            $notiz = (string)($k['schliessen_notiz'] ?? '');
            q("UPDATE bestellung SET notiz=CONCAT(COALESCE(notiz,''), ?) WHERE id=?", ["\n[" . gmdate('d.m.Y') . '] ' . $notiz . ' (' . DPF_1211_VERMERK . ')', (int)$b['id']]);
            dpf_1211_log('bestellung', (int)$b['id'], 'Als geliefert geschlossen: ' . $notiz);
            $erg['geschlossen'][] = ['id' => (int)$b['id'], 'nummer' => $nr];
        } else $erg['nicht_geschlossen'][] = $nr . ': Schließen abgelehnt';
    }

    // 3. Lieferanten-Dublette: alles, was nur an der Quelle hängt, auf das Ziel; Quelle sperren (nicht löschen).
    //    Abgeschlossene Belege bleiben: Kreditoren-Rechnungen und gelieferte/stornierte Bestellungen.
    if (!empty($k['lief_von']) && !empty($k['lief_nach'])) {
        $von  = one("SELECT * FROM lieferanten WHERE lieferantennummer=?", [$k['lief_von']]);
        $nach = one("SELECT * FROM lieferanten WHERE lieferantennummer=?", [$k['lief_nach']]);
        if ($von && $nach && (int)$von['id'] !== (int)$nach['id']) {
            $vid = (int)$von['id']; $nid = (int)$nach['id'];
            $moved = [];
            $cols = all("SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME LIKE '%lieferant_id' AND TABLE_NAME <> 'lieferanten'
                           AND TABLE_NAME NOT LIKE 'v3imp=_%' ESCAPE '=' AND TABLE_NAME NOT LIKE 'bu=_%' ESCAPE '='
                           AND TABLE_NAME NOT IN ('lieferant_rechnung','lieferant_zahlung')");
            foreach ($cols as $cc) {
                $t = (string)$cc['t']; $c = (string)$cc['c'];
                $wo = $t === 'bestellung' ? " AND status NOT IN ('geliefert','storniert')" : '';
                $n = q("UPDATE `$t` SET `$c`=? WHERE `$c`=?$wo", [$nid, $vid])->rowCount();
                if ($n > 0) $moved[$t . '.' . $c] = $n;
            }
            if (table_exists('dokument')) {
                $n = q("UPDATE dokument SET objekt_id=? WHERE objekt_typ='lieferant' AND objekt_id=?", [$nid, $vid])->rowCount();
                if ($n > 0) $moved['dokument (Lieferanten-Dokumente)'] = $n;
            }
            // Kontaktfelder nur füllen, wo das Ziel leer ist (Malte bleibt).
            foreach (['ansprechpartner', 'email', 'telefon'] as $f) {
                if (!column_exists('lieferanten', $f)) continue;
                if (trim((string)($nach[$f] ?? '')) === '' && trim((string)($von[$f] ?? '')) !== '') {
                    q("UPDATE lieferanten SET `$f`=? WHERE id=?", [$von[$f], $nid]);
                    $moved['Kontakt ' . $f] = 1;
                }
            }
            // Alter Name als Alias, damit Importe/Zuordnungen ihn weiter erkennen.
            if (table_exists('lieferant_alias'))
                q("INSERT IGNORE INTO lieferant_alias (alias, firma) VALUES (?, ?)", [(string)$von['firma'], (string)$nach['firma']]);
            q("UPDATE lieferanten SET gesperrt=1 WHERE id=?", [$vid]);
            $liste = $moved ? implode(', ', array_map(fn($x, $n) => $x . ' ' . $n, array_keys($moved), $moved)) : 'nichts';
            dpf_1211_log('lieferant', $vid, 'Dublette von ' . $nach['firma'] . ' (' . $k['lief_nach'] . '): gesperrt, nicht gelöscht. Übernommen: ' . $liste);
            dpf_1211_log('lieferant', $nid, 'Dublette ' . $von['firma'] . ' (' . $k['lief_von'] . ') hierher übernommen: ' . $liste);
            $erg['lieferant'] = ['von' => $vid, 'nach' => $nid, 'moved' => $moved];
        } else $erg['lieferant'] = ['fehler' => 'Lieferanten nicht gefunden'];
    }

    // 4. Tippfehler in Artikelnamen; alter Name wird Synonym (Suche trifft weiter).
    foreach ($k['umbenennen'] ?? [] as $alt => $neu) {
        $like = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $alt) . '%';
        foreach (all("SELECT id, name, synonyme FROM item WHERE name COLLATE utf8mb4_bin LIKE ? ESCAPE '='", [$like]) as $it) {
            $neuName = str_replace($alt, $neu, (string)$it['name']);
            $syn = array_filter(array_map('trim', preg_split('/[,;\n]+/', (string)($it['synonyme'] ?? ''))));
            if (!in_array($it['name'], $syn, true)) $syn[] = (string)$it['name'];
            q("UPDATE item SET name=?, synonyme=? WHERE id=?", [$neuName, implode(', ', $syn), (int)$it['id']]);
            dpf_1211_log('item', (int)$it['id'], 'Name korrigiert: „' . $it['name'] . '“ → „' . $neuName . '“ (alter Name bleibt als Synonym)');
            $erg['umbenannt'][] = ['id' => (int)$it['id'], 'alt' => (string)$it['name'], 'neu' => $neuName];
        }
    }
    if (function_exists('bedarf_bump')) bedarf_bump();
    return $erg;
}

// Aus dem Schema-Abgleich: genau einmal, nur mit der Live-Konfiguration.
function dpf_1211_einmal(): void {
    try {
        if (meta_get('dpf_1211_erledigt', '') === '1' || !table_exists('bestellung') || !column_exists('item', 'synonyme')) return;
        meta_set('dpf_1211_erledigt', '1');   // zuerst setzen: lieber nie als doppelt
        $erg = dpf_1211_ausfuehren(dpf_1211_konfig());
        meta_set('dpf_1211_ergebnis', json_encode($erg, JSON_UNESCAPED_UNICODE));
    } catch (\Throwable $e) {
        try { meta_set('dpf_1211_ergebnis', 'Fehler: ' . $e->getMessage()); } catch (\Throwable $e2) {}
    }
}
