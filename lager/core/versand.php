<?php
// Carrier-Schicht fuer den Warenausgang. Liest die Zugaenge aus lg_meta (Einstellungen -> Zugaenge),
// waehlt den Carrier je Versandart (Paket -> DHL, Palette -> Cargoboard) und erzeugt Label + Tracking.
// Die eigentlichen API-Clients stehen in versand_dhl.php / versand_cargoboard.php.

// Zugaenge aus den Einstellungen.
function versand_cfg(): array {
    $g = fn(string $k, string $d = ''): string => function_exists('lg_meta_lesen') ? lg_meta_lesen($k, $d) : $d;
    return [
        'dhl' => [
            'user' => trim($g('dhl_api_user')), 'key' => trim($g('dhl_api_key')), 'secret' => trim($g('dhl_api_secret')),
            'billing' => trim($g('dhl_abrechnungsnummer')), 'sandbox' => $g('dhl_sandbox', '1') === '1',
            'ekp' => trim($g('dhl_ekp')),
            'tn_v01pak' => trim($g('dhl_tn_v01pak')), 'tn_v62wp' => trim($g('dhl_tn_v62wp')),
            'tn_v53wpak' => trim($g('dhl_tn_v53wpak')), 'tn_v66wpi' => trim($g('dhl_tn_v66wpi')),
            'format_gross' => trim($g('dhl_format_gross', '910-300-400')) ?: '910-300-400',
            'format_klein' => trim($g('dhl_format_klein', '100x70mm')) ?: '100x70mm',
        ],
        'cargoboard' => [
            'key' => trim($g('cargoboard_api_key')), 'sandbox' => $g('cargoboard_sandbox', '1') === '1',
        ],
    ];
}

// Strukturierter Absender (Einstellungen -> Formate) – fuer die Carrier-Requests.
function versand_absender_struktur(): array {
    $g = fn(string $k, string $d = ''): string => function_exists('lg_meta_lesen') ? lg_meta_lesen($k, $d) : $d;
    return [
        'name' => trim($g('absender_name')), 'strasse' => trim($g('absender_strasse')), 'hausnummer' => trim($g('absender_hausnummer')),
        'plz' => trim($g('absender_plz')), 'ort' => trim($g('absender_ort')), 'land' => strtoupper(trim($g('absender_land', 'DE'))) ?: 'DE',
        'email' => trim($g('absender_email')), 'telefon' => trim($g('absender_telefon')),
    ];
}

// Minimaler HTTP-Helfer. Rueckgabe: ['status'=>int,'body'=>string,'err'=>string,'ct'=>string].
function versand_http(string $method, string $url, array $headers, ?string $body = null): array {
    if (!function_exists('curl_init')) return ['status' => 0, 'body' => '', 'err' => 'PHP-curl fehlt', 'ct' => ''];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if (defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);   // Windows: System-Zertifikate
    $res = curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['status' => $st, 'body' => $res === false ? '' : (string)$res, 'err' => $err, 'ct' => $ct];
}

// Haupt-Einstieg: Versand-Label fuer eine Sendung erzeugen. Waehlt den Carrier je Versandart.
// Rueckgabe ['ok'=>bool,'tracking'=>?,'carrier'=>?,'fehler'=>?]. Bei Erfolg: Label + Tracking gespeichert.
function versand_label_erstellen(int $versand_id): array {
    if (!function_exists('lg_versand')) return ['ok' => false, 'fehler' => 'Versand nicht verfügbar.'];
    $v = lg_versand($versand_id);
    if (!$v) return ['ok' => false, 'fehler' => 'Sendung nicht gefunden.'];
    if (trim((string)$v['empf_ort'] . (string)$v['empf_plz']) === '') return ['ok' => false, 'fehler' => 'Empfängeradresse unvollständig.'];
    $cfg = versand_cfg();
    $abs = versand_absender_struktur();
    if (trim($abs['name'] . $abs['ort']) === '') return ['ok' => false, 'fehler' => 'Absender fehlt – unter Einstellungen → Formate eintragen.'];

    if ((string)$v['typ'] === 'palette') {
        if ($cfg['cargoboard']['key'] === '') return ['ok' => false, 'fehler' => 'Cargoboard-Zugang fehlt – unter Einstellungen → Zugänge eintragen.'];
        require_once __DIR__ . '/versand_cargoboard.php';
        $r = cargoboard_label_erstellen($v, $abs, $cfg['cargoboard']);
        $carrier = 'cargoboard';
    } else {
        $d = $cfg['dhl'];
        if ($d['key'] === '') return ['ok' => false, 'fehler' => 'DHL API-Key fehlt – unter Einstellungen → Zugänge eintragen.'];
        if (!$d['sandbox'] && ($d['user'] === '' || $d['secret'] === ''))
            return ['ok' => false, 'fehler' => 'DHL GK-Login fehlt (Benutzer/Passwort) – unter Einstellungen → Zugänge eintragen.'];
        require_once __DIR__ . '/versand_dhl.php';
        $r = dhl_label_erstellen($v, $abs, $d);
        $carrier = 'dhl';
    }
    if (empty($r['ok'])) return ['ok' => false, 'fehler' => (string)($r['fehler'] ?? 'Unbekannter Fehler.')];

    lg_versand_label_set($versand_id, $carrier, (string)($r['format'] ?? 'A4'), (string)($r['pdf'] ?? ''), (string)($r['zoll_pdf'] ?? ''));
    lg_versand_tracking_setzen($versand_id, (string)($r['tracking'] ?? ''), $carrier);
    return ['ok' => true, 'tracking' => (string)($r['tracking'] ?? ''), 'carrier' => $carrier];
}

// Sendung beim Carrier stornieren (nur DHL; solange noch nicht übergeben). Loescht Label + Tracking.
function versand_storno(int $versand_id): array {
    $v = lg_versand($versand_id);
    if (!$v) return ['ok' => false, 'meldung' => 'Sendung nicht gefunden.'];
    if ((string)$v['carrier'] !== 'dhl') return ['ok' => false, 'meldung' => 'Storno ist aktuell nur für DHL möglich.'];
    require_once __DIR__ . '/versand_dhl.php';
    $r = dhl_cancel(versand_cfg()['dhl'], (string)($v['tracking'] ?? ''));
    if (!empty($r['ok'])) {
        q("DELETE FROM lg_versand_label WHERE versand_id=?", [$versand_id]);
        q("UPDATE lg_versand SET tracking=NULL WHERE id=?", [$versand_id]);
    }
    return $r;
}

// DHL-Zugang testen (ohne echte Sendung). Rueckgabe ['ok','meldung'].
function versand_dhl_test(): array {
    require_once __DIR__ . '/versand_dhl.php';
    return dhl_validate(versand_cfg()['dhl']);
}
