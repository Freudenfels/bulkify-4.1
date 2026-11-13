<?php
// Rahmen jeder Seite – im Aussehen des Dashboards (lädt dasselbe /assets/app.css, dieselben Klassen).
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/auth.php';

// Menü der Produktions-App (Nico 08.11.2026, wie die Lager-App): EINE flache Liste ohne Gruppen, größer, mit Icons.
// Darunter, kleiner und abgesetzt: Archiv, Einstellungen, Werk-App, Zum Dashboard (+ Testdaten nur Admin).
function pr_nav(): array {
    // Ein Weg (11.11.2026): „Produzieren“ = Werk-App, „Plan“ = Reiter Plan im Dashboard (modus/run/liste/kalender leiten weiter).
    return ['dash' => 'Übersicht', 'werk' => 'Produzieren', 'plan' => 'Plan', 'reinigung' => 'Reinigung', 'anleitungen' => 'Anleitungen'];
}
function pr_nav_klein(): array {
    return ['archiv' => 'Archiv', 'einstellungen' => 'Einstellungen'];
}
// Linien-Icons (Stil Lucide, Inline-SVG in currentColor) – gleiche Art wie Lager-App und Dashboard-Menü „Einfach".
function pr_nav_icon(string $key): string {
    static $i = [
        'dash'        => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'werk'        => '<circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/>',
        'plan'        => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
        'modus'       => '<circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/>',
        'liste'       => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
        'kalender'    => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
        'reinigung'   => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>',
        'anleitungen' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
    ];
    return isset($i[$key]) ? '<svg class="pr-navico" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $i[$key] . '</svg>' : '';
}

function kopf(string $titel, string $aktiv = ''): void {
    $u = pr_benutzer();
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
       . '<title>' . h($titel !== '' ? $titel . ' – ' . BX_MARKE . ' ' . BX_TITEL : BX_MARKE . ' ' . BX_TITEL) . '</title>'
       . '<link rel="stylesheet" href="/assets/app.css"><script src="/assets/bx-ui.js?v=20261113" defer></script>'
       . '<link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">'
       // Menü flach und groß (08.11.2026) – direkt hier, die Produktions-App hat keine eigene CSS-Datei (kein Cache-Problem).
       . '<style>.bx-side nav .pr-navflat a{justify-content:flex-start;gap:12px;font-size:16px;padding:12px var(--sp-5);line-height:1.25}'
       . '.pr-navflat .pr-navico{flex:0 0 20px;width:20px;height:20px;opacity:.9}.pr-navflat a.on .pr-navico{opacity:1}'
       . '.pr-navflat .pr-navlbl{flex:1 1 auto;min-width:0;text-align:left}'
       . '.pr-navklein{margin-top:10px;padding-top:8px;border-top:1px solid rgba(255,255,255,.1)}'
       . '.bx-side nav .pr-navklein a{justify-content:flex-start;font-size:13px;padding:6px var(--sp-5);color:#b9c6b4}.bx-side nav .pr-navklein a.on{color:#fff}'
       . '.pr-sub{font-size:12px;margin-top:2px;font-weight:400}.pr-prod{white-space:normal;min-width:130px}.pr-nur-handy{display:none}'
       . '@media (max-width:640px){.pr-wann{display:none}.pr-nur-handy{display:block}}'
       . '@media (max-width:860px){.bx-side nav .pr-navflat a{font-size:17px;padding:14px var(--sp-5)}}</style>'
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
        echo '<div class="pr-navflat">';
        foreach (pr_nav() as $route => $label)
            echo '<a href="' . ($route === 'plan' ? '/?p=produktion&amp;tab=plan' : '?p=' . h($route)) . '"' . ($aktiv === $route ? ' class="on"' : '') . ' style="justify-content:flex-start">'
               . pr_nav_icon($route) . '<span class="pr-navlbl">' . h($label) . '</span></a>';
        echo '</div><div class="pr-navklein">';
        foreach (pr_nav_klein() as $route => $label)
            echo '<a href="?p=' . h($route) . '"' . ($aktiv === $route ? ' class="on"' : '') . '><span>' . h($label) . '</span></a>';
        echo '<a href="' . h(erp_dashboard_url()) . '"><span>Zum Dashboard</span></a>';
        if (pr_ist_admin()) echo '<a href="' . h(erp_dashboard_url()) . '?p=testdaten"><span>Testdaten (Durchspiel)</span></a>';
        echo '</div>';
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
