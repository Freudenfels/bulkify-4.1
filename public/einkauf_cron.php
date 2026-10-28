<?php
// Automatische Erinnerungen an überfällige, unbestätigte Portal-Bestellungen (B4).
// KEIN Login, token-gesichert. Aufruf per Server-Cron, z. B. 1x täglich:
//   <host>/einkauf_cron.php?token=<einkauf_cron_token>
// Den Token zeigt die Einkauf-Liste (Admin) an bzw. legt ihn beim ersten Aufruf von einkauf_cron_token() an.
// Schickt je überfälliger Bestellung höchstens 1 Erinnerung pro 24 h (Mailversand über Dashboard-SMTP).
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/schema.php';
require_once __DIR__ . '/../core/mail.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function ec_out(int $code, array $a): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

init_schema();

$given = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
$token = einkauf_cron_token();
if ($token === '' || $given === '' || !hash_equals($token, $given)) {
    ec_out(403, ['ok' => false, 'fehler' => 'Ungültiger oder fehlender Token.']);
}

$tage = max(1, min(30, (int)($_GET['tage'] ?? 3)));          // ab wie vielen Tagen überfällig
$r = einkauf_erinnerungen_cron($tage, 24);
ec_out(200, ['ok' => true, 'gesendet' => (int)$r['gesendet'], 'geprueft' => (int)$r['geprueft']]);
