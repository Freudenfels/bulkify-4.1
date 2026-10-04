<?php
// Liefert die gespeicherte Beleg-Datei aus (nur nach Login). Route: beleg_datei. ?id=<bu_beleg_eingang>
require_once BX_ROOT . '/core/belegeingang.php';
be_init();

$id = (int)($_GET['id'] ?? 0);
$r = $id ? be_get($id) : null;
if (!$r || empty($r['datei'])) { http_response_code(404); echo 'Beleg-Datei nicht gefunden.'; exit; }
$pfad = be_pfad($r['datei']);
if (!is_file($pfad)) { http_response_code(404); echo 'Datei fehlt auf dem Server.'; exit; }

$ext = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
$typen = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
          'webp'=>'image/webp','gif'=>'image/gif','heic'=>'image/heic'];
$ct = $typen[$ext] ?? 'application/octet-stream';
$name = $r['orig_name'] ?: basename($pfad);

header('Content-Type: ' . $ct);
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$name) . '"');
header('Content-Length: ' . filesize($pfad));
header('X-Content-Type-Options: nosniff');
readfile($pfad);
exit;
