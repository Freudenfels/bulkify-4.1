<?php
// Dokument-Download für das KUNDENPORTAL. Öffentliche Route – deshalb hier drei Prüfungen:
//   1. gültiges Portal-Token (echter Kunde),
//   2. Dokument ausdrücklich freigegeben (dokument.kunde_sichtbar=1),
//   3. Kunde hat den passenden Portal-Bereich freigeschaltet.
// Ohne alle drei gibt es die Datei nicht. Interne Unterlagen (Lieferanten-Specs) bleiben so draußen.
require_once BX_ROOT . '/core/schema.php';

$token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
$k = $token ? one("SELECT * FROM kunden WHERE portal_token=?", [$token]) : null;
if (!$k) { http_response_code(403); echo 'Zugang ungültig.'; exit; }

$id = (int)($_GET['id'] ?? 0);
$d  = $id ? one("SELECT * FROM dokument WHERE id=? AND kunde_sichtbar=1", [$id]) : null;
if (!$d) { http_response_code(404); echo 'Dokument nicht verfügbar.'; exit; }

// Bereichs-Freischaltung des Kunden prüfen: Rohstoff-Dokumente brauchen den Rohstoff- oder Rezeptur-Bereich,
// Produkt-Dokumente den Produkt-Bereich.
$erlaubt = $d['objekt_typ'] === 'item'
    ? (!empty($k['portal_rohstoffe']) || !empty($k['portal_rezeptur']) || !empty($k['portal_produkte']))
    : !empty($k['portal_produkte']);
if (!$erlaubt) { http_response_code(403); echo 'Kein Zugriff.'; exit; }

$path = BX_UPLOADS . '/' . basename((string)$d['datei']);
if (!is_file($path)) { http_response_code(404); echo 'Datei nicht vorhanden.'; exit; }

$mime = 'application/octet-stream';
if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($fi, $path) ?: $mime; finfo_close($fi); }
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . rawurlencode($d['datei_orig'] ?: basename((string)$d['datei'])) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
