<?php
// Rahmen jeder Seite - im Aussehen des Dashboards.
//
// Kein eigenes Aussehen: Das Lager laedt dasselbe Stylesheet wie das Dashboard (`/assets/app.css`)
// und benutzt dieselben Klassen. Die wenigen Ergaenzungen stehen in `assets/lager.css` (alle mit
// `lg-` davor). Dunkler Modus: dieselbe Speicherstelle `bx-theme` wie Dashboard und CRM.
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/auth.php';

function lg_nav(): array {
    // Reihenfolge nach dem taeglichen Ablauf: erst was reinkommt (erwartet/einbuchen), dann finden,
    // dann raus, dann Nachschlagen. Keine festen Plaetze (alles fliegender Modus) -> kein Fulfillment.
    // Übersicht + Einbuchen stehen OBEN als eigene Punkte (Gruppenschlüssel '' = ohne Überschrift),
    // über der Gruppe "Lager 1". Einbuchen gibt es zusätzlich als Knopf oben auf jeder Seite.
    $nav = [
        ''            => ['uebersicht' => 'Übersicht', 'we' => 'Einbuchen'],
        'Lager 1'     => ['erwartet' => 'Erwartete Lieferungen',
                          'finden' => 'Suche', 'ausgang' => 'Warenausgang', 'bestand' => 'Bestand'],
        'Lager 2 (Fremdlager)' => ['l2_finden' => 'Suche', 'l2_bestand' => 'Fremdlager-Bestand'],
        'Verwaltung'  => ['bewegungen' => 'Bewegungen', 'kisten' => 'Kisten', 'leisten' => 'Blinker', 'papierkorb' => 'Mülleimer'],
    ];
    if (lg_ist_admin()) $nav['System'] = ['einstellungen' => 'Einstellungen'];
    return $nav;
}

function kopf(string $titel, string $aktiv = ''): void {
    $GLOBALS['lg_aktiv'] = $aktiv;   // fuer seitenkopf(): auf welcher Seite sind wir (Einbuchen-Knopf)
    $u = lg_benutzer();
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
       . '<title>' . h($titel !== '' ? $titel . ' – ' . BX_MARKE . ' ' . BX_TITEL : BX_MARKE . ' ' . BX_TITEL) . '</title>'
       . '<link rel="stylesheet" href="/assets/app.css">'
       . '<link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">'
       . '<link rel="icon" href="/assets/icons/favicon.ico" sizes="any">'
       . '<link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png">'
       . '<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">'
       . '<link rel="manifest" href="manifest.webmanifest">'
       . '<meta name="mobile-web-app-capable" content="yes">'
       . '<meta name="apple-mobile-web-app-capable" content="yes">'
       . '<meta name="apple-mobile-web-app-title" content="Lager">'
       . '<link rel="stylesheet" href="assets/lager.css">'
       . '<meta name="theme-color" content="#10210F">'
       . '<script>(function(){try{var t=localStorage.getItem("bx-theme");'
       . 'if(t==="dark"||t==="light")document.documentElement.setAttribute("data-theme",t);'
       . 'var w=parseInt(localStorage.getItem("bx-side-w"),10);'
       . 'if(w>=180&&w<=420)document.documentElement.style.setProperty("--side-w",w+"px");}catch(e){}})();</script>'
       . '</head><body>';

    echo '<div class="bx-shell">';

    if ($u) {
        echo '<aside class="bx-side">'
           . '<div class="bx-brand"><img src="/assets/bulkify-logo-white.png" alt="' . h(BX_MARKE) . '" class="bx-logo">'
           . '<span class="bx-ver">' . h(BX_TITEL) . '</span></div><nav>';
        foreach (lg_nav() as $gruppe => $seiten) {
            if ($gruppe !== '') echo '<div class="bx-navgroup">' . h($gruppe) . '</div>';
            foreach ($seiten as $route => $label) {
                echo '<a href="?p=' . h($route) . '"' . ($aktiv === $route ? ' class="on"' : '') . '>'
                   . '<span>' . h($label) . '</span></a>';
            }
        }
        echo '<div class="bx-navgroup">Dashboard</div>'
           . '<a href="' . h(erp_dashboard_url()) . '"><span>Zum Dashboard</span></a>';

        echo '<div class="bx-userbox">'
           . '<div class="bx-username">' . h((string)$u['name']) . '</div>'
           . '<div class="bx-userroles">' . h((string)$u['email']) . '</div>'
           . '<a class="bx-logout" href="?p=logout">Abmelden</a>'
           . '<button type="button" class="bx-themebtn">Dunkler Modus</button>'
           . '</div></nav></aside>'
           . '<div class="bx-menuescrim" id="bx-menuescrim"></div>';
    }

    echo '<main class="bx-main">';
    if ($u) {
        echo '<div class="bx-mobilbar">'
           . '<button type="button" class="bx-burger" id="bx-burger" aria-label="Menü" aria-expanded="false">'
           . '<span></span><span></span><span></span></button>'
           . ($titel !== '' ? '<span class="bx-mobil-titel">' . h($titel) . '</span>' : '')
           . '<img src="/assets/bulkify-logo-white.png" alt="bulkify" class="bx-logo">'
           . '</div>';
        warnbalken();
    }
}

// Warnbalken oben: wie viele Blinker (geschaetzt) eine neue Batterie brauchen. Klick -> Batterie-Runde.
function warnbalken(): void {
    if (!function_exists('leiste_batterie_zahl')) return;
    try { $n = leiste_batterie_zahl(); } catch (Throwable $e) { return; }
    if ($n < 1) return;
    echo '<a class="lg-warnbalken" href="?p=batterie">'
       . '<span class="lg-warnpunkt"></span>'
       . '<strong>' . $n . '</strong>&nbsp;' . ($n === 1 ? 'Blinker sollte' : 'Blinker sollten') . ' eine neue Batterie bekommen'
       . '<span class="lg-warnmehr">prüfen →</span></a>';
}

function fuss(): void {
    echo '</main></div>';
    // Dunkler Modus - gleiche Speicherstelle wie im Dashboard.
    echo '<script>(function(){var r=document.documentElement;'
       . 'function lbl(){var d=r.getAttribute("data-theme")==="dark";'
       . 'document.querySelectorAll(".bx-themebtn").forEach(function(b){b.textContent=d?"Heller Modus":"Dunkler Modus";});}'
       . 'document.querySelectorAll(".bx-themebtn").forEach(function(b){b.addEventListener("click",function(e){e.preventDefault();'
       . 'var d=r.getAttribute("data-theme")==="dark";var t=d?"light":"dark";r.setAttribute("data-theme",t);'
       . 'try{localStorage.setItem("bx-theme",t);}catch(err){}lbl();});});lbl();})();</script>';
    // Menue-Schublade fuers Handy/Tablet.
    echo '<script>(function(){var r=document.documentElement,'
       . 'b=document.getElementById("bx-burger"),s=document.getElementById("bx-menuescrim");'
       . 'function zu(){r.removeAttribute("data-menue");if(b)b.setAttribute("aria-expanded","false");}'
       . 'function auf(){r.setAttribute("data-menue","auf");if(b)b.setAttribute("aria-expanded","true");}'
       . 'if(b)b.addEventListener("click",function(){r.getAttribute("data-menue")==="auf"?zu():auf();});'
       . 'if(s)s.addEventListener("click",zu);'
       . 'document.addEventListener("keydown",function(e){if(e.key==="Escape")zu();});'
       . 'document.querySelectorAll(".bx-side nav a").forEach(function(a){a.addEventListener("click",zu);});'
       . 'addEventListener("resize",function(){if(innerWidth>860)zu();});})();</script>';
    // Lade-Rueckmeldung fuer Formulare (Spinner + Ladebalken) und die Leucht-Knoepfe.
    echo '<script src="assets/busy.js" defer></script>';
    echo '<script src="assets/lager.js" defer></script>';
    echo '<script src="assets/voice.js" defer></script>';
    // Service Worker nur fuer die Installierbarkeit als App (Handscanner). Cacht keine Seiten.
    echo '<script>if("serviceWorker" in navigator){window.addEventListener("load",function(){'
       . 'navigator.serviceWorker.register("sw.js").catch(function(){});});}</script>';
    echo '</body></html>';
}

// Ueberschrift einer Seite, optional mit Knoepfen rechts - wie bx_head() im Dashboard.
// Rechts steht auf jeder Seite ein "Einbuchen"-Knopf (ausser auf der Einbuch-Seite selbst).
function seitenkopf(string $titel, string $unter = '', string $aktion = ''): void {
    if (($GLOBALS['lg_aktiv'] ?? '') !== 'we')
        $aktion = '<a class="btn btn-primary" href="?p=we">Einbuchen</a> ' . $aktion;
    echo '<div class="bx-head"><div><h1>' . h($titel) . '</h1>';
    if ($unter !== '') echo '<p class="bx-sub">' . h($unter) . '</p>';
    echo '</div>';
    if ($aktion !== '') echo '<div class="bx-row">' . $aktion . '</div>';
    echo '</div>';
}

function hinweis(string $text, string $art = 'ok'): void {
    echo '<div class="bx-panel lg-hinweis ' . ($art === 'warn' ? 'warn' : 'ok') . '">' . h($text) . '</div>';
}
