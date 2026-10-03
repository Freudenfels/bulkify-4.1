<?php
// Interner Blink-Auslöser für ANDERE Programme (z. B. Produktion „Pick-to-Light").
// Kein Lager-Login nötig – Auth per gemeinsamem Token (LG_BLINK_TOKEN aus secrets.php)
// ODER Loopback (Server-zu-Server, REMOTE_ADDR = 127.0.0.1/::1). Antwort: JSON {ok, meldung}.
//
// Eingang: charge_id (Dashboard-Charge) ODER charge_nr; optional aktion=an|aus, farbe, sek.
// Es wird NUR die zur Charge gebundene Leiste bzw. der Kisten-Blinker angesteuert (leiste_finden).
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

$charge_id = (int)($_REQUEST['charge_id'] ?? 0);
if ($charge_id <= 0 && trim((string)($_REQUEST['charge_nr'] ?? '')) !== '')
    $charge_id = (int) scalar("SELECT id FROM charge WHERE charge_nr=? ORDER BY id DESC LIMIT 1", [trim((string)$_REQUEST['charge_nr'])]);
if ($charge_id <= 0) { echo json_encode(['ok' => false, 'meldung' => 'Keine Charge angegeben.'], JSON_UNESCAPED_UNICODE); exit; }

// Leiste der Charge finden – erst direkt gebunden, sonst über die Kiste.
$l = leiste_fuer_charge($charge_id);
if (!$l) { $k = kiste_fuer_charge($charge_id); if ($k) $l = kiste_blinker((int)$k['kiste_id']); }
if (!$l) { echo json_encode(['ok' => false, 'meldung' => 'Für diese Charge ist kein Blinker hinterlegt.'], JSON_UNESCAPED_UNICODE); exit; }

$farbe = (string)($_REQUEST['farbe'] ?? 'gruen');
if (!isset(led_farben()[$farbe])) $farbe = 'gruen';
$sek = (int)($_REQUEST['sek'] ?? 40);

$r = ($_REQUEST['aktion'] ?? 'an') === 'aus'
    ? leiste_aus((int)$l['id'])
    : leiste_finden((int)$l['id'], $farbe, $sek, true);

echo json_encode($r, JSON_UNESCAPED_UNICODE);
