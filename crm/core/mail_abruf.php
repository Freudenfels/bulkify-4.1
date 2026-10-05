<?php
// Automatischer E-Mail-Eingang: holt neue Mails aus dem Postfach (crm@bulkify.pro) und legt sie als
// "Eingang" ab - mit KI-Vorschau. Angelegt wird erst per Klick auf der Eingang-Seite.
//
// Zugang steht in crm_meta (ueber "Mehr" eintragbar), NICHT in der secrets.php - Nico pflegt ihn
// selbst in der Oberflaeche. Der Abruf nutzt die PHP-IMAP-Erweiterung; fehlt sie auf dem Server,
// sagt die Oberflaeche das deutlich (dann bauen wir den Socket-Abruf nach).
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/mail_ki.php';
require_once __DIR__ . '/kontakt.php';
require_once __DIR__ . '/dublette.php';
require_once __DIR__ . '/imap_client.php';   // Socket-Abruf (Fallback ohne ext-imap)

// --- Zugang (crm_meta) -------------------------------------------------------------------------
function mail_imap_konfig(): array {
    $host   = trim(crm_meta_lesen('imap_host', ''));
    $user   = trim(crm_meta_lesen('imap_user', ''));
    $pass   = (string) crm_meta_lesen('imap_pass', '');
    $port   = (int) (crm_meta_lesen('imap_port', '993') ?: 993);
    $ssl    = crm_meta_lesen('imap_ssl', '1') !== '0';
    $ordner = trim(crm_meta_lesen('imap_ordner', '')) ?: 'INBOX';
    return [
        'host' => $host, 'user' => $user, 'pass' => $pass, 'port' => $port ?: 993,
        'ssl' => $ssl, 'ordner' => $ordner,
        'vollstaendig' => ($host !== '' && $user !== '' && $pass !== ''),
    ];
}
function mail_imap_speichern(array $p): void {
    crm_meta_schreiben('imap_host', trim((string)($p['imap_host'] ?? '')));
    crm_meta_schreiben('imap_user', trim((string)($p['imap_user'] ?? '')));
    crm_meta_schreiben('imap_port', (string)((int)($p['imap_port'] ?? 993) ?: 993));
    crm_meta_schreiben('imap_ssl', !empty($p['imap_ssl']) ? '1' : '0');
    crm_meta_schreiben('imap_ordner', trim((string)($p['imap_ordner'] ?? '')) ?: 'INBOX');
    // Passwort nur ueberschreiben, wenn eins eingegeben wurde (Feld bleibt sonst leer stehen).
    if (trim((string)($p['imap_pass'] ?? '')) !== '') crm_meta_schreiben('imap_pass', (string)$p['imap_pass']);
}

// Koennen wir ueberhaupt abrufen? Entweder ueber die PHP-IMAP-Erweiterung ODER ueber unseren
// Socket-Abruf (OpenSSL + mbstring). Steuert, was die Oberflaeche anbietet.
function mail_abruf_via_ext(): bool    { return function_exists('imap_open'); }
function mail_abruf_via_socket(): bool { return function_exists('stream_socket_client') && extension_loaded('openssl') && function_exists('mb_decode_mimeheader'); }
function mail_abruf_moeglich(): bool   { return mail_abruf_via_ext() || mail_abruf_via_socket(); }
function mail_abruf_bereit(): bool     { return mail_abruf_moeglich() && mail_imap_konfig()['vollstaendig']; }
function mail_abruf_letzter(): string  { return crm_meta_lesen('imap_last_fetch', ''); }

// --- Abruf -------------------------------------------------------------------------------------
// Holt die UNGELESENEN Mails, legt sie in crm_mail_eingang ab und markiert sie als gelesen.
// Waehlt den Weg: PHP-IMAP-Erweiterung, wenn vorhanden, sonst der eigene Socket-Abruf.
// Wirft nie. Rueckgabe: ['ok'=>bool, 'anzahl'=>int, 'fehler'=>string]
function mail_abholen(int $max = 30): array {
    $c = mail_imap_konfig();
    if (!$c['vollstaendig']) return ['ok' => false, 'anzahl' => 0, 'fehler' => 'IMAP-Zugang ist noch nicht vollständig eingetragen (Mehr → E-Mail-Eingang).'];
    if (mail_abruf_via_ext())    $r = mail_abholen_imap($c, $max);
    elseif (mail_abruf_via_socket()) $r = mail_abholen_socket($c, $max);
    else return ['ok' => false, 'anzahl' => 0, 'fehler' => 'Kein Abrufweg verfügbar (weder PHP-IMAP noch OpenSSL).'];
    if (!empty($r['ok'])) crm_meta_schreiben('imap_last_fetch', gmdate('Y-m-d H:i:s'));
    return $r;
}

// Eine abgeholte Mail aufnehmen: Dubletten-Schutz, KI-Vorschau, Zeile in crm_mail_eingang.
// Gemeinsam fuer beide Abrufwege. Rueckgabe: 1 = neu eingetragen, 0 = Dublette/uebersprungen.
function mail_eingang_aufnehmen(string $messageId, int $uid, string $vonName, string $vonEmail, string $betreff, string $datum, string $body): int {
    $messageId = trim($messageId) !== '' ? $messageId : ('sock:' . md5($vonEmail . '|' . $betreff . '|' . $datum . '|' . mb_substr($body, 0, 200)));
    if (scalar("SELECT COUNT(*) FROM crm_mail_eingang WHERE message_id=?", [$messageId])) return 0;

    $roh = "Von: " . trim($vonName . ' <' . $vonEmail . '>') . "\nBetreff: " . $betreff . "\n\n" . $body;
    $r = mail_ki_lesen($roh);
    $daten = ($r['ok'] ?? false) ? $r['daten'] : [
        'art' => 'sonstiges', 'name' => $vonName, 'firma' => '', 'email' => $vonEmail, 'telefon' => '',
        'betreff' => $betreff, 'zusammenfassung' => mb_substr(trim($body), 0, 300), 'wunsch' => '',
        'wert' => null, 'tage' => null, 'antwort_noetig' => true, 'sprache' => 'de',
    ];
    if (trim((string)($daten['email'] ?? '')) === '' && $vonEmail !== '') $daten['email'] = $vonEmail;
    if (trim((string)($daten['name'] ?? '')) === '' && $vonName !== '')   $daten['name']  = $vonName;

    q("INSERT INTO crm_mail_eingang (message_id, imap_uid, von_name, von_email, betreff, datum, body, art, daten_json, status, angelegt)
       VALUES (?,?,?,?,?,?,?,?,?, 'neu', ?)",
      [mb_substr($messageId, 0, 255), $uid ?: null,
       mb_substr($vonName, 0, 190) ?: null, mb_substr($vonEmail, 0, 190) ?: null,
       mb_substr($betreff, 0, 255) ?: null, $datum, mb_substr($body, 0, 60000),
       (string)($daten['art'] ?? 'sonstiges'), json_encode($daten, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s')]);
    return 1;
}

// Abruf ueber die PHP-IMAP-Erweiterung.
function mail_abholen_imap(array $c, int $max): array {
    $flags = '/imap' . ($c['ssl'] ? '/ssl' : '/novalidate-cert');
    $mbox  = '{' . $c['host'] . ':' . $c['port'] . $flags . '}' . $c['ordner'];
    $imap = @imap_open($mbox, $c['user'], $c['pass'], 0, 1);
    if (!$imap) return ['ok' => false, 'anzahl' => 0, 'fehler' => 'Anmeldung am Postfach fehlgeschlagen: ' . trim((string) imap_last_error())];
    $neu = 0;
    try {
        $ids = imap_search($imap, 'UNSEEN', SE_UID) ?: [];
        rsort($ids);
        foreach (array_slice($ids, 0, max(1, $max)) as $uid) {
            try {
                $ov = imap_fetch_overview($imap, (string)$uid, FT_UID); $o = $ov[0] ?? null;
                if (!$o) continue;
                $messageId = trim((string)($o->message_id ?? '')) ?: ('uid:' . $c['user'] . ':' . $uid);
                if (scalar("SELECT COUNT(*) FROM crm_mail_eingang WHERE message_id=?", [$messageId])) { @imap_setflag_full($imap, (string)$uid, '\\Seen', ST_UID); continue; }
                $vonName = ''; $vonEmail = '';
                if (!empty($o->from)) [$vonName, $vonEmail] = mail_absender_zerlegen((string)$o->from);
                $betreff = mail_dekodiere_kopf((string)($o->subject ?? ''));
                $datum   = !empty($o->date) ? gmdate('Y-m-d H:i:s', strtotime((string)$o->date) ?: time()) : gmdate('Y-m-d H:i:s');
                $body    = mail_body_text($imap, (int)$uid);
                $neu += mail_eingang_aufnehmen($messageId, (int)$uid, $vonName, $vonEmail, $betreff, $datum, $body);
                @imap_setflag_full($imap, (string)$uid, '\\Seen', ST_UID);
            } catch (Throwable $e) { error_log('mail_abholen(imap): UID ' . $uid . ' uebersprungen: ' . $e->getMessage()); }
        }
    } catch (Throwable $e) { @imap_close($imap); return ['ok' => false, 'anzahl' => $neu, 'fehler' => 'Abruf-Fehler: ' . $e->getMessage()]; }
    @imap_close($imap);
    return ['ok' => true, 'anzahl' => $neu, 'fehler' => ''];
}

// Abruf ueber den eigenen Socket-Client (ohne ext-imap). Nutzt BxImap + mail_raw_parsen.
function mail_abholen_socket(array $c, int $max): array {
    $imap = new BxImap($c['host'], $c['port'], $c['ssl']);
    if (!$imap->verbinden())            return ['ok' => false, 'anzahl' => 0, 'fehler' => $imap->fehler];
    if (!$imap->anmelden($c['user'], $c['pass'])) { $f = $imap->fehler; $imap->abmelden(); return ['ok' => false, 'anzahl' => 0, 'fehler' => $f]; }
    if (!$imap->waehlen($c['ordner']))  { $f = $imap->fehler; $imap->abmelden(); return ['ok' => false, 'anzahl' => 0, 'fehler' => $f]; }

    $neu = 0;
    try {
        $ids = $imap->ungelesen();
        rsort($ids);
        foreach (array_slice($ids, 0, max(1, $max)) as $uid) {
            try {
                $raw = $imap->roh((int)$uid);
                if ($raw === null || $raw === '') continue;
                $p = mail_raw_parsen($raw);
                $messageId = '';
                if (preg_match('/^message-id:\s*(.+)$/im', $raw, $m)) $messageId = trim($m[1]);
                if ($messageId === '') $messageId = 'uid:' . $c['user'] . ':' . $uid;
                if (scalar("SELECT COUNT(*) FROM crm_mail_eingang WHERE message_id=?", [mb_substr($messageId, 0, 255)])) { $imap->gelesen((int)$uid); continue; }
                $neu += mail_eingang_aufnehmen($messageId, (int)$uid, $p['from_name'], $p['from_email'], $p['subject'], $p['date'], $p['body']);
                $imap->gelesen((int)$uid);
            } catch (Throwable $e) { error_log('mail_abholen(socket): UID ' . $uid . ' uebersprungen: ' . $e->getMessage()); }
        }
    } catch (Throwable $e) { $imap->abmelden(); return ['ok' => false, 'anzahl' => $neu, 'fehler' => 'Abruf-Fehler: ' . $e->getMessage()]; }
    $imap->abmelden();
    return ['ok' => true, 'anzahl' => $neu, 'fehler' => ''];
}

// Absender "Max Mustermann <max@firma.de>" in [Name, E-Mail] zerlegen.
function mail_absender_zerlegen(string $from): array {
    $from = mail_dekodiere_kopf($from);
    if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>/', $from, $m)) {
        return [trim($m[1]), trim(mb_strtolower($m[2]))];
    }
    if (filter_var(trim($from), FILTER_VALIDATE_EMAIL)) return ['', trim(mb_strtolower($from))];
    return [trim($from), ''];
}

// MIME-kodierte Kopfzeile (=?UTF-8?B?...?=) lesbar machen.
function mail_dekodiere_kopf(string $s): string {
    if ($s === '') return '';
    $out = '';
    foreach (imap_mime_header_decode($s) as $teil) {
        $txt = (string)$teil->text; $cs = strtoupper((string)$teil->charset);
        if ($cs !== '' && $cs !== 'UTF-8' && $cs !== 'DEFAULT') {
            $k = @mb_convert_encoding($txt, 'UTF-8', $cs);
            if ($k !== false && $k !== '') $txt = $k;
        }
        $out .= $txt;
    }
    return trim($out);
}

// Den lesbaren Text einer Mail holen: bevorzugt text/plain, sonst text/html entschlackt.
function mail_body_text($imap, int $uid): string {
    $struktur = @imap_fetchstructure($imap, $uid, FT_UID);
    if (!$struktur) {
        $roh = (string) @imap_body($imap, $uid, FT_UID);
        return trim(mb_substr($roh, 0, 60000));
    }
    // Einfache Mail ohne Teile.
    if (empty($struktur->parts)) {
        $roh = (string) @imap_body($imap, $uid, FT_UID);
        return trim(mail_dekodiere_teil($roh, (int)($struktur->encoding ?? 0), $struktur));
    }
    $plain = ''; $html = '';
    mail_teile_sammeln($imap, $uid, $struktur->parts, '', $plain, $html);
    if (trim($plain) !== '') return trim(mb_substr($plain, 0, 60000));
    if (trim($html) !== '')  return trim(mb_substr(mail_html_zu_text($html), 0, 60000));
    return '';
}

// Rekursiv durch die MIME-Teile gehen und text/plain + text/html einsammeln.
function mail_teile_sammeln($imap, int $uid, array $parts, string $prefix, string &$plain, string &$html): void {
    foreach ($parts as $i => $p) {
        $nr = $prefix === '' ? (string)($i + 1) : $prefix . '.' . ($i + 1);
        $subtyp = strtoupper((string)($p->subtype ?? ''));
        if (!empty($p->parts)) { mail_teile_sammeln($imap, $uid, $p->parts, $nr, $plain, $html); continue; }
        // Anhaenge ueberspringen (haben meist disposition=attachment).
        $istAnhang = false;
        if (!empty($p->ifdisposition) && strtoupper((string)$p->disposition) === 'ATTACHMENT') $istAnhang = true;
        if ($istAnhang) continue;
        if ((int)($p->type ?? -1) !== 0) continue; // 0 = text
        $roh = (string) @imap_fetchbody($imap, $uid, $nr, FT_UID);
        $txt = mail_dekodiere_teil($roh, (int)($p->encoding ?? 0), $p);
        if ($subtyp === 'PLAIN')     $plain .= ($plain !== '' ? "\n" : '') . $txt;
        elseif ($subtyp === 'HTML')  $html  .= ($html !== '' ? "\n" : '') . $txt;
    }
}

// Einen Teil nach Transfer-Encoding und Zeichensatz dekodieren.
function mail_dekodiere_teil(string $roh, int $encoding, $part): string {
    // 3 = base64, 4 = quoted-printable (imap-Konstanten).
    if ($encoding === 3)      $roh = (string) imap_base64($roh);
    elseif ($encoding === 4)  $roh = (string) imap_qprint($roh);
    // Zeichensatz aus den Parametern lesen und nach UTF-8 wandeln.
    $cs = '';
    foreach ((array)($part->parameters ?? []) as $pa) if (strtoupper((string)$pa->attribute) === 'CHARSET') $cs = strtoupper((string)$pa->value);
    foreach ((array)($part->dparameters ?? []) as $pa) if (strtoupper((string)$pa->attribute) === 'CHARSET' && $cs === '') $cs = strtoupper((string)$pa->value);
    if ($cs !== '' && $cs !== 'UTF-8' && $cs !== 'US-ASCII') {
        $k = @mb_convert_encoding($roh, 'UTF-8', $cs);
        if ($k !== false && $k !== '') $roh = $k;
    }
    return $roh;
}

// Grobe HTML-zu-Text-Wandlung fuer den Fall, dass nur HTML da ist.
function mail_html_zu_text(string $html): string {
    $html = preg_replace('~<(script|style)[^>]*>.*?</\1>~is', '', $html);
    $html = preg_replace('~<br\s*/?>~i', "\n", $html);
    $html = preg_replace('~</(p|div|tr|li|h[1-6])>~i', "\n", $html);
    $txt = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace("/\n{3,}/", "\n\n", $txt));
}

// --- Eingang: Liste + Anlegen/Verwerfen -------------------------------------------------------
function mail_eingang_liste(string $status = 'neu'): array {
    return all("SELECT * FROM crm_mail_eingang WHERE status=? ORDER BY COALESCE(datum, angelegt) DESC, id DESC LIMIT 200", [$status]);
}
function mail_eingang_zahl(string $status = 'neu'): int {
    return (int) scalar("SELECT COUNT(*) FROM crm_mail_eingang WHERE status=?", [$status]);
}
function mail_eingang(int $id): ?array { return one("SELECT * FROM crm_mail_eingang WHERE id=?", [$id]); }

// Aus den gespeicherten Daten frisch Treffer + Plan rechnen (ohne KI - nur Dubletten-Abgleich).
// So ist das Ziel immer aktuell, auch wenn seit dem Abruf ein Kunde dazugekommen ist.
function mail_eingang_plan(array $row): array {
    $daten = json_decode((string)($row['daten_json'] ?? ''), true);
    if (!is_array($daten)) $daten = [];
    $treffer = (($daten['name'] ?? '') !== '' || ($daten['firma'] ?? '') !== '' || ($daten['email'] ?? '') !== '')
        ? dublette_suchen((string)($daten['name'] ?? ''), (string)($daten['firma'] ?? ''), (string)($daten['email'] ?? ''), (string)($daten['telefon'] ?? ''))
        : [];
    return ['daten' => $daten, 'treffer' => $treffer, 'plan' => mail_ki_plan($daten, $treffer)];
}

// Einen Eingang anlegen: wie die Bestaetigung bei "E-Mail einlesen", nur aus der Eingang-Liste.
// Rueckgabe: ['ok'=>bool, 'ziel'=>'kunde'|'kontakt', 'id'=>int, 'fehler'=>string]
function mail_eingang_anlegen(int $id, int $uid = 0): array {
    $row = mail_eingang($id);
    if (!$row || $row['status'] !== 'neu') return ['ok' => false, 'id' => 0, 'fehler' => 'Dieser Eingang ist nicht (mehr) offen.'];
    $pp   = mail_eingang_plan($row);
    $d    = $pp['daten']; $plan = $pp['plan'];
    $tage = (int)$plan['tage'];
    $notiz = trim((string)$plan['notiz']) !== '' ? (string)$plan['notiz'] : (string)($row['betreff'] ?? 'E-Mail');
    $titel = 'Nachfassen: ' . (($d['betreff'] ?? '') !== '' ? $d['betreff'] : ($plan['ziel_text'] ?: (string)($row['betreff'] ?? '')));

    if ($plan['ziel_art'] === 'kunde' && $plan['ziel_id'] > 0) {
        kunde_verlauf((int)$plan['ziel_id'], 'mail', $notiz, $uid);
        if ($tage > 0) kunde_wiedervorlage((int)$plan['ziel_id'], $titel, $tage, $uid);
        mail_eingang_abschliessen($id, 'angelegt', null, (int)$plan['ziel_id']);
        return ['ok' => true, 'ziel' => 'kunde', 'id' => (int)$plan['ziel_id'], 'fehler' => ''];
    }
    if ($plan['ziel_art'] === 'kontakt' && $plan['ziel_id'] > 0) {
        kontakt_verlauf((int)$plan['ziel_id'], 'mail', $notiz, $uid);
        if ($tage > 0) kontakt_wiedervorlage((int)$plan['ziel_id'], $titel, $tage, $uid);
        mail_eingang_abschliessen($id, 'angelegt', (int)$plan['ziel_id'], null);
        return ['ok' => true, 'ziel' => 'kontakt', 'id' => (int)$plan['ziel_id'], 'fehler' => ''];
    }
    // Sonst: neuer Kontakt (auch wenn der Plan 'nichts' sagt - der Klick ist eine bewusste Entscheidung).
    $kid = kontakt_anlegen([
        'name'     => ($d['name'] ?? '') !== '' ? $d['name'] : (($d['firma'] ?? '') !== '' ? $d['firma'] : ((string)($row['von_name'] ?? '') ?: ((string)($row['von_email'] ?? '') ?: 'Ohne Namen'))),
        'firma'    => (string)($d['firma'] ?? ''),
        'email'    => (string)($d['email'] ?? $row['von_email'] ?? ''),
        'telefon'  => (string)($d['telefon'] ?? ''),
        'quelle'   => 'mail',
        'notiz'    => $notiz,
        'wert_eur' => isset($d['wert']) && $d['wert'] !== null ? (string)$d['wert'] : '',
    ], $uid);
    if ($tage > 0) kontakt_wiedervorlage($kid, $titel, $tage, $uid);
    mail_eingang_abschliessen($id, 'angelegt', $kid, null);
    return ['ok' => true, 'ziel' => 'kontakt', 'id' => $kid, 'fehler' => ''];
}

function mail_eingang_verwerfen(int $id): void { mail_eingang_abschliessen($id, 'verworfen', null, null); }

function mail_eingang_abschliessen(int $id, string $status, ?int $kontakt_id, ?int $kunde_id): void {
    q("UPDATE crm_mail_eingang SET status=?, kontakt_id=?, kunde_id=?, bearbeitet=? WHERE id=?",
      [$status, $kontakt_id, $kunde_id, gmdate('Y-m-d H:i:s'), $id]);
}

// Token fuer den Cron-Abruf (public/crm/mail_cron.php). Wird beim ersten Zugriff erzeugt und in
// crm_meta gemerkt; ueber "Mehr" neu erzeugbar. So kann der Hintergrund-Abruf ohne Login laufen.
function mail_cron_token(): string {
    $t = crm_meta_lesen('mail_cron_token', '');
    if ($t === '') { $t = bin2hex(random_bytes(24)); crm_meta_schreiben('mail_cron_token', $t); }
    return $t;
}
