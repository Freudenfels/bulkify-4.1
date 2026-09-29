<?php
// v3-Dokumente-Migration: die hochgeladenen Dateien aus v3 (bulkify-data/board.sqlite + uploads/) nach v4
// uebernehmen. v3 speichert je Auftrag Anhaenge in `dateien` (gate: coa|rechnung|pib|etikett|qualitaet),
// Rohstoff-CoAs in `coa_dateien` (je Zutat) und Lieferanten-CoA/Spec in `lieferant_zutat_doc`. Die Dateien
// liegen im uploads/-Ordner. Zuordnung v3->v4 ueber v3_id (auftrag/item). Idempotent per dokument.v3_ref.
//
// Aufruf: v3_dok_import('/pfad/zu/bulkify-data', $commit=false). $commit=false = Trockenlauf (nur zaehlen).
// SQLite-Treiber (pdo_sqlite) noetig.

// gate -> [v4-typ, kunde_sichtbar]. Rechnungen sieht der Kunde; der Rest ist erstmal intern (spaeter freigeben).
function v3_dok_gate_map(string $gate): array {
    return [
        'rechnung'  => ['rechnung', 1],
        'etikett'   => ['etikett', 0],
        'coa'       => ['analyse', 0],
        'qualitaet' => ['analyse', 0],
        'pib'       => ['sonstiges', 0],
    ][$gate] ?? ['sonstiges', 0];
}
// "COA · L-Glutamin (COA L-Glutamine.pdf)" -> Titel "COA · L-Glutamin", Dateiname "COA L-Glutamine.pdf".
function v3_dok_titel_datei(string $original, string $stored): array {
    $original = trim($original);
    if (preg_match('/^(.*?)\s*\(([^()]*\.[A-Za-z0-9]{2,5})\)\s*$/', $original, $m)) return [trim($m[1]) ?: $m[2], $m[2]];
    return [$original ?: $stored, $original ?: $stored];
}
function v3_dok_kopiere(string $src, string $praefix): ?string {
    if (!is_file($src)) return null;
    if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
    $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($src, PATHINFO_EXTENSION)));
    $fn  = $praefix . '_' . bin2hex(random_bytes(5)) . ($ext ? '.' . $ext : '');
    return @copy($src, BX_UPLOADS . '/' . $fn) ? $fn : null;
}
function v3_dok_insert(string $objTyp, int $objId, string $typ, ?string $titel, string $datei, string $origName,
                       ?string $datum, ?int $lieferant_id, int $sicht, string $v3ref): void {
    $hash = @md5_file(BX_UPLOADS . '/' . $datei) ?: null;
    $dd = ($datum && preg_match('/^\d{4}-\d{2}-\d{2}/', $datum)) ? substr($datum, 0, 10) : null;
    q("INSERT INTO dokument (objekt_typ,objekt_id,typ,lieferant_id,titel,datei,datei_orig,dok_datum,datei_hash,kunde_sichtbar,hochgeladen_von,v3_ref)
       VALUES (?,?,?,?,?,?,?,?,?,?,'v3',?)",
      [$objTyp, $objId, $typ, $lieferant_id, $titel ? mb_substr($titel, 0, 190) : null, $datei, mb_substr($origName, 0, 255), $dd, $hash, $sicht, $v3ref]);
}

function v3_dok_import(string $v3dir, bool $commit = false): array {
    $v3dir = rtrim($v3dir, '/\\');
    $db = $v3dir . '/board.sqlite';
    $up = $v3dir . '/uploads';
    if (!extension_loaded('pdo_sqlite')) return ['ok' => false, 'fehler' => 'PHP-Erweiterung pdo_sqlite fehlt auf diesem Server.'];
    if (!is_file($db)) return ['ok' => false, 'fehler' => 'board.sqlite nicht gefunden unter ' . $db];
    if (!is_dir($up)) return ['ok' => false, 'fehler' => 'uploads/-Ordner nicht gefunden unter ' . $up];
    try { $v3 = new PDO('sqlite:' . $db); $v3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
    catch (Throwable $e) { return ['ok' => false, 'fehler' => 'board.sqlite nicht lesbar: ' . $e->getMessage()]; }

    $hatTab = fn($t) => (bool) $v3->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $v3->quote($t))->fetchColumn();
    $liefV3 = (bool) scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_name='lieferanten' AND column_name='v3_id'");
    $st = ['ok' => true, 'commit' => $commit, 'dateien' => 0, 'coa' => 0, 'liefdoc' => 0,
           'schon' => 0, 'kein_auftrag' => 0, 'kein_item' => 0, 'datei_fehlt' => 0, 'gates' => []];

    // 1) dateien (je Auftrag): CoA/Rechnung/PIB/Etikett/Qualitaet
    if ($hatTab('dateien')) {
        foreach ($v3->query("SELECT id,auftrag_id,gate,original,stored,uploaded_at FROM dateien ORDER BY id") as $r) {
            $ref = 'dateien:' . (int)$r['id'];
            if (scalar("SELECT id FROM dokument WHERE v3_ref=?", [$ref])) { $st['schon']++; continue; }
            $a4 = (int) scalar("SELECT id FROM auftrag WHERE v3_id=?", [(int)$r['auftrag_id']]);
            if (!$a4) { $st['kein_auftrag']++; continue; }
            $src = $up . '/' . $r['stored'];
            if (!is_file($src)) { $st['datei_fehlt']++; continue; }
            [$typ, $sicht] = v3_dok_gate_map((string)$r['gate']);
            [$titel, $orig] = v3_dok_titel_datei((string)$r['original'], (string)$r['stored']);
            $st['gates'][(string)$r['gate']] = ($st['gates'][(string)$r['gate']] ?? 0) + 1;
            if ($commit) {
                $fn = v3_dok_kopiere($src, 'v3_' . $r['gate'] . '_a' . $a4);
                if (!$fn) { $st['datei_fehlt']++; continue; }
                v3_dok_insert('auftrag', $a4, $typ, $titel, $fn, $orig, (string)$r['uploaded_at'], null, $sicht, $ref);
            }
            $st['dateien']++;
        }
    }
    // 2) coa_dateien (je Zutat/Rohstoff)
    if ($hatTab('coa_dateien')) {
        foreach ($v3->query("SELECT id,zutat_id,typ,original,stored,uploaded_at FROM coa_dateien ORDER BY id") as $r) {
            $ref = 'coa_dateien:' . (int)$r['id'];
            if (scalar("SELECT id FROM dokument WHERE v3_ref=?", [$ref])) { $st['schon']++; continue; }
            $i4 = (int) scalar("SELECT id FROM item WHERE v3_id=?", [(int)$r['zutat_id']]);
            if (!$i4) { $st['kein_item']++; continue; }
            $src = $up . '/' . $r['stored'];
            if (!is_file($src)) { $st['datei_fehlt']++; continue; }
            $typ = strtolower((string)$r['typ']) === 'spec' ? 'spec' : 'coa';
            [$titel, $orig] = v3_dok_titel_datei((string)$r['original'], (string)$r['stored']);
            if ($commit) {
                $fn = v3_dok_kopiere($src, 'v3_coa_i' . $i4);
                if (!$fn) { $st['datei_fehlt']++; continue; }
                v3_dok_insert('item', $i4, $typ, $titel, $fn, $orig, (string)$r['uploaded_at'], null, 0, $ref);
            }
            $st['coa']++;
        }
    }
    // 3) lieferant_zutat_doc (CoA/Spec je Lieferant+Rohstoff)
    if ($hatTab('lieferant_zutat_doc')) {
        foreach ($v3->query("SELECT id,lieferant_id,zutat_id,coa_datei,coa_orig,spec_datei,spec_orig,updated_at FROM lieferant_zutat_doc ORDER BY id") as $r) {
            $i4 = (int) scalar("SELECT id FROM item WHERE v3_id=?", [(int)$r['zutat_id']]);
            if (!$i4) { $st['kein_item']++; continue; }
            $lid = $liefV3 ? ((int) scalar("SELECT id FROM lieferanten WHERE v3_id=?", [(int)$r['lieferant_id']]) ?: null) : null;
            foreach ([['coa', $r['coa_datei'], $r['coa_orig']], ['spec', $r['spec_datei'], $r['spec_orig']]] as [$typ, $datei, $orig]) {
                if (!$datei) continue;
                $ref = 'liefdoc:' . (int)$r['id'] . ':' . $typ;
                if (scalar("SELECT id FROM dokument WHERE v3_ref=?", [$ref])) { $st['schon']++; continue; }
                $src = $up . '/' . $datei;
                if (!is_file($src)) { $st['datei_fehlt']++; continue; }
                if ($commit) {
                    $fn = v3_dok_kopiere($src, 'v3_' . $typ . '_i' . $i4);
                    if (!$fn) { $st['datei_fehlt']++; continue; }
                    v3_dok_insert('item', $i4, $typ, ($orig ?: $typ), $fn, ($orig ?: $datei), (string)$r['updated_at'], $lid, 0, $ref);
                }
                $st['liefdoc']++;
            }
        }
    }
    $st['gesamt'] = $st['dateien'] + $st['coa'] + $st['liefdoc'];
    return $st;
}
