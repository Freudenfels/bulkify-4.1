<?php
// Interner Druck-Auslöser für ANDERE Programme (z. B. Produktion: Proben-Etikett).
// Kein Lager-Login nötig – Auth per gemeinsamem Token (LG_BLINK_TOKEN aus secrets.php)
// ODER Loopback (Server-zu-Server, REMOTE_ADDR = 127.0.0.1/::1). Antwort: JSON {ok, meldung}.
//
// Legt NUR einen Druckauftrag in lg_druckjob an (typ, id). Die PDF wird – wie bei allen Druckjobs –
// erst beim Poll von der Brücke frisch erzeugt und auf dem je typ hinterlegten Drucker gedruckt.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$tokenOk = defined('LG_BLINK_TOKEN') && LG_BLINK_TOKEN !== ''
    && hash_equals((string)LG_BLINK_TOKEN, (string)($_REQUEST['token'] ?? ''));
$remote   = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$loopback = in_array($remote, ['127.0.0.1', '::1'], true);
if (!$tokenOk && !$loopback) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'meldung' => 'Nicht erlaubt (Token fehlt/falsch).'], JSON_UNESCAPED_UNICODE);
    exit;
}

$typ = preg_replace('/[^a-z]/', '', (string)($_REQUEST['typ'] ?? 'probe'));
if (!in_array($typ, ['probe'], true)) {   // nur definierte interne Drucktypen
    echo json_encode(['ok' => false, 'meldung' => 'Unbekannter Drucktyp.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$refId = (int)($_REQUEST['id'] ?? 0);
if ($refId <= 0) { echo json_encode(['ok' => false, 'meldung' => 'Keine ID angegeben.'], JSON_UNESCAPED_UNICODE); exit; }

q("INSERT INTO lg_druckjob (ids, format, typ, status, benutzer_id, angelegt) VALUES (?, 'klein', ?, 'offen', NULL, ?)",
  [(string)$refId, $typ, jetzt_utc()]);
$jobId = (int) insert_id();

// Läuft die Brücke gerade? (Lebenszeichen < 15s) – nur als Info für die aufrufende Seite.
$zuletzt = lg_meta_lesen('bruecke_zuletzt', '');
$wach    = $zuletzt !== '' && (time() - strtotime($zuletzt . ' UTC')) < 15;

echo json_encode([
    'ok'           => true,
    'job'          => $jobId,
    'bruecke_wach' => $wach,
    'meldung'      => $wach ? 'An den Drucker gesendet.' : 'In die Warteschlange gelegt (Brücke meldet sich gerade nicht).',
], JSON_UNESCAPED_UNICODE);
