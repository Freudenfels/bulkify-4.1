<?php
// Rahmen jeder Seite – im Aussehen des Dashboards (lädt dasselbe /assets/app.css, dieselben Klassen).
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/auth.php';

function pr_nav(): array {
    $nav = [
        'Produktion' => ['liste' => 'Produktionsaufträge'],
    ];
    // Weitere Punkte (Geführte Produktion, Chargen, Bericht …) ergänzt der Produktions-Chat hier.
    return $nav;
}

function kopf(string $titel, string $aktiv = ''): void {
    $u = pr_benutzer();
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
       . '<title>' . h($titel !== '' ? $titel . ' – ' . BX_MARKE . ' ' . BX_TITEL : BX_MARKE . ' ' . BX_TITEL) . '</title>'
       . '<link rel="stylesheet" href="/assets/app.css">'
       . '<link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">'
       . '<meta name="theme-color" content="#10210F">'
       . '<script>(function(){try{var t=localStorage.getItem("bx-theme");'
       . 'if(t==="dark"||t==="light")document.documentElement.setAttribute("data-theme",t);'
       . 'var w=parseInt(localStorage.getItem("bx-side-w"),10);'
       . 'if(w>=180&&w<=420)document.documentElement.style.setProperty("--side-w",w+"px");}catch(e){}})();</script>'
       . '</head><body><div class="bx-shell">';

    if ($u) {
        echo '<aside class="bx-side">'
           . '<div class="bx-brand"><img src="/assets/bulkify-logo-white.png" alt="' . h(BX_MARKE) . '" class="bx-logo">'
           . '<span class="bx-ver">' . h(BX_TITEL) . '</span></div><nav>';
        foreach (pr_nav() as $gruppe => $seiten) {
            echo '<div class="bx-navgroup">' . h($gruppe) . '</div>';
            foreach ($seiten as $route => $label)
                echo '<a href="?p=' . h($route) . '"' . ($aktiv === $route ? ' class="on"' : '') . '><span>' . h($label) . '</span></a>';
        }
        echo '<div class="bx-navgroup">Dashboard</div><a href="' . h(erp_dashboard_url()) . '"><span>Zum Dashboard</span></a>';
        echo '<div class="bx-userbox">'
           . '<div class="bx-username">' . h((string)$u['name']) . '</div>'
           . '<div class="bx-userroles">' . h((string)$u['email']) . '</div>'
           . '<a class="bx-logout" href="?p=logout">Abmelden</a>'
           . '<button type="button" class="bx-themebtn">Dunkler Modus</button>'
           . '</div></nav></aside><div class="bx-menuescrim" id="bx-menuescrim"></div>';
    }

    echo '<main class="bx-main">';
    if ($u) {
        echo '<div class="bx-mobilbar">'
           . '<button type="button" class="bx-burger" id="bx-burger" aria-label="Menü" aria-expanded="false"><span></span><span></span><span></span></button>'
           . '<img src="/assets/bulkify-logo-white.png" alt="bulkify" class="bx-logo"></div>';
    }
    flash_zeigen();
}

function fuss(): void {
    echo '</main></div>';
    echo '<script>(function(){var r=document.documentElement;'
       . 'function lbl(){var d=r.getAttribute("data-theme")==="dark";'
       . 'document.querySelectorAll(".bx-themebtn").forEach(function(b){b.textContent=d?"Heller Modus":"Dunkler Modus";});}'
       . 'document.querySelectorAll(".bx-themebtn").forEach(function(b){b.addEventListener("click",function(e){e.preventDefault();'
       . 'var d=r.getAttribute("data-theme")==="dark";var t=d?"light":"dark";r.setAttribute("data-theme",t);'
       . 'try{localStorage.setItem("bx-theme",t);}catch(err){}lbl();});});lbl();})();</script>';
    echo '<script>(function(){var r=document.documentElement,'
       . 'b=document.getElementById("bx-burger"),s=document.getElementById("bx-menuescrim");'
       . 'function zu(){r.removeAttribute("data-menue");if(b)b.setAttribute("aria-expanded","false");}'
       . 'function auf(){r.setAttribute("data-menue","auf");if(b)b.setAttribute("aria-expanded","true");}'
       . 'if(b)b.addEventListener("click",function(){r.getAttribute("data-menue")==="auf"?zu():auf();});'
       . 'if(s)s.addEventListener("click",zu);'
       . 'document.addEventListener("keydown",function(e){if(e.key==="Escape")zu();});'
       . 'document.querySelectorAll(".bx-side nav a").forEach(function(a){a.addEventListener("click",zu);});'
       . 'addEventListener("resize",function(){if(innerWidth>860)zu();});})();</script>';
    echo '</body></html>';
}
