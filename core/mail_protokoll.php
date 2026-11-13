<?php
// Mail-Protokoll und Mail-Bremse (Fable-6 T0.4/T0.5, 13.11.2026). Siehe mail_protokoll.md.
// Anlass: Am 13.11.2026 abends hat united-domains den Versand vom Webspace gesperrt (Connection refused auf 587 und 465).
// - Jeder Versuch steht in mail_protokoll (Zeit UTC, Empfänger, Betreff, Status, Fehler). data/mail.log bleibt zusätzlich.
// - Bremse: höchstens MP_PRO_MINUTE Versuche je 60 Sekunden. Was darüber geht, kommt in mail_warteschlange und wird
//   nach der Antwort (mit Wartezeit) bzw. beim nächsten Seitenaufruf und im täglichen Cron abgearbeitet.
// Alles hier ist best-effort: fehlt die DB, wird ungebremst gesendet (eine Mail darf nie an der Bremse scheitern).

const MP_PRO_MINUTE = 20;

function mp_schema(): bool {
    static $ok = null; if ($ok !== null) return $ok;
    try {
        q("CREATE TABLE IF NOT EXISTS mail_protokoll (
            id INT AUTO_INCREMENT PRIMARY KEY,
            zeit DATETIME NOT NULL,
            an VARCHAR(255) NOT NULL DEFAULT '',
            betreff VARCHAR(500) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'versuch',
            fehler VARCHAR(500) NULL,
            quelle VARCHAR(120) NULL,
            KEY idx_zeit (zeit), KEY idx_an (an)
        ) DEFAULT CHARSET=utf8mb4");
        q("CREATE TABLE IF NOT EXISTS mail_warteschlange (
            id INT AUTO_INCREMENT PRIMARY KEY,
            angelegt DATETIME NOT NULL,
            an VARCHAR(255) NOT NULL,
            betreff VARCHAR(500) NOT NULL DEFAULT '',
            text MEDIUMTEXT NULL,
            signatur MEDIUMTEXT NULL,
            anhaenge LONGTEXT NULL,
            protokoll_id INT NULL,
            gesendet_am DATETIME NULL,
            fehler VARCHAR(500) NULL,
            KEY idx_offen (gesendet_am)
        ) DEFAULT CHARSET=utf8mb4");
        $ok = true;
    } catch (\Throwable $e) { $ok = false; }
    return $ok;
}

function mp_quelle(): string {
    $p = (string)($_GET['p'] ?? ''); $s = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return mb_substr(trim($s . ($p !== '' ? '?p=' . $p : '') . (($_POST['aktion'] ?? '') !== '' ? ' ' . (string)$_POST['aktion'] : '')), 0, 120);
}

// Eintrag anlegen; Rückgabe id (0 = kein Protokoll möglich).
function mp_eintrag(string $an, string $betreff, string $status, string $fehler = ''): int {
    if (!mp_schema()) return 0;
    try {
        q("INSERT INTO mail_protokoll (zeit, an, betreff, status, fehler, quelle) VALUES (?,?,?,?,?,?)",
          [gmdate('Y-m-d H:i:s'), mb_substr($an, 0, 255), mb_substr($betreff, 0, 500), $status, $fehler !== '' ? mb_substr($fehler, 0, 500) : null, mp_quelle()]);
        return (int) insert_id();
    } catch (\Throwable $e) { return 0; }
}
function mp_status(int $id, string $status, string $fehler = ''): void {
    if ($id <= 0) return;
    try { q("UPDATE mail_protokoll SET status=?, fehler=?, zeit=? WHERE id=?", [$status, $fehler !== '' ? mb_substr($fehler, 0, 500) : null, gmdate('Y-m-d H:i:s'), $id]); } catch (\Throwable $e) {}
}

// Wie viele Versuche sind in den letzten 60 Sekunden noch frei? (Versuche = gesendet + Fehler + gerade im Versand)
function mail_bremse_frei(): int {
    if (!mp_schema()) return MP_PRO_MINUTE;
    try {
        $n = (int) scalar("SELECT COUNT(*) FROM mail_protokoll WHERE zeit >= ? AND status IN ('gesendet','fehler','versuch')", [gmdate('Y-m-d H:i:s', time() - 60)]);
        return max(0, MP_PRO_MINUTE - $n);
    } catch (\Throwable $e) { return MP_PRO_MINUTE; }
}
function mail_warteschlange_offen(): int {
    if (!mp_schema()) return 0;
    try { return (int) scalar("SELECT COUNT(*) FROM mail_warteschlange WHERE gesendet_am IS NULL"); } catch (\Throwable $e) { return 0; }
}

// In die Warteschlange legen (Anhänge binär -> base64). Rückgabe true = liegt drin.
function mail_einreihen(string $to, string $betreff, string $text, string $sig, array $anhaenge): bool {
    if (!mp_schema()) return false;
    try {
        $anh = array_map(fn($a) => ['name' => (string)($a['name'] ?? ''), 'typ' => (string)($a['typ'] ?? ''), 'b64' => base64_encode((string)($a['daten'] ?? ''))], $anhaenge);
        $pid = mp_eintrag($to, $betreff, 'warteschlange');
        q("INSERT INTO mail_warteschlange (angelegt, an, betreff, text, signatur, anhaenge, protokoll_id) VALUES (?,?,?,?,?,?,?)",
          [gmdate('Y-m-d H:i:s'), $to, mb_substr($betreff, 0, 500), $text, $sig, $anh ? json_encode($anh) : null, $pid ?: null]);
        mail_warteschlange_nach_antwort();
        return true;
    } catch (\Throwable $e) { return false; }
}

// Warteschlange abarbeiten, so weit die Bremse es zulässt. Rückgabe: Anzahl versuchter Mails.
function mail_warteschlange_abarbeiten(int $max = 200): int {
    if (!mp_schema()) return 0;
    $n = 0;
    while ($n < $max && mail_bremse_frei() > 0) {
        $w = null;
        try {
            $w = one("SELECT * FROM mail_warteschlange WHERE gesendet_am IS NULL ORDER BY id LIMIT 1");
            if (!$w) break;
            // als „in Arbeit“ markieren (gegen zwei gleichzeitige Abarbeiter)
            $st = q("UPDATE mail_warteschlange SET gesendet_am=? WHERE id=? AND gesendet_am IS NULL", [gmdate('Y-m-d H:i:s'), (int)$w['id']]);
            if ($st->rowCount() !== 1) continue;
        } catch (\Throwable $e) { break; }
        $anh = [];
        foreach ((array) json_decode((string)($w['anhaenge'] ?? ''), true) as $a) $anh[] = ['name' => $a['name'] ?? '', 'typ' => $a['typ'] ?? '', 'daten' => base64_decode((string)($a['b64'] ?? ''))];
        $f = mail_senden_jetzt((string)$w['an'], (string)$w['betreff'], (string)$w['text'], (string)$w['signatur'], $anh, (int)($w['protokoll_id'] ?? 0));
        if ($f !== '') { try { q("UPDATE mail_warteschlange SET fehler=? WHERE id=?", [mb_substr($f, 0, 500), (int)$w['id']]); } catch (\Throwable $e) {} }
        $n++;
    }
    return $n;
}

// Nach der Antwort an den Browser: Warteschlange abarbeiten, notfalls bis zu 3 Minuten auf freie Plätze warten.
function mail_warteschlange_nach_antwort(): void {
    static $reg = false; if ($reg) return; $reg = true;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @ignore_user_abort(true);
        $bis = time() + 180;
        try {
            while (mail_warteschlange_offen() > 0 && time() < $bis) {
                if (mail_bremse_frei() > 0) { mail_warteschlange_abarbeiten(); continue; }
                sleep(5);
            }
        } catch (\Throwable $e) {}
    });
}

// Beim Seitenaufruf: liegt etwas in der Warteschlange und ist die Bremse frei, nach der Antwort weiterarbeiten.
function mail_warteschlange_pruefen(): void {
    try { if (mail_warteschlange_offen() > 0) mail_warteschlange_nach_antwort(); } catch (\Throwable $e) {}
}

// Liste für Einstellungen -> E-Mail (nur Admin): die letzten $max Einträge, optional Suche im Empfänger.
// Ältere Mails (vor Einführung des Protokolls) kommen aus data/mail.log.
function mp_liste(int $max = 200, string $such = ''): array {
    $rows = [];
    if (mp_schema()) {
        try {
            $rows = $such !== ''
                ? all("SELECT zeit, an, betreff, status, fehler FROM mail_protokoll WHERE an LIKE ? ESCAPE '=' ORDER BY id DESC LIMIT " . (int)$max, ['%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $such) . '%'])
                : all("SELECT zeit, an, betreff, status, fehler FROM mail_protokoll ORDER BY id DESC LIMIT " . (int)$max);
        } catch (\Throwable $e) { $rows = []; }
    }
    if (count($rows) < $max) {
        $ab = $rows ? (string) end($rows)['zeit'] : '';
        if (!$ab) { try { $ab = (string) scalar("SELECT MIN(zeit) FROM mail_protokoll"); } catch (\Throwable $e) { $ab = ''; } }
        foreach (mp_log_lesen($max - count($rows), $such, $ab) as $r) $rows[] = $r;
    }
    return $rows;
}

// data/mail.log rückwärts lesen (letzte ~4 MB). Ein Eintrag gilt als Fehler, wenn danach ein „FEHLER:“-Eintrag mit gleichem
// Empfänger und Betreff folgt. Nur Einträge vor $vor (UTC, Y-m-d H:i:s), damit sich nichts mit dem Protokoll doppelt.
function mp_log_lesen(int $max, string $such = '', string $vor = ''): array {
    if ($max <= 0 || !defined('BX_UPLOADS')) return [];
    $datei = dirname(BX_UPLOADS) . '/mail.log';
    if (!is_file($datei)) return [];
    $groesse = filesize($datei); $start = max(0, $groesse - 4 * 1024 * 1024);
    $fh = @fopen($datei, 'rb'); if (!$fh) return [];
    fseek($fh, $start); $inhalt = (string) stream_get_contents($fh); fclose($fh);
    $eintraege = [];
    foreach (preg_split('/\R/', $inhalt) as $z) {
        if (!preg_match('/^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)[^ ]*  AN: (.*?)  BETREFF: (.*)$/', $z, $m)) continue;
        $eintraege[] = ['zeit' => str_replace('T', ' ', $m[1]), 'an' => $m[2], 'betreff' => $m[3]];
    }
    $out = []; $fehlerFuer = [];
    for ($i = count($eintraege) - 1; $i >= 0 && count($out) < $max; $i--) {
        $e = $eintraege[$i];
        if (strncmp($e['betreff'], 'FEHLER: ', 8) === 0) { $fehlerFuer[$e['an'] . '|' . substr($e['betreff'], 8)] = true; continue; }
        if ($vor !== '' && $e['zeit'] >= $vor) continue;
        if ($such !== '' && stripos($e['an'], $such) === false) continue;
        $k = $e['an'] . '|' . $e['betreff'];
        $st = isset($fehlerFuer[$k]) ? 'fehler' : (strpos($e['an'], '(Testumgebung') === 0 ? 'test' : 'log');
        unset($fehlerFuer[$k]);
        $out[] = ['zeit' => $e['zeit'], 'an' => $e['an'], 'betreff' => $e['betreff'], 'status' => $st, 'fehler' => '', 'aus_log' => true];
    }
    return $out;
}
