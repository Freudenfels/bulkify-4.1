<?php
// Rahmen des Lieferantenportals: Kopf, Menü, Fuß – und die Übersetzung.
// Drei Sprachen: Deutsch, Englisch und Chinesisch. Welche gilt, steht am Lieferanten (`lieferanten.sprache`);
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
        'artikel'         => ['de'=>'Artikel',                'en'=>'Item', 'zh'=>'物料'],
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
        'ihr_preis'       => ['de'=>'Ihr Preis',              'en'=>'Your price', 'zh'=>'您的报价'],
        'ab_menge'        => ['de'=>'ab Menge',               'en'=>'from quantity', 'zh'=>'起订数量'],
        'fuer_menge'      => ['de'=>'für',                    'en'=>'for', 'zh'=>'针对'],
        'optional'        => ['de'=>'optional',               'en'=>'optional', 'zh'=>'可选'],
        'staffel'         => ['de'=>'Staffel',                'en'=>'Tier', 'zh'=>'阶梯价'],
        'staffel_hinweis' => ['de'=>'Nur ausfüllen, wenn ANDERE Mengen einen anderen Preis haben. Der Preis oben gilt für die angefragte Menge. Leere Zeilen werden ignoriert.',
                              'en'=>'Only needed if OTHER quantities come at a different price. The price above applies to the requested quantity. Empty rows are ignored.',
                              'zh'=>'仅当其他数量的价格不同时才需填写。上方价格适用于询价数量。空行将被忽略。'],
        'anfrage_offen'   => ['de'=>'offen',                  'en'=>'open', 'zh'=>'待处理'],
        'anfrage_beant'   => ['de'=>'beantwortet',            'en'=>'answered', 'zh'=>'已回复'],
        'dateien'         => ['de'=>'Dokumente (CoA, Spezifikation, Angebot)', 'en'=>'Documents (CoA, specification, quote)', 'zh'=>'文件（COA、Spec、报价单）'],
        'datei_gelesen'   => ['de'=>'Die Unterlage wurde automatisch ausgewertet – vielen Dank.',
                              'en'=>'The document has been read automatically – thank you.',
                              'zh'=>'文件已自动读取——谢谢。'],
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
        'dunkel'          => ['de'=>'Dunkler Modus',           'en'=>'Dark mode', 'zh'=>'深色模式'],
        'hell'            => ['de'=>'Heller Modus',           'en'=>'Light mode', 'zh'=>'浅色模式'],
        'ansehen'         => ['de'=>'Ansehen',                 'en'=>'View', 'zh'=>'查看'],
        'artikelnummer'   => ['de'=>'Artikelnummer',           'en'=>'Part no.', 'zh'=>'物料编号'],
        'coa_mitschicken' => ['de'=>'bitte mitschicken',       'en'=>'please attach', 'zh'=>'请一并提供'],
        'angenommen'      => ['de'=>'angenommen',              'en'=>'accepted', 'zh'=>'已接受'],
        'moq'             => ['de'=>'Mindestmenge (MOQ)',      'en'=>'Minimum order quantity', 'zh'=>'最小起订量'],
        'lieferzeit'      => ['de'=>'Lieferzeit (Tage)',       'en'=>'Lead time (days)', 'zh'=>'交货周期（天）'],
        'mengenstaffeln'  => ['de'=>'Mengenstaffeln',          'en'=>'Volume tiers', 'zh'=>'阶梯数量'],
        'art'             => ['de'=>'Art',                     'en'=>'Type', 'zh'=>'类型'],
        'spezifikation'   => ['de'=>'Spezifikation',           'en'=>'Specification', 'zh'=>'规格书'],
        'sonstiges'       => ['de'=>'Sonstiges',               'en'=>'Other', 'zh'=>'其他'],
        'datei'           => ['de'=>'Datei',                   'en'=>'File', 'zh'=>'文件'],
        'pflichtfeld'     => ['de'=>'Pflichtfeld.',            'en'=>'required.', 'zh'=>'为必填项。'],
        'nur_bilder'      => ['de'=>'Nur PNG, JPG oder WebP.', 'en'=>'Only PNG, JPG or WebP.', 'zh'=>'仅支持 PNG、JPG 或 WebP。'],
        'datei_zu_gross'  => ['de'=>'Die Datei ist größer als 2 MB.', 'en'=>'The file is larger than 2 MB.', 'zh'=>'文件超过 2 MB。'],
        'produkttyp'      => ['de'=>'Produkttyp',              'en'=>'Product type', 'zh'=>'产品类型'],
        'preis_basis'     => ['de'=>'Preis gilt', 'en'=>'Price applies', 'zh'=>'报价基准'],
        'je_1000'         => ['de'=>'je 1.000', 'en'=>'per 1,000', 'zh'=>'每 1,000'],
        'gesamtmenge'     => ['de'=>'Gesamtmenge',             'en'=>'Total quantity', 'zh'=>'总数量'],
        'je_packung'      => ['de'=>'je Packung',              'en'=>'per pack', 'zh'=>'每包装'],
        'packungen'       => ['de'=>'Packungen',               'en'=>'packs', 'zh'=>'包装数'],
        'kapselgroesse'   => ['de'=>'Kapselgröße',             'en'=>'Capsule size', 'zh'=>'胶囊规格'],
        'rezeptur'        => ['de'=>'Rezeptur je Einheit',     'en'=>'Formulation per unit', 'zh'=>'每单位配方'],
        'verpackung_lbl'  => ['de'=>'Verpackung',              'en'=>'Packaging', 'zh'=>'包装'],
        'preis_je'        => ['de'=>'je',                     'en'=>'per', 'zh'=>'每'],
        'rueckfragen'     => ['de'=>'Rückfragen',              'en'=>'Questions and answers', 'zh'=>'留言与答复'],
        'rueckfragen_sub' => ['de'=>'Fragen und Antworten zwischen Ihnen und bulkify. Zu einer Bestellung oder Preisanfrage schreiben Sie am besten direkt dort.',
                              'en'=>'Questions and answers between you and bulkify. For a specific order or price request, please write directly there.',
                              'zh'=>'您与 bulkify 之间的问题与答复。关于某个订单或询价，请直接在该页面留言。'],
        'gesendet'        => ['de'=>'Nachricht gesendet.',      'en'=>'Message sent.', 'zh'=>'留言已发送。'],
        'neu'             => ['de'=>'neu',                     'en'=>'new', 'zh'=>'新'],
        'dateien_menu'    => ['de'=>'Dateien',                 'en'=>'Files', 'zh'=>'文件'],
        'katalog'         => ['de'=>'Mein Katalog',            'en'=>'My catalogue', 'zh'=>'我的产品目录'],
        'anleitung'       => ['de'=>'Anleitung',               'en'=>'Guide', 'zh'=>'使用说明'],
        'katalog_sub'     => ['de'=>'Was Sie anbieten. Wir legen daraus unsere Artikel an – nach Prüfung.',
                              'en'=>'What you offer. We create our items from it – after review.',
                              'zh'=>'您所提供的产品。我方审核后据此建立物料。'],
        'katalog_hoch'    => ['de'=>'Preisliste oder Produktblatt hochladen', 'en'=>'Upload price list or product sheet', 'zh'=>'上传价格表或产品资料'],
        'katalog_hoch_sub'=> ['de'=>'Excel (.xlsx), CSV, PDF oder Bild – auch als Scan. Die Liste wird ausgelesen und erscheint unten. Sie können danach noch korrigieren. Altes .xls bitte vorher als .xlsx speichern.',
                              'en'=>'Excel (.xlsx), CSV, PDF or image – scans work too. The list is read out and appears below; you can still correct it. Please save old .xls as .xlsx first.',
                              'zh'=>'Excel（.xlsx）、CSV、PDF 或图片，扫描件亦可。系统会自动读取并显示在下方，您仍可修改。旧版 .xls 请先另存为 .xlsx。'],
        'katalog_lesen'   => ['de'=>'Liste auslesen',           'en'=>'Read the list', 'zh'=>'读取列表'],
        'katalog_gelesen' => ['de'=>'Zeilen aus der Liste übernommen:', 'en'=>'Rows read from the list:', 'zh'=>'已从列表读取的行数：'],
        'katalog_liste'   => ['de'=>'Ihre Artikel',             'en'=>'Your items', 'zh'=>'您的产品'],
        'katalog_leer'    => ['de'=>'Noch nichts hinterlegt. Laden Sie eine Liste hoch oder tragen Sie unten einen Artikel ein.',
                              'en'=>'Nothing here yet. Upload a list or add an item below.',
                              'zh'=>'尚无内容。请上传列表或在下方添加产品。'],
        'katalog_wartet'  => ['de'=>'wartet auf unsere Prüfung', 'en'=>'awaiting our review', 'zh'=>'待我方审核'],
        'katalog_geprueft_nein' => ['de'=>'in Prüfung',         'en'=>'under review', 'zh'=>'审核中'],
        'katalog_uebernommen'   => ['de'=>'übernommen',         'en'=>'accepted', 'zh'=>'已采用'],
        'katalog_abgelehnt'     => ['de'=>'nicht übernommen',   'en'=>'not accepted', 'zh'=>'未采用'],
        'katalog_zeile_neu'     => ['de'=>'Artikel von Hand eintragen', 'en'=>'Add an item manually', 'zh'=>'手动添加产品'],
        'katalog_zeile_add'     => ['de'=>'Artikel hinzufügen', 'en'=>'Add item', 'zh'=>'添加产品'],
        'form_lbl'        => ['de'=>'Form',                     'en'=>'Form', 'zh'=>'形态'],
        'herkunft'        => ['de'=>'Herkunft',                 'en'=>'Origin', 'zh'=>'产地'],
        'waehrung'        => ['de'=>'Währung',                  'en'=>'Currency', 'zh'=>'货币'],
        'loeschen'        => ['de'=>'Zeile löschen',            'en'=>'Delete row', 'zh'=>'删除该行'],
        'dauert'          => ['de'=>'dauert 20–90 Sekunden',    'en'=>'takes 20–90 seconds', 'zh'=>'需要 20–90 秒'],
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

// Einheiten stehen als deutsches Stammdatum in der Datenbank. Für den Lieferanten werden sie
// übersetzt und in die richtige Zahlform gebracht – „250.000 Kapseln", aber „Preis je Kapsel".
// Die Zuordnung steht zentral in einheit_wort() (core/schema.php), damit intern und im Portal
// dasselbe Wort steht. kg, g, L und ml bleiben unverändert.
function lp_einheit(?string $e, float $menge = 1, string $sprache = ''): string {
    return einheit_wort($e, $menge, $sprache !== '' ? $sprache : lp_sprache());
}
// Zahl in der Schreibweise des Lesers: Deutsch 1.234,5 – English und Chinesisch 1,234.5.
function lp_num($x, int $dez = 3): string {
    if ($x === null || $x === '') return '';
    $de = lp_sprache() === 'de';
    $s = number_format((float)$x, $dez, $de ? ',' : '.', $de ? '.' : ',');
    if ($dez > 0) $s = rtrim(rtrim($s, '0'), $de ? ',' : '.');
    return $s;
}

// Welche Sprache gilt gerade? Reihenfolge: eigene Wahl (Session) vor Stammdaten des Lieferanten.
// Wichtig fuer Login und Einladung – dort ist noch niemand angemeldet, es gibt also keine Stammdaten.
function lp_sprache(): string {
    $angemeldet = function_exists('ist_lieferant') && ist_lieferant();
    $uid = (int)($_SESSION['uid'] ?? 0);
    $wahl = $_SESSION['lp_lang'] ?? '';
    // Eine Wahl gilt, solange derselbe Besucher sie getroffen hat. Wer sich anmeldet, bekommt
    // zunächst seine hinterlegte Sprache – sonst bliebe das Portal in der Sprache haengen,
    // die jemand vor dem Login auf der Anmeldeseite angeklickt hat.
    if (in_array($wahl, ['de', 'en', 'zh'], true) && (int)($_SESSION['lp_lang_uid'] ?? 0) === $uid) return $wahl;
    return $angemeldet ? lieferant_sprache() : (in_array($wahl, ['de', 'en', 'zh'], true) ? $wahl : 'de');
}
// Sprachwahl aus ?lang= uebernehmen (auf jeder Portalseite erlaubt).
function lp_sprache_setzen(): void {
    $l = strtolower(trim((string)($_GET['lang'] ?? '')));
    if (in_array($l, ['de', 'en', 'zh'], true)) { $_SESSION['lp_lang'] = $l; $_SESSION['lp_lang_uid'] = (int)($_SESSION['uid'] ?? 0); }
}
// Umschalter „Deutsch | English | 中文" – die aktuelle Sprache ist hervorgehoben. Er darf umbrechen,
// sonst wird die dritte Sprache in der schmalen Seitenleiste abgeschnitten.
function lp_sprachwahl(): string {
    $cur = lp_sprache();
    $ziel = strtok((string)($_SERVER['REQUEST_URI'] ?? '?p=lieferant_login'), '#');
    $ziel = preg_replace('/([?&])lang=[^&]*(&|$)/', '$1', $ziel);
    $trenner = strpos($ziel, '?') === false ? '?' : '&';
    $out = '<span style="display:flex;flex-wrap:wrap;gap:4px 12px;font-size:11px;align-items:center" title="Sprache / Language / 语言">';
    // Kurz als Sprachkuerzel – so passt die Wahl in eine Zeile der schmalen Seitenleiste.
    foreach (['de' => 'DE', 'en' => 'EN', 'zh' => '中文'] as $code => $label) {
        // display/padding zuruecksetzen: die Menue-Regel .bx-side nav a wuerde die Links sonst
        // breit machen und den Umschalter umbrechen lassen.
        $stil = 'display:inline;padding:0;text-decoration:none;color:inherit;'
              . ($code === $cur ? 'font-weight:600;text-decoration:underline' : 'opacity:.5');
        $out .= '<a style="' . $stil . '" href="' . h($ziel . $trenner . 'lang=' . $code) . '">' . h($label) . '</a>';
    }
    return $out . '</span>';
}
function lp_head(string $titel): void {
    $lang = lp_sprache();
    echo '<!doctype html><html lang="' . h($lang) . '"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . h($titel) . '</title><link rel="stylesheet" href="assets/app.css">' . pwa_head()
       . '<script>(function(){try{var t=localStorage.getItem(\'bx-theme\');if(t===\'dark\'||t===\'light\')document.documentElement.setAttribute(\'data-theme\',t);}catch(e){}})();</script>'
       . '</head><body>';
}

function lp_foot(): void {
    echo (function_exists('bx_theme_script') ? bx_theme_script() : '') . bx_menue_script()
       . (function_exists('bx_busy_script') ? bx_busy_script() : '') . pwa_script() . '</body></html>';
}

// Menü + Rahmen. $aktiv = Routenname der aktuellen Seite.
function lp_shell_start(string $aktiv): void {
    $lf = aktueller_lieferant();
    $menu = [
        'lieferant_portal'      => lp_t('uebersicht'),
        'lieferant_bestellung'  => lp_t('bestellungen'),
        'lieferant_anfrage'     => lp_t('anfragen'),
        'lieferant_nachrichten' => lp_t('rueckfragen'),
        'lieferant_katalog'     => lp_t('katalog'),
        'lieferant_dateien'     => lp_t('dateien_menu'),
        'lieferant_profil'      => lp_t('profil'),
        'lieferant_hilfe'       => lp_t('anleitung'),
    ];
    // Zähler-Badges (Kreis mit Zahl): offene Preisanfragen und ungelesene Rückfragen.
    $lid = (int)($lf['id'] ?? 0);
    $badges = [];
    if ($lid) {
        $offeneAnf = (int) scalar("SELECT COUNT(*) FROM lieferant_anfrage WHERE lieferant_id=? AND status='offen'", [$lid]);
        if ($offeneAnf > 0) $badges['lieferant_anfrage'] = $offeneAnf;
        $neu = nachrichten_ungelesen($lid, 'lieferant');
        if ($neu > 0) $badges['lieferant_nachrichten'] = $neu;
    }
    // Kleiner runder Zähler – inline gestylt, damit er ohne Portal-CSS funktioniert.
    $zaehler = fn(int $n) => ' <span style="display:inline-block;min-width:18px;height:18px;line-height:18px;text-align:center;'
        . 'background:var(--lime,#c0f24e);color:#10210f;border-radius:999px;padding:0 5px;font-size:11px;font-weight:700;float:right">' . $n . '</span>';
    echo '<div class="bx-shell"><aside class="bx-side">'
       . '<div class="bx-brand"><img src="assets/bulkify-logo-white.png" alt="bulkify" class="bx-logo"><span class="bx-ver">' . h(lp_t('portal')) . '</span></div>'
       . '<nav><div class="bx-navgroup">' . h((string)($lf['firma'] ?? '')) . '</div>';
    foreach ($menu as $route => $label) {
        $badge = isset($badges[$route]) ? $zaehler((int)$badges[$route]) : '';
        echo '<a href="?p=' . h($route) . '"' . ($aktiv === $route ? ' class="on"' : '') . '>' . h($label) . $badge . '</a>';
    }
    // Die Sprache stellt man einmal ein, deshalb steht der Umschalter klein ganz unten.
    echo '<div class="bx-userbox"><a href="?p=logout">' . h(lp_t('abmelden')) . '</a></div>'
       . '<div class="bx-userbox" style="margin-top:0"><button type="button" class="bx-themebtn" data-dunkel="' . h(lp_t('dunkel')) . '" data-hell="' . h(lp_t('hell')) . '">' . h(lp_t('dunkel')) . '</button></div>'
       . '<div class="bx-userbox" style="margin-top:0;padding-top:8px">' . lp_sprachwahl() . '</div>'
       . '</nav></aside>' . bx_menue_scrim() . '<main class="bx-main">' . bx_mobilbar();
}
function lp_shell_ende(): void { echo '</main></div>'; }

// Die Wahl aus ?lang= gilt ab sofort – auch fuer Texte, die vor lp_head() gebaut werden.
lp_sprache_setzen();
