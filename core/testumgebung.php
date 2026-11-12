<?php
// Testumgebung test.bulkify.pro (Paket 12.11.2026 Teil 2). Eigenständig (keine Abhängigkeiten), damit Dashboard,
// Lager, Produktion, CRM und Buchhaltung dieselbe Datei aus ihrer config.php laden können.
// Erkennung: secrets.php der Testumgebung setzt define('BX_TESTUMGEBUNG', true) (oder $BX_TESTUMGEBUNG = true);
// zusätzlich zählt jeder Aufruf über einen Host, der mit „test.“ beginnt. Live ist nie betroffen.
// Wirkung im Testmodus:
//  - jede HTML-Seite bekommt oben das Band „TESTUMGEBUNG - keine echten Daten“ und <meta name="robots" noindex>,
//    jede Antwort den Kopf X-Robots-Tag: noindex, nofollow
//  - Mails gehen nur an BX_TEST_MAIL_AN (Standard Nico), Betreff mit „[TEST an …]“; leer = nur protokollieren
//  - Crons, IMAP-Abruf, Blinker-Cloud, Carrier-Aufrufe (Versand-Labels) und die Druckbrücke sind aus
// Siehe testumgebung.md.

if (!function_exists('bx_testumgebung')) {

// 13.11.2026: zusätzlich der Datei-Marker „.testumgebung“ im Projektstamm des Test-Ordners – erkennt den Testmodus auch auf der
// Kommandozeile (Crons, tools/), bevor eine secrets.php geladen ist. Der Test-Deploy legt ihn an, live gibt es ihn nie.
function bx_test_marker_da(): bool {
    static $da = null;
    if ($da === null) $da = is_file(dirname(__DIR__) . '/.testumgebung');
    return $da;
}
function bx_testumgebung(): bool {
    static $an = null;
    if ($an !== null) return $an;
    $an = (defined('BX_TESTUMGEBUNG') && BX_TESTUMGEBUNG)
        || !empty($GLOBALS['BX_TESTUMGEBUNG'])
        || bx_test_marker_da()
        || (PHP_SAPI !== 'cli' && preg_match('/^test\./i', (string)($_SERVER['HTTP_HOST'] ?? '')) === 1);
    return $an;
}

// Riegel „Testmodus an, Datenbank ist live“ (13.11.2026): Jedes Programm (Dashboard, Lager, CRM, Buchhaltung, Produktion) ruft
// das direkt nach dem Verbinden auf. Live trägt den Marker app_meta umgebung=live (setzt sich auf app.bulkify.pro selbst).
// Testmodus + Marker gefunden = sofort Abbruch mit Meldung – die Testumgebung schreibt nie in die Live-Datenbank.
function bx_test_riegel(PDO $pdo): void {
    static $geprueft = false;
    if ($geprueft) return;
    $geprueft = true;
    try {
        if (bx_testumgebung()) {
            $st = $pdo->prepare("SELECT v FROM app_meta WHERE k='umgebung'"); $st->execute();
            if ((string)($st->fetchColumn() ?: '') === 'live') {
                if (PHP_SAPI !== 'cli' && !headers_sent()) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); }
                echo 'Abbruch: Testmodus ist an, aber die Datenbank ist die LIVE-Datenbank (app_meta umgebung=live). Bitte die secrets.php der Testumgebung prüfen.';
                exit(1);
            }
        } elseif (PHP_SAPI !== 'cli' && preg_match('/^app\.bulkify\.pro$/i', (string)($_SERVER['HTTP_HOST'] ?? '')) === 1) {
            // Live markiert sich selbst (einmal je Anfrage geprüft, geschrieben nur, wenn er fehlt).
            $st = $pdo->prepare("SELECT v FROM app_meta WHERE k='umgebung'"); $st->execute();
            if ((string)($st->fetchColumn() ?: '') !== 'live') $pdo->prepare("INSERT INTO app_meta (k, v) VALUES ('umgebung', 'live') ON DUPLICATE KEY UPDATE v='live'")->execute();
        }
    } catch (\Throwable $e) { /* app_meta noch nicht da (frische Installation) */ }
}

// Host für interne Aufrufe Lager/Produktion -> Dashboard und für QR-Links (13.11.2026): im Testmodus nie app.bulkify.pro.
function bx_intern_host(): string {
    $h = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($h !== '') return $h;
    if (bx_testumgebung()) return defined('BX_TEST_HOST') ? (string) BX_TEST_HOST : 'test.bulkify.pro';
    return 'app.bulkify.pro';
}

// Ziel aller Mails im Testmodus. '' = nur ins Mail-Protokoll schreiben, nichts versenden.
function bx_test_mail_an(): string {
    if (defined('BX_TEST_MAIL_AN')) return trim((string) BX_TEST_MAIL_AN);
    if (isset($GLOBALS['BX_TEST_MAIL_AN'])) return trim((string) $GLOBALS['BX_TEST_MAIL_AN']);
    return 'thomalla@freudenfels.de';
}

// Mail im Testmodus umleiten: [$to, $betreff] zurück; $to = '' heißt nicht senden.
function bx_test_mail_umleiten(string $to, string $betreff): array {
    if (!bx_testumgebung()) return [$to, $betreff];
    return [bx_test_mail_an(), '[TEST an ' . $to . '] ' . $betreff];
}

// Meldung, wenn im Testmodus eine Wirkung nach außen gesperrt ist ('' = erlaubt).
function bx_test_gesperrt(string $was): string {
    return bx_testumgebung() ? 'Testumgebung: ' . $was . ' ist hier ausgeschaltet (keine Wirkung nach außen).' : '';
}

function bx_test_band_html(): string {
    return '<div id="bx-testband" style="position:sticky;top:0;z-index:99999;background:#b42318;color:#fff;font:600 14px/1.4 Arial,sans-serif;'
         . 'text-align:center;padding:7px 12px;letter-spacing:.04em">TESTUMGEBUNG - keine echten Daten'
         . '<span style="font-weight:400;opacity:.9"> · Mails gehen nur an das Test-Postfach, nichts wird gedruckt oder versendet · </span>'
         . '<a href="/lager/?p=druck_vorschau" style="color:#fff">Druckvorschau</a> · <a href="/?p=test_abzug" style="color:#fff">Datenstand von live holen</a></div>';
}

// Band + noindex in jede HTML-Ausgabe einsetzen (Ausgabepuffer). PDFs/JSON bleiben unverändert.
function bx_test_ausgabe(string $html): string {
    if (stripos($html, '<body') === false) return $html;
    if (stripos($html, 'name="robots"') === false)
        $html = preg_replace('/<head([^>]*)>/i', '<head$1><meta name="robots" content="noindex, nofollow">', $html, 1);
    if (strpos($html, 'id="bx-testband"') === false)
        $html = preg_replace('/<body([^>]*)>/i', '<body$1>' . bx_test_band_html(), $html, 1);
    return $html;
}

function bx_test_start(): void {
    static $los = false;
    if ($los || !bx_testumgebung() || PHP_SAPI === 'cli') return;
    $los = true;
    if (!headers_sent()) header('X-Robots-Tag: noindex, nofollow');
    ob_start('bx_test_ausgabe');
}

}
