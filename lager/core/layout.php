<?php
// Rahmen jeder Seite - im Aussehen des Dashboards.
//
// Kein eigenes Aussehen: Das Lager laedt dasselbe Stylesheet wie das Dashboard (`/assets/app.css`)
// und benutzt dieselben Klassen. Die wenigen Ergaenzungen stehen in `assets/lager.css` (alle mit
// `lg-` davor). Dunkler Modus: dieselbe Speicherstelle `bx-theme` wie Dashboard und CRM.
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/auth.php';

// Menü der Lager-App (Nico 08.11.2026): EINE flache Liste ohne Gruppen, größer, mit Icons – wie das Dashboard-Menü „Einfach".
// Die Hauptpunkte führen auf Kachel-Seiten (lager/module/kacheln.php bzw. ?p=we), die die vorhandenen Seiten bündeln.
// Darunter, kleiner und abgesetzt: Bewegungen, Mülleimer, Einstellungen (nur Admin), Zum Dashboard.
// Nicht mehr im Menü (Seiten bleiben erreichbar): Blinker, Erwartete Lieferungen, Kundenware einbuchen, Lager-2-Suche.
function lg_nav(): array {
    return ['uebersicht' => 'Übersicht', 'finden' => 'Suchen', 'we' => 'Einbuchen', 'umbuchen' => 'Umbuchen',
            'versand_start' => 'Versand', 'lager1' => 'Lager 1', 'lager2' => 'Lager 2'];
}
function lg_nav_klein(): array {
    $k = ['bewegungen' => 'Bewegungen', 'papierkorb' => 'Mülleimer'];
    if (function_exists('lg_darf_inventur') && lg_darf_inventur()) $k['inventur'] = 'Inventur';   // 11.11.2026, nur Admin + Lager
    if (lg_ist_admin()) $k['einstellungen'] = 'Einstellungen';
    return $k;
}
// Welcher Menüpunkt ist auf dieser Seite markiert? Unterseiten zählen zu ihrer Kachel-Seite.
function lg_nav_aktiv(string $aktiv): string {
    static $zu = [
        'erwartet' => 'we', 'l2_eingang' => 'we', 'eingang' => 'we',
        'einlagern' => 'umbuchen', 'umlagern' => 'umbuchen', 'kisten' => 'umbuchen', 'kiste' => 'umbuchen',
        'versand_bereit' => 'versand_start', 'versand' => 'versand_start', 'versand_detail' => 'versand_start', 'lieferschein_neu' => 'versand_start', 'ausgang' => 'versand_start',
        'bestand' => 'lager1', 'quarantaene' => 'lager1',
        'l2_bestand' => 'lager2', 'l2_artikel' => 'lager2', 'l2_artikel_edit' => 'lager2',
        'l2_finden' => 'finden', 'leisten' => 'finden',
        'inventur_neu' => 'inventur', 'inventur_lauf' => 'inventur', 'inventur_zaehlen' => 'inventur', 'inventur_auswerten' => 'inventur', 'plaetze' => 'inventur', 'tara' => 'inventur',
    ];
    return $zu[$aktiv] ?? $aktiv;
}
// Linien-Icons (Stil Lucide, Inline-SVG in currentColor) – truck/warehouse wie im Dashboard-Menü „Einfach".
function lg_nav_icon(string $key): string {
    static $i = [
        'uebersicht'    => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'finden'        => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'we'            => '<path d="M16 16h6"/><path d="M19 13v6"/><path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/><path d="m7.5 4.27 9 5.15"/><path d="M3.29 7 12 12l8.71-5"/><path d="M12 22V12"/>',
        'umbuchen'      => '<path d="M8 3 4 7l4 4"/><path d="M4 7h16"/><path d="m16 21 4-4-4-4"/><path d="M20 17H4"/>',
        'versand_start' => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
        'lager1'        => '<path d="M22 8.35V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8.35A2 2 0 0 1 3.26 6.5l8-3.2a2 2 0 0 1 1.48 0l8 3.2A2 2 0 0 1 22 8.35Z"/><path d="M6 18h12"/><path d="M6 14h12"/><rect width="12" height="12" x="6" y="10"/>',
        'lager2'        => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/>',
    ];
    return isset($i[$key]) ? '<svg class="lg-navico" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $i[$key] . '</svg>' : '';
}

// Versions-Anhang für Stil-/Skriptdateien (Änderungszeit): Nach jedem Deploy lädt der Browser die neue Datei,
// statt eine alte aus dem Zwischenspeicher zu nehmen (08.11.2026: neues Menü sah mit alter lager.css schief aus).
function lg_asset_v(string $datei): string {
    $f = __DIR__ . '/../../public/lager/assets/' . basename($datei);
    return is_file($f) ? (string) filemtime($f) : '1';
}

function kopf(string $titel, string $aktiv = ''): void {
    $GLOBALS['lg_aktiv'] = $aktiv;   // fuer seitenkopf(): auf welcher Seite sind wir (Einbuchen-Knopf)
    $u = lg_benutzer();
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
       . '<title>' . h($titel !== '' ? $titel . ' – ' . BX_MARKE . ' ' . BX_TITEL : BX_MARKE . ' ' . BX_TITEL) . '</title>'
       . '<link rel="stylesheet" href="/assets/app.css"><script src="/assets/bx-ui.js?v=20261114a" defer></script>'
       . '<link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">'
       . '<link rel="icon" href="/assets/icons/favicon.ico" sizes="any">'
       . '<link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png">'
       . '<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">'
       . '<link rel="manifest" href="manifest.webmanifest">'
       . '<meta name="mobile-web-app-capable" content="yes">'
       . '<meta name="apple-mobile-web-app-capable" content="yes">'
       . '<meta name="apple-mobile-web-app-title" content="Lager">'
       . '<link rel="stylesheet" href="assets/lager.css?v=' . lg_asset_v('lager.css') . '">'
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
        $navAktiv = lg_nav_aktiv($aktiv);
        $quarN = function_exists('lg_quarantaene_zahl') ? lg_quarantaene_zahl() : 0;
        echo '<div class="lg-navflat">';
        foreach (lg_nav() as $route => $label) {
            $badge = ($route === 'lager1' && $quarN > 0) ? '<span class="bx-navbadge lg-navbadge" title="' . $quarN . ' Charge(n) in Quarantäne">' . $quarN . '</span>' : '';
            echo '<a href="?p=' . h($route) . '"' . ($navAktiv === $route ? ' class="on"' : '') . ' style="justify-content:flex-start">'
               . lg_nav_icon($route) . '<span class="lg-navlbl" style="flex:1 1 auto;text-align:left">' . h($label) . '</span>' . $badge . '</a>';
        }
        echo '</div><div class="lg-navklein">';
        foreach (lg_nav_klein() as $route => $label)
            echo '<a href="?p=' . h($route) . '"' . ($navAktiv === $route ? ' class="on"' : '') . '><span>' . h($label) . '</span></a>';
        echo '<a href="' . h(erp_dashboard_url()) . '"><span>Zum Dashboard</span></a></div>';

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
    echo '<script src="assets/busy.js?v=' . lg_asset_v('busy.js') . '" defer></script>';
    echo '<script src="assets/lager.js?v=' . lg_asset_v('lager.js') . '" defer></script>';
    echo '<script src="assets/voice.js?v=' . lg_asset_v('voice.js') . '" defer></script>';
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
