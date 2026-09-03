<?php
// Wiederverwendbares Layout bulkify 4.1
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pwa.php';   // Handy-App: manifest + Service Worker

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Zeit immer UTC gespeichert, Anzeige immer Europe/Berlin
function fmt_zeit(?string $utc, string $fmt = 'd.m.Y H:i'): string {
    if (!$utc) return '';
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
        return $dt->format($fmt);
    } catch (Exception $e) { return $utc; }
}

// Navigation: eine zentrale Definition (Bereich => [Seiten])
function bx_nav(): array {
    return [
        'Start'        => ['dashboard' => 'Dashboard'],
        'Vertrieb'     => ['kunden' => 'Kunden', 'partner' => 'Partner', 'angebote' => 'Angebote', 'auftraege' => 'Aufträge'],
        'Anfragen'     => [
            'anfragen'           => 'Rezepturanfragen',
            'paf_produkt'        => ['label'=>'Produktanfragen',        'route'=>'portal_anfragen', 'href'=>'?p=portal_anfragen&typ=produkt',        'typ'=>'produkt'],
            'paf_rohstoff'       => ['label'=>'Rohstoffanfragen',       'route'=>'portal_anfragen', 'href'=>'?p=portal_anfragen&typ=rohstoff',       'typ'=>'rohstoff'],
            'paf_dienstleistung' => ['label'=>'Dienstleistungsanfragen', 'route'=>'portal_anfragen', 'href'=>'?p=portal_anfragen&typ=dienstleistung', 'typ'=>'dienstleistung'],
        ],
        'Produkt'      => ['rezeptur' => 'Rezepturen', 'produkte' => 'Produkte'],
        'Produktion'   => ['produktion' => 'Produktion', 'kalender' => 'Kalender', 'aufgaben' => 'Aufgaben', 'versand' => 'Versand'],
        'Lager'        => ['lager' => 'Warenlager', 'lager2' => 'Fremdlager', 'wareneingang' => 'Wareneingang', 'rohstoffe' => 'Rohstoffe', 'verpackungen' => 'Verpackungen', 'naehrstoffe' => 'Nährstoffe (NRV)'],
        'Einkauf'      => ['bedarf' => 'Einkaufsbedarf', 'einkaufsliste' => 'Einkaufsliste', 'einkauf' => 'Bestellungen', 'lieferanten' => 'Lieferanten'],
        'Buchhaltung'  => ['rechnungen' => 'Rechnungen', 'buchhaltung' => 'Belege'],
        'System'       => ['einstellungen' => 'Einstellungen', 'benutzer' => 'Benutzer', 'app' => 'App aufs Handy'],
    ];
}

// Zähler offener Anfragen je Nav-Punkt (offen = noch nicht beantwortet/abgelehnt), wie ungelesene Mails
function bx_anfrage_counts(): array {
    $c = ['anfragen'=>0,'paf_produkt'=>0,'paf_rohstoff'=>0,'paf_dienstleistung'=>0];
    if (!function_exists('scalar')) return $c;
    try {
        $c['anfragen'] = (int) scalar("SELECT COUNT(*) FROM rezeptur_anfrage WHERE status NOT IN ('beantwortet','abgelehnt')");
        foreach (all("SELECT typ, COUNT(*) c FROM portal_anfrage WHERE status NOT IN ('beantwortet','abgelehnt') GROUP BY typ") as $r) {
            $k = ['produkt'=>'paf_produkt','rohstoff'=>'paf_rohstoff','dienstleistung'=>'paf_dienstleistung'][$r['typ']] ?? null;
            if ($k) $c[$k] = (int) $r['c'];
        }
    } catch (Throwable $e) { /* Tabelle evtl. noch nicht da */ }
    return $c;
}

// Eigenes, schlankes Menü für den Werk-Bereich (Produktionsmitarbeiter): nur Produktion, Warenwirtschaft, Entwicklung.
// Kein Verkauf, keine Kunden-/Angebots-/Rechnungs-Punkte. Reine Anzeige-Sicht; Rechte greifen zusätzlich über route_erlaubt().
function bx_nav_werk(): array {
    return [
        'Start'            => ['werk' => 'Cockpit', 'aufgaben' => 'Aufgaben'],
        'Produktion'       => ['produktion' => 'Produktionsaufträge', 'kalender' => 'Kalender'],
        'Warenwirtschaft'  =>['bedarf' => 'Einkaufsbedarf', 'lager' => 'Bestand', 'lager2' => 'Fremdlager', 'wareneingang' => 'Wareneingang', 'chargen' => 'Chargen',
                               'rohstoffe' => 'Rohstoffe', 'verpackungen' => 'Verpackungen', 'naehrstoffe' => 'Nährstoffe (NRV)',
                               'versand' => 'Versand'],
        'Entwicklung'      => ['rezeptur' => 'Rezepturen', 'anfragen' => 'Rezepturanfragen'],
    ];
}

function render_header(string $aktiv = 'dashboard', string $titel = ''): void {
    $marke = BX_MARKE; $ver = BX_VERSION;
    echo "<!doctype html><html lang=\"de\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
    echo "<title>" . h($titel ? "$titel – $marke $ver" : "$marke $ver") . "</title>";
    echo "<link rel=\"stylesheet\" href=\"assets/app.css\">";
    echo pwa_head();
    echo "<script>(function(){try{var t=localStorage.getItem('bx-theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>";
    // Menübreite + Einklapp-Zustand VOR dem Rendern setzen, sonst springt die Leiste beim Laden kurz.
    echo "<script>(function(){try{var r=document.documentElement;"
       . "var w=parseInt(localStorage.getItem('bx-side-w'),10);"
       . "if(w>=180&&w<=420)r.style.setProperty('--side-w',w+'px');"
       . "if(localStorage.getItem('bx-side-zu')==='1')r.setAttribute('data-side','zu');}catch(e){}})();</script>";
    echo "</head><body>";
    echo "<div class=\"bx-shell\">";
    // Werk-Bereich (Produktionsmitarbeiter): eigenes Menü + eigene Marke.
    $istWerk = function_exists('ist_produktionsbereich') && ist_produktionsbereich();
    $verLabel = $istWerk ? 'Werk' : $ver;
    $navdef   = $istWerk ? bx_nav_werk() : bx_nav();
    // Sidebar
    echo "<aside class=\"bx-side\"><div class=\"bx-brand\"><img src=\"assets/bulkify-logo-white.png\" alt=\"$marke\" class=\"bx-logo\"><span class=\"bx-ver\">" . h($verLabel) . "</span></div><nav>";
    $darf = function_exists('route_erlaubt');   // Auth aktiv?
    $curTyp = $_GET['typ'] ?? null;
    $anfCount = bx_anfrage_counts();
    if (function_exists('aufgabe_offen_zahl') && function_exists('current_user') && ($cu = current_user()))
        $anfCount['aufgaben'] = aufgabe_offen_zahl((int)$cu['id']);
    // Einkaufs-Zähler: offener Einkaufsbedarf (noch nicht gemeldet) + Einkaufsliste (Positionen zu bestellen)
    if (function_exists('scalar')) {
        try {
            $anfCount['bedarf'] = (int) scalar("SELECT COUNT(*) FROM produktionsauftrag WHERE status IN ('offen','laufend') AND auftrag_id IS NOT NULL AND bedarf_gemeldet IS NULL");
            $anzGem = (int) scalar("SELECT COUNT(*) FROM produktionsauftrag WHERE status IN ('offen','laufend') AND bedarf_gemeldet IS NOT NULL");
            $ekl = 0;
            if ($anzGem > 0 && function_exists('bedarf_aggregiert')) {
                foreach (bedarf_aggregiert(true) as $a) if ($a['zu_bestellen'] > 1e-6) $ekl++;
                foreach (bedarf_bulk(true) as $b) if ($b['zu_bestellen'] > 1e-6) $ekl++;
            }
            $anfCount['einkaufsliste'] = $ekl;
        } catch (Throwable $e) { /* Tabellen evtl. noch nicht da */ }
    }
    foreach ($navdef as $gruppe => $seiten) {
        // Nur erlaubte Einträge der Gruppe (Eintrag = Label-String oder Array mit route/href/typ)
        $sichtbar = [];
        foreach ($seiten as $key => $val) {
            $route = is_array($val) ? $val['route'] : $key;
            if (!$darf || route_erlaubt($route)) $sichtbar[$key] = $val;
        }
        if (!$sichtbar) continue;   // leere Gruppe überspringen
        echo "<div class=\"bx-navgroup\">" . h($gruppe) . "</div>";
        foreach ($sichtbar as $key => $val) {
            if (is_array($val)) { $route = $val['route']; $label = $val['label']; $href = $val['href']; $typ = $val['typ'] ?? null; }
            else               { $route = $key; $label = $val; $href = '?p=' . $key; $typ = null; }
            $on = ($route === $aktiv) && ($typ === null || $curTyp === $typ);
            $cls = $on ? ' class="on"' : '';
            $n = $anfCount[$key] ?? 0;
            $badgeTitel = match ($key) {
                'aufgaben'      => "$n offene Aufgaben",
                'bedarf'        => "$n Aufträge mit offenem Einkaufsbedarf (noch nicht gemeldet)",
                'einkaufsliste' => "$n Positionen zu bestellen",
                default         => "$n offene Anfragen",
            };
            $badge = $n > 0 ? "<span class=\"bx-navbadge\" title=\"$badgeTitel\">$n</span>" : '';
            echo "<a href=\"" . h($href) . "\"$cls><span>" . h($label) . "</span>$badge</a>";
        }
    }
    // Benutzer-Fuß: Name + Rollen + Abmelden
    if (function_exists('current_user') && ($u = current_user())) {
        $rollen = function_exists('rollen_liste') ? rollen_liste() : [];
        $meine = array_map(fn($r) => $rollen[$r] ?? $r, user_rollen());
        echo "<div class=\"bx-userbox\">"
           . "<div class=\"bx-username\">" . h($u['name']) . "</div>"
           . "<div class=\"bx-userroles\">" . h(implode(' · ', $meine) ?: 'keine Rolle') . "</div>"
           . "<a class=\"bx-logout\" href=\"?p=logout\">Abmelden</a>"
           . "<button type=\"button\" class=\"bx-themebtn\">Dunkler Modus</button>"
           . "<button type=\"button\" class=\"bx-sidebtn\">Menü einklappen</button></div>";
    } else {
        echo "<div class=\"bx-userbox\"><button type=\"button\" class=\"bx-themebtn\">Dunkler Modus</button>"
           . "<button type=\"button\" class=\"bx-sidebtn\">Menü einklappen</button></div>";
    }
    echo "</nav></aside>";
    // Ziehgriff an der Kante (Breite) + Knopf zum Wiederaufklappen (nur sichtbar, wenn die Leiste zu ist).
    echo "<div class=\"bx-sidegriff\" tabindex=\"0\" role=\"separator\" aria-orientation=\"vertical\""
       . " title=\"Breite ziehen · Doppelklick setzt zurück · Pfeiltasten verstellen\"></div>";
    echo "<button type=\"button\" class=\"bx-sideauf\" title=\"Menü aufklappen\">Menü</button>";
    // Hauptbereich
    echo "<main class=\"bx-main\">";
}

function render_footer(): void {
    echo "</main></div>";
    echo bx_theme_script();
    echo bx_side_scroll_script();
    echo bx_side_script();
    echo pwa_script();
    echo "</body></html>";
}

// Merkt sich die Scroll-Position der Sidebar über Seitenwechsel (sonst springt das Menü bei jedem Klick nach oben).
function bx_side_scroll_script(): string {
    return "<script>(function(){var el=document.querySelector('.bx-side');if(!el)return;"
        . "try{var y=sessionStorage.getItem('bx-side-scroll');if(y!==null)el.scrollTop=parseInt(y,10)||0;}catch(e){}"
        . "var t;el.addEventListener('scroll',function(){try{clearTimeout(t);t=setTimeout(function(){sessionStorage.setItem('bx-side-scroll',el.scrollTop);},80);}catch(e){}});"
        . "el.querySelectorAll('a[href]').forEach(function(a){a.addEventListener('click',function(){try{sessionStorage.setItem('bx-side-scroll',el.scrollTop);}catch(e){}});});"
        . "})();</script>";
}

// Wiring für die Menüleiste: Breite ziehen (.bx-sidegriff) + Ein-/Ausklappen (.bx-sidebtn / .bx-sideauf).
// Gemerkt wird beides in localStorage (bx-side-w / bx-side-zu), gesetzt wird es schon im <head>.
function bx_side_script(): string {
    return "<script>(function(){var r=document.documentElement,g=document.querySelector('.bx-sidegriff');"
        . "var MIN=180,MAX=420,STD=224;"
        . "function breite(w){w=Math.max(MIN,Math.min(MAX,Math.round(w)));r.style.setProperty('--side-w',w+'px');"
        . "try{localStorage.setItem('bx-side-w',w);}catch(e){}return w;}"
        . "function jetzt(){return parseInt(getComputedStyle(r).getPropertyValue('--side-w'),10)||STD;}"
        . "function zu(v){if(v){r.setAttribute('data-side','zu');}else{r.removeAttribute('data-side');}"
        . "try{localStorage.setItem('bx-side-zu',v?'1':'0');}catch(e){}}"
        . "if(g){g.addEventListener('mousedown',function(e){e.preventDefault();g.classList.add('aktiv');"
        . "document.body.classList.add('bx-zieht');"
        . "function mv(ev){breite(ev.clientX);}"
        . "function up(){g.classList.remove('aktiv');document.body.classList.remove('bx-zieht');"
        . "document.removeEventListener('mousemove',mv);document.removeEventListener('mouseup',up);}"
        . "document.addEventListener('mousemove',mv);document.addEventListener('mouseup',up);});"
        . "g.addEventListener('dblclick',function(){breite(STD);});"
        . "g.addEventListener('keydown',function(e){var s=e.shiftKey?40:10;"
        . "if(e.key==='ArrowLeft'){e.preventDefault();breite(jetzt()-s);}"
        . "else if(e.key==='ArrowRight'){e.preventDefault();breite(jetzt()+s);}"
        . "else if(e.key==='Home'){e.preventDefault();breite(STD);}});}"
        . "document.querySelectorAll('.bx-sidebtn').forEach(function(b){b.addEventListener('click',function(e){e.preventDefault();zu(true);});});"
        . "document.querySelectorAll('.bx-sideauf').forEach(function(b){b.addEventListener('click',function(e){e.preventDefault();zu(false);});});"
        . "})();</script>";
}

// Wiring für den Dark-Mode-Umschalter (Button .bx-themebtn). Gemeinsam für intern + Portal.
function bx_theme_script(): string {
    return "<script>(function(){var r=document.documentElement;"
        // Die Beschriftung darf je Seite anders heissen (das Lieferantenportal spricht drei Sprachen).
        . "function lbl(){var d=r.getAttribute('data-theme')==='dark';document.querySelectorAll('.bx-themebtn').forEach(function(b){b.textContent=d?(b.dataset.hell||'Heller Modus'):(b.dataset.dunkel||'Dunkler Modus');});}"
        . "document.querySelectorAll('.bx-themebtn').forEach(function(b){b.addEventListener('click',function(e){e.preventDefault();var d=r.getAttribute('data-theme')==='dark';var t=d?'light':'dark';r.setAttribute('data-theme',t);try{localStorage.setItem('bx-theme',t);}catch(err){}lbl();});});lbl();})();</script>";
}
