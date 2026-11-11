<?php
// Chargenprotokoll einer Unter-Charge als PDF (Nico 11.11.2026, Teil 7). Abgelegte Fassung (beim Schließen der Unter-Charge),
// sonst der aktuelle Stand frisch erzeugt (laufende Unter-Charge).
require_once BX_ROOT . '/core/schema.php';
$uc = pa_unter_charge((int)($_GET['uc'] ?? 0));
if (!$uc) { http_response_code(404); exit('Unter-Charge nicht gefunden.'); }
$datei = $uc['protokoll_dokument_id'] ? (string) scalar("SELECT datei FROM dokument WHERE id=?", [(int)$uc['protokoll_dokument_id']]) : '';
$pfad = $datei !== '' ? pa_uploads() . '/' . basename($datei) : '';
$pdf = ($pfad !== '' && is_file($pfad) && $uc['status'] === 'fertig') ? (string) file_get_contents($pfad) : (string) pa_protokoll_pdf((int)$uc['id']);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Chargenprotokoll-' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$uc['charge_nr']) . '.pdf"');
echo $pdf;
exit;
