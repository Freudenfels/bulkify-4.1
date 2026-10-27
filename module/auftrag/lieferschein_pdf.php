<?php
// Lieferschein-PDF für das Team: ?p=lieferschein_pdf&id=<BELEG-ID>
// Der Lieferschein-Beleg (typ=lieferschein, LS-) entsteht beim Versand automatisch; hier wird er druckbar.
require_once BX_ROOT . '/core/pdf_lieferschein.php';

$id = (int)($_GET['id'] ?? 0);
$b  = $id ? one("SELECT id, nummer FROM beleg WHERE id=? AND typ='lieferschein'", [$id]) : null;
if (!$b) { http_response_code(404); echo 'Lieferschein nicht gefunden.'; exit; }

if (!lieferschein_pdf_ausliefern($id, (string)$b['nummer'])) {
    http_response_code(409);
    echo 'Für diesen Lieferschein gibt es noch kein PDF: Es ist kein Kunde hinterlegt.';
}
exit;
