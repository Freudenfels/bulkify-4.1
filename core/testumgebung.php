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

function bx_testumgebung(): bool {
    static $an = null;
    if ($an !== null) return $an;
    $an = (defined('BX_TESTUMGEBUNG') && BX_TESTUMGEBUNG)
        || !empty($GLOBALS['BX_TESTUMGEBUNG'])
        || (PHP_SAPI !== 'cli' && preg_match('/^test\./i', (string)($_SERVER['HTTP_HOST'] ?? '')) === 1);
    return $an;
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
