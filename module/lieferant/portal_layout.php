<?php
// Rahmen des Lieferantenportals: Kopf, Menü, Fuß – und die Übersetzung.
// Zwei Sprachen: Deutsch und Englisch. Welche gilt, steht am Lieferanten (`lieferanten.sprache`);
// alles außer 'de' läuft auf Englisch, weil die meisten Lieferanten im Ausland sitzen.

// Übersetzung. Fehlt ein Schlüssel, kommt der Schlüssel selbst zurück – dann fällt es auf.
require_once BX_ROOT . '/core/nachricht.php';   // fuer die Zahl ungelesener Nachrichten im Menue

function lp_t(string $key, string $sprache = ''): string {
    static $texte = null;
    if ($texte === null) $texte = [
        'portal'          => ['de'=>'Lieferantenportal',      'en'=>'Supplier portal', 'zh'=>'供应商门户'],
        'uebersicht'      => ['de'=>'Übersicht',              'en'=>'Overview', 'zh'=>'概览'],
        'bestellungen'    => ['de'=>'Bestellungen',           'en'=>'Orders', 'zh'=>'订单'],
        'anfragen'        => ['de'=>'Anfragen',               'en'=>'Requests', 'zh'=>'询价'],
        'profil'          => ['de'=>'Meine Daten',            'en'=>'My details', 'zh'=>'我的资料'],
        'abmelden'        => ['de'=>'Abmelden',               'en'=>'Sign out', 'zh'=>'退出登录'],
        'willkommen'      => ['de'=>'Willkommen',             'en'=>'Welcome', 'zh'=>'欢迎'],
        'offene_best'     => ['de'=>'Offene Bestellungen',    'en'=>'Open orders', 'zh'=>'未完成订单'],
        'unbestaetigt'    => ['de'=>'Noch nicht bestätigt',   'en'=>'Awaiting confirmation', 'zh'=>'待确认'],
        'offene_anfragen' => ['de'=>'Offene Anfragen',        'en'=>'Open requests', 'zh'=>'待处理询价'],
        'nummer'          => ['de'=>'Nummer',                 'en'=>'Number', 'zh'=>'编号'],
        'datum'           => ['de'=>'Datum',                  'en'=>'Date', 'zh'=>'日期'],
        'positionen'      => ['de'=>'Positionen',             'en'=>'Items', 'zh'=>'明细'],
        'status'          => ['de'=>'Status',                 'en'=>'Status', 'zh'=>'状态'],
        'termin'          => ['de'=>'Zugesagter Termin',      'en'=>'Confirmed date', 'zh'=>'确认交货日期'],
        'menge'           => ['de'=>'Menge',                  'en'=>'Quantity', 'zh'=>'数量'],
        'einheit'         => ['de'=>'Einheit',                'en'=>'Unit', 'zh'=>'单位'],
        'preis'           => ['de'=>'Preis',                  'en'=>'Price', 'zh'=>'价格'],
        'summe'           => ['de'=>'Summe',                  'en'=>'Total', 'zh'=>'合计'],
        'artikel'         => ['de'=>'Artikel',                'en'=>'Item', 'zh'=>'物品'],
        'pdf'             => ['de'=>'Bestellung als PDF',     'en'=>'Order as PDF', 'zh'=>'订单 PDF'],
        'keine_best'      => ['de'=>'Zurzeit liegen keine Bestellungen vor.', 'en'=>'There are no orders at the moment.', 'zh'=>'目前没有订单。'],
        'keine_anfragen'  => ['de'=>'Zurzeit liegen keine Anfragen vor.',     'en'=>'There are no requests at the moment.', 'zh'=>'目前没有询价。'],
        'zur_bestellung'  => ['de'=>'Bestellung ansehen',     'en'=>'View order', 'zh'=>'查看订单'],
        'gespeichert'     => ['de'=>'Gespeichert.',           'en'=>'Saved.', 'zh'=>'已保存。'],
        'ansprechpartner' => ['de'=>'Ansprechpartner',        'en'=>'Contact person', 'zh'=>'联系人'],
        'email'           => ['de'=>'E-Mail',                 'en'=>'E-mail', 'zh'=>'电子邮箱'],
        'telefon'         => ['de'=>'Telefon',                'en'=>'Phone', 'zh'=>'电话'],
        'speichern'       => ['de'=>'Speichern',              'en'=>'Save', 'zh'=>'保存'],
        'sprache'         => ['de'=>'Sprache',                'en'=>'Language', 'zh'=>'语言'],
        'angebot_abgeben' => ['de'=>'Angebot abgeben',        'en'=>'Submit quote', 'zh'=>'提交报价'],
        'ihr_preis'       => ['de'=>'Ihr Preis je Einheit',   'en'=>'Your price per unit', 'zh'=>'单价'],
        'ab_menge'        => ['de'=>'ab Menge',               'en'=>'from quantity', 'zh'=>'起订数量'],
        'staffel_hinweis' => ['de'=>'Mengenstaffeln: je Zeile eine Menge und den Preis dazu. Leere Zeilen werden ignoriert.',
                              'en'=>'Volume tiers: one quantity and its price per row. Empty rows are ignored.',
                              'zh'=>'阶梯价格：每行填写一个数量及其价格。空行将被忽略。'],
        'anfrage_offen'   => ['de'=>'offen',                  'en'=>'open', 'zh'=>'待处理'],
        'anfrage_beant'   => ['de'=>'beantwortet',            'en'=>'answered', 'zh'=>'已回复'],
        'dateien'         => ['de'=>'Dokumente (CoA, Spezifikation, Angebot)', 'en'=>'Documents (CoA, specification, quote)', 'zh'=>'文件（COA、Spec、报价单）'],
        'hochladen'       => ['de'=>'Hochladen',              'en'=>'Upload', 'zh'=>'上传'],
        'notiz'           => ['de'=>'Notiz',                  'en'=>'Note', 'zh'=>'备注'],
        'gewuenscht'      => ['de'=>'Angefragte Menge',       'en'=>'Requested quantity', 'zh'=>'询价数量'],
        'abgegeben_am'    => ['de'=>'abgegeben am',           'en'=>'submitted on', 'zh'=>'提交于'],
        'anmelden'        => ['de'=>'Anmelden',                'en'=>'Sign in', 'zh'=>'登录'],
        'login_sub'       => ['de'=>'Zugang für Lieferanten',  'en'=>'Supplier access', 'zh'=>'供应商登录'],
        'passwort'        => ['de'=>'Passwort',                'en'=>'Password', 'zh'=>'密码'],
        'login_fehler'    => ['de'=>'Anmeldung fehlgeschlagen.', 'en'=>'Sign-in failed.', 'zh'=>'用户名或密码错误。'],
        'kein_zugang'     => ['de'=>'Noch keinen Zugang? Bitte den Einladungslink nutzen, den wir Ihnen geschickt haben.',
                              'en'=>'No access yet? Please use the invitation link we sent you.',
                              'zh'=>'还没有账号？请使用我们发送给您的邀请链接。'],
        'einl_titel'      => ['de'=>'Zugang einrichten',       'en'=>'Set up your access', 'zh'=>'设置账号'],
        'einl_fuer'       => ['de'=>'für',                     'en'=>'for', 'zh'=>'适用于'],
        'einl_text'       => ['de'=>'Legen Sie hier Ihren Zugang an. Danach sehen Sie Ihre Bestellungen und Anfragen und können Termine, Fortschritt und Versanddaten selbst eintragen.',
                              'en'=>'Create your access here. Afterwards you can see your orders and requests, and enter dates, progress and shipping details yourself.',
                              'zh'=>'请在此设置您的账号。之后您可以查看订单和询价，并自行填写交货日期、生产进度和物流信息。'],
        'ihr_name'        => ['de'=>'Ihr Name',                'en'=>'Your name', 'zh'=>'您的姓名'],
        'pw_regel'        => ['de'=>'Passwort (mindestens 8 Zeichen)', 'en'=>'Password (at least 8 characters)', 'zh'=>'密码（至少 8 个字符）'],
        'zugang_anlegen'  => ['de'=>'Zugang anlegen',          'en'=>'Create access', 'zh'=>'创建账号'],
        'einl_ungueltig'  => ['de'=>'Einladung ungültig',      'en'=>'Invitation not valid', 'zh'=>'邀请无效'],
        'einl_abgelaufen' => ['de'=>'Dieser Einladungslink ist abgelaufen oder wurde bereits verwendet. Bitte melden Sie sich bei uns.',
                              'en'=>'This invitation link has expired or was already used. Please get in touch with us.',
                              'zh'=>'此邀请链接已过期或已被使用。请与我们联系。'],
        'zum_login'       => ['de'=>'Zum Login',               'en'=>'To sign-in', 'zh'=>'前往登录'],
        'firma'           => ['de'=>'Firma',                   'en'=>'Company', 'zh'=>'公司名称'],
        'strasse'         => ['de'=>'Straße und Hausnummer',   'en'=>'Street and number', 'zh'=>'街道和门牌号'],
        'plz'             => ['de'=>'PLZ',                     'en'=>'Postal code', 'zh'=>'邮政编码'],
        'ort'             => ['de'=>'Ort',                     'en'=>'City', 'zh'=>'城市'],
        'land'            => ['de'=>'Land',                    'en'=>'Country', 'zh'=>'国家'],
        'webseite'        => ['de'=>'Webseite',                'en'=>'Website', 'zh'=>'网站'],
        'wechat'          => ['de'=>'WeChat-ID',               'en'=>'WeChat ID', 'zh'=>'微信号'],
        'whatsapp'        => ['de'=>'WhatsApp',                'en'=>'WhatsApp', 'zh'=>'WhatsApp'],
        'ustid'           => ['de'=>'USt-IdNr. / Steuernummer','en'=>'VAT / tax number', 'zh'=>'税号 / 增值税号'],
        'logo'            => ['de'=>'Firmenlogo',              'en'=>'Company logo', 'zh'=>'公司标志'],
        'logo_hinweis'    => ['de'=>'PNG oder JPG, max. 2 MB. Wird nur intern bei uns angezeigt.',
                              'en'=>'PNG or JPG, max. 2 MB. Shown internally at our end only.',
                              'zh'=>'PNG 或 JPG，最大 2 MB。仅在我们内部显示。'],
        'kontakt'         => ['de'=>'Kontakt',                 'en'=>'Contact', 'zh'=>'联系方式'],
        'rueckfragen'     => ['de'=>'Rückfragen',              'en'=>'Questions and answers', 'zh'=>'留言与答复'],
        'rueckfragen_sub' => ['de'=>'Fragen und Antworten zwischen Ihnen und bulkify. Zu einer Bestellung oder Preisanfrage schreiben Sie am besten direkt dort.',
                              'en'=>'Questions and answers between you and bulkify. For a specific order or price request, please write directly there.',
                              'zh'=>'您与 bulkify 之间的问题与答复。关于某个订单或询价，请直接在该页面留言。'],
        'gesendet'        => ['de'=>'Nachricht gesendet.',      'en'=>'Message sent.', 'zh'=>'留言已发送。'],
        'neu'             => ['de'=>'neu',                     'en'=>'new', 'zh'=>'新'],
        'dateien_menu'    => ['de'=>'Dateien',                 'en'=>'Files', 'zh'=>'文件'],
        'dateien_sub'     => ['de'=>'Zertifikate, Spezifikationen, CoA und andere Unterlagen – gemeinsam mit bulkify an einem Ort.',
                              'en'=>'Certificates, specifications, CoA and other documents – shared with bulkify in one place.',
                              'zh'=>'证书、规格书、CoA 及其他文件——与 bulkify 共享于同一处。'],
        'firmendaten'     => ['de'=>'Firmendaten',             'en'=>'Company details', 'zh'=>'公司信息'],
        'nur_wir'         => ['de'=>'Konditionen und Preise pflegen wir – ändern Sie hier Ihre Kontakt- und Firmendaten.',
                              'en'=>'Terms and prices are maintained by us – please keep your contact and company details up to date here.',
                              'zh'=>'条款与价格由我方维护 — 请在此保持您的联系方式和公司信息为最新。'],
    ];
    $s = $sprache !== '' ? $sprache : lp_sprache();
    $s = in_array(strtolower($s), ['de', 'en', 'zh'], true) ? strtolower($s) : 'en';
    return $texte[$key][$s] ?? $key;
}

// Welche Sprache gilt gerade? Reihenfolge: eigene Wahl (Session) vor Stammdaten des Lieferanten.
// Wichtig fuer Login und Einladung – dort ist noch niemand angemeldet, es gibt also keine Stammdaten.
function lp_sprache(): string {
    if (!empty($_SESSION['lp_lang']) && in_array($_SESSION['lp_lang'], ['de', 'en', 'zh'], true)) return $_SESSION['lp_lang'];
    return function_exists('lieferant_sprache') && function_exists('ist_lieferant') && ist_lieferant() ? lieferant_sprache() : 'de';
}
// Sprachwahl aus ?lang= uebernehmen (auf jeder Portalseite erlaubt).
function lp_sprache_setzen(): void {
    $l = strtolower(trim((string)($_GET['lang'] ?? '')));
    if (in_array($l, ['de', 'en', 'zh'], true)) $_SESSION['lp_lang'] = $l;
}
// Umschalter „Deutsch | English" – die aktuelle Sprache ist hervorgehoben.
function lp_sprachwahl(): string {
    $cur = lp_sprache();
    $ziel = strtok((string)($_SERVER['REQUEST_URI'] ?? '?p=lieferant_login'), '#');
    $ziel = preg_replace('/([?&])lang=[^&]*(&|$)/', '$1', $ziel);
    $trenner = strpos($ziel, '?') === false ? '?' : '&';
    $out = '<span style="display:inline-flex;gap:12px;font-size:13px;align-items:center">';
    foreach (['de' => 'Deutsch', 'en' => 'English', 'zh' => '中文'] as $code => $label) {
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
        'lieferant_nachrichten' => lp_t('rueckfragen'),
        'lieferant_dateien'     => lp_t('dateien_menu'),
        'lieferant_profil'      => lp_t('profil'),
    ];
    $neu = $lf ? nachrichten_ungelesen((int)$lf['id'], 'lieferant') : 0;
    if ($neu > 0) $menu['lieferant_nachrichten'] .= ' (' . $neu . ' ' . lp_t('neu') . ')';
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
