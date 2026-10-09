<?php
// Gebinde-/Karton-Aufkleber einer Charge als PDF (Spec 5.6). Je Gebinde ein Aufkleber mit eigenem
// QR-Code + eigener Nummer. Erzeugung zentral in lager/core/etikett_pdf.php.
//
//   ?p=gebinde_etikett&charge=<id>   (Format: 100x150 hoch)
require_once __DIR__ . '/../../core/etikett_pdf.php';

$cid = (int)($_GET['charge'] ?? 0);
if ($cid <= 0) { http_response_code(404); echo 'Keine Charge angegeben.'; exit; }

$format = ($_GET['format'] ?? 'gross') === 'klein' ? 'klein' : 'gross';
$out = lg_gebinde_etikett_pdf($cid, $format);
if ($out === null) { http_response_code(404); echo 'Keine Gebinde-Aufkleber für diese Charge.'; exit; }

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="gebinde-etiketten.pdf"');
header('Content-Length: ' . strlen($out));
header('Cache-Control: no-store');
echo $out;
exit;
