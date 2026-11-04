<?php
// Lieferschein einer Sendung als PDF. Erzeugung zentral in lager/core/lieferschein_pdf.php.
//   ?p=lieferschein&id=<versand_id>
require_once __DIR__ . '/../../core/lieferschein_pdf.php';
require_once __DIR__ . '/../../core/etikett_pdf.php';   // Barcode für die Paletten-Etiketten (Soll-Kette 7b)

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); echo 'Keine Sendung angegeben.'; exit; }

$out = ($_GET['art'] ?? '') === 'palette' ? lg_paletten_etikett_pdf($id) : lg_lieferschein_pdf($id);   // ?art=palette: Paletten-Etiketten (Soll-Kette 7b)
if ($out === null) { http_response_code(404); echo 'Sendung nicht gefunden.'; exit; }

$v = function_exists('lg_versand') ? lg_versand($id) : null;
$name = 'lieferschein-' . ($v['nummer'] ?? $id) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $name . '"');
header('Content-Length: ' . strlen($out));
header('Cache-Control: no-store');
echo $out;
exit;
