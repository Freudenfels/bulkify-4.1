<?php
// Lieferschein einer Sendung als PDF. Erzeugung zentral in lager/core/lieferschein_pdf.php.
//   ?p=lieferschein&id=<versand_id>
require_once __DIR__ . '/../../core/lieferschein_pdf.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); echo 'Keine Sendung angegeben.'; exit; }

$out = lg_lieferschein_pdf($id);
if ($out === null) { http_response_code(404); echo 'Sendung nicht gefunden.'; exit; }

$v = function_exists('lg_versand') ? lg_versand($id) : null;
$name = 'lieferschein-' . ($v['nummer'] ?? $id) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $name . '"');
header('Content-Length: ' . strlen($out));
header('Cache-Control: no-store');
echo $out;
exit;
