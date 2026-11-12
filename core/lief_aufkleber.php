<?php
// Lieferanten-Gebindeaufkleber mit QR (Paket 12.11.2026 Teil 5, Plan plan-lieferant-gebindeaufkleber.md, freigegeben von Nico).
// Der Lieferant druckt je Gebinde (Sack, Fass, Karton) einen Aufkleber; unsere Lager-App scannt ihn beim Wareneingang:
// ein Scan = ein Gebinde (Gewicht wie heute), die Charge entsteht beim ersten Scan.
//  - Charge, MHD, Herstelldatum stehen dort, wo sie heute liegen: bestellung_charge (Chargen-Dokumente an der Position).
//  - lief_aufkleber: ein Datensatz je gedrucktem Aufkleber (Netto/Brutto/Stück), Status aktiv | storniert | erfasst.
//  - QR enthält NUR den Code „BX<id>-<Prüfziffer>“ (HMAC mit Schlüssel aus app_meta) – keine Klartextdaten, keine Preise.
// Seiten: Portal-Bestellung (Bereich „Labels / Aufkleber“), Dashboard-Bestellung (gleiche Funktion), Lager-App (Scan).
// Läuft im Dashboard UND in der Lager-App: nur q/one/all/scalar, nie core/schema.php (Sub-App-Regel). Siehe lief_aufkleber.md.

if (!function_exists('la_schema')) {

function la_jetzt(): string { return gmdate('Y-m-d H:i:s'); }
function la_meta(string $k, ?string $neu = null): string {
    try {
        if ($neu !== null) { q("INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$k, $neu]); return $neu; }
        return (string) (scalar("SELECT v FROM app_meta WHERE k=?", [$k]) ?? '');
    } catch (\Throwable $e) { return ''; }
}
function la_spalte_da(string $t, string $c): bool {
    static $cache = [];
    $key = $t . '.' . $c;
    if (!isset($cache[$key])) {
        try { $cache[$key] = (int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?", [$t, $c]) > 0; }
        catch (\Throwable $e) { $cache[$key] = false; }
    }
    return $cache[$key];
}

function la_schema(): void {
    static $ok = false;
    if ($ok) return;
    $ok = true;
    try {
        q("CREATE TABLE IF NOT EXISTS lief_aufkleber (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bestellung_id INT NOT NULL,
            bestellung_position_id INT NOT NULL,
            bestellung_charge_id INT NOT NULL,
            netto_kg DECIMAL(12,3) NULL,
            brutto_kg DECIMAL(12,3) NULL,
            stueck DECIMAL(14,3) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'aktiv',
            gedruckt_am DATETIME NOT NULL,
            gedruckt_von VARCHAR(20) NOT NULL DEFAULT 'lieferant',
            storniert_am DATETIME NULL,
            charge_id INT NULL,
            gebinde_id INT NULL,
            erfasst_am DATETIME NULL,
            erfasst_von VARCHAR(190) NULL,
            KEY idx_best (bestellung_id), KEY idx_bc (bestellung_charge_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Throwable $e) {}
    // Spalten best-effort (ohne core/schema.php): ALTER nur, wenn sie fehlen.
    foreach ([['bestellung_charge', 'herstell_datum', 'DATE NULL'],
              ['bestellung', 'aufkleber_abschluss', 'DATETIME NULL'],        // Wareneingang per Aufkleber abgeschlossen
              ['item', 'aufkleber_lagerung', 'VARCHAR(255) NULL']] as [$t, $c, $d]) {   // Shelf life/Storage am Artikel (überschreibt den Standard)
        try { if (!la_spalte_da($t, $c)) q("ALTER TABLE `$t` ADD COLUMN `$c` $d"); } catch (\Throwable $e) {}
    }
}

// --- QR-Code ------------------------------------------------------------------------------------------------------
// Schlüssel (13.11.2026): wird er neu angelegt, wird er danach noch einmal GELESEN. Lässt er sich nicht lesen/schreiben, gibt es
// keinen Schlüssel ('') – dann wird weder gedruckt noch gescannt (vorher wurde mit leerem Schlüssel gerechnet = fälschbar).
function la_schluessel(): string {
    static $k = null;
    if ($k !== null && $k !== '') return $k;
    $k = la_meta('la_hmac_key');
    if ($k === '') { la_meta('la_hmac_key', bin2hex(random_bytes(24))); $k = la_meta('la_hmac_key'); }
    if (strlen($k) < 32) $k = '';
    return $k;
}
function la_schluessel_da(): bool { return la_schluessel() !== ''; }
// Code eines Aufklebers: BX + ID + '-' + Prüfteil (HMAC). Seit 13.11.2026 8 Hex-Zeichen; alte 5-stellige Codes bleiben gültig.
// Ohne Schlüssel: '' (kein Code).
function la_code(int $id, int $len = 8): string {
    $k = la_schluessel();
    if ($k === '') return '';
    return 'BX' . $id . '-' . strtoupper(substr(hash_hmac('sha256', 'BX' . $id, $k), 0, $len));
}
function la_ist_code(string $s): bool { return (bool) preg_match('/^\s*BX\d{1,9}-([0-9A-Fa-f]{8}|[0-9A-Fa-f]{5})\s*$/i', $s); }
// Code prüfen -> Aufkleber-ID oder 0 (falsche Prüfziffer oder kein Schlüssel = 0). 8 oder (alt) 5 Zeichen.
function la_code_id(string $code): int {
    $code = strtoupper(preg_replace('/\s+/', '', $code));
    if (!preg_match('/^BX(\d{1,9})-([0-9A-F]{8}|[0-9A-F]{5})$/', $code, $m)) return 0;
    $soll = la_code((int)$m[1], strlen($m[2]));
    return ($soll !== '' && hash_equals($soll, $code)) ? (int)$m[1] : 0;
}

// --- Daten --------------------------------------------------------------------------------------------------------
function la_positionen(int $bestellung_id): array {
    la_schema();
    $out = [];
    foreach (all("SELECT bp.id, bp.item_id, bp.menge, bp.einheit, COALESCE(NULLIF(bp.bezeichnung,''), i.name, '–') AS name, i.kategorie
                  FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id
                  WHERE bp.bestellung_id=? AND bp.menge>0 ORDER BY bp.sort, bp.id", [$bestellung_id]) as $p) {
        $p['chargen'] = [];
        foreach (all("SELECT * FROM bestellung_charge WHERE bestellung_position_id=? ORDER BY id", [(int)$p['id']]) as $c) {
            $c['aufkleber'] = all("SELECT * FROM lief_aufkleber WHERE bestellung_charge_id=? ORDER BY id", [(int)$c['id']]);
            $p['chargen'][] = $c;
        }
        $out[] = $p;
    }
    return $out;
}
function la_aktive(int $bestellung_id): array {
    la_schema();
    return all("SELECT * FROM lief_aufkleber WHERE bestellung_id=? AND status<>'storniert' ORDER BY id", [$bestellung_id]);
}
// Stand für Versand und Lager: gedruckt (aktiv+erfasst), erfasst, Netto-Summe.
function la_stand(int $bestellung_id): array {
    $a = la_aktive($bestellung_id);
    $erf = array_filter($a, fn($x) => $x['status'] === 'erfasst');
    return ['gesamt' => count($a), 'erfasst' => count($erf), 'netto' => array_sum(array_map(fn($x) => (float)$x['netto_kg'], $a)),
            'fehlt' => array_values(array_filter($a, fn($x) => $x['status'] === 'aktiv'))];
}

function la_log(int $bestellung_id, string $akteur, string $text): void {
    try { q("INSERT INTO aktivitaet (objekt_typ,objekt_id,akteur,typ,text,erstellt) VALUES ('bestellung',?,?,?,?,?)", [$bestellung_id, $akteur, 'status', mb_substr($text, 0, 1000), la_jetzt()]); }
    catch (\Throwable $e) {}
}

// Aufkleber anlegen: $anzahl Stück für eine Charge-Zeile. Rückgabe IDs (leer = Fehler, $fehler = Schlüssel).
function la_anlegen(int $bestellung_charge_id, ?float $netto, ?float $brutto, ?float $stueck, int $anzahl, string $von, ?string &$fehler = null): array {
    la_schema();
    $fehler = '';
    $bc = one("SELECT bc.*, bp.bestellung_id FROM bestellung_charge bc JOIN bestellung_position bp ON bp.id=bc.bestellung_position_id WHERE bc.id=?", [$bestellung_charge_id]);
    if (!$bc) { $fehler = 'charge'; return []; }
    if ($anzahl < 1 || $anzahl > 200) { $fehler = 'anzahl'; return []; }
    if (!$netto || $netto <= 0) { $fehler = 'netto'; return []; }
    $ids = [];
    for ($i = 0; $i < $anzahl; $i++) {
        q("INSERT INTO lief_aufkleber (bestellung_id, bestellung_position_id, bestellung_charge_id, netto_kg, brutto_kg, stueck, status, gedruckt_am, gedruckt_von)
           VALUES (?,?,?,?,?,?,'aktiv',?,?)",
          [(int)$bc['bestellung_id'], (int)$bc['bestellung_position_id'], $bestellung_charge_id, $netto, $brutto ?: null, $stueck ?: null, la_jetzt(), $von === 'team' ? 'team' : 'lieferant']);
        $ids[] = (int) (function_exists('insert_id') ? insert_id() : scalar("SELECT LAST_INSERT_ID()"));
    }
    la_log((int)$bc['bestellung_id'], $von === 'team' ? 'team' : 'lieferant', $anzahl . ' Gebindeaufkleber gedruckt (Charge ' . $bc['charge_nr'] . ', je ' . la_num($netto) . ' kg netto).');
    return $ids;
}
function la_stornieren(int $id, int $bestellung_id, string $von): bool {
    la_schema();
    $n = q("UPDATE lief_aufkleber SET status='storniert', storniert_am=? WHERE id=? AND bestellung_id=? AND status='aktiv'", [la_jetzt(), $id, $bestellung_id])->rowCount();
    if ($n) la_log($bestellung_id, $von === 'team' ? 'team' : 'lieferant', 'Gebindeaufkleber ' . la_code($id) . ' storniert.');
    return $n > 0;
}
function la_num(?float $x, int $d = 3): string {
    if ($x === null) return '';
    return rtrim(rtrim(number_format($x, $d, ',', '.'), '0'), ',');
}

// --- Firmenadresse „Ship to“ (Produktionsadresse) -------------------------------------------------------------------
// Feld in den Einstellungen (Firma): firma_prod_adresse (mehrzeilig). Fehlt es, gilt die Produktionsadresse Barßel.
function la_ship_to(): array {
    $t = trim(la_meta('firma_prod_adresse'));
    if ($t === '') $t = "Maniso GmbH\nII. Hüllenweg 29\n26676 Barßel\nGermany";
    return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $t))));
}
function la_lagerung_standard(): string { return '24 months, store cool and dry, protected from light'; }   // Standard, am Artikel überschreibbar (item.aufkleber_lagerung)

// Alle Angaben eines Aufklebers (für das PDF).
function la_daten(int $id): ?array {
    $a = one("SELECT la.*, bc.charge_nr, bc.mhd, bc.herstell_datum, bp.item_id, bp.bezeichnung, bp.auftrag_id, b.nummer AS bestellung_nr, b.lieferant_id
              FROM lief_aufkleber la JOIN bestellung_charge bc ON bc.id=la.bestellung_charge_id
              JOIN bestellung_position bp ON bp.id=la.bestellung_position_id JOIN bestellung b ON b.id=la.bestellung_id WHERE la.id=?", [$id]);
    if (!$a) return null;
    $it = $a['item_id'] ? one("SELECT * FROM item WHERE id=?", [(int)$a['item_id']]) : null;
    // Produktname deutsch und kundenneutral: Artikelname, sonst Bezeichnung ohne „Bulk:“ und ohne Auftragsnummer.
    $name = $it ? (string)$it['name'] : trim(preg_replace(['/^(Bulk|Premix):\s*/u', '/\s*\((AB|PA|PR)-[^)]*\)\s*$/u'], '', (string)$a['bezeichnung']));
    $rid = 0;
    if ($it && !empty($it['rezeptur_id']) && ((string)$it['kategorie'] === 'fertig' || (string)$it['form'] === 'premix')) $rid = (int)$it['rezeptur_id'];
    if (!$rid && !$it && !empty($a['auftrag_id'])) $rid = (int) scalar("SELECT COALESCE(a.rezeptur_id, p.rezeptur_id) FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [(int)$a['auftrag_id']]);
    $formel = [];
    if ($rid > 0) {
        $zus = all("SELECT COALESCE(NULLIF(z.bezeichnung,''), i.name) AS n, z.menge_mg FROM rezeptur_zutat z LEFT JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=? ORDER BY z.sort, z.id", [$rid]);
        $sum = array_sum(array_map(fn($z) => (float)$z['menge_mg'], $zus));
        foreach ($zus as $z) $formel[] = [(string)$z['n'], (float)$z['menge_mg'], $sum > 0 ? (float)$z['menge_mg'] / $sum * 100 : 0.0];
        if ($name === '' ) $name = (string) scalar("SELECT name FROM rezeptur WHERE id=?", [$rid]);
    }
    $spec = [];
    if ($it) {
        try { foreach (all("SELECT parameter, wert FROM item_kennwert WHERE item_id=? ORDER BY sort, id LIMIT 6", [(int)$it['id']]) as $k) $spec[] = trim($k['parameter'] . ($k['wert'] !== null && $k['wert'] !== '' ? ': ' . $k['wert'] : '')); } catch (\Throwable $e) {}
    }
    // Regel Nico 13.11.2026: Der Aufkleber klebt bei uns im Lager – Lieferant nur als Lieferantennummer, nie Name oder Adresse.
    $lief = $a['lieferant_id'] ? one("SELECT lieferantennummer, land FROM lieferanten WHERE id=?", [(int)$a['lieferant_id']]) : null;
    $lager = trim((string)($it['aufkleber_lagerung'] ?? ''));
    if ($lager === '') $lager = la_lagerung_standard();
    return [
        'code' => la_code($id), 'name' => $name, 'formel' => $formel, 'spec' => $spec,
        'charge' => (string)$a['charge_nr'], 'herstell' => (string)($a['herstell_datum'] ?? ''), 'mhd' => (string)($a['mhd'] ?? ''),
        'netto' => $a['netto_kg'] !== null ? (float)$a['netto_kg'] : null, 'brutto' => $a['brutto_kg'] !== null ? (float)$a['brutto_kg'] : null,
        'stueck' => $a['stueck'] !== null ? (float)$a['stueck'] : null, 'po' => (string)$a['bestellung_nr'], 'lagerung' => $lager,
        'lieferant_nr' => $lief ? trim((string)$lief['lieferantennummer']) : '',
        'herkunft' => trim((string)($it['herkunftsland'] ?? '')) ?: (string)($lief['land'] ?? ''),
        'ship_to' => la_ship_to(),
    ];
}


// Versand: Vorschlag „Anzahl Pakete“ = nicht stornierte Aufkleber; Hinweis, wenn die Netto-Summe stark von der Bestellmenge abweicht.
function la_versand_hinweis(int $bestellung_id, string $sprache = 'de'): string {
    $st = la_stand($bestellung_id);
    if ($st['gesamt'] === 0) return '';
    $t = fn(string $de, string $en, string $zh) => $sprache === 'de' ? $de : ($sprache === 'zh' ? $zh : $en);
    $o = $t('Gedruckte Aufkleber: ', 'Printed labels: ', '已打印标签：') . $st['gesamt'] . ' · ' . $t('netto zusammen ', 'net total ', '净重合计 ') . la_num($st['netto']) . ' kg';
    $soll = 0.0;
    foreach (all("SELECT menge, einheit FROM bestellung_position WHERE bestellung_id=? AND menge>0", [$bestellung_id]) as $p) {
        $e = mb_strtolower(trim((string)$p['einheit']));
        if ($e === 'kg') $soll += (float)$p['menge']; elseif ($e === 'g') $soll += (float)$p['menge'] / 1000; elseif ($e === 't') $soll += (float)$p['menge'] * 1000;
    }
    if ($soll > 0 && abs($st['netto'] - $soll) / $soll > 0.10)
        $o .= ' – ' . $t('Hinweis: weicht stark von der Bestellmenge ab (', 'Note: differs strongly from the ordered quantity (', '提示：与订单数量差异较大（') . la_num($soll) . ' kg).';
    return $o;
}

}
