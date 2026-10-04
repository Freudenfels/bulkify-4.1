<?php
// Beleg-Posteingang: Belege (Eingangsrechnungen, Quittungen, Kassenbons) hochladen → KI liest aus →
// erfassen → Datei speichern → Steuerberater-Paket (Excel-CSV + Dateien als ZIP). Eigenes Register
// (Tabelle bu_beleg_eingang), unabhängig von den Eingangsrechnungen/Kreditoren. Kanäle: Online-Upload,
// Handy-Foto per Token-Link (ohne Login), E-Mail (Gerüst, Postfach noch zu hinterlegen).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/erp.php';   // meta_get/meta_set
require_once __DIR__ . '/ki.php';    // ki_bereit, ki_datei_frage

function be_init(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS bu_beleg_eingang (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        quelle        VARCHAR(10)  NOT NULL DEFAULT 'upload',   -- upload | foto | mail
        datei         VARCHAR(255) NULL,                        -- gespeicherter Pfad relativ zu BX_UPLOADS
        orig_name     VARCHAR(255) NULL,
        mime          VARCHAR(100) NULL,
        status        VARCHAR(20)  NOT NULL DEFAULT 'neu',      -- neu | erfasst | verbucht | verworfen
        belegart      VARCHAR(20)  NOT NULL DEFAULT 'sonstiges',-- eingangsrechnung | quittung | sonstiges
        lieferant_name VARCHAR(190) NULL,
        beleg_nummer  VARCHAR(80)  NULL,
        datum         DATE NULL,
        netto         DECIMAL(14,2) NOT NULL DEFAULT 0,
        ust_prozent   DECIMAL(5,2)  NOT NULL DEFAULT 0,
        ust_betrag    DECIMAL(14,2) NOT NULL DEFAULT 0,
        brutto        DECIMAL(14,2) NOT NULL DEFAULT 0,
        waehrung      VARCHAR(3)   NOT NULL DEFAULT 'EUR',
        kategorie     VARCHAR(80)  NULL,
        notiz         TEXT NULL,
        ki_json       MEDIUMTEXT NULL,
        ki_ok         TINYINT(1)   NOT NULL DEFAULT 0,
        erfasst_von   INT NULL,
        angelegt      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status), KEY idx_datum (datum)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// --- Dateien --------------------------------------------------------------
function be_uploads_dir(): string {
    $d = BX_UPLOADS . '/belege';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}
function be_erlaubte_endung(string $name): ?string {
    $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($e, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'gif'], true) ? $e : null;
}
// Speichert eine Upload-Datei ($_FILES-Eintrag). Rückgabe ['datei','orig','mime','pfad'] oder null.
function be_datei_speichern(array $file): ?array {
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) return null;
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 25 * 1024 * 1024) return null;   // max 25 MB
    $orig = (string)($file['name'] ?? 'beleg');
    $ext  = be_erlaubte_endung($orig);
    if (!$ext) return null;
    $dir = be_uploads_dir();
    $unter = date('Y-m'); if (!is_dir("$dir/$unter")) @mkdir("$dir/$unter", 0775, true);
    $name = $unter . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $ziel = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $ziel)) return null;
    $mime = function_exists('finfo_open') ? (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $ziel) ?: '') : ($file['type'] ?? '');
    return ['datei' => 'belege/' . $name, 'orig' => mb_substr($orig, 0, 255), 'mime' => (string)$mime, 'pfad' => $ziel];
}
// Absoluter Pfad zu einer gespeicherten Beleg-Datei (datei ist relativ zu BX_UPLOADS).
function be_pfad(string $datei): string { return BX_UPLOADS . '/' . ltrim($datei, '/'); }

// --- KI-Auslesung ---------------------------------------------------------
// Liest einen Beleg per KI aus. Rückgabe: ['ok'=>bool, 'fehler'=>?, 'daten'=>[…], 'roh'=>jsonstring]
function be_ki_auslesen(string $pfad): array {
    if (!ki_bereit()) return ['ok' => false, 'fehler' => 'KI ist nicht eingerichtet (Einstellungen → KI).', 'daten' => [], 'roh' => ''];
    $prompt = "Dies ist ein Buchhaltungsbeleg (Eingangsrechnung, Quittung oder Kassenbon). Lies die Kopfdaten "
        . "aus und gib NUR JSON zurück:\n"
        . '{"belegart":"eingangsrechnung","lieferant_name":"","beleg_nummer":"","datum":"","netto":0,"ust_prozent":19,"brutto":0,"waehrung":"EUR","kategorie":""}' . "\n"
        . "belegart = eingangsrechnung | quittung | sonstiges. datum im Format YYYY-MM-DD. "
        . "brutto = Endbetrag inkl. USt (Gesamtsumme). netto = Nettobetrag. ust_prozent = USt-Satz in Prozent (0 wenn keiner). "
        . "lieferant_name = Aussteller/Händler/Firma. beleg_nummer = Rechnungs-/Belegnummer. waehrung ISO (EUR/USD/CNY …). "
        . "kategorie = grobe Ausgabenkategorie (Wareneinkauf, Bürobedarf, Reisekosten, Bewirtung, Kfz, Software, Gebühren, Telekommunikation, Miete, Sonstiges). "
        . "Zahlen mit Punkt als Dezimaltrennzeichen, keine Tausenderpunkte. Nichts erfinden – unbekannte Felder leer/0.";
    $r = ki_datei_frage($pfad, $prompt, ['json' => true, 'max_tokens' => 1200, 'zweck' => 'beleg-eingang']);
    if (empty($r['ok'])) return ['ok' => false, 'fehler' => (string)($r['fehler'] ?? 'Beleg konnte nicht gelesen werden.'), 'daten' => [], 'roh' => ''];
    $d = is_array($r['daten'] ?? null) ? $r['daten'] : [];
    $num  = fn($x) => (float) str_replace(',', '.', (string)$x);
    $gilt = fn($s) => (is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) ? $s : null;
    $brutto = $num($d['brutto'] ?? 0); $netto = $num($d['netto'] ?? 0); $ustP = $num($d['ust_prozent'] ?? 0);
    if ($brutto <= 0 && $netto > 0) $brutto = round($netto * (1 + $ustP / 100), 2);
    if ($netto <= 0 && $brutto > 0) $netto = $ustP > 0 ? round($brutto / (1 + $ustP / 100), 2) : $brutto;
    $art = strtolower(trim((string)($d['belegart'] ?? 'sonstiges')));
    if (!in_array($art, ['eingangsrechnung', 'quittung', 'sonstiges'], true)) $art = 'sonstiges';
    return ['ok' => true, 'fehler' => null, 'roh' => json_encode($d, JSON_UNESCAPED_UNICODE),
        'daten' => [
            'belegart'       => $art,
            'lieferant_name' => trim((string)($d['lieferant_name'] ?? '')),
            'beleg_nummer'   => trim((string)($d['beleg_nummer'] ?? '')),
            'datum'          => $gilt($d['datum'] ?? null),
            'netto'          => round($netto, 2),
            'ust_prozent'    => $ustP,
            'brutto'         => round($brutto, 2),
            'waehrung'       => strtoupper(trim((string)($d['waehrung'] ?? 'EUR'))) ?: 'EUR',
            'kategorie'      => trim((string)($d['kategorie'] ?? '')),
        ]];
}

// --- CRUD -----------------------------------------------------------------
function be_anlegen(array $d): int {
    be_init();
    $netto = round((float)($d['netto'] ?? 0), 2);
    $ustP  = (float)($d['ust_prozent'] ?? 0);
    $ust   = round($netto * $ustP / 100, 2);
    $brutto = isset($d['brutto']) && $d['brutto'] !== '' ? round((float)$d['brutto'], 2) : round($netto + $ust, 2);
    q("INSERT INTO bu_beleg_eingang
        (quelle,datei,orig_name,mime,status,belegart,lieferant_name,beleg_nummer,datum,netto,ust_prozent,ust_betrag,brutto,waehrung,kategorie,notiz,ki_json,ki_ok,erfasst_von)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [(string)($d['quelle'] ?? 'upload'), $d['datei'] ?? null, $d['orig_name'] ?? null, $d['mime'] ?? null,
       (string)($d['status'] ?? 'neu'), (string)($d['belegart'] ?? 'sonstiges'),
       trim((string)($d['lieferant_name'] ?? '')) ?: null, trim((string)($d['beleg_nummer'] ?? '')) ?: null,
       $d['datum'] ?? null, $netto, $ustP, $ust, $brutto, strtoupper((string)($d['waehrung'] ?? 'EUR')),
       trim((string)($d['kategorie'] ?? '')) ?: null, trim((string)($d['notiz'] ?? '')) ?: null,
       $d['ki_json'] ?? null, !empty($d['ki_ok']) ? 1 : 0, $d['erfasst_von'] ?? null]);
    return insert_id();
}
function be_update(int $id, array $d): void {
    $r = one("SELECT * FROM bu_beleg_eingang WHERE id=?", [$id]);
    if (!$r || $r['status'] === 'verbucht') return;
    $netto = round((float)($d['netto'] ?? $r['netto']), 2);
    $ustP  = (float)($d['ust_prozent'] ?? $r['ust_prozent']);
    $ust   = round($netto * $ustP / 100, 2);
    $brutto = isset($d['brutto']) && $d['brutto'] !== '' ? round((float)$d['brutto'], 2) : round($netto + $ust, 2);
    q("UPDATE bu_beleg_eingang SET belegart=?, lieferant_name=?, beleg_nummer=?, datum=?, netto=?, ust_prozent=?,
          ust_betrag=?, brutto=?, waehrung=?, kategorie=?, notiz=?, status=? WHERE id=?",
      [(string)($d['belegart'] ?? $r['belegart']), trim((string)($d['lieferant_name'] ?? $r['lieferant_name'])) ?: null,
       trim((string)($d['beleg_nummer'] ?? $r['beleg_nummer'])) ?: null, $d['datum'] ?? $r['datum'],
       $netto, $ustP, $ust, $brutto, strtoupper((string)($d['waehrung'] ?? $r['waehrung'])),
       trim((string)($d['kategorie'] ?? $r['kategorie'])) ?: null, trim((string)($d['notiz'] ?? $r['notiz'])) ?: null,
       (string)($d['status'] ?? $r['status']), $id]);
}
function be_status_setzen(int $id, string $status): void {
    if (!in_array($status, ['neu', 'erfasst', 'verbucht', 'verworfen'], true)) return;
    q("UPDATE bu_beleg_eingang SET status=? WHERE id=?", [$status, $id]);
}
function be_get(int $id): ?array { return $id ? one("SELECT * FROM bu_beleg_eingang WHERE id=?", [$id]) : null; }
function be_zaehlen(string $status = 'neu'): int {
    be_init();
    return (int) scalar("SELECT COUNT(*) FROM bu_beleg_eingang WHERE status=?", [$status]);
}
function be_liste(string $status = '', string $q = ''): array {
    be_init();
    $where = "status<>'verworfen'"; $args = [];
    if ($status !== '') { $where = "status=?"; $args[] = $status; }
    $rows = all("SELECT * FROM bu_beleg_eingang WHERE $where ORDER BY (datum IS NULL), datum DESC, id DESC", $args);
    if ($q !== '') {
        $n = mb_strtolower($q);
        $rows = array_values(array_filter($rows, fn($r) =>
            mb_strpos(mb_strtolower((string)$r['lieferant_name']), $n) !== false
            || mb_strpos(mb_strtolower((string)$r['beleg_nummer']), $n) !== false
            || mb_strpos(mb_strtolower((string)$r['kategorie']), $n) !== false));
    }
    return $rows;
}

// --- Foto-Token (Handy-Upload ohne Login) ---------------------------------
function be_token(): string {
    $t = (string) meta_get('belege_upload_token', '');
    if ($t === '') { $t = bin2hex(random_bytes(16)); meta_set('belege_upload_token', $t); }
    return $t;
}
function be_token_neu(): string { $t = bin2hex(random_bytes(16)); meta_set('belege_upload_token', $t); return $t; }
function be_token_ok(string $t): bool { $t = trim($t); return $t !== '' && hash_equals(be_token(), $t); }

// --- Export: Steuerberater-Paket -----------------------------------------
function be_csv_zeile(array $f): string {
    $out = [];
    foreach ($f as $v) { $v = (string)$v; if (preg_match('/[";\r\n]/', $v)) $v = '"' . str_replace('"', '""', $v) . '"'; $out[] = $v; }
    return implode(';', $out) . "\r\n";
}
function be_betrag(float $x): string { return number_format($x, 2, ',', ''); }
function be_belege_zeitraum(string $von, string $bis): array {
    be_init();
    $where = "status IN ('erfasst','verbucht')"; $args = [];
    if ($von !== '') { $where .= " AND datum >= ?"; $args[] = $von; }
    if ($bis !== '') { $where .= " AND datum <= ?"; $args[] = $bis; }
    return all("SELECT * FROM bu_beleg_eingang WHERE $where ORDER BY datum ASC, id ASC", $args);
}
function be_export_csv(string $von = '', string $bis = ''): string {
    $rows = be_belege_zeitraum($von, $bis);
    $csv  = "\xEF\xBB\xBF";
    $csv .= be_csv_zeile(['Datum', 'Belegart', 'Lieferant/Aussteller', 'Belegnr', 'Kategorie',
                          'Netto', 'USt-Satz', 'USt-Betrag', 'Brutto', 'Waehrung', 'Status', 'Datei']);
    foreach ($rows as $r) {
        $csv .= be_csv_zeile([
            $r['datum'] ? date('d.m.Y', strtotime($r['datum'])) : '',
            $r['belegart'], $r['lieferant_name'], $r['beleg_nummer'], $r['kategorie'],
            be_betrag((float)$r['netto']), number_format((float)$r['ust_prozent'], 0) . '%',
            be_betrag((float)$r['ust_betrag']), be_betrag((float)$r['brutto']), $r['waehrung'],
            $r['status'], $r['datei'] ? basename($r['datei']) : '',
        ]);
    }
    return $csv;
}
// Baut das Steuerberater-ZIP (belege.csv + alle Belegdateien). Rückgabe ['name','data'].
function be_export_zip(string $von = '', string $bis = ''): array {
    $rows = be_belege_zeitraum($von, $bis);
    $eintraege = ['belege.csv' => be_export_csv($von, $bis)];
    $benutzt = [];
    foreach ($rows as $r) {
        if (empty($r['datei'])) continue;
        $pfad = be_pfad($r['datei']);
        if (!is_file($pfad)) continue;
        $basis = $r['datum'] ? date('Ymd', strtotime($r['datum'])) : 'ohne-datum';
        $lief = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($r['lieferant_name'] ?: 'beleg'));
        $ext = pathinfo($r['datei'], PATHINFO_EXTENSION);
        $name = 'belege/' . $basis . '_' . $r['id'] . '_' . mb_substr($lief, 0, 40) . ($ext ? '.' . $ext : '');
        while (isset($benutzt[$name])) $name .= '_';
        $benutzt[$name] = true;
        $eintraege[$name] = (string) @file_get_contents($pfad);
    }
    return ['name' => 'steuerberater_belege_' . ($von ?: 'alle') . '_' . ($bis ?: date('Y-m-d')) . '.zip',
            'data' => bu_zip($eintraege)];
}

// Minimaler ZIP-Writer (Store/ohne Kompression) – ohne ZipArchive-Extension. $files: [name => bytes].
function bu_zip(array $files): string {
    $local = ''; $central = ''; $n = 0; $offset = 0;
    $dosTime = 0; $dosDate = 0x21;   // 1980-01-01 (fester Platzhalter)
    foreach ($files as $name => $data) {
        $name = str_replace('\\', '/', (string)$name);
        $data = (string)$data;
        $crc = crc32($data); $len = strlen($data); $nl = strlen($name);
        $lf = "PK\x03\x04" . pack('v', 20) . pack('v', 0) . pack('v', 0)
            . pack('v', $dosTime) . pack('v', $dosDate)
            . pack('V', $crc) . pack('V', $len) . pack('V', $len)
            . pack('v', $nl) . pack('v', 0) . $name;
        $local .= $lf . $data;
        $central .= "PK\x01\x02" . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', 0)
            . pack('v', $dosTime) . pack('v', $dosDate)
            . pack('V', $crc) . pack('V', $len) . pack('V', $len)
            . pack('v', $nl) . pack('v', 0) . pack('v', 0)
            . pack('v', 0) . pack('v', 0) . pack('V', 0)
            . pack('V', $offset) . $name;
        $offset += strlen($lf) + $len; $n++;
    }
    $eocd = "PK\x05\x06" . pack('v', 0) . pack('v', 0) . pack('v', $n) . pack('v', $n)
          . pack('V', strlen($central)) . pack('V', strlen($local)) . pack('v', 0);
    return $local . $central . $eocd;
}

// --- E-Mail-Abholung (GERÜST) --------------------------------------------
// Holt Belege aus einem Postfach per IMAP. Braucht die PHP-imap-Extension + app_meta:
// belege_imap_host, belege_imap_user, belege_imap_pass, (belege_imap_port, belege_imap_ssl).
// Ohne Konfiguration/Extension passiert nichts (gibt Hinweis zurück). Noch nicht aktiv getestet.
function be_mail_bereit(): bool {
    return function_exists('imap_open')
        && (string) meta_get('belege_imap_host', '') !== ''
        && (string) meta_get('belege_imap_user', '') !== '';
}
function be_mail_abholen(int $max = 20): array {
    if (!function_exists('imap_open')) return ['ok' => false, 'grund' => 'PHP-imap-Extension fehlt auf dem Server.', 'anzahl' => 0];
    $host = (string) meta_get('belege_imap_host', '');
    $user = (string) meta_get('belege_imap_user', '');
    $pass = (string) meta_get('belege_imap_pass', '');
    if ($host === '' || $user === '') return ['ok' => false, 'grund' => 'Kein Postfach hinterlegt (Einstellungen: belege_imap_*).', 'anzahl' => 0];
    $port = (int) (meta_get('belege_imap_port', '993') ?: 993);
    $flags = (meta_get('belege_imap_ssl', '1') === '0') ? '/imap' : '/imap/ssl';
    $mbox = @imap_open('{' . $host . ':' . $port . $flags . '}INBOX', $user, $pass);
    if (!$mbox) return ['ok' => false, 'grund' => 'IMAP-Login fehlgeschlagen: ' . imap_last_error(), 'anzahl' => 0];
    $neu = 0;
    $ids = imap_search($mbox, 'UNSEEN') ?: [];
    foreach (array_slice($ids, 0, $max) as $num) {
        $struktur = imap_fetchstructure($mbox, $num);
        $teile = $struktur->parts ?? [];
        foreach ($teile as $i => $teil) {
            $name = '';
            foreach (array_merge($teil->parameters ?? [], $teil->dparameters ?? []) as $p)
                if (in_array(strtolower($p->attribute), ['name', 'filename'], true)) $name = $p->value;
            if ($name === '' || !be_erlaubte_endung($name)) continue;
            $roh = imap_fetchbody($mbox, $num, (string)($i + 1));
            if (($teil->encoding ?? 0) == 3) $roh = base64_decode($roh);
            elseif (($teil->encoding ?? 0) == 4) $roh = quoted_printable_decode($roh);
            $ext = be_erlaubte_endung($name);
            $dir = be_uploads_dir(); $unter = date('Y-m'); if (!is_dir("$dir/$unter")) @mkdir("$dir/$unter", 0775, true);
            $rel = 'belege/' . $unter . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
            file_put_contents(BX_UPLOADS . '/' . $rel, $roh);
            $ki = be_ki_auslesen(BX_UPLOADS . '/' . $rel);
            $d = ($ki['ok'] ? $ki['daten'] : []) + ['quelle' => 'mail', 'datei' => $rel, 'orig_name' => $name, 'status' => 'neu', 'ki_ok' => $ki['ok'], 'ki_json' => $ki['roh'] ?? null];
            be_anlegen($d);
            $neu++;
        }
        imap_setflag_full($mbox, (string)$num, "\\Seen");
    }
    imap_close($mbox);
    return ['ok' => true, 'grund' => '', 'anzahl' => $neu];
}
