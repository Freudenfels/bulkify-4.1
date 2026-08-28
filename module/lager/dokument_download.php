<?php
// Generisches Dokument (CoA/Spec/Analyse) ausliefern – aus data/uploads, Zugriff über die Route geschützt.
require_once BX_ROOT . '/core/schema.php';

$id = (int)($_GET['id'] ?? 0);
$d  = $id ? one("SELECT datei, datei_orig FROM dokument WHERE id=?", [$id]) : null;
if (!$d) { http_response_code(404); echo 'Dokument nicht gefunden.'; exit; }
$path = BX_UPLOADS . '/' . basename((string)$d['datei']);
if (!is_file($path)) { http_response_code(404); echo 'Datei nicht vorhanden.'; exit; }

$mime = 'application/octet-stream';
if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($fi, $path) ?: $mime; finfo_close($fi); }
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . rawurlencode($d['datei_orig'] ?: basename((string)$d['datei'])) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
