<?php
// Karton-Etikett (Wareneingang) als PDF. Erzeugung zentral in lager/core/etikett_pdf.php
// (gleiche Etiketten für Anzeige UND Druck-Brücke).
//
// Einzeln:  ?p=etikett&id=<charge_id>
// Stapel:   ?p=etikett&ids=1,2,3
// Format:   nur noch 100x150 hoch (100x70 wurde entfernt)
// Override: &pakete=<n>
require_once __DIR__ . '/../../core/etikett_pdf.php';

$ids = [];
if (isset($_GET['ids'])) {
    foreach (explode(',', (string)$_GET['ids']) as $x) { $x = (int)trim($x); if ($x > 0) $ids[] = $x; }
} elseif (isset($_GET['id'])) {
    $id = (int)$_GET['id']; if ($id > 0) $ids[] = $id;
}
if (!$ids) { http_response_code(404); echo 'Keine Charge angegeben.'; exit; }

$format = 'gross';   // 100x70 entfernt - nur noch 100x150 (hoch)
$override = isset($_GET['pakete']) ? max(1, (int)$_GET['pakete']) : 0;

$out = lg_etikett_pdf($ids, $format, $override);
if ($out === null) { http_response_code(404); echo 'Charge nicht gefunden.'; exit; }

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="karton-etikett.pdf"');
header('Content-Length: ' . strlen($out));
header('Cache-Control: no-store');
echo $out;
exit;
