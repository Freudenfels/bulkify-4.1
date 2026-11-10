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

// Navigation: die STANDARD-Definition (Bereich => [Seiten]). Welche Seiten es überhaupt gibt, steht hier.
// Die tatsächliche Anzeige-Reihenfolge/Beschriftung liefert bx_nav() – ggf. mit Admin-Anpassung aus app_meta.
function bx_nav_default(): array {
    return [
        'Start'        => ['dashboard' => 'Dashboard'],
        'Vertrieb'     => ['kunden' => 'Kunden', 'partner' => 'Partner', 'angebote' => 'Angebote', 'auftraege' => 'Aufträge', 'kundenpreise' => 'Kundenpreise', 'nachbestellungen' => 'Nachbestellungen', 'dienstleistungen' => 'Dienstleistungen', 'rechnungen_ansicht' => 'Rechnungen (Ansicht)', 'fasttrack' => 'Fast Track', 'kontingente' => 'Kontingente'],
        // Schritt 2 Soll-Handbuch (08.11.2026): EIN Punkt „Anfragen" – die Seite zeigt alle Arten (Rezeptur/Produkt/Rohstoff/
        // Dienstleistung) in einer Tabelle. Wer nur Portal-Anfragen sehen darf (Einkauf), landet über alt_route dort.
        'Anfragen'     => [
            'anfragen' => ['label'=>'Anfragen', 'route'=>'anfragen', 'href'=>'?p=anfragen', 'alt_route'=>'portal_anfragen', 'alt_href'=>'?p=portal_anfragen', 'auch_aktiv'=>['portal_anfragen']],
        ],
        'Produkt'      => ['rezeptur' => 'Rezepturen', 'rezeptur_dubletten' => 'Doppelte Rezepturen', 'produkte' => 'Produkte', 'rohstoffe' => 'Rohstoffe', 'novelfood' => 'Novel Food', 'novelfood_verlauf' => 'Novel-Food News', 'd3k2_rechner' => 'D3K2-Rechner'],
        // Vor-Produktion als eigener Menüpunkt entfernt (Block I): Quelle festlegen + Freigeben laufen jetzt
        // am Auftrag; „Wartet auf Freigabe" ist ein Reiter in der Auftragsliste (?p=auftraege&tab=freigabe).
        // Die Route produktion_vorbereitung&id=… (Glas/Verpackung je PA) bleibt bestehen, nur ohne Nav-Eintrag.
        // Produktionsfreigabe (Nico 03.11.2026): Liste der PAs in Vorbereitung mit Zähler im Menü. Eigener Key (nicht
        // 'produktion_vorbereitung'), damit ein früher ausgeblendeter Eintrag im gespeicherten Menü ihn nicht verdeckt.
        // Soll-Kette 4c (04.11.2026): heißt jetzt „Beschaffungsweg offen“ – es gibt keine eigene Freigabe mehr, nur den Weg.
        'Produktion'   => ['produktionsfreigabe' => ['label'=>'Beschaffungsweg offen', 'route'=>'produktion_vorbereitung', 'href'=>'?p=produktion_vorbereitung'],
                           'produktion' => 'Produktion', 'produktion_neu' => 'Produktion (neu)', 'easy_production' => 'Easy Production', 'produktion_planung' => 'Planung', 'produktion_run' => 'Geführte Produktion', 'maschinen' => 'Maschinen', 'kalender' => 'Kalender', 'aufgaben' => 'Aufgaben', 'versand' => 'Versand'],
        // Lager = EIN Punkt (Schritt 2 Soll-Handbuch): Lager 1 / Lager 2 sind Filter-Reiter auf der Seite (?ort=…), keine Menüpunkte.
        // Rohstoffe stehen seit Schritt 2 in der Gruppe Produkt.
        'Lager'        => [
            'lager'        => ['label'=>'Lager', 'route'=>'lager', 'href'=>'?p=lager'],
            'wareneingang' => 'Wareneingang',
            'rohstoff_split' => 'Rohstoffe aufschlüsseln', 'rohstoff_website' => 'Website-Freigabe', 'freigaben' => 'Freigaben', 'rohstoffe_papiere' => 'Rohstoffe ohne Papiere', 'laboranalysen' => 'Laboranalysen', 'verpackungen' => 'Verpackungen', 'naehrstoffe' => 'Nährstoffe (NRV)'],
        'Einkauf'      => ['einkaufsliste' => 'Bedarf', 'einkauf' => 'Bestellt', 'einkauf_mobil' => 'Schnell (mobil)', 'lieferanten' => 'Lieferanten', 'lief_anfragen' => 'Anfragen & Preise', 'lief_nachrichten' => 'Nachrichten', 'katalog_freigaben' => 'Katalog-Freigaben', 'einkauf_preise' => 'Preise'],
        'Import'       => ['angebot_import' => 'Angebots-Import', 'lager2_import' => 'Lager-2 Bestände'],
        'System'       => ['benachrichtigungen' => 'Benachrichtigungen', 'einstellungen' => 'Einstellungen', 'menu_editor' => 'Menü', 'benutzer' => 'Benutzer', 'protokoll' => 'Protokoll', 'datenpruefung' => 'Datenprüfung', 'artikel_zusammenfuehren' => 'Artikel zusammenführen', 'durchlaufzeiten' => 'Durchlaufzeiten', 'angebotsscan' => 'Angebotsscan', 'testdaten' => 'Testdaten (lokal)', 'app' => 'App aufs Handy'],
        'Assistent'    => ['fastaction' => 'Fastaction'],
    ];
}

// Registry: jeder Menüpunkt EINMAL flach – key => ['label'=>…, 'def'=>Standarddefinition, 'group'=>Standardgruppe].
// So weiß der Editor, welche Punkte es gibt, und bx_nav() kann Punkte sicher per key verschieben.
function bx_nav_registry(): array {
    $reg = [];
    foreach (bx_nav_default() as $grp => $items) {
        foreach ($items as $key => $def) {
            $label = is_array($def) ? (string)($def['label'] ?? $key) : (string)$def;
            $reg[$key] = ['label' => $label, 'def' => $def, 'group' => $grp];
        }
    }
    return $reg;
}

// Gespeichertes Admin-Menü (JSON) aus app_meta, oder null. Form: ['groups'=>[['label'=>…, 'keys'=>[…]], …], 'hidden'=>[…]].
function menu_layout_get(): ?array {
    if (!function_exists('meta_get')) return null;
    $raw = trim((string) meta_get('menu_layout', ''));
    if ($raw === '') return null;
    $d = json_decode($raw, true);
    return (is_array($d) && !empty($d['groups']) && is_array($d['groups'])) ? $d : null;
}

// Die tatsächliche Navigation: Standard, überlagert mit der Admin-Anpassung. Punkte, die (noch) nirgends
// einsortiert sind (z. B. neue Seiten), kommen automatisch unter „Weitere" – es geht nie etwas verloren.
function bx_nav(): array {
    $reg = bx_nav_registry();
    $layout = menu_layout_get();
    if (!$layout) return bx_nav_default();

    $hidden = array_flip(array_map('strval', $layout['hidden'] ?? []));
    $nav = []; $benutzt = [];
    foreach ($layout['groups'] as $g) {
        $label = trim((string)($g['label'] ?? '')); if ($label === '') continue;
        $items = [];
        foreach (($g['keys'] ?? []) as $key) {
            $key = (string)$key;
            if (!isset($reg[$key]) || isset($hidden[$key]) || isset($benutzt[$key])) continue;
            $items[$key] = $reg[$key]['def'];
            $benutzt[$key] = true;
        }
        if ($items) $nav[$label] = array_merge($nav[$label] ?? [], $items);
    }
    // Nicht einsortierte, nicht ausgeblendete Punkte -> in ihre Standardgruppe (sonst „Weitere"), und zwar direkt
    // hinter ihren Vorgänger aus dem Standardmenü, wenn der dort steht (z. B. „Produktion (neu)" unter „Produktion").
    $vorgaenger = []; $vorher = null; $vorherGrp = null;
    foreach ($reg as $key => $r) { $vorgaenger[$key] = $vorherGrp === $r['group'] ? $vorher : null; $vorher = $key; $vorherGrp = $r['group']; }
    foreach ($reg as $key => $r) {
        if (isset($benutzt[$key]) || isset($hidden[$key])) continue;
        $grp = isset($nav[$r['group']]) ? $r['group'] : 'Weitere';
        $vg = $vorgaenger[$key];
        if ($vg !== null && isset($nav[$grp][$vg])) {
            $neu = [];
            foreach ($nav[$grp] as $k => $d) { $neu[$k] = $d; if ($k === $vg) $neu[$key] = $r['def']; }
            $nav[$grp] = $neu;
        } else {
            $nav[$grp][$key] = $r['def'];
        }
        $benutzt[$key] = true;
    }
    return $nav;
}

// Menü-Modus des angemeldeten Nutzers: „einfach" (Standard, auch wenn noch nichts gespeichert ist) oder „ausfuehrlich".
function bx_menu_modus(): string {
    $u = function_exists('current_user') ? current_user() : null;
    return ($u && (string)($u['menu_modus'] ?? '') === 'ausfuehrlich') ? 'ausfuehrlich' : 'einfach';
}

// Menü „Einfach" (Soll-Handbuch Abschnitt 3): feste Liste in fester Reihenfolge. Die Definition je Punkt kommt aus der
// Registry (dieselben Ziele wie im ausführlichen Menü); was der Admin im Menü-Editor ausgeblendet hat, bleibt auch hier
// weg. Ganz oben kommt später „Zu erledigen" (Schritt 4) – bis dahin kein Platzhalter. Blendet nur aus, löscht nichts.
function bx_nav_einfach(): array {
    // Menü „Einfach" (Nico 08.11.2026): EINE flache Liste ohne Gruppen, feste Reihenfolge im Code – hängt NICHT am
    // Menü-Editor (der steuert nur „Ausführlich"). Ziele und Rechte wie in der Registry; Buchhaltung nur mit Rolle finance.
    // Platz für „Zu erledigen" (Schritt 4) ist ganz oben reserviert und wird bis dahin nicht angezeigt.
    $reg = bx_nav_registry();
    // Benachrichtigungen ganz oben (Nico 08.11.2026; später unter „Zu erledigen"), Zähler = ungelesene des Nutzers.
    $plan = ['benachrichtigungen' => null, 'anfragen' => null, 'angebote' => null, 'auftraege' => null, 'einkaufsliste' => 'Einkauf', 'einkauf' => 'Bestellt', 'produktion' => null,
             'lager' => 'Lager', 'produkte' => null, 'rezeptur' => null, 'rohstoffe' => null];
    $nav = [];
    foreach ($plan as $key => $label) {
        if (!isset($reg[$key])) continue;
        $def = $reg[$key]['def'];
        if ($label !== null) $def = is_array($def) ? array_merge($def, ['label' => $label]) : $label;
        // „Bestellt" (Liste der Bestellungen) bleibt auch auf einer geöffneten Bestellung markiert.
        if ($key === 'einkauf') $def = ['label' => 'Bestellt', 'route' => 'einkauf', 'href' => '?p=einkauf', 'auch_aktiv' => ['bestellung']];
        $nav[$key] = $def;
    }
    if (function_exists('user_rollen') && in_array('finance', user_rollen(), true))
        $nav['buchhaltung'] = ['label' => 'Buchhaltung', 'route' => '', 'href' => 'buchhaltung/', 'extern' => true];
    return ['' => $nav];
}
// Linien-Icons fürs Menü „Einfach" (Stil Lucide/Feather, Inline-SVG in currentColor – keine Emojis, kein CDN).
function bx_nav_icon(string $key): string {
    static $p = [
        'benachrichtigungen' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'aufgaben'      => '<path d="m3 17 2 2 4-4"/><path d="m3 7 2 2 4-4"/><path d="M13 6h8"/><path d="M13 12h8"/><path d="M13 18h8"/>',
        'anfragen'      => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'angebote'      => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'auftraege'     => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
        'einkaufsliste' => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
        'einkauf'       => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
        'produktion'    => '<path d="M2 20a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8l-7 5V8l-7 5V4a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M17 18h1"/><path d="M12 18h1"/><path d="M7 18h1"/>',
        'lager'         => '<path d="M22 8.35V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8.35A2 2 0 0 1 3.26 6.5l8-3.2a2 2 0 0 1 1.48 0l8 3.2A2 2 0 0 1 22 8.35Z"/><path d="M6 18h12"/><path d="M6 14h12"/><rect width="12" height="12" x="6" y="10"/>',
        'produkte'      => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'rezeptur'      => '<path d="M10 2v7.527a2 2 0 0 1-.211.896L4.72 20.55a1 1 0 0 0 .9 1.45h12.76a1 1 0 0 0 .9-1.45l-5.069-10.127A2 2 0 0 1 14 9.527V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/>',
        'rohstoffe'     => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>',
        'buchhaltung'   => '<rect width="16" height="20" x="4" y="2" rx="2"/><line x1="8" x2="16" y1="6" y2="6"/><line x1="16" x2="16" y1="14" y2="18"/><path d="M16 10h.01"/><path d="M12 10h.01"/><path d="M8 10h.01"/><path d="M12 14h.01"/><path d="M8 14h.01"/><path d="M12 18h.01"/><path d="M8 18h.01"/>',
    ];
    if (!isset($p[$key])) return '';
    return '<svg class="bx-navico" viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[$key] . '</svg>';
}

// Zähler offener Anfragen je Nav-Punkt (offen = noch nicht beantwortet/abgelehnt), wie ungelesene Mails
function bx_anfrage_counts(): array {
    $c = ['anfragen'=>0,'paf_produkt'=>0,'paf_rohstoff'=>0,'paf_dienstleistung'=>0];
    if (!function_exists('scalar')) return $c;
    try {
        // Deckungsgleich mit den Listen-Ansichten „Offen" (module/anfrage/liste.php, module/intern/portal_anfragen.php)
        // und der Kundensicht: OFFEN = es liegt noch KEIN echter Vorschlag bzw. kein sichtbares Angebot beim Kunden.
        // Sonst zeigt der Menü-Badge etwas an, das in der geöffneten Liste (Standard „Offen") gar nicht steht.
        // Rezepturanfrage: beantwortet = verknüpfte Rezeptur ist vorschlag/freigegeben/eingefroren (und nicht „überarbeiten“).
        $c['anfragen'] = (int) scalar(
            "SELECT COUNT(*) FROM rezeptur_anfrage ra LEFT JOIN rezeptur r ON r.id=ra.rezeptur_id
             WHERE ra.status<>'abgelehnt'
               AND NOT (ra.status<>'ueberarbeiten' AND r.status IN ('vorschlag','freigegeben','eingefroren'))");
        // Portal-Anfrage (Produkt/Rohstoff/Dienstleistung): beantwortet = es gibt ein sichtbares, preislich
        // freigegebenes Angebot (genau wie im Kundenportal). Zurückgezogenes/gesperrtes Angebot zählt wieder als offen.
        foreach (all(
            "SELECT pa.typ, COUNT(*) c FROM portal_anfrage pa
             WHERE pa.status<>'abgelehnt'
               AND NOT EXISTS (SELECT 1 FROM angebot a WHERE a.anfrage_id=pa.id AND a.kunde_id=pa.kunde_id
                               AND COALESCE(a.kunde_ausgeblendet,0)=0 AND a.status<>'offen' AND a.preise_kunde=1)
             GROUP BY pa.typ") as $r) {
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
        'Produktion'       => ['produktion_run' => 'Geführte Produktion', 'produktion' => 'Produktionsaufträge', 'kalender' => 'Kalender'],
        'Warenwirtschaft'  =>['einkaufsliste' => 'Bedarf',
                               'lager'        => ['label'=>'Warenlager', 'route'=>'lager', 'href'=>'?p=lager',             'ort'=>'alle'],
                               'lager_l1'     => ['label'=>'Lager 1',    'route'=>'lager', 'href'=>'?p=lager&ort=lager1', 'ort'=>'lager1'],
                               'lager_fremd'  => ['label'=>'Fremdlager', 'route'=>'lager', 'href'=>'?p=lager&ort=lager2', 'ort'=>'lager2'],
                               'wareneingang' => 'Wareneingang', 'chargen' => 'Chargen', 'produktionschargen' => 'Produktionschargen',
                               'rohstoffe' => 'Rohstoffe', 'rohstoff_split' => 'Rohstoffe aufschlüsseln', 'freigaben' => 'Freigaben', 'verpackungen' => 'Verpackungen', 'naehrstoffe' => 'Nährstoffe (NRV)',
                               'versand' => 'Versand'],
        'Entwicklung'      => ['rezeptur' => 'Rezepturen', 'anfragen' => 'Rezepturanfragen', 'novelfood' => 'Novel Food', 'novelfood_verlauf' => 'Novel-Food News'],
    ];
}

function render_header(string $aktiv = 'dashboard', string $titel = ''): void {
    $marke = BX_MARKE; $ver = BX_VERSION;
    echo "<!doctype html><html lang=\"de\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
    echo "<title>" . h($titel ? "$titel – $marke $ver" : "$marke $ver") . "</title>";
    echo "<link rel=\"stylesheet\" href=\"assets/app.css?v=" . (int) @filemtime(BX_ROOT . '/public/assets/app.css') . "\">";
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
    $menuModus = $istWerk ? 'werk' : bx_menu_modus();
    $navdef   = $istWerk ? bx_nav_werk() : ($menuModus === 'einfach' ? bx_nav_einfach() : bx_nav());
    // Sidebar
    // Logo führt zur Startseite (im Menü „Einfach" gibt es keinen eigenen Punkt „Dashboard").
    $startHref = $istWerk ? '?p=werk' : '?p=dashboard';
    echo "<aside class=\"bx-side\"><div class=\"bx-brand\"><a href=\"$startHref\" title=\"Zur Startseite\" style=\"display:contents\"><img src=\"assets/bulkify-logo-white.png\" alt=\"$marke\" class=\"bx-logo\"></a><span class=\"bx-ver\">" . h($verLabel) . "</span></div><nav>";
    // Einklappbare Menü-Gruppen: Kopf = Umschalter, Items im Wrapper; Zustand je Gruppe in localStorage.
    echo "<style>.bx-navgroup{display:flex;align-items:center;justify-content:space-between;cursor:pointer;user-select:none}"
       . ".bx-navchev{font-size:12px;line-height:1;opacity:.85;transition:transform .15s;margin-left:8px;display:inline-block}"
       . ".bx-navgroup.collapsed .bx-navchev{transform:rotate(-90deg)}"
       . ".bx-navitems.collapsed{display:none}"
       // Menü „Einfach": flache Liste, etwas größer, Icon links in Textfarbe, Texte bündig.
       . ".bx-navflat{padding-top:4px}"
       . ".bx-side nav .bx-navflat a{justify-content:flex-start;gap:12px;font-size:15px;padding:10px var(--sp-5);line-height:1.25}"
       . ".bx-navflat .bx-navico{flex:0 0 19px;width:19px;height:19px;opacity:.9}"
       . ".bx-navflat a.on .bx-navico{opacity:1}"
       . ".bx-navflat .bx-navlbl{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis}"
       . ".bx-navflat .bx-navbadge{min-width:22px;height:22px;line-height:22px;font-size:12px;padding:0 6px}"
       . ".bx-navicowrap{position:relative;flex:0 0 19px;width:19px;height:19px;display:inline-flex}"
       . ".bx-navflat .bx-navbadge.bx-navbadge-ico{position:absolute;top:-9px;left:4px;min-width:18px;height:18px;line-height:18px;font-size:10px;padding:0 3px}"
       . "@media (max-width:860px){.bx-side nav .bx-navflat a{font-size:16px;padding:12px var(--sp-5)}}</style>";
    $darf = function_exists('route_erlaubt');   // Auth aktiv?
    // Globale Suche (nur Admin): ein Feld ganz oben, sucht über alle Bereiche.
    if (function_exists('has_role') && has_role('admin')) {
        $sq = $aktiv === 'suche' ? h((string)($_GET['q'] ?? '')) : '';
        echo "<form class=\"bx-sidesuche\" method=\"get\" action=\"?\" role=\"search\">"
           . "<input type=\"hidden\" name=\"p\" value=\"suche\">"
           . "<input type=\"search\" name=\"q\" value=\"$sq\" placeholder=\"Suche (alles)…\" aria-label=\"Globale Suche\">"
           . "</form>";
    }
    $curTyp = $_GET['typ'] ?? null;
    $curOrt = $_GET['ort'] ?? 'alle';   // Lager-Sicht (Warenlager=alle / Lager 1 / Fremdlager) für die Aktiv-Erkennung
    $anfCount = bx_anfrage_counts();
    // Ein Punkt „Anfragen": Zähler über alle Arten, die der Nutzer sehen darf (Rezepturanfragen nur mit Zugriff darauf).
    $anfPortal = $anfCount['paf_produkt'] + $anfCount['paf_rohstoff'] + $anfCount['paf_dienstleistung'];
    $anfCount['anfragen'] = ((!$darf || route_erlaubt('anfragen')) ? $anfCount['anfragen'] : 0)
                          + ((!$darf || route_erlaubt('portal_anfragen')) ? $anfPortal : 0);
    if (function_exists('aufgabe_offen_zahl') && function_exists('current_user') && ($cu = current_user()))
        $anfCount['aufgaben'] = aufgabe_offen_zahl((int)$cu['id']);
    if (function_exists('benachrichtigungen_ungelesen_count') && function_exists('current_user') && ($cu = current_user()))
        $anfCount['benachrichtigungen'] = benachrichtigungen_ungelesen_count((int)$cu['id']);
    // Einkaufs-Zähler: offener Einkaufsbedarf (noch nicht gemeldet) + Einkaufsliste (Positionen zu bestellen)
    if (function_exists('scalar')) {
        try {
            // Keine Badges an „Einkaufsbedarf" und „Einkaufsliste" (auf Wunsch entfernt). Die Zahl an
            // „Einkaufsliste" erforderte zudem den vollen Bedarf je Seitenaufruf – die Hauptbremse. Die
            // jeweiligen Seiten selbst zeigen alles live.
            $anfCount['bedarf'] = 0;
            $anfCount['einkaufsliste'] = 0;
            // Aufträge, die noch nicht fertig (= versendet) sind. Dienstleistungs-Aufträge (DB-) zählen hier NICHT mit –
            // die stehen im DL-Modul, nicht in der Auftragsliste (?p=auftraege zeigt nur kategorie<>'dienstleistung').
            // Sonst wiche das Menü-Badge vom „Offen"-Reiter der Liste ab.
            $anfCount['auftraege'] = (int) scalar("SELECT COUNT(*) FROM auftrag WHERE status NOT IN ('versendet','storniert') AND COALESCE(kategorie,'produkt') <> 'dienstleistung'");
            // Offene Kundenfreigaben: geänderte Specs (erneute Freigabe) + Alt-Rückstände ohne Freigabe
            // + Chargen mit CoA-Werten ohne CoA-Freigabe. Erst-Uploads geben automatisch frei und zählen hier nicht.
            $anfCount['freigaben'] =
                (int) scalar("SELECT COUNT(*) FROM item i WHERE i.kategorie='rohstoff' AND i.gesperrt=0
                    AND ( COALESCE(i.spec_aenderung_offen,0)=1
                       OR (COALESCE(i.spec_freigegeben,0)=0 AND ( EXISTS (SELECT 1 FROM item_kennwert k WHERE k.item_id=i.id)
                          OR EXISTS (SELECT 1 FROM item_wirkstoff w WHERE w.item_id=i.id)
                          OR EXISTS (SELECT 1 FROM item_grenzwert g WHERE g.item_id=i.id)
                          OR (i.spec_pdf IS NOT NULL AND i.spec_pdf<>'') )) )")
              + (int) scalar("SELECT COUNT(*) FROM charge c WHERE COALESCE(c.coa_freigegeben,0)=0 AND EXISTS (SELECT 1 FROM charge_analyse a WHERE a.charge_id=c.id)")
              // Ware in Quarantäne (Nico 04.11.2026: gehört zu den Freigaben, nicht versteckt im Wareneingang)
              + (int) scalar("SELECT COUNT(*) FROM charge c WHERE c.status='quarantaene' AND c.fremd_kunde_id IS NULL AND c.menge_verfuegbar>0");
            // Offene Lieferanten-Uploads (Mein Katalog: Katalog/CoA/Spec/manuell), die auf unsere Prüfung warten.
            $anfCount['katalog_freigaben'] = (int) scalar("SELECT COUNT(*) FROM lieferant_katalog WHERE status='neu'");
            // Noch nicht freigegebene Produktionsaufträge (Vorbereitung), ohne stornierte Aufträge – wie die Liste.
            $anfCount['produktionsfreigabe'] = (int) scalar("SELECT COUNT(*) FROM produktionsauftrag pa WHERE pa.status='vorbereitung'
                AND NOT EXISTS (SELECT 1 FROM auftrag ax WHERE ax.id=pa.auftrag_id AND ax.status='storniert')");
            // Neu abgegebene Lieferantenpreise (Preisanfrage beantwortet, noch nicht angenommen/abgelehnt).
            $anfCount['lief_anfragen'] = (int) scalar("SELECT COUNT(*) FROM lieferant_anfrage la JOIN lieferant_angebot ag ON ag.anfrage_id=la.id WHERE ag.status='offen'");
            // Ungelesene Lieferanten-Nachrichten (Sicht Team) – deutlich, weil die Software den ganzen Tag offen ist.
            if (function_exists('nachrichten_ungelesen_gesamt')) $anfCount['lief_nachrichten'] = nachrichten_ungelesen_gesamt();
        } catch (Throwable $e) { /* Tabellen evtl. noch nicht da */ }
    }
    foreach ($navdef as $gruppe => $seiten) {
        // Nur erlaubte Einträge der Gruppe (Eintrag = Label-String oder Array mit route/href/typ)
        $sichtbar = [];
        foreach ($seiten as $key => $val) {
            $route = is_array($val) ? $val['route'] : $key;
            if (is_array($val) && !empty($val['extern'])) { $sichtbar[$key] = $val; continue; }   // Unterprogramm (Rolle schon geprüft)
            if (!$darf || route_erlaubt($route)) { $sichtbar[$key] = $val; continue; }
            // Ersatzziel (z. B. „Anfragen" -> Portal-Anfragen für den Einkauf): gleicher Punkt, anderes Ziel, keine neuen Rechte.
            if (is_array($val) && !empty($val['alt_route']) && route_erlaubt($val['alt_route']))
                $sichtbar[$key] = array_merge($val, ['route' => $val['alt_route'], 'href' => $val['alt_href']]);
        }
        if (!$sichtbar) continue;   // leere Gruppe überspringen
        $gid = 'g_' . substr(md5($gruppe), 0, 8);
        $grpHtml = ''; $grpOn = false;
        foreach ($sichtbar as $key => $val) {
            if (is_array($val)) { $route = $val['route']; $label = $val['label']; $href = $val['href']; $typ = $val['typ'] ?? null; $ort = $val['ort'] ?? null; }
            else               { $route = $key; $label = $val; $href = '?p=' . $key; $typ = null; $ort = null; }
            $on = ($route === $aktiv || (is_array($val) && in_array($aktiv, (array)($val['auch_aktiv'] ?? []), true)))
                  && ($typ === null || $curTyp === $typ) && ($ort === null || $curOrt === $ort);
            if ($on) $grpOn = true;
            $cls = $on ? ' class="on"' : '';
            $n = $anfCount[$key] ?? 0;
            $badgeTitel = match ($key) {
                'aufgaben'      => "$n offene Aufgaben",
                'benachrichtigungen' => "$n ungelesene Benachrichtigungen",
                'bedarf'        => "$n Aufträge mit offenem Einkaufsbedarf (noch nicht gemeldet)",
                'einkaufsliste' => "$n Positionen zu bestellen",
                'auftraege'     => "$n Aufträge noch nicht fertig",
                'freigaben'     => "$n offene Freigaben (Quarantäne/Spezifikationen/CoA)",
                'katalog_freigaben' => "$n Lieferanten-Uploads zu prüfen",
                'produktionsfreigabe' => "$n Aufträge ohne festgelegten Beschaffungsweg",
                'lief_anfragen' => "$n neue Lieferantenpreise (abgegeben, noch nicht bearbeitet)",
                'lief_nachrichten' => "$n ungelesene Nachrichten von Lieferanten",
                default         => "$n offene Anfragen",
            };
            $badge = $n > 0 ? "<span class=\"bx-navbadge\" title=\"$badgeTitel\">$n</span>" : '';
            $ico = $menuModus === 'einfach' ? bx_nav_icon((string)$key) : '';
            // Benachrichtigungen: Zähler an der Glocke statt rechts – sonst wird das lange Wort bei 3-stelliger Zahl abgeschnitten.
            if ($ico !== '' && $key === 'benachrichtigungen') {
                $ico = '<span class="bx-navicowrap">' . $ico . ($n > 0 ? '<span class="bx-navbadge bx-navbadge-ico" title="' . h($badgeTitel) . '">' . ($n > 99 ? '99+' : $n) . '</span>' : '') . '</span>';
                $badge = '';
            }
            $grpHtml .= "<a href=\"" . h($href) . "\"$cls>" . $ico . "<span class=\"bx-navlbl\">" . h($label) . "</span>$badge</a>";
        }
        if ($menuModus === 'einfach') { echo "<div class=\"bx-navflat\">" . $grpHtml . "</div>"; continue; }   // flach, ohne Gruppen
        echo "<div class=\"bx-navgroup\" data-g=\"$gid\" role=\"button\" tabindex=\"0\"><span>" . h($gruppe) . "</span><span class=\"bx-navchev\">▼</span></div>";
        echo "<div class=\"bx-navitems" . ($grpOn ? ' open-forced' : '') . "\" data-g=\"$gid\">" . $grpHtml . "</div>";
    }
    // Unterseiten (eigene Programme unter /produktion/, /lager/, /crm/, /buchhaltung/) – unten als eigene
    // Gruppe, normale Menü-Links. CRM nur Admin; Lager auch Produktion/Versand/Einkauf/Labor; Produktion für
    // Produktion+Admin; Buchhaltung für Finanz+Admin.
    $crm = function_exists('has_role') && has_role('admin');
    $lager = $crm || (function_exists('user_rollen') && array_intersect(user_rollen(), ['production', 'fulfillment', 'einkauf', 'labor']));
    $produktion = $crm || (function_exists('user_rollen') && array_intersect(user_rollen(), ['production']));
    $buchhaltung = $crm || (function_exists('has_role') && has_role('finance'));
    // Menü „Einfach": Unterprogramme stehen unter „Ausführlich" (Soll-Handbuch Abschnitt 3, Schritt 2). Werk-Menü unverändert.
    if (($crm || $lager || $produktion || $buchhaltung) && $menuModus !== 'einfach') {
        $sub = '';
        if ($buchhaltung) $sub .= "<a href=\"buchhaltung/\"><span>Buchhaltung</span></a>";
        if ($produktion) $sub .= "<a href=\"produktion/\"><span>Produktion</span></a>";
        if ($lager)      $sub .= "<a href=\"lager/\"><span>Lager</span></a>";
        if ($crm)        $sub .= "<a href=\"crm/\"><span>CRM</span></a>";
        echo "<div class=\"bx-navgroup\" data-g=\"g_subpages\" role=\"button\" tabindex=\"0\"><span>Unterseiten</span><span class=\"bx-navchev\">▼</span></div>";
        echo "<div class=\"bx-navitems\" data-g=\"g_subpages\">" . $sub . "</div>";
    }
    // Menü „Einfach": Buchhaltung (Rolle finance) steht als letzter Punkt in der flachen Liste (bx_nav_einfach).
    // Benutzer-Fuß: Name + Rollen + Abmelden
    if (function_exists('current_user') && ($u = current_user())) {
        $rollen = function_exists('rollen_liste') ? rollen_liste() : [];
        $meine = array_map(fn($r) => $rollen[$r] ?? $r, user_rollen());
        $nBenach = function_exists('benachrichtigungen_ungelesen_count') ? benachrichtigungen_ungelesen_count((int)$u['id']) : 0;
        // Im Menü „Einfach" steht Benachrichtigungen als Menüpunkt oben – unten dann nicht doppelt.
        $benachUnten = $menuModus !== 'einfach'
           ? "<a class=\"bx-usernotify\" href=\"?p=benachrichtigungen\" style=\"display:flex;align-items:center;justify-content:space-between;gap:8px;padding:6px 0;text-decoration:none;color:inherit\">"
           . "<span>Benachrichtigungen</span>" . ($nBenach > 0 ? "<span class=\"bx-navbadge\">" . (int)$nBenach . "</span>" : "<span class=\"muted\" style=\"font-size:12px\">keine neuen</span>") . "</a>"
           : '';
        echo "<div class=\"bx-userbox\">" . $benachUnten
           . "<div class=\"bx-username\">" . h($u['name']) . "</div>"
           . "<div class=\"bx-userroles\">" . h(implode(' · ', $meine) ?: 'keine Rolle') . "</div>"
           . ($menuModus !== 'werk' ? bx_menu_umschalter($menuModus) : '')
           . "<a class=\"bx-logout\" href=\"?p=logout\">Abmelden</a>"
           . "<button type=\"button\" class=\"bx-themebtn\">Dunkler Modus</button>"
           . "<button type=\"button\" class=\"bx-sidebtn\">Menü einklappen</button></div>";
    } else {
        echo "<div class=\"bx-userbox\"><button type=\"button\" class=\"bx-themebtn\">Dunkler Modus</button>"
           . "<button type=\"button\" class=\"bx-sidebtn\">Menü einklappen</button></div>";
    }
    // Einklapp-Logik: Zustand je Gruppe merken; die Gruppe mit der aktiven Seite immer offen lassen.
    echo "<script>(function(){function setC(g,c){document.querySelectorAll('[data-g=\"'+g+'\"]').forEach(function(el){el.classList.toggle('collapsed',c);});}"
       . "var s={};try{s=JSON.parse(localStorage.getItem('bx-nav-collapsed')||'{}');}catch(e){}"
       . "document.querySelectorAll('.bx-navgroup[data-g]').forEach(function(h){var g=h.getAttribute('data-g');"
       . "var it=document.querySelector('.bx-navitems[data-g=\"'+g+'\"]');var forced=it&&it.classList.contains('open-forced');"
       . "if(s[g]&&!forced)setC(g,true);"
       . "h.addEventListener('click',function(){var c=!h.classList.contains('collapsed');setC(g,c);s[g]=c;try{localStorage.setItem('bx-nav-collapsed',JSON.stringify(s));}catch(e){}});"
       . "h.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();h.click();}});});})();</script>";
    echo "</nav></aside>";
    // Ziehgriff an der Kante (Breite) + Knopf zum Wiederaufklappen (nur sichtbar, wenn die Leiste zu ist).
    echo "<div class=\"bx-sidegriff\" tabindex=\"0\" role=\"separator\" aria-orientation=\"vertical\""
       . " title=\"Breite ziehen · Doppelklick setzt zurück · Pfeiltasten verstellen\"></div>";
    echo "<button type=\"button\" class=\"bx-sideauf\" title=\"Menü aufklappen\">Menü</button>";
    // Hauptbereich
    echo bx_menue_scrim();
    echo "<main class=\"bx-main\">" . bx_mobilbar();
}

// Umschalter „Menü: Einfach | Ausführlich" in der Benutzer-Box. Postet auf die aktuelle Seite; public/index.php speichert
// am Nutzer (benutzer.menu_modus) und lädt die Seite neu. Der aktive Modus ist hervorgehoben.
function bx_menu_umschalter(string $modus): string {
    $btn = function (string $wert, string $label) use ($modus): string {
        $on = $modus === $wert;
        return "<button type=\"submit\" name=\"bx_menu_modus\" value=\"$wert\"" . ($on ? ' aria-pressed="true" disabled' : '')
             . " style=\"flex:1;padding:5px 6px;border-radius:6px;border:1px solid rgba(255,255,255,.25);font-size:12px;cursor:" . ($on ? 'default' : 'pointer') . ";"
             . ($on ? 'background:#C0F24E;color:#10210f;border-color:#C0F24E' : 'background:transparent;color:inherit') . "\">" . $label . "</button>";
    };
    return "<form method=\"post\" class=\"bx-menumodus\" style=\"margin:8px 0 4px\">"
         . "<div style=\"font-size:12px;opacity:.8;margin-bottom:4px\">Menü</div>"
         . "<div style=\"display:flex;gap:6px\">" . $btn('einfach', 'Einfach') . $btn('ausfuehrlich', 'Ausführlich') . "</div></form>";
}

// ---------- Menue auf schmalen Bildschirmen ----------
// Auf dem Handy passt die Menueleiste nicht neben den Inhalt. Sie liegt dort als Schublade
// darueber; diese drei Bausteine gehoeren dazu und stecken in allen Oberflaechen
// (intern, Lieferantenportal, Kundenportal). Am Rechner blendet das CSS sie aus.

// Kopfleiste mit Burger - gehoert als ERSTES in <main class="bx-main">.
function bx_mobilbar(): string {
    return '<div class="bx-mobilbar">'
         . '<button type="button" class="bx-burger" id="bx-burger" aria-label="Menü" aria-expanded="false">'
         . '<span></span><span></span><span></span></button>'
         . '<img src="assets/bulkify-logo-white.png" alt="bulkify" class="bx-logo">'
         . '</div>';
}

// Dunkle Flaeche hinter der offenen Schublade - Tippen schliesst sie.
function bx_menue_scrim(): string { return '<div class="bx-menuescrim" id="bx-menuescrim"></div>'; }

function bx_menue_script(): string {
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

function render_footer(): void {
    echo "</main></div>";
    echo bx_theme_script();
    echo bx_side_scroll_script();
    echo bx_side_script();
    echo bx_menue_script();
    echo bx_busy_script();
    echo bx_rscombo_script();
    echo bx_tabs_persist_script();
    echo pwa_script();
    echo "</body></html>";
}

// Durchsuchbares Dropdown: wertet jedes <select class="rscombo"> zu einem Suchfeld + Trefferliste auf.
// Die Optionen kommen direkt aus dem <select> (kein Daten-Einbetten nötig); der gespeicherte Wert bleibt
// das Select, funktioniert also auch ohne JS. Platzhalter per data-ph; Mehrfach-Selects werden ignoriert.
// Pflichtfeld (required) leer: das versteckte Select kann der Browser nicht fokussieren -> Absenden brach STILL ab
// (Easy Production „Vorschau passiert nichts", 06.11.2026). Darum zeigt das Suchfeld selbst die Meldung + roten Rahmen.
function bx_rscombo_script(): string {
    return "<script>(function(){"
      . "function norm(s){return String(s).toLowerCase().replace(/\\u00e4/g,'ae').replace(/\\u00f6/g,'oe').replace(/\\u00fc/g,'ue').replace(/\\u00df/g,'ss');}"
      . "function esc(s){return String(s).replace(/[&<>\"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[c];});}"
      . "function combo(sel){if(!sel||sel.multiple||sel.dataset.rscombo)return;sel.dataset.rscombo='1';"
      . "var opts=Array.prototype.map.call(sel.options,function(o){return {v:o.value,t:o.textContent.trim(),d:o.disabled,s:o.getAttribute('data-such')||''};});"
      . "sel.style.display='none';var wrap=document.createElement('div');wrap.className='rs-combo';if(sel.style.minWidth)wrap.style.minWidth=sel.style.minWidth;if(sel.style.maxWidth)wrap.style.maxWidth=sel.style.maxWidth;"
      . "sel.parentNode.insertBefore(wrap,sel);wrap.appendChild(sel);"
      . "var box=document.createElement('input');box.type='text';box.className='rs-input';box.autocomplete='off';box.placeholder=sel.getAttribute('data-ph')||'Suchen…';"
      . "var cur=sel.options[sel.selectedIndex];if(cur&&sel.value!=='')box.value=cur.textContent.trim();"
      . "var list=document.createElement('div');list.className='rs-list';list.hidden=true;wrap.appendChild(box);wrap.appendChild(list);"
      . "var hl=-1,shown=[];"
      . "function mq(o){var t=o.querySelector('.rs-opt-t');if(!t)return;o.addEventListener('mouseenter',function(){var ov=t.scrollWidth-(o.clientWidth-22);if(ov>2){var d=Math.min(6,Math.max(1.2,ov/70));t.style.transition='transform '+d+'s linear';t.style.transform='translateX(-'+ov+'px)';}});o.addEventListener('mouseleave',function(){t.style.transition='transform .25s ease';t.style.transform='';});}"
      . "function render(q){q=norm((q||'').trim());shown=opts.filter(function(o){return o.v!==''&&!o.d&&(!q||norm(o.t+' '+o.s).indexOf(q)>=0);}).slice(0,100);"
      . "if(!shown.length){list.innerHTML='<div class=\"rs-empty\">Nichts gefunden.</div>';}else{list.innerHTML=shown.map(function(o,i){return '<div class=\"rs-opt\" data-i=\"'+i+'\" title=\"'+esc(o.t)+'\"><span class=\"rs-opt-t\">'+esc(o.t)+'</span></div>';}).join('');Array.prototype.forEach.call(list.querySelectorAll('.rs-opt'),mq);}hl=-1;list.hidden=false;}"
      . "function paint(){Array.prototype.forEach.call(list.querySelectorAll('.rs-opt'),function(o){o.classList.toggle('hl',+o.dataset.i===hl);});var e=list.querySelector('.rs-opt.hl');if(e)e.scrollIntoView({block:'nearest'});}"
      . "function ok(){box.setCustomValidity('');box.classList.remove('rs-invalid');}"
      . "function pick(i){var o=shown[i];if(!o)return;sel.value=o.v;box.value=o.t;list.hidden=true;ok();sel.dispatchEvent(new Event('change',{bubbles:true}));}"
      . "sel.addEventListener('invalid',function(){box.classList.add('rs-invalid');if(window.__rsInv)return;window.__rsInv=1;setTimeout(function(){window.__rsInv=0;},80);"
      . "box.setCustomValidity('Bitte aus der Liste auswählen.');box.focus();box.reportValidity();});"
      . "box.addEventListener('input',function(){box.setCustomValidity('');render(box.value);});"
      . "box.addEventListener('focus',function(){render(box.value);});"
      . "box.addEventListener('keydown',function(e){if(list.hidden){if(e.key==='ArrowDown')render(box.value);return;}if(e.key==='ArrowDown'){e.preventDefault();hl=Math.min(hl+1,shown.length-1);paint();}else if(e.key==='ArrowUp'){e.preventDefault();hl=Math.max(hl-1,0);paint();}else if(e.key==='Enter'){if(hl>=0){e.preventDefault();pick(hl);}}else if(e.key==='Escape'){list.hidden=true;}});"
      . "box.addEventListener('blur',function(){setTimeout(function(){var c=sel.options[sel.selectedIndex];if(c&&sel.value!=='')box.value=c.textContent.trim();},160);});"
      . "list.addEventListener('mousedown',function(e){var o=e.target.closest('.rs-opt');if(o){e.preventDefault();pick(+o.dataset.i);}});"
      . "document.addEventListener('click',function(e){if(!wrap.contains(e.target))list.hidden=true;});}"
      . "function init(root){(root||document).querySelectorAll('select.rscombo').forEach(combo);}"
      . "if(document.readyState!=='loading')init();else document.addEventListener('DOMContentLoaded',function(){init();});"
      . "window.bxRsComboInit=init;})();</script>";
}

// Lade-Rueckmeldung: Beim Absenden eines Formulars wird der geklickte Knopf zum Spinner
// ("Bitte warten...") und oben laeuft ein dezenter Ladebalken. So sieht jeder (Team,
// Lieferant, Kunde), dass das Programm arbeitet - wichtig beim Spec-Upload mit KI-Auslesung,
// die einige Sekunden dauern kann. Ausnahmen: Formulare/Knoepfe mit data-no-busy oder
// target=_blank (Download/neuer Tab navigieren nicht weg -> kein Dauer-Spinner).
// Eigener Busy-Text je Knopf ueber data-busy="...".
function bx_busy_script(): string {
    return "<script>(function(){"
        // Einmalig: Styles fuer Overlay + Spinner selbst mitbringen, damit die Rueckmeldung
        // in ALLEN Layouts (intern + beide Portale) wirkt, unabhaengig vom Stylesheet.
        . "if(!document.getElementById('bxBusyStyle')){var st=document.createElement('style');st.id='bxBusyStyle';"
        . "st.textContent='#bxOverlay{position:fixed;inset:0;z-index:99998;background:rgba(18,20,23,.45);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .15s ease}#bxOverlay.an{opacity:1}#bxOverlay .bxo-spin{width:46px;height:46px;border:4px solid rgba(255,255,255,.55);border-top-color:#fff;border-radius:50%;animation:bxo-spin .7s linear infinite}@keyframes bxo-spin{to{transform:rotate(360deg)}}';"
        . "document.head.appendChild(st);}"
        . "var bar;function ladebalken(){try{if(document.getElementById('bxLadebalken'))return;bar=document.createElement('div');bar.id='bxLadebalken';document.body.appendChild(bar);requestAnimationFrame(function(){bar.className='an';});}catch(e){}}"
        // Voll-Overlay: graut die Seite aus und sperrt Klicks, bis die naechste Seite geladen ist.
        // Failsafe: falls eine Navigation doch abgebrochen wird, verschwindet es nach 15s von selbst.
        . "function overlay(){try{if(document.getElementById('bxOverlay'))return;var o=document.createElement('div');o.id='bxOverlay';o.innerHTML='<div class=\\\"bxo-spin\\\" aria-hidden=\\\"true\\\"></div>';document.body.appendChild(o);requestAnimationFrame(function(){o.className='an';});setTimeout(function(){var x=document.getElementById('bxOverlay');if(x)x.remove();},15000);}catch(e){}}"
        . "function weg(){try{var l=document.getElementById('bxLadebalken');if(l)l.remove();var o=document.getElementById('bxOverlay');if(o)o.remove();}catch(e){}}"
        . "document.addEventListener('submit',function(e){"
        . "var f=e.target;if(!f||f.hasAttribute('data-no-busy'))return;"
        . "var b=e.submitter||f.querySelector('button[type=submit],input[type=submit],button:not([type])');"
        . "var t=(b&&(b.getAttribute('formtarget')))||f.getAttribute('target');if(t==='_blank')return;"
        . "if(f.__busy)return;f.__busy=true;ladebalken();"
        . "if(b&&!b.dataset.busyOn){b.dataset.busyOn='1';"
        // data-busy setzt eigenen Text; sonst bleibt der (uebersetzte) Knopftext stehen - nur der Spinner kommt davor.
        . "if(b.tagName==='BUTTON'){b.dataset.busyLabel=b.innerHTML;var tx=b.getAttribute('data-busy');b.innerHTML='<span class=\\\"bx-spin\\\" aria-hidden=\\\"true\\\"></span>'+(tx!==null?tx:b.dataset.busyLabel);}"
        . "b.classList.add('is-busy');b.setAttribute('aria-busy','true');"
        . "setTimeout(function(){b.disabled=true;},0);}"
        . "},true);"
        // Jede ECHTE Navigation loest 'beforeunload' aus (Formular, Link, Zeilenklick per location.href,
        // Zurueck/Vor). Downloads, neuer Tab (target=_blank) und reine #-Anker entladen die Seite NICHT
        // -> dort erscheint bewusst kein Overlay.
        . "window.addEventListener('beforeunload',function(){overlay();ladebalken();});"
        // Zurueck-Navigation (bfcache): Overlay/Balken + Knopf zuruecksetzen, sonst haengt alles.
        . "window.addEventListener('pageshow',function(ev){if(!ev.persisted)return;weg();"
        . "var b=document.querySelector('.btn.is-busy');if(b){b.disabled=false;b.classList.remove('is-busy');b.removeAttribute('aria-busy');"
        . "if(b.dataset.busyLabel!==undefined){b.innerHTML=b.dataset.busyLabel;delete b.dataset.busyLabel;}delete b.dataset.busyOn;}"
        . "document.querySelectorAll('form').forEach(function(f){f.__busy=false;});});"
        . "})();</script>";
}

// Merkt sich die Scroll-Position der Sidebar über Seitenwechsel (sonst springt das Menü bei jedem Klick nach oben).
function bx_side_scroll_script(): string {
    return "<script>(function(){var el=document.querySelector('.bx-side');if(!el)return;"
        . "try{var y=sessionStorage.getItem('bx-side-scroll');if(y!==null)el.scrollTop=parseInt(y,10)||0;}catch(e){}"
        . "var t;el.addEventListener('scroll',function(){try{clearTimeout(t);t=setTimeout(function(){sessionStorage.setItem('bx-side-scroll',el.scrollTop);},80);}catch(e){}});"
        . "el.querySelectorAll('a[href]').forEach(function(a){a.addEventListener('click',function(){try{sessionStorage.setItem('bx-side-scroll',el.scrollTop);}catch(e){}});});"
        . "})();</script>";
}

// Merkt sich den aktiven Reiter je Seite (Route + id) in sessionStorage und stellt ihn NACH dem vollstaendigen
// Parsen wieder her. Loest zwei Dinge: (1) nach dem Speichern (POST->Redirect) nicht mehr zurueck auf Reiter 1,
// (2) ein per #hash angesprungener Reiter (z. B. #zugang nach „Einladungslink erzeugen") zeigt seinen Inhalt,
// auch wenn das Panel im HTML NACH dem seiteneigenen Reiter-Skript steht (sonst klickte es ins Leere).
// Setzt auf die vorhandenen Reiter-Klick-Handler je Seite auf (ruft nur .click()).
function bx_tabs_persist_script(): string {
    return "<script>(function(){"
        . "function run(){"
        . "var links=document.querySelectorAll('[data-tab]');if(!links.length)return;"
        . "var qs=new URLSearchParams(location.search);var key='bxtab:'+(qs.get('p')||'')+':'+(qs.get('id')||'');"
        . "function klick(name){for(var i=0;i<links.length;i++){if(links[i].getAttribute('data-tab')===name){links[i].click();return true;}}return false;}"
        . "links.forEach(function(l){l.addEventListener('click',function(){try{sessionStorage.setItem(key,l.getAttribute('data-tab'));}catch(e){}});});"
        . "var hash=(location.hash||'').replace('#','');"
        . "if(hash){if(klick(hash)){try{sessionStorage.setItem(key,hash);}catch(e){}}return;}"
        . "var saved=null;try{saved=sessionStorage.getItem(key);}catch(e){}"
        . "if(saved)klick(saved);"
        . "}"
        . "if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',run);else run();"
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
