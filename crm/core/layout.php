<?php
// Rahmen jeder Seite. Handy zuerst: oben eine schmale Kopfleiste, unten eine Leiste mit den
// vier Bereichen - so, wie man eine App bedient. Auf breiten Bildschirmen wandert dieselbe
// Navigation nach oben in die Kopfleiste.
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/auth.php';

// Jeder Bereich mit eigenem Zeichen. Bewusst schlichte Linien - keine Emojis.
function crm_nav(): array {
    return [
        'wartet'   => ['Wartet',   '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 1.8"/>'],
        'kontakte' => ['Kontakte', '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 19.5c0-3.3 3.1-5.5 7-5.5s7 2.2 7 5.5"/>'],
        'erfassen' => ['Erfassen', '<circle cx="12" cy="12" r="8.5"/><path d="M12 8.5v7M8.5 12h7"/>'],
        'mehr'     => ['Mehr',     '<circle cx="5.5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="18.5" cy="12" r="1.3"/>'],
    ];
}

// Wie viele warten auf mich? Wird in der unteren Leiste angezeigt. Faellt der Zusammenzug aus
// (z. B. Datenbank kurz weg), zeigen wir lieber nichts als eine Fehlermeldung im Menue.
function crm_wartet_zahl(): int {
    static $n = null;
    if ($n !== null) return $n;
    try { require_once __DIR__ . '/wartet.php'; $n = count(wartet_zeilen('sie')); }
    catch (Throwable $e) { $n = 0; }
    return $n;
}

function kopf(string $titel, string $aktiv = ''): void {
    $u = crm_benutzer();
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
       . '<title>' . h($titel !== '' ? $titel . ' – ' . BX_MARKE . ' ' . BX_TITEL : BX_MARKE . ' ' . BX_TITEL) . '</title>'
       . '<link rel="stylesheet" href="assets/app.css">'
       . '<link rel="manifest" href="manifest.webmanifest">'
       . '<meta name="theme-color" content="#10210F">'
       . '<link rel="apple-touch-icon" href="assets/app-icon-180.png">'
       . '<meta name="apple-mobile-web-app-capable" content="yes">'
       . '<meta name="apple-mobile-web-app-title" content="bulkify CRM">'
       . '<script>(function(){try{var t=localStorage.getItem("crm-theme");'
       . 'if(t==="dark"||t==="light")document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script>'
       . '</head><body>';

    echo '<header class="kopf"><a class="marke" href="?p=wartet">' . h(BX_MARKE) . ' <span>' . h(BX_TITEL) . '</span></a>';
    if ($u) {
        echo '<nav class="breitnav">';
        foreach (crm_nav() as $route => [$label, $_]) {
            echo '<a href="?p=' . h($route) . '"' . ($aktiv === $route ? ' class="an"' : '') . '>' . h($label) . '</a>';
        }
        echo '</nav><span class="wer">' . h((string)$u['name']) . '</span>';
    }
    echo '</header><main class="inhalt">';
}

function fuss(string $aktiv = ''): void {
    echo '</main>';
    if (crm_angemeldet()) {
        echo '<nav class="tableiste">';
        // Am Punkt "Wartet" steht die Zahl - das ist der einzige Grund, die App zu oeffnen.
        $offen = crm_wartet_zahl();
        foreach (crm_nav() as $route => [$label, $pfad]) {
            $zahl = ($route === 'wartet' && $offen > 0) ? '<i class="zahl">' . ($offen > 99 ? '99+' : $offen) . '</i>' : '';
            echo '<a href="?p=' . h($route) . '"' . ($aktiv === $route ? ' class="an"' : '') . '>'
               . '<span class="zeichen"><svg viewBox="0 0 24 24" aria-hidden="true">' . $pfad . '</svg>' . $zahl . '</span>'
               . '<span>' . h($label) . '</span></a>';
        }
        echo '</nav>';
    }
    echo '<script>if("serviceWorker" in navigator){window.addEventListener("load",function(){'
       . 'navigator.serviceWorker.register("sw.js").catch(function(){});});}</script>';
    echo '</body></html>';
}

// Ueberschrift einer Seite, optional mit einem Knopf rechts.
function seitenkopf(string $titel, string $unter = '', string $aktion = ''): void {
    echo '<div class="seitenkopf"><div><h1>' . h($titel) . '</h1>';
    if ($unter !== '') echo '<p class="leise">' . h($unter) . '</p>';
    echo '</div>' . ($aktion !== '' ? '<div>' . $aktion . '</div>' : '') . '</div>';
}

function hinweis(string $text, string $art = 'ok'): void {
    echo '<div class="hinweis ' . h($art) . '">' . h($text) . '</div>';
}
