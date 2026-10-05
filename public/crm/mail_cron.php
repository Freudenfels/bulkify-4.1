<?php
// Hintergrund-Abruf des E-Mail-Eingangs - fuer einen Cronjob. KEIN Login, token-gesichert.
// Aufruf: <host>/crm/mail_cron.php?token=<mail_cron_token>  (z. B. alle 5 Minuten per Cron).
// Holt neue Mails ins crm_mail_eingang; angelegt wird nichts automatisch - das macht ein Mensch
// mit einem Klick auf der Eingang-Seite.
require_once __DIR__ . '/../../crm/core/config.php';
require_once __DIR__ . '/../../crm/core/db.php';
require_once __DIR__ . '/../../crm/core/schema.php';
require_once __DIR__ . '/../../crm/core/mail_abruf.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function mc_out(int $code, array $a): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

crm_schema();

$given = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
$token = mail_cron_token();
if ($token === '' || $given === '' || !hash_equals($token, $given)) {
    mc_out(403, ['ok' => false, 'fehler' => 'Ungültiger oder fehlender Token.']);
}

$max = max(1, min(100, (int)($_GET['max'] ?? 30)));
$r = mail_abholen($max);
mc_out($r['ok'] ? 200 : 500, ['ok' => (bool)$r['ok'], 'abgeholt' => (int)$r['anzahl'], 'fehler' => (string)$r['fehler']]);
