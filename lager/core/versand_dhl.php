<?php
// DHL Paket DE – "Versenden" API v2 (Geschaeftskunden). Erzeugt eine Sendung + Label.
//   POST {base}/parcel/de/shipping/v2/orders?includeDocs=include
//   Auth: Header dhl-api-key + HTTP-Basic (GK-Benutzer:Passwort).
//   Produkt je Zielland: V01PAK (DE), V54EPAK (EU), V53WPAK (Welt).
// Doku: https://developer.dhl.com/api-reference/parcel-de-shipping-post-parcel-germany-v2

function dhl_eu_laender(): array {
    return ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE'];
}
// DHL erwartet 3-stellige ISO-Laendercodes. Gaengige Faelle; sonst bleibt der 2-Buchstaben-Code (API meldet dann sauber).
function dhl_iso3(string $c): string {
    $c = strtoupper(trim($c));
    $m = ['DE'=>'DEU','AT'=>'AUT','CH'=>'CHE','FR'=>'FRA','IT'=>'ITA','ES'=>'ESP','NL'=>'NLD','BE'=>'BEL','LU'=>'LUX',
        'DK'=>'DNK','PL'=>'POL','CZ'=>'CZE','SE'=>'SWE','FI'=>'FIN','GB'=>'GBR','IE'=>'IRL','US'=>'USA','CA'=>'CAN',
        'NO'=>'NOR','PT'=>'PRT','HU'=>'HUN','RO'=>'ROU','GR'=>'GRC','HR'=>'HRV','SI'=>'SVN','SK'=>'SVK','BG'=>'BGR',
        'EE'=>'EST','LV'=>'LVA','LT'=>'LTU','CY'=>'CYP','MT'=>'MLT'];
    return $m[$c] ?? $c;
}

function dhl_label_erstellen(array $v, array $abs, array $cfg): array {
    $base = !empty($cfg['sandbox']) ? 'https://api-sandbox.dhl.com' : 'https://api-eu.dhl.com';
    $url  = $base . '/parcel/de/shipping/v2/orders?includeDocs=include';

    $land = strtoupper(trim((string)$v['empf_land'])) ?: 'DE';
    $product = $land === 'DE' ? 'V01PAK' : (in_array($land, dhl_eu_laender(), true) ? 'V54EPAK' : 'V53WPAK');
    $gewicht = (float)($v['gewicht_kg'] ?? 0); if ($gewicht <= 0) $gewicht = 1.0;

    $consignee = [
        'name1'         => trim((string)$v['empf_firma']) ?: trim((string)$v['empf_name']),
        'addressStreet' => trim((string)$v['empf_strasse'] . ' ' . (string)$v['empf_hausnummer']),
        'postalCode'    => (string)$v['empf_plz'],
        'city'          => (string)$v['empf_ort'],
        'country'       => dhl_iso3($land),
    ];
    if (trim((string)$v['empf_name']) !== '' && trim((string)$v['empf_firma']) !== '') $consignee['name2'] = trim((string)$v['empf_name']);
    if (trim((string)$v['empf_email']) !== '')   $consignee['email'] = (string)$v['empf_email'];
    if (trim((string)$v['empf_telefon']) !== '') $consignee['phone'] = (string)$v['empf_telefon'];

    $shipment = [
        'product'       => $product,
        'billingNumber' => (string)$cfg['billing'],
        'refNo'         => (string)$v['nummer'],
        'shipper' => [
            'name1'         => $abs['name'],
            'addressStreet' => trim($abs['strasse'] . ' ' . $abs['hausnummer']),
            'postalCode'    => $abs['plz'],
            'city'          => $abs['ort'],
            'country'       => dhl_iso3($abs['land']),
        ] + ($abs['email'] !== '' ? ['email' => $abs['email']] : []) + ($abs['telefon'] !== '' ? ['phone' => $abs['telefon']] : []),
        'consignee' => $consignee,
        'details'   => ['weight' => ['uom' => 'kg', 'value' => $gewicht]],
    ];
    $payload = ['profile' => 'STANDARD_GRUPPENPROFIL', 'shipments' => [$shipment]];

    $headers = [
        'Content-Type: application/json', 'Accept: application/json',
        'dhl-api-key: ' . $cfg['key'],
        'Authorization: Basic ' . base64_encode($cfg['user'] . ':' . $cfg['secret']),
    ];
    $r = versand_http('POST', $url, $headers, json_encode($payload, JSON_UNESCAPED_UNICODE));
    if ($r['err'] !== '') return ['ok' => false, 'fehler' => 'DHL: Verbindungsfehler (' . $r['err'] . ')'];
    $j = json_decode($r['body'], true);

    if ($r['status'] >= 200 && $r['status'] < 300 && isset($j['items'][0])) {
        $it  = $j['items'][0];
        $sno = (string)($it['shipmentNo'] ?? '');
        $pdf = '';
        if (!empty($it['label']['b64'])) $pdf = (string) base64_decode((string)$it['label']['b64']);
        elseif (!empty($it['label']['url'])) {
            $lr = versand_http('GET', (string)$it['label']['url'], ['dhl-api-key: ' . $cfg['key'], 'Authorization: Basic ' . base64_encode($cfg['user'] . ':' . $cfg['secret'])]);
            if ($lr['status'] === 200) $pdf = $lr['body'];
        }
        return ['ok' => true, 'tracking' => $sno, 'pdf' => $pdf, 'format' => 'A4'];
    }
    $msg = $j['items'][0]['message'] ?? $j['items'][0]['validationMessages'][0]['validationMessage'] ?? $j['detail'] ?? $j['title'] ?? ('HTTP ' . $r['status']);
    return ['ok' => false, 'fehler' => 'DHL: ' . (is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE))];
}
