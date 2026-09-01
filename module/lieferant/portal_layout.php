<?php
// Rahmen des Lieferantenportals: Kopf, Menü, Fuß – und die Übersetzung.
// Zwei Sprachen: Deutsch und Englisch. Welche gilt, steht am Lieferanten (`lieferanten.sprache`);
// alles außer 'de' läuft auf Englisch, weil die meisten Lieferanten im Ausland sitzen.

// Übersetzung. Fehlt ein Schlüssel, kommt der Schlüssel selbst zurück – dann fällt es auf.
function lp_t(string $key, string $sprache = ''): string {
    static $texte = null;
    if ($texte === null) $texte = [
        'portal'          => ['de'=>'Lieferantenportal',      'en'=>'Supplier portal'],
        'uebersicht'      => ['de'=>'Übersicht',              'en'=>'Overview'],
        'bestellungen'    => ['de'=>'Bestellungen',           'en'=>'Orders'],
        'anfragen'        => ['de'=>'Anfragen',               'en'=>'Requests'],
        'profil'          => ['de'=>'Meine Daten',            'en'=>'My details'],
        'abmelden'        => ['de'=>'Abmelden',               'en'=>'Sign out'],
        'willkommen'      => ['de'=>'Willkommen',             'en'=>'Welcome'],
        'offene_best'     => ['de'=>'Offene Bestellungen',    'en'=>'Open orders'],
        'unbestaetigt'    => ['de'=>'Noch nicht bestätigt',   'en'=>'Awaiting confirmation'],
        'offene_anfragen' => ['de'=>'Offene Anfragen',        'en'=>'Open requests'],
        'nummer'          => ['de'=>'Nummer',                 'en'=>'Number'],
        'datum'           => ['de'=>'Datum',                  'en'=>'Date'],
        'positionen'      => ['de'=>'Positionen',             'en'=>'Items'],
        'status'          => ['de'=>'Status',                 'en'=>'Status'],
        'termin'          => ['de'=>'Zugesagter Termin',      'en'=>'Confirmed date'],
        'menge'           => ['de'=>'Menge',                  'en'=>'Quantity'],
        'einheit'         => ['de'=>'Einheit',                'en'=>'Unit'],
        'preis'           => ['de'=>'Preis',                  'en'=>'Price'],
        'summe'           => ['de'=>'Summe',                  'en'=>'Total'],
        'artikel'         => ['de'=>'Artikel',                'en'=>'Item'],
        'pdf'             => ['de'=>'Bestellung als PDF',     'en'=>'Order as PDF'],
        'keine_best'      => ['de'=>'Zurzeit liegen keine Bestellungen vor.', 'en'=>'There are no orders at the moment.'],
        'keine_anfragen'  => ['de'=>'Zurzeit liegen keine Anfragen vor.',     'en'=>'There are no requests at the moment.'],
        'zur_bestellung'  => ['de'=>'Bestellung ansehen',     'en'=>'View order'],
        'gespeichert'     => ['de'=>'Gespeichert.',           'en'=>'Saved.'],
        'ansprechpartner' => ['de'=>'Ansprechpartner',        'en'=>'Contact person'],
        'email'           => ['de'=>'E-Mail',                 'en'=>'E-mail'],
        'telefon'         => ['de'=>'Telefon',                'en'=>'Phone'],
        'speichern'       => ['de'=>'Speichern',              'en'=>'Save'],
        'sprache'         => ['de'=>'Sprache',                'en'=>'Language'],
        'angebot_abgeben' => ['de'=>'Angebot abgeben',        'en'=>'Submit quote'],
        'ihr_preis'       => ['de'=>'Ihr Preis je Einheit',   'en'=>'Your price per unit'],
        'ab_menge'        => ['de'=>'ab Menge',               'en'=>'from quantity'],
        'staffel_hinweis' => ['de'=>'Mengenstaffeln: je Zeile eine Menge und den Preis dazu. Leere Zeilen werden ignoriert.',
                              'en'=>'Volume tiers: one quantity and its price per row. Empty rows are ignored.'],
        'anfrage_offen'   => ['de'=>'offen',                  'en'=>'open'],
        'anfrage_beant'   => ['de'=>'beantwortet',            'en'=>'answered'],
        'dateien'         => ['de'=>'Dokumente (CoA, Spezifikation, Angebot)', 'en'=>'Documents (CoA, specification, quote)'],
        'hochladen'       => ['de'=>'Hochladen',              'en'=>'Upload'],
        'notiz'           => ['de'=>'Notiz',                  'en'=>'Note'],
        'gewuenscht'      => ['de'=>'Angefragte Menge',       'en'=>'Requested quantity'],
        'abgegeben_am'    => ['de'=>'abgegeben am',           'en'=>'submitted on'],
    ];
    $s = $sprache !== '' ? $sprache : (function_exists('lieferant_sprache') ? lieferant_sprache() : 'de');
    $s = strtolower($s) === 'de' ? 'de' : 'en';
    return $texte[$key][$s] ?? $key;
}

function lp_head(string $titel): void {
    $lang = (function_exists('lieferant_sprache') ? lieferant_sprache() : 'de');
    echo '<!doctype html><html lang="' . h($lang) . '"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . h($titel) . '</title><link rel="stylesheet" href="assets/app.css">'
       . '<script>(function(){try{var t=localStorage.getItem(\'bx-theme\');if(t===\'dark\'||t===\'light\')document.documentElement.setAttribute(\'data-theme\',t);}catch(e){}})();</script>'
       . '</head><body>';
}

function lp_foot(): void {
    echo (function_exists('bx_theme_script') ? bx_theme_script() : '') . '</body></html>';
}

// Menü + Rahmen. $aktiv = Routenname der aktuellen Seite.
function lp_shell_start(string $aktiv): void {
    $lf = aktueller_lieferant();
    $menu = [
        'lieferant_portal'      => lp_t('uebersicht'),
        'lieferant_bestellung'  => lp_t('bestellungen'),
        'lieferant_anfrage'     => lp_t('anfragen'),
        'lieferant_profil'      => lp_t('profil'),
    ];
    echo '<div class="bx-shell"><aside class="bx-side">'
       . '<div class="bx-brand">bulkify <span class="bx-ver">' . h(lp_t('portal')) . '</span></div>'
       . '<nav><div class="bx-navgroup">' . h((string)($lf['firma'] ?? '')) . '</div>';
    foreach ($menu as $route => $label) {
        echo '<a href="?p=' . h($route) . '"' . ($aktiv === $route ? ' class="on"' : '') . '>' . h($label) . '</a>';
    }
    echo '<div class="bx-userbox"><a href="?p=logout">' . h(lp_t('abmelden')) . '</a></div>'
       . '<div class="bx-userbox"><button type="button" class="bx-themebtn">Dunkler Modus</button></div>'
       . '</nav></aside><main class="bx-main">';
}
function lp_shell_ende(): void { echo '</main></div>'; }
