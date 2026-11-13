<?php
// Einmal-Kennung gegen doppelt abgeschickte Formulare (Fable-6 P15/T2.6, 13.11.2026). Siehe einmal.md.
// - Browser (public/assets/bx-ui.js): jedes normal abgeschickte POST-Formular bekommt beim Absenden ein verstecktes Feld
//   _einmal mit einer zufälligen Kennung; der Knopf wird nach dem ersten Klick gesperrt.
// - Server (dieser Baustein, in allen Front-Controllern): eine Kennung wird nur EINMAL angenommen (Tabelle formular_einmal,
//   Primärschlüssel). Die zweite Sendung mit derselben Kennung wird abgelehnt, bevor irgendetwas gebucht wird.
// Ohne Datenbank-Helfer q() oder ohne Kennung passiert nichts (alte Formulare, Skripte).

function einmal_pruefen(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $k = (string)($_POST['_einmal'] ?? '');
    if ($k === '' || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $k) || !function_exists('q')) return;
    try {
        q("CREATE TABLE IF NOT EXISTS formular_einmal (kennung VARCHAR(64) NOT NULL PRIMARY KEY, angelegt DATETIME NOT NULL, ziel VARCHAR(190) NULL) DEFAULT CHARSET=utf8mb4");
        $st = q("INSERT IGNORE INTO formular_einmal (kennung, angelegt, ziel) VALUES (?,?,?)",
                [$k, gmdate('Y-m-d H:i:s'), mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 190)]);
        if ($st->rowCount() === 1) return;   // erste Sendung -> weiter
    } catch (\Throwable $e) { return; }   // nie wegen der Kennung etwas blockieren, wenn die DB hakt
    einmal_doppelt();
}

// Zweite Sendung: nichts buchen. JSON-Anfragen bekommen 409, sonst zurück auf die Seite (zeigt den Stand nach der ersten Sendung).
function einmal_doppelt(): void {
    $json = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    if ($json) {
        http_response_code(409);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'fehler' => 'Schon abgeschickt – wurde nur einmal gebucht.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $zur = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $ok = $zur !== '' && $host !== '' && parse_url($zur, PHP_URL_HOST) === parse_url('http://' . $host, PHP_URL_HOST);
    if ($ok) {
        $zur = preg_replace('/([?&])einmal=doppelt(&|$)/', '$1', $zur);
        header('Location: ' . $zur . (str_contains($zur, '?') ? '&' : '?') . 'einmal=doppelt', true, 303);
        exit;
    }
    http_response_code(409);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Schon abgeschickt</title>'
       . '<div style="font-family:system-ui,sans-serif;max-width:520px;margin:40px auto;padding:0 16px"><h1 style="font-size:20px">Schon abgeschickt</h1>'
       . '<p>Dieses Formular wurde bereits abgeschickt und nur einmal gebucht. Bitte die Seite neu laden.</p><p><a href="javascript:history.back()">Zurück</a></p></div>';
    exit;
}
