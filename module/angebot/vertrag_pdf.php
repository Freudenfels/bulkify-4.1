<?php
// Jahresabnahmevertrag als PDF: ?p=vertrag_pdf&id=<angebot_id>
// Intern (Team) und – über dieselbe Funktion – im Kundenportal genutzt.
require_once BX_ROOT . '/core/pdf_vertrag.php';

$id = (int)($_GET['id'] ?? 0);
$a  = $id ? one("SELECT nummer, jahresvertrag FROM angebot WHERE id=?", [$id]) : null;
if (!$a) { http_response_code(404); echo 'Angebot nicht gefunden.'; exit; }
if ((int)($a['jahresvertrag'] ?? 0) !== 1) { http_response_code(409); echo 'Dieses Angebot ist kein Jahresvertrag.'; exit; }

$pdf = build_jahresvertrag_pdf($id);
if ($pdf === null) { http_response_code(409); echo 'Vertrag kann nicht erzeugt werden – Kunde/Produkt fehlt.'; exit; }
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Jahresvertrag_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$a['nummer']) . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
