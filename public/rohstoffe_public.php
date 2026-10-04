<?php
// rohstoffe_public.php – ÖFFENTLICHE Rohstoff-Datenbank für die Website (bulkify.pro-SEO).
// Kein Login. Liefert NUR freigegebene Rohstoffe (item.website_sichtbar=1) und NUR sichere Felder
// (siehe core/rohstoff_public.php). Vertrag: WEBSEITEBULKIFY/bulkifyv2/ROHSTOFF-DATENBANK.md
//   GET  /rohstoffe_public.php            -> { stand, anzahl, rohstoffe:[ {slug,name,...,kennwerte,wirkstoffe} ] }
//   GET  /rohstoffe_public.php?slug=<slug> -> dasselbe mit nur diesem einen Rohstoff
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/schema.php';
require_once __DIR__ . '/../core/rohstoff_public.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');          // reine Lese-Daten, öffentlich
header('Cache-Control: public, max-age=3600');     // 1 h cachebar
function rp_out(array $a, int $code = 200): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
set_exception_handler(function ($e) {
    error_log('rohstoffe_public: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    rp_out(['ok' => false, 'message' => 'Serverfehler'], 500);
});

// Schema sicherstellen (wie der Front-Controller) – sonst fehlen hier ggf. neue Spalten. Durch den
// Schnell-Pfad in init_schema() ist das nach dem ersten Lauf pro Deploy nur eine meta_get-Abfrage.
init_schema();

$slug = isset($_GET['slug']) ? preg_replace('/[^a-z0-9-]/', '', (string)$_GET['slug']) : null;
$liste = rohstoff_public_liste($slug !== '' ? $slug : null);

rp_out([
    'ok'         => true,
    'stand'      => gmdate('c'),
    'anzahl'     => count($liste),
    'rohstoffe'  => $liste,
]);
