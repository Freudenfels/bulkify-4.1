<?php
// Produktinformationsblatt (PIB) direkt aus einer Rezeptur ansehen (ohne Produkt – Komposition/Deklaration/Kapsel).
// Interner Aufruf (Rechteprüfung im Front-Controller). Gibt das PDF direkt aus.
require_once BX_ROOT . '/core/pdf_pib.php';
$rid  = (int)($_GET['id'] ?? 0);
$name = $rid ? (string) scalar("SELECT COALESCE(NULLIF(name,''),'Rezeptur') FROM rezeptur WHERE id=?", [$rid]) : '';
$pdf  = $rid ? pib_pdf_bauen_rezeptur($rid) : null;
if ($pdf === null) { http_response_code(404); echo 'Produktinformationsblatt nicht verfügbar.'; exit; }
$safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', 'Produktinfo-' . ($name !== '' ? $name : (string)$rid)) ?: 'Produktinformationsblatt';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $safe . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
