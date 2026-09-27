<?php
// Ein Dokument (Lieferschein, CoA ...) aus der Dashboard-Ablage ausliefern. Nur fuer Angemeldete.
$id = (int)($_GET['id'] ?? 0);
$d = erp_dokument($id);
if (!$d || !$d['datei']) { http_response_code(404); echo 'Dokument nicht gefunden.'; exit; }

// Nur Dateien aus dem Upload-Ordner, kein Ausbrechen ueber ../ .
$pfad = BX_UPLOADS . '/' . basename((string)$d['datei']);
if (!is_file($pfad)) { http_response_code(404); echo 'Datei fehlt.'; exit; }

$typ = match (strtolower(pathinfo($pfad, PATHINFO_EXTENSION))) {
    'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'webp' => 'image/webp', default => 'application/octet-stream',
};
header('Content-Type: ' . $typ);
header('Content-Disposition: inline; filename="' . rawurlencode((string)($d['datei_orig'] ?: $d['datei'])) . '"');
header('Content-Length: ' . filesize($pfad));
header('Cache-Control: private, max-age=60');
readfile($pfad);
exit;
