<?php
// Bestell-PDF fuer das Team: ?p=bestellung_pdf&id=<ID>
// Der Beleg, den der Lieferant per Mail bekommt – gleiche Vorlage wie Angebot und Rechnung.
require_once BX_ROOT . '/core/pdf_bestellung.php';

$id = (int)($_GET['id'] ?? 0);
$b  = $id ? one("SELECT id, nummer FROM bestellung WHERE id=?", [$id]) : null;
if (!$b) { http_response_code(404); echo 'Bestellung nicht gefunden.'; exit; }

if (!bestellung_pdf_ausliefern($id, (string)$b['nummer'])) {
    http_response_code(409);
    echo 'Fuer diese Bestellung gibt es noch kein PDF: Es ist kein Lieferant hinterlegt.';
}
exit;
