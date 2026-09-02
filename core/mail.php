<?php
// E-Mail-Versand – wie in v3: eigener SMTP-Versand über einen Socket, kein Composer,
// kein PHPMailer. Konfiguriert wird alles unter Einstellungen → E-Mail (z. B. die
// Zugangsdaten von United Domains); dort steht auch der Testversand.
//
// Jede Mail wird zusätzlich nach data/mail.log geschrieben – damit ist nachvollziehbar,
// was rausging, auch wenn der Server gerade keinen Versand schafft.

function mail_config(): array {
    return [
        'aktiv'     => meta_get('mail_aktiv', '0') === '1',
        'host'      => trim((string) meta_get('smtp_host', '')),
        'port'      => (int) meta_get('smtp_port', 587),
        'secure'    => (string) meta_get('smtp_secure', 'tls'),      // tls (587) | ssl (465) | '' (offen)
        'user'      => trim((string) meta_get('smtp_user', '')),
        'pass'      => (string) meta_get('smtp_pass', ''),
        'from'      => trim((string) meta_get('mail_from', '')),
        'from_name' => trim((string) meta_get('mail_from_name', 'bulkify')),
        'helo'      => trim((string) meta_get('smtp_helo', '')),
    ];
}

// Ist der Versand einsatzbereit? (Für Hinweise in der Oberfläche.)
function mail_bereit(): bool {
    $c = mail_config();
    return $c['aktiv'] && $c['host'] !== '' && $c['from'] !== '';
}

// Mail verschicken. Gibt '' zurück, wenn es geklappt hat, sonst den Grund.
// Wirft nie – ein fehlgeschlagener Versand darf keinen Vorgang abbrechen.
function mail_senden(string $to, string $betreff, string $text): string {
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return 'Keine gültige Empfängeradresse.';
    mail_log($to, $betreff, $text);

    $c = mail_config();
    if (!$c['aktiv']) return 'Der E-Mail-Versand ist in den Einstellungen ausgeschaltet.';
    if ($c['host'] === '') return 'Es ist kein SMTP-Server hinterlegt (Einstellungen → E-Mail).';
    if ($c['from'] === '') return 'Es ist keine Absenderadresse hinterlegt (Einstellungen → E-Mail).';

    $fehler = smtp_senden($to, $betreff, $text, $c);
    if ($fehler !== '') mail_log($to, 'FEHLER: ' . $betreff, $fehler);
    return $fehler;
}

function mail_log(string $to, string $betreff, string $text): void {
    $datei = dirname(BX_UPLOADS) . '/mail.log';
    @file_put_contents($datei, gmdate('c') . "  AN: $to  BETREFF: $betreff\n" . $text . "\n----\n", FILE_APPEND | LOCK_EX);
}

// Minimaler SMTP-Versand über einen Socket. Unterstützt STARTTLS (587), implizites TLS (465)
// und AUTH LOGIN. Rückgabe: '' = gesendet, sonst eine Kurzbeschreibung des Schritts, der scheiterte.
function smtp_senden(string $to, string $betreff, string $text, array $c): string {
    $port   = $c['port'] ?: 587;
    $secure = in_array($c['secure'], ['tls', 'ssl', ''], true) ? $c['secure'] : 'tls';
    $from   = $c['from'];
    $fname  = $c['from_name'] !== '' ? $c['from_name'] : 'bulkify';
    $domain = strpos($from, '@') !== false ? substr(strrchr($from, '@'), 1) : 'bulkify.pro';
    $helo   = $c['helo'] !== '' ? $c['helo'] : $domain;
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
        $code($cmd('EHLO ' . $helo));
        if ($secure === 'tls') {
            if ($code($cmd('STARTTLS')) !== 220) throw new RuntimeException('STARTTLS abgelehnt.');
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('TLS-Verschlüsselung fehlgeschlagen.');
            $code($cmd('EHLO ' . $helo));                      // nach TLS erneut
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
             . "X-Mailer: bulkify\r\n\r\n"
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

// --- Vorlagen ---------------------------------------------------------------

// Einladung an einen Lieferanten. Sprache richtet sich nach dem Lieferanten.
function mail_lieferant_einladung(int $lieferant_id, string $link): string {
    $lf = one("SELECT firma, ansprechpartner, email, sprache FROM lieferanten WHERE id=?", [$lieferant_id]);
    if (!$lf) return 'Lieferant nicht gefunden.';
    if (trim((string)$lf['email']) === '') return 'Für diesen Lieferanten ist keine E-Mail-Adresse hinterlegt.';
    $fa = beleg_firma();
    $de = strtolower((string)$lf['sprache']) === 'de';
    $anrede = trim((string)$lf['ansprechpartner']) !== '' ? (string)$lf['ansprechpartner'] : (string)$lf['firma'];

    if ($de) {
        $betreff = 'Ihr Zugang zum Lieferantenportal von ' . $fa['name'];
        $text = "Guten Tag $anrede,\n\n"
              . "wir arbeiten ab sofort mit einem Lieferantenportal. Dort sehen Sie unsere Bestellungen,\n"
              . "bestätigen Liefertermine, pflegen den Fortschritt und beantworten Preisanfragen.\n\n"
              . "Bitte richten Sie hier Ihren Zugang ein (der Link gilt einmal):\n$link\n\n"
              . "Bei Fragen antworten Sie einfach auf diese E-Mail.\n\nViele Grüße\n" . $fa['name'];
    } else {
        $betreff = 'Your access to the supplier portal of ' . $fa['name'];
        $text = "Dear $anrede,\n\n"
              . "we now work with a supplier portal. There you can see our purchase orders,\n"
              . "confirm delivery dates, keep the progress up to date and answer price requests.\n\n"
              . "Please set up your access here (the link can be used once):\n$link\n\n"
              . "If you have any questions, simply reply to this e-mail.\n\nBest regards\n" . $fa['name'];
    }
    return mail_senden((string)$lf['email'], $betreff, $text);
}

// Neue Bestellung an den Lieferanten melden.
function mail_lieferant_bestellung(int $bestellung_id): string {
    $b = one("SELECT b.nummer, b.lieferant_id, l.firma, l.ansprechpartner, l.email, l.sprache
              FROM bestellung b JOIN lieferanten l ON l.id=b.lieferant_id WHERE b.id=?", [$bestellung_id]);
    if (!$b) return 'Bestellung oder Lieferant nicht gefunden.';
    if (trim((string)$b['email']) === '') return 'Für diesen Lieferanten ist keine E-Mail-Adresse hinterlegt.';
    $fa = beleg_firma();
    $de = strtolower((string)$b['sprache']) === 'de';
    $anrede = trim((string)$b['ansprechpartner']) !== '' ? (string)$b['ansprechpartner'] : (string)$b['firma'];
    $link = rtrim((string) meta_get('portal_url', ''), '/') . '/?p=lieferant_login';

    if ($de) {
        $betreff = 'Neue Bestellung ' . $b['nummer'];
        $text = "Guten Tag $anrede,\n\nwir haben Ihnen die Bestellung " . $b['nummer'] . " erteilt.\n\n"
              . "Bitte bestätigen Sie im Portal die Bestellung und den geplanten Liefertermin:\n$link\n\n"
              . "Viele Grüße\n" . $fa['name'];
    } else {
        $betreff = 'New purchase order ' . $b['nummer'];
        $text = "Dear $anrede,\n\nwe have placed purchase order " . $b['nummer'] . " with you.\n\n"
              . "Please confirm the order and the planned delivery date in the portal:\n$link\n\n"
              . "Best regards\n" . $fa['name'];
    }
    return mail_senden((string)$b['email'], $betreff, $text);
}

// Interne Benachrichtigung an alle aktiven Admins (z. B. „Lieferant hat bestätigt").
function mail_team(string $betreff, string $text): int {
    if (!mail_bereit()) return 0;
    $n = 0;
    foreach (all("SELECT email FROM benutzer WHERE aktiv=1 AND lieferant_id IS NULL AND rollen LIKE '%admin%'") as $u) {
        if (mail_senden((string)$u['email'], $betreff, $text) === '') $n++;
    }
    return $n;
}
