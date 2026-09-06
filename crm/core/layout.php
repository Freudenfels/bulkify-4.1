<?php
// Rahmen jeder Seite - im Aussehen des Dashboards.
//
// Bewusst KEIN eigenes Aussehen mehr: Das CRM laedt dasselbe Stylesheet wie das Dashboard
// (`/assets/app.css`) und benutzt dieselben Klassen (bx-shell, bx-side, bx-panel, btn ...).
// Wer dort etwas an den Farben aendert, aendert es hier mit. Nur die Wartezeilen brauchen ein
// paar Zeilen extra, die stehen in `assets/crm.css` und werden DANACH geladen.
//
// Genauso uebernommen: die Menue-Schublade fuers Handy (Burger) und der dunkle Modus - dieselbe
// Speicherstelle `bx-theme`, damit die Einstellung fuer beide Programme gilt.
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/auth.php';

// Die Bereiche des CRM - Aufbau wie das Menue im Dashboard: Gruppe, darunter die Punkte.
function crm_nav(): array {
    return [
        'Start'    => ['wartet' => 'Wer wartet auf mich'],
        'Kontakte' => ['kontakte' => 'Kontakte', 'erfassen' => 'Schnell erfassen',
                       'mail' => 'E-Mail einlesen'],
        'Kalender' => ['kalender' => 'Kalender', 'termine' => 'Termine'],
        'Kunden'   => ['kunden' => 'Kunden'],
        'System'   => ['mehr' => 'Einstellungen'],
    ];
}

function kopf(string $titel, string $aktiv = ''): void {
    $u = crm_benutzer();
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
       . '<title>' . h($titel !== '' ? $titel . ' – ' . BX_MARKE . ' ' . BX_TITEL : BX_MARKE . ' ' . BX_TITEL) . '</title>'
       // Erst das Stylesheet des Dashboards, danach die wenigen Ergaenzungen des CRM.
       . '<link rel="stylesheet" href="/assets/app.css">'
       . '<link rel="stylesheet" href="assets/crm.css">'
       . '<link rel="manifest" href="manifest.webmanifest">'
       . '<meta name="theme-color" content="#10210F">'
       . '<link rel="apple-touch-icon" href="assets/app-icon-180.png">'
       . '<meta name="apple-mobile-web-app-capable" content="yes">'
       . '<meta name="apple-mobile-web-app-title" content="bulkify CRM">'
       // Dunkler Modus und Menuebreite VOR dem Rendern setzen, sonst blitzt es beim Laden.
       . '<script>(function(){try{var t=localStorage.getItem("bx-theme");'
       . 'if(t==="dark"||t==="light")document.documentElement.setAttribute("data-theme",t);'
       . 'var w=parseInt(localStorage.getItem("bx-side-w"),10);'
       . 'if(w>=180&&w<=420)document.documentElement.style.setProperty("--side-w",w+"px");}catch(e){}})();</script>'
       . '</head><body>';

    echo '<div class="bx-shell">';

    if ($u) {
        echo '<aside class="bx-side">'
           . '<div class="bx-brand"><img src="assets/bulkify-logo-white.png" alt="' . h(BX_MARKE) . '" class="bx-logo">'
           . '<span class="bx-ver">' . h(BX_TITEL) . '</span></div><nav>';

        $offen = crm_wartet_zahl();
        foreach (crm_nav() as $gruppe => $seiten) {
            echo '<div class="bx-navgroup">' . h($gruppe) . '</div>';
            foreach ($seiten as $route => $label) {
                $zahl = ($route === 'wartet' && $offen > 0)
                      ? '<span class="bx-navbadge" title="wartet auf dich">' . ($offen > 99 ? '99+' : $offen) . '</span>' : '';
                echo '<a href="?p=' . h($route) . '"' . ($aktiv === $route ? ' class="on"' : '') . '>'
                   . '<span>' . h($label) . '</span>' . $zahl . '</a>';
            }
        }

        // Zurueck ins Dashboard - der haeufigste Weg von hier aus.
        $dash = erp_dashboard_url();
        if ($dash !== '') {
            echo '<div class="bx-navgroup">Dashboard</div>'
               . '<a href="' . h($dash . '/') . '" target="_blank" rel="noopener"><span>Zum Dashboard</span></a>';
        }

        echo '<div class="bx-userbox">'
           . '<div class="bx-username">' . h((string)$u['name']) . '</div>'
           . '<div class="bx-userroles">' . h((string)$u['email']) . '</div>'
           . '<a class="bx-logout" href="?p=logout">Abmelden</a>'
           . '<button type="button" class="bx-themebtn">Dunkler Modus</button>'
           . '</div></nav></aside>';

        echo bx_menue_scrim();
    }

    echo '<main class="bx-main">' . ($u ? bx_mobilbar() : '');
}

// Kopfleiste mit Burger fuers Handy - gehoert als ERSTES in <main>. Gleiches Markup wie im
// Dashboard; das CSS dafuer kommt aus dessen app.css.
function bx_mobilbar(): string {
    return '<div class="bx-mobilbar">'
         . '<button type="button" class="bx-burger" id="bx-burger" aria-label="Menü" aria-expanded="false">'
         . '<span></span><span></span><span></span></button>'
         . '<img src="assets/bulkify-logo-white.png" alt="bulkify" class="bx-logo">'
         . '</div>';
}
function bx_menue_scrim(): string { return '<div class="bx-menuescrim" id="bx-menuescrim"></div>'; }

function fuss(string $aktiv = ''): void {
    echo '</main></div>';
    // Dunkler Modus: dieselbe Speicherstelle wie im Dashboard, damit die Einstellung fuer beide gilt.
    echo '<script>(function(){var r=document.documentElement;'
       . 'function lbl(){var d=r.getAttribute("data-theme")==="dark";'
       . 'document.querySelectorAll(".bx-themebtn").forEach(function(b){b.textContent=d?"Heller Modus":"Dunkler Modus";});}'
       . 'document.querySelectorAll(".bx-themebtn").forEach(function(b){b.addEventListener("click",function(e){e.preventDefault();'
       . 'var d=r.getAttribute("data-theme")==="dark";var t=d?"light":"dark";r.setAttribute("data-theme",t);'
       . 'try{localStorage.setItem("bx-theme",t);}catch(err){}lbl();});});lbl();})();</script>';
    // Menue-Schublade fuers Handy - gleiches Verhalten wie im Dashboard.
    echo '<script>(function(){var r=document.documentElement,'
       . 'b=document.getElementById("bx-burger"),s=document.getElementById("bx-menuescrim");'
       . 'function zu(){r.removeAttribute("data-menue");if(b)b.setAttribute("aria-expanded","false");}'
       . 'function auf(){r.setAttribute("data-menue","auf");if(b)b.setAttribute("aria-expanded","true");}'
       . 'if(b)b.addEventListener("click",function(){r.getAttribute("data-menue")==="auf"?zu():auf();});'
       . 'if(s)s.addEventListener("click",zu);'
       . 'document.addEventListener("keydown",function(e){if(e.key==="Escape")zu();});'
       . 'document.querySelectorAll(".bx-side nav a").forEach(function(a){a.addEventListener("click",zu);});'
       . 'addEventListener("resize",function(){if(innerWidth>860)zu();});})();</script>';
    echo '<script>if("serviceWorker" in navigator){window.addEventListener("load",function(){'
       . 'navigator.serviceWorker.register("sw.js").catch(function(){});});}</script>';
    echo '</body></html>';
}

// Wie viele warten auf mich? Steht als Zahl am Menuepunkt. Faellt der Zusammenzug aus (Datenbank
// kurz weg), zeigen wir lieber nichts als eine Fehlermeldung im Menue.
function crm_wartet_zahl(): int {
    static $n = null;
    if ($n !== null) return $n;
    try { require_once __DIR__ . '/wartet.php'; $n = count(wartet_zeilen('sie')); }
    catch (Throwable $e) { $n = 0; }
    return $n;
}

// Ueberschrift einer Seite, optional mit Knoepfen rechts - wie bx_head() im Dashboard.
function seitenkopf(string $titel, string $unter = '', string $aktion = ''): void {
    echo '<div class="bx-head"><div><h1>' . h($titel) . '</h1>';
    if ($unter !== '') echo '<p class="bx-sub">' . h($unter) . '</p>';
    echo '</div>';
    if ($aktion !== '') echo '<div class="bx-row">' . $aktion . '</div>';
    echo '</div>';
}

function hinweis(string $text, string $art = 'ok'): void {
    $stil = $art === 'warn'
        ? 'border-color:#e6c4c0;color:#8f231b'
        : 'border-color:var(--gruen)';
    echo '<div class="bx-panel" style="padding:12px 16px;' . $stil . '">' . h($text) . '</div>';
}
