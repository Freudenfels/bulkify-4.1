<?php
// Vom Carrier erzeugtes Versand-Label (PDF) einer Sendung ausgeben.
//   ?p=versand_label&id=<versand_id>
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); echo 'Keine Sendung angegeben.'; exit; }

$zoll = ($_GET['zoll'] ?? '') === '1';
$l = function_exists('lg_versand_label') ? lg_versand_label($id) : null;
$pdf = $l ? (string)($zoll ? ($l['zoll_pdf'] ?? '') : ($l['pdf'] ?? '')) : '';
if ($pdf === '') { http_response_code(404); echo $zoll ? 'Kein Zollpapier vorhanden.' : 'Kein Versand-Label vorhanden.'; exit; }

$v = function_exists('lg_versand') ? lg_versand($id) : null;
$name = ($zoll ? 'zollpapier-' : 'versandlabel-') . ($v['nummer'] ?? $id) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $name . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: no-store');
echo $pdf;
exit;
