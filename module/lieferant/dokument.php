<?php
// Datei aus der Ablage an den angemeldeten Lieferanten ausliefern – nur eigene.
// Route: ?p=lieferant_dokument&id=<dokument.id>
// Die Dateien liegen außerhalb von public, deshalb geht das nur über diese Route.
require_once BX_ROOT . '/core/lieferant_dateien.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$d = lieferant_darf_datei(aktueller_lieferant_id(), (int)($_GET['id'] ?? 0));
$pfad = $d ? BX_UPLOADS . '/' . basename((string)$d['datei']) : '';
if ($pfad === '' || !is_file($pfad)) { http_response_code(404); echo 'Datei nicht gefunden.'; exit; }

$mime = function_exists('mime_content_type') ? (mime_content_type($pfad) ?: 'application/octet-stream') : 'application/octet-stream';
$name = preg_replace('/[^\w.\-]+/u', '_', (string)($d['datei_orig'] ?: basename($pfad)));
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($pfad));
header('Content-Disposition: inline; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Cache-Control: private, max-age=0');
readfile($pfad);
exit;
