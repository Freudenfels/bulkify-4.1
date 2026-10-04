<?php
// Download eines Kontakt-Dokuments. Nur fuer angemeldete Mitarbeiter. Liefert die Datei aus
// data/kontakt_datei aus (liegt ausserhalb des Webroots und ist sonst nicht erreichbar).
require_once __DIR__ . '/../../crm/core/config.php';
require_once __DIR__ . '/../../crm/core/db.php';
require_once __DIR__ . '/../../crm/core/schema.php';
require_once __DIR__ . '/../../crm/core/auth.php';
require_once __DIR__ . '/../../crm/core/kontakt.php';

crm_session_start();
if (!crm_angemeldet()) { http_response_code(403); exit('Nicht angemeldet.'); }

crm_schema();
$id = (int)($_GET['id'] ?? 0);
$d  = $id > 0 ? kontakt_datei($id) : null;
if (!$d) { http_response_code(404); exit('Nicht gefunden.'); }

$pfad = kontakt_datei_dir() . '/' . $d['stored'];
if (!is_file($pfad)) { http_response_code(404); exit('Datei fehlt.'); }

// Inline anzeigen, wenn der Browser es kann (PDF/Bild), sonst Download.
$ext = strtolower(pathinfo((string)$d['original'], PATHINFO_EXTENSION));
$typ = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'txt' => 'text/plain; charset=utf-8'][$ext] ?? 'application/octet-stream';
$inline = in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt'], true);

header('Content-Type: ' . $typ);
header('Content-Length: ' . filesize($pfad));
$dispo = $inline ? 'inline' : 'attachment';
header('Content-Disposition: ' . $dispo . '; filename="' . rawurlencode((string)$d['original']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($pfad);
