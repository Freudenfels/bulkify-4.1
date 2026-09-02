<?php
// Hintergrundarbeit fuer die KI.
//
// Eine KI-Anfrage dauert leicht eine Minute. Wenn ein Kunde im Portal seine Idee abschickt, darf er
// nicht so lange auf die Bestaetigung warten – und der Webserver bricht die Verbindung ohnehin
// irgendwann ab (Timeout), obwohl die Anfrage laengst gespeichert ist.
//
// Deshalb: Der sichtbare Aufruf speichert nur und stoesst hier einen zweiten, eigenen Aufruf an
// (?p=ki_job). Der laeuft im Webserver weiter, waehrend der Kunde schon seine Seite sieht.
// Kein Cron, keine Warteschlange, keine zusaetzliche Software.
//
// Sicherheit: Der Job-Aufruf braucht einen Schluessel, der aus Art + ID + unserem API-Schluessel
// berechnet wird. Von aussen ist er nicht zu erraten, und er passt immer nur auf genau einen Vorgang.
require_once __DIR__ . '/ki.php';

function ki_job_schluessel(string $art, int $id): string {
    return hash_hmac('sha256', $art . ':' . $id, ki_key() ?: 'bulkify');
}

// Job anstossen und sofort zurueckkommen. Die kurze Wartezeit reicht dem Webserver, den Aufruf
// anzunehmen; die Antwort interessiert uns nicht – deshalb ist der Timeout hier der Normalfall.
function ki_job_starten(string $art, int $id): void {
    if (!ki_bereit()) return;
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') return;
    $schema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $pfad   = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $url    = $schema . '://' . $host . $pfad . '?p=ki_job&art=' . urlencode($art) . '&id=' . $id
            . '&s=' . ki_job_schluessel($art, $id);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS     => 400,     // nur anklopfen, nicht auf das Ergebnis warten
        CURLOPT_NOSIGNAL       => 1,
        CURLOPT_SSL_VERIFYPEER => false,   // eigener Host, ggf. internes Zertifikat
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    curl_exec($ch);
    curl_close($ch);
    ki_log('job/start', $art . '#' . $id, 0, null, 'angestossen');
}

// Die eigentliche Arbeit. Laeuft im zweiten Aufruf, ohne dass jemand zusieht.
function ki_job_ausfuehren(string $art, int $id): void {
    if ($art === 'rezeptur') {
        require_once __DIR__ . '/rezeptur_ki.php';
        $r = rezeptur_ki_entwickeln($id);
        if ($r['ok']) rezeptur_ki_merken($id, $r);
    }
}
