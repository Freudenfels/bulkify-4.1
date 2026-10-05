<?php
// DHL Parcel DE – Shipping (Post & Parcel Germany) API v2. Erzeugt Sendung + Label in einem Aufruf.
// Portiert aus fulfillment-web/src/dhl.php, angepasst an die Lager-Datenstruktur (lg_versand + lg_meta).
//   Base: api-sandbox.dhl.com / api-eu.dhl.com  (…/parcel/de/shipping/v2)
//   Auth: Header dhl-api-key + HTTP-Basic (GK-User:Passwort).  Siehe ANLEITUNG-DHL-VERSAND.md.
//
// $cfg (aus versand_cfg()['dhl']): key, user, secret, sandbox, ekp, tn_v01pak/_v62wp/_v53wpak/_v66wpi,
//                                  billing (14-stellige Alt-Nummer, Fallback), format_gross, format_klein.

function dhl_land3(string $iso2): string {
    $m = ['DE'=>'DEU','AT'=>'AUT','CH'=>'CHE','FR'=>'FRA','IT'=>'ITA','ES'=>'ESP','NL'=>'NLD','BE'=>'BEL','LU'=>'LUX',
        'DK'=>'DNK','PL'=>'POL','CZ'=>'CZE','SE'=>'SWE','FI'=>'FIN','GB'=>'GBR','IE'=>'IRL','US'=>'USA','CA'=>'CAN',
        'NO'=>'NOR','PT'=>'PRT','HU'=>'HUN','RO'=>'ROU','GR'=>'GRC','HR'=>'HRV','SI'=>'SVN','SK'=>'SVK','BG'=>'BGR',
        'EE'=>'EST','LV'=>'LVA','LT'=>'LTU','CY'=>'CYP','MT'=>'MLT'];
    return $m[strtoupper(trim($iso2))] ?? strtoupper(trim($iso2));
}
function dhl_ist_eu(string $iso2): bool {
    return in_array(strtoupper(trim($iso2)), ['DE','AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE'], true);
}
// Produktwahl: national(DE) vs international, groß(Paket) vs klein(Kleinpaket/Warenpost).
function dhl_produkt(string $iso2, string $groesse): string {
    $intl = strtoupper(trim($iso2)) !== 'DE';
    if ($groesse === 'klein') return $intl ? 'V66WPI' : 'V62KP';
    return $intl ? 'V53WPAK' : 'V01PAK';
}
// billingNumber = EKP(10) + Teilnahmenummer(4) je Produkt. Sandbox = DHL-Testnummern.
function dhl_billing(array $cfg, string $product): string {
    $proc = ['V01PAK'=>'01','V62KP'=>'62','V53WPAK'=>'53','V66WPI'=>'66'][$product] ?? '01';
    if (!empty($cfg['sandbox'])) return '3333333333' . $proc . '02';   // DHL-Test-EKP
    $ekp = preg_replace('/\D/', '', (string)($cfg['ekp'] ?? ''));
    $tnMap = ['V01PAK'=>'tn_v01pak','V62KP'=>'tn_v62wp','V53WPAK'=>'tn_v53wpak','V66WPI'=>'tn_v66wpi'];
    $tn = preg_replace('/\D/', '', (string)($cfg[$tnMap[$product] ?? ''] ?? ''));
    if (strlen($ekp) === 10 && strlen($tn) === 4) return $ekp . $tn;
    // Fallback: 14-stellige Alt-Nummer, Verfahrens-Code zum Produkt tauschen.
    $base = preg_replace('/\D/', '', (string)($cfg['billing'] ?? ''));
    if (strlen($base) === 14) return substr($base, 0, 10) . $proc . substr($base, 12, 2);
    return $base;
}

function dhl_request(array $cfg, string $method, string $path, ?array $body = null): array {
    $base = !empty($cfg['sandbox']) ? 'https://api-sandbox.dhl.com/parcel/de/shipping/v2' : 'https://api-eu.dhl.com/parcel/de/shipping/v2';
    $user = ($cfg['user'] ?? '') ?: (!empty($cfg['sandbox']) ? 'user-valid' : '');
    $pass = ($cfg['secret'] ?? '') ?: (!empty($cfg['sandbox']) ? 'SandboxPasswort2023!' : '');
    $headers = [
        'dhl-api-key: ' . (string)($cfg['key'] ?? ''),
        'Authorization: Basic ' . base64_encode("$user:$pass"),
        'Accept: application/json', 'Accept-Language: de-DE',
    ];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    $r = versand_http($method, $base . $path, $headers, $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null);
    return ['http' => $r['status'], 'raw' => $r['body'], 'json' => json_decode((string)$r['body'], true), 'err' => $r['err']];
}

// Shipment-Objekt aus Sendung (lg_versand) + strukturiertem Absender.
function dhl_build_shipment(array $v, array $abs, array $cfg, ?string &$fehler = null): ?array {
    $land = strtoupper(trim((string)$v['empf_land'])) ?: 'DE';
    $groesse = ((string)($v['dhl_groesse'] ?? 'gross') === 'klein') ? 'klein' : 'gross';
    $product = dhl_produkt($land, $groesse);

    // Nicht-EU braucht eine Zollinhaltserklärung (CN23) mit HS-Code/Warenwert – im Lager noch nicht erfasst.
    if (!dhl_ist_eu($land)) { $fehler = 'Nicht-EU-Sendung (' . $land . ') braucht Zolldaten (HS-Code/Warenwert) – kommt als nächster Schritt. Bitte vorerst EU/DE oder Zollpapier manuell.'; return null; }

    $gewicht_g = (int) round(((float)($v['gewicht_kg'] ?? 0) ?: 0.5) * 1000);
    if ($gewicht_g < 1) $gewicht_g = 500;

    $consignee = [
        'name1'         => trim((string)$v['empf_firma']) ?: trim((string)$v['empf_name']),
        'addressStreet' => trim((string)$v['empf_strasse']),
        'addressHouse'  => trim((string)$v['empf_hausnummer']),
        'postalCode'    => (string)$v['empf_plz'],
        'city'          => (string)$v['empf_ort'],
        'country'       => dhl_land3($land),
    ];
    if (trim((string)$v['empf_name']) !== '' && trim((string)$v['empf_firma']) !== '') $consignee['name2'] = trim((string)$v['empf_name']);
    if (trim((string)$v['empf_email']) !== '') $consignee['email'] = (string)$v['empf_email'];

    $shipper = [
        'name1'         => $abs['name'],
        'addressStreet' => $abs['strasse'],
        'addressHouse'  => $abs['hausnummer'],
        'postalCode'    => $abs['plz'],
        'city'          => $abs['ort'],
        'country'       => dhl_land3($abs['land']),
    ];
    if (($abs['email'] ?? '') !== '') $shipper['email'] = $abs['email'];

    $ship = [
        'product'       => $product,
        'billingNumber' => dhl_billing($cfg, $product),
        'refNo'         => substr(trim((string)$v['nummer'] . ' ' . trim((string)$v['empf_firma'])), 0, 35) ?: (string)$v['nummer'],
        'shipper'       => $shipper,
        'consignee'     => $consignee,
        'details'       => ['weight' => ['uom' => 'g', 'value' => $gewicht_g]],
    ];
    $services = [];
    if (in_array($product, ['V01PAK', 'V53WPAK'], true)) $services['endorsement'] = 'RETURN';
    if (in_array($product, ['V53WPAK', 'V66WPI'], true)) $services['premium'] = true;
    if ($services) $ship['services'] = $services;
    return $ship;
}

// Haupt-Einstieg (vom Dispatcher aufgerufen). Rueckgabe ['ok','tracking','pdf','format','fehler'].
function dhl_label_erstellen(array $v, array $abs, array $cfg): array {
    $fehler = null;
    $ship = dhl_build_shipment($v, $abs, $cfg, $fehler);
    if (!$ship) return ['ok' => false, 'fehler' => 'DHL: ' . ($fehler ?: 'Sendung konnte nicht gebaut werden.')];

    $groesse = ((string)($v['dhl_groesse'] ?? 'gross') === 'klein') ? 'klein' : 'gross';
    $printFormat = $groesse === 'klein' ? (string)$cfg['format_klein'] : (string)$cfg['format_gross'];
    $path = '/orders?includeDocs=include&printFormat=' . rawurlencode($printFormat) . '&docFormat=PDF';
    $r = dhl_request($cfg, 'POST', $path, ['profile' => 'STANDARD_GRUPPENPROFIL', 'shipments' => [$ship]]);
    if ($r['err'] !== '') return ['ok' => false, 'fehler' => 'DHL: Verbindungsfehler (' . $r['err'] . ')'];

    $j = $r['json'];
    $item = $j['items'][0] ?? null;
    if (($r['http'] === 200 || $r['http'] === 207) && $item) {
        $sno = (string)($item['shipmentNo'] ?? $item['shipmentNumber'] ?? '');
        $b64 = (string)($item['label']['b64'] ?? $item['label']['content'] ?? '');
        $pdf = $b64 !== '' ? (string) base64_decode($b64) : '';
        if ($sno !== '' && $pdf !== '') return ['ok' => true, 'tracking' => $sno, 'pdf' => $pdf, 'format' => $printFormat];
    }
    $msg = '';
    if (is_array($j)) {
        if (!empty($j['status']['detail'])) $msg = (string)$j['status']['detail'];
        foreach (($item['validationMessages'] ?? []) as $m) $msg .= ' — ' . (is_array($m) ? ($m['validationMessage'] ?? json_encode($m)) : (string)$m);
    }
    return ['ok' => false, 'fehler' => 'DHL: ' . ($msg !== '' ? $msg : ('HTTP ' . $r['http']))];
}

// Zugangsdaten prüfen (erzeugt KEINE Sendung). Rueckgabe ['ok','meldung'].
function dhl_validate(array $cfg): array {
    $testV = ['nummer' => 'PRUEF', 'empf_firma' => 'Test Empfänger', 'empf_name' => '', 'empf_strasse' => 'Charles-de-Gaulle-Strasse',
        'empf_hausnummer' => '20', 'empf_plz' => '53113', 'empf_ort' => 'Bonn', 'empf_land' => 'DE', 'empf_email' => '',
        'gewicht_kg' => 0.5, 'dhl_groesse' => 'gross'];
    $abs = function_exists('versand_absender_struktur') ? versand_absender_struktur() : [];
    if (trim(($abs['name'] ?? '') . ($abs['ort'] ?? '')) === '') return ['ok' => false, 'meldung' => 'Bitte zuerst den Absender unter Formate eintragen.'];
    $fehler = null;
    $ship = dhl_build_shipment($testV, $abs, $cfg, $fehler);
    if (!$ship) return ['ok' => false, 'meldung' => (string)$fehler];
    $r = dhl_request($cfg, 'POST', '/orders?validate=true', ['profile' => 'STANDARD_GRUPPENPROFIL', 'shipments' => [$ship]]);
    if ($r['err'] !== '') return ['ok' => false, 'meldung' => 'Verbindungsfehler: ' . $r['err']];
    if ($r['http'] === 200) return ['ok' => true, 'meldung' => 'Zugang ok – DHL hat die Testsendung akzeptiert.'];
    $j = $r['json']; $msg = '';
    if (is_array($j)) {
        if (!empty($j['status']['detail'])) $msg = (string)$j['status']['detail'];
        foreach (($j['items'][0]['validationMessages'] ?? []) as $m) $msg .= ' — ' . (is_array($m) ? ($m['validationMessage'] ?? json_encode($m)) : (string)$m);
    }
    $hint = in_array($r['http'], [401, 403], true) ? ' (Login/API-Key prüfen)' : '';
    return ['ok' => false, 'meldung' => ($msg !== '' ? $msg : ('HTTP ' . $r['http'])) . $hint];
}

// Sendung stornieren (nur solange DHL sie noch nicht übergeben hat). Rueckgabe ['ok','meldung'].
function dhl_cancel(array $cfg, string $sendungsnr): array {
    if (trim($sendungsnr) === '') return ['ok' => false, 'meldung' => 'Keine Sendungsnummer.'];
    $path = '/orders?profile=' . rawurlencode('STANDARD_GRUPPENPROFIL') . '&shipment=' . rawurlencode($sendungsnr);
    $r = dhl_request($cfg, 'DELETE', $path, null);
    if ($r['http'] === 200) return ['ok' => true, 'meldung' => 'Sendung storniert.'];
    $j = $r['json']; $msg = is_array($j) && !empty($j['status']['detail']) ? (string)$j['status']['detail'] : ('HTTP ' . $r['http']);
    return ['ok' => false, 'meldung' => $msg];
}
