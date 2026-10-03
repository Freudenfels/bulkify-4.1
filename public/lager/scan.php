<?php
// lager/scan.php – read-only Scan-Endpunkt für Smartglass / Handscanner.
//
// Die Brille scannt den Karton-Etikett-QR (Inhalt: .../lager/?p=charge&id=<id>),
// liest die Charge-ID und ruft hier auf. Antwort ist JSON zur Charge:
//   Produkt, Menge (verfügbar), Einheit, MHD, Charge-Nr, Status, Lieferant, Ort
//   (Kiste/Fach oder direkter Blinker). orders[] ist für Stufe 2 reserviert.
//
// NUR LESEN – verändert nichts. Kein Dashboard-Login (eigener Lager-Bootstrap).
// Auth: token als GET/POST-Param `token` ODER Header X-Scan-Token.
//       Der Wert steht im Dashboard unter Einstellungen → Reiter „Lager-Scan".
require_once __DIR__ . '/../../lager/core/config.php';
require_once __DIR__ . '/../../lager/core/db.php';
require_once __DIR__ . '/../../lager/core/schema.php';
require_once __DIR__ . '/../../lager/core/erp.php';
require_once __DIR__ . '/../../lager/core/kiste.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');                 // Brille lädt aus file:// -> freie CORS
header('Access-Control-Allow-Headers: X-Scan-Token, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

function sout(array $a, int $code = 200): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
set_exception_handler(function ($e) { sout(['ok' => false, 'error' => 'Serverfehler'], 500); });

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

lg_schema();   // stellt die lg_-Tabellen sicher (für den Ort/Blinker)

// --- Token prüfen (konstantzeit) ---
$soll  = erp_scan_token();
$given = (string) ($_REQUEST['token'] ?? ($_SERVER['HTTP_X_SCAN_TOKEN'] ?? ''));
if ($soll === '' || $given === '' || !hash_equals($soll, $given)) sout(['ok' => false, 'error' => 'unauthorized'], 401);

// --- Charge-ID aus ?id= ODER aus gescanntem Text/URL ?code= ---
$id = (int) ($_REQUEST['id'] ?? 0);
if ($id <= 0) {
    $code = (string) ($_REQUEST['code'] ?? '');
    if     ($code !== '' && preg_match('/[?&]id=(\d+)/', $code, $m)) $id = (int) $m[1];
    elseif ($code !== '' && preg_match('/(\d+)/',       $code, $m)) $id = (int) $m[1];
}
if ($id <= 0) sout(['ok' => false, 'error' => 'Keine Charge-ID erkannt'], 400);

// --- Charge laden ---
$c = erp_charge_voll($id);
if (!$c) sout(['ok' => false, 'found' => false, 'error' => 'Charge nicht gefunden', 'id' => $id], 404);

// --- Ort (Kiste/Fach oder direkter Blinker) ---
$ort = '';
try {
    $b   = blinker_fuer_charge($id);
    $ort = (string) ($b['ort'] ?? '');
    if ($ort === '' && !empty($b['leiste']['code'])) $ort = 'Blinker ' . $b['leiste']['code'];
} catch (\Throwable $e) {}

sout([
    'ok'        => true,
    'found'     => true,
    'id'        => (int) $c['id'],
    'produkt'   => (string) ($c['item_name'] ?? ''),
    'charge'    => (string) ($c['charge_nr'] ?? ''),
    'menge'     => (float)  ($c['menge_verfuegbar'] ?? $c['menge'] ?? 0),
    'einheit'   => (string) ($c['einheit'] ?? ''),
    'mhd'       => (string) ($c['mhd'] ?? ''),
    'status'    => (string) ($c['status'] ?? ''),
    'kategorie' => (string) ($c['kategorie'] ?? ''),
    'lieferant' => (string) ($c['lieferant'] ?? ''),
    'ort'       => $ort,
    'orders'    => [],   // Stufe 2: offene Aufträge, die diesen Rohstoff brauchen
]);
