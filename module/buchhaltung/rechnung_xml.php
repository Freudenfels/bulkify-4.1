<?php
// E-Rechnung-Download: CII/EN16931-XML zu einem Beleg. Route: rechnung_xml (Rolle finance). ?id=<beleg>
require_once BX_ROOT . '/core/erechnung.php';

$id = (int)($_GET['id'] ?? 0);
$b  = $id ? one("SELECT nummer, typ FROM beleg WHERE id=? AND typ IN ('rechnung','gutschrift')", [$id]) : null;
if (!$b) { http_response_code(404); echo 'Beleg nicht gefunden.'; exit; }

$xml = erechnung_cii_xml($id);
if ($xml === '') { http_response_code(500); echo 'XML konnte nicht erzeugt werden.'; exit; }

$name = 'erechnung_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$b['nummer']) . '.xml';
header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($xml));
header('X-Content-Type-Options: nosniff');
echo $xml;
exit;
