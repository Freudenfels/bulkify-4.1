<?php
// Etiketten-Druckauftrag in die Warteschlange legen. Die kombinierte Brücke auf dem Lager-PC holt
// ihn ab und druckt lautlos (SumatraPDF). Aufruf per fetch von den Etikett-Panels (z. B. PDA).
header('Content-Type: application/json; charset=utf-8');

$roh = (string)($_POST['ids'] ?? '');
$ids = array_values(array_filter(array_map('intval', explode(',', $roh)), fn($x) => $x > 0));
if (!$ids) { echo json_encode(['ok' => false, 'fehler' => 'Keine Charge angegeben.']); exit; }
$format = ($_POST['format'] ?? '') === 'gross' ? 'gross' : 'klein';

$uid = (int)(lg_benutzer()['id'] ?? 0);
q("INSERT INTO lg_druckjob (ids, format, status, benutzer_id, angelegt) VALUES (?,?,'offen',?,?)",
  [implode(',', $ids), $format, $uid ?: null, jetzt_utc()]);
$jobId = (int) insert_id();

$wach = lg_meta_lesen('bruecke_zuletzt', '');
$laeuft = $wach !== '' && (time() - strtotime($wach . ' UTC')) < 15;
echo json_encode([
    'ok' => true,
    'id' => $jobId,
    'meldung' => $laeuft ? 'An den Drucker geschickt.' : 'In die Warteschlange gelegt – die Brücke auf dem Lager-PC läuft gerade nicht.',
]);
