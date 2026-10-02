<?php
// Rechnungs-PDF fürs Team: ?p=rechnung_pdf&id=<ID>
require_once BX_ROOT . '/core/pdf_rechnung.php';

$id = (int)($_GET['id'] ?? 0);
$b  = $id ? one("SELECT id, nummer FROM beleg WHERE id=? AND typ='rechnung'", [$id]) : null;
if (!$b) { http_response_code(404); echo 'Rechnung nicht gefunden.'; exit; }

if (!rechnung_pdf_ausliefern($id, (string)$b['nummer'])) {
    http_response_code(409);
    echo 'Für diese Rechnung gibt es kein PDF (keine Positionen).';
}
exit;
