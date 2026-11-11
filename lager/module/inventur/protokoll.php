<?php
// Inventurprotokoll herunterladen (nur Admin, 11.11.2026): abgelegte Datei der abgeschlossenen Inventur; fehlt sie (oder läuft
// die Inventur noch), wird der aktuelle Stand frisch erzeugt (Vorschau).
$inv = inv_get((int)($_GET['id'] ?? 0));
if (!$inv) { http_response_code(404); exit('Inventur nicht gefunden.'); }
$f = (string)($_GET['f'] ?? 'pdf') === 'csv' ? 'csv' : 'pdf';
$datei = (string)($inv['protokoll_' . $f] ?? '');
$pfad = $datei !== '' ? BX_UPLOADS . '/' . basename($datei) : '';
$inhalt = $pfad !== '' && is_file($pfad) ? (string)file_get_contents($pfad) : ($f === 'csv' ? inv_protokoll_csv($inv) : inv_protokoll_pdf($inv));
$name = 'Inventurprotokoll-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$inv['name']) . '-' . (string)$inv['stichtag'] . '.' . $f;
header('Content-Type: ' . ($f === 'csv' ? 'text/csv; charset=utf-8' : 'application/pdf'));
header('Content-Disposition: ' . ($f === 'csv' ? 'attachment' : 'inline') . '; filename="' . $name . '"');
echo $inhalt;
exit;
