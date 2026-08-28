<?php
// Spec-PDF eines Rohstoffs ausliefern (aus data/uploads, außerhalb public). Zugriff ist über die Route geschützt.
require_once BX_ROOT . '/core/schema.php';

$id = (int)($_GET['id'] ?? 0);
$fn = $id ? scalar("SELECT spec_pdf FROM item WHERE id=?", [$id]) : null;
if (!$fn) { http_response_code(404); echo 'Kein Spec-PDF hinterlegt.'; exit; }
$path = BX_UPLOADS . '/' . basename((string)$fn);
if (!is_file($path)) { http_response_code(404); echo 'Datei nicht gefunden.'; exit; }

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename((string)$fn) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
