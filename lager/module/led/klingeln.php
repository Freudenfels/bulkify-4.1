<?php
// Blinker klingeln lassen per fetch (assets/lager.js), fuer das Chaos-Finden. Antwort: JSON {ok, meldung}.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_antwort(['ok' => false, 'meldung' => 'Nur per POST.'], 405);

$leiste_id = (int)($_POST['leiste_id'] ?? 0);
$farbe = (string)($_POST['farbe'] ?? 'gruen');
if (!isset(led_farben()[$farbe])) $farbe = 'gruen';

$r = ($_POST['aktion'] ?? 'an') === 'aus'
    ? leiste_aus($leiste_id)
    : leiste_finden($leiste_id, $farbe, (int)($_POST['sek'] ?? 40));

json_antwort($r);
