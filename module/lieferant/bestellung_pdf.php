<?php
// Bestell-PDF fuer den Lieferanten: ?p=lieferant_bestellung_pdf&id=<ID>
// Gleicher Beleg wie intern – aber nur fuer die eigenen Bestellungen.
require_once BX_ROOT . '/core/pdf_bestellung.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$id = (int)($_GET['id'] ?? 0);
$b  = $id ? one("SELECT id, nummer FROM bestellung WHERE id=? AND lieferant_id=?", [$id, aktueller_lieferant_id()]) : null;
if (!$b) { http_response_code(404); echo 'Not found.'; exit; }
bestellung_pdf_ausliefern($id, (string)$b['nummer']);
exit;
