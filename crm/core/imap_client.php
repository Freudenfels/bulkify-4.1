<?php
// Schlanker IMAP-Abruf OHNE die PHP-IMAP-Erweiterung - nur ueber TLS-Socket (OpenSSL) und mbstring.
// Gebaut, weil der Live-Server ext-imap nicht hat. Kann genau so viel, wie der E-Mail-Eingang
// braucht: anmelden, INBOX waehlen, ungelesene Mails suchen, roh holen, als gelesen markieren.
//
// Bewusst minimal und defensiv: jeder Lesevorgang hat ein Timeout, nichts wirft nach aussen.

class BxImap {
    private $fp = null;
    private int $tag = 0;
    public string $fehler = '';

    public function __construct(
        private string $host, private int $port = 993,
        private bool $ssl = true, private int $timeout = 30
    ) {}

    public function verbinden(): bool {
        $ziel = ($this->ssl ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $fp = @stream_socket_client($ziel, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) { $this->fehler = 'Verbindung fehlgeschlagen: ' . trim($errstr . ' (' . $errno . ')'); return false; }
        stream_set_timeout($fp, $this->timeout);
        $this->fp = $fp;
        $gruss = $this->zeile();   // Server-Begruessung "* OK ..."
        if (stripos((string)$gruss, '* OK') === false) { $this->fehler = 'Keine IMAP-Begruessung.'; return false; }
        return true;
    }

    public function anmelden(string $user, string $pass): bool {
        $q = fn(string $s): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
        [$ok, $lines] = $this->befehl('LOGIN ' . $q($user) . ' ' . $q($pass));
        if (!$ok) $this->fehler = 'Anmeldung abgelehnt: ' . $this->letzteMeldung($lines);
        return $ok;
    }

    public function waehlen(string $ordner = 'INBOX'): bool {
        $q = '"' . str_replace('"', '\\"', $ordner) . '"';
        [$ok, $lines] = $this->befehl('SELECT ' . $q);
        if (!$ok) $this->fehler = 'Ordner nicht waehlbar: ' . $this->letzteMeldung($lines);
        return $ok;
    }

    // UIDs der ungelesenen Mails.
    public function ungelesen(): array {
        [$ok, $lines] = $this->befehl('UID SEARCH UNSEEN');
        if (!$ok) { $this->fehler = 'Suche fehlgeschlagen.'; return []; }
        foreach ($lines as $l) {
            if (preg_match('/^\*\s+SEARCH\b(.*)$/i', trim($l), $m)) {
                $ids = array_map('intval', preg_split('/\s+/', trim($m[1])) ?: []);
                return array_values(array_filter($ids, fn($x) => $x > 0));
            }
        }
        return [];
    }

    // Rohe Mail (Header + Body) einer UID. BODY.PEEK[] setzt \Seen NICHT - das machen wir selbst,
    // erst nachdem die Mail wirklich verarbeitet ist.
    public function roh(int $uid, int $max_bytes = 5000000): ?string {
        $tag = $this->senden('UID FETCH ' . $uid . ' BODY.PEEK[]');
        $raw = null;
        while (true) {
            $line = $this->zeile();
            if ($line === null) return $raw;
            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $n = (int)$m[1]; $data = ''; $gelesen = 0;
                while ($gelesen < $n) {
                    $chunk = fread($this->fp, min(8192, $n - $gelesen));
                    if ($chunk === false || $chunk === '') { $info = stream_get_meta_data($this->fp); if (!empty($info['timed_out'])) break; break; }
                    $gelesen += strlen($chunk);
                    if (strlen($data) < $max_bytes) $data .= $chunk;   // zu grosse Mails nur bis zur Grenze behalten
                }
                $raw = $data;
                continue;   // weiterlesen bis zur abschliessenden Tag-Zeile
            }
            if (preg_match('/^' . preg_quote($tag, '/') . '\s+(OK|NO|BAD)\b/i', $line)) return $raw;
        }
    }

    public function gelesen(int $uid): void { $this->befehl('UID STORE ' . $uid . ' +FLAGS (\\Seen)'); }
    public function abmelden(): void { if ($this->fp) { @$this->befehl('LOGOUT'); @fclose($this->fp); $this->fp = null; } }

    // --- intern ---------------------------------------------------------------------------------
    private function senden(string $cmd): string {
        $tag = 'A' . (++$this->tag);
        @fwrite($this->fp, $tag . ' ' . $cmd . "\r\n");
        return $tag;
    }
    // Befehl senden und bis zur Tag-Antwort lesen. Rueckgabe [ok(bool), zeilen(array)].
    private function befehl(string $cmd): array {
        $tag = $this->senden($cmd);
        $lines = [];
        while (true) {
            $line = $this->zeile();
            if ($line === null) return [false, $lines];
            $lines[] = $line;
            if (preg_match('/^' . preg_quote($tag, '/') . '\s+(OK|NO|BAD)\b/i', $line, $m))
                return [strtoupper($m[1]) === 'OK', $lines];
        }
    }
    private function zeile(): ?string {
        if (!$this->fp) return null;
        $line = fgets($this->fp);
        if ($line === false) return null;
        return $line;
    }
    private function letzteMeldung(array $lines): string {
        $l = trim((string)end($lines));
        return mb_substr(preg_replace('/^A\d+\s+(OK|NO|BAD)\s*/i', '', $l), 0, 200);
    }
}

// --- Rohe Mail (RFC822) in die Felder zerlegen, die der Eingang braucht. ------------------------
// Rueckgabe: ['from_name','from_email','subject','date','body'].
function mail_raw_parsen(string $raw): array {
    $raw = str_replace("\r\n", "\n", $raw);
    $trenn = strpos($raw, "\n\n");
    $kopf = $trenn === false ? $raw : substr($raw, 0, $trenn);
    $rumpf = $trenn === false ? '' : substr($raw, $trenn + 2);

    $header = mail_header_parsen($kopf);
    $subject = mail_header_dekodieren((string)($header['subject'] ?? ''));
    [$vonName, $vonEmail] = mail_von_zerlegen((string)($header['from'] ?? ''));
    $datum = !empty($header['date']) ? gmdate('Y-m-d H:i:s', strtotime((string)$header['date']) ?: time()) : gmdate('Y-m-d H:i:s');

    $body = mail_koerper_text($header, $rumpf);
    return ['from_name' => $vonName, 'from_email' => $vonEmail, 'subject' => $subject, 'date' => $datum, 'body' => trim($body)];
}

// Header einlesen (gefaltete Zeilen zusammenfuehren), Schluessel klein.
function mail_header_parsen(string $kopf): array {
    $out = []; $cur = null;
    foreach (explode("\n", $kopf) as $zeile) {
        if ($zeile === '') continue;
        if (($zeile[0] === ' ' || $zeile[0] === "\t") && $cur !== null) {
            $out[$cur] .= ' ' . trim($zeile);   // Fortsetzung einer gefalteten Zeile
            continue;
        }
        $p = strpos($zeile, ':');
        if ($p === false) continue;
        $cur = strtolower(trim(substr($zeile, 0, $p)));
        $out[$cur] = trim(substr($zeile, $p + 1));
    }
    return $out;
}

// RFC2047-kodierte Kopfzeilen (=?UTF-8?B?...?=) lesbar machen - ohne ext-imap, ueber mbstring.
function mail_header_dekodieren(string $s): string {
    if ($s === '') return '';
    $d = @mb_decode_mimeheader($s);
    return $d !== false && $d !== '' ? trim($d) : trim($s);
}

function mail_von_zerlegen(string $from): array {
    $from = mail_header_dekodieren($from);
    if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>/', $from, $m)) return [trim($m[1]), trim(mb_strtolower($m[2]))];
    if (filter_var(trim($from), FILTER_VALIDATE_EMAIL)) return ['', trim(mb_strtolower($from))];
    return [trim($from), ''];
}

// Lesbaren Text gewinnen: bevorzugt text/plain, sonst text/html entschlackt. Rekursiv ueber
// multipart-Grenzen.
function mail_koerper_text(array $header, string $rumpf): string {
    $ct = (string)($header['content-type'] ?? 'text/plain');
    if (stripos($ct, 'multipart/') !== false && preg_match('/boundary="?([^";]+)"?/i', $ct, $m)) {
        $grenze = $m[1];
        $teile = mail_multipart_teilen($rumpf, $grenze);
        $plain = ''; $html = '';
        foreach ($teile as $teil) {
            $t = strpos($teil, "\n\n");
            $th = $t === false ? $teil : substr($teil, 0, $t);
            $tb = $t === false ? '' : substr($teil, $t + 2);
            $h = mail_header_parsen($th);
            $tct = (string)($h['content-type'] ?? 'text/plain');
            if (stripos($tct, 'multipart/') !== false) {
                $sub = mail_koerper_text($h, $tb);
                if (stripos($tct, 'alternative') !== false && $sub !== '') return $sub;
                $plain .= ($plain !== '' ? "\n" : '') . $sub;
                continue;
            }
            // Anhaenge ueberspringen.
            if (stripos((string)($h['content-disposition'] ?? ''), 'attachment') !== false) continue;
            $txt = mail_teil_dekodieren($tb, $h);
            if (stripos($tct, 'text/plain') !== false)     $plain .= ($plain !== '' ? "\n" : '') . $txt;
            elseif (stripos($tct, 'text/html') !== false)  $html  .= ($html !== '' ? "\n" : '') . $txt;
        }
        if (trim($plain) !== '') return $plain;
        if (trim($html) !== '')  return mail_html_zu_text($html);
        return '';
    }
    // Einfache Mail.
    $txt = mail_teil_dekodieren($rumpf, $header);
    return stripos($ct, 'text/html') !== false ? mail_html_zu_text($txt) : $txt;
}

function mail_multipart_teilen(string $rumpf, string $grenze): array {
    $stueck = preg_split('/\r?\n?--' . preg_quote($grenze, '/') . '(--)?\r?\n?/', $rumpf);
    $out = [];
    foreach ((array)$stueck as $s) { $s = ltrim($s, "\r\n"); if (trim($s) !== '') $out[] = $s; }
    return $out;
}

// Einen Teil nach Content-Transfer-Encoding + Zeichensatz dekodieren.
function mail_teil_dekodieren(string $body, array $header): string {
    $cte = strtolower(trim((string)($header['content-transfer-encoding'] ?? '')));
    if ($cte === 'base64')            $body = (string) base64_decode(preg_replace('/\s+/', '', $body), false);
    elseif ($cte === 'quoted-printable') $body = quoted_printable_decode($body);
    $cs = '';
    if (preg_match('/charset="?([^";\s]+)"?/i', (string)($header['content-type'] ?? ''), $m)) $cs = strtoupper($m[1]);
    if ($cs !== '' && $cs !== 'UTF-8' && $cs !== 'US-ASCII') {
        $k = @mb_convert_encoding($body, 'UTF-8', $cs);
        if ($k !== false && $k !== '') $body = $k;
    }
    return $body;
}
