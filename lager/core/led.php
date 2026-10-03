<?php
// LED-Treiber fuer die Blinker (Jinzhishi / 金之识). Alles, was die Hardware betrifft, steht HIER.
//
// Der Befehl an einen Blinker ist ein 16-stelliger Hex-Code:
//   FD10 + Blinker (6) + Farbe (1) + Dauer (1) + 50DF
//   Beispiel: FD10 D73CE3 A 8 50DF = Blinker D73CE3, rot mit Piepton, 60 Sekunden
//   Ausschalten: Farbe und Dauer beide 0.
// Der Sender nimmt ihn im Lager-Netz entgegen: GET http://{ip}/light?code=...  -> {"ok":true,...}
//
// Drei Wege, wie ein Befehl zum Sender kommt (Einstellung je Sender, lg_sender.weg):
//   bruecke - wird in lg_befehl abgelegt und vom Brueckenprogramm im Lager abgeholt
//             (public/lager/bruecke.php). Normalfall: der Server kommt nicht an eine Lager-IP.
//   direkt  - der Server ruft die IP selbst auf. Nur, wenn bulkify im selben Netz laeuft.
//   cloud   - Open-API des Herstellers (Sender mit 4G). Zugang aus secrets.php:
//             LG_CLOUD_URL, LG_CLOUD_APP_ID, LG_CLOUD_SECRET. NOCH NICHT an echter Hardware getestet.
//
// Jede Anforderung wird in lg_befehl protokolliert - auch direkt/cloud.
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/ui.php';

// Farbe => [Code mit Piepton, Code still, Anzeigename, Farbwert fuer die Anzeige]
function led_farben(): array {
    return [
        'gruen' => ['9', '1', 'Grün',  '#1f9d55'],
        'rot'   => ['A', '2', 'Rot',   '#d64545'],
        'blau'  => ['C', '4', 'Blau',  '#2b6cd4'],
        'gelb'  => ['B', '3', 'Gelb',  '#e0b000'],
        'pink'  => ['E', '6', 'Pink',  '#e57aa8'],
        'cyan'  => ['D', '5', 'Cyan',  '#1fb5c4'],
        'weiss' => ['F', '7', 'Weiß',  '#bbbbbb'],
    ];
}

// Sekunden => Code. Andere Werte gibt es nicht; led_sekunden() rundet auf den naechsten ab.
function led_dauern(): array {
    return [3 => '0', 6 => '2', 20 => '4', 40 => '6', 60 => '8', 90 => 'A', 120 => 'C', 180 => 'E'];
}
function led_sekunden(int $wunsch): int {
    $ok = 3;
    foreach (array_keys(led_dauern()) as $s) if ($s <= $wunsch) $ok = $s;
    return $ok;
}

// Aus dem Barcode auf dem Blinker (z. B. "D73CE3XD") den 6-stelligen Code machen.
// Nimmt auch den nackten Code ("d73ce3") an. null, wenn es kein gueltiger Code ist.
function led_leiste_normalisieren(string $scan): ?string {
    $s = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $scan));
    if (strlen($s) === 8 && str_ends_with($s, 'XD')) $s = substr($s, 0, 6);
    return preg_match('/^[0-9A-F]{6}$/', $s) ? $s : null;
}

function led_code(string $leiste, string $farbe, bool $piep, int $sekunden): string {
    $f = led_farben()[$farbe] ?? led_farben()['gruen'];
    return 'FD10' . $leiste . ($piep ? $f[0] : $f[1]) . led_dauern()[led_sekunden($sekunden)] . '50DF';
}
function led_code_aus(string $leiste): string { return 'FD10' . $leiste . '00' . '50DF'; }

// ---------------------------------------------------------------------------------------------
// Sender

function led_sender(int $id): ?array { return one("SELECT * FROM lg_sender WHERE id=?", [$id]); }
function led_sender_alle(): array    { return all("SELECT * FROM lg_sender ORDER BY aktiv DESC, name"); }

// Standard = erster aktiver Sender. Reicht, solange es nur einen gibt.
function led_sender_standard(): ?array {
    return one("SELECT * FROM lg_sender WHERE aktiv=1 ORDER BY id LIMIT 1");
}
function led_wege(): array {
    return ['bruecke' => 'Über die Brücke im Lager', 'direkt' => 'Direkt vom Server (gleiches Netz)',
            'cloud' => 'Über die Hersteller-Cloud (4G)'];
}

// ---------------------------------------------------------------------------------------------
// Kern: Befehl protokollieren und je nach Weg des Senders zustellen.
function led_befehl(string $leiste, ?int $sender_id, string $code, string $farbe, int $sekunden,
                    bool $piep, ?int $platz_id = null): array {
    $s = $sender_id ? led_sender($sender_id) : led_sender_standard();
    if (!$s || !(int)$s['aktiv']) {
        return ['ok' => false, 'meldung' => 'Kein aktiver Sender eingerichtet (System → Sender und Brücke).'];
    }

    q("INSERT INTO lg_befehl (sender_id, platz_id, leiste, code, farbe, sekunden, piep, status, benutzer_id, angelegt)
       VALUES (?,?,?,?,?,?,?, 'offen', ?, ?)",
      [(int)$s['id'], $platz_id, $leiste, $code, $farbe, $sekunden, $piep ? 1 : 0,
       function_exists('lg_uid') ? (lg_uid() ?: null) : null, jetzt_utc()]);
    $id = insert_id();

    // Nutzung je Blinker mitzaehlen (Akku-Schaetzung). Nur echtes Leuchten, kein Ausschalten.
    if ($farbe !== 'aus') {
        q("UPDATE lg_leiste SET ausloesungen = ausloesungen + 1, verbrauch_sek = verbrauch_sek + ? WHERE code = ?",
          [max(0, $sekunden), $leiste]);
    }

    if ($s['weg'] === 'bruecke') {
        $zuletzt = lg_meta_lesen('bruecke_zuletzt', '');
        $wach = $zuletzt !== '' && (time() - strtotime($zuletzt . ' UTC')) < 15;
        if ($wach) return ['ok' => true, 'meldung' => 'An die Brücke übergeben.'];
        // Nicht liegen lassen: Sonst leuchtet es irgendwann spaeter, obwohl hier "Fehler" stand.
        q("UPDATE lg_befehl SET status='verfallen', antwort='Brücke nicht erreichbar', erledigt=? WHERE id=?", [jetzt_utc(), $id]);
        return ['ok' => false, 'meldung' => 'Die Brücke im Lager meldet sich nicht (zuletzt ' . vor_wann($zuletzt ?: null)
            . '). Läuft das Brückenprogramm?'];
    }

    $r = $s['weg'] === 'cloud'
        ? led_cloud_senden($s, $leiste, $farbe, $sekunden, $piep)
        : led_lan_senden((string)$s['ip'], $code);

    q("UPDATE lg_befehl SET status=?, antwort=?, erledigt=? WHERE id=?",
      [$r['ok'] ? 'ok' : 'fehler', mb_substr($r['antwort'], 0, 500), jetzt_utc(), $id]);
    return ['ok' => $r['ok'], 'meldung' => $r['ok'] ? ($farbe === 'aus' ? 'Ausgeschaltet.' : 'Leuchtet.') : 'Sender antwortet nicht: ' . $r['antwort']];
}

// URL, die die Bruecke (oder der Server selbst) im Lager-Netz aufruft.
function led_lan_url(string $ip, string $code): string {
    return 'http://' . trim($ip) . '/light?code=' . rawurlencode($code);
}

// Weg "direkt": der Server ruft den Sender selbst auf.
function led_lan_senden(string $ip, string $code): array {
    if (trim($ip) === '') return ['ok' => false, 'antwort' => 'Keine IP-Adresse beim Sender hinterlegt.'];
    [$status, $body, $fehler] = led_http('GET', led_lan_url($ip, $code), [], null, 3);
    if ($fehler !== '') return ['ok' => false, 'antwort' => $fehler];
    $j = json_decode($body, true);
    return ['ok' => $status === 200 && !empty($j['ok']), 'antwort' => $status . ' ' . $body];
}

// ---------------------------------------------------------------------------------------------
// Weg "cloud": Open-API des Herstellers.
//
// Signatur laut Doku: Parameter nach Namen sortiert, leere weglassen, "k=v&" aneinander,
// dann "nonce=...&secret_key=...". signature = MD5(app_id + timestamp + str + app_secret).
// Die Adresse der API steht NICHT in der Doku - LG_CLOUD_URL muss vom Hersteller kommen.

function led_cloud_bereit(): bool {
    return defined('LG_CLOUD_URL') && defined('LG_CLOUD_APP_ID') && defined('LG_CLOUD_SECRET')
        && LG_CLOUD_URL !== '' && LG_CLOUD_APP_ID !== '' && LG_CLOUD_SECRET !== '';
}

function led_cloud_senden(array $s, string $leiste, string $farbe, int $sekunden, bool $piep): array {
    if ($farbe === 'aus') {
        return led_cloud_aufruf('POST', '/api/open/turnOff', ['light_strip_code' => $leiste, 'device_sn' => (string)$s['sn']]);
    }
    // Die Cloud kennt nur 3/6/20/40/60 Sekunden und immer den Buchstaben der Farbe.
    $sek = 3;
    foreach ([3, 6, 20, 40, 60] as $x) if ($x <= $sekunden) $sek = $x;
    return led_cloud_aufruf('POST', '/api/open/turnOn', [
        'light_strip_code' => $leiste, 'device_sn' => (string)$s['sn'],
        'mode' => $piep ? 1 : 2, 'color' => (led_farben()[$farbe] ?? led_farben()['gruen'])[0], 'seconds' => $sek,
    ]);
}

function led_cloud_aufruf(string $methode, string $pfad, array $param): array {
    if (!led_cloud_bereit()) return ['ok' => false, 'antwort' => 'Cloud-Zugang fehlt in secrets.php.'];
    $ts = (string)time();
    $nonce = bin2hex(random_bytes(8));
    $sig = $param;
    ksort($sig);
    $str = '';
    foreach ($sig as $k => $v) { if ($v === '' || $v === null) continue; $str .= $k . '=' . $v . '&'; }
    $str = trim($str . 'nonce=' . $nonce . '&secret_key=' . LG_CLOUD_SECRET, '&');
    $kopf = ['X-App-Id: ' . LG_CLOUD_APP_ID, 'X-Timestamp: ' . $ts, 'X-Nonce: ' . $nonce,
             'X-Signature: ' . md5(LG_CLOUD_APP_ID . $ts . $str . LG_CLOUD_SECRET)];
    $url = rtrim(LG_CLOUD_URL, '/') . $pfad;
    if ($methode === 'GET' && $param) $url .= '?' . http_build_query($param);
    [$status, $body, $fehler] = led_http($methode, $url, $kopf, $methode === 'GET' ? null : http_build_query($param), 8);
    if ($fehler !== '') return ['ok' => false, 'antwort' => $fehler];
    $j = json_decode($body, true);
    return ['ok' => $status === 200 && (int)($j['code'] ?? 0) === 1, 'antwort' => $status . ' ' . $body];
}

// Schlanker HTTP-Aufruf. Rueckgabe: [status, body, fehlertext]
function led_http(string $methode, string $url, array $kopf, ?string $body, int $timeout): array {
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => $timeout, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_HTTPHEADER => $kopf,
    ]);
    if ($body !== null) curl_setopt($c, CURLOPT_POSTFIELDS, $body);
    // Geraete im eigenen Netz nie ueber einen Proxy ansprechen. Auf Entwickler-Rechnern ist oft
    // ein Proxy gesetzt (VPN, Clash), der eine LAN-IP mit 502 quittiert. curl erbt den sonst aus
    // http_proxy/HTTP_PROXY. Fuer private IPv4-Adressen also den Proxy hart abschalten.
    $host = (string)parse_url($url, PHP_URL_HOST);
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE)) {
        curl_setopt($c, CURLOPT_PROXY, '');
    }
    $antwort = curl_exec($c);
    $fehler = $antwort === false ? curl_error($c) : '';
    $status = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return [$status, (string)$antwort, $fehler];
}
