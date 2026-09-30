<?php
// Gutschrift/Storno-PDF fuers Team: ?p=gutschrift_pdf&id=<ID>
require_once BX_ROOT . '/core/pdf_gutschrift.php';

$id = (int)($_GET['id'] ?? 0);
$b  = $id ? one("SELECT id, nummer FROM beleg WHERE id=? AND typ='gutschrift'", [$id]) : null;
if (!$b) { http_response_code(404); echo 'Gutschrift nicht gefunden.'; exit; }

if (!gutschrift_pdf_ausliefern($id, (string)$b['nummer'])) {
    http_response_code(409);
    echo 'Für diese Gutschrift gibt es kein PDF.';
}
exit;
