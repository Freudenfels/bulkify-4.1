<?php
// Leuchten per fetch() (assets/lager.js). Antwortet immer mit JSON: {ok, meldung}.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_antwort(['ok' => false, 'meldung' => 'Nur per POST.'], 405);

$platz_id = (int)($_POST['platz_id'] ?? 0);
$farbe = (string)($_POST['farbe'] ?? 'gruen');
if (!isset(led_farben()[$farbe])) $farbe = 'gruen';

$r = ($_POST['aktion'] ?? 'an') === 'aus'
    ? led_platz_aus($platz_id)
    : led_platz_an($platz_id, $farbe, (int)($_POST['sek'] ?? 20), (string)($_POST['piep'] ?? '1') === '1');

json_antwort($r);
