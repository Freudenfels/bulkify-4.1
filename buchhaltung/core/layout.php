<?php
// Layout des Buchhaltungs-Programms: gleiche HTML-Hülle + bx-Klassen wie das Dashboard (lädt dasselbe
// /assets/app.css), aber eigene, schlanke Buchhaltungs-Navigation und eigene Sitzung. Die JS-Helfer
// (Busy-Overlay, Dark-Mode, Menü) sind verbatim aus dem Dashboard übernommen.
require_once __DIR__ . '/db.php';

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_zeit(?string $utc, string $fmt = 'd.m.Y H:i'): string {
    if (!$utc) return '';
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
        return $dt->format($fmt);
    } catch (Exception $e) { return $utc; }
}

// Eigene Navigation der Buchhaltung. Schlüssel = $aktiv-Wert, den die Seiten an render_header() geben.
function bu_nav(): array {
    return [
        'Buchhaltung' => [
            'buchhaltung'       => ['label' => 'Übersicht',           'href' => '?p=buchhaltung'],
            'rechnungen'        => ['label' => 'Rechnungen',          'href' => '?p=rechnungen'],
            'angebote_ansicht'  => ['label' => 'Angebote (Ansicht)',  'href' => '?p=angebote_ansicht'],
            'rechnung_frei'     => ['label' => 'Rechnung erstellen',  'href' => '?p=rechnung_frei'],
            'lief_rechnung_neu' => ['label' => 'Eingangsrechnung',    'href' => '?p=lief_rechnung_neu'],
            'rechnung_import'   => ['label' => 'Alt-Rechnungen',      'href' => '?p=rechnung_import'],
            'auftrag_import'    => ['label' => 'Auftrag importieren', 'href' => '?p=auftrag_import'],
            'gutschrift_neu'    => ['label' => 'Storno/Gutschrift',   'href' => '?p=gutschrift_neu'],
        ],
    ];
}

function render_header(string $aktiv = 'buchhaltung', string $titel = ''): void {
    $marke = BX_MARKE; $ver = BX_VERSION;
    $cssmt = (int) @filemtime(dirname(BX_ROOT) . '/public/assets/app.css');
    echo "<!doctype html><html lang=\"de\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
    echo "<title>" . h($titel ? "$titel – $marke $ver" : "$marke $ver") . "</title>";
    echo "<link rel=\"stylesheet\" href=\"/assets/app.css?v=$cssmt\">";
    echo "<script>(function(){try{var t=localStorage.getItem('bx-theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>";
    echo "<script>(function(){try{var r=document.documentElement;"
       . "var w=parseInt(localStorage.getItem('bx-side-w'),10);"
       . "if(w>=180&&w<=420)r.style.setProperty('--side-w',w+'px');"
       . "if(localStorage.getItem('bx-side-zu')==='1')r.setAttribute('data-side','zu');}catch(e){}})();</script>";
    echo "</head><body>";
    echo "<div class=\"bx-shell\">";
    echo "<aside class=\"bx-side\"><div class=\"bx-brand\"><img src=\"/assets/bulkify-logo-white.png\" alt=\"$marke\" class=\"bx-logo\"><span class=\"bx-ver\">" . h($ver) . "</span></div><nav>";
    foreach (bu_nav() as $gruppe => $seiten) {
        echo "<div class=\"bx-navgroup\">" . h($gruppe) . "</div>";
        foreach ($seiten as $key => $val) {
            $label = $val['label']; $href = $val['href'];
            $cls = ($key === $aktiv) ? ' class="on"' : '';
            echo "<a href=\"" . h($href) . "\"$cls><span>" . h($label) . "</span></a>";
        }
    }
    // Zurück ins Dashboard (eigene Programme stehen dort unter „Unterseiten").
    echo "<div class=\"bx-navgroup\">Dashboard</div>";
    echo "<a href=\"/\"><span>Zurück zum Dashboard</span></a>";
    // Benutzer-Fuß
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
    echo "<div class=\"bx-sidegriff\" tabindex=\"0\" role=\"separator\" aria-orientation=\"vertical\" title=\"Breite ziehen\"></div>";
    echo "<button type=\"button\" class=\"bx-sideauf\" title=\"Menü aufklappen\">Menü</button>";
    echo bu_menue_scrim();
    echo "<main class=\"bx-main\">" . bu_mobilbar();
}

function bu_mobilbar(): string {
    return '<div class="bx-mobilbar">'
         . '<button type="button" class="bx-burger" id="bx-burger" aria-label="Menü" aria-expanded="false">'
         . '<span></span><span></span><span></span></button>'
         . '<img src="/assets/bulkify-logo-white.png" alt="bulkify" class="bx-logo">'
         . '</div>';
}
function bu_menue_scrim(): string { return '<div class="bx-menuescrim" id="bx-menuescrim"></div>'; }

function render_footer(): void {
    echo "</main></div>";
    echo bu_theme_script();
    echo bu_side_script();
    echo bu_menue_script();
    echo bu_busy_script();
    echo "</body></html>";
}

function bu_menue_script(): string {
    return "<script>(function(){var r=document.documentElement,"
         . "b=document.getElementById('bx-burger'),s=document.getElementById('bx-menuescrim');"
         . "function zu(){r.removeAttribute('data-menue');if(b)b.setAttribute('aria-expanded','false');}"
         . "function auf(){r.setAttribute('data-menue','auf');if(b)b.setAttribute('aria-expanded','true');}"
         . "if(b)b.addEventListener('click',function(){r.getAttribute('data-menue')==='auf'?zu():auf();});"
         . "if(s)s.addEventListener('click',zu);"
         . "document.addEventListener('keydown',function(e){if(e.key==='Escape')zu();});"
         . "document.querySelectorAll('.bx-side nav a').forEach(function(a){a.addEventListener('click',zu);});"
         . "addEventListener('resize',function(){if(innerWidth>860)zu();});})();</script>";
}

function bu_busy_script(): string {
    return "<script>(function(){"
        . "if(!document.getElementById('bxBusyStyle')){var st=document.createElement('style');st.id='bxBusyStyle';"
        . "st.textContent='#bxOverlay{position:fixed;inset:0;z-index:99998;background:rgba(18,20,23,.45);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .15s ease}#bxOverlay.an{opacity:1}#bxOverlay .bxo-spin{width:46px;height:46px;border:4px solid rgba(255,255,255,.55);border-top-color:#fff;border-radius:50%;animation:bxo-spin .7s linear infinite}@keyframes bxo-spin{to{transform:rotate(360deg)}}';"
        . "document.head.appendChild(st);}"
        . "var bar;function ladebalken(){try{if(document.getElementById('bxLadebalken'))return;bar=document.createElement('div');bar.id='bxLadebalken';document.body.appendChild(bar);requestAnimationFrame(function(){bar.className='an';});}catch(e){}}"
        . "function overlay(){try{if(document.getElementById('bxOverlay'))return;var o=document.createElement('div');o.id='bxOverlay';o.innerHTML='<div class=\\\"bxo-spin\\\" aria-hidden=\\\"true\\\"></div>';document.body.appendChild(o);requestAnimationFrame(function(){o.className='an';});setTimeout(function(){var x=document.getElementById('bxOverlay');if(x)x.remove();},15000);}catch(e){}}"
        . "function weg(){try{var l=document.getElementById('bxLadebalken');if(l)l.remove();var o=document.getElementById('bxOverlay');if(o)o.remove();}catch(e){}}"
        . "document.addEventListener('submit',function(e){"
        . "var f=e.target;if(!f||f.hasAttribute('data-no-busy'))return;"
        . "var b=e.submitter||f.querySelector('button[type=submit],input[type=submit],button:not([type])');"
        . "var t=(b&&(b.getAttribute('formtarget')))||f.getAttribute('target');if(t==='_blank')return;"
        . "if(f.__busy)return;f.__busy=true;ladebalken();"
        . "if(b&&!b.dataset.busyOn){b.dataset.busyOn='1';"
        . "if(b.tagName==='BUTTON'){b.dataset.busyLabel=b.innerHTML;var tx=b.getAttribute('data-busy');b.innerHTML='<span class=\\\"bx-spin\\\" aria-hidden=\\\"true\\\"></span>'+(tx!==null?tx:b.dataset.busyLabel);}"
        . "b.classList.add('is-busy');b.setAttribute('aria-busy','true');"
        . "setTimeout(function(){b.disabled=true;},0);}"
        . "},true);"
        . "window.addEventListener('beforeunload',function(){overlay();ladebalken();});"
        . "window.addEventListener('pageshow',function(ev){if(!ev.persisted)return;weg();"
        . "var b=document.querySelector('.btn.is-busy');if(b){b.disabled=false;b.classList.remove('is-busy');b.removeAttribute('aria-busy');"
        . "if(b.dataset.busyLabel!==undefined){b.innerHTML=b.dataset.busyLabel;delete b.dataset.busyLabel;}delete b.dataset.busyOn;}"
        . "document.querySelectorAll('form').forEach(function(f){f.__busy=false;});});"
        . "})();</script>";
}

function bu_side_script(): string {
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
        . "g.addEventListener('dblclick',function(){breite(STD);});}"
        . "document.querySelectorAll('.bx-sidebtn').forEach(function(b){b.addEventListener('click',function(e){e.preventDefault();zu(true);});});"
        . "document.querySelectorAll('.bx-sideauf').forEach(function(b){b.addEventListener('click',function(e){e.preventDefault();zu(false);});});"
        . "})();</script>";
}

function bu_theme_script(): string {
    return "<script>(function(){var r=document.documentElement;"
        . "function lbl(){var d=r.getAttribute('data-theme')==='dark';document.querySelectorAll('.bx-themebtn').forEach(function(b){b.textContent=d?(b.dataset.hell||'Heller Modus'):(b.dataset.dunkel||'Dunkler Modus');});}"
        . "document.querySelectorAll('.bx-themebtn').forEach(function(b){b.addEventListener('click',function(e){e.preventDefault();var d=r.getAttribute('data-theme')==='dark';var t=d?'light':'dark';r.setAttribute('data-theme',t);try{localStorage.setItem('bx-theme',t);}catch(err){}lbl();});});lbl();})();</script>";
}
