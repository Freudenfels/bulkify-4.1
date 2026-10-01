<?php
// Auftragsbestätigungs-PDF für das Team: ?p=auftrag_pdf&id=<ID>
// Alle Infos zum Auftrag (Kunde, Produkt, Menge, Verpackung, Preis, Charge/MHD) als Beleg-PDF.
require_once BX_ROOT . '/core/pdf_auftrag.php';

$id = (int)($_GET['id'] ?? 0);
$a  = $id ? one("SELECT id, nummer FROM auftrag WHERE id=?", [$id]) : null;
if (!$a) { http_response_code(404); echo 'Auftrag nicht gefunden.'; exit; }

if (!auftrag_pdf_ausliefern($id, (string)$a['nummer'])) {
    http_response_code(409);
    echo 'Für diesen Auftrag gibt es noch kein PDF: Es ist kein Kunde hinterlegt.';
}
exit;
