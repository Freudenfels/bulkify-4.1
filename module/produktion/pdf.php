<?php
// Produktionsauftrag / Laufzettel als PDF: ?p=produktionsauftrag_pdf&id=<ID>
require_once BX_ROOT . '/core/pdf_produktionsauftrag.php';

$id = (int)($_GET['id'] ?? 0);
$pa = $id ? one("SELECT id, nummer FROM produktionsauftrag WHERE id=?", [$id]) : null;
if (!$pa) { http_response_code(404); echo 'Produktionsauftrag nicht gefunden.'; exit; }

produktionsauftrag_pdf_ausliefern($id, (string)$pa['nummer']);
exit;
