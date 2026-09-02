<?php
// Logo eines Lieferanten ausliefern. Zwei Wege:
//   ?p=lieferant_logo            – der angemeldete Lieferant sieht sein eigenes
//   ?p=lieferant_logo&id=<ID>    – das Team sieht das eines Lieferanten
// Die Datei liegt ausserhalb von public, deshalb geht das nur ueber diese Route.
$lid = 0;
if (function_exists('ist_lieferant') && ist_lieferant()) {
    $lid = aktueller_lieferant_id();
} elseif (is_logged_in()) {
    $lid = (int)($_GET['id'] ?? 0);
}
$fn = $lid ? (string) scalar("SELECT logo FROM lieferanten WHERE id=?", [$lid]) : '';
$pfad = $fn !== '' ? BX_UPLOADS . '/' . basename($fn) : '';
if ($pfad === '' || !is_file($pfad)) { http_response_code(404); echo 'Kein Logo.'; exit; }
$typ = ['png'=>'image/png', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'webp'=>'image/webp'];
$ext = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
header('Content-Type: ' . ($typ[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($pfad));
header('Cache-Control: private, max-age=300');
readfile($pfad);
exit;
