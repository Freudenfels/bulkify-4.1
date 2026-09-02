<?php
// Aufruf ohne Zuschauer: entwickelt im Hintergrund, was zu lange dauert (siehe core/ki_job.php).
// Kein Login – dafuer der Schluessel aus Art + ID + API-Schluessel. Antwortet immer nur "ok".
require_once BX_ROOT . '/core/ki_job.php';

$art = preg_replace('/[^a-z_]/', '', (string)($_GET['art'] ?? ''));
$id  = (int)($_GET['id'] ?? 0);
$s   = (string)($_GET['s'] ?? '');

if ($art === '' || $id <= 0 || !hash_equals(ki_job_schluessel($art, $id), $s)) {
    http_response_code(403); echo 'nein'; exit;
}

// Die Verbindung ist gleich zu – wir arbeiten trotzdem weiter.
ignore_user_abort(true);
echo 'ok';
ki_antwort_abschliessen();
ki_job_ausfuehren($art, $id);
exit;
