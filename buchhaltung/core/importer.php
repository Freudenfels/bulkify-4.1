<?php
// Bulk-Import-Assistent: alte Angebote + Rechnungen (PDF) stapelweise hochladen, per KI auslesen/einordnen,
// Rechnungen den Angeboten zuordnen, Zahlungen (Datum+Betrag, Teilzahlung) erfassen und übernehmen.
// Übernahme erzeugt BLEIBENDE Datensätze: Rechnungen -> beleg (kundensichtbar, mit Zahlungen),
// Angebote -> bu_imp_angebot (+ Positionen Produkt/Verpackung/Etikett) als Archiv. Das Import-Staging
// (bu_imp_item/_pos/_zahlung) ist temporär und löschbar; die übernommenen Belege/Angebote bleiben.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/erp.php';
require_once __DIR__ . '/schema.php';        // beleg + Belegfunktionen (zahlung_erfassen, naechste_nummer via erp)
require_once __DIR__ . '/belegeingang.php';  // be_datei_speichern(), be_ki_auslesen-Muster, be_pfad()

function imp_init(): void {
    static $done = false; if ($done) return; $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS bu_imp_item (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch VARCHAR(20) NOT NULL,
        datei VARCHAR(255) NULL, orig_name VARCHAR(255) NULL, mime VARCHAR(100) NULL,
        art VARCHAR(12) NOT NULL DEFAULT 'unklar',        -- rechnung | angebot | unklar
        status VARCHAR(12) NOT NULL DEFAULT 'neu',         -- neu | uebernommen | verworfen
        kunde_name VARCHAR(190) NULL,                      -- KI-Rohname
        kunde_id INT NULL,                                 -- zugeordnet/angelegt
        nummer VARCHAR(80) NULL, datum DATE NULL,
        netto DECIMAL(14,2) NOT NULL DEFAULT 0, ust_prozent DECIMAL(5,2) NOT NULL DEFAULT 0,
        ust_betrag DECIMAL(14,2) NOT NULL DEFAULT 0, brutto DECIMAL(14,2) NOT NULL DEFAULT 0,
        waehrung VARCHAR(3) NOT NULL DEFAULT 'EUR',
        angebot_ref VARCHAR(80) NULL,                      -- Rechnung: referenzierte Angebotsnummer
        link_item_id INT NULL,                             -- Rechnung -> Angebot-Staging-Item
        ki_ok TINYINT(1) NOT NULL DEFAULT 0, ki_json MEDIUMTEXT NULL,
        ergebnis_beleg_id INT NULL, ergebnis_angebot_id INT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_batch (batch), KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bu_imp_pos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        imp_item_id INT NOT NULL, sort INT NOT NULL DEFAULT 0, gruppe INT NOT NULL DEFAULT 1,
        typ VARCHAR(12) NOT NULL DEFAULT 'produkt',        -- produkt | verpackung | etikett
        bezeichnung VARCHAR(255) NULL, menge DECIMAL(14,3) NULL, einheit VARCHAR(20) NULL, preis DECIMAL(12,2) NULL,
        KEY idx_item (imp_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bu_imp_zahlung (
        id INT AUTO_INCREMENT PRIMARY KEY,
        imp_item_id INT NOT NULL, datum DATE NULL, betrag DECIMAL(14,2) NOT NULL DEFAULT 0, art VARCHAR(30) NULL,
        KEY idx_item (imp_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Bleibendes Angebots-Archiv (nur hinterlegt; keine Vertriebs-Angebote).
    $pdo->exec("CREATE TABLE IF NOT EXISTS bu_imp_angebot (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kunde_id INT NULL, nummer VARCHAR(80) NULL, datum DATE NULL,
        netto DECIMAL(14,2) NOT NULL DEFAULT 0, brutto DECIMAL(14,2) NOT NULL DEFAULT 0, waehrung VARCHAR(3) NOT NULL DEFAULT 'EUR',
        datei VARCHAR(255) NULL, orig_name VARCHAR(255) NULL, mime VARCHAR(100) NULL, notiz TEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_kunde (kunde_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bu_imp_angebot_pos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        angebot_id INT NOT NULL, sort INT NOT NULL DEFAULT 0, gruppe INT NOT NULL DEFAULT 1,
        typ VARCHAR(12) NOT NULL DEFAULT 'produkt', bezeichnung VARCHAR(255) NULL,
        menge DECIMAL(14,3) NULL, einheit VARCHAR(20) NULL, preis DECIMAL(12,2) NULL,
        KEY idx_angebot (angebot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Kunden-Aliasse (mehrere Namen -> ein Kunde), z. B. Annapurna/Pure Health/CW Media -> Pure Health NL DE.
    $pdo->exec("CREATE TABLE IF NOT EXISTS bu_kunde_alias (
        alias VARCHAR(190) NOT NULL PRIMARY KEY,           -- kleingeschrieben
        kunde_id INT NULL,                                 -- gesetzt = fix zugeordnet
        gruppe VARCHAR(40) NULL                            -- gleiche gruppe = gehören zusammen
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Beleg bekommt einen Verweis auf das archivierte Angebot (für Portal/Detail).
    if (function_exists('bu_ensure_column')) bu_ensure_column('beleg', 'imp_angebot_id', "INT NULL");
    imp_alias_seed();
}

// Standard-Aliasse anlegen (nur wenn noch keiner da ist). kunde_id bleibt offen -> wird bei der ersten
// Zuordnung im Wizard für die ganze Gruppe gesetzt.
function imp_alias_seed(): void {
    if ((int) scalar("SELECT COUNT(*) FROM bu_kunde_alias") > 0) return;
    foreach (['annapurna', 'pure health', 'cw media', 'cw-media'] as $a)
        q("INSERT IGNORE INTO bu_kunde_alias (alias, kunde_id, gruppe) VALUES (?,?,?)", [$a, null, 'pure-health-nl-de']);
}

// --- Kunden-Match ---------------------------------------------------------
// Rückgabe: ['kunde_id'=>?int, 'firma'=>?string, 'quelle'=>'alias'|'treffer'|'neu', 'gruppe'=>?string]
function imp_kunde_match(string $name): array {
    $n = mb_strtolower(trim($name));
    if ($n === '') return ['kunde_id' => null, 'firma' => null, 'quelle' => 'neu', 'gruppe' => null];
    // 1) Alias (Name enthält Alias oder umgekehrt)
    foreach (all("SELECT alias, kunde_id, gruppe FROM bu_kunde_alias") as $a) {
        if (mb_strpos($n, $a['alias']) !== false || mb_strpos($a['alias'], $n) !== false) {
            if ($a['kunde_id']) { $k = erp_kunde((int)$a['kunde_id']); return ['kunde_id' => (int)$a['kunde_id'], 'firma' => $k['firma'] ?? null, 'quelle' => 'alias', 'gruppe' => $a['gruppe']]; }
            // Gruppe bekannt, aber noch kein Kunde gesetzt: andere Gruppen-Mitglieder evtl. schon zugeordnet
            $g = one("SELECT kunde_id FROM bu_kunde_alias WHERE gruppe=? AND kunde_id IS NOT NULL LIMIT 1", [$a['gruppe']]);
            if ($g) { $k = erp_kunde((int)$g['kunde_id']); return ['kunde_id' => (int)$g['kunde_id'], 'firma' => $k['firma'] ?? null, 'quelle' => 'alias', 'gruppe' => $a['gruppe']]; }
            return ['kunde_id' => null, 'firma' => null, 'quelle' => 'neu', 'gruppe' => $a['gruppe']];
        }
    }
    // 2) Fuzzy über die Firma
    $k = erp_kunde_per_firma($name);
    if ($k) return ['kunde_id' => (int)$k['id'], 'firma' => $k['firma'], 'quelle' => 'treffer', 'gruppe' => null];
    return ['kunde_id' => null, 'firma' => null, 'quelle' => 'neu', 'gruppe' => null];
}

// Einen Kunden einem Item zuordnen; gehört der Rohname zu einer Alias-Gruppe, wird die Gruppe fix gesetzt.
function imp_item_kunde_setzen(int $item_id, int $kunde_id): void {
    $it = imp_item($item_id); if (!$it) return;
    q("UPDATE bu_imp_item SET kunde_id=? WHERE id=?", [$kunde_id ?: null, $item_id]);
    $n = mb_strtolower(trim((string)$it['kunde_name']));
    foreach (all("SELECT alias, gruppe FROM bu_kunde_alias") as $a) {
        if ($a['gruppe'] && (mb_strpos($n, $a['alias']) !== false || mb_strpos($a['alias'], $n) !== false)) {
            q("UPDATE bu_kunde_alias SET kunde_id=? WHERE gruppe=?", [$kunde_id ?: null, $a['gruppe']]);
            break;
        }
    }
}

// Neuen Kunden anlegen und dem Item (+ggf. Alias-Gruppe) zuordnen. Gibt kunde_id.
function imp_item_kunde_neu(int $item_id, string $firma): int {
    $kid = erp_kunde_anlegen($firma);
    if ($kid) imp_item_kunde_setzen($item_id, $kid);
    return $kid;
}

// --- KI-Auslesung ---------------------------------------------------------
// Liest ein Beleg-PDF: Art (Angebot/Rechnung), Kopf + bei Angeboten die Positionsblöcke.
function imp_ki_auslesen(string $pfad): array {
    if (!function_exists('ki_bereit') || !ki_bereit()) return ['ok' => false, 'daten' => [], 'roh' => ''];
    $prompt = "Lies dieses Dokument (eine Rechnung ODER ein Angebot eines Nahrungsergänzungs-Lohnherstellers) "
        . "und gib NUR JSON zurück:\n"
        . '{"art":"rechnung","kunde_name":"","nummer":"","datum":"","netto":0,"ust_prozent":19,"brutto":0,"waehrung":"EUR","angebot_ref":"","positionen":[{"typ":"produkt","gruppe":1,"bezeichnung":"","menge":0,"einheit":"","preis":0}]}' . "\n"
        . "art = 'rechnung' oder 'angebot'. kunde_name = der Kunde/Empfänger (Firma). nummer = Rechnungs- bzw. Angebotsnummer. "
        . "datum YYYY-MM-DD. brutto = Endbetrag inkl. USt, netto = Nettobetrag, ust_prozent = USt-Satz %. waehrung ISO (EUR/USD/CNY). "
        . "angebot_ref = nur bei Rechnungen: die referenzierte Angebotsnummer, falls genannt (sonst leer). "
        . "positionen = NUR bei Angeboten: die Zeilen in Blöcken je Produkt – typ 'produkt', dann dessen 'verpackung', ggf. 'etikett', "
        . "danach das nächste Produkt. gruppe = fortlaufende Blocknummer (1,2,3…). bezeichnung = Positionstext, menge/einheit/preis wenn erkennbar. "
        . "Bei Rechnungen positionen leer lassen. Zahlen mit Punkt als Dezimaltrennzeichen. Nichts erfinden – unbekannt leer/0.";
    $r = ki_datei_frage($pfad, $prompt, ['json' => true, 'max_tokens' => 3000, 'zweck' => 'bulk-import']);
    if (empty($r['ok'])) return ['ok' => false, 'daten' => [], 'roh' => ''];
    $d = is_array($r['daten'] ?? null) ? $r['daten'] : [];
    $num = fn($x) => (float) str_replace(',', '.', (string)$x);
    $gilt = fn($s) => (is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) ? $s : null;
    $art = strtolower(trim((string)($d['art'] ?? 'unklar'))); if (!in_array($art, ['rechnung', 'angebot'], true)) $art = 'unklar';
    $brutto = $num($d['brutto'] ?? 0); $netto = $num($d['netto'] ?? 0); $ustP = $num($d['ust_prozent'] ?? 0);
    if ($brutto <= 0 && $netto > 0) $brutto = round($netto * (1 + $ustP / 100), 2);
    if ($netto <= 0 && $brutto > 0) $netto = $ustP > 0 ? round($brutto / (1 + $ustP / 100), 2) : $brutto;
    $pos = [];
    foreach ((array)($d['positionen'] ?? []) as $p) {
        $typ = strtolower(trim((string)($p['typ'] ?? 'produkt'))); if (!in_array($typ, ['produkt', 'verpackung', 'etikett'], true)) $typ = 'produkt';
        $pos[] = ['typ' => $typ, 'gruppe' => (int)($p['gruppe'] ?? 1) ?: 1, 'bezeichnung' => trim((string)($p['bezeichnung'] ?? '')),
                  'menge' => $num($p['menge'] ?? 0), 'einheit' => trim((string)($p['einheit'] ?? '')), 'preis' => $num($p['preis'] ?? 0)];
    }
    return ['ok' => true, 'roh' => json_encode($d, JSON_UNESCAPED_UNICODE), 'daten' => [
        'art' => $art, 'kunde_name' => trim((string)($d['kunde_name'] ?? '')), 'nummer' => trim((string)($d['nummer'] ?? '')),
        'datum' => $gilt($d['datum'] ?? null), 'netto' => round($netto, 2), 'ust_prozent' => $ustP, 'brutto' => round($brutto, 2),
        'waehrung' => strtoupper(trim((string)($d['waehrung'] ?? 'EUR'))) ?: 'EUR',
        'angebot_ref' => trim((string)($d['angebot_ref'] ?? '')), 'positionen' => $pos,
    ]];
}

// --- Staging: Datei hinzufügen -------------------------------------------
// NUR speichern + Staging-Item anlegen (SCHNELL, ohne KI). Die KI-Auslesung läuft danach pro Datei
// (imp_ki_item), damit der Upload vieler Dateien nicht in einen Timeout läuft. Gibt die Item-id.
function imp_datei_hinzufuegen(string $batch, array $file): int {
    imp_init();
    $g = be_datei_speichern($file);
    if (!$g) return 0;
    q("INSERT INTO bu_imp_item (batch,datei,orig_name,mime,art,status,ki_ok) VALUES (?,?,?,?,?,?,0)",
      [$batch, $g['datei'], $g['orig'], $g['mime'], 'unklar', 'neu']);
    return insert_id();
}

// Ein einzelnes Staging-Item per KI auslesen (eigener Request → kein Sammel-Timeout). Aktualisiert Felder,
// matcht den Kunden, legt die Angebotspositionen an. Rückgabe ['ok'=>bool,'art'=>...,'fehler'=>?].
function imp_ki_item(int $id): array {
    $it = imp_item($id);
    if (!$it) return ['ok' => false, 'art' => 'unklar', 'fehler' => 'Item nicht gefunden'];
    if (empty($it['datei'])) return ['ok' => false, 'art' => $it['art'], 'fehler' => 'keine Datei'];
    $ki = imp_ki_auslesen(be_pfad((string)$it['datei']));
    if (empty($ki['ok'])) { q("UPDATE bu_imp_item SET ki_ok=0 WHERE id=?", [$id]); return ['ok' => false, 'art' => $it['art'], 'fehler' => 'KI nicht erreichbar/eingerichtet']; }
    $d = $ki['daten'];
    $ustP = (float)($d['ust_prozent'] ?? 0); $netto = (float)($d['netto'] ?? 0);
    $match = imp_kunde_match((string)($d['kunde_name'] ?? ''));
    q("UPDATE bu_imp_item SET art=?, kunde_name=?, kunde_id=COALESCE(kunde_id,?), nummer=?, datum=?,
            netto=?, ust_prozent=?, ust_betrag=?, brutto=?, waehrung=?, angebot_ref=?, ki_ok=1, ki_json=? WHERE id=?",
      [(string)($d['art'] ?? 'unklar'), (string)($d['kunde_name'] ?? '') ?: null, $match['kunde_id'],
       (string)($d['nummer'] ?? '') ?: null, $d['datum'] ?? null, $netto, $ustP, round($netto * $ustP / 100, 2),
       (float)($d['brutto'] ?? 0), (string)($d['waehrung'] ?? 'EUR'), (string)($d['angebot_ref'] ?? '') ?: null, $ki['roh'] ?? null, $id]);
    q("DELETE FROM bu_imp_pos WHERE imp_item_id=?", [$id]);
    $sort = 0;
    foreach ((array)($d['positionen'] ?? []) as $p)
        q("INSERT INTO bu_imp_pos (imp_item_id,sort,gruppe,typ,bezeichnung,menge,einheit,preis) VALUES (?,?,?,?,?,?,?,?)",
          [$id, $sort++, (int)$p['gruppe'], $p['typ'], $p['bezeichnung'] ?: null, $p['menge'] ?: null, $p['einheit'] ?: null, $p['preis'] ?: null]);
    return ['ok' => true, 'art' => (string)($d['art'] ?? 'unklar'), 'fehler' => null];
}

// Item-ids eines Batches, die noch nicht per KI gelesen wurden (für den Auto-Durchlauf).
function imp_ungelesen(string $batch): array {
    imp_init();
    return array_map('intval', array_column(all("SELECT id FROM bu_imp_item WHERE batch=? AND status='neu' AND ki_ok=0 ORDER BY id", [$batch]), 'id'));
}

// --- Staging: lesen/ändern ------------------------------------------------
function imp_items(string $batch, string $art = ''): array {
    imp_init();
    $w = "batch=? AND status<>'verworfen'"; $a = [$batch];
    if ($art !== '') { $w .= " AND art=?"; $a[] = $art; }
    return all("SELECT i.*, k.firma AS kunde_firma FROM bu_imp_item i LEFT JOIN kunden k ON k.id=i.kunde_id
                WHERE $w ORDER BY i.art, i.id", $a);
}
function imp_item(int $id): ?array { return $id ? one("SELECT * FROM bu_imp_item WHERE id=?", [$id]) : null; }
function imp_pos(int $item_id): array { return all("SELECT * FROM bu_imp_pos WHERE imp_item_id=? ORDER BY sort, id", [$item_id]); }
function imp_item_update(int $id, array $d): void {
    $it = imp_item($id); if (!$it || $it['status'] === 'uebernommen') return;
    $netto = round((float) str_replace(',', '.', (string)($d['netto'] ?? $it['netto'])), 2);
    $ustP = (float) str_replace(',', '.', (string)($d['ust_prozent'] ?? $it['ust_prozent']));
    $brutto = isset($d['brutto']) && $d['brutto'] !== '' ? round((float) str_replace(',', '.', (string)$d['brutto']), 2) : round($netto * (1 + $ustP / 100), 2);
    q("UPDATE bu_imp_item SET art=?, nummer=?, datum=?, netto=?, ust_prozent=?, ust_betrag=?, brutto=?, waehrung=?, angebot_ref=? WHERE id=?",
      [(string)($d['art'] ?? $it['art']), trim((string)($d['nummer'] ?? $it['nummer'])) ?: null, $d['datum'] ?? $it['datum'],
       $netto, $ustP, round($netto * $ustP / 100, 2), $brutto, strtoupper((string)($d['waehrung'] ?? $it['waehrung'])),
       trim((string)($d['angebot_ref'] ?? $it['angebot_ref'])) ?: null, $id]);
}
function imp_item_verwerfen(int $id): void { q("UPDATE bu_imp_item SET status='verworfen' WHERE id=?", [$id]); }
function imp_item_verknuepfen(int $rechnung_item, ?int $angebot_item): void {
    q("UPDATE bu_imp_item SET link_item_id=? WHERE id=? AND art='rechnung'", [$angebot_item ?: null, $rechnung_item]);
}

// Auto-Verknüpfung: Rechnung.angebot_ref -> Angebot-Item mit passender Nummer (im selben Batch).
function imp_auto_verknuepfen(string $batch): int {
    $angebote = imp_items($batch, 'angebot');
    $n = 0;
    foreach (imp_items($batch, 'rechnung') as $r) {
        if (!empty($r['link_item_id']) || trim((string)$r['angebot_ref']) === '') continue;
        $ref = mb_strtolower(trim((string)$r['angebot_ref']));
        foreach ($angebote as $a) {
            $an = mb_strtolower(trim((string)$a['nummer']));
            if ($an !== '' && ($an === $ref || mb_strpos($ref, $an) !== false || mb_strpos($an, $ref) !== false)) {
                imp_item_verknuepfen((int)$r['id'], (int)$a['id']); $n++; break;
            }
        }
    }
    return $n;
}

// --- Zahlungen (Staging) --------------------------------------------------
function imp_zahlungen(int $item_id): array { return all("SELECT * FROM bu_imp_zahlung WHERE imp_item_id=? ORDER BY datum, id", [$item_id]); }
function imp_zahlung_add(int $item_id, float $betrag, ?string $datum, string $art = ''): void {
    if ($betrag == 0.0) return;
    q("INSERT INTO bu_imp_zahlung (imp_item_id,datum,betrag,art) VALUES (?,?,?,?)", [$item_id, $datum ?: null, round($betrag, 2), trim($art) ?: null]);
}
function imp_zahlung_del(int $zid): void { q("DELETE FROM bu_imp_zahlung WHERE id=?", [$zid]); }
function imp_zahlung_summe(int $item_id): float { return (float) scalar("SELECT COALESCE(SUM(betrag),0) FROM bu_imp_zahlung WHERE imp_item_id=?", [$item_id]); }

// --- Übernahme (Commit) ---------------------------------------------------
// Erzeugt bleibende Belege (Rechnungen) + archivierte Angebote. Rückgabe ['angebote'=>n,'rechnungen'=>n,'fehler'=>[...]].
function imp_uebernehmen(string $batch): array {
    imp_init();
    $fehler = []; $nA = 0; $nR = 0;
    // 1) Angebote archivieren (zuerst, damit Rechnungen darauf verweisen können)
    foreach (imp_items($batch, 'angebot') as $a) {
        if ($a['status'] === 'uebernommen' && $a['ergebnis_angebot_id']) continue;
        q("INSERT INTO bu_imp_angebot (kunde_id,nummer,datum,netto,brutto,waehrung,datei,orig_name,mime) VALUES (?,?,?,?,?,?,?,?,?)",
          [$a['kunde_id'] ?: null, $a['nummer'] ?: null, $a['datum'] ?: null, (float)$a['netto'], (float)$a['brutto'],
           (string)$a['waehrung'], $a['datei'], $a['orig_name'], $a['mime']]);
        $gid = insert_id();
        $s = 0;
        foreach (imp_pos((int)$a['id']) as $p)
            q("INSERT INTO bu_imp_angebot_pos (angebot_id,sort,gruppe,typ,bezeichnung,menge,einheit,preis) VALUES (?,?,?,?,?,?,?,?)",
              [$gid, $s++, (int)$p['gruppe'], $p['typ'], $p['bezeichnung'], $p['menge'], $p['einheit'], $p['preis']]);
        q("UPDATE bu_imp_item SET status='uebernommen', ergebnis_angebot_id=? WHERE id=?", [$gid, (int)$a['id']]);
        $nA++;
    }
    // 2) Rechnungen -> beleg (kundensichtbar) + Zahlungen
    foreach (imp_items($batch, 'rechnung') as $r) {
        if ($r['status'] === 'uebernommen' && $r['ergebnis_beleg_id']) continue;
        if (empty($r['kunde_id'])) { $fehler[] = 'Rechnung ' . ($r['nummer'] ?: '#' . $r['id']) . ': kein Kunde zugeordnet.'; continue; }
        if ((float)$r['brutto'] <= 0) { $fehler[] = 'Rechnung ' . ($r['nummer'] ?: '#' . $r['id']) . ': kein Betrag.'; continue; }
        // verknüpftes (bereits übernommenes) Angebot
        $impAngId = null;
        if (!empty($r['link_item_id'])) { $li = imp_item((int)$r['link_item_id']); if ($li && $li['ergebnis_angebot_id']) $impAngId = (int)$li['ergebnis_angebot_id']; }
        $nummer = trim((string)$r['nummer']) ?: naechste_nummer('RE');
        $netto = (float)$r['netto']; $ustP = (float)$r['ust_prozent']; $ust = round($netto * $ustP / 100, 2);
        $brutto = (float)$r['brutto'] > 0 ? (float)$r['brutto'] : round($netto + $ust, 2);
        q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum,
                original_datei,original_orig,kunde_sichtbar,imp_angebot_id)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?)",
          [$nummer, 'rechnung', null, (int)$r['kunde_id'], $netto, $ustP, $ust, $brutto, 'offen', $r['datum'] ?: gmdate('Y-m-d'),
           $r['datei'], $r['orig_name'], $impAngId]);
        $bid = insert_id();
        if (function_exists('beleg_status_log_add')) beleg_status_log_add($bid, 'offen', 'Alt-Rechnung importiert (Bulk)' . ($impAngId ? ', mit Angebot verknüpft' : ''), 'team');
        // Zahlungen übernehmen (setzt Status offen/teilbezahlt/bezahlt via zahlung_erfassen)
        foreach (imp_zahlungen((int)$r['id']) as $z)
            if ((float)$z['betrag'] != 0.0) zahlung_erfassen($bid, (float)$z['betrag'], $z['datum'] ?: null, null, $z['art'] ?: 'Import', 'Import (Bulk)', 'team');
        q("UPDATE bu_imp_item SET status='uebernommen', ergebnis_beleg_id=? WHERE id=?", [$bid, (int)$r['id']]);
        $nR++;
    }
    return ['angebote' => $nA, 'rechnungen' => $nR, 'fehler' => $fehler];
}

// --- Batch löschen (Staging weg; übernommene Belege/Angebote bleiben) -----
function imp_batch_loeschen(string $batch): void {
    imp_init();
    foreach (all("SELECT id, datei, status FROM bu_imp_item WHERE batch=?", [$batch]) as $it) {
        // Dateien nur löschen, wenn NICHT übernommen (sonst hängt das Original am Beleg/Archiv).
        if ($it['status'] !== 'uebernommen' && !empty($it['datei'])) { $pf = be_pfad((string)$it['datei']); if (is_file($pf)) @unlink($pf); }
        q("DELETE FROM bu_imp_pos WHERE imp_item_id=?", [(int)$it['id']]);
        q("DELETE FROM bu_imp_zahlung WHERE imp_item_id=?", [(int)$it['id']]);
    }
    q("DELETE FROM bu_imp_item WHERE batch=?", [$batch]);
}

// Offene (nicht übernommene) Batches – für die Startliste des Assistenten.
function imp_batches(): array {
    imp_init();
    return all("SELECT batch, COUNT(*) anz, SUM(status='uebernommen') uebernommen, MIN(angelegt) seit
                FROM bu_imp_item GROUP BY batch ORDER BY seit DESC");
}

// Archiviertes Angebot + Positionen (für Beleg-Detail / Portal).
function imp_angebot(int $id): ?array { return $id ? one("SELECT * FROM bu_imp_angebot WHERE id=?", [$id]) : null; }
function imp_angebot_pos(int $angebot_id): array { return all("SELECT * FROM bu_imp_angebot_pos WHERE angebot_id=? ORDER BY sort, id", [$angebot_id]); }
