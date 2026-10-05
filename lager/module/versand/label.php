<?php
// Vom Carrier erzeugtes Versand-Label (PDF) einer Sendung ausgeben.
//   ?p=versand_label&id=<versand_id>
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); echo 'Keine Sendung angegeben.'; exit; }

$l = function_exists('lg_versand_label') ? lg_versand_label($id) : null;
if (!$l || (string)($l['pdf'] ?? '') === '') { http_response_code(404); echo 'Kein Versand-Label vorhanden.'; exit; }

$v = function_exists('lg_versand') ? lg_versand($id) : null;
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="versandlabel-' . ($v['nummer'] ?? $id) . '.pdf"');
header('Content-Length: ' . strlen((string)$l['pdf']));
header('Cache-Control: no-store');
echo $l['pdf'];
exit;
