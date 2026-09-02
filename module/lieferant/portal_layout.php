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
        'anmelden'        => ['de'=>'Anmelden',                'en'=>'Sign in'],
        'login_sub'       => ['de'=>'Zugang für Lieferanten',  'en'=>'Supplier access'],
        'passwort'        => ['de'=>'Passwort',                'en'=>'Password'],
        'login_fehler'    => ['de'=>'Anmeldung fehlgeschlagen.', 'en'=>'Sign-in failed.'],
        'kein_zugang'     => ['de'=>'Noch keinen Zugang? Bitte den Einladungslink nutzen, den wir Ihnen geschickt haben.',
                              'en'=>'No access yet? Please use the invitation link we sent you.'],
        'einl_titel'      => ['de'=>'Zugang einrichten',       'en'=>'Set up your access'],
        'einl_fuer'       => ['de'=>'für',                     'en'=>'for'],
        'einl_text'       => ['de'=>'Legen Sie hier Ihren Zugang an. Danach sehen Sie Ihre Bestellungen und Anfragen und können Termine, Fortschritt und Versanddaten selbst eintragen.',
                              'en'=>'Create your access here. Afterwards you can see your orders and requests, and enter dates, progress and shipping details yourself.'],
        'ihr_name'        => ['de'=>'Ihr Name',                'en'=>'Your name'],
        'pw_regel'        => ['de'=>'Passwort (mindestens 8 Zeichen)', 'en'=>'Password (at least 8 characters)'],
        'zugang_anlegen'  => ['de'=>'Zugang anlegen',          'en'=>'Create access'],
        'einl_ungueltig'  => ['de'=>'Einladung ungültig',      'en'=>'Invitation not valid'],
        'einl_abgelaufen' => ['de'=>'Dieser Einladungslink ist abgelaufen oder wurde bereits verwendet. Bitte melden Sie sich bei uns.',
                              'en'=>'This invitation link has expired or was already used. Please get in touch with us.'],
        'zum_login'       => ['de'=>'Zum Login',               'en'=>'To sign-in'],
        'firma'           => ['de'=>'Firma',                   'en'=>'Company'],
        'strasse'         => ['de'=>'Straße und Hausnummer',   'en'=>'Street and number'],
        'plz'             => ['de'=>'PLZ',                     'en'=>'Postal code'],
        'ort'             => ['de'=>'Ort',                     'en'=>'City'],
        'land'            => ['de'=>'Land',                    'en'=>'Country'],
        'webseite'        => ['de'=>'Webseite',                'en'=>'Website'],
        'wechat'          => ['de'=>'WeChat-ID',               'en'=>'WeChat ID'],
        'whatsapp'        => ['de'=>'WhatsApp',                'en'=>'WhatsApp'],
        'ustid'           => ['de'=>'USt-IdNr. / Steuernummer','en'=>'VAT / tax number'],
        'logo'            => ['de'=>'Firmenlogo',              'en'=>'Company logo'],
        'logo_hinweis'    => ['de'=>'PNG oder JPG, max. 2 MB. Wird nur intern bei uns angezeigt.',
                              'en'=>'PNG or JPG, max. 2 MB. Shown internally at our end only.'],
        'kontakt'         => ['de'=>'Kontakt',                 'en'=>'Contact'],
        'firmendaten'     => ['de'=>'Firmendaten',             'en'=>'Company details'],
        'nur_wir'         => ['de'=>'Konditionen und Preise pflegen wir – ändern Sie hier Ihre Kontakt- und Firmendaten.',
                              'en'=>'Terms and prices are maintained by us – please keep your contact and company details up to date here.'],
    ];
    $s = $sprache !== '' ? $sprache : lp_sprache();
    $s = strtolower($s) === 'de' ? 'de' : 'en';
    return $texte[$key][$s] ?? $key;
}

// Welche Sprache gilt gerade? Reihenfolge: eigene Wahl (Session) vor Stammdaten des Lieferanten.
// Wichtig fuer Login und Einladung – dort ist noch niemand angemeldet, es gibt also keine Stammdaten.
function lp_sprache(): string {
    if (!empty($_SESSION['lp_lang']) && in_array($_SESSION['lp_lang'], ['de', 'en'], true)) return $_SESSION['lp_lang'];
    return function_exists('lieferant_sprache') && function_exists('ist_lieferant') && ist_lieferant() ? lieferant_sprache() : 'de';
}
// Sprachwahl aus ?lang= uebernehmen (auf jeder Portalseite erlaubt).
function lp_sprache_setzen(): void {
    $l = strtolower(trim((string)($_GET['lang'] ?? '')));
    if (in_array($l, ['de', 'en'], true)) $_SESSION['lp_lang'] = $l;
}
// Umschalter „Deutsch | English" – die aktuelle Sprache ist hervorgehoben.
function lp_sprachwahl(): string {
    $cur = lp_sprache();
    $ziel = strtok((string)($_SERVER['REQUEST_URI'] ?? '?p=lieferant_login'), '#');
    $ziel = preg_replace('/([?&])lang=[^&]*(&|$)/', '$1', $ziel);
    $trenner = strpos($ziel, '?') === false ? '?' : '&';
    $out = '<span style="display:inline-flex;gap:12px;font-size:13px;align-items:center">';
    foreach (['de' => 'Deutsch', 'en' => 'English'] as $code => $label) {
        $stil = 'text-decoration:none;color:inherit;' . ($code === $cur ? 'font-weight:600;text-decoration:underline' : 'opacity:.6');
        $out .= '<a style="' . $stil . '" href="' . h($ziel . $trenner . 'lang=' . $code) . '">' . h($label) . '</a>';
    }
    return $out . '</span>';
}
function lp_head(string $titel): void {
    $lang = lp_sprache();
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
       . '<div class="bx-brand"><img src="assets/bulkify-logo-white.png" alt="bulkify" class="bx-logo"><span class="bx-ver">' . h(lp_t('portal')) . '</span></div>'
       . '<nav><div class="bx-navgroup">' . h((string)($lf['firma'] ?? '')) . '</div>';
    foreach ($menu as $route => $label) {
        echo '<a href="?p=' . h($route) . '"' . ($aktiv === $route ? ' class="on"' : '') . '>' . h($label) . '</a>';
    }
    echo '<div class="bx-userbox" style="padding-bottom:0">' . lp_sprachwahl() . '</div>'
       . '<div class="bx-userbox"><a href="?p=logout">' . h(lp_t('abmelden')) . '</a></div>'
       . '<div class="bx-userbox"><button type="button" class="bx-themebtn">Dunkler Modus</button></div>'
       . '</nav></aside><main class="bx-main">';
}
function lp_shell_ende(): void { echo '</main></div>'; }

// Die Wahl aus ?lang= gilt ab sofort – auch fuer Texte, die vor lp_head() gebaut werden.
lp_sprache_setzen();
