<?php
// Cargoboard – Palette/Fracht. Legt eine Order an und holt das Label (PDF).
//   POST {base}/v1/orders                              (Order anlegen)
//   GET  {base}/v1/orders/{id}/print-shipment-labels   (Label als PDF)
//   Auth: Header X-API-KEY.  base: https://api.cargoboard.com  (Sandbox: https://api-sandbox.cargoboard.com)
// Doku: https://docs.cargoboard.com/reference

function cargoboard_label_erstellen(array $v, array $abs, array $cfg): array {
    $base = !empty($cfg['sandbox']) ? 'https://api-sandbox.cargoboard.com' : 'https://api.cargoboard.com';

    $pakete  = max(1, (int)($v['pakete'] ?? 1));
    $gewicht = (float)($v['gewicht_kg'] ?? 0); if ($gewicht <= 0) $gewicht = 1.0;
    // Maße je Packstück (cm); Standard = Europalette, falls nicht erfasst.
    $l = (float)($v['masse_l'] ?? 0) ?: 120.0;
    $b = (float)($v['masse_b'] ?? 0) ?: 80.0;
    $h = (float)($v['masse_h'] ?? 0) ?: 100.0;

    $order = [
        'product' => 'FIX',
        'shipper' => [
            'name' => $abs['name'],
            'contactPerson' => ['name' => $abs['name'], 'phone' => $abs['telefon'], 'email' => $abs['email']],
            'address' => ['street' => trim($abs['strasse'] . ' ' . $abs['hausnummer']), 'city' => $abs['ort'], 'postCode' => $abs['plz'], 'countryCode' => $abs['land']],
            'pickupOn' => date('Y-m-d', strtotime('+1 weekday')),
            'wantsTailLiftTruck' => false,
        ],
        'consignee' => [
            'name' => trim((string)$v['empf_firma']) ?: trim((string)$v['empf_name']),
            'contactPerson' => ['name' => trim((string)$v['empf_name']) ?: trim((string)$v['empf_firma']), 'phone' => (string)$v['empf_telefon'], 'email' => (string)$v['empf_email']],
            'address' => ['street' => trim((string)$v['empf_strasse'] . ' ' . (string)$v['empf_hausnummer']), 'city' => (string)$v['empf_ort'], 'postCode' => (string)$v['empf_plz'], 'countryCode' => strtoupper(trim((string)$v['empf_land'])) ?: 'DE'],
            'wantsTailLiftTruck' => false,
        ],
        'lines' => [[
            'content' => 'Nahrungsergänzungsmittel',
            'unitQuantity' => $pakete,
            'unitPackageType' => 'PA',
            'unitLength' => $l, 'unitWidth' => $b, 'unitHeight' => $h,
            'unitWeight' => round($gewicht / $pakete, 2),
            'isStackable' => true,
        ]],
        'customerOrderCode' => (string)$v['nummer'],
        'wantsInsurance' => false,
        'incoterm' => 'STANDARD',
    ];

    $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-API-KEY: ' . $cfg['key']];
    $r = versand_http('POST', $base . '/v1/orders', $headers, json_encode($order, JSON_UNESCAPED_UNICODE));
    if ($r['err'] !== '') return ['ok' => false, 'fehler' => 'Cargoboard: Verbindungsfehler (' . $r['err'] . ')'];
    $j = json_decode($r['body'], true);

    if (!($r['status'] >= 200 && $r['status'] < 300 && isset($j['data']['id']))) {
        $msg = $j['message'] ?? $j['error'] ?? $j['detail'] ?? ('HTTP ' . $r['status']);
        return ['ok' => false, 'fehler' => 'Cargoboard: ' . (is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE))];
    }
    $oid = (string)$j['data']['id'];
    $ref = (string)($j['data']['reference'] ?? $oid);

    // Label (PDF) holen.
    $lr = versand_http('GET', $base . '/v1/orders/' . rawurlencode($oid) . '/print-shipment-labels?format=A4',
        ['Accept: application/pdf', 'X-API-KEY: ' . $cfg['key']]);
    $pdf = ($lr['status'] === 200) ? $lr['body'] : '';

    return ['ok' => true, 'tracking' => $ref, 'pdf' => $pdf, 'format' => 'A4'];
}
