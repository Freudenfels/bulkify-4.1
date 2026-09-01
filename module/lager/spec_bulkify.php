<?php
// Spezifikation bzw. CoA im bulkify-Layout ausliefern.
//   ?p=spec_bulkify&id=<item_id>      -> Spezifikation des Rohstoffs
//   ?p=coa_bulkify&id=<charge_id>     -> Analysenzertifikat der Charge
// Bewusst UNSER Dokument: die Unterlagen der Vorlieferanten gehen nicht an den Kunden.
require_once BX_ROOT . '/core/pdf_spec.php';

$id  = (int)($_GET['id'] ?? 0);
$coa = ($_GET['p'] ?? '') === 'coa_bulkify';

if ($coa) {
    $c = $id ? one("SELECT c.charge_nr, i.name FROM charge c JOIN item i ON i.id=c.item_id WHERE c.id=?", [$id]) : null;
    if (!$c) { http_response_code(404); echo 'Charge nicht gefunden.'; exit; }
    $pdf = build_coa_pdf($id);
    $name = 'CoA_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)($c['charge_nr'] ?: $id));
} else {
    $it = $id ? one("SELECT name, artikelnummer FROM item WHERE id=?", [$id]) : null;
    if (!$it) { http_response_code(404); echo 'Rohstoff nicht gefunden.'; exit; }
    $pdf = build_spec_pdf($id);
    $name = 'Spezifikation_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)($it['artikelnummer'] ?: $id));
}
if ($pdf === null) { http_response_code(404); echo 'Kein Dokument.'; exit; }
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $name . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
