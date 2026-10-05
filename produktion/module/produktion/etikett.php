<?php
// Liefert das hochgeladene Kunden-Etikett (Dokument am Auftrag) inline aus – für die Vorschau im
// Produktionsauftrag. Zugriff nur für angemeldete Produktions-Mitarbeiter (der Front Controller gated).
// Datei liegt in den Dashboard-Uploads (BX_UPLOADS). Dokument-Lookup über die Naht erp.php.
$id = (int)($_GET['id'] ?? 0);
$d  = erp_etikett_datei($id);
if (!$d) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Kein Etikett vorhanden.'; exit; }
$path = BX_UPLOADS . '/' . basename((string)$d['datei']);
if (!is_file($path)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Datei nicht gefunden.'; exit; }

$mime = 'application/octet-stream';
if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($fi, $path) ?: $mime; finfo_close($fi); }
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . rawurlencode((string)($d['datei_orig'] ?: basename((string)$d['datei']))) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=60');
readfile($path);
exit;
