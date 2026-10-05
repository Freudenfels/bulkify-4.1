<?php
// Druckauftrag in die Warteschlange legen. Die kombinierte Brücke auf dem Lager-PC holt ihn ab und
// druckt lautlos (SumatraPDF) – auf den je Dokument eingestellten Drucker.
//   typ=etikett (Standard): ids=<charge-ids>        -> Karton-Etikett (drucker_name)
//   typ=lieferschein:       id=<versand-id>         -> Lieferschein A4 (drucker_lieferschein)
//   typ=label:              id=<versand-id>         -> Versand-Label (drucker_versandlabel)
header('Content-Type: application/json; charset=utf-8');

$typ = (string)($_POST['typ'] ?? 'etikett');
if (!in_array($typ, ['etikett', 'lieferschein', 'label'], true)) $typ = 'etikett';

if ($typ === 'etikett') {
    $roh = (string)($_POST['ids'] ?? '');
    $ids = array_values(array_filter(array_map('intval', explode(',', $roh)), fn($x) => $x > 0));
    if (!$ids) { echo json_encode(['ok' => false, 'fehler' => 'Keine Charge angegeben.']); exit; }
    $refs = implode(',', $ids);
    $format = 'gross';
} else {
    $vid = (int)($_POST['id'] ?? 0);
    if ($vid <= 0) { echo json_encode(['ok' => false, 'fehler' => 'Keine Sendung angegeben.']); exit; }
    if ($typ === 'label' && function_exists('lg_versand_hat_label') && !lg_versand_hat_label($vid)) {
        echo json_encode(['ok' => false, 'fehler' => 'Für diese Sendung gibt es noch kein Versand-Label.']); exit;
    }
    $refs = (string)$vid;
    $format = $typ === 'label' ? 'label' : 'a4';
}

$uid = (int)(lg_benutzer()['id'] ?? 0);
q("INSERT INTO lg_druckjob (ids, format, typ, status, benutzer_id, angelegt) VALUES (?,?,?,'offen',?,?)",
  [$refs, $format, $typ, $uid ?: null, jetzt_utc()]);
$jobId = (int) insert_id();

$wach = lg_meta_lesen('bruecke_zuletzt', '');
$laeuft = $wach !== '' && (time() - strtotime($wach . ' UTC')) < 15;
echo json_encode([
    'ok' => true,
    'id' => $jobId,
    'meldung' => $laeuft ? 'An den Drucker geschickt.' : 'In die Warteschlange gelegt – die Brücke auf dem Lager-PC läuft gerade nicht.',
]);
