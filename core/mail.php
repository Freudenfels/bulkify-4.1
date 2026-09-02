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
    $link = mail_basis_url() . '/?p=lieferant_login';

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

// --- Links ------------------------------------------------------------------

// Basis-URL für Links in Mails: die Einstellung portal_url, sonst der aktuelle Aufruf.
function mail_basis_url(): string {
    $b = rtrim((string) meta_get('portal_url', ''), '/');
    if ($b === '') $b = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $b;
}
// Passwortloser Link ins Kundenportal, optional direkt auf eine Ansicht (angebote, bestellungen …).
function mail_link_kundenportal(int $kunde_id, string $ansicht = ''): string {
    return mail_basis_url() . '/?p=portal&token=' . kunde_portal_token($kunde_id) . ($ansicht !== '' ? '&v=' . $ansicht : '');
}
// Anrede für einen Kunden: Ansprechpartner, sonst die Firma.
function mail_kunde_anrede(array $k): string {
    $a = trim((string)($k['ansprechpartner'] ?? ''));
    return $a !== '' ? $a : (string)($k['firma'] ?? '');
}

// --- Vorlagen Kunde (Kunden werden auf Deutsch angeschrieben) ----------------

// Ein Angebot wurde an den Kunden gesendet: er bekommt den Link ins Portal.
function mail_kunde_angebot(int $angebot_id): string {
    $a = one("SELECT a.nummer, a.gueltig_bis, a.kunde_id, k.firma, k.ansprechpartner, k.email
              FROM angebot a JOIN kunden k ON k.id=a.kunde_id WHERE a.id=?", [$angebot_id]);
    if (!$a) return 'Angebot oder Kunde nicht gefunden.';
    if (trim((string)$a['email']) === '') return 'Für diesen Kunden ist keine E-Mail-Adresse hinterlegt.';
    $fa   = beleg_firma();
    $link = mail_link_kundenportal((int)$a['kunde_id'], 'angebote');
    $bis  = $a['gueltig_bis'] ? date('d.m.Y', strtotime((string)$a['gueltig_bis'])) : '';
    $betreff = 'Ihr Angebot ' . $a['nummer'] . ' von ' . $fa['name'];
    $text = "Guten Tag " . mail_kunde_anrede($a) . ",\n\n"
          . "Ihr Angebot " . $a['nummer'] . " liegt im Kundenportal bereit" . ($bis !== '' ? " und gilt bis $bis" : '') . ".\n"
          . "Dort sehen Sie alle Varianten und können die passende verbindlich annehmen:\n$link\n\n"
          . "Bei Fragen antworten Sie einfach auf diese E-Mail.\n\nViele Grüße\n" . $fa['name'];
    return mail_senden((string)$a['email'], $betreff, $text);
}

// Der Kunde hat ein Angebot angenommen: Bestätigung an den Kunden, Hinweis ans Team.
// Rückgabe = Ergebnis der Kundenmail ('' = verschickt).
function mail_angebot_angenommen(int $angebot_id, ?int $auftrag_id = null): string {
    $a = one("SELECT a.nummer, a.kunde_id, a.freigabe_name, k.firma, k.ansprechpartner, k.email
              FROM angebot a JOIN kunden k ON k.id=a.kunde_id WHERE a.id=?", [$angebot_id]);
    if (!$a) return 'Angebot oder Kunde nicht gefunden.';
    $fa    = beleg_firma();
    $aufNr = $auftrag_id ? (string) scalar("SELECT nummer FROM auftrag WHERE id=?", [$auftrag_id]) : '';

    mail_team('Angebot ' . $a['nummer'] . ' angenommen: ' . $a['firma'],
        "Der Kunde " . $a['firma'] . " hat das Angebot " . $a['nummer'] . " im Portal verbindlich angenommen"
        . ($a['freigabe_name'] ? ' (' . $a['freigabe_name'] . ')' : '') . ".\n"
        . ($aufNr !== '' ? "Auftrag $aufNr ist angelegt, Rechnung und Produktionsauftrag ebenfalls.\n" : '')
        . "\n" . mail_basis_url() . '/?p=angebot&id=' . $angebot_id . "\n");

    if (trim((string)$a['email']) === '') return 'Für diesen Kunden ist keine E-Mail-Adresse hinterlegt.';
    $link = mail_link_kundenportal((int)$a['kunde_id'], 'bestellungen');
    $betreff = 'Auftragsbestätigung' . ($aufNr !== '' ? ' ' . $aufNr : '') . ' von ' . $fa['name'];
    $text = "Guten Tag " . mail_kunde_anrede($a) . ",\n\n"
          . "vielen Dank, Sie haben das Angebot " . $a['nummer'] . " verbindlich angenommen.\n"
          . ($aufNr !== '' ? "Wir führen den Auftrag unter der Nummer $aufNr.\n" : '')
          . "Den Stand des Auftrags und die Rechnung finden Sie im Kundenportal:\n$link\n\n"
          . "Viele Grüße\n" . $fa['name'];
    return mail_senden((string)$a['email'], $betreff, $text);
}

// Eine Anfrage wurde abgesagt („nicht machbar"): der Kunde erfährt den Grund.
function mail_kunde_absage(int $anfrage_id): string {
    $p = one("SELECT p.nummer, p.betreff, p.absage_grund, p.kunde_id, k.firma, k.ansprechpartner, k.email
              FROM portal_anfrage p JOIN kunden k ON k.id=p.kunde_id WHERE p.id=?", [$anfrage_id]);
    if (!$p) return 'Anfrage oder Kunde nicht gefunden.';
    if (trim((string)$p['email']) === '') return 'Für diesen Kunden ist keine E-Mail-Adresse hinterlegt.';
    $fa   = beleg_firma();
    $link = mail_link_kundenportal((int)$p['kunde_id'], 'meine_anfragen');
    $betreff = 'Ihre Anfrage ' . $p['nummer'] . ': leider nicht machbar';
    $text = "Guten Tag " . mail_kunde_anrede($p) . ",\n\n"
          . "wir haben Ihre Anfrage " . $p['nummer'] . ($p['betreff'] ? ' („' . $p['betreff'] . '")' : '') . " geprüft und können sie leider nicht umsetzen.\n\n"
          . "Grund:\n" . trim((string)$p['absage_grund']) . "\n\n"
          . "Wenn Sie eine angepasste Variante anfragen möchten, geht das jederzeit über das Kundenportal:\n$link\n\n"
          . "Viele Grüße\n" . $fa['name'];
    return mail_senden((string)$p['email'], $betreff, $text);
}

// --- Vorlagen Team (Lieferant hat im Portal etwas getan) ---------------------

// Der Lieferant hat eine Bestellung bestätigt oder eine Station gesetzt.
function mail_team_bestellung(int $bestellung_id, string $was): int {
    $b = one("SELECT b.nummer, b.eta_geplant, l.firma FROM bestellung b JOIN lieferanten l ON l.id=b.lieferant_id WHERE b.id=?", [$bestellung_id]);
    if (!$b) return 0;
    return mail_team('Bestellung ' . $b['nummer'] . ': ' . $was . ' (' . $b['firma'] . ')',
        "Der Lieferant " . $b['firma'] . " hat die Bestellung " . $b['nummer'] . " im Portal aktualisiert: $was.\n"
        . ($b['eta_geplant'] ? 'Geplanter Liefertermin: ' . date('d.m.Y', strtotime((string)$b['eta_geplant'])) . "\n" : '')
        . "\n" . mail_basis_url() . '/?p=bestellung&id=' . $bestellung_id . "\n");
}

// Der Lieferant hat eine Preisanfrage beantwortet.
function mail_team_preisanfrage(int $anfrage_id): int {
    $a = one("SELECT af.nummer, af.betreff, af.lieferant_id, l.firma, i.name AS item_name
              FROM lieferant_anfrage af JOIN lieferanten l ON l.id=af.lieferant_id LEFT JOIN item i ON i.id=af.item_id WHERE af.id=?", [$anfrage_id]);
    if (!$a) return 0;
    $ang = one("SELECT preis, einheit, mindestmenge, lieferzeit_tage FROM lieferant_angebot WHERE anfrage_id=?", [$anfrage_id]);
    $was = (string)($a['item_name'] ?: $a['betreff']);
    $details = '';
    if ($ang) {
        $details = 'Preis: ' . number_format((float)$ang['preis'], 2, ',', '.') . ' € je ' . $ang['einheit'];
        if ($ang['mindestmenge'])    $details .= ', Mindestmenge ' . rtrim(rtrim(number_format((float)$ang['mindestmenge'], 3, ',', '.'), '0'), ',');
        if ($ang['lieferzeit_tage']) $details .= ', Lieferzeit ' . (int)$ang['lieferzeit_tage'] . ' Tage';
        $details .= "\n";
    }
    return mail_team('Preisanfrage ' . $a['nummer'] . ' beantwortet (' . $a['firma'] . ')',
        "Der Lieferant " . $a['firma'] . " hat die Preisanfrage " . $a['nummer'] . ($was !== '' ? " ($was)" : '') . " beantwortet.\n"
        . $details . "\n" . mail_basis_url() . '/?p=lieferant&id=' . (int)$a['lieferant_id'] . "\n");
}

// Neue Rückfrage/Antwort: die andere Seite bekommt den Text und einen Link zum Antworten.
function mail_nachricht(int $lieferant_id, string $akteur, string $text, ?string $bezug_typ = null, ?int $bezug_id = null): void {
    $lf = one("SELECT firma, ansprechpartner, email, sprache FROM lieferanten WHERE id=?", [$lieferant_id]);
    if (!$lf) return;
    $auszug = mb_substr(trim($text), 0, 600);
    if ($akteur === 'lieferant') {
        $bezug = function_exists('nachricht_bezug_label') ? nachricht_bezug_label($bezug_typ, $bezug_id, 'de') : '';
        mail_team('Rückfrage von ' . $lf['firma'] . ($bezug !== '' ? ' zu ' . $bezug : ''),
            "Der Lieferant " . $lf['firma'] . " hat im Portal geschrieben" . ($bezug !== '' ? " (zu $bezug)" : '') . ":\n\n" . $auszug . "\n\n"
            . "Antworten: " . mail_basis_url() . '/?p=lieferant&id=' . $lieferant_id . "#rueckfragen\n");
        return;
    }
    if (trim((string)$lf['email']) === '') return;
    $fa = beleg_firma();
    $de = strtolower((string)$lf['sprache']) === 'de';
    $anrede = trim((string)$lf['ansprechpartner']) !== '' ? (string)$lf['ansprechpartner'] : (string)$lf['firma'];
    $bezug  = function_exists('nachricht_bezug_label') ? nachricht_bezug_label($bezug_typ, $bezug_id, $de ? 'de' : 'en') : '';
    $link   = mail_basis_url() . '/?p=lieferant_nachrichten';
    if ($de) {
        $betreff = 'Neue Nachricht von ' . $fa['name'] . ($bezug !== '' ? ' zu ' . $bezug : '');
        $body = "Guten Tag $anrede,\n\nwir haben Ihnen im Lieferantenportal geschrieben" . ($bezug !== '' ? " (zu $bezug)" : '') . ":\n\n" . $auszug . "\n\n"
              . "Antworten können Sie direkt im Portal:\n$link\n\nViele Grüße\n" . $fa['name'];
    } else {
        $betreff = 'New message from ' . $fa['name'] . ($bezug !== '' ? ' regarding ' . $bezug : '');
        $body = "Dear $anrede,\n\nwe have written to you in the supplier portal" . ($bezug !== '' ? " (regarding $bezug)" : '') . ":\n\n" . $auszug . "\n\n"
              . "You can reply directly in the portal:\n$link\n\nBest regards\n" . $fa['name'];
    }
    mail_senden((string)$lf['email'], $betreff, $body);
}
