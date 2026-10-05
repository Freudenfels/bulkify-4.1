<?php
// E-Mail SENDEN aus dem CRM - unter dem eigenen Konto des angemeldeten Mitarbeiters.
//
// Die benutzer-Tabelle gehoert dem Dashboard und wird nicht angefasst; das Mailkonto je Mitarbeiter
// steht in der CRM-eigenen Tabelle crm_mitarbeiter (eintragbar unter Einstellungen → Mein Mailkonto).
// Der Versand nutzt dieselbe bewaehrte Socket-SMTP-Technik wie das Dashboard (core/mail.php),
// hier aber mit dem Konto des Mitarbeiters. Kein Composer, keine Abhaengigkeit.
require_once __DIR__ . '/schema.php';

// Konfiguration eines Mitarbeiters. 'bereit' = genug fuer den Versand hinterlegt.
function mitarbeiter_mail_konfig(int $benutzer_id): array {
    $r = $benutzer_id > 0 ? one("SELECT * FROM crm_mitarbeiter WHERE benutzer_id=?", [$benutzer_id]) : null;
    $r = $r ?: [];
    $konf = [
        'absender_name'  => trim((string)($r['absender_name'] ?? '')),
        'absender_email' => trim((string)($r['absender_email'] ?? '')),
        'host'           => trim((string)($r['smtp_host'] ?? '')),
        'port'           => (int)($r['smtp_port'] ?? 587) ?: 587,
        'secure'         => in_array((string)($r['smtp_secure'] ?? 'tls'), ['tls', 'ssl', ''], true) ? (string)($r['smtp_secure'] ?? 'tls') : 'tls',
        'user'           => trim((string)($r['smtp_user'] ?? '')),
        'pass'           => (string)($r['smtp_pass'] ?? ''),
        'signatur'       => (string)($r['signatur'] ?? ''),
    ];
    $konf['bereit'] = ($konf['host'] !== '' && $konf['absender_email'] !== '' && filter_var($konf['absender_email'], FILTER_VALIDATE_EMAIL));
    return $konf;
}

function mitarbeiter_mail_speichern(int $benutzer_id, array $p): void {
    if ($benutzer_id <= 0) return;
    $vorher = mitarbeiter_mail_konfig($benutzer_id);
    // Passwort nur ueberschreiben, wenn eins eingegeben wurde.
    $pass = trim((string)($p['smtp_pass'] ?? '')) !== '' ? (string)$p['smtp_pass'] : $vorher['pass'];
    $secure = in_array((string)($p['smtp_secure'] ?? 'tls'), ['tls', 'ssl', ''], true) ? (string)$p['smtp_secure'] : 'tls';
    q("INSERT INTO crm_mitarbeiter (benutzer_id, absender_name, absender_email, smtp_host, smtp_port, smtp_secure, smtp_user, smtp_pass, signatur, aktualisiert)
       VALUES (?,?,?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE absender_name=VALUES(absender_name), absender_email=VALUES(absender_email),
         smtp_host=VALUES(smtp_host), smtp_port=VALUES(smtp_port), smtp_secure=VALUES(smtp_secure),
         smtp_user=VALUES(smtp_user), smtp_pass=VALUES(smtp_pass), signatur=VALUES(signatur), aktualisiert=VALUES(aktualisiert)",
      [$benutzer_id,
       mb_substr(trim((string)($p['absender_name'] ?? '')), 0, 190) ?: null,
       mb_substr(trim((string)($p['absender_email'] ?? '')), 0, 190) ?: null,
       mb_substr(trim((string)($p['smtp_host'] ?? '')), 0, 190) ?: null,
       (int)($p['smtp_port'] ?? 587) ?: 587, $secure,
       mb_substr(trim((string)($p['smtp_user'] ?? '')), 0, 190) ?: null,
       $pass !== '' ? $pass : null,
       trim((string)($p['signatur'] ?? '')) ?: null,
       gmdate('Y-m-d H:i:s')]);
}

// Senden. Rueckgabe: '' = gesendet, sonst Klartext-Fehler. Haengt die Signatur an (falls gesetzt).
function crm_mail_senden(int $benutzer_id, string $to, string $betreff, string $text): string {
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return 'Keine gültige Empfängeradresse.';
    $c = mitarbeiter_mail_konfig($benutzer_id);
    if (!$c['bereit']) return 'Kein eigenes Mailkonto hinterlegt (Einstellungen → Mein Mailkonto).';
    if (trim($c['signatur']) !== '') $text = rtrim($text) . "\n\n" . $c['signatur'];
    return crm_smtp_senden($to, $betreff, $text, $c);
}

// Minimaler SMTP-Versand ueber einen Socket (STARTTLS/SSL/AUTH LOGIN) - uebernommen aus core/mail.php.
function crm_smtp_senden(string $to, string $betreff, string $text, array $c): string {
    $port   = $c['port'] ?: 587;
    $secure = in_array($c['secure'], ['tls', 'ssl', ''], true) ? $c['secure'] : 'tls';
    $from   = $c['absender_email'];
    $fname  = $c['absender_name'] !== '' ? $c['absender_name'] : 'bulkify';
    $domain = strpos($from, '@') !== false ? substr(strrchr($from, '@'), 1) : 'bulkify.pro';
    $timeout = 15;

    $ziel = ($secure === 'ssl' ? 'ssl://' : '') . $c['host'] . ':' . $port;
    $ctx  = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $fp = @stream_socket_client($ziel, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return 'Verbindung zu ' . $c['host'] . ':' . $port . ' nicht möglich (' . trim((string)$errstr) . ').';
    stream_set_timeout($fp, $timeout);

    $lesen = function () use ($fp) {
        $d = '';
        while (($z = fgets($fp, 515)) !== false) { $d .= $z; if (strlen($z) < 4 || $z[3] === ' ') break; }
        return $d;
    };
    $code = fn($r) => (int) substr((string)$r, 0, 3);
    $cmd  = function ($c2) use ($fp, $lesen) { fwrite($fp, $c2 . "\r\n"); return $lesen(); };

    $fehler = '';
    try {
        if ($code($lesen()) !== 220) throw new RuntimeException('Server meldet sich nicht mit 220.');
        $code($cmd('EHLO ' . $domain));
        if ($secure === 'tls') {
            if ($code($cmd('STARTTLS')) !== 220) throw new RuntimeException('STARTTLS abgelehnt.');
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('TLS-Verschlüsselung fehlgeschlagen.');
            $code($cmd('EHLO ' . $domain));
        }
        if ($c['user'] !== '') {
            if ($code($cmd('AUTH LOGIN')) !== 334) throw new RuntimeException('Server erlaubt kein AUTH LOGIN.');
            if ($code($cmd(base64_encode($c['user']))) !== 334) throw new RuntimeException('Benutzername abgelehnt.');
            if ($code($cmd(base64_encode($c['pass']))) !== 235) throw new RuntimeException('Passwort abgelehnt.');
        }
        if ($code($cmd('MAIL FROM:<' . $from . '>')) !== 250) throw new RuntimeException('Absender abgelehnt.');
        if ($code($cmd('RCPT TO:<' . $to . '>')) >= 300)      throw new RuntimeException('Empfänger abgelehnt.');
        if ($code($cmd('DATA')) !== 354)                       throw new RuntimeException('DATA abgelehnt.');

        $fromKopf = preg_match('/[\x80-\xFF]/', $fname) ? '=?UTF-8?B?' . base64_encode($fname) . '?=' : $fname;
        $msg = 'Date: ' . date('r') . "\r\n"
             . 'Message-ID: <' . bin2hex(random_bytes(12)) . '.' . time() . '@' . $domain . ">\r\n"
             . 'From: ' . $fromKopf . ' <' . $from . ">\r\n"
             . 'To: <' . $to . ">\r\n"
             . 'Reply-To: ' . $from . "\r\n"
             . 'Subject: =?UTF-8?B?' . base64_encode($betreff) . "?=\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n"
             . "X-Mailer: bulkify CRM\r\n\r\n"
             . chunk_split(base64_encode($text));
        fwrite($fp, $msg . "\r\n.\r\n");
        if ($code($lesen()) !== 250) throw new RuntimeException('Server hat die Nachricht nicht angenommen.');
        $cmd('QUIT');
    } catch (Throwable $e) {
        $fehler = $e->getMessage();
    }
    fclose($fp);
    return $fehler;
}
