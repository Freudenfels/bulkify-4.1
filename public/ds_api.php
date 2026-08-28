<?php
// ds_api.php – Schnittstelle für das Fulfillment-System (fulfillment-web).
// Token im Header X-DS-Token. Kein Login (Front-Controller wird bewusst umgangen).
// Vertrag (siehe fulfillment-web/src/bulkify_dash.php):
//   GET  ?action=lager2          -> {ok:true, products:[{bsku,shopify_inventory_item_id,verfuegbar,...}]}
//   POST ?action=verbrauch_sku   (iid|bsku, menge, ref)          -> Lager 2 abbuchen (idempotent), 404 = kein Artikel
//   POST ?action=retoure_sku     (iid|bsku, menge, ref)          -> Lager 2 wieder hoch
//   POST ?action=retoure_defekt  (iid|bsku, menge, ref, zustand) -> nur dokumentieren
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/schema.php';

header('Content-Type: application/json; charset=utf-8');
function jout(array $arr, int $code = 200): void { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
// Nie HTML/Fatal ausgeben – immer JSON.
set_exception_handler(function ($e) { jout(['ok'=>false, 'message'=>'Serverfehler'], 500); });
init_schema();

// --- Token prüfen (konstantzeit) ---
$hdr = (string)($_SERVER['HTTP_X_DS_TOKEN'] ?? '');
if ($hdr === '' || !hash_equals(ds_api_token(), $hdr)) jout(['ok'=>false, 'message'=>'unauthorized'], 401);

$action = (string)($_GET['action'] ?? '');

// --- Bestands-Feed ---
if ($action === 'lager2') {
    $products = [];
    foreach (lager2_produkte() as $r) $products[] = [
        'bsku'                      => $r['bsku'],
        'shopify_inventory_item_id' => $r['shopify_inventory_item_id'],
        'verfuegbar'                => (float)$r['bestand'],
        'name'                      => $r['anzeigename'],
        'kunde'                     => $r['kunde'],
        'produkt_nr'                => $r['produkt_nr'],
    ];
    jout(['ok'=>true, 'products'=>$products]);
}

// --- Buchungs-Aktionen (POST) ---
if (in_array($action, ['verbrauch_sku', 'retoure_sku', 'retoure_defekt'], true)) {
    $iid   = (string)($_POST['iid'] ?? '');
    $bsku  = (string)($_POST['bsku'] ?? '');
    $menge = (float)str_replace(',', '.', (string)($_POST['menge'] ?? '0'));
    $ref   = trim((string)($_POST['ref'] ?? ''));
    if ($ref === '')            jout(['ok'=>false, 'message'=>'ref fehlt'], 400);
    if ($iid === '' && $bsku === '') jout(['ok'=>false, 'message'=>'iid oder bsku nötig'], 400);
    $item = lager2_find_item($iid, $bsku);
    if (!$item) jout(['ok'=>false, 'message'=>'kein Lager-2-Artikel (iid/bsku unbekannt)'], 404);

    if ($action === 'verbrauch_sku')  jout(lager2_verbrauch($item, $menge, $ref));
    if ($action === 'retoure_sku')    jout(lager2_retoure($item, $menge, $ref));
    if ($action === 'retoure_defekt') jout(lager2_defekt($item, $menge, $ref, (string)($_POST['zustand'] ?? 'defekt')));
}

jout(['ok'=>false, 'message'=>'unbekannte Aktion'], 400);
