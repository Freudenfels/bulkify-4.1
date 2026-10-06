<?php
// Bild aus dem Upload-Ordner ausgeben (z. B. Etikett-Bilder der Lager-2-Artikel). Nur Angemeldete
// (Login-Gate in index.php), nur Bilddateien, nur basename (kein ../).
$f = basename((string)($_GET['f'] ?? ''));
if ($f === '') { http_response_code(404); echo 'Kein Bild angegeben.'; exit; }
$ext  = strtolower(pathinfo($f, PATHINFO_EXTENSION));
$mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'][$ext] ?? '';
$pfad = BX_UPLOADS . '/' . $f;
if ($mime === '' || !is_file($pfad)) { http_response_code(404); echo 'Bild nicht gefunden.'; exit; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($pfad));
header('Cache-Control: private, max-age=86400');
readfile($pfad);
exit;
